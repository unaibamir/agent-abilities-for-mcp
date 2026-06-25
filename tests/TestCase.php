<?php
/**
 * Shared base test case.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests;

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
		delete_option( 'oversio_enabled_abilities' );
		// The registry catalog is memoized per request; tests mutate the
		// oversio_abilities_registry filter set between cases, so start each one with a
		// fresh build (the next registry read rebuilds).
		if ( function_exists( 'oversio_flush_registry_cache' ) ) {
			oversio_flush_registry_cache();
		}
	}

	/**
	 * Tear down plugin state after each test.
	 *
	 * WP_UnitTestCase does not unregister post types created mid-test, so a throwaway
	 * `oversio_*` CPT registered in one method leaks into the next and breaks tests that
	 * assert its absence. Unregister those and clear the exposed-types option so every
	 * CPT/admin case starts from a clean registry and a clean allowlist.
	 */
	public function tear_down(): void {
		foreach ( array_keys( get_post_types() ) as $type ) {
			if ( 0 === strncmp( $type, 'oversio_', 5 ) ) {
				unregister_post_type( $type );
			}
		}
		delete_option( 'oversio_allowed_post_types' );
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
		$table      = $wpdb->prefix . 'oversio_activity_log';
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
	 * name onto $wp_current_filter — the idiom WP core's own ability test trait uses.
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
	 * option, then run oversio_register_enabled_abilities() inside a simulated
	 * wp_abilities_api_init action so the enabled slugs actually register.
	 *
	 * @param string[] $slugs Ability slugs to enable and register.
	 */
	protected function register_enabled( array $slugs ): void {
		update_option( 'oversio_enabled_abilities', $slugs );
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}
}
