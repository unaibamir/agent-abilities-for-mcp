<?php
/**
 * Proves the WordPress PHPUnit harness boots and the plugin loads.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Unit;

use Oversio\Tests\TestCase;

final class HarnessTest extends TestCase {

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( class_exists( 'WP_Ability' ), 'Abilities API (WP 6.9 core) must be present.' );
		$this->assertTrue( defined( 'OVERSIO_VERSION' ) );
		// The exact release version is pinned in MetadataTest; the smoke just needs a
		// non-empty semver string so it survives version bumps.
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', OVERSIO_VERSION );
	}
}
