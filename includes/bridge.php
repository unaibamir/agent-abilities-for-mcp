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
