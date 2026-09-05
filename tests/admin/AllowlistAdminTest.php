<?php
/**
 * The allowlist AJAX save handler: nonce/capability gating, row sanitization, and the row cap.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AllowlistAdminTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_ability_allowlist_overrides' );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['allowlist_json'], $_REQUEST['nonce'] );
		parent::tear_down();
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 * Mirrors OauthRevokeAjaxTest::intercept_die().
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
	 * Run the AJAX handler and return its captured JSON payload.
	 *
	 * @return array<string,mixed>
	 */
	private function run_handler(): array {
		ob_start();
		try {
			aafm_ajax_save_allowlist();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Register a real OAuth client (its id is an auto-generated 32-hex string, never a
	 * human-chosen one) so a test can submit an allowlist row that names an actual client.
	 *
	 * @return string The real client_id.
	 */
	private function register_real_oauth_client(): string {
		aafm_install_oauth_tables();
		$res = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example/cb' ),
				'client_name'   => 'Test Client',
			)
		);
		$this->assertIsArray( $res );
		return (string) $res['client_id'];
	}

	public function test_a_subscriber_is_refused(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode( array() );

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( array(), aafm_allowlist_overrides() );
	}

	public function test_a_missing_or_invalid_nonce_is_refused(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['nonce']          = 'not-a-real-nonce';
		$_REQUEST['nonce']       = 'not-a-real-nonce';
		$_POST['allowlist_json'] = wp_json_encode( array() );

		$this->intercept_die();
		ob_start();
		try {
			aafm_ajax_save_allowlist();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		ob_end_clean();

		$this->assertSame( array(), aafm_allowlist_overrides() );
	}

	public function test_an_admin_can_save_a_valid_role_and_client_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$client_id = $this->register_real_oauth_client();

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts', 'aafm/get-posts' ), // duplicate, should dedupe.
				),
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $client_id,
					'allowed_abilities' => 'all',
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertTrue( $json['success'] ?? false );
		$stored = aafm_allowlist_overrides();
		$this->assertCount( 2, $stored );
		$this->assertSame( array( 'aafm/get-posts' ), $stored[0]['allowed_abilities'] );
		$this->assertSame( 'all', $stored[1]['allowed_abilities'] );
	}

	/**
	 * Codex round-b finding 8: two submitted rows for the same scope used to both reach storage,
	 * and aafm_ability_allowed_for_principal() only ever checks the first match - so an earlier
	 * permissive "all" row would silently defeat a later restrictive one, regardless of which one
	 * the operator actually meant to keep. Saving now keys rows by scope_type:scope_id so only
	 * the LAST submitted row for a given scope survives.
	 */
	public function test_a_duplicate_client_scope_keeps_only_the_last_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$client_id = $this->register_real_oauth_client();

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $client_id,
					'allowed_abilities' => 'all',
				),
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $client_id,
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertTrue( $json['success'] ?? false );
		$stored = aafm_allowlist_overrides();
		$this->assertCount( 1, $stored, 'Only one row may survive for a single scope.' );
		$this->assertSame( array( 'aafm/get-posts' ), $stored[0]['allowed_abilities'] );
	}

	public function test_a_row_with_an_unknown_role_is_dropped(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'not-a-real-role',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array(), aafm_allowlist_overrides() );
	}

	/**
	 * Codex final round 2 MEDIUM: a client id was accepted as arbitrary free text with no check
	 * that it named a real client. A mistyped id (a real client's id off by one character, the
	 * exact reproduction Codex gave) matched no OAuth client row, so it added no restriction at
	 * all for that client - and per the allowlist's own intersection precedence, an unmatched
	 * client is unrestricted, i.e. the row silently failed open rather than merely failing to
	 * apply. It must be dropped the same way an unknown role slug already is.
	 */
	public function test_a_row_with_a_mistyped_client_id_is_dropped(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$client_id = $this->register_real_oauth_client();
		$mistyped  = substr( $client_id, 0, -1 ); // Off by one character - names no real client.

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $mistyped,
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array(), aafm_allowlist_overrides() );
	}

	public function test_more_than_the_row_cap_is_refused(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$rows = array();
		for ( $i = 0; $i <= AAFM_ALLOWLIST_MAX_ROWS; $i++ ) {
			$rows[] = array(
				'scope_type'        => 'oauth_client',
				'scope_id'          => 'client-' . $i,
				'allowed_abilities' => 'all',
			);
		}

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode( $rows );

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( array(), aafm_allowlist_overrides() );
	}
}
