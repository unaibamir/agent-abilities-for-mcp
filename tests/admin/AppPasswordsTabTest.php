<?php
/**
 * Tests for the Application Passwords card on the Connection tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;
use WP_Application_Passwords;

/**
 * Covers aafm_connection_app_passwords_data(), its card and aafm_ajax_revoke_app_password().
 */
class AppPasswordsTabTest extends TestCase {

	use AjaxHarness;

	/**
	 * The plugin-created agent user.
	 *
	 * @var int
	 */
	private int $agent = 0;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->agent = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'agent-bot',
			)
		);
		update_user_meta( $this->agent, aafm_agent_user_marker_meta_key(), 1 );
	}

	public function tear_down(): void {
		// wp_authenticate_application_password() records its result in globals the REST API reads
		// on every later request in this process.
		unset( $GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid'] );
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	/**
	 * Create an Application Password.
	 *
	 * @param int    $user_id Owner.
	 * @param string $name    Name.
	 * @return array{0:string,1:string} The password and its uuid.
	 */
	private function password( int $user_id, string $name ): array {
		$made = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );
		$this->assertIsArray( $made );
		return array( $made[0], $made[1]['uuid'] );
	}

	private function render(): string {
		ob_start();
		aafm_render_app_passwords_card( aafm_connection_app_passwords_data() );
		return (string) ob_get_clean();
	}

	private function revoke( int $user_id, $uuid, string $role = 'administrator' ): array {
		$this->post_as(
			$role,
			array(
				'user_id' => is_int( $user_id ) ? (string) $user_id : $user_id,
				'uuid'    => $uuid,
			)
		);
		return $this->run_handler( 'aafm_ajax_revoke_app_password' );
	}

	public function test_it_lists_only_plugin_created_agent_users_passwords(): void {
		$this->password( $this->agent, 'Claude Desktop' );
		$this->password( $this->agent, 'Cursor' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->password( $admin, 'Admin laptop' );

		$data = aafm_connection_app_passwords_data();
		$this->assertCount( 1, $data['users'] );
		$this->assertSame( $this->agent, $data['users'][0]['user_id'] );
		$this->assertCount( 2, $data['users'][0]['passwords'] );
		$html = $this->render();
		$this->assertStringContainsString( 'Claude Desktop', $html );
		$this->assertStringNotContainsString( 'Admin laptop', $html );
	}

	public function test_each_row_shows_name_created_last_used_and_ip_or_never(): void {
		list( , $uuid ) = $this->password( $this->agent, 'Claude Desktop' );
		$created        = WP_Application_Passwords::get_user_application_password( $this->agent, $uuid )['created'];
		$html           = $this->render();
		$this->assertStringContainsString( '<td>Claude Desktop</td><td>' . esc_html( aafm_format_admin_datetime( gmdate( 'Y-m-d H:i:s', $created ) ) ) . '</td><td><span class="aafm-muted">Never</span></td><td><span class="aafm-muted">Never</span></td>', $html );

		$remote                 = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- snapshot, restored below.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		WP_Application_Passwords::record_application_password_usage( $this->agent, $uuid );
		$_SERVER['REMOTE_ADDR'] = $remote;
		$this->assertStringContainsString( '<td>198.51.100.9</td>', $this->render() );
	}

	public function test_the_empty_state_names_the_next_step(): void {
		$html = $this->render();
		$this->assertStringContainsString( '<div class="aafm-empty-state"><strong>No Application Passwords yet</strong>', $html );
		$this->assertStringContainsString( 'Step 1', $html );
	}

	public function test_a_site_with_application_passwords_switched_off_says_so(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$data = aafm_connection_app_passwords_data();
		$this->assertFalse( $data['available'] );
		$this->assertSame( aafm_doctor_check_app_passwords()['detail'], $data['unavailable'] );
		$this->assertStringContainsString( 'aafm-app-passwords-off', $this->render() );
	}

	public function test_revoke_deletes_exactly_that_password_and_certifies(): void {
		list( , $first )  = $this->password( $this->agent, 'One' );
		list( , $second ) = $this->password( $this->agent, 'Two' );
		$json             = $this->revoke( $this->agent, $first );
		$this->assertTrue( $json['success'] );
		$this->assertNull( WP_Application_Passwords::get_user_application_password( $this->agent, $first ) );
		$this->assertNotNull( WP_Application_Passwords::get_user_application_password( $this->agent, $second ) );
	}

	public function test_revoke_refuses_a_user_who_is_not_an_agent_user(): void {
		$admin          = self::factory()->user->create( array( 'role' => 'administrator' ) );
		list( , $uuid ) = $this->password( $admin, 'Admin laptop' );
		$this->assertFalse( $this->revoke( $admin, $uuid )['success'] );
		$this->assertNotNull( WP_Application_Passwords::get_user_application_password( $admin, $uuid ) );
	}

	public function test_revoke_refuses_a_malformed_uuid(): void {
		list( , $uuid ) = $this->password( $this->agent, 'One' );
		foreach ( array( 'not-a-uuid', $uuid . 'x', array( $uuid ) ) as $bad ) {
			$this->assertFalse( $this->revoke( $this->agent, $bad )['success'] );
		}
		$this->post_as(
			'administrator',
			array(
				'user_id' => array( (string) $this->agent ),
				'uuid'    => $uuid,
			)
		);
		$this->assertFalse( $this->run_handler( 'aafm_ajax_revoke_app_password' )['success'] );
		$this->assertFalse( $this->revoke( $this->agent, $uuid, 'editor' )['success'] );

		$this->post_as(
			'administrator',
			array(
				'user_id' => (string) $this->agent,
				'uuid'    => $uuid,
			)
		);
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$this->run_handler( 'aafm_ajax_revoke_app_password' );
		$this->assertNotNull( WP_Application_Passwords::get_user_application_password( $this->agent, $uuid ) );
	}

	public function test_revoke_writes_one_row_without_the_uuid_or_the_password_name(): void {
		global $wpdb;
		list( , $uuid ) = $this->password( $this->agent, 'Secret name' );
		$this->revoke( $this->agent, $uuid );
		$details = $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s', $wpdb->prefix . 'aafm_activity_log', 'setting_changed' ) );
		$this->assertSame( array( 'Application Password revoked (user ID ' . $this->agent . ')' ), $details );
	}

	public function test_a_revoked_password_stops_working_on_the_transport_gate(): void {
		add_filter( 'application_password_is_api_request', '__return_true' );
		list( $password, $uuid ) = $this->password( $this->agent, 'One' );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate_application_password( null, 'agent-bot', $password ) );
		$this->revoke( $this->agent, $uuid );
		$this->assertWPError( wp_authenticate_application_password( null, 'agent-bot', $password ) );
	}

	public function test_rendering_writes_nothing(): void {
		global $wpdb;
		$this->password( $this->agent, 'One' );
		$snapshot = static fn() => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'aafm\\_%' ORDER BY option_name", ARRAY_A );
		$before   = $snapshot();
		$this->render();
		$this->assertSame( $before, $snapshot() );
	}
}
