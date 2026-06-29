<?php
/**
 * Plugin Name:       Agent Abilities for MCP — MCP Server for AI Agents
 * Plugin URI:        https://github.com/unaibamir/agent-abilities-for-mcp
 * Description:       Connect AI agents to your WordPress site as a scoped, least-privilege user over MCP. Off by default, every call audited.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Unaib Amir
 * Author URI:        https://unaib.com
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

// Win the WP\MCP\ class-declaration race. The wordpress/mcp-adapter library is bundled by many
// plugins under the same WP\MCP\ namespace, but PHP can load only one McpAdapter per request:
// whichever copy is declared first wins site-wide. A sibling shipping an older copy via a plain
// Composer autoloader (confirmed: Rank Math SEO 0.4.1) can win that race and trip our floor check,
// killing our /mcp route. We MUST run our own 0.5.0 (0.4.1 lacks the per-connection capability
// gate). A prepended autoloader alone is not enough — later plugins' Composer autoloaders also
// prepend and leapfrog ours — so we EAGER-LOAD our copy: declare every WP\MCP\ class from our
// bundle now, during the plugin-include phase. Because plugin folders load alphabetically and we
// sort first as "agent-abilities-for-mcp", this runs before any conflicting sibling's file, so PHP
// commits to our copy and later siblings transparently use it. The prepended autoloader (still
// registered first) resolves interface/trait dependencies during the eager load and covers
// no-conflict installs. The floor/notice logic in includes/bootstrap.php stays as the fallback for
// a sibling that sorts before us and declares an incompatible copy first.
require_once AAFM_PLUGIN_DIR . 'includes/adapter-loader.php';
aafm_register_adapter_autoloader();
aafm_eager_load_adapter();

// Audit log is required early so the activation hook can install its table.
require_once AAFM_PLUGIN_DIR . 'includes/audit/log.php';
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_install_activity_log' );

/**
 * Schedule the daily activity-log prune event, if not already scheduled.
 *
 * The event fires `aafm_prune_activity_log_daily`, which trims entries older than the
 * configured retention window. Runs on activation and self-heals on admin_init so an
 * install that predates this event still picks it up without a reactivation.
 *
 * @return void
 */
function aafm_schedule_log_prune(): void {
	if ( ! wp_next_scheduled( 'aafm_prune_activity_log_daily' ) ) {
		wp_schedule_event( time(), 'daily', 'aafm_prune_activity_log_daily' );
	}
}
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_schedule_log_prune' );
add_action( 'admin_init', 'aafm_schedule_log_prune' );

/**
 * Clear the scheduled activity-log prune event on deactivation.
 *
 * @return void
 */
function aafm_unschedule_log_prune(): void {
	wp_clear_scheduled_hook( 'aafm_prune_activity_log_daily' );
}
register_deactivation_hook( AAFM_PLUGIN_FILE, 'aafm_unschedule_log_prune' );

// The cron event fires this action; the handler prunes entries past the retention window.
add_action( 'aafm_prune_activity_log_daily', 'aafm_prune_activity_log' );

// OAuth storage schema is required early so the activation hook can install its tables.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/schema.php';
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_install_oauth_tables' );

/**
 * Schedule the daily OAuth cleanup event on activation, if not already scheduled.
 *
 * The event fires the `aafm_oauth_cleanup` action (wired in aafm_bootstrap()),
 * which prunes expired codes and dead tokens.
 *
 * @return void
 */
