<?php
/**
 * Test fixture: OUR bundled copy of a plugin-shell class (a direct child of WP\MCP\).
 *
 * Stands in for our bundled includes/Autoloader.php. The eager loader must SKIP this file so it
 * never pre-declares a bootstrap-shell class - otherwise the standalone plugin's unguarded
 * `require_once includes/Autoloader.php` would fatal on redeclaration and WSOD the site.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP;

class Autoloaderish {
	const SOURCE = 'bundle';
}
