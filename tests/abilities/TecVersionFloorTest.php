<?php
/**
 * TEC version floor: aafm_tec_active() gates registration on TEC being both present AND at or
 * above AAFM_TEC_MIN_VERSION. Mirrors AioseoVersionFloorTest.php's exact shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;

final class TecVersionFloorTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_tec();
	}

	/**
	 * The floor gates on the pinned version, not on the real class detection.
	 *
	 * @dataProvider provide_tec_versions
	 *
	 * @param string|null $version       The version aafm_tec_version() reports.
	 * @param bool        $expect_active Whether the site should be reported active at that version.
	 */
	public function test_tec_floor_gates_on_version( ?string $version, bool $expect_active ): void {
		$override = static fn() => $version;
		add_filter( 'aafm_tec_version', $override, 20 );

		try {
			$this->assertSame( $expect_active, aafm_tec_active() );
		} finally {
			remove_filter( 'aafm_tec_version', $override, 20 );
		}
	}

	/**
	 * Data provider: version string (or null) paired with the active state it must produce.
	 *
	 * @return array<string,array{0:string|null,1:bool}>
	 */
	public function provide_tec_versions(): array {
		return array(
			'below the floor'     => array( '6.17.3.0', false ),
			'at the floor'        => array( '6.17.3.1', true ),
			'well above'          => array( '7.0.0', true ),
			'undetectable (null)' => array( null, false ),
		);
	}
}
