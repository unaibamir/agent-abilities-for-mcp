<?php
/**
 * Detection layer: the aafm_integration_active() predicate is filterable per slug so
 * the suite can force an integration on without the host plugin. Wave 5 splits the
 * single 'seo' slug into three per-plugin predicates (yoast / rankmath / aioseo), each
 * independently force-able.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class IntegrationDetectionTest extends TestCase {

	public function test_unknown_slug_is_never_active(): void {
		$this->assertFalse( aafm_integration_active( 'bogus' ) );
	}

	public function test_host_absent_means_inactive_by_default(): void {
		// None of the host plugins is installed on the test site, so all three SEO plugins are
		// inactive. Yoast keys on a constant, Rank Math on a class, AIOSEO on a function — none of
		// which the bare test site defines (a prior SEO suite may define the markers process-wide,
		// so pin each seam off, the same seam production detection passes through, to assert the
		// host-absent default deterministically).
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'yoast' ) );
		remove_filter( 'aafm_yoast_active', '__return_false', 99 );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'rankmath' ) );
		remove_filter( 'aafm_rankmath_active', '__return_false', 99 );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'aioseo' ) );
		remove_filter( 'aafm_aioseo_active', '__return_false', 99 );
		// ACF detection keys on function_exists('get_field'); the AcfTest fixture defines a get_field
		// stub process-wide (and a defined function cannot be undefined), so once that suite has run,
		// real ACF detection legitimately reports active here. Pin the aafm_acf_active seam off — the
		// same seam production detection passes through — to assert the host-absent default.
		add_filter( 'aafm_acf_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'acf' ) );
		remove_filter( 'aafm_acf_active', '__return_false', 99 );
		// WooCommerce detection keys on class_exists('WooCommerce'); the WooProductsTest fixture
		// defines a WooCommerce marker class process-wide (and a defined class cannot be undefined),
		// so once that suite has run real WC detection legitimately reports active here. Pin the
		// aafm_woocommerce_active seam off — the same seam production detection passes through — to
		// assert the host-absent default.
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	public function test_per_slug_filter_forces_active_for_tests(): void {
		// The filter is how the suite enables an integration WITHOUT installing the host plugin.
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		$this->assertTrue( aafm_integration_active( 'woocommerce' ) );
		// ACF must NOT be active off the back of the woocommerce filter (the per-slug filter is not
		// global). Pin the ACF seam off so the AcfTest get_field stub does not mask the per-slug point.
		add_filter( 'aafm_acf_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'acf' ), 'the filter is per-slug, not global.' );
		remove_filter( 'aafm_acf_active', '__return_false', 99 );
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
	}

	public function test_each_seo_plugin_forces_active_independently(): void {
		// Wave 5 splits the unified 'seo' slug into three per-plugin predicates. Each is
		// independently force-able through its own per-slug filter, and forcing one must NOT
		// activate the others (the per-slug filter is not global). Pin the two NOT-forced seams
		// off so a process-wide marker from a prior SEO suite cannot mask the independence point.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		$this->assertTrue( aafm_integration_active( 'yoast' ) );
		$this->assertFalse( aafm_integration_active( 'rankmath' ), 'forcing yoast must not activate rankmath.' );
		$this->assertFalse( aafm_integration_active( 'aioseo' ), 'forcing yoast must not activate aioseo.' );
		remove_filter( 'aafm_aioseo_active', '__return_false', 99 );
		remove_filter( 'aafm_rankmath_active', '__return_false', 99 );
		remove_filter( 'aafm_integration_active_yoast', '__return_true' );
	}

	public function test_stub_helpers_are_available(): void {
		// The shared trait is what every later slice's fixture uses to force an integration
		// active and define the host-API stubs without the host plugin installed.
		$this->assertTrue( trait_exists( 'AAFM\Tests\\IntegrationStubs' ) );
	}
}
