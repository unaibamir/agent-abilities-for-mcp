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
 * Whether the loaded adapter version meets our floor.
 *
 * @param string $loaded_version Version reported by the active adapter copy.
 * @return bool
 */
function aafm_adapter_is_compatible( string $loaded_version ): bool {
	return version_compare( $loaded_version, AAFM_MIN_ADAPTER_VERSION, '>=' );
}

/**
 * The version of the adapter actually loaded (whoever's copy won via Jetpack Autoloader).
 *
 * @return string|null
 */
function aafm_loaded_adapter_version(): ?string {
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
function aafm_init_mcp(): bool {
	$version = aafm_loaded_adapter_version();

	if ( null === $version ) {
		add_action( 'admin_notices', 'aafm_notice_adapter_missing' );
		return false;
	}
	if ( ! aafm_adapter_is_compatible( $version ) ) {
		add_action( 'admin_notices', 'aafm_notice_adapter_outdated' );
		return false;
	}

	// Only our governed server should exist.
	add_filter( 'mcp_adapter_create_default_server', '__return_false' );

	McpAdapter::instance();
	add_action( 'mcp_adapter_init', 'aafm_register_mcp_server' );

	return true;
}

/**
 * Admin notice: no adapter available.
 *
 * @return void
 */
function aafm_notice_adapter_missing(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agent Abilities for MCP could not load the MCP adapter. Please reinstall the plugin.', 'agent-abilities-for-mcp' );
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
function aafm_notice_adapter_outdated(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$loaded = aafm_loaded_adapter_version() ?? __( 'unknown', 'agent-abilities-for-mcp' );
	$plugin = aafm_resolve_adapter_owner_plugin();

	echo '<div class="notice notice-warning"><p>';
	if ( '' !== $plugin ) {
		printf(
			/* translators: 1: offending plugin name, 2: loaded adapter version, 3: minimum required adapter version. */
			esc_html__( 'Agent Abilities for MCP is disabled: the plugin %1$s is loading MCP Adapter %2$s, but Agent Abilities for MCP requires %3$s or newer. Update or deactivate %1$s to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $plugin ),
			esc_html( $loaded ),
			esc_html( AAFM_MIN_ADAPTER_VERSION )
		);
	} else {
		printf(
			/* translators: 1: loaded adapter version, 2: minimum required adapter version. */
			esc_html__( 'Agent Abilities for MCP is disabled: another active plugin is loading MCP Adapter %1$s, but %2$s or newer is required. Update or deactivate that plugin to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $loaded ),
			esc_html( AAFM_MIN_ADAPTER_VERSION )
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
function aafm_resolve_adapter_owner_plugin(): string {
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
