<?php
/**
 * MCP-safe tool-name mapping must match the adapter's McpNameSanitizer.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Unit;

use Oversio\Tests\TestCase;

final class ToolNameTest extends TestCase {

	public function test_slash_becomes_hyphen_matching_the_adapter(): void {
		// CONFIRMED in Phase 0.5: the adapter's McpNameSanitizer converts '/' -> '-' and KEEPS hyphens.
		$this->assertSame( 'oversio-get-posts', oversio_mcp_tool_name( 'oversio/get-posts' ) );
		$this->assertSame( 'oversio-set-featured-image', oversio_mcp_tool_name( 'oversio/set-featured-image' ) );
	}

	public function test_name_is_slash_free_and_in_the_adapter_charset(): void {
		foreach ( array( 'oversio/get-posts', 'oversio/upload-media', 'oversio/moderate-comment' ) as $name ) {
			$safe = oversio_mcp_tool_name( $name );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]+$/', $safe ); // adapter charset.
			$this->assertStringNotContainsString( '/', $safe );                    // slashes are the hard blocker.
		}
	}
}
