<?php
/**
 * Per-ability disclosure map and Abilities-tab badge rendering.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesDisclosureTest extends TestCase {

	public function test_every_ability_has_a_disclosure(): void {
		$disclosures = aafm_ability_disclosures();
		foreach ( array_keys( aafm_get_abilities_registry() ) as $name ) {
			$this->assertArrayHasKey( $name, $disclosures, "Missing disclosure for $name" );
			$this->assertNotEmpty( $disclosures[ $name ] );
		}
	}
}
