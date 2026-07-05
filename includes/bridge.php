<?php
/**
 * Abilities bridge engine: discover WordPress Abilities registered by OTHER plugins and
 * register a governed aafm-bridge/<slug> wrapper for each one the operator opts into.
 *
 * The wrapper is registered through aafm_register_ability_with_log() so it inherits the full
 * governance envelope (audit start/outcome rows, per-principal rate limit, denial auditing).
 * Permission and execute both delegate to the LIVE foreign ability, re-resolved at call time.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a foreign ability's JSON schema so empty object-containers serialize as {} not [].
 *
 * A foreign get_input_schema() may be empty or use PHP arrays that json_encode as [] where JSON
 * Schema requires {} (objects). A tool whose inputSchema.properties serializes as [] is rejected
 * by strict MCP clients, so any empty associative object-container is coerced to stdClass.
 *
 * @param mixed $schema Raw schema (array) or empty.
 * @return array<string,mixed>
 */
function aafm_normalize_json_schema( $schema ): array {
	if ( ! is_array( $schema ) || array() === $schema ) {
		return array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);
	}
	if ( empty( $schema['type'] ) ) {
		$schema['type'] = 'object';
	}
	if ( 'object' === $schema['type'] && ! array_key_exists( 'properties', $schema ) ) {
		$schema['properties'] = new stdClass();
	}
	return aafm_normalize_schema_node( $schema );
}

/**
 * Recursively coerce empty associative object-containers to stdClass.
 *
 * Walks the object-keyed schema containers (properties, patternProperties, $defs, definitions),
 * the single-subschema keys (items, additionalProperties), and the composite arrays
 * (allOf/anyOf/oneOf). An empty object-container becomes stdClass so it emits {}; non-empty
 * containers and genuine list schemas are left untouched.
 *
 * @param mixed $node Schema node.
 * @return mixed
 */
function aafm_normalize_schema_node( $node ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	$object_keyed = array( 'properties', 'patternProperties', '$defs', 'definitions' );
	foreach ( $object_keyed as $key ) {
		if ( array_key_exists( $key, $node ) ) {
			if ( is_array( $node[ $key ] ) && array() === $node[ $key ] ) {
				$node[ $key ] = new stdClass();
			} elseif ( is_array( $node[ $key ] ) ) {
				foreach ( $node[ $key ] as $prop => $sub ) {
					$node[ $key ][ $prop ] = aafm_normalize_schema_node( $sub );
				}
			}
		}
	}
	foreach ( array( 'items', 'additionalProperties' ) as $key ) {
		if ( array_key_exists( $key, $node ) && is_array( $node[ $key ] ) ) {
			$node[ $key ] = aafm_normalize_schema_node( $node[ $key ] );
		}
	}
	foreach ( array( 'allOf', 'anyOf', 'oneOf' ) as $key ) {
		if ( array_key_exists( $key, $node ) && is_array( $node[ $key ] ) ) {
			foreach ( $node[ $key ] as $i => $sub ) {
				$node[ $key ][ $i ] = aafm_normalize_schema_node( $sub );
			}
		}
	}
	return $node;
}

/**
 * Normalize a foreign slug to our wrapper ability name.
 *
 * Lowercase, every run of non-[a-z0-9] collapses to a single '-', trimmed. So
 * "Elementor/Get_Pages" becomes "aafm-bridge/elementor-get-pages". The distinct
 * aafm-bridge/ prefix keeps us clear of EasyMCP's wp_ability_ names.
 *
 * @param string $foreign_slug The foreign ability slug.
 * @return string
 */
function aafm_bridge_tool_name( string $foreign_slug ): string {
	$norm = strtolower( $foreign_slug );
	$norm = (string) preg_replace( '/[^a-z0-9]+/', '-', $norm );
	$norm = trim( $norm, '-' );
	return 'aafm-bridge/' . $norm;
}

/**
 * Classify a foreign ability's risk from its annotations.
 *
 * @param \WP_Ability $ability The foreign ability.
 * @return array{risk:string,readonly:bool,destructive:bool}
 */
function aafm_bridge_risk( $ability ): array {
	$ann = array();
	if ( method_exists( $ability, 'get_meta_item' ) ) {
		$ann = (array) ( $ability->get_meta_item( 'annotations' ) ?? array() );
	}
	$readonly    = ! empty( $ann['readonly'] );
	$destructive = ! empty( $ann['destructive'] );
	$risk        = $destructive ? 'destructive' : ( $readonly ? 'read' : 'write' );
	return array(
		'risk'        => $risk,
		'readonly'    => $readonly,
		'destructive' => $destructive,
	);
}

/**
 * The foreign ability's input schema, normalized. Empty when none is exposed.
 *
 * @param \WP_Ability $ability The foreign ability.
 * @return array<string,mixed>
 */
function aafm_bridge_input_schema( $ability ): array {
	$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : array();
	return aafm_normalize_json_schema( $schema );
}

/**
 * Discover foreign abilities grouped by namespace. Read-only - never registers or mutates.
 *
 * Bails to an empty array when the Abilities API is absent (WP < 6.9). Excludes our own
 * aafm namespace and our aafm-bridge wrappers, so we never bridge ourselves or double-list.
 *
 * @return array<string,array{label:string,abilities:array<int,array<string,mixed>>}>
 */
function aafm_discover_foreign_abilities(): array {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return array();
	}
	$groups = array();
	foreach ( wp_get_abilities() as $slug => $ability ) {
		$slug = (string) $slug;
		$pos  = strpos( $slug, '/' );
		$ns   = false !== $pos ? substr( $slug, 0, $pos ) : 'core';
		if ( 'aafm' === $ns || 'aafm-bridge' === $ns ) {
			continue;
		}
		$risk                         = aafm_bridge_risk( $ability );
		$groups[ $ns ]['label']       = $ns;
		$groups[ $ns ]['abilities'][] = array(
			'slug'         => $slug,
			'label'        => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $slug,
			'description'  => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'risk'         => $risk['risk'],
			'readonly'     => $risk['readonly'],
			'destructive'  => $risk['destructive'],
			'input_schema' => aafm_bridge_input_schema( $ability ),
			'tool_name'    => aafm_mcp_tool_name( aafm_bridge_tool_name( $slug ) ),
		);
	}
	ksort( $groups );
	foreach ( $groups as &$group ) {
		usort(
			$group['abilities'],
			static fn( array $a, array $b ): int => strcasecmp( (string) $a['label'], (string) $b['label'] )
		);
	}
	unset( $group );
	return $groups;
}
