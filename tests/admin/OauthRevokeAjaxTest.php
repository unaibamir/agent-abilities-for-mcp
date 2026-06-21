<?php
/**
 * Tests for the nonce- and capability-gated OAuth revoke AJAX endpoints that back
 * the Connections management tables.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class OauthRevokeAjaxTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['client_id'], $_POST['user_id'], $_REQUEST['nonce'] );
		parent::tear_down();
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 *
	 * @return void
	 */
	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload.
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			// wp_send_json* always dies; the body is already buffered.
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Seed a client with one active token.
	 *
	 * @param string $client_id Client id.
	 * @param int    $user_id   Token owner.
	 * @return void
	 */
	private function seed_client_with_token( string $client_id, int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => $client_id,
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => hash( 'sha256', $client_id . wp_rand() ),
				'refresh_hash' => hash( 'sha256', 'r' . $client_id . wp_rand() ),
				'client_id'    => $client_id,
				'wp_user_id'   => $user_id,
				'is_active'    => 1,
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Seed a pending (not-yet-redeemed) authorization code for a client+user.
	 *
	 * @param string $client_id Owning client.
	 * @param int    $user_id   Owning user.
	 * @return void
	 */
	private function seed_code( string $client_id, int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_codes',
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
	 * Count pending authorization codes for a client+user pair.
	 *
	 * @param string $client_id Client to count.
	 * @param int    $user_id   User to scope to.
	 * @return int
	 */
	private function codes( string $client_id, int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_codes WHERE client_id = %s AND wp_user_id = %d",
				$client_id,
				$user_id
			)
		);
	}

	public function test_revoke_client_succeeds_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->seed_client_with_token( 'client_abc', 7 );
		// A pending authorization code is still redeemable within its 60s window unless revoke
		// drops it too, so seed one and prove the handler clears it (the race fix at connection.php).
		$this->seed_code( 'client_abc', 7 );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_client' );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertTrue( aafm_oauth_client_is_deactivated( 'client_abc' ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE client_id = %s AND is_active = 1",
				'client_abc'
			)
		);
		$this->assertSame( 0, $active, 'The client tokens should be revoked.' );
		$this->assertSame( 0, $this->codes( 'client_abc', 7 ), 'Pending authorization codes must be dropped on client revoke.' );
	}

	public function test_revoke_client_denied_for_subscriber(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->seed_client_with_token( 'client_abc', 7 );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		$this->intercept_die();
		$thrown = false;
		ob_start();
		try {
			aafm_ajax_oauth_revoke_client();
		} catch ( \WPDieException $e ) {
			$thrown = true;
		} finally {
			ob_end_clean();
		}

		$this->assertTrue( $thrown, 'A subscriber must be denied.' );
		// The client must stay active: the cap check fires before any write.
		$this->assertFalse( aafm_oauth_client_is_deactivated( 'client_abc' ) );
	}

	public function test_revoke_grant_succeeds_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->seed_client_with_token( 'client_abc', $admin );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $admin,
				'client_id'  => 'client_abc',
			),
			array( '%d', '%s' )
		);
		// Pending code for this grant: revoke must drop it so it can't mint fresh tokens after
		// the consent and tokens are gone (the per-grant half of the revocation race fix).
		$this->seed_code( 'client_abc', $admin );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['user_id']   = (string) $admin;
		$_POST['client_id'] = 'client_abc';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_grant' );

		$this->assertTrue( $json['success'] ?? false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
				$admin,
				'client_abc'
			)
		);
		$this->assertSame( 0, $remaining, 'The consent should be deleted.' );
		$this->assertSame( 0, $this->codes( 'client_abc', $admin ), 'Pending authorization codes must be dropped on grant revoke.' );
	}
}
