<?php
/**
 * MCP adapter bootstrap + coexistence guard.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use WP\MCP\Core\McpAdapter;

/**
 * The MCP server's REST namespace. Single source for the four sites that need the route
 * literal (discovery, validator, connection, server). Splitting namespace from route keeps
 * the value byte-identical to what create_server() registers and what oversio_endpoint_url()
 * builds — the OAuth audience binding (hash_equals on the endpoint URL) is byte-sensitive.
 */
if ( ! defined( 'OVERSIO_MCP_NAMESPACE' ) ) {
	define( 'OVERSIO_MCP_NAMESPACE', 'oversio-agent-abilities' );
}

/**
 * The MCP server's route segment under its namespace.
 */
if ( ! defined( 'OVERSIO_MCP_ROUTE_SEGMENT' ) ) {
	define( 'OVERSIO_MCP_ROUTE_SEGMENT', 'mcp' );
}

/**
 * The MCP REST route WITHOUT a leading slash: "oversio-agent-abilities/mcp".
 *
 * This is the exact string passed to rest_url() (in oversio_endpoint_url()) and the
 * namespace/route create_server() registers. Keep callers that need rest_url() input
 * on this form.
 *
 * @return string
 */
function oversio_mcp_rest_namespace_route(): string {
	return OVERSIO_MCP_NAMESPACE . '/' . OVERSIO_MCP_ROUTE_SEGMENT;
}

/**
 * The MCP REST route WITH a leading slash: "/oversio-agent-abilities/mcp".
 *
 * The form WP_REST_Request::get_route() returns and the routing predicates compare against.
 *
 * @return string
 */
function oversio_mcp_rest_route(): string {
	return '/' . oversio_mcp_rest_namespace_route();
}

/**
 * Upper bound (exclusive) for a compatible MCP adapter version.
 *
 * The plugin is built against the adapter's 0.5.x contract (create_server() signature,
 * initialize-response shape, tools-list filter), so it gates the loaded copy to the tested
 * range [floor, next-minor) and warns the operator otherwise.
 *
 * After the eager-load fix (see adapter-loader.php), our bundled copy is the one in use
 * whenever we load before the conflicting sibling — and because we sort alphabetically first
 * as "oversio-agent-abilities", that is the normal case. We deliberately OVERRIDE any
 * later-loading sibling's copy of ANY version (older or newer); the trade is that a sibling
 * bundling a newer adapter is forced onto our version, which is acceptable because the
 * adapter's public API is additive and stable across the versions we support.
 *
 * Consequently this floor/upper-bound check and the "too old" / "too new" notices below now
 * only fire in the residual case: an incompatible copy is declared by a plugin that loads
 * BEFORE us (an alphabetically-earlier folder), so its copy wins the class declaration before
 * our eager load runs. Bump the bound deliberately after verifying against a new adapter line.
 */
if ( ! defined( 'OVERSIO_MAX_ADAPTER_VERSION' ) ) {
	define( 'OVERSIO_MAX_ADAPTER_VERSION', '0.6.0' );
}

/**
 * Whether the loaded adapter version is within the tested compatibility range.
 *
 * Requires the version to be at or above the floor AND strictly below the upper bound, so a
 * too-old adapter (below the floor) and a too-new one (at or above the next breaking line)
 * are both rejected.
 *
 * @param string $loaded_version Version reported by the active adapter copy.
 * @return bool
 */
function oversio_adapter_is_compatible( string $loaded_version ): bool {
	return version_compare( $loaded_version, OVERSIO_MIN_ADAPTER_VERSION, '>=' )
		&& version_compare( $loaded_version, OVERSIO_MAX_ADAPTER_VERSION, '<' );
}

/**
 * Whether the loaded adapter is newer than the tested upper bound.
 *
 * Lets oversio_init_mcp() pick the "too new" notice apart from the "too old" one.
 *
 * @param string $loaded_version Version reported by the active adapter copy.
 * @return bool
 */
function oversio_adapter_is_too_new( string $loaded_version ): bool {
	return version_compare( $loaded_version, OVERSIO_MAX_ADAPTER_VERSION, '>=' );
}

/**
 * The version of the adapter actually loaded (whoever's copy won via Jetpack Autoloader).
 *
 * @return string|null
 */
function oversio_loaded_adapter_version(): ?string {
	if ( ! class_exists( McpAdapter::class ) ) {
		return null;
	}
	return defined( McpAdapter::class . '::VERSION' ) ? (string) McpAdapter::VERSION : '0.0.0';
}

/**
 * Initialize the MCP layer if a compatible adapter is present; otherwise show a notice.
 *
 * @return bool True when initialization proceeded.
 */
function oversio_init_mcp(): bool {
	$version = oversio_loaded_adapter_version();

	if ( null === $version ) {
		add_action( 'admin_notices', 'oversio_notice_adapter_missing' );
		return false;
	}
	if ( ! oversio_adapter_is_compatible( $version ) ) {
		if ( oversio_adapter_is_too_new( $version ) ) {
			add_action( 'admin_notices', 'oversio_notice_adapter_too_new' );
		} else {
			add_action( 'admin_notices', 'oversio_notice_adapter_outdated' );
		}
		return false;
	}

	// Only our governed server should exist.
	add_filter( 'mcp_adapter_create_default_server', '__return_false' );

	McpAdapter::instance();
	add_action( 'mcp_adapter_init', 'oversio_register_mcp_server' );

	return true;
}

/**
 * Admin notice: no adapter available.
 *
 * @return void
 */
