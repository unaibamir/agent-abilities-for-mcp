<?php
/**
 * Site Ops: cache layers the cache-status tool names and the purge tools do not reach.
 *
 * Every test is a symbol test; no vendor code runs and no purge is possible through these rows.
 * The three page cache plugins listed here have no adapter in this version, so a site that runs
 * one of them beside a supported plugin is told that a purge result does not cover it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The name the object cache drop-in gives itself, from its file header. The file is read, never
 * included. No connection state, host, prefix or constant value is returned.
 *
 * @return string At most 60 characters; '' when there is no drop-in or it names nothing.
 */
function aafm_site_cache_drop_in_name(): string {
	$file = WP_CONTENT_DIR . '/object-cache.php';
	if ( ! is_file( $file ) || ! is_readable( $file ) ) {
		return '';
	}
	$data = get_file_data( $file, array( 'name' => 'Plugin Name' ) );
	$name = aafm_sanitize_plain_text( isset( $data['name'] ) ? (string) $data['name'] : '' );
	return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
}

/**
 * The status-only rows of the cache-status tool.
 *
 * @return list<array{provider:string,label:string,kind:string}>
 */
function aafm_site_cache_status_only(): array {
	$rows = array();

	if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
		$rows[] = array(
			'provider' => 'autoptimize',
			'label'    => 'Autoptimize',
			'kind'     => 'asset_cache',
		);
	}

	if ( wp_using_ext_object_cache() ) {
		$name   = aafm_site_cache_drop_in_name();
		$rows[] = array(
			'provider' => 'object_cache',
			'label'    => '' !== $name ? $name : __( 'Object cache drop-in', 'agent-abilities-for-mcp' ),
			'kind'     => 'object_cache',
		);
	}

	$not_covered = array(
		'wp_rocket'        => array( 'WP_ROCKET_VERSION', 'WP Rocket' ),
		'flyingpress'      => array( 'FLYING_PRESS_VERSION', 'FlyingPress' ),
		'super_page_cache' => array( 'SWCFPC_PLUGIN_PATH', 'Super Page Cache' ),
	);
	foreach ( $not_covered as $provider => $plugin ) {
		if ( defined( $plugin[0] ) ) {
			$rows[] = array(
				'provider' => $provider,
				'label'    => $plugin[1],
				'kind'     => 'page_cache_not_covered',
			);
		}
	}

	if ( class_exists( 'WPMedia\MCP\OAuth\Bootstrap', false ) ) {
		$rows[] = array(
			'provider' => 'vendor_mcp_server',
			'label'    => 'WP Rocket MCP server',
			'kind'     => 'vendor_mcp_server',
		);
	}

	return $rows;
}
