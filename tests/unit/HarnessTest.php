<?php
/**
 * Proves the WordPress PHPUnit harness boots and the plugin loads.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class HarnessTest extends TestCase {

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( class_exists( 'WP_Ability' ), 'Abilities API (WP 6.9 core) must be present.' );
		$this->assertTrue( defined( 'AAFM_VERSION' ) );
		$this->assertSame( '0.1.0', AAFM_VERSION );
	}
}
