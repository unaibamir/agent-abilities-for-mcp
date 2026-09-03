<?php
/**
 * Plugin Name:       Agent Abilities for MCP - MCP Server with Permission Controls and Audit Log
 * Plugin URI:        https://agentabilitieswp.com
 * Description:       WordPress MCP server. Connect Claude, ChatGPT, or any AI agent, with permission controls, off by default, and a full audit log.
 * Version:           1.7.2
 * Requires at least: 6.9
 * Requires PHP:      7.4
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

define( 'AAFM_VERSION', '1.7.2' );
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
// gate). A prepended autoloader alone is not enough - later plugins' Composer autoloaders also
// prepend and leapfrog ours - so we EAGER-LOAD our copy: declare every WP\MCP\ class from our
// bundle now, during the plugin-include phase. Declaring every class up front beats a sibling's
// lazy Composer autoloader, which only resolves a class on first use: by the time anything asks for
// a WP\MCP\ class ours is already declared, so PHP commits to our copy and later siblings
// transparently use it. (Active plugins load in activation order, not alphabetically, so we do not
// rely on winning a name sort.) The prepended autoloader (still registered first) resolves
// interface/trait dependencies during the eager load and covers no-conflict installs. The
// floor/notice logic in includes/bootstrap.php stays as the fallback for a sibling that eager-loads
// before us and declares an incompatible copy first.
require_once AAFM_PLUGIN_DIR . 'includes/adapter-loader.php';
aafm_register_adapter_autoloader();
aafm_eager_load_adapter();

// Stored plain-text sanitizing. First, because the audit log and the OAuth client registration
// below both sanitize text they store, and both are required before aafm_bootstrap() runs. It
// depends on nothing else the plugin defines, so there is no ordering cost to putting it here.
require_once AAFM_PLUGIN_DIR . 'includes/text.php';

// Audit log is required early so the activation hook can install its table.
require_once AAFM_PLUGIN_DIR . 'includes/audit/log.php';
require_once AAFM_PLUGIN_DIR . 'includes/audit/detail.php';
// The high-risk floor. Required at top level, not inside the bootstrap, because both the admin
// screens and the registration walk read it, and neither should have to care which ran first.
require_once AAFM_PLUGIN_DIR . 'includes/audit/high-risk.php';
// Read-only mode, the second floor. Same reasoning as the line above: the admin screens, the
// native registration walk, and the bridge walk all read it.
require_once AAFM_PLUGIN_DIR . 'includes/audit/read-only.php';

/**
 * Schedule the daily activity-log prune event, if not already scheduled.
 *
 * The event fires `aafm_prune_activity_log_daily`, which trims entries older than the
 * configured retention window. Runs on activation and self-heals on both admin_init
 * and rest_api_init. A network activation fires the activation hook once, on the
 * activation blog only, so a subsite serving only REST/MCP traffic would otherwise
 * write audit rows forever with the retention prune never scheduled; the rest_api_init
 * heal schedules it on the path that actually carries that traffic, matching the
 * activity-log schema's own self-heal. The admin_init heal also covers a single-site
 * install that predates this event and was updated in place without a reactivation.
 * wp_next_scheduled() keeps every path idempotent.
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
add_action( 'rest_api_init', 'aafm_schedule_log_prune' );

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

/**
 * Activation: create the activity-log and OAuth tables.
 *
 * Honors the $network_wide flag: a network activation loops every site with
 * switch_to_blog() and installs both schemas per site, matching the get_sites()
 * loop uninstall.php already runs on removal. Without this only the main site got
 * tables, so a subsite serving MCP lost every audit row and OAuth had nowhere to
 * store clients or tokens. The rest_api_init/admin_init self-heals in
 * includes/audit/log.php and includes/oauth/schema.php remain the backstop for
 * sites this loop never saw; this installs eagerly so no site ever serves its
 * first requests against missing tables.
 *
 * @param bool $network_wide True when the plugin is being activated network-wide.
 * @return void
 */
function aafm_activate( $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			aafm_install_activity_log();
			aafm_install_oauth_tables();
			restore_current_blog();
		}
		return;
	}

	aafm_install_activity_log();
	aafm_install_oauth_tables();
}
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_activate' );

/**
 * Create the plugin tables for a site added after a network activation.
 *
 * The activation loop above only covers sites that exist when the plugin is
 * activated; core fires wp_initialize_site for every site created later. Gated on
 * the plugin being network-active, and hooked at priority 100 - after core's own
 * wp_initialize_site() initializer at 10 has built the new site's base tables and
 * options, which the installers need.
 *
 * @param WP_Site|mixed $new_site The site object core just created.
 * @return void
 */
