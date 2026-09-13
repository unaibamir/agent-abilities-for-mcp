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

	/**
	 * Make one query fail by rewriting it to target a table that does not exist, so
	 * $wpdb->query()/update()/delete() report failure the same way a real SQL error would.
	 *
	 * @param string $needle Substring identifying the one query to break.
	 * @return void
	 */
	private function fail_query_containing( string $needle ): void {
		add_filter(
			'query',
			static function ( string $query ) use ( $needle ): string {
				return false !== strpos( $query, $needle )
					? 'SELECT * FROM aafm_missing_table_for_test'
					: $query;
			}
		);
	}

	/**
	 * Codex round 9, R9-2: aafm_oauth_deactivate_client() used to collapse a real SQL failure
	 * and "0 rows matched" into the same false-turned-true(0) result, so the handler always sent
	 * success. The client must stay active and the handler must report failure.
	 */
	public function test_revoke_client_reports_failure_when_the_deactivate_write_fails(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->seed_client_with_token( 'client_abc', 7 );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		global $wpdb;
		$this->fail_query_containing( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_clients` SET is_active = 0' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_client' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A failed deactivation must not report success.' );
		$this->assertFalse( aafm_oauth_client_is_deactivated( 'client_abc' ), 'The client must stay active when the write failed.' );
	}

	/**
	 * Codex round 9, R9-2: a failed access-token UPDATE left a live bearer token validating while
	 * the handler still reported success. The token must stay valid and the handler must fail.
	 */
	public function test_revoke_grant_reports_failure_when_the_token_revoke_write_fails(): void {
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

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['user_id']   = (string) $admin;
		$_POST['client_id'] = 'client_abc';

		$this->fail_query_containing( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_access_tokens` SET is_active = 0' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_grant' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A failed token revoke must not report success.' );
		$this->assertTrue(
			aafm_oauth_user_client_has_active_tokens( $admin, 'client_abc' ),
			'The bearer token must still be active when the write failed.'
		);
	}

	/**
	 * Codex round 10, R10-2: the round 9 fix above only faulted the mutation and left the
	 * confirming read healthy, so it could not see that aafm_oauth_client_is_deactivated() also
	 * casts a failed SELECT to "not deactivated" - the very read aafm_oauth_deactivate_client() now
	 * uses to certify. Faulting the deactivating UPDATE and its confirming SELECT together must
	 * still report failure, not a false success from two failures cancelling out.
	 */
	public function test_revoke_client_reports_failure_when_the_deactivate_write_and_its_confirming_read_both_fail(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->seed_client_with_token( 'client_abc', 7 );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		global $wpdb;
		$this->fail_query_containing( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_clients` SET is_active = 0' );
		$this->fail_query_containing( 'SELECT is_active FROM `' . $wpdb->prefix . 'aafm_oauth_clients`' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_client' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A deactivation whose write and confirming read both fail must not report success.' );
		$this->assertFalse( aafm_oauth_client_is_deactivated( 'client_abc' ), 'The client row was never actually reachable; it must still read as active.' );
	}

	/**
	 * Codex round 10, R10-2: same shape as the client-deactivate case above, but for
	 * aafm_oauth_delete_consent(), whose old certification (! aafm_oauth_has_consent()) folded a
	 * failed confirming SELECT into "consent gone" the same way.
	 */
	public function test_revoke_grant_reports_failure_when_the_consent_delete_and_its_confirming_read_both_fail(): void {
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

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['user_id']   = (string) $admin;
		$_POST['client_id'] = 'client_abc';

		$this->fail_query_containing( 'DELETE FROM `' . $wpdb->prefix . 'aafm_oauth_consents`' );
		$this->fail_query_containing( 'SELECT id FROM `' . $wpdb->prefix . 'aafm_oauth_consents`' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_grant' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A consent delete whose write and confirming read both fail must not report success.' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
				$admin,
				'client_abc'
			)
		);
		$this->assertSame( 1, $remaining, 'The consent row was never actually reachable; it must still be there.' );
	}

	/**
	 * Codex round 10, R10-2: the revoke handlers call aafm_oauth_revoke_client_codes() and throw
	 * its result away entirely - the authorization-code table was never certified at all, only the
	 * client and its tokens were. A code left behind by a failed delete is still redeemable within
	 * its ~60-second window even after the client is deactivated and its tokens revoked. Faulting
	 * only the codes DELETE and its new confirming COUNT (deactivation and token revoke both
	 * succeed normally) must still report failure.
	 */
	public function test_revoke_client_reports_failure_when_the_pending_code_delete_and_its_confirming_read_both_fail(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->seed_client_with_token( 'client_abc', 7 );
		$this->seed_code( 'client_abc', 7 );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		global $wpdb;
		$this->fail_query_containing( 'DELETE FROM `' . $wpdb->prefix . 'aafm_oauth_codes` WHERE client_id' );
		$this->fail_query_containing( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_oauth_codes` WHERE client_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_client' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A pending code that could not be certified as cleared must not report success, even though deactivation and token revoke both genuinely succeeded.' );
		$this->assertTrue( aafm_oauth_client_is_deactivated( 'client_abc' ), 'The client itself was genuinely deactivated; only the code certification is what failed.' );
		$this->assertSame( 1, $this->codes( 'client_abc', 7 ), 'The pending code was never actually reachable; it must still be there.' );
	}

	/**
	 * Same gap as above, scoped to the per-grant revoke path and aafm_oauth_revoke_user_client_-
	 * codes().
	 */
	public function test_revoke_grant_reports_failure_when_the_pending_code_delete_and_its_confirming_read_both_fail(): void {
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
		$this->seed_code( 'client_abc', $admin );

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['user_id']   = (string) $admin;
		$_POST['client_id'] = 'client_abc';

		$this->fail_query_containing( 'DELETE FROM `' . $wpdb->prefix . 'aafm_oauth_codes` WHERE wp_user_id' );
		$this->fail_query_containing( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_oauth_codes` WHERE wp_user_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_grant' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A pending code that could not be certified as cleared must not report success, even though the consent delete and token revoke both genuinely succeeded.' );
		$this->assertSame( 1, $this->codes( 'client_abc', $admin ), 'The pending code was never actually reachable; it must still be there.' );
	}

	/**
	 * Codex round 11, R11-1: the round 9 fix (test_revoke_grant_reports_failure_when_the_token_-
	 * revoke_write_fails, above) faults only the token UPDATE and leaves its confirming
	 * aafm_oauth_client_has_active_tokens() COUNT healthy, so it cannot see the same
	 * cancel-two-failures-into-a-false-success shape the round 10 fixes above already cover for
	 * the client-deactivate, consent-delete, and pending-code paths. Faulting the token UPDATE and
	 * its exact confirming COUNT together must still report failure, and the token row itself
	 * must still read active. The certification is scoped to aafm_oauth_get_access_token_row()
	 * rather than a full aafm_oauth_validate_access_token() bearer check: this handler also
	 * deactivates the client (a genuinely successful, independent write), which alone would
	 * block the bearer regardless of whether the token-revoke certification under test is
	 * correct, so it cannot discriminate the two.
	 */
	public function test_revoke_client_reports_failure_when_the_token_revoke_write_and_its_confirming_count_both_fail(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_abc',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		$tokens = aafm_oauth_mint_tokens(
			array(
				'client_id'  => 'client_abc',
				'wp_user_id' => 7,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		$this->fail_query_containing( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_access_tokens` SET is_active = 0 WHERE client_id' );
		$this->fail_query_containing( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_oauth_access_tokens` WHERE client_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_client' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A token revoke whose write and confirming count both fail must not report success.' );
		$this->assertNotNull(
			aafm_oauth_get_access_token_row( $tokens['access_token'] ),
			'The token row was never actually reachable; it must still read active.'
		);
	}

	/**
	 * Same gap as above, scoped to the per-grant revoke path and
	 * aafm_oauth_user_client_has_active_tokens().
	 */
	public function test_revoke_grant_reports_failure_when_the_token_revoke_write_and_its_confirming_count_both_fail(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_abc',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		$tokens = aafm_oauth_mint_tokens(
			array(
				'client_id'  => 'client_abc',
				'wp_user_id' => $admin,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $admin,
				'client_id'  => 'client_abc',
			),
			array( '%d', '%s' )
		);

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['user_id']   = (string) $admin;
		$_POST['client_id'] = 'client_abc';

		$this->fail_query_containing( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_access_tokens` SET is_active = 0 WHERE wp_user_id' );
		$this->fail_query_containing( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_oauth_access_tokens` WHERE wp_user_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_grant' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A token revoke whose write and confirming count both fail must not report success.' );
		$this->assertNotNull(
			aafm_oauth_get_access_token_row( $tokens['access_token'] ),
			'The token row was never actually reachable; it must still read active.'
		);
	}

	/**
	 * R2-10 (1.7.5 deferred, round 2): the two AJAX-level tests above fault BOTH the token
	 * UPDATE and the confirming COUNT, but aafm_ajax_oauth_revoke_client()'s guard is
	 * `-1 === $revoked || aafm_oauth_client_has_active_tokens(...) || ...` - PHP's `||`
	 * short-circuits on the already-true `-1 === $revoked`, so the confirming reader is never
	 * even called and its own fault injection above never actually ran. This asserts
	 * aafm_oauth_client_has_active_tokens()'s fail-closed contract directly, with nothing else
	 * able to short-circuit around it. Fails if that reader reverts to treating a failed COUNT
	 * read as "no active tokens" (0), the R10-2 regression this reader's own docblock names.
	 */
	public function test_client_active_tokens_reader_fails_closed_when_its_count_query_fails(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_abc',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		aafm_oauth_mint_tokens(
			array(
				'client_id'  => 'client_abc',
				'wp_user_id' => 7,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);

		$this->fail_query_containing( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_oauth_access_tokens` WHERE client_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$has_active = aafm_oauth_client_has_active_tokens( 'client_abc' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertTrue( $has_active, 'A confirming count read that itself fails must report "still has active tokens", not "none".' );
	}

	/**
	 * Same gap as above, scoped to aafm_oauth_user_client_has_active_tokens() and the per-grant
	 * revoke handler's identical short-circuit.
	 */
	public function test_user_client_active_tokens_reader_fails_closed_when_its_count_query_fails(): void {
		global $wpdb;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_abc',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		aafm_oauth_mint_tokens(
			array(
				'client_id'  => 'client_abc',
				'wp_user_id' => $admin,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);

		$this->fail_query_containing( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_oauth_access_tokens` WHERE wp_user_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$has_active = aafm_oauth_user_client_has_active_tokens( $admin, 'client_abc' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertTrue( $has_active, 'A confirming count read that itself fails must report "still has active tokens", not "none".' );
	}

	/**
	 * R2-10 (1.7.5 deferred, round 2): F6's fix (refuse success on `-1 === $revoked`) has never
	 * had a fixture where there is genuinely nothing left to "survive" - every existing fault
	 * test also mints a real active token, so a regression that dropped the `-1` check entirely
	 * would still be caught by that token's own confirming read, never by the -1 check itself.
	 * Here the client has zero tokens to begin with: the confirming reader correctly (and
	 * genuinely) finds none active either way, so only the `-1 === $revoked` check can be
	 * standing between a failed UPDATE and a false "success" response.
	 */
	public function test_revoke_client_reports_failure_when_the_update_fails_with_no_tokens_to_revoke(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_abc',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		// Deliberately no aafm_oauth_mint_tokens() call: this client has zero token rows.

		$nonce              = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']     = $nonce;
		$_REQUEST['nonce']  = $nonce;
		$_POST['client_id'] = 'client_abc';

		$this->fail_query_containing( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_access_tokens` SET is_active = 0 WHERE client_id' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_oauth_revoke_client' );
		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse(
			$json['success'] ?? true,
			'A failed revoke UPDATE must not report success just because there was nothing active left to confirm either way.'
		);
	}
}
