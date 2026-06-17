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

	public function test_tab_renders_the_three_cards_and_the_disclaimer(): void {
		$this->acting_as( 'administrator' );
		// The SEO slice's stubs define WPSEO_VERSION / a RankMath marker class process-wide (a
		// constant/class cannot be undefined), so once SeoTest runs in the same process real SEO
		// detection reports active. Pin SEO inactive through its own filter — the seam production
		// detection passes through — so the "Not installed" state is deterministic here.
		add_filter( 'aafm_integration_active_seo', '__return_false', 99 );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		remove_filter( 'aafm_integration_active_seo', '__return_false', 99 );

		// One card per integration, reusing the shared component class.
		$this->assertStringContainsString( 'aafm-card', $html );
		foreach ( array( 'SEO', 'ACF', 'WooCommerce' ) as $name ) {
			$this->assertStringContainsString( $name, $html );
		}
		// The security disclaimer appears in the header.
		$this->assertStringContainsString( 'aafm-integrations-disclaimer', $html );
		// Detected status: host plugins absent → "Not installed".
		$this->assertStringContainsString( 'Not installed', $html );
		// Zero Dashicons (inline SVG only — the project icon rule).
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	public function test_status_helper_reports_not_installed_when_host_files_absent(): void {
		// None of the SEO plugins nor WooCommerce ship their host file in this WP install,
		// and neither is active, so each reports not_installed. The SEO slice's stubs define the
		// detection markers (WPSEO_VERSION / a RankMath class) process-wide, so pin SEO inactive
		// through its filter to keep this status deterministic regardless of test order.
		add_filter( 'aafm_integration_active_seo', '__return_false', 99 );
		$this->assertSame( 'not_installed', aafm_integration_status( 'woocommerce' ) );
		$this->assertSame( 'not_installed', aafm_integration_status( 'seo' ) );
		remove_filter( 'aafm_integration_active_seo', '__return_false', 99 );
	}

	public function test_status_helper_reports_installed_inactive_when_file_present_but_class_absent(): void {
		// The DDEV/test WP install carries the advanced-custom-fields plugin FILE (a
		// reference dependency) but does not load it, so the ACF class is absent and the
		// integration is not active. The file-present probe yields installed_inactive,
		// which is exactly the middle state the tab must surface.
		$this->assertSame( 'installed_inactive', aafm_integration_status( 'acf' ) );
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
