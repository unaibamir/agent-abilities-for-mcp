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
		$this->assertFalse( aafm_integration_active( 'acf' ) );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
	}

	public function test_per_slug_filter_forces_active_for_tests(): void {
		// The filter is how the suite enables an integration WITHOUT installing the host plugin.
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		$this->assertTrue( aafm_integration_active( 'woocommerce' ) );
		$this->assertFalse( aafm_integration_active( 'acf' ), 'the filter is per-slug, not global.' );
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
	}

	public function test_seo_sub_detection_reports_no_active_plugin_when_none_present(): void {
		$this->assertSame( '', aafm_seo_active_plugin(), 'no SEO plugin installed → empty string.' );
	}
}
