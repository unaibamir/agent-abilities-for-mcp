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

	// The other copy's own autoloader resolves the rest of its classes lazily.
	spl_autoload_register(
		static function ( $class_name ) {
			if ( 'WP\\MCP\\Handlers\\Tools\\ToolsHandler' === $class_name ) {
				require __DIR__ . '/ToolsHandler.php';
			}
		}
	);
}

$before = count( spl_autoload_functions() ?: array() );

require AAFM_PLUGIN_DIR . 'includes/adapter-loader.php';

$loaded = aafm_load_bundled_adapter();

$after_loader = count( spl_autoload_functions() ?: array() );
$vendor       = aafm_load_vendor_autoloader();
$after_vendor = count( spl_autoload_functions() ?: array() );

echo json_encode(
	array(
		'loaded'        => $loaded,
		'autoloaders'   => $after_loader - $before,
		'vendor_loaded' => $vendor,
		'vendor_added'  => $after_vendor - $after_loader,
		'gate_present'  => aafm_adapter_capability_gate_present(),
		'tools_handler' => class_exists( 'WP\\MCP\\Core\\McpServer', false ),
		'schema_loaded' => class_exists( 'WP\\McpSchema\\Schema', false ),
		'version'       => \WP\MCP\Core\McpAdapter::VERSION,
		'autoload_const' => defined( 'WP_MCP_AUTOLOAD' ) ? WP_MCP_AUTOLOAD : 'undefined',
		'actions'       => $GLOBALS['recorded_actions'],
	)
);
