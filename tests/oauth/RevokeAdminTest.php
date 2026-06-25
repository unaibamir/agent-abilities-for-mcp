<?php
/**
 * Tests for the admin revoke helpers: deactivating a client, bulk-revoking a
 * client's tokens, deleting a consent, and bulk-revoking a user+client's tokens.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\OAuth;

use Oversio\Tests\TestCase;

final class RevokeAdminTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_oauth_tables();
		oversio_truncate_oauth_tables();
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
			$wpdb->prefix . 'oversio_oauth_clients',
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
			$wpdb->prefix . 'oversio_oauth_access_tokens',
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
				"SELECT COUNT(*) FROM {$wpdb->prefix}oversio_oauth_access_tokens WHERE client_id = %s AND is_active = 1",
				$client_id
			)
		);
	}

	public function test_deactivate_client_and_revoke_its_tokens(): void {
		$this->seed_client( 'client_abc', 1 );
		$this->seed_token( 'client_abc', 7 );
		$this->seed_token( 'client_abc', 8 );

		$this->assertTrue( oversio_oauth_deactivate_client( 'client_abc' ) );
		$this->assertTrue( oversio_oauth_client_is_deactivated( 'client_abc' ) );

		$this->assertSame( 2, oversio_oauth_revoke_client_tokens( 'client_abc' ) );
		$this->assertSame( 0, $this->active_tokens( 'client_abc' ) );

		// Idempotent: a second pass revokes nothing.
		$this->assertSame( 0, oversio_oauth_revoke_client_tokens( 'client_abc' ) );
	}

	public function test_delete_consent_and_revoke_user_client_tokens_is_scoped(): void {
		global $wpdb;
		$this->seed_client( 'client_abc', 1 );

		// Two users on the same client, plus a consent for the first user.
		$this->seed_token( 'client_abc', 7 );
		$this->seed_token( 'client_abc', 8 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'oversio_oauth_consents',
			array(
				'wp_user_id' => 7,
				'client_id'  => 'client_abc',
			),
			array( '%d', '%s' )
		);

		$this->assertTrue( oversio_oauth_delete_consent( 7, 'client_abc' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}oversio_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
				7,
				'client_abc'
			)
		);
		$this->assertSame( 0, $remaining );

		// Only user 7's tokens go inactive; user 8 keeps its session.
		$this->assertSame( 1, oversio_oauth_revoke_user_client_tokens( 7, 'client_abc' ) );
		$this->assertSame( 1, $this->active_tokens( 'client_abc' ) );
	}

	/**
	 * Seed a pending (not-yet-redeemed) authorization code.
	 *
	 * @param string $client_id Owning client.
	 * @param int    $user_id   Owning user.
	 * @return void
	 */
	private function seed_code( string $client_id, int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'oversio_oauth_codes',
			array(
				'code_hash'  => hash( 'sha256', $client_id . $user_id . wp_rand() ),
				'client_id'  => $client_id,
				'wp_user_id' => $user_id,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Count authorization codes for a client (optionally a single user).
	 *
	 * @param string   $client_id Client to count.
	 * @param int|null $user_id   When set, scope to this user.
	 * @return int
	 */
	private function codes( string $client_id, ?int $user_id = null ): int {
		global $wpdb;
		if ( null === $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
					"SELECT COUNT(*) FROM {$wpdb->prefix}oversio_oauth_codes WHERE client_id = %s",
					$client_id
				)
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}oversio_oauth_codes WHERE client_id = %s AND wp_user_id = %d",
				$client_id,
				$user_id
			)
		);
	}

	public function test_revoke_client_codes_drops_all_pending_codes(): void {
		$this->seed_code( 'client_abc', 7 );
		$this->seed_code( 'client_abc', 8 );
		$this->seed_code( 'client_other', 7 );

		$this->assertSame( 2, oversio_oauth_revoke_client_codes( 'client_abc' ) );
		$this->assertSame( 0, $this->codes( 'client_abc' ) );
		// A different client's pending code is untouched.
		$this->assertSame( 1, $this->codes( 'client_other' ) );
	}

	public function test_revoke_user_client_codes_is_scoped_to_the_pair(): void {
		$this->seed_code( 'client_abc', 7 );
		$this->seed_code( 'client_abc', 8 );

		$this->assertSame( 1, oversio_oauth_revoke_user_client_codes( 7, 'client_abc' ) );
		$this->assertSame( 0, $this->codes( 'client_abc', 7 ) );
		// User 8's pending code for the same client survives.
		$this->assertSame( 1, $this->codes( 'client_abc', 8 ) );
	}
}
