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
		// Policy reads are memoised per request; each test is a fresh request. With
		// AAFM_TEST_POLICY_PATH=batched every test runs as an MCP REST request, so policy reads
		// take the batched path; unset, they take the front-end path.
		aafm_policy_reset_request_state();
		$this->policy_request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- snapshot, restored as it was.
		if ( 'batched' === getenv( 'AAFM_TEST_POLICY_PATH' ) ) {
			$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		}
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
		// The write-outcome log observer is attached in production from the moment
		// includes/write-contract.php loads, at the earliest possible priority. Recorded here,
		// before the detach, so a test can assert the production priority directly instead of
		// trusting that this fixture's own remove_action() call names the right one.
		// Detached here so the existing suites that count activity-log rows keep counting exactly
		// what 1.7.5 wrote; a case that asserts a write_outcome row attaches the observer itself.
		$this->write_outcome_observer_priority = has_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome' );
		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );
	}

	/**
	 * The write-outcome log observer's priority as production had it attached, captured in
	 * set_up() before this fixture detaches it. False when the observer was not attached
	 * at all.
	 *
	 * @var int|false
	 */
	protected $write_outcome_observer_priority = false;

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
		if ( null === $this->policy_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->policy_request_uri;
		}
		aafm_policy_reset_request_state();
		parent::tear_down();
	}

	/**
	 * REQUEST_URI as set_up() found it, restored in tear_down().
	 *
	 * @var string|null
	 */
	private $policy_request_uri = null;

	/**
	 * The MCP endpoint's request path, built as aafm_oauth_request_targets_mcp_route() builds it.
	 *
	 * @return string
	 */
	protected static function mcp_rest_path(): string {
		$segments = array_filter(
			array( trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ), trim( rest_get_url_prefix(), '/' ) ),
			static function ( string $segment ): bool {
				return '' !== $segment;
			}
		);
		return '/' . implode( '/', $segments ) . aafm_mcp_rest_route();
	}

	/**
	 * Run this test on the front-end policy path (no batched read) under either suite setting.
	 * Only a named front-end pin calls it.
	 *
	 * @return void
	 */
	protected function use_front_end_policy_path(): void {
		unset( $_SERVER['REQUEST_URI'] );
		aafm_policy_reset_request_state();
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
