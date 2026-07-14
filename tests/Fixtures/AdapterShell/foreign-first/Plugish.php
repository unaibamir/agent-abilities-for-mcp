<?php
/**
 * Test fixture: the standalone plugin's shell class, declared BEFORE our eager load (the
 * theirs-first / Order B scenario). Distinct class name from the Order A fixture so both scenarios
 * can run in the same shared PHP process without a redeclaration.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP;

class Plugish {
	const SOURCE = 'standalone';
}
