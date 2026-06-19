<?php
/**
 * Wave 4: the Integrations admin tab shell — registered in the tab nav, renders one
 * card per integration with a detected status and the security disclaimer header,
 * reuses the shared design system, and ships zero Dashicons.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class IntegrationsTabTest extends TestCase {

	public function test_tab_is_registered(): void {
		$this->assertArrayHasKey( 'integrations', aafm_admin_tabs() );
	}

	public function test_tab_renders_the_cards_and_the_disclaimer(): void {
		$this->acting_as( 'administrator' );
		// The SEO slices' stubs define WPSEO_VERSION / a RankMath class / an aioseo() function
		// process-wide (none can be undefined), so once those suites run real SEO detection reports
		// active. Pin each per-plugin seam off — the same seam production detection passes through —
		// so the "Not installed" state is deterministic here.
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		// WooProductsTest defines a WooCommerce marker class process-wide (a class cannot be
		// undefined), so once that suite has run real WC detection reports active. Pin the
		// aafm_woocommerce_active seam off — the same seam production detection passes through — so
		// the "Not installed" state stays deterministic regardless of test order.
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		remove_filter( 'aafm_aioseo_active', '__return_false', 99 );
		remove_filter( 'aafm_rankmath_active', '__return_false', 99 );
		remove_filter( 'aafm_yoast_active', '__return_false', 99 );

		// One card per integration, reusing the shared component class.
		$this->assertStringContainsString( 'aafm-card', $html );
		foreach ( array( 'Yoast SEO', 'Rank Math', 'All in One SEO', 'ACF', 'WooCommerce' ) as $name ) {
			$this->assertStringContainsString( $name, $html );
		}
		// The security disclaimer appears in the header.
		$this->assertStringContainsString( 'aafm-integrations-disclaimer', $html );
		// Detected status: host plugins absent → "Not installed".
		$this->assertStringContainsString( 'Not installed', $html );
		// Zero Dashicons (inline SVG only — the project icon rule).
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	public function test_inactive_card_shows_the_manifest_count(): void {
		$this->acting_as( 'administrator' );
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		remove_filter( 'aafm_aioseo_active', '__return_false', 99 );
		remove_filter( 'aafm_rankmath_active', '__return_false', 99 );
		remove_filter( 'aafm_yoast_active', '__return_false', 99 );

		// The inactive WooCommerce card shows its manifest count so the operator sees what
		// activating the plugin would unlock (32 read + 23 write of 67).
		$wc = aafm_integration_manifest()['woocommerce'];
		$this->assertStringContainsString( 'aafm-integration-count', $html );
		$this->assertStringContainsString(
			sprintf( '0 / %1$d · %2$d read, %3$d write', $wc['total'], $wc['read'], $wc['write'] ),
			$html
		);
	}

	public function test_status_helper_reports_not_installed_when_host_files_absent(): void {
		// Neither Yoast nor WooCommerce ships its host file in this WP install, and neither is
		// active, so each reports not_installed. The SEO slices' stubs define the detection markers
		// (WPSEO_VERSION etc.) process-wide, so pin the Yoast seam off to keep this status
		// deterministic regardless of test order.
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		// WooProductsTest defines a WooCommerce marker class process-wide, so pin the WC seam off too
		// (the same seam production detection passes through) to keep the not_installed status
		// deterministic regardless of test order.
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertSame( 'not_installed', aafm_integration_status( 'woocommerce' ) );
		$this->assertSame( 'not_installed', aafm_integration_status( 'yoast' ) );
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		remove_filter( 'aafm_yoast_active', '__return_false', 99 );
	}

	public function test_status_helper_reports_installed_inactive_when_file_present_but_class_absent(): void {
		// The DDEV/test WP install carries the advanced-custom-fields plugin FILE (a
		// reference dependency) but does not load it, so the ACF class is absent and the
		// integration is not active. The file-present probe yields installed_inactive,
		// which is exactly the middle state the tab must surface.
		//
		// The AcfTest fixture defines a get_field stub process-wide (a defined function cannot be
		// undefined), so once that suite has run, real ACF detection reports active and the status
		// helper would say 'active'. Pin the aafm_acf_active seam off — the same seam production
		// detection passes through — so the status falls through to the file-present probe, keeping
		// this status deterministic regardless of test order.
		add_filter( 'aafm_acf_active', '__return_false', 99 );
		$this->assertSame( 'installed_inactive', aafm_integration_status( 'acf' ) );
		remove_filter( 'aafm_acf_active', '__return_false', 99 );
	}

	public function test_status_helper_reports_active_when_forced_on(): void {
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		$this->assertSame( 'active', aafm_integration_status( 'woocommerce' ) );
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
	}

	public function test_tab_has_exactly_one_form_and_no_nested_form(): void {
		// The Wave-0 lesson: never nest a <form>. The tab renders one outer form for the
		// per-ability toggles; any secondary control is a <div> + type="button".
		$this->acting_as( 'administrator' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );

		$this->assertSame( 1, substr_count( $html, '<form' ), 'The Integrations tab must render exactly one <form>.' );
	}
}
