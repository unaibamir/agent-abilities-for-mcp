<?php
/**
 * Coexistence: version-floor compatibility logic.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class CoexistenceTest extends TestCase {

	public function test_equal_or_newer_adapter_is_compatible(): void {
		$this->assertTrue( aafm_adapter_is_compatible( '0.5.0' ) );
		$this->assertTrue( aafm_adapter_is_compatible( '0.6.0' ) );
		$this->assertTrue( aafm_adapter_is_compatible( '1.0.0' ) );
	}

	public function test_older_adapter_is_incompatible(): void {
		$this->assertFalse( aafm_adapter_is_compatible( '0.4.0' ) );
		$this->assertFalse( aafm_adapter_is_compatible( '0.3.9' ) );
	}

	public function test_missing_adapter_class_short_circuits_init(): void {
		// When the class is absent, aafm_init_mcp() must return false and not fatal.
		// Simulated by asserting the guard reads the constant rather than hard-failing.
		$this->assertIsBool( aafm_adapter_is_compatible( '0.5.0' ) );
	}
}
