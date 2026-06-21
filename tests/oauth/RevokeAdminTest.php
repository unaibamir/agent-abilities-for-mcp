<?php
/**
 * Tests for the admin revoke helpers: deactivating a client, bulk-revoking a
 * client's tokens, deleting a consent, and bulk-revoking a user+client's tokens.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class RevokeAdminTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	/**
	 * Seed a client row.
	 *
	 * @param string $client_id Public client id.
	 * @param int    $is_active 1 active, 0 revoked.
	 * @return void
	 */
	private function seed_client( string $client_id, int $is_active = 1 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => $client_id,
				'client_name' => 'Test',
				'is_active'   => $is_active,
			),
			array( '%s', '%s', '%d' )
		);
	}

	/**
	 * Seed an active access-token row.
	 *
	 * @param string $client_id Owning client.
	 * @param int    $user_id   Owning user.
	 * @return void
	 */
	private function seed_token( string $client_id, int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => hash( 'sha256', $client_id . $user_id . wp_rand() ),
				'refresh_hash' => hash( 'sha256', 'r' . $client_id . $user_id . wp_rand() ),
				'client_id'    => $client_id,
				'wp_user_id'   => $user_id,
				'is_active'    => 1,
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Count active tokens for a client.
	 *
	 * @param string $client_id Client to count.
	 * @return int
	 */
	private function active_tokens( string $client_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE client_id = %s AND is_active = 1",
				$client_id
			)
		);
	}

	public function test_deactivate_client_and_revoke_its_tokens(): void {
		$this->seed_client( 'client_abc', 1 );
		$this->seed_token( 'client_abc', 7 );
		$this->seed_token( 'client_abc', 8 );

		$this->assertTrue( aafm_oauth_deactivate_client( 'client_abc' ) );
		$this->assertTrue( aafm_oauth_client_is_deactivated( 'client_abc' ) );

		$this->assertSame( 2, aafm_oauth_revoke_client_tokens( 'client_abc' ) );
		$this->assertSame( 0, $this->active_tokens( 'client_abc' ) );

		// Idempotent: a second pass revokes nothing.
		$this->assertSame( 0, aafm_oauth_revoke_client_tokens( 'client_abc' ) );
	}

	public function test_delete_consent_and_revoke_user_client_tokens_is_scoped(): void {
		global $wpdb;
		$this->seed_client( 'client_abc', 1 );

		// Two users on the same client, plus a consent for the first user.
		$this->seed_token( 'client_abc', 7 );
		$this->seed_token( 'client_abc', 8 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => 7,
				'client_id'  => 'client_abc',
			),
			array( '%d', '%s' )
		);

		$this->assertTrue( aafm_oauth_delete_consent( 7, 'client_abc' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
				7,
				'client_abc'
			)
		);
		$this->assertSame( 0, $remaining );

		// Only user 7's tokens go inactive; user 8 keeps its session.
		$this->assertSame( 1, aafm_oauth_revoke_user_client_tokens( 7, 'client_abc' ) );
		$this->assertSame( 1, $this->active_tokens( 'client_abc' ) );
	}
}
