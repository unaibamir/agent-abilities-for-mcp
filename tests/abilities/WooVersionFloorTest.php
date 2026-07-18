<?php
/**
 * WooCommerce version floor (WS-B / M3): aafm_woocommerce_active() gates registration on
 * WooCommerce being both present AND at or above AAFM_WOOCOMMERCE_MIN_VERSION.
 *
 * Deliberately its own file rather than folded into IntegrationDetectionTest: proving the
 * version-compare branch requires class_exists('WooCommerce') to be true, and every WC test file
 * in this suite defines that marker class process-wide (a class can never be undefined again).
 * The "Woo" filename prefix keeps this file sorted alongside the other Woo*Test files, which all
 * run AFTER the plain Integration*Test files - IntegrationManifestTest in particular asserts WC is
 * NOT yet active, and would break if this file's marker leaked earlier in the run.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class WooVersionFloorTest extends TestCase {

	/**
	 * Define the WooCommerce marker class once, mirroring IntegrationStubs::stub_woocommerce().
	 * A no-op once any earlier Woo*Test file in this run has already defined it.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- class-only marker stub for tests; never shipped.
		}
	}

	/**
	 * Pinned via the aafm_woocommerce_version seam rather than the real WC_VERSION constant, since
	 * a PHP constant can never be undefined once defined - a bare constant would let one case's
	 * pinned version leak into every later test in this process.
	 *
	 * @dataProvider provide_woocommerce_versions
	 *
	 * @param string|null $version       The version aafm_woocommerce_version() reports.
	 * @param bool        $expect_active Whether the site should be reported active at that version.
	 */
	public function test_woocommerce_floor_gates_on_version( ?string $version, bool $expect_active ): void {
		add_filter(
			'aafm_woocommerce_version',
			static function () use ( $version ) {
				return $version;
			}
		);

		try {
			$this->assertSame( $expect_active, aafm_woocommerce_active() );
		} finally {
			remove_all_filters( 'aafm_woocommerce_version' );
		}
	}

	/**
	 * Data provider: version string (or null) paired with the active state it must produce.
	 *
	 * @return array<string, array{0: string|null, 1: bool}>
	 */
	public function provide_woocommerce_versions(): array {
		return array(
			'below the floor (9.0.1)'     => array( '9.0.1', false ),
			'at the floor (9.1.0)'        => array( '9.1.0', true ),
			'well above the floor'        => array( '10.9.4', true ),
			// Fail-safe: an undetectable version must never disable a working store.
			'undetectable version (null)' => array( null, true ),
		);
	}

	/**
	 * The Integrations tab reason line: a below-floor site is genuinely active as a WP plugin, so
	 * it must report 'below_floor', never the generic 'not_installed'/'installed_inactive' a
	 * merely-absent or merely-deactivated host would get.
	 */
	public function test_woocommerce_status_reports_below_floor(): void {
		add_filter(
			'aafm_woocommerce_version',
			static function () {
				return '9.0.1';
			}
		);

		try {
			$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
			$this->assertSame( 'below_floor', aafm_integration_status( 'woocommerce' ) );
		} finally {
			remove_all_filters( 'aafm_woocommerce_version' );
		}
	}
}
