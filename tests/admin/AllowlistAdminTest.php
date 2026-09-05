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
					'scope_id'          => 'client-9',
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
