<?php
/**
 * Per-site uninstall cleanup removes the option and the log table.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class UninstallTest extends TestCase {

	/**
	 * When aafm_delete_data_on_uninstall is not set (default), aafm_uninstall_site_data()
	 * must be a no-op: config options, the activity-log table, the OAuth tables, and the
	 * OAuth schema-version option must all survive.
	 */
	public function test_uninstall_keeps_data_when_flag_not_set(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_force_draft', true );
		// Confirm the flag is absent (default keep-path).
		delete_option( 'aafm_delete_data_on_uninstall' );

		aafm_uninstall_site_data();

		// Config options survive.
		$this->assertNotFalse( get_option( 'aafm_enabled_abilities' ), 'aafm_enabled_abilities must survive when flag is off.' );
		$this->assertNotFalse( get_option( 'aafm_force_draft' ), 'aafm_force_draft must survive when flag is off.' );
		// Activity log table survives.
		$this->assertTrue( $this->activity_log_table_exists(), 'Activity log table must survive when flag is off.' );
		// OAuth schema version survives (proxy for OAuth tables still present).
		$this->assertNotFalse( get_option( 'aafm_oauth_schema_version' ), 'aafm_oauth_schema_version must survive when flag is off.' );
	}

	/**
	 * Team-lead item A: the default (retain-data) uninstall path used to return before ever
	 * reaching either wp_clear_scheduled_hook() call, leaving both daily cron events behind
	 * with no plugin left to run their callbacks. Cron registrations are executable plugin
	 * machinery, not retained user data, so they must go regardless of the retention choice.
	 */
	public function test_uninstall_clears_both_cron_events_when_flag_not_set(): void {
		delete_option( 'aafm_delete_data_on_uninstall' );
		wp_schedule_event( time(), 'daily', 'aafm_prune_activity_log_daily' );
		wp_schedule_event( time(), 'daily', 'aafm_oauth_cleanup' );
		$this->assertNotFalse( wp_next_scheduled( 'aafm_prune_activity_log_daily' ), 'precondition: the prune event must be scheduled.' );
		$this->assertNotFalse( wp_next_scheduled( 'aafm_oauth_cleanup' ), 'precondition: the OAuth cleanup event must be scheduled.' );

		aafm_uninstall_site_data();

		$this->assertFalse( wp_next_scheduled( 'aafm_prune_activity_log_daily' ), 'the prune event must be cleared even when data is retained.' );
		$this->assertFalse( wp_next_scheduled( 'aafm_oauth_cleanup' ), 'the OAuth cleanup event must be cleared even when data is retained.' );
	}

	/**
	 * When aafm_delete_data_on_uninstall is explicitly set to true, aafm_uninstall_site_data()
	 * must run the full teardown: all config options gone, activity-log table dropped, OAuth
	 * schema-version option gone, and the flag option itself must also be gone.
	 */
	public function test_uninstall_wipes_everything_when_flag_is_set(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_force_draft', true );
		update_option( 'aafm_oauth_schema_version', '4' );
		update_option( 'aafm_delete_data_on_uninstall', true );

		aafm_uninstall_site_data();

		// Every config option is gone.
		foreach ( aafm_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} must be deleted when the uninstall flag is set." );
		}
		// Activity log table is gone.
		$this->assertFalse( $this->activity_log_table_exists(), 'Activity log table must be dropped when flag is set.' );
		// OAuth schema version is gone.
		$this->assertFalse( get_option( 'aafm_oauth_schema_version', false ), 'aafm_oauth_schema_version must be deleted when flag is set.' );
		// The flag itself must not leak.
		$this->assertFalse( get_option( 'aafm_delete_data_on_uninstall', false ), 'aafm_delete_data_on_uninstall must be deleted after the wipe.' );
	}

	/**
	 * The one-time OAuth upgrade-preserve guard is a real stored option, so a delete-data uninstall
	 * must remove it rather than orphan it. It must NOT be swept by reset: clearing it on reset would
	 * let the preserve migration re-run and could flip OAuth back on after a reset.
	 */
	public function test_uninstall_removes_oauth_toggle_migrated_but_reset_keeps_it(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();

		// Reset leaves the guard in place (it is not part of the reset set).
		update_option( 'aafm_oauth_toggle_migrated', '1', true );
		aafm_reset_plugin();
		$this->assertSame( '1', get_option( 'aafm_oauth_toggle_migrated' ), 'Reset must not clear the OAuth preserve guard.' );
		$this->assertNotContains( 'aafm_oauth_toggle_migrated', aafm_config_option_names(), 'The guard must stay out of the reset set.' );

		// Delete-data uninstall removes it.
		update_option( 'aafm_oauth_toggle_migrated', '1', true );
		update_option( 'aafm_delete_data_on_uninstall', true );
		aafm_uninstall_site_data();
		$this->assertFalse( get_option( 'aafm_oauth_toggle_migrated', false ), 'Uninstall-with-delete-data must remove the OAuth preserve guard.' );
	}

	/**
	 * Team-lead item A, the delete-data path: both cron events must still be cleared, the same
	 * as the retain-data path - the unconditional clear at the top of the function must not
	 * regress once the full teardown below it also runs.
	 */
	public function test_uninstall_clears_both_cron_events_when_flag_is_set(): void {
		update_option( 'aafm_delete_data_on_uninstall', true );
		wp_schedule_event( time(), 'daily', 'aafm_prune_activity_log_daily' );
		wp_schedule_event( time(), 'daily', 'aafm_oauth_cleanup' );

		aafm_uninstall_site_data();

		$this->assertFalse( wp_next_scheduled( 'aafm_prune_activity_log_daily' ), 'the prune event must be cleared when data is deleted.' );
		$this->assertFalse( wp_next_scheduled( 'aafm_oauth_cleanup' ), 'the OAuth cleanup event must be cleared when data is deleted.' );
	}

	/**
	 * Team-lead item A, multisite: the plugin's own deactivation callbacks only clear cron in
	 * the CURRENT blog context, so they cannot close this gap on a network. uninstall.php's
	 * aafm_run_uninstall() reaches every site by switching into it before calling
	 * aafm_uninstall_site_data() - this pins that a switched-to subsite's own cron gets
	 * cleared, not just the main site's.
	 *
	 * @group ms-required
	 */
	public function test_uninstall_clears_cron_on_a_switched_multisite_blog(): void {
		$this->skipWithoutMultisite();
		$blog_id = (int) self::factory()->blog->create();

		switch_to_blog( $blog_id );
		delete_option( 'aafm_delete_data_on_uninstall' );
		wp_schedule_event( time(), 'daily', 'aafm_prune_activity_log_daily' );
		wp_schedule_event( time(), 'daily', 'aafm_oauth_cleanup' );
		$this->assertNotFalse( wp_next_scheduled( 'aafm_prune_activity_log_daily' ), 'precondition: the subsite must have the prune event scheduled.' );

		aafm_uninstall_site_data();

		$prune_after = wp_next_scheduled( 'aafm_prune_activity_log_daily' );
		$oauth_after = wp_next_scheduled( 'aafm_oauth_cleanup' );
		restore_current_blog();

		$this->assertFalse( $prune_after, 'the prune event must be cleared on the switched-to subsite.' );
		$this->assertFalse( $oauth_after, 'the OAuth cleanup event must be cleared on the switched-to subsite.' );
	}

	/**
	 * 1.7.2 bug #7: aafm_config_option_names() never lists aafm_oauth_access_ttl or
	 * aafm_oauth_refresh_ttl (the two OAuth token-lifetime overrides read by
	 * includes/oauth/tokens.php), so a delete-data uninstall leaves both behind instead of wiping
	 * "all my data" as promised.
	 *
	 * RED against the current code: both options survive aafm_uninstall_site_data() with the
	 * delete-data flag set, because they are outside the config-option list every other option in
	 * this test relies on.
	 */
	public function test_uninstall_wipes_oauth_ttl_overrides_when_flag_is_set(): void {
		update_option( 'aafm_oauth_access_ttl', 1234 );
		update_option( 'aafm_oauth_refresh_ttl', 5678 );
		update_option( 'aafm_delete_data_on_uninstall', true );

		aafm_uninstall_site_data();

		$this->assertFalse( get_option( 'aafm_oauth_access_ttl', false ), 'aafm_oauth_access_ttl must be deleted when the uninstall flag is set.' );
		$this->assertFalse( get_option( 'aafm_oauth_refresh_ttl', false ), 'aafm_oauth_refresh_ttl must be deleted when the uninstall flag is set.' );
	}

	/**
	 * BLOCKER: aafm_uninstall_site_data() must decide whether to wipe the site from the actual
	 * database row, never from get_option()'s cache. Plants the exact shape a stale persistent
	 * object cache leaves behind - the row for aafm_delete_data_on_uninstall gone (or never
	 * written), the autoloaded blob still claiming it is "1" - and asserts nothing is dropped: a
	 * stale cached true must never authorize a wipe the database itself does not back.
	 */
	public function test_uninstall_keeps_data_when_db_row_is_absent_despite_a_stale_cached_true(): void {
		global $wpdb;
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_oauth_schema_version', '4' );

		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_delete_data_on_uninstall' ) );
		$all                                       = wp_load_alloptions( true );
		$all['aafm_delete_data_on_uninstall']      = '1';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertTrue( (bool) get_option( 'aafm_delete_data_on_uninstall', false ), 'Precondition: the stale cache is what get_option() sees.' );
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'aafm_delete_data_on_uninstall' ) ),
			'Precondition: no DB row backs the cached true.'
		);

		aafm_uninstall_site_data();

		$this->assertSame( array( 'aafm/get-posts' ), get_option( 'aafm_enabled_abilities' ), 'A stale cached true must not authorize a wipe when the database row is absent.' );
		$this->assertTrue( $this->activity_log_table_exists(), 'The activity log table must survive a cache-only "true".' );
		$this->assertSame( '4', get_option( 'aafm_oauth_schema_version' ), 'aafm_oauth_schema_version must survive a cache-only "true".' );
	}

	/**
	 * The mirror image, and the reason this cannot simply always retain data: when the database
	 * row genuinely says true, the wipe must still proceed, whatever the cache happens to hold.
	 */
	public function test_uninstall_wipes_everything_when_the_db_row_is_genuinely_true(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_oauth_schema_version', '4' );
		update_option( 'aafm_delete_data_on_uninstall', true );
		// A stale cache in the other direction must not block a genuine, DB-backed wipe either.
		wp_cache_delete( 'aafm_delete_data_on_uninstall', 'options' );

		aafm_uninstall_site_data();

		$this->assertFalse( get_option( 'aafm_enabled_abilities', false ), 'A genuinely stored true must still authorize the wipe.' );
		$this->assertFalse( $this->activity_log_table_exists(), 'Activity log table must be dropped when the DB row is genuinely true.' );
		$this->assertFalse( get_option( 'aafm_oauth_schema_version', false ), 'aafm_oauth_schema_version must be deleted when the DB row is genuinely true.' );
		$this->assertFalse( get_option( 'aafm_delete_data_on_uninstall', false ), 'The flag itself must not leak after the wipe.' );
	}

	/**
	 * Multisite variant of the retention case above: a subsite's own cache must not be able to
	 * authorize a wipe of that subsite's data when its own options table disagrees.
	 *
	 * @group ms-required
	 */
	public function test_uninstall_keeps_data_on_a_switched_multisite_blog_despite_a_stale_cached_true(): void {
		global $wpdb;
		$this->skipWithoutMultisite();
		$blog_id = (int) self::factory()->blog->create();

		switch_to_blog( $blog_id );
		aafm_install_activity_log();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_delete_data_on_uninstall' ) );
		$all                                  = wp_load_alloptions( true );
		$all['aafm_delete_data_on_uninstall'] = '1';
		wp_cache_set( 'alloptions', $all, 'options' );

		aafm_uninstall_site_data();

		$kept_enabled = get_option( 'aafm_enabled_abilities' );
		$log_survived = $this->activity_log_table_exists();
		restore_current_blog();

		$this->assertSame( array( 'aafm/get-posts' ), $kept_enabled, 'A stale cached true on a subsite must not authorize wiping that subsite.' );
		$this->assertTrue( $log_survived, "The subsite's activity log table must survive a cache-only \"true\"." );
	}

	public function test_cleanup_drops_table_and_option(): void {
		aafm_install_activity_log();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$this->assertTrue( $this->activity_log_table_exists() );

		aafm_uninstall_site();

		$this->assertFalse( get_option( 'aafm_enabled_abilities' ) );
		$this->assertFalse( $this->activity_log_table_exists() );
	}

	/**
	 * Uninstall must delete the FULL configuration option set, not just the hardcoded
	 * enabled-abilities literal - this proves the pre-existing leak fix (aafm_allowed_meta_keys
	 * plus the Slice C options all survived uninstall before). It must also drop the
	 * detected-keys transient, the only outside-config-list row in the same defect class.
	 *
	 * Asserts via get_option/get_transient === false, never a table probe (the temp-table CI
	 * lesson: the suite's DROP TABLE is rewritten to its TEMPORARY form).
	 */
	public function test_cleanup_removes_all_config_options_and_detected_keys_transient(): void {
		aafm_install_activity_log();
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		update_option( 'aafm_denied_meta_keys', array( 'secret_key' ) );
		update_option( 'aafm_exposed_user_meta_keys', array( 'profile_color' ) );
		update_option( 'aafm_denied_user_meta_keys', array( 'private_note' ) );
		update_option( 'aafm_exposed_term_meta_keys', array( 'seo_title' ) );
		update_option( 'aafm_denied_term_meta_keys', array( 'term_secret' ) );
		set_transient( 'aafm_detected_meta_keys', array( 'x' ), HOUR_IN_SECONDS );

		aafm_uninstall_site();

		foreach ( aafm_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} should be deleted by uninstall." );
		}
		$this->assertFalse( get_transient( 'aafm_detected_meta_keys' ) );
	}
}
