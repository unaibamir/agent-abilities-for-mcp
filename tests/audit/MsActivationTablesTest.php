<?php
/**
 * Network activation must install the plugin tables on every site (finding B36).
 *
 * Activation hooks run once, on the main site, and register_activation_hook passes the
 * $network_wide flag for exactly this reason: without honoring it, only the main site
 * gets the activity-log and OAuth tables, while every subsite serving MCP loses its
 * audit rows and cannot store OAuth clients or tokens. uninstall.php already loops
 * get_sites(), so install and uninstall disagreed. These tests pin the repaired
 * activation path (aafm_activate) and the wp_initialize_site wiring that covers sites
 * created after activation.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class MsActivationTablesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	/**
	 * Whether the OAuth clients table exists for the current blog.
	 *
	 * Same trivial-select probe as activity_log_table_exists(): the test suite rewrites
	 * plugin CREATE TABLE to TEMPORARY tables, which SHOW TABLES cannot see.
	 *
	 * @return bool
	 */
	private function oauth_clients_table_exists(): bool {
		global $wpdb;
		$table      = $wpdb->prefix . 'aafm_oauth_clients';
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );
		return '' === $error;
	}

	/**
	 * B36 part one: network-wide activation loops every site and installs both schemas
	 * per site, matching the get_sites() loop uninstall.php already runs.
	 */
	public function test_network_activation_installs_tables_on_every_site(): void {
		$blog_id = (int) self::factory()->blog->create();

		// The fresh subsite starts without the plugin tables - this is the reported gap.
		switch_to_blog( $blog_id );
		$log_before   = $this->activity_log_table_exists();
		$oauth_before = $this->oauth_clients_table_exists();
		restore_current_blog();
		$this->assertFalse( $log_before, 'precondition: a fresh subsite has no activity log table.' );
		$this->assertFalse( $oauth_before, 'precondition: a fresh subsite has no OAuth tables.' );

		aafm_activate( true );

		switch_to_blog( $blog_id );
		$log_after   = $this->activity_log_table_exists();
		$oauth_after = $this->oauth_clients_table_exists();
		restore_current_blog();
		$this->assertTrue( $log_after, 'network activation must create the activity log table on every site.' );
		$this->assertTrue( $oauth_after, 'network activation must create the OAuth tables on every site.' );

		// The main site is installed by the same pass.
		$this->assertTrue( $this->activity_log_table_exists(), 'the main site gets its tables too.' );
	}

	/**
	 * Single-site-shaped activation (the flag false) must keep its current-site-only
	 * behavior: install here, and never touch another site.
	 */
	public function test_non_network_activation_installs_current_site_only(): void {
		$blog_id = (int) self::factory()->blog->create();

		aafm_activate( false );

		$this->assertTrue( $this->activity_log_table_exists(), 'per-site activation installs the current site.' );

		switch_to_blog( $blog_id );
		$other_site_log = $this->activity_log_table_exists();
		restore_current_blog();
		$this->assertFalse( $other_site_log, 'per-site activation must not reach into other sites.' );
	}

	/**
	 * B36 part two: a site created AFTER network activation gets its tables from the
	 * wp_initialize_site hook, gated on the plugin being network-active.
	 */
	public function test_new_site_gets_tables_when_plugin_is_network_active(): void {
		update_site_option( 'active_sitewide_plugins', array( AAFM_PLUGIN_BASENAME => time() ) );

		$blog_id = (int) self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$log_exists   = $this->activity_log_table_exists();
		$oauth_exists = $this->oauth_clients_table_exists();
		restore_current_blog();

		$this->assertTrue( $log_exists, 'a site created after network activation must get the activity log table.' );
		$this->assertTrue( $oauth_exists, 'a site created after network activation must get the OAuth tables.' );
	}

	/**
	 * Without network activation the initializer stays out of new sites - a per-site
	 * install must not leak schema into unrelated subsites.
	 */
	public function test_new_site_untouched_when_plugin_not_network_active(): void {
		$blog_id = (int) self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$log_exists = $this->activity_log_table_exists();
		restore_current_blog();

		$this->assertFalse( $log_exists, 'a new site must stay untouched when the plugin is not network-active.' );
	}

	/**
	 * The initializer must run after core's own wp_initialize_site (priority 10), which
	 * creates the new site's base tables and options; ours needs that infrastructure.
	 */
	public function test_initializer_hooked_after_core(): void {
		$priority = has_action( 'wp_initialize_site', 'aafm_initialize_new_site_tables' );
		$this->assertIsInt( $priority, 'aafm_initialize_new_site_tables must be hooked on wp_initialize_site.' );
		$this->assertGreaterThan( 10, $priority, 'it must run after core wp_initialize_site() has built the new site.' );
	}
}
