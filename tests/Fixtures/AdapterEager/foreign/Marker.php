<?php
/**
 * Test fixture: a foreign sibling's WP\MCP\ class, declared BEFORE our eager load runs.
 *
 * Stands in for an alphabetically-earlier plugin that bundles its own mcp-adapter copy and
 * declares this class first. The eager loader must NOT redeclare it.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP\Collide;

class Marker {
	const SOURCE = 'foreign';
}
