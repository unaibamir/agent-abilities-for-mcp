<?php
/**
 * Catalog exporter: turn the discovered foreign-abilities registry into a portable, versioned
 * aafm.catalog/v1 dataset that feeds the public abilities directory site.
 *
 * Read-only. No secrets, no site content - only ability metadata + schema field names.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Build the aafm.catalog/v1 dataset from discovered foreign abilities.
 *
 * @param bool $include_native Reserved for a future --include-native that appends our own aafm/*
 *                             registry; foreign discovery is the exporter's primary job.
 * @return array<string,mixed>
 */
function aafm_build_catalog( bool $include_native = false ): array {
	unset( $include_native );

	$plugins = array();
	foreach ( aafm_discover_foreign_abilities() as $ns => $group ) {
		$abilities = array();
		foreach ( $group['abilities'] as $ability ) {
			$props = array();
			if ( isset( $ability['input_schema']['properties'] ) && is_array( $ability['input_schema']['properties'] ) ) {
				$props = array_keys( $ability['input_schema']['properties'] );
			}
			$abilities[] = array(
				'slug'                 => (string) $ability['slug'],
				'label'                => (string) $ability['label'],
				'description'          => (string) $ability['description'],
				'risk'                 => (string) $ability['risk'],
				'readonly'             => (bool) $ability['readonly'],
				'destructive'          => (bool) $ability['destructive'],
				'input_schema_summary' => $props,
				'mcp_tool_name'        => (string) $ability['tool_name'],
			);
		}
		$plugins[] = array(
			'namespace'     => (string) $ns,
			'label'         => (string) $group['label'],
			'ability_count' => count( $abilities ),
			'abilities'     => $abilities,
		);
	}

	return array(
		'schema'    => 'aafm.catalog/v1',
		'generated' => gmdate( 'c' ),
		'site'      => array(
			'wp_version'     => get_bloginfo( 'version' ),
			'plugin_version' => defined( 'AAFM_VERSION' ) ? AAFM_VERSION : '',
		),
		'plugins'   => $plugins,
	);
}

/**
 * WP-CLI: `wp aafm catalog export [--pretty] [--include-native]`.
 *
 * Prints the aafm.catalog/v1 JSON to stdout. Guarded at the registration site by
 * defined( 'WP_CLI' ).
 *
 * @param array<int,string>    $args  Positional args (unused).
 * @param array<string,string> $assoc Associative flags.
 * @return void
 */
function aafm_cli_catalog_export( $args, $assoc ): void {
	unset( $args );
	$catalog = aafm_build_catalog( isset( $assoc['include-native'] ) );
	$flags   = isset( $assoc['pretty'] ) ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;
	WP_CLI::line( (string) wp_json_encode( $catalog, $flags ) );
}
