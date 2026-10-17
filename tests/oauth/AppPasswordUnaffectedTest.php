<?php
/**
 * Application Password connections are unaffected by the per-connection OAuth controls.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Only pause-all stops an Application Password call on the MCP routes.
 */
final class AppPasswordUnaffectedTest extends TestCase {

	private const CLIENT = 'client_same_user';

	/**
	 * The Basic-auth user.
	 *
	 * @var int
	 */
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		// An Application Password resolves through core, so no OAuth grant is in the request store.
		wp_set_current_user( $this->user );
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
			)
		);
	}

	/**
	 * The transport gate's answer for the current request.
	 *
	 * @return bool|\WP_Error
	 */
	private function gate() {
		return aafm_transport_permission_callback( new \WP_REST_Request( 'POST', aafm_mcp_rest_route() ) );
	}

	public function test_every_feature_at_its_default_leaves_the_gate_open(): void {
		$this->assertTrue( $this->gate() );
	}

	public function test_a_paused_oauth_grant_of_the_same_user_does_not_stop_it(): void {
		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => true ) ) );
		$this->assertTrue( $this->gate() );
	}

	public function test_an_expired_oauth_grant_of_the_same_user_does_not_stop_it(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'client_id' => self::CLIENT ) );
		$this->assertTrue( $this->gate() );
	}

	public function test_pause_all_refuses_the_same_request_on_the_mcp_route(): void {
		aafm_set_pause_all( true );
		$result = aafm_pause_all_guard( null, rest_get_server(), new \WP_REST_Request( 'POST', aafm_mcp_rest_route() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_access_paused', $result->get_error_code() );
	}
}
