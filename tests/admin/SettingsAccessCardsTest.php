<?php
/**
 * Tests for the Settings rows "Roles that can connect" and "Real visitor address behind a proxy".
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Covers the roles and proxy sanitizers, their validation and the Settings save glue.
 */
class SettingsAccessCardsTest extends TestCase {

	use AjaxHarness;

	private const CLIENT = 'client_settings_roles';

	/**
	 * Saved $_SERVER.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['HTTPS'] = 'on';
		// The installer's DDL commits, so it runs before this test writes anything.
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		delete_option( 'aafm_ip_allowlist' );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		remove_all_filters( 'aafm_oauth_min_capability' );
		remove_all_filters( 'query' );
		$this->set_permalink_structure( '' );
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * A Settings save as an administrator with the OAuth switch kept on.
	 *
	 * @param array<string,mixed> $fields POST fields.
	 * @return array<string,mixed>
	 */
	private function save( array $fields ): array {
		$this->post_as( 'administrator', array_merge( array( 'aafm_oauth_enabled' => '1' ), $fields ) );
		$json = $this->run_handler( 'aafm_ajax_save_settings' );
		aafm_policy_reset_request_state();
		return $json;
	}

	private function render(): string {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_settings_tab();
		return (string) ob_get_clean();
	}

	/**
	 * The role checkboxes the form would post as ticked, Administrator excluded (it is disabled).
	 *
	 * @return array<int,string>
	 */
	private function displayed_ticks(): array {
		preg_match_all( '/name="aafm_oauth_connect_roles\[\]" value="([a-z_]+)" checked=[\'"]checked[\'"]>/', $this->render(), $m );
		return $m[1];
	}

	private function setting_rows(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s ORDER BY id', $wpdb->prefix . 'aafm_activity_log', 'setting_changed' ) );
	}

	public function test_the_row_lists_every_role_and_ticks_todays_floor_when_nothing_is_stored(): void {
		$html = $this->render();
		foreach ( array_keys( wp_roles()->get_names() ) as $slug ) {
			$this->assertStringContainsString( 'name="aafm_oauth_connect_roles[]" value="' . $slug . '"', $html );
		}
		$this->assertMatchesRegularExpression( '/value="administrator" checked=[\'"]checked[\'"] disabled>/', $html );
		$this->assertContains( 'editor', $this->displayed_ticks() );
		$this->assertContains( 'contributor', $this->displayed_ticks() );
		$this->assertNotContains( 'subscriber', $this->displayed_ticks() );
		$this->assertStringContainsString( 'name="aafm_oauth_connect_roles_present" value="1"', $html );
	}

	public function test_a_save_without_the_marker_leaves_the_roles_alone(): void {
		$this->assertTrue( $this->save( array( 'aafm_oauth_connect_roles' => array( 'editor' ) ) )['success'] );
		$this->assertFalse( aafm_option_row( 'aafm_oauth_connect_roles' )['found'] );
	}

	public function test_a_save_with_the_marker_and_no_ticks_stores_administrators_only(): void {
		$this->assertTrue( $this->save( array( 'aafm_oauth_connect_roles_present' => '1' ) )['success'] );
		$this->assertSame( array( 'administrator' ), aafm_option_row( 'aafm_oauth_connect_roles' )['value'] );
	}

	public function test_unknown_role_slugs_are_dropped(): void {
		$this->save(
			array(
				'aafm_oauth_connect_roles_present' => '1',
				'aafm_oauth_connect_roles'         => array( 'editor', 'no_such_role' ),
			)
		);
		$this->assertSame( array( 'administrator', 'editor' ), aafm_option_row( 'aafm_oauth_connect_roles' )['value'] );
	}

