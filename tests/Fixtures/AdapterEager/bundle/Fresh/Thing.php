<?php
/**
 * Test fixture: a non-colliding bundled WP\MCP\ class.
 *
 * Proves the eager loader still requires files whose class is NOT already declared, even while
 * it skips the colliding one in the same pass.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP\Fresh;

class Thing {
	const SOURCE = 'bundle';
}
