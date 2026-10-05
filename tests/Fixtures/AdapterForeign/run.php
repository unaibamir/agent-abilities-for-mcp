<?php
/**
 * Subprocess for AdapterForeignCopyTest: runs the bundled-adapter loader with no WordPress,
 * optionally after another copy has declared McpAdapter, and prints what the loader did as JSON.
 *
 * Usage: php run.php <foreign|none> <plugin-dir> [active-plugins-json]
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AAFM_PLUGIN_DIR', rtrim( $argv[2], '/' ) . '/' );

$GLOBALS['recorded_actions'] = array();

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['recorded_actions'][] = $hook . ':' . ( is_string( $callback ) ? $callback : 'closure' );
}

function get_option( $name, $default = false ) {
	global $argv;

	if ( 'active_plugins' === $name && isset( $argv[3] ) ) {
		return json_decode( $argv[3], true );
	}

	return $default;
}

function is_multisite() {
	return false;
}

if ( 'foreign' === $argv[1] ) {
	require __DIR__ . '/McpAdapter.php';
}

$before = count( spl_autoload_functions() );

require AAFM_PLUGIN_DIR . 'includes/adapter-loader.php';

$loaded = aafm_load_bundled_adapter();

echo json_encode(
	array(
		'loaded'        => $loaded,
		'autoloaders'   => count( spl_autoload_functions() ) - $before,
		'tools_handler' => class_exists( 'WP\\MCP\\Handlers\\Tools\\ToolsHandler', false ),
		'schema_loaded' => class_exists( 'WP\\McpSchema\\Schema', false ),
		'version'       => \WP\MCP\Core\McpAdapter::VERSION,
		'autoload_const' => defined( 'WP_MCP_AUTOLOAD' ) ? WP_MCP_AUTOLOAD : 'undefined',
		'actions'       => $GLOBALS['recorded_actions'],
	)
);