	/**
	 * Malformed roles input.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function malformed_roles(): array {
		return array(
			'string'          => array( 'editor' ),
			'nested'          => array( array( array( 'editor' ) ) ),
			'oversized'       => array( array_fill( 0, 50, 'editor' ) ),
			'over-long entry' => array( array( str_repeat( 'a', 65 ) ) ),
			'integer entry'   => array( array( 5 ) ),
		);
	}

	/**
	 * Malformed roles input is refused before any write.
	 *
	 * @dataProvider malformed_roles
	 *
	 * @param mixed $roles Posted roles.
	 */
	public function test_malformed_roles_input_is_refused_and_the_stored_row_is_unchanged( $roles ): void {
		global $wpdb;
		$raw = static fn() => $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'aafm_oauth_connect_roles' ) );
		foreach ( array( null, array( 'administrator', 'editor' ) ) as $stored ) {
			if ( null === $stored ) {
				delete_option( 'aafm_oauth_connect_roles' );
			} else {
				update_option( 'aafm_oauth_connect_roles', $stored, false );
			}
			$before = $raw();
			$json   = $this->save(
				array(
					'aafm_oauth_connect_roles_present' => '1',
					'aafm_oauth_connect_roles'         => $roles,
					'aafm_rate_limit_per_min'          => '77',
				)
			);
			$this->assertFalse( $json['success'] );
			$this->assertSame( 'The roles list could not be read. Reload this page and try again.', $json['data']['message'] );
			$this->assertSame( $before, $raw() );
			$this->assertFalse( aafm_option_row( 'aafm_rate_limit_per_min' )['found'] );
			$this->assertSame( array(), $this->setting_rows() );
		}
	}

	public function test_saving_the_derived_default_unchanged_writes_nothing_and_logs_nothing(): void {
		$this->assertTrue(
			$this->save(
				array(
					'aafm_oauth_connect_roles_present' => '1',
					'aafm_oauth_connect_roles'         => $this->displayed_ticks(),
				)
			)['success']
		);
		$this->assertFalse( aafm_option_row( 'aafm_oauth_connect_roles' )['found'] );
		$this->assertSame( array(), $this->setting_rows() );
	}

	public function test_an_unrelated_settings_save_leaves_the_roles_option_absent_and_the_grant_working(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Roles',
				'is_active'   => 1,
			)
		);
		$contributor = get_userdata( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		aafm_oauth_record_consent( $contributor->ID, self::CLIENT );
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $contributor->ID,
				'client_id'  => self::CLIENT,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		$contributor->remove_role( 'contributor' );
		$contributor->add_role( 'subscriber' );
		$ticks = $this->displayed_ticks();

		$this->assertTrue(
			$this->save(
				array(
					'aafm_oauth_connect_roles_present' => '1',
					'aafm_oauth_connect_roles'         => $ticks,
					'aafm_max_title_len'               => '90',
				)
			)['success']
		);
		$this->assertFalse( aafm_option_row( 'aafm_oauth_connect_roles' )['found'] );
		$this->assertNull( aafm_oauth_connect_roles() );
		$this->assertTrue( aafm_oauth_user_still_may_connect( $contributor->ID ) );

		$_SERVER['REQUEST_URI']        = self::mcp_rest_path();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$this->route_as_rest_request();
		aafm_policy_reset_request_state();
		$this->assertSame( $contributor->ID, aafm_oauth_resolve_current_user( false ) );
	}

	public function test_the_row_says_when_a_developer_filter_overrides_the_list(): void {
		$this->assertStringNotContainsString( 'takes priority', $this->render() );
		add_filter( 'aafm_oauth_min_capability', static fn() => 'manage_options' );
		$this->assertStringContainsString( 'A setting in this site&#039;s code also controls this and takes priority.', $this->render() );
	}

	public function test_a_role_change_writes_one_setting_changed_row_naming_the_roles(): void {
		$this->save(
			array(
				'aafm_oauth_connect_roles_present' => '1',
				'aafm_oauth_connect_roles'         => array( 'editor' ),
			)
		);
		$this->assertSame( array( 'Roles that can connect changed to: Administrator, Editor' ), $this->setting_rows() );
	}

	public function test_the_row_says_a_removed_role_pauses_its_connections_until_it_is_added_back(): void {
		$this->assertStringContainsString( 'While a role is off this list, the connections of users who only have that role stop working. Adding the role back lets them work again, though an app that was already refused may need to reconnect.', $this->render() );
	}

	public function test_off_is_the_default_and_renders_selected(): void {
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/<option value="off" selected/', $html );
		$this->assertStringContainsString( '<div class="aafm-proxy-custom" hidden>', $html );
	}

	public function test_cloudflare_needs_no_other_field(): void {
		$this->assertTrue( $this->save( array( 'aafm_proxy_mode' => 'cloudflare' ) )['success'] );
		$this->assertSame( 'cloudflare', aafm_trusted_proxy_config()['mode'] );
	}

	public function test_custom_requires_a_header_from_the_closed_list_and_at_least_one_valid_range(): void {
		$json = $this->save(
			array(
				'aafm_proxy_mode'   => 'custom',
				'aafm_proxy_header' => 'X-Evil',
				'aafm_proxy_ranges' => '10.0.0.0/8',
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'Choose which header', $json['data']['message'] );
		$json = $this->save(
			array(
				'aafm_proxy_mode'   => 'custom',
				'aafm_proxy_header' => 'X-Real-IP',
				'aafm_proxy_ranges' => '',
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertTrue(
			$this->save(
				array(
					'aafm_proxy_mode'   => 'custom',
					'aafm_proxy_header' => 'X-Real-IP',
					'aafm_proxy_ranges' => "10.0.0.0/8\n2001:db8::/16",
				)
			)['success']
		);
		$this->assertSame(
			array(
				'mode'   => 'custom',
				'header' => 'X-Real-IP',
				'ranges' => array( '10.0.0.0/8', '2001:db8::/16' ),
			),
			aafm_trusted_proxy_config()
		);
	}

	public function test_wide_ranges_are_refused_with_a_message(): void {
		foreach ( array( '0.0.0.0/0', '::/0', '10.0.0.0/7', '2001::/15' ) as $wide ) {
			$json = $this->save(
				array(
					'aafm_proxy_mode'   => 'custom',
					'aafm_proxy_header' => 'X-Real-IP',
					'aafm_proxy_ranges' => $wide,
				)
			);
			$this->assertFalse( $json['success'], $wide );
			$this->assertStringContainsString( 'wider than /8', $json['data']['message'] );
		}
	}

	public function test_invalid_range_lines_are_counted_and_dropped_like_the_allowlist_does(): void {
		$clean = aafm_sanitize_proxy_input(
			array(
				'aafm_proxy_mode'   => 'custom',
				'aafm_proxy_header' => 'X-Real-IP',
				'aafm_proxy_ranges' => "10.0.0.0/8\ngarbage\n0.0.0.0/0\n10.0.0.0/8",
			)
		);
		$this->assertSame( array( '10.0.0.0/8' ), $clean['ranges'] );
	}

	public function test_nothing_is_written_when_validation_fails(): void {
		$json = $this->save(
			array(
				'aafm_proxy_mode'         => 'custom',
				'aafm_proxy_header'       => 'X-Real-IP',
				'aafm_proxy_ranges'       => '',
				'aafm_rate_limit_per_min' => '42',
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertFalse( aafm_option_row( 'aafm_rate_limit_per_min' )['found'] );
		$this->assertFalse( aafm_option_row( 'aafm_trusted_proxy' )['found'] );
	}

	public function test_the_card_shows_the_address_the_plugin_sees_now_and_the_connection_address(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertStringContainsString( 'As this plugin sees it now: 198.51.100.20. Connection address: 198.51.100.20.', $this->render() );
	}

	public function test_a_proxy_change_writes_one_row_and_no_ip_values(): void {
		$this->save(
			array(
				'aafm_proxy_mode'   => 'custom',
				'aafm_proxy_header' => 'X-Real-IP',
				'aafm_proxy_ranges' => '203.0.113.0/24',
			)
		);
		$rows = $this->setting_rows();
		$this->assertSame( array( 'Visitor address behind a proxy set to custom (1 ranges)' ), $rows );
		$this->assertStringNotContainsString( '203.0.113', $rows[0] );
	}

	public function test_the_proxy_write_comes_after_the_allowlist_write(): void {
		$json = QueryFaultInjector::fail_query(
			array( 'INSERT INTO', 'aafm_ip_allowlist' ),
			fn() => $this->save(
				array(
					'aafm_ip_allowlist' => '198.51.100.0/24',
					'aafm_proxy_mode'   => 'cloudflare',
				)
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertFalse( aafm_option_row( 'aafm_trusted_proxy' )['found'] );
	}

	public function test_enabling_a_proxy_with_a_non_empty_allowlist_returns_a_warning(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$json                   = $this->save(
			array(
				'aafm_ip_allowlist' => '198.51.100.0/24',
				'aafm_proxy_mode'   => 'cloudflare',
			)
		);
		$this->assertTrue( $json['success'] );
		$this->assertSame( 'With this setting the plugin reads this request as 198.51.100.20, which your IP allowlist admits.', $json['data']['warning'] );

		$json = $this->save(
			array(
				'aafm_ip_allowlist' => '',
				'aafm_proxy_mode'   => 'cloudflare',
			)
		);
		$this->assertSame( '', $json['data']['warning'] );
		$this->assertStringContainsString( 'Authenticated Origin Pulls', $this->render() );
	}

	public function test_an_array_param_is_refused_cleanly(): void {
		$json = $this->save( array( 'aafm_proxy_mode' => array( 'custom' ) ) );
		$this->assertFalse( $json['success'], 'A present but malformed mode is refused, not reported as saved.' );
		$this->assertFalse( aafm_option_row( 'aafm_trusted_proxy' )['found'] );
		$json = $this->save(
			array(
				'aafm_proxy_mode'   => 'custom',
				'aafm_proxy_header' => array( 'X-Real-IP' ),
				'aafm_proxy_ranges' => array( '10.0.0.0/8' ),
			)
		);
		$this->assertFalse( $json['success'] );
	}
}
