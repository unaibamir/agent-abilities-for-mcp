<?php
/**
 * Tests for the admin read helpers that back the Registered Clients and Active
 * Grants management tables: shape, active-token counts, redirect-URI decoding,
 * and the consent-to-client/user join.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class ClientListTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	/**
	 * Seed a client row.
	 *
	 * @param string   $client_id     Public client id.
	 * @param string   $client_name   Display name.
	 * @param string[] $redirect_uris Redirect URIs (stored as JSON).
	 * @param int      $is_active     1 active, 0 revoked.
	 * @return void
	 */
	private function seed_client( string $client_id, string $client_name, array $redirect_uris, int $is_active = 1 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'     => $client_id,
				'client_name'   => $client_name,
				'redirect_uris' => wp_json_encode( $redirect_uris ),
				'is_active'     => $is_active,
			),
			array( '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Seed an access-token row.
	 *
	 * @param string      $client_id  Owning client.
	 * @param int         $user_id    Owning user.
	 * @param int         $is_active  1 active, 0 inactive.
	 * @param string|null $expires_at Expiry timestamp, or null for non-expiring.
	 * @return void
	 */
	private function seed_token( string $client_id, int $user_id, int $is_active, ?string $expires_at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => hash( 'sha256', $client_id . $user_id . wp_rand() ),
				'refresh_hash' => hash( 'sha256', 'r' . $client_id . $user_id . wp_rand() ),
				'client_id'    => $client_id,
				'wp_user_id'   => $user_id,
				'is_active'    => $is_active,
				'expires_at'   => $expires_at,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Seed a consent (grant) row.
	 *
	 * @param int    $user_id   Granting user.
	 * @param string $client_id Granted client.
	 * @return void
	 */
	private function seed_consent( int $user_id, string $client_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $user_id,
				'client_id'  => $client_id,
			),
			array( '%d', '%s' )
		);
	}

	public function test_list_clients_returns_shape_and_active_token_count(): void {
		$this->seed_client( 'client_abc', 'Claude', array( 'https://claude.ai/cb', 'https://claude.ai/alt' ) );

		// One active unexpired token, one expired, one inactive: only the first counts.
		$this->seed_token( 'client_abc', 7, 1, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
		$this->seed_token( 'client_abc', 7, 1, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->seed_token( 'client_abc', 7, 0, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$clients = aafm_oauth_list_clients();
		$this->assertCount( 1, $clients );

		$client = $clients[0];
		$this->assertSame( 'client_abc', $client['client_id'] );
		$this->assertSame( 'Claude', $client['client_name'] );
		$this->assertSame( array( 'https://claude.ai/cb', 'https://claude.ai/alt' ), $client['redirect_uris'] );
		$this->assertTrue( $client['is_active'] );
		$this->assertSame( 1, $client['active_tokens'] );
	}

	public function test_list_clients_decodes_malformed_uris_to_empty_array(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'     => 'client_bad',
				'client_name'   => 'Broken',
				'redirect_uris' => 'not-json',
				'is_active'     => 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		$clients = aafm_oauth_list_clients();
		$this->assertCount( 1, $clients );
		$this->assertSame( array(), $clients[0]['redirect_uris'] );
		$this->assertFalse( $clients[0]['is_active'] );
		$this->assertSame( 0, $clients[0]['active_tokens'] );
	}

	public function test_list_grants_joins_client_name_and_resolves_user(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login'   => 'grant_owner',
				'display_name' => 'Grant Owner',
			)
		);
		$this->seed_client( 'client_abc', 'Claude', array( 'https://claude.ai/cb' ) );
		$this->seed_consent( $user_id, 'client_abc' );

		$grants = aafm_oauth_list_grants();
		$this->assertCount( 1, $grants );

		$grant = $grants[0];
		$this->assertSame( $user_id, $grant['user_id'] );
		$this->assertSame( 'Grant Owner', $grant['user_display'] );
		$this->assertSame( 'grant_owner', $grant['user_login'] );
		$this->assertSame( 'client_abc', $grant['client_id'] );
		$this->assertSame( 'Claude', $grant['client_name'] );
	}

	public function test_list_grants_skips_deleted_users(): void {
		$this->seed_client( 'client_abc', 'Claude', array( 'https://claude.ai/cb' ) );
		$this->seed_consent( 999999, 'client_abc' ); // No such user.

		$this->assertSame( array(), aafm_oauth_list_grants() );
	}
}
