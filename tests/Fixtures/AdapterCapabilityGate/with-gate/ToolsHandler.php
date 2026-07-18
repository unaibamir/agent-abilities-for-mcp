<?php
/**
 * Test fixture: an adapter tools/list handler that STILL applies the per-connection capability gate.
 *
 * Stands in for a compliant in-range adapter copy - it runs apply_filters( 'mcp_adapter_tools_list',
 * ... ), the hook our request-time gate rides on. Under a unique fixture namespace so it never
 * collides with the real WP\MCP\ classes and is never eager-loaded (it lives under tests/, not vendor/).
 *
 * @package AgentAbilitiesForMCP
 */

namespace AAFM\Tests\Fixtures\CapabilityGate\WithGate;

class ToolsHandler {

	/**
	 * Apply the per-connection capability filter to the tool list.
	 *
	 * @param array<int,mixed> $tools Tool DTOs.
	 * @return array<int,mixed>
	 */
	public function list_tools( array $tools ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fixture mimics the vendor adapter's own hook name; it must match verbatim to stand in for a real adapter copy.
		return (array) apply_filters( 'mcp_adapter_tools_list', $tools, null );
	}
}
