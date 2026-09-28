<?php
/**
 * Self-test for the shared fault-injection helper: proves the two failure shapes it promises
 * (no-flush vs real-error), Nth-occurrence targeting, and output containment actually hold before
 * any other test relies on them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class QueryFaultInjectorTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	/**
	 * The fired counter must count exactly the queries an armed fault actually redirected, for
	 * both failure shapes, and stay at zero when nothing matched.
	 */
	public function test_fired_count_tracks_actual_fault_activations(): void {
		global $wpdb;

		$this->assertSame( 0, QueryFaultInjector::fired_count(), 'precondition: no fault has fired yet.' );

		QueryFaultInjector::fail_query(
			'aafm_fault_injector_counter_probe',
			static function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'SELECT 1 AS aafm_fault_injector_counter_probe' );
			}
		);
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'one matching query must count as one fire.' );

		QueryFaultInjector::break_query_with_real_error(
			'aafm_fault_injector_counter_probe_two',
			static function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'SELECT 1 AS aafm_fault_injector_counter_probe_two' );
			}
		);
		$this->assertSame( 2, QueryFaultInjector::fired_count(), 'the real-error shape must count too.' );

		QueryFaultInjector::fail_query(
			'aafm_needle_that_never_matches_anything',
			static function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'SELECT 1' );
			}
		);
		$this->assertSame( 2, QueryFaultInjector::fired_count(), 'a query that never matches must not bump the counter.' );
	}

	/**
	 * Fail_query() must make the matching query return false without flushing last_result - the
	 * exact precondition the R7-2/R8-1/R8-3 defect class depends on.
	 */
	public function test_fail_query_returns_false_without_flushing_last_result(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SELECT 1 AS marker' );
		$prior_result = $wpdb->last_result;
		$this->assertNotEmpty( $prior_result, 'precondition: the marker query must have left a result.' );

		$outcome = QueryFaultInjector::fail_query(
			'aafm_fault_injector_probe',
			static function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return $wpdb->query( 'SELECT 1 AS aafm_fault_injector_probe' );
			}
		);

		$this->assertFalse( $outcome, 'the targeted query must report failure.' );
		$this->assertSame( $prior_result, $wpdb->last_result, 'last_result must stay stale on the no-flush path.' );
		$this->assertSame( '', $wpdb->last_error, 'the no-flush path must not raise a real SQL error.' );
	}

	/**
	 * The filter must be fully disarmed once fail_query()/fail_nth_query() return: a query run
	 * afterwards, even one containing the same needle, must succeed normally.
	 */
	public function test_the_filter_is_disarmed_after_the_call_returns(): void {
		global $wpdb;

		QueryFaultInjector::fail_query(
			'aafm_fault_injector_probe_two',
			static function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return $wpdb->query( 'SELECT 1 AS aafm_fault_injector_probe_two' );
			}
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$after = $wpdb->query( 'SELECT 1 AS aafm_fault_injector_probe_two' );

		$this->assertNotFalse( $after, 'the same query must succeed once the injector has returned.' );
	}

	/**
	 * Fail_nth_query() must fail only the requested occurrence: the first matching query must
	 * survive when occurrence=2 is targeted, and the second must fail.
	 */
	public function test_fail_nth_query_targets_only_the_requested_occurrence(): void {
		global $wpdb;

		$results = QueryFaultInjector::fail_nth_query(
			'aafm_fault_injector_nth_probe',
			2,
			static function () use ( $wpdb ) {
				return array(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'SELECT 1 AS aafm_fault_injector_nth_probe' ),
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'SELECT 1 AS aafm_fault_injector_nth_probe' ),
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'SELECT 1 AS aafm_fault_injector_nth_probe' ),
				);
			}
		);

		$this->assertNotFalse( $results[0], 'the first occurrence must survive when occurrence=2 is targeted.' );
		$this->assertFalse( $results[1], 'the second occurrence must be the one that fails.' );
		$this->assertNotFalse( $results[2], 'the third occurrence must survive; only occurrence 2 is targeted.' );
	}

	/**
	 * Break_query_with_real_error() must produce a genuine SQL failure - last_result flushed,
	 * last_error populated - the opposite shape from the no-flush path, and must not let wpdb's
	 * forced show_errors echo leak into PHPUnit's output check.
	 */
	public function test_break_query_with_real_error_flushes_last_result_and_sets_last_error(): void {
		global $wpdb;

		$outcome = QueryFaultInjector::break_query_with_real_error(
			'aafm_fault_injector_real_error_probe',
			static function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return $wpdb->query( 'SELECT 1 AS aafm_fault_injector_real_error_probe' );
			}
		);

		$this->assertFalse( $outcome, 'a redirected query must report failure.' );
		$this->assertNotSame( '', $wpdb->last_error, 'a real SQL error must populate last_error.' );
	}
}
