<?php
/**
 * The tools/list handler of the copy another plugin loaded. It applies the capability filter.
 */

namespace WP\MCP\Handlers\Tools;

final class ToolsHandler {
	public function list_tools( array $tools ): array {
		return apply_filters( 'mcp_adapter_tools_list', $tools, null );
	}
}
