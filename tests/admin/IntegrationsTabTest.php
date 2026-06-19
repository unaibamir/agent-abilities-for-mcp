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

	public function test_inactive_card_still_renders_its_ability_rows_disabled(): void {
		// The whole point of the descriptor: an inactive host shows every ability it WOULD expose,
		// fully readable but disabled, so the operator can see what activating the plugin unlocks.
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

		// A known WooCommerce ability label and value render even though the host is inactive.
		$this->assertStringContainsString( 'List WooCommerce products', $html );
		$this->assertStringContainsString( 'value="aafm/wc-list-products"', $html );
		// The inactive rows carry the disabled attribute (so they never submit) and aria-disabled.
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		// The old empty-state copy is gone for good — there is always a list now.
		$this->assertStringNotContainsString( 'No abilities are available for this integration yet.', $html );
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
		// installed_inactive is the middle state: a candidate host plugin FILE is present under
		// WP_PLUGIN_DIR but the plugin is not active (its class/function is absent). Rather than
		// lean on whatever happens to live in the test WP install, create the ACF host file as a
		// throwaway fixture so this passes in a clean WordPress and cleans up after itself.
		//
		// The AcfTest fixture defines a get_field stub process-wide (a defined function cannot be
		// undefined), so once that suite has run, real ACF detection reports active and the status
		// helper would say 'active'. Pin the aafm_acf_active seam off — the same seam production
		// detection passes through — so the status falls through to the file-present probe, keeping
		// this status deterministic regardless of test order.
		add_filter( 'aafm_acf_active', '__return_false', 99 );

		// The first candidate host file for the ACF card, e.g. advanced-custom-fields/acf.php.
		$plugin_file = aafm_integration_cards()['acf']['plugins'][0];
		$fixture     = WP_PLUGIN_DIR . '/' . $plugin_file;
		$dir         = dirname( $fixture );
		$created_dir = ! is_dir( $dir );
		if ( $created_dir ) {
			wp_mkdir_p( $dir );
		}
		$pre_existing = file_exists( $fixture );
		if ( ! $pre_existing ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a throwaway local fixture under WP_PLUGIN_DIR, not a remote resource; WP_Filesystem is unavailable in the test harness.
			file_put_contents( $fixture, "<?php\n/* Plugin Name: ACF test fixture */\n" );
		}

		try {
			$this->assertSame( 'installed_inactive', aafm_integration_status( 'acf' ) );
		} finally {
			// Remove only what this test created — never delete a file the install already shipped.
			if ( ! $pre_existing && file_exists( $fixture ) ) {
				wp_delete_file( $fixture );
			}
			if ( $created_dir && is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the throwaway fixture directory this test created; WP_Filesystem is unavailable in the test harness.
			}
			remove_filter( 'aafm_acf_active', '__return_false', 99 );
		}
	}

	public function test_status_helper_reports_active_when_forced_on(): void {
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		$this->assertSame( 'active', aafm_integration_status( 'woocommerce' ) );
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
	}

	public function test_each_card_is_a_collapsed_details_accordion(): void {
		$this->acting_as( 'administrator' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );

		// Each integration card is a native <details> accordion that keeps the card classes, and
		// every accordion starts collapsed (no open attribute on the integration <details>).
		$this->assertStringContainsString( '<details class="aafm-card aafm-integration-card', $html );
		$this->assertStringNotContainsString( '<details class="aafm-card aafm-integration-card aafm-integration-woocommerce" open', $html );

		// The summary doubles as the card head: title, status pill, and the count all live in it.
		// Slice the WooCommerce card's <details> and check its own <summary>.
		$wc_open = strpos( $html, 'aafm-integration-woocommerce' );
		$this->assertNotFalse( $wc_open, 'The WooCommerce card should render.' );
		$summary_open = strpos( $html, '<summary class="aafm-card-head', $wc_open );
		$this->assertNotFalse( $summary_open, 'The card head must be a <summary>.' );
		$summary_close = strpos( $html, '</summary>', $summary_open );
		$summary       = substr( $html, $summary_open, $summary_close - $summary_open );
		$this->assertStringContainsString( 'WooCommerce', $summary );
		$this->assertStringContainsString( 'aafm-pill', $summary );
		$this->assertStringContainsString( 'aafm-integration-count', $summary );

		// The status note is re-enabled and renders in the accordion content.
		$this->assertStringContainsString( 'aafm-integration-note', $html );

		// The inner "Abilities X/Y" sub-collapsible is gone — the section accordion replaces it.
		$this->assertStringNotContainsString( 'aafm-abilities-details', $html );
	}

	public function test_each_card_renders_a_search_and_risk_filter(): void {
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

		// Each card carries a per-card filter: a search input plus the All / Read Only / Write group.
		// There are five integration cards, so each control appears at least five times.
		$this->assertSame( 5, substr_count( $html, 'aafm-integration-filter' ) );
		$this->assertStringContainsString( 'type="search"', $html );
		$this->assertStringContainsString( 'data-filter-risk="all"', $html );
		$this->assertStringContainsString( 'data-filter-risk="read"', $html );
		$this->assertStringContainsString( 'data-filter-risk="write"', $html );
		// Rows carry the data-risk hook the filter matches against.
		$this->assertStringContainsString( 'data-risk=', $html );
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
