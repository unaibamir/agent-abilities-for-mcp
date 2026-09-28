<?php
/**
 * Tests for the OAuth HTTP helpers: rate limiter and transport-security policy.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Exercises the fixed-window rate limiter and the https_required policy helper.
 */
class HttpTest extends TestCase {

	/**
	 * The per-IP cap trips once the limit is exceeded; calls within it are allowed.
	 *
	 * A small per-IP limit and a high global ceiling so the per-IP cap is the one
	 * that trips first. The Nth call (limit N) is still allowed; call N+1 is denied.
	 */
	public function test_per_ip_limit_trips_after_cap(): void {
		$per_ip = 3;
		$global = 1000;

		// Calls 1..3 are within the per-IP cap.
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_perip', $per_ip, $global ) );
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_perip', $per_ip, $global ) );
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_perip', $per_ip, $global ) );

		// Call 4 exceeds the per-IP cap and is denied.
		$this->assertFalse( aafm_oauth_rate_ok( 'http_test_perip', $per_ip, $global ) );
	}

	/**
	 * The limiter still trips when each call is a separate request on the default object cache.
	 *
	 * This is the B3 regression. The previous limiter seeded its counter with wp_cache_add() and
	 * only consulted the transient on a wp_cache_incr() miss, which never fires within a single
	 * process. Every existing test called the limiter repeatedly inside ONE test method, so the
	 * in-memory object cache persisted and the counter climbed, hiding a limiter that was dead on
	 * the default per-request cache. wp_cache_flush() between calls models a fresh process each
	 * time (what a real request is), where only the transient survives. Expected sequence for a
	 * per-IP cap of 2 is allow, allow, then deny for the rest.
	 */
	public function test_limit_holds_across_separate_requests(): void {
		$per_ip  = 2;
		$global  = 100;
		$results = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$results[] = aafm_oauth_rate_ok( 'http_test_flush', $per_ip, $global );
			wp_cache_flush();
		}

		$this->assertSame(
			array( true, true, false, false, false, false ),
			$results,
			'The OAuth rate limiter must hold across separate requests on the default object cache.'
		);
	}

	/**
	 * Distinct buckets are counted independently.
	 *
	 * Exhausting bucket A must not consume bucket B's allowance.
	 */
	public function test_buckets_count_independently(): void {
		$per_ip = 2;
		$global = 1000;

		// Drain bucket A to its cap, then one over.
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_a', $per_ip, $global ) );
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_a', $per_ip, $global ) );
		$this->assertFalse( aafm_oauth_rate_ok( 'http_test_a', $per_ip, $global ) );

		// Bucket B is still fresh.
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_b', $per_ip, $global ) );
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_b', $per_ip, $global ) );
		$this->assertFalse( aafm_oauth_rate_ok( 'http_test_b', $per_ip, $global ) );
	}

	/**
	 * The global ceiling trips independently of the per-IP cap.
	 *
	 * A high per-IP limit and a small global limit so the global counter is the one
	 * that denies. This also covers the second counter branch in aafm_oauth_rate_ok().
	 */
	public function test_global_limit_trips_after_cap(): void {
		$per_ip = 1000;
		$global = 2;

		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_global', $per_ip, $global ) );
		$this->assertTrue( aafm_oauth_rate_ok( 'http_test_global', $per_ip, $global ) );
		$this->assertFalse( aafm_oauth_rate_ok( 'http_test_global', $per_ip, $global ) );
	}

	/**
	 * The https_required() helper returns a bool, relaxed under the test environment.
	 *
	 * The WP test harness reports wp_get_environment_type() as 'local' (the default
	 * for the suite), so the loopback relaxation applies and the function returns
	 * false. We assert the observed value rather than forcing a contrived pass.
	 */
	public function test_https_required_returns_bool(): void {
		$result = aafm_oauth_https_required();

		$this->assertIsBool( $result );

		// Document and assert the environment the harness reports.
		$env = wp_get_environment_type();
		if ( in_array( $env, array( 'local', 'development' ), true ) ) {
			$this->assertFalse( $result, 'HTTPS is relaxed on local/development environments.' );
		} else {
			// Production-like harness: HTTPS is required unless the override is set.
			$expected = defined( 'AAFM_OAUTH_ALLOW_HTTP' ) && AAFM_OAUTH_ALLOW_HTTP ? false : true;
			$this->assertSame( $expected, $result );
		}
	}

	/**
	 * RC-T3 (T3, ledger s14w1-code-4): get_transient()'s read of an OAuth counter fails while its
	 * row holds 5. The counter reads 6, not 1, and the row is not reset.
	 */
	public function test_a_failed_counter_read_counts_the_stored_row(): void {
		$transient = 'aafm_oauth_rl_rc_t3';
		\AAFM\Tests\Support\QueryFaultInjector::reset_fired_count();
		set_transient( $transient, 5, 60 );
		wp_cache_delete( '_transient_' . $transient, 'options' );
		wp_cache_delete( '_transient_timeout_' . $transient, 'options' );

		$count = \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT', "'_transient_" . $transient . "'" ),
			static fn() => aafm_oauth_bump_counter( 'rl_rc_t3', 60 ),
			1
		);

		$this->assertGreaterThan( 0, \AAFM\Tests\Support\QueryFaultInjector::fired_count() );
		$this->assertSame( 6, $count );
	}

	/**
	 * RC-T4 (T4): the counter's read and re-read both fail. The counter reads over every limit, so
	 * the limiter refuses, and nothing is written over the stored count.
	 */
	public function test_a_counter_that_cannot_be_read_refuses(): void {
		$transient = 'aafm_oauth_rl_rc_t4';
		\AAFM\Tests\Support\QueryFaultInjector::reset_fired_count();
		set_transient( $transient, 2, 60 );
		wp_cache_delete( '_transient_' . $transient, 'options' );
		wp_cache_delete( '_transient_timeout_' . $transient, 'options' );

		$count = \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT', "'_transient_" . $transient . "'" ),
			static fn() => aafm_oauth_bump_counter( 'rl_rc_t4', 60 )
		);

		$this->assertGreaterThan( 0, \AAFM\Tests\Support\QueryFaultInjector::fired_count() );
		$this->assertSame( PHP_INT_MAX, $count );
		$this->assertSame( '2', aafm_option_row( '_transient_' . $transient )['value'] );
	}
}
