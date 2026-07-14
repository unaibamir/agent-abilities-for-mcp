<?php
/**
 * Test fixture: OUR bundled copy of the Order B shell class. The eager pass must skip it (both
 * because it is a plugin-shell class and because the standalone already declared it), so requiring
 * the bundle never fatals and the standalone's copy is retained.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP;

class Plugish {
	const SOURCE = 'bundle';
}
