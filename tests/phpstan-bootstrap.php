<?php
/**
 * PHPStan-only bootstrap.
 *
 * Declares the plugin's runtime-valued constants (those defined from `plugin_dir_url()`
 * and friends) so static analysis can resolve them. PHPStan resolves constants whose
 * `define()` value is a literal automatically; the path/URL constants are computed at
 * runtime, so they are declared here for analysis only. This file is never loaded by
 * WordPress.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

if ( ! defined( 'OVERSIO_PLUGIN_URL' ) ) {
	define( 'OVERSIO_PLUGIN_URL', 'https://example.com/wp-content/plugins/oversio-agent-abilities/' );
}
if ( ! defined( 'OVERSIO_PLUGIN_DIR' ) ) {
	define( 'OVERSIO_PLUGIN_DIR', '/var/www/html/wp-content/plugins/oversio-agent-abilities/' );
}
if ( ! defined( 'OVERSIO_PLUGIN_BASENAME' ) ) {
	define( 'OVERSIO_PLUGIN_BASENAME', 'oversio-agent-abilities/oversio-agent-abilities.php' );
}
