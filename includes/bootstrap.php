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
 * @return void
 */
function aafm_notice_adapter_outdated(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	printf(
		/* translators: %s: minimum adapter version */
		esc_html__( 'Agent Abilities for MCP needs MCP Adapter %s or newer. Another active plugin is providing an older copy; update or deactivate it to enable agent tools.', 'agent-abilities-for-mcp' ),
		esc_html( AAFM_MIN_ADAPTER_VERSION )
	);
	echo '</p></div>';
}
