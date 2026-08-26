<?php
/**
 * AIOSEO version floor (fix round 2, sweep review security finding):
 * aafm_aioseo_active() gates registration on AIOSEO being both present AND at or above
 * AAFM_AIOSEO_MIN_VERSION.
 *
 * Deliberately its own file, mirroring WooVersionFloorTest.php: proving the version-compare
 * branch requires function_exists('aioseo') to be true, and every AIOSEO test file in this suite
 * defines that marker function process-wide (a function can never be undefined again).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class AioseoVersionFloorTest extends TestCase {

	/**
	 * Define the aioseo() marker function once, mirroring IntegrationStubs::stub_aioseo(). A no-op
	 * once any earlier AIOSEO test file in this run has already defined it.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'aioseo' ) ) {
			eval( 'function aioseo() { return new \stdClass(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
		}
	}

	/**
	 * Pinned via the aafm_aioseo_version seam rather than the real AIOSEO_VERSION constant, since a
	 * PHP constant can never be undefined once defined - a bare constant would let one case's
	 * pinned version leak into every later test in this process.
	 *
	 * @dataProvider provide_aioseo_versions
	 *
	 * @param string|null $version       The version aafm_aioseo_version() reports.
	 * @param bool        $expect_active Whether the site should be reported active at that version.
	 */
	public function test_aioseo_floor_gates_on_version( ?string $version, bool $expect_active ): void {
		add_filter(
			'aafm_aioseo_version',
			static function () use ( $version ) {
				return $version;
			}
		);

		try {
			$this->assertSame( $expect_active, aafm_aioseo_active() );
		} finally {
			remove_all_filters( 'aafm_aioseo_version' );
		}
	}

	/**
	 * Data provider: version string (or null) paired with the active state it must produce.
	 *
	 * @return array<string, array{0: string|null, 1: bool}>
	 */
	public function provide_aioseo_versions(): array {
		return array(
			'below the floor (4.9.7)'     => array( '4.9.7', false ),
			'at the floor (4.9.8)'        => array( '4.9.8', true ),
			'well above the floor'        => array( '5.0.0.1', true ),
			// Fail-CLOSED by deliberate choice (unlike WooCommerce's fail-open): an undetectable
			// version must never let the write ability register against an AIOSEO this plugin
			// cannot confirm is patch-aware on partial saves.
			'undetectable version (null)' => array( null, false ),
		);
	}
}
