<?php
/**
 * Plugin Name:       Agent Abilities for MCP
 * Plugin URI:        https://github.com/unaibamir/agent-abilities-for-mcp
 * Description:       Exposes WordPress abilities to AI agents over the Model Context Protocol (MCP).
 * Version:           0.1.0
 * Requires at least: 6.9
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
define( 'AAFM_MIN_ADAPTER_VERSION', '0.5.0' );

// Audit log is required early so the activation hook can install its table.
require_once AAFM_PLUGIN_DIR . 'includes/audit/log.php';
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_install_activity_log' );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function aafm_bootstrap() {
	require_once AAFM_PLUGIN_DIR . 'vendor/autoload_packages.php';
	require_once AAFM_PLUGIN_DIR . 'includes/registry.php';
	require_once AAFM_PLUGIN_DIR . 'includes/helpers.php';
	require_once AAFM_PLUGIN_DIR . 'includes/register.php';
	require_once AAFM_PLUGIN_DIR . 'includes/server.php';
	require_once AAFM_PLUGIN_DIR . 'includes/bootstrap.php';

	require_once AAFM_PLUGIN_DIR . 'includes/abilities/posts.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/pages.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/terms.php';

	add_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	add_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

	aafm_init_mcp();
}
add_action( 'plugins_loaded', 'aafm_bootstrap' );
