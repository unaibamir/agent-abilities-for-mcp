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
		delete_option( 'aafm_enabled_abilities' );
		// The registry catalog is memoized per request; tests mutate the
		// aafm_abilities_registry filter set between cases, so start each one with a
		// fresh build (the next registry read rebuilds).
		if ( function_exists( 'aafm_flush_registry_cache' ) ) {
			aafm_flush_registry_cache();
		}
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
}
