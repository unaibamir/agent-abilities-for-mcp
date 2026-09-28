<?php
/**
 * An enabled name with nothing registered (a bridge wrapper whose host plugin is inactive) must
 * resolve quietly in the server lookups; wp_get_ability() on it raises _doing_it_wrong, which
 * flooded every WP-CLI run. The suite fails a test on any unexpected _doing_it_wrong.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class UnregisteredServerNameLookupTest extends TestCase {

	private const NAME = 'aafm-bridge/never-registered-host-plugin-inactive';

	public function test_ownership_filter_skips_an_unregistered_name_quietly(): void {
		$this->assertFalse( wp_has_ability( self::NAME ) );
		$this->assertSame( array(), aafm_ownership_filter_server_tools( array( self::NAME ) ) );
	}

	public function test_schema_bounds_check_ignores_an_unregistered_name_quietly(): void {
		$this->assertNull( aafm_schema_bounds_violation( self::NAME ) );
	}
}
