<?php
/**
 * Confirms the bundled adapter autoloads.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Coexistence;

use Oversio\Tests\TestCase;

final class AdapterLoadTest extends TestCase {

	public function test_adapter_class_exists(): void {
		$this->assertTrue( class_exists( \WP\MCP\Core\McpAdapter::class ) );
	}

	public function test_min_adapter_version_constant_defined(): void {
		$this->assertSame( '0.5.0', OVERSIO_MIN_ADAPTER_VERSION );
	}
}
