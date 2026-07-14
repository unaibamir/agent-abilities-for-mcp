<?php
/**
 * Test fixture: a bundled RUNTIME class (lives in a sub-namespace, WP\MCP\Core\).
 *
 * Proves the plugin-shell skip is surgical: the eager loader still requires real runtime classes
 * in the same pass while skipping the top-level bootstrap-shell files.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP\Core;

class Widget {
	const SOURCE = 'bundle';
}
