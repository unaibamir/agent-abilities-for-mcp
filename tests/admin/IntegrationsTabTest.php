<?php
/**
 * Wave 4: the Integrations admin tab shell - registered in the tab nav, renders one
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
		// active. Pin each per-plugin seam off - the same seam production detection passes through -
		// so the "Not installed" state is deterministic here.
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		// WooProductsTest defines a WooCommerce marker class process-wide (a class cannot be
		// undefined), so once that suite has run real WC detection reports active. Pin the
		// aafm_woocommerce_active seam off - the same seam production detection passes through - so
		// each pinned card's status stays deterministic regardless of test order.
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );

		// Derive the status pill each pinned-off card should render from the real on-disk probe,
		// under the same forced-inactive seams the render passes through - never hardcode
		// "Not installed". This WP test install shares the developer's wp-content, so a host plugin
		// file (e.g. wordpress-seo/wp-seo.php) can be physically present but inactive; that is the
		// CORRECT "installed_inactive" → "Inactive" state. Asserting "Not installed" unconditionally
		// would assert a false premise about this box (it would only hold on a clean install with no
		// host plugin files), not a bug in the tab. Compute the expectation while the seams are
		// still active so it matches what the render observes.
		$expected_pills = array();
		foreach ( array( 'yoast', 'rankmath', 'aioseo', 'woocommerce' ) as $slug ) {
			$status = aafm_integration_status( $slug );
			$this->assertNotSame( 'active', $status, "Forcing {$slug} inactive must defeat any leaked detection stub." );
			$expected_pills[ 'not_installed' === $status ? 'Not installed' : 'Inactive' ] = true;
		}

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
		// Detected status: each pinned-off card renders the status pill the box actually warrants
		// ("Not installed" on a clean install, "Inactive" where the host file is present-but-off).
		foreach ( array_keys( $expected_pills ) as $pill_label ) {
			$this->assertStringContainsString( $pill_label, $html );
		}
		// Zero Dashicons (inline SVG only - the project icon rule).
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
		// activating the plugin would unlock (27 read + 23 write of 52).
		$wc = aafm_integration_manifest()['woocommerce'];
		$this->assertStringContainsString( 'aafm-integration-count', $html );
		$this->assertStringContainsString(
			sprintf( '0 / %1$d · %2$d read, %3$d write', $wc['total'], $wc['read'], $wc['write'] ),
			$html
		);
	}

	public function test_active_card_shows_the_real_enabled_count(): void {
		// Regression guard for the count header bug: the enabled tally was hardcoded to the literal
		// 0, so an ACTIVE integration with abilities switched on still read "0 / N". Force WooCommerce
		// active (the same seam production detection passes through), enable a known subset of its
		// abilities, and assert the WooCommerce card header reports that real count - not "0 /".
		$this->acting_as( 'administrator' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		// Flush so the live registry rebuilds WITH the WooCommerce rows: aafm_get_enabled_abilities()
		// only honors enabled names that still exist in the live registry, so the WC abilities must be
		// registered for the enabled subset to count.
		aafm_registry_cache_should_flush( true );

		// Enable a known subset of WooCommerce's abilities, derived from the manifest so the test is
		// robust to the catalog growing. Three is enough to prove a NON-ZERO count distinct from total.
		$wc             = aafm_integration_manifest()['woocommerce'];
		$wc_names       = array_column( aafm_integration_ability_manifest()['woocommerce'], 'name' );
		$enabled_subset = array_slice( $wc_names, 0, 3 );
		$this->assertCount( 3, $enabled_subset, 'WooCommerce should expose at least three abilities to enable.' );
		update_option( 'aafm_enabled_abilities', $enabled_subset );

		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );

		// Slice the WooCommerce card's own <summary> so the assertion can't match another card's count.
		$wc_open = strpos( $html, 'aafm-integration-woocommerce' );
		$this->assertNotFalse( $wc_open, 'The WooCommerce card should render.' );
		$summary_open  = strpos( $html, '<summary class="aafm-card-head', $wc_open );
		$summary_close = strpos( $html, '</summary>', (int) $summary_open );
		$summary       = substr( $html, (int) $summary_open, (int) $summary_close - (int) $summary_open );

		// The header reports the REAL enabled count (3), the total, and the read/write tallies - the
		// exact format the fix produces. n is 3 (> 0), proving the count is computed, not the literal 0.
		$this->assertStringContainsString(
			sprintf( '3 / %1$d · %2$d read, %3$d write', $wc['total'], $wc['read'], $wc['write'] ),
			$summary
		);
		// And it must NOT have regressed to the hardcoded "0 / <total>".
		$this->assertStringNotContainsString(
			sprintf( '0 / %1$d · %2$d read, %3$d write', $wc['total'], $wc['read'], $wc['write'] ),
			$summary
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
		// The old empty-state copy is gone for good - there is always a list now.
		$this->assertStringNotContainsString( 'No abilities are available for this integration yet.', $html );
	}

	public function test_status_helper_reports_not_installed_when_host_files_absent(): void {
		// not_installed is the file-driven branch: with the host inactive AND no candidate host file
		// under WP_PLUGIN_DIR, the helper reports not_installed. Force each active seam off - the same
		// seam production detection passes through - so a leaked in-process stub can never flip
		// detection back to 'active' and mask this branch: the SEO slices define WPSEO_VERSION etc.
		// process-wide and WooProductsTest defines a WC marker class, and neither can be undefined.
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );

		// The expected status is derived from the real on-disk probe, never hardcoded: this WP test
		// install shares the developer's wp-content, so it can physically carry an INACTIVE copy of a
		// host plugin (e.g. wordpress-seo/wp-seo.php is present here). When the file is present,
		// installed_inactive is the CORRECT answer - hardcoding not_installed there would assert a
		// false premise about the environment, not a bug in the helper.
		foreach ( array( 'woocommerce', 'yoast' ) as $slug ) {
			$present = false;
			foreach ( aafm_integration_cards()[ $slug ]['plugins'] as $file ) {
				if ( aafm_integration_plugin_file_exists( $file ) ) {
					$present = true;
					break;
				}
			}
			$status = aafm_integration_status( $slug );
			// With the active seam forced off the status is file-driven, never 'active' - this is the
			// leaked-stub regression this test guards against.
			$this->assertNotSame( 'active', $status, "Forcing {$slug} inactive must defeat any leaked detection stub." );
			$this->assertSame(
				$present ? 'installed_inactive' : 'not_installed',
				$status,
				"The {$slug} status must follow on-disk host-file presence when the host is inactive."
			);
		}

		// Anchor the not_installed branch deterministically, independent of which host plugins happen
		// to be physically installed: an unknown slug has no candidate host files, the degenerate case
		// of "all candidate files absent", so it must always report not_installed.
		$this->assertSame( 'not_installed', aafm_integration_status( 'aafm-no-such-integration' ) );

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
		// helper would say 'active'. Pin the aafm_acf_active seam off - the same seam production
		// detection passes through - so the status falls through to the file-present probe, keeping
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
			// Remove only what this test created - never delete a file the install already shipped.
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

		// The inner "Abilities X/Y" sub-collapsible is gone - the section accordion replaces it.
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

	/**
	 * WS-B: a WooCommerce site running below the abilities' required version floor is genuinely
	 * active as a WP plugin - it must NOT get the generic "Not installed"/"Inactive" copy, but a
	 * specific "requires WooCommerce X.Y+" reason naming the version actually detected.
	 */
	public function test_woocommerce_card_shows_below_floor_reason(): void {
		$this->acting_as( 'administrator' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- class-only marker stub for the floor test, never shipped.
		}
		add_filter(
			'aafm_woocommerce_version',
			static function () {
				return '9.0.1';
			}
		);
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );

		try {
			$this->assertSame( 'below_floor', aafm_integration_status( 'woocommerce' ) );

			ob_start();
			aafm_render_integrations_tab();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'Update required', $html );
			$this->assertStringContainsString( 'WooCommerce', $html );
			$this->assertStringContainsString( AAFM_WOOCOMMERCE_MIN_VERSION, $html );
			$this->assertStringContainsString( '9.0.1', $html );
		} finally {
			remove_filter( 'aafm_aioseo_active', '__return_false', 99 );
			remove_filter( 'aafm_rankmath_active', '__return_false', 99 );
			remove_filter( 'aafm_yoast_active', '__return_false', 99 );
			remove_all_filters( 'aafm_woocommerce_version' );
		}
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
