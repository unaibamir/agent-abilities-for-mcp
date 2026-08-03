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
 * @param bool $include_native When true, append our own aafm/* registry as an extra group under
 *                             the "aafm" namespace. Default false - foreign discovery is the
 *                             exporter's primary job (the website already has the native inventory).
 * @return array<string,mixed>
 */
function aafm_build_catalog( bool $include_native = false ): array {
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

	if ( $include_native ) {
		$native = aafm_build_native_catalog_group();
		if ( ! empty( $native['abilities'] ) ) {
			$plugins[] = $native;
		}
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
 * Build the "aafm" native-abilities group for the catalog (the --include-native payload).
 *
 * Reads our own registry (aafm_get_abilities_registry()) and maps each row to the same ability
 * record shape as the foreign groups. risk drives readonly/destructive; the MCP tool name is the
 * plain aafm-<name> sanitization (native abilities are not bridged, so there is no aafm-bridge- prefix).
 *
 * @return array<string,mixed>
 */
function aafm_build_native_catalog_group(): array {
	$abilities = array();
	foreach ( aafm_get_abilities_registry() as $name => $entry ) {
		$name        = (string) $name;
		$risk        = (string) ( $entry['risk'] ?? 'write' );
		$abilities[] = array(
			'slug'                 => $name,
			'label'                => (string) ( $entry['label'] ?? $name ),
			'description'          => (string) ( $entry['description'] ?? '' ),
			'risk'                 => $risk,
			'readonly'             => 'read' === $risk,
			'destructive'          => 'destructive' === $risk,
			'input_schema_summary' => array(),
			'mcp_tool_name'        => aafm_mcp_tool_name( $name ),
		);
	}
	// usort() only became stable in PHP 8.0; a label collision (plausible - labels are
	// human-written, not unique) could otherwise reorder on this plugin's PHP 7.4 floor. Pair
	// each row with its original position and tie-break on it, so every PHP version reproduces
	// PHP 8's current stable order byte-for-byte.
	$paired = array_map(
		static function ( array $item, int $index ): array {
			return array( $item, $index );
		},
		$abilities,
		array_keys( $abilities )
	);
	usort(
		$paired,
		static function ( array $a, array $b ): int {
			$cmp = strcasecmp( (string) $a[0]['label'], (string) $b[0]['label'] );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			// Ties break toward the earlier ability (original order), matching PHP 8's stable
			// usort() so this plugin's PHP 7.4 floor and PHP 8.x produce identical output.
			return $a[1] <=> $b[1];
		}
	);
	$abilities = array_column( $paired, 0 );

	return array(
		'namespace'     => 'aafm',
		'label'         => __( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		'ability_count' => count( $abilities ),
		'abilities'     => $abilities,
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
