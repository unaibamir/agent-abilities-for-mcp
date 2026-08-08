<?php
/**
 * Multisite gate for the create-agent-user AJAX handler (finding B17).
 *
 * On multisite, manage_options is a per-site capability every subsite administrator
 * holds, but create_users maps through map_meta_cap to do_not_allow unless the caller
 * is a super admin or the network's add_new_users setting is on (capabilities.php,
 * case 'create_users'). A handler gated on manage_options alone therefore lets any
 * subsite admin mint network-wide accounts even when the network forbids it. The
 * aafm/create-user ability already gates on create_users; these tests pin the admin
 * AJAX sibling to the same rule.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class MsCreateAgentUserTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['login'], $_REQUEST['nonce'] );
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
	 * Arm the nonce and login POST fields the handler reads.
	 *
	 * @param string $login Requested agent login.
	 * @return void
	 */
	private function arm_request( string $login ): void {
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$_POST['login']    = $login;
	}

	/**
	 * B17: a subsite administrator without create_users (network add_new_users off, the
	 * default) must be refused, even though they hold manage_options.
	 */
	public function test_subsite_admin_without_create_users_is_refused(): void {
		$this->acting_as( 'administrator' );

		// Core's multisite mapping: site admin, not super admin, add_new_users off.
		$this->assertTrue( current_user_can( 'manage_options' ), 'precondition: subsite admin holds manage_options.' );
		$this->assertFalse( current_user_can( 'create_users' ), 'precondition: core denies create_users to a subsite admin when add_new_users is off.' );

		$this->arm_request( 'ms-agent-blocked' );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_create_agent_user' );

		$this->assertArrayHasKey( 'success', $json );
		$this->assertFalse( $json['success'], 'the handler must refuse a caller core would not allow to create users.' );
		$this->assertFalse( username_exists( 'ms-agent-blocked' ), 'no account may be created on a refused request.' );
	}

	/**
	 * When the network's add_new_users setting is on, core grants create_users to subsite
	 * admins, so the same caller must go through - the gate follows the network setting,
	 * it does not hard-block multisite.
	 */
	public function test_subsite_admin_with_add_new_users_on_can_create(): void {
		$this->acting_as( 'administrator' );
		update_site_option( 'add_new_users', 1 );

		$this->assertTrue( current_user_can( 'create_users' ), 'precondition: add_new_users on grants create_users to subsite admins.' );

		$this->arm_request( 'ms-agent-allowed' );
		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_create_agent_user' );

		$this->assertArrayHasKey( 'success', $json );
		$this->assertTrue( $json['success'], 'a caller core allows to create users must pass the gate.' );
		$this->assertNotFalse( username_exists( 'ms-agent-allowed' ), 'the agent user must exist after a successful create.' );
	}
}
