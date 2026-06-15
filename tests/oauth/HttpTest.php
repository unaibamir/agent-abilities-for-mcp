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
}
