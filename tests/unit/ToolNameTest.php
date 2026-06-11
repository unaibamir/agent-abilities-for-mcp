<?php
/**
 * MCP-safe tool-name mapping must match the adapter's McpNameSanitizer.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class ToolNameTest extends TestCase {

	public function test_slash_becomes_hyphen_matching_the_adapter(): void {
		// CONFIRMED in Phase 0.5: the adapter's McpNameSanitizer converts '/' -> '-' and KEEPS hyphens.
		$this->assertSame( 'aafm-get-posts', aafm_mcp_tool_name( 'aafm/get-posts' ) );
		$this->assertSame( 'aafm-set-featured-image', aafm_mcp_tool_name( 'aafm/set-featured-image' ) );
	}

	public function test_name_is_slash_free_and_in_the_adapter_charset(): void {
		foreach ( array( 'aafm/get-posts', 'aafm/upload-media', 'aafm/moderate-comment' ) as $name ) {
			$safe = aafm_mcp_tool_name( $name );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]+$/', $safe ); // adapter charset.
			$this->assertStringNotContainsString( '/', $safe );                    // slashes are the hard blocker.
		}
	}
}