function oversio_notice_adapter_missing(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Oversio Agent Abilities could not load the MCP adapter. Please reinstall the plugin.', 'oversio-agent-abilities' );
	echo '</p></div>';
}

/**
 * Admin notice: another plugin loaded an adapter older than our floor.
 *
 * Names the offending plugin when it can be resolved (reflect the loaded McpAdapter class file,
 * map it to its plugin folder under WP_PLUGIN_DIR, read its header name), and reports the loaded
 * vs required versions, so the operator knows exactly which plugin to update or deactivate. Falls
 * back to the generic wording when the plugin cannot be resolved. All output is escaped.
 *
 * @return void
 */
function oversio_notice_adapter_outdated(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$loaded = oversio_loaded_adapter_version() ?? __( 'unknown', 'oversio-agent-abilities' );
	$plugin = oversio_resolve_adapter_owner_plugin();

	echo '<div class="notice notice-warning"><p>';
	if ( '' !== $plugin ) {
		printf(
			/* translators: 1: offending plugin name, 2: loaded adapter version, 3: minimum required adapter version. */
			esc_html__( 'Oversio Agent Abilities is disabled: the plugin %1$s is loading MCP Adapter %2$s, but Oversio Agent Abilities requires %3$s or newer. Update or deactivate %1$s to enable agent tools.', 'oversio-agent-abilities' ),
			esc_html( $plugin ),
			esc_html( $loaded ),
			esc_html( OVERSIO_MIN_ADAPTER_VERSION )
		);
	} else {
		printf(
			/* translators: 1: loaded adapter version, 2: minimum required adapter version. */
			esc_html__( 'Oversio Agent Abilities is disabled: another active plugin is loading MCP Adapter %1$s, but %2$s or newer is required. Update or deactivate that plugin to enable agent tools.', 'oversio-agent-abilities' ),
			esc_html( $loaded ),
			esc_html( OVERSIO_MIN_ADAPTER_VERSION )
		);
	}
	echo '</p></div>';
}

/**
 * Admin notice: another plugin loaded an adapter NEWER than our tested upper bound.
 *
 * A 0.6+ adapter may have changed the create_server() signature or response shape the plugin is
 * built against, so it is disabled rather than risking a runtime break. Names the offending plugin
 * when it can be resolved, and reports the loaded vs maximum-supported versions. All output escaped.
 *
 * @return void
 */
function oversio_notice_adapter_too_new(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$loaded = oversio_loaded_adapter_version() ?? __( 'unknown', 'oversio-agent-abilities' );
	$plugin = oversio_resolve_adapter_owner_plugin();

	echo '<div class="notice notice-warning"><p>';
	if ( '' !== $plugin ) {
		printf(
			/* translators: 1: offending plugin name, 2: loaded adapter version, 3: maximum supported adapter version (exclusive). */
			esc_html__( 'Oversio Agent Abilities is disabled: the plugin %1$s is loading MCP Adapter %2$s, which is newer than this plugin supports (below %3$s). Update Oversio Agent Abilities, or deactivate %1$s, to enable agent tools.', 'oversio-agent-abilities' ),
			esc_html( $plugin ),
			esc_html( $loaded ),
			esc_html( OVERSIO_MAX_ADAPTER_VERSION )
		);
	} else {
		printf(
			/* translators: 1: loaded adapter version, 2: maximum supported adapter version (exclusive). */
			esc_html__( 'Oversio Agent Abilities is disabled: another active plugin is loading MCP Adapter %1$s, which is newer than this plugin supports (below %2$s). Update Oversio Agent Abilities, or deactivate that plugin, to enable agent tools.', 'oversio-agent-abilities' ),
			esc_html( $loaded ),
			esc_html( OVERSIO_MAX_ADAPTER_VERSION )
		);
	}
	echo '</p></div>';
}

/**
 * Resolve the display name of the plugin whose copy of the MCP adapter won the autoload.
 *
 * Reflects the loaded WP\MCP\Core\McpAdapter class file path, finds which plugin folder under
 * WP_PLUGIN_DIR contains it, then reads that plugin's header name via get_plugins(). Returns an
 * empty string when the class is absent, the file is outside the plugins directory, or no plugin
 * header can be matched — the caller then uses the generic wording.
 *
 * @return string Plugin display name, or '' when it cannot be resolved.
 */
function oversio_resolve_adapter_owner_plugin(): string {
	if ( ! class_exists( McpAdapter::class ) ) {
		return '';
	}

	try {
		$file = ( new ReflectionClass( McpAdapter::class ) )->getFileName();
	} catch ( \ReflectionException $e ) {
		return '';
	}
	if ( ! is_string( $file ) || '' === $file ) {
		return '';
	}

	$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
	$class_file  = wp_normalize_path( $file );

	// The class must live under the plugins directory for us to name a plugin.
	if ( 0 !== strpos( $class_file, trailingslashit( $plugins_dir ) ) ) {
		return '';
	}

	// The first path segment after the plugins dir is the owning plugin's folder.
	$relative = ltrim( substr( $class_file, strlen( $plugins_dir ) ), '/' );
	$segments = explode( '/', $relative );
	$folder   = $segments[0] ?? '';
	if ( '' === $folder ) {
		return '';
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all_plugins = get_plugins();
	foreach ( $all_plugins as $plugin_file => $data ) {
		// $plugin_file is "folder/entry.php"; match on the folder segment.
		if ( 0 === strpos( $plugin_file, $folder . '/' ) && ! empty( $data['Name'] ) ) {
			return (string) $data['Name'];
		}
	}

	return '';
}
