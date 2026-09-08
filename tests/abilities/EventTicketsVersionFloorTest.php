<?php
/**
 * Event Tickets version floor: aafm_event_tickets_active() gates registration on Event Tickets
 * being both present AND at or above AAFM_EVENT_TICKETS_MIN_VERSION. Mirrors
 * TecVersionFloorTest.php / AioseoVersionFloorTest.php's exact shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;

final class EventTicketsVersionFloorTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_event_tickets();
	}

	/**
	 * The floor gates on the pinned version, not on the real class detection.
	 *
	 * @dataProvider provide_versions
	 *
	 * @param string|null $version       The version aafm_event_tickets_version() reports.
	 * @param bool        $expect_active Whether the site should be reported active at that version.
	 */
	public function test_event_tickets_floor_gates_on_version( ?string $version, bool $expect_active ): void {
		$override = static fn() => $version;
		add_filter( 'aafm_event_tickets_version', $override, 20 );

		try {
			$this->assertSame( $expect_active, aafm_event_tickets_active() );
		} finally {
			remove_filter( 'aafm_event_tickets_version', $override, 20 );
		}
	}

	/**
	 * Data provider: version string (or null) paired with the active state it must produce.
	 *
	 * @return array<string,array{0:string|null,1:bool}>
	 */
	public function provide_versions(): array {
		return array(
			'below the floor'     => array( '5.29.3.0', false ),
			'at the floor'        => array( '5.29.3.1', true ),
			'well above'          => array( '6.0.0', true ),
			'undetectable (null)' => array( null, false ),
		);
	}
}
