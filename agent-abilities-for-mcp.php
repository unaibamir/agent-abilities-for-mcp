<?php
/**
 * Plugin Name:       Agent Abilities for MCP
 * Plugin URI:        https://github.com/unaibamir/agent-abilities-for-mcp
 * Description:       Exposes WordPress abilities to AI agents over the Model Context Protocol (MCP).
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Unaib Amir
 * Author URI:        https://github.com/unaibamir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-abilities-for-mcp
 * Domain Path:       /languages
 *
 * @package AgentAbilitiesForMCP
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AAFM_VERSION', '0.1.0' );
define( 'AAFM_PLUGIN_FILE', __FILE__ );
define( 'AAFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AAFM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function aafm_bootstrap() {
	// Plugin initialization will be wired up here.
}
add_action( 'plugins_loaded', 'aafm_bootstrap' );