function aafm_initialize_new_site_tables( $new_site ): void {
	if ( ! $new_site instanceof WP_Site ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( AAFM_PLUGIN_BASENAME ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	aafm_install_activity_log();
	aafm_install_oauth_tables();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'aafm_initialize_new_site_tables', 100 );

/**
 * Schedule the daily OAuth cleanup event on activation, if not already scheduled.
 *
 * The event fires the `aafm_oauth_cleanup` action (wired at file scope below via
 * add_action( 'aafm_oauth_cleanup', … )), which prunes expired codes and dead
 * tokens and reaps abandoned DCR clients.
 *
 * Runs on activation and self-heals on admin_init and rest_api_init. A network
 * activation fires the activation hook once, on the activation blog only, so
 * subsites would otherwise never schedule this event; WP-Cron is per-site, and
 * without the reaper the public DCR endpoint could grow the clients table
 * without bound on any subsite that turns on OAuth. The admin_init heal also
 * covers a single-site install that predates this event and was updated in
 * place without a reactivation; the rest_api_init heal covers a subsite serving
 * only REST/MCP traffic. wp_next_scheduled() keeps every path idempotent.
 *
 * @return void
 */
function aafm_oauth_schedule_cleanup(): void {
	if ( ! wp_next_scheduled( 'aafm_oauth_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'aafm_oauth_cleanup' );
	}
}
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_oauth_schedule_cleanup' );
add_action( 'admin_init', 'aafm_oauth_schedule_cleanup' );
add_action( 'rest_api_init', 'aafm_oauth_schedule_cleanup' );

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

// OAuth lifecycle audit logging: one activity-log row per register/authorize/token/refresh/revoke
// event (non-secret context only). Writes through the audit log module, required earlier.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/audit.php';

// Authorization codes: hashed storage, 60-second TTL, atomic one-time redemption.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/codes.php';

// Access/refresh token manager: hashed storage, refresh rotation, reuse detection.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/tokens.php';

// Discovery documents: the two .well-known metadata files served before REST auth.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/discovery.php';
add_action( 'parse_request', 'aafm_oauth_maybe_serve_well_known', 0 );

// Seed the OAuth toggles at activation (add_option only - never clobbers a saved value): OAuth off,
// dynamic client registration on.
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_oauth_seed_default_options' );

// Flag a genuinely new install so the first-activation admin-menu pointer shows once. The callback
// lives in includes/admin/onboarding-pointer.php, required here at top level so it is defined when
// the activation hook fires: activating a plugin includes its main file and runs the activation hook
// in the SAME request without ever firing plugins_loaded, so a callback defined only inside
// aafm_bootstrap() (hooked on plugins_loaded) would be an undefined function - fatal on activation.
// The matching require_once inside aafm_bootstrap() then no-ops. The callback uses add_option so a
// later reactivation never re-arms it; per-user dismissal is tracked in the core dismissed-pointers
// meta.
require_once AAFM_PLUGIN_DIR . 'includes/admin/onboarding-pointer.php';
register_activation_hook( AAFM_PLUGIN_FILE, 'aafm_quickconnect_flag_menu_pointer' );

// One-time upgrade safety: an install updated in place from a pre-off-by-default version
// holds no stored toggle row and ran on the old on-by-default reader, so the new
// fail-closed default would disable its OAuth surface (and any live connection) on update.
// Preserve the prior state once, early, before any request-time toggle read; fresh installs
// (seeded '0' at activation) stay off. See aafm_oauth_preserve_toggle_on_upgrade().
add_action( 'plugins_loaded', 'aafm_oauth_preserve_toggle_on_upgrade', 1 );

// One-time DCR default-on adoption: dynamic client registration used to default off (the #90
// footgun that stopped ChatGPT and Claude connecting), so installs that predate this release hold
// a stored '0'. Flip that old default on once, early, with its own guard. See
// aafm_oauth_dcr_adopt_on_by_default().
add_action( 'plugins_loaded', 'aafm_oauth_dcr_adopt_on_by_default', 1 );

// Surface the transport's 401 challenge (resource_metadata) as a real
// WWW-Authenticate header on the dispatched REST error response.
add_filter( 'rest_post_dispatch', 'aafm_oauth_filter_rest_challenge', 10, 3 );

// Let browser-context (CORS) MCP clients read the OAuth challenge + MCP session
// header off the response, and send the session/protocol headers back on follow-up
// requests. Registered unconditionally and gated INSIDE each callback on the toggle,
// so enabling OAuth at runtime takes effect on the same request; both no-op when off.
add_filter( 'rest_exposed_cors_headers', 'aafm_oauth_filter_exposed_cors_headers' );
add_filter( 'rest_allowed_cors_headers', 'aafm_oauth_filter_allowed_cors_headers' );

// OAuth REST endpoints: dynamic client registration, token, and revocation.
require_once AAFM_PLUGIN_DIR . 'includes/oauth/rest.php';
add_action( 'rest_api_init', 'aafm_oauth_register_rest_routes' );

// Re-shape core's malformed-JSON rejection into RFC 6749 on the three OAuth routes only;
// every other route's rest_invalid_json response passes through untouched.
add_filter( 'rest_post_dispatch', 'aafm_oauth_filter_malformed_json', 10, 3 );

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

// wp_kses allowlist helpers - loaded unconditionally so they are available to the
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
	require_once AAFM_PLUGIN_DIR . 'includes/wpml.php';
	require_once AAFM_PLUGIN_DIR . 'includes/safety.php';
	require_once AAFM_PLUGIN_DIR . 'includes/block-guard.php';
	require_once AAFM_PLUGIN_DIR . 'includes/register.php';
	require_once AAFM_PLUGIN_DIR . 'includes/class-aafm-rate-limited-ability.php';
	require_once AAFM_PLUGIN_DIR . 'includes/server.php';
	require_once AAFM_PLUGIN_DIR . 'includes/bridge.php';
	require_once AAFM_PLUGIN_DIR . 'includes/catalog.php';
	require_once AAFM_PLUGIN_DIR . 'includes/integrations.php';
	require_once AAFM_PLUGIN_DIR . 'includes/integration-manifest.php';
	require_once AAFM_PLUGIN_DIR . 'includes/bootstrap.php';

	// Rewrite four specific "not found" 404s on the MCP route to 200, so an unknown or
	// governance-disabled tool reads as an in-band error an agent can correct from, not as
	// the MCP session-terminated signal. -32005 (session not found) is deliberately left
	// out and keeps its 404. Registered after bootstrap.php, which is where
	// aafm_mcp_rest_route() - the first thing this filter calls - is defined.
	add_filter( 'rest_post_dispatch', 'aafm_mcp_filter_governed_error_status', 10, 3 );

	// Session-persistence guard: the adapter's SessionManager::create_session() returns a session id
	// without checking its update_user_meta() write succeeded, so a silent write failure hands the
	// client a valid-looking Mcp-Session-Id that its next request can't resolve (the "OAuth ok, then
	// can't connect" field report). This verifies the just-issued session is actually in the user's
	// store; when it is not, it strips the phantom header and rewrites the body to a JSON-RPC -32603 so
	// the client gets an honest, retryable error. Priority 11 so it runs AFTER the transport's own
	// priority-10 rest_post_dispatch closure has stamped the Mcp-Session-Id header, and registered
	// BEFORE aafm_log_mcp_transport_outcome() (also priority 11) so the -32603 it produces is logged as
	// a (transport) internal_error row.
	add_filter( 'rest_post_dispatch', 'aafm_mcp_guard_unpersisted_session', 11, 3 );

	// Transport-visibility logging: write one (transport) activity row for an MCP-route JSON-RPC
	// error response, so a failed /mcp request is self-diagnosing (reached-WP-and-failed vs
	// never-reached-WP). Pure observability - reads the response, changes no status. Priority 11 so
	// it runs AFTER the governed-status rewrite above and records the final HTTP status the client sees.
	add_filter( 'rest_post_dispatch', 'aafm_log_mcp_transport_outcome', 11, 3 );

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
	// Bridged wrappers register at PHP_INT_MAX on the same init pass so EVERY foreign ability is
	// already in the registry when the bridge walks it - including ones a foreign plugin hooks at
	// a later-than-native priority (e.g. 20). A fixed low priority (11) would run before those and
	// silently fail to bridge them on every request. This still lands well before the MCP server
	// builds its tool list (mcp_adapter_init on rest_api_init:15), and after native registration.
	add_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities', PHP_INT_MAX );

	// Registered unconditionally: admin_init only fires on admin requests (where
	// includes/admin/page.php is loaded), so this is behavior-identical to gating it
	// behind is_admin(), while remaining wired at plugin load for deterministic tests.
	add_action( 'admin_init', 'aafm_register_privacy_policy_content' );

	// Same admin_init rationale: keeps the OAuth schema current on real upgrades
	// (the installer runs only when the recorded version is behind). Hooked on
	// rest_api_init as well, mirroring the activity-log wiring in includes/audit/log.php:
	// a headless site that auto-updates over cron and serves bearer traffic over REST may
	// never see an admin request, and its OAuth tables would stay on the old schema
	// forever. The version guard makes both hooks one option compare per request.
	add_action( 'admin_init', 'aafm_maybe_upgrade_oauth_tables' );
	add_action( 'rest_api_init', 'aafm_maybe_upgrade_oauth_tables' );

	// One-time backfill so a pre-marker install's agent user is recognised by the onboarding
	// checklist. Self-guarded by a stored flag; runs at most once. Defined in admin/connection.php.
	add_action( 'admin_init', 'aafm_backfill_agent_user_marker' );

	require_once AAFM_PLUGIN_DIR . 'includes/admin/icons.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/notices.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/components.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/dashboard.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/connection.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/quickconnect.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/onboarding-pointer.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/review-request.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/disclosures.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/page.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/settings.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/integrations.php';
	require_once AAFM_PLUGIN_DIR . 'includes/admin/bridge-directory.php';
	if ( is_admin() ) {
		add_action( 'admin_menu', 'aafm_register_admin_menu' );
		add_filter( 'submenu_file', 'aafm_highlight_tab_submenu' );
		add_filter( 'plugin_action_links_' . AAFM_PLUGIN_BASENAME, 'aafm_plugin_action_links' );
		add_action( 'admin_enqueue_scripts', 'aafm_enqueue_admin_assets' );
		add_action( 'wp_ajax_aafm_save_abilities', 'aafm_ajax_save_abilities' );
		add_action( 'wp_ajax_aafm_save_bridged_abilities', 'aafm_ajax_save_bridged_abilities' );
		add_action( 'wp_ajax_aafm_save_post_types', 'aafm_ajax_save_post_types' );
		add_action( 'wp_ajax_aafm_save_meta_keys', 'aafm_ajax_save_meta_keys' );
		add_action( 'wp_ajax_aafm_save_denied_meta_keys', 'aafm_ajax_save_denied_meta_keys' );
		add_action( 'wp_ajax_aafm_save_user_meta_keys', 'aafm_ajax_save_user_meta_keys' );
		add_action( 'wp_ajax_aafm_save_term_meta_keys', 'aafm_ajax_save_term_meta_keys' );
		add_action( 'wp_ajax_aafm_save_settings', 'aafm_ajax_save_settings' );
		add_action( 'wp_ajax_aafm_clear_log', 'aafm_ajax_clear_log' );
		add_action( 'wp_ajax_aafm_get_log_page', 'aafm_ajax_get_log_page' );
		add_action( 'admin_post_aafm_export_log', 'aafm_handle_export_activity_log' );
		add_action( 'wp_ajax_aafm_reset_plugin', 'aafm_ajax_reset_plugin' );
		add_action( 'wp_ajax_aafm_create_agent_user', 'aafm_ajax_create_agent_user' );
		add_action( 'wp_ajax_aafm_test_connection', 'aafm_ajax_test_connection' );
		add_action( 'wp_ajax_aafm_oauth_revoke_client', 'aafm_ajax_oauth_revoke_client' );
		add_action( 'wp_ajax_aafm_oauth_revoke_grant', 'aafm_ajax_oauth_revoke_grant' );
		add_action( 'wp_ajax_aafm_quickconnect_oauth', 'aafm_ajax_quickconnect_oauth' );
		add_action( 'wp_ajax_aafm_quickconnect_finish', 'aafm_ajax_quickconnect_finish' );
		add_action( 'wp_ajax_aafm_quickconnect_dismiss', 'aafm_ajax_quickconnect_dismiss' );
		add_action( 'wp_ajax_aafm_review_request', 'aafm_ajax_review_request' );
		// The same answer, arriving as a plain link when the notice's footer script never ran.
		add_action( 'admin_post_aafm_review_request', 'aafm_handle_review_request_post' );
		add_action( 'admin_notices', 'aafm_render_review_request_notice' );
		add_action( 'admin_notices', 'aafm_notice_omitted_abilities' );
		add_action( 'admin_enqueue_scripts', 'aafm_maybe_enqueue_menu_pointer' );
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'aafm catalog export', 'aafm_cli_catalog_export' );
	}

	aafm_init_mcp();
}
add_action( 'plugins_loaded', 'aafm_bootstrap' );
