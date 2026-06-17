<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and our plugin.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/agent-abilities-for-mcp.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// Shared host-API stub helpers for the Wave 4 integration tests. Loaded after the WP
// test bootstrap so add_filter() and the plugin functions the stubs reference exist.
require_once __DIR__ . '/stubs/IntegrationStubs.php';
