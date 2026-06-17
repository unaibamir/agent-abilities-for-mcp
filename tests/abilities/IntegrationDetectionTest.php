<?php
/**
 * Wave 4 detection layer: the aafm_integration_active() predicate is filterable
 * per slug so the suite can force an integration on without the host plugin, and
 * SEO sub-detection reports which of the three plugins is active (none here).
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
		// None of the host plugins is installed on the test site, so all three are inactive.
		$this->assertFalse( aafm_integration_active( 'seo' ) );
		// ACF detection keys on function_exists('get_field'); the AcfTest fixture defines a get_field
		// stub process-wide (and a defined function cannot be undefined), so once that suite has run,
		// real ACF detection legitimately reports active here. Pin the aafm_acf_active seam off — the
		// same seam production detection passes through — to assert the host-absent default.
		add_filter( 'aafm_acf_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'acf' ) );
		remove_filter( 'aafm_acf_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
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

	public function test_seo_sub_detection_reports_no_active_plugin_when_none_present(): void {
		$this->assertSame( '', aafm_seo_active_plugin(), 'no SEO plugin installed → empty string.' );
	}

	public function test_stub_helpers_are_available(): void {
		// The shared trait is what every later slice's fixture uses to force an integration
		// active and define the host-API stubs without the host plugin installed.
		$this->assertTrue( trait_exists( 'AAFM\\Tests\\IntegrationStubs' ) );
	}
}
