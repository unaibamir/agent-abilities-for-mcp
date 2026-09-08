<?php
/**
 * Shared base test case.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use WP_UnitTestCase;

/**
 * Base class for all plugin tests. Resets the enabled-abilities option between tests.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Reset plugin state before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();
		delete_option( 'aafm_enabled_abilities' );
		// The high-risk floor is off-by-default, and a test that finds it already lifted would assert
		// against a security posture no fresh install has. It needs an explicit reset for the same
		// reason the enabled-abilities option above does: aafm_clear_activity_log() issues a TRUNCATE,
		// which MySQL treats as DDL and implicitly commits, so any option a suite writes in its own
		// set_up before calling it escapes the per-test rollback and lands in the next test.
		delete_option( 'aafm_high_risk_abilities_unlocked' );
		// Read-only mode, off-by-default and escaping the rollback the same way for the same
		// TRUNCATE reason. A suite that leaves it on would silently subtract every write ability
		// from the next test's registered set.
		delete_option( 'aafm_read_only_mode' );
		// The registry catalog is memoized per request; tests mutate the
		// aafm_abilities_registry filter set between cases, so start each one with a
		// fresh build (the next registry read rebuilds).
		if ( function_exists( 'aafm_flush_registry_cache' ) ) {
			aafm_flush_registry_cache();
		}
	}

	/**
	 * Tear down plugin state after each test.
	 *
	 * WP_UnitTestCase does not unregister post types created mid-test, so a throwaway
	 * `aafm_*` CPT registered in one method leaks into the next and breaks tests that
	 * assert its absence. Unregister those and clear the exposed-types option so every
	 * CPT/admin case starts from a clean registry and a clean allowlist.
	 */
	public function tear_down(): void {
		foreach ( array_keys( get_post_types() ) as $type ) {
			// aafm_*: this plugin's own throwaway CPT test fixtures. tribe_*: the real TEC post
			// types TecStubStore::aafm_tec_stub_register_post_types() registers for real (so
			// current_user_can()/map_meta_cap() behavior is genuinely exercised). gd_place: the
			// real GeoDirectory post type GeodirStubStore::aafm_geodir_stub_activate() registers
			// the same way - without this, a public CPT registered once (register_post_type()
			// cannot be "unregistered" between PHP-process-wide class/function definitions) leaks
			// into aafm_eligible_post_types() for every later test in the same process, breaking
			// tests that assume no eligible custom post type is registered.
			if ( 0 === strncmp( $type, 'aafm_', 5 ) || 0 === strncmp( $type, 'tribe_', 6 ) || 'gd_place' === $type ) {
				unregister_post_type( $type );
			}
		}
		delete_option( 'aafm_allowed_post_types' );
		// M16: the resolved-client_id store is a process-wide static (see the file header on
		// aafm_oauth_current_client_id()), so a test that resolves an OAuth bearer must not leak
		// that client_id into an unrelated test's activity-log assertions.
		if ( function_exists( 'aafm_oauth_current_client_id' ) ) {
			aafm_oauth_current_client_id( '' );
		}
		parent::tear_down();
	}

	/**
	 * Whether the activity log table exists for the current blog.
	 *
	 * The WordPress test suite rewrites every plugin `CREATE TABLE` / `DROP TABLE`
	 * to its `TEMPORARY` form so each test gets an isolated, rolled-back table.
	 * `SHOW TABLES` does not list temporary tables, so existence is probed with a
	 * trivial select instead, which sees the temporary table the same way the
	 * plugin's own queries do.
	 *
	 * @return bool
	 */
	protected function activity_log_table_exists(): bool {
		global $wpdb;
		$table      = $wpdb->prefix . 'aafm_activity_log';
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );
		return '' === $error;
	}

	/**
	 * Create a user with a single explicit role and switch to it.
	 *
	 * @param string $role WordPress role slug.
	 * @return int User ID.
	 */
	protected function acting_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * Core's wp_register_ability()/wp_register_ability_category() refuse to run unless
	 * their gated init action is doing_action(); simulate that by pushing the action
	 * name onto $wp_current_filter - the idiom WP core's own ability test trait uses.
	 * We do NOT call do_action() on the core hook directly: that trips the WPCS
	 * NonPrefixedHooknameFound sniff (Phase 1 carried issue).
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	protected function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable a set of abilities and register them through the Abilities API init action.
	 *
	 * The recurring two-step idiom across the ability suites: write the enabled-abilities
	 * option, then run aafm_register_enabled_abilities() inside a simulated
	 * wp_abilities_api_init action so the enabled slugs actually register.
	 *
	 * @param string[] $slugs Ability slugs to enable and register.
	 */
	protected function register_enabled( array $slugs ): void {
		update_option( 'aafm_enabled_abilities', $slugs );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}
}
