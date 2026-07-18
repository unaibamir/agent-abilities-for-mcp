<?php
/**
 * Test fixture: the STANDALONE plugin's copy of the shell class, declared via its own unguarded
 * `require_once` AFTER our eager load has run (the ours-first / Order A scenario).
 *
 * Requiring this must NOT fatal: our eager load must have skipped the bundle copy so WP\MCP\
 * Autoloaderish is still undeclared when the standalone plugin declares its own.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP;

class Autoloaderish {
	const SOURCE = 'standalone';
}