function aafm_oauth_schedule_cleanup(): void {
	if ( ! wp_next_scheduled( 'aafm_oauth_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'aafm_oauth_cleanup' );
	}
}
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_oauth_schedule_cleanup' );

/**
 * Clear the scheduled OAuth cleanup event on deactivation.
 *
 * @return void
 */
function aafm_oauth_unschedule_cleanup(): void {
	wp_clear_scheduled_hook( 'aafm_oauth_cleanup' );
}
register_deactivation_hook( AAFM_PLUGIN_FILE, 'aafm_oauth_unschedule_cleanup' );

// The cron event fires this action; the handler prunes expired OAuth artifacts.
add_action( 'aafm_oauth_cleanup', 'aafm_oauth_cleanup' );

// PKCE helpers are pure functions with nothing to hook.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/pkce.php';

// HTTP helpers (transport policy, rate limiting) load before the client registry,
// which calls into them and the audit log's aafm_source_ip().
require_once AAFM_PLUGIN_DIR . 'includes/oauth/http.php';
require_once AAFM_PLUGIN_DIR . 'includes/oauth/clients.php';

// Authorization codes: hashed storage, 60-second TTL, atomic one-time redemption.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/codes.php';

// Access/refresh token manager: hashed storage, refresh rotation, reuse detection.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/tokens.php';

// Discovery documents: the two .well-known metadata files served before REST auth.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/discovery.php';
add_action( 'parse_request', 'aafm_oauth_maybe_serve_well_known', 0 );

// Seed the OAuth toggles to "on" at activation (add_option only — never clobbers a saved value).
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_oauth_seed_default_options' );

// Surface the transport's 401 challenge (resource_metadata) as a real
// WWW-Authenticate header on the dispatched REST error response.
add_filter( 'rest_post_dispatch', 'aafm_oauth_filter_rest_challenge', 10, 3 );

// Let browser-context (CORS) MCP clients read the OAuth challenge + MCP session
// header off the response, and send the session/protocol headers back on follow-up
// requests. Gated on the toggle to match the rest of the OAuth surface.
if ( aafm_oauth_enabled() ) {
	add_filter( 'rest_exposed_cors_headers', 'aafm_oauth_filter_exposed_cors_headers' );
	add_filter( 'rest_allowed_cors_headers', 'aafm_oauth_filter_allowed_cors_headers' );
}

// OAuth REST endpoints: dynamic client registration, token, and revocation.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/rest.php';
add_action( 'rest_api_init', 'aafm_oauth_register_rest_routes' );

// Bearer-token validator: resolve an OAuth access token to its approving user on
// the same auth layer Application Passwords use, before the transport gate runs.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/validator.php';
// Priority 20 runs after cookie auth (10) and alongside core's Application
// Password resolver. Ordering is not load-bearing for the frozen invariant: the
// resolver returns early whenever a user is already set, so it can never preempt
// an App Password (or any other) identity regardless of which runs first.
add_filter( 'determine_current_user', 'aafm_oauth_resolve_current_user', 20 );
// Defensive pass-through so a present-but-invalid OAuth token never gets turned
// into a hard auth failure on unrelated REST routes.
add_filter( 'rest_authentication_errors', 'aafm_oauth_rest_authentication_errors', 5 );

// wp_kses allowlist helpers — loaded unconditionally so they are available to the
// OAuth consent page (rendered on the front end, before aafm_bootstrap()).
require_once AAFM_PLUGIN_DIR . 'includes/admin/kses.php';

// Authorization endpoint + consent screen: served off init at ?aafm_oauth=authorize.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/authorize.php';
require_once AAFM_PLUGIN_DIR . 'includes/oauth/consent-template.php';
add_action( 'init', 'aafm_oauth_handle_authorize' );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function aafm_bootstrap() {
	require_once AAFM_PLUGIN_DIR . 'vendor/autoload.php';
	require_once AAFM_PLUGIN_DIR . 'includes/registry.php';
	require_once AAFM_PLUGIN_DIR . 'includes/helpers.php';
	require_once AAFM_PLUGIN_DIR . 'includes/safety.php';
	require_once AAFM_PLUGIN_DIR . 'includes/register.php';
	require_once AAFM_PLUGIN_DIR . 'includes/server.php';
	require_once AAFM_PLUGIN_DIR . 'includes/integrations.php';
	require_once AAFM_PLUGIN_DIR . 'includes/integration-manifest.php';
	require_once AAFM_PLUGIN_DIR . 'includes/bootstrap.php';

	require_once AAFM_PLUGIN_DIR . 'includes/abilities/posts.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/pages.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/terms.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/structure.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/comments.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/media.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/users.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/meta.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/user-meta.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/revisions.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/search.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/settings.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/plugins.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/activity-log.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/blocks.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/menus.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/themes.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/yoast.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/rankmath.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/aioseo.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/acf-integration.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/_shared.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/products.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/variations.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/attributes.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/orders.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/customers.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/coupons.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/shipping.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/tax.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/reports.php';
	require_once AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/gateways.php';

	add_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	add_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

	// Registered unconditionally: admin_init only fires on admin requests (where
	// includes/admin/page.php is loaded), so this is behavior-identical to gating it
	// behind is_admin(), while remaining wired at plugin load for deterministic tests.
	add_action( 'admin_init', 'aafm_register_privacy_policy_content' );

	// Same admin_init rationale: keeps the OAuth schema current on real upgrades
	// (the installer runs only when the recorded version is behind).
	add_action( 'admin_init', 'aafm_maybe_upgrade_oauth_tables' );

	require_once AAFM_PLUGIN_DIR . 'includes/admin/icons.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/notices.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/components.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/dashboard.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/connection.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/disclosures.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/page.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/settings.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/integrations.php';
	if ( is_admin() ) {
		add_action( 'admin_menu', 'aafm_register_admin_menu' );
		add_filter( 'submenu_file', 'aafm_highlight_tab_submenu' );
		add_filter( 'plugin_action_links_' . AAFM_PLUGIN_BASENAME, 'aafm_plugin_action_links' );
		add_action( 'admin_enqueue_scripts', 'aafm_enqueue_admin_assets' );
		add_action( 'wp_ajax_aafm_save_abilities', 'aafm_ajax_save_abilities' );
		add_action( 'wp_ajax_aafm_save_post_types', 'aafm_ajax_save_post_types' );
		add_action( 'wp_ajax_aafm_save_meta_keys', 'aafm_ajax_save_meta_keys' );
		add_action( 'wp_ajax_aafm_save_denied_meta_keys', 'aafm_ajax_save_denied_meta_keys' );
		add_action( 'wp_ajax_aafm_save_user_meta_keys', 'aafm_ajax_save_user_meta_keys' );
		add_action( 'wp_ajax_aafm_save_term_meta_keys', 'aafm_ajax_save_term_meta_keys' );
		add_action( 'wp_ajax_aafm_save_settings', 'aafm_ajax_save_settings' );
		add_action( 'wp_ajax_aafm_clear_log', 'aafm_ajax_clear_log' );
		add_action( 'wp_ajax_aafm_get_log_page', 'aafm_ajax_get_log_page' );
		add_action( 'wp_ajax_aafm_reset_plugin', 'aafm_ajax_reset_plugin' );
		add_action( 'wp_ajax_aafm_create_agent_user', 'aafm_ajax_create_agent_user' );
		add_action( 'wp_ajax_aafm_test_connection', 'aafm_ajax_test_connection' );
		add_action( 'wp_ajax_aafm_oauth_revoke_client', 'aafm_ajax_oauth_revoke_client' );
		add_action( 'wp_ajax_aafm_oauth_revoke_grant', 'aafm_ajax_oauth_revoke_grant' );
	}

	aafm_init_mcp();
}
add_action( 'plugins_loaded', 'aafm_bootstrap' );
