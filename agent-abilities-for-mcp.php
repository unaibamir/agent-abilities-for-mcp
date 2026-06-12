<?php
/**
 * Plugin Name:       Agent Abilities for MCP
 * Plugin URI:        https://github.com/unaibamir/agent-abilities-for-mcp
 * Description:       Exposes WordPress abilities to AI agents over the Model Context Protocol (MCP).
 * Version:           1.0.0
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

define( 'AAFM_VERSION', '1.0.0' );
define( 'AAFM_PLUGIN_FILE', __FILE__ );
define( 'AAFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AAFM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AAFM_MIN_ADAPTER_VERSION', '0.5.0' );

// Bound the activity log so a chatty (or adversarial deny-loop) agent can't grow it
// without limit. Pruned on insert, keeping the newest AAFM_LOG_MAX_ROWS rows. Both
// values are filterable (aafm_log_max_rows / aafm_log_prune_interval).
if ( ! defined( 'AAFM_LOG_MAX_ROWS' ) ) {
	define( 'AAFM_LOG_MAX_ROWS', 10000 );
}
if ( ! defined( 'AAFM_LOG_PRUNE_INTERVAL' ) ) {
	define( 'AAFM_LOG_PRUNE_INTERVAL', 200 );
}

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
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/structure.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/comments.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/media.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/users.php';

	add_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	add_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

	// Registered unconditionally: admin_init only fires on admin requests (where
	// includes/admin/page.php is loaded), so this is behavior-identical to gating it
	// behind is_admin(), while remaining wired at plugin load for deterministic tests.
	add_action( 'admin_init', 'aafm_register_privacy_policy_content' );

	require_once AAFM_PLUGIN_DIR . 'includes/admin/connection.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/page.php';
	if ( is_admin() ) {
		add_action( 'admin_menu', 'aafm_register_admin_menu' );
		add_action( 'admin_enqueue_scripts', 'aafm_enqueue_admin_assets' );
		add_action( 'wp_ajax_aafm_save_abilities', 'aafm_ajax_save_abilities' );
		add_action( 'wp_ajax_aafm_save_post_types', 'aafm_ajax_save_post_types' );
		add_action( 'wp_ajax_aafm_clear_log', 'aafm_ajax_clear_log' );
		add_action( 'wp_ajax_aafm_create_agent_user', 'aafm_ajax_create_agent_user' );
		add_action( 'wp_ajax_aafm_test_connection', 'aafm_ajax_test_connection' );
	}

	aafm_init_mcp();
}
add_action( 'plugins_loaded', 'aafm_bootstrap' );
