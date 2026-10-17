<?php
/**
 * Tests for the fresh-install roles seed and the stamp that waits for it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Only a store with no stamp, no client and no consent row is seeded Administrators only; a seed that
 * cannot be certified withholds the stamp; an upgraded site is never seeded.
 */
class FreshInstallRolesTest extends TestCase {

	use AjaxHarness;

	/**
	 * $_SERVER as set_up() found it.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['HTTPS'] = 'on';
		aafm_install_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		$this->fresh_store();
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		remove_all_filters( 'query' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		$this->set_permalink_structure( '' );
		$_SERVER = $this->server;
		SchemaV9Test::restore_schema();
		parent::tear_down();
	}

	/**
	 * A store that has never used OAuth: no stamp, no client, no consent, no roles row.
	 *
	 * @return void
	 */
	private function fresh_store(): void {
		global $wpdb;
		aafm_truncate_oauth_tables();
		foreach ( array( 'aafm_oauth_schema_version', 'aafm_oauth_connect_roles' ) as $option ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
			wp_cache_delete( $option, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		aafm_policy_reset_request_state();
	}

	/**
	 * Whether an option row exists, read past every cache.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function row_exists( string $option ): bool {
		return aafm_read_option_views( $option )['db_found'];
	}

	/**
	 * The stored roles row value.
	 *
	 * @return mixed
	 */
	private function roles_row() {
		return aafm_read_option_views( 'aafm_oauth_connect_roles' )['db_value'];
	}

	/**
	 * Arm a fault on the first write of one option (an INSERT naming it).
	 *
	 * @param string $option Option name.
	 * @return callable The filter, to remove.
	 */
	private function fail_write_of( string $option ): callable {
		$filter = QueryFaultInjector::no_flush_filter( array( 'INSERT INTO', $option ) );
		add_filter( 'query', $filter );
		return $filter;
	}

	/**
	 * Move the upgrade lock's expiry into the past, which is what the hour does.
	 *
	 * @return void
	 */
	private function age_the_lock(): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 10 ) ), array( 'option_name' => '_transient_timeout_aafm_oauth_upgrade_lock' ) );
		wp_cache_delete( '_transient_timeout_aafm_oauth_upgrade_lock', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * A user of a role.
	 *
	 * @param string $role Role slug.
	 * @return \WP_User
	 */
	private function user( string $role ): \WP_User {
		return get_userdata( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_a_fresh_activation_seeds_administrators_only(): void {
		aafm_activate();
		$this->assertSame( array( 'administrator' ), $this->roles_row() );
		$this->assertSame( AAFM_OAUTH_SCHEMA_VERSION, aafm_read_option_views( 'aafm_oauth_schema_version' )['db_value'] );
	}

	public function test_an_install_with_a_schema_row_is_not_seeded(): void {
		foreach ( array( '8', '9' ) as $version ) {
			$this->fresh_store();
			update_option( 'aafm_oauth_schema_version', $version );
			$this->assertTrue( aafm_seed_fresh_install_defaults() );
			$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ), $version );
		}
	}

	public function test_a_site_with_a_client_or_consent_row_is_not_seeded(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id' => 'used',
				'is_active' => 0,
			)
		);
		$this->assertTrue( aafm_seed_fresh_install_defaults() );
		$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ) );

		$this->fresh_store();
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => 2,
				'client_id'  => 'used',
			)
		);
		$this->assertTrue( aafm_seed_fresh_install_defaults() );
		$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ) );
	}

	public function test_a_plugin_option_written_before_the_seed_does_not_stop_it(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_oauth_enabled', '1' );
		$this->assertTrue( aafm_seed_fresh_install_defaults() );
		$this->assertSame( array( 'administrator' ), $this->roles_row() );
	}

	public function test_reactivation_never_overwrites_the_owners_list(): void {
		aafm_activate();
		$this->assertTrue( aafm_update_option_verified( 'aafm_oauth_connect_roles', array( 'administrator', 'editor' ), false ) );
		aafm_activate();
		$this->assertSame( array( 'administrator', 'editor' ), $this->roles_row() );
	}

	public function test_an_unreadable_database_does_not_seed_or_stamp(): void {
		global $wpdb;
		$probes = array(
			"option_name = 'aafm_oauth_connect_roles'",
			"option_name = 'aafm_oauth_schema_version'",
			'FROM `' . $wpdb->prefix . 'aafm_oauth_clients` LIMIT 1',
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents` LIMIT 1',
		);
		foreach ( $probes as $probe ) {
			$this->fresh_store();
			$result = QueryFaultInjector::fail_query(
				$probe,
				static fn() => array( aafm_seed_fresh_install_defaults(), aafm_install_oauth_tables() )
			);
			$this->assertSame( array( false, false ), $result, $probe );
			$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ), $probe );
			$this->assertFalse( $this->row_exists( 'aafm_oauth_schema_version' ), $probe );
		}
	}

	public function test_an_unreadable_prior_use_probe_seeds_nothing_and_keeps_the_legacy_floor(): void {
		global $wpdb;
		$editor = $this->user( 'editor' );
		$probes = array(
			"option_name = 'aafm_oauth_schema_version'",
			'FROM `' . $wpdb->prefix . 'aafm_oauth_clients` LIMIT 1',
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents` LIMIT 1',
		);
		foreach ( $probes as $probe ) {
			$this->fresh_store();
			$result = QueryFaultInjector::fail_query(
				$probe,
				static fn() => array( aafm_seed_fresh_install_defaults(), aafm_oauth_user_may_connect( $editor ) )
			);
			$this->assertSame( array( false, true ), $result, $probe );
			$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ), $probe );
			$this->assertFalse( $this->row_exists( 'aafm_oauth_schema_version' ), $probe );
		}
	}

	public function test_a_failed_seed_write_withholds_the_stamp_and_the_hourly_retry_seeds_it(): void {
		$editor = $this->user( 'editor' );
		$admin  = $this->user( 'administrator' );
		delete_transient( 'aafm_oauth_schema_error' );

		$fault = $this->fail_write_of( 'aafm_oauth_connect_roles' );
		$this->assertFalse( aafm_install_oauth_tables() );
		$this->assertFalse( $this->row_exists( 'aafm_oauth_schema_version' ) );
		$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ) );
		$this->assertNotFalse( get_transient( 'aafm_oauth_schema_error' ) );
		$this->assertFalse( aafm_oauth_user_may_connect( $editor ) );
		$this->assertTrue( aafm_oauth_user_may_connect( $admin ) );

		aafm_maybe_upgrade_oauth_tables();
		$this->assertNotFalse( get_transient( 'aafm_oauth_upgrade_lock' ), 'A failed retry holds the lock.' );
		remove_filter( 'query', $fault );

		aafm_maybe_upgrade_oauth_tables();
		$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ), 'Nothing runs while the lock is held.' );

		$this->age_the_lock();
		aafm_maybe_upgrade_oauth_tables();
		$this->assertSame( array( 'administrator' ), $this->roles_row() );
		$this->assertSame( '9', aafm_read_option_views( 'aafm_oauth_schema_version' )['db_value'] );
	}

	public function test_settings_saved_during_the_retry_lock_do_not_skip_the_seed_or_open_approvals(): void {
		$editor = $this->user( 'editor' );
		$fault  = $this->fail_write_of( 'aafm_oauth_connect_roles' );
		aafm_install_oauth_tables();
		aafm_maybe_upgrade_oauth_tables();
		$this->assertNotFalse( get_transient( 'aafm_oauth_upgrade_lock' ) );

		update_option( 'aafm_oauth_enabled', '1' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$this->run_settings_save(
			array(
				'aafm_oauth_enabled'               => '1',
				'aafm_oauth_connect_roles_present' => '1',
				'aafm_oauth_connect_roles'         => array( 'administrator', 'editor', 'author', 'contributor' ),
			)
		);
		$this->assertFalse( aafm_oauth_user_may_connect( $editor ) );
		$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ) );

		remove_filter( 'query', $fault );
		$this->age_the_lock();
		aafm_maybe_upgrade_oauth_tables();
		aafm_policy_reset_request_state();
		$this->assertSame( array( 'administrator' ), $this->roles_row() );
		$this->assertSame( '9', aafm_read_option_views( 'aafm_oauth_schema_version' )['db_value'] );
		$this->assertFalse( aafm_oauth_user_may_connect( $editor ) );
	}

	public function test_reactivation_retries_a_failed_seed_at_once(): void {
		$fault = $this->fail_write_of( 'aafm_oauth_connect_roles' );
		aafm_install_oauth_tables();
		aafm_maybe_upgrade_oauth_tables();
		remove_filter( 'query', $fault );
		$this->assertNotFalse( get_transient( 'aafm_oauth_upgrade_lock' ) );

		aafm_activate();
		$this->assertSame( array( 'administrator' ), $this->roles_row() );
		$this->assertSame( '9', aafm_read_option_views( 'aafm_oauth_schema_version' )['db_value'] );
		$this->assertNotFalse( get_transient( 'aafm_oauth_upgrade_lock' ), 'Activation neither needs nor touches the lock.' );
	}

	public function test_an_unstamped_site_with_an_editors_grant_keeps_it_working_before_and_after_the_stamp(): void {
		global $wpdb;
		$editor = $this->user( 'editor' );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id' => 'old_app',
				'is_active' => 1,
			)
		);
		aafm_oauth_record_consent( $editor->ID, 'old_app' );
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $editor->ID,
				'client_id'  => 'old_app',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );

		$works = function ( string $phase ) use ( &$tokens, $editor ): void {
			aafm_policy_reset_request_state();
			$_SERVER['REQUEST_URI']        = self::mcp_rest_path();
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
			$this->route_as_rest_request();
			$this->assertSame( $editor->ID, aafm_oauth_resolve_current_user( false ), "token, $phase" );
			wp_set_current_user( $editor->ID );
			$this->assertTrue( aafm_transport_permission_callback( new \WP_REST_Request( 'POST', aafm_mcp_rest_route() ) ), "transport, $phase" );
			$rotated = aafm_oauth_rotate_refresh( $tokens['refresh_token'], 'old_app' );
			$this->assertIsArray( $rotated, "refresh, $phase" );
			$tokens = $rotated;
			$this->assertTrue( aafm_oauth_user_may_connect( $editor ), "approval floor, $phase" );
			$this->assertFalse( $this->row_exists( 'aafm_oauth_connect_roles' ), "no roles row, $phase" );
		};

		$works( 'before' );

		$fault = $this->fail_write_of( 'aafm_oauth_schema_version' );
		aafm_maybe_upgrade_oauth_tables();
		$this->assertFalse( $this->row_exists( 'aafm_oauth_schema_version' ) );
		$this->assertNotFalse( get_transient( 'aafm_oauth_upgrade_lock' ) );
		$works( 'while the stamp fails' );
		remove_filter( 'query', $fault );

		$this->age_the_lock();
		aafm_maybe_upgrade_oauth_tables();
		$this->assertSame( '9', aafm_read_option_views( 'aafm_oauth_schema_version' )['db_value'] );
		$works( 'after' );
	}
}
