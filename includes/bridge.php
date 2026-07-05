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
	return aafm_normalize_schema_node( $schema, 0 );
}

/**
 * The maximum schema nesting depth the normalizer will recurse into.
 *
 * A foreign get_input_schema()/get_output_schema() is attacker-influenced: a self-referential or
 * pathologically deep schema would otherwise recurse unbounded and fatal on every discovery/admin
 * render. Real schemas are shallow, so 30 is far above any legitimate need and the cap doubles as
 * a cycle breaker (a reference loop terminates once it hits the depth).
 */
const AAFM_SCHEMA_MAX_DEPTH = 30;

/**
 * Whether an array has sequential integer keys starting at 0 (a list / tuple).
 *
 * A PHP 8.0-safe stand-in for array_is_list() (8.1+), used to tell tuple-form JSON Schema
 * `items` (a list of subschemas) from a single-subschema object.
 *
 * @param array<int|string,mixed> $arr Array to test.
 * @return bool
 */
function aafm_bridge_is_list( array $arr ): bool {
	$expected = 0;
	foreach ( $arr as $key => $unused ) {
		unset( $unused );
		if ( $key !== $expected ) {
			return false;
		}
		++$expected;
	}
	return true;
}

/**
 * Recursively coerce empty associative object-containers to stdClass.
 *
 * Walks the object-keyed schema containers (properties, patternProperties, $defs, definitions),
 * the single-subschema keys (items, additionalProperties), and the composite arrays
 * (allOf/anyOf/oneOf). An empty object-container becomes stdClass so it emits {}; non-empty
 * containers and genuine list schemas are left untouched.
 *
 * @param mixed $node  Schema node.
 * @param int   $depth Current recursion depth (0 at the root).
 * @return mixed
 */
function aafm_normalize_schema_node( $node, int $depth = 0 ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	// Fail closed past the depth cap: stop recursing and hand the node back untouched. This
	// bounds a pathologically deep foreign schema and terminates any cyclic reference.
	if ( $depth >= AAFM_SCHEMA_MAX_DEPTH ) {
		return $node;
	}
	$next         = $depth + 1;
	$object_keyed = array( 'properties', 'patternProperties', '$defs', 'definitions' );
	foreach ( $object_keyed as $key ) {
		if ( array_key_exists( $key, $node ) ) {
			if ( is_array( $node[ $key ] ) && array() === $node[ $key ] ) {
				$node[ $key ] = new stdClass();
			} elseif ( is_array( $node[ $key ] ) ) {
				foreach ( $node[ $key ] as $prop => $sub ) {
					$node[ $key ][ $prop ] = aafm_normalize_schema_node( $sub, $next );
				}
			}
		}
	}
	foreach ( array( 'items', 'additionalProperties' ) as $key ) {
		if ( ! array_key_exists( $key, $node ) || ! is_array( $node[ $key ] ) ) {
			continue; // A boolean additionalProperties (true/false) is left as-is.
		}
		// An empty array here is a schema container, not a list: emit {} so it stays a valid
		// (empty) schema. additionalProperties: [] in particular is invalid JSON Schema.
		if ( array() === $node[ $key ] ) {
			$node[ $key ] = new stdClass();
			continue;
		}
		// Tuple-form items (a list of subschemas, one per position) recurse per element.
		if ( 'items' === $key && aafm_bridge_is_list( $node[ $key ] ) ) {
			foreach ( $node[ $key ] as $i => $sub ) {
				$node[ $key ][ $i ] = aafm_normalize_schema_node( $sub, $next );
			}
			continue;
		}
		$node[ $key ] = aafm_normalize_schema_node( $node[ $key ], $next );
	}
	foreach ( array( 'allOf', 'anyOf', 'oneOf' ) as $key ) {
		if ( array_key_exists( $key, $node ) && is_array( $node[ $key ] ) ) {
			foreach ( $node[ $key ] as $i => $sub ) {
				$node[ $key ][ $i ] = aafm_normalize_schema_node( $sub, $next );
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
 * @return array{risk:string,readonly:bool,destructive:bool,idempotent:bool}
 */
function aafm_bridge_risk( $ability ): array {
	$ann = array();
	if ( method_exists( $ability, 'get_meta_item' ) ) {
		$ann = (array) ( $ability->get_meta_item( 'annotations' ) ?? array() );
	}
	$readonly    = ! empty( $ann['readonly'] );
	$destructive = ! empty( $ann['destructive'] );
	$idempotent  = ! empty( $ann['idempotent'] );
	$risk        = $destructive ? 'destructive' : ( $readonly ? 'read' : 'write' );
	return array(
		'risk'        => $risk,
		'readonly'    => $readonly,
		'destructive' => $destructive,
		'idempotent'  => $idempotent,
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
 * The foreign ability's output schema, normalized - or null when it exposes none.
 *
 * Returns null (not a default object schema) when the foreign ability has no output schema, so the
 * wrapper simply omits output_schema and inherits core's no-output-validation default. A default
 * {type:object, properties:{}} here would instead make core validate every execute result against
 * an empty stdClass container and fatal.
 *
 * @param \WP_Ability $ability The foreign ability.
 * @return array<string,mixed>|null
 */
function aafm_bridge_output_schema( $ability ): ?array {
	$schema = method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : array();
	if ( ! is_array( $schema ) || array() === $schema ) {
		return null;
	}
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

/**
 * The foreign slugs the operator has enabled for bridging (sanitized, de-duplicated).
 *
 * Reads option aafm_enabled_bridged_abilities, kept SEPARATE from aafm_enabled_abilities so a
 * foreign plugin deactivating can never corrupt the native enabled list.
 *
 * @return array<int,string>
 */
function aafm_get_enabled_bridged_abilities(): array {
	$stored = get_option( 'aafm_enabled_bridged_abilities', array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$clean = array();
	// Keep only non-empty strings. array_map('strval', ...) would FATAL on an object with no
	// __toString, so filter to strings first rather than coercing arbitrary values.
	foreach ( array_filter( $stored, 'is_string' ) as $slug ) {
		if ( '' === $slug || aafm_bridge_is_native_namespace( $slug ) ) {
			continue; // Never bridge our own aafm/* abilities or aafm-bridge/* wrappers.
		}
		$clean[] = $slug;
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Whether a slug lives in one of our own namespaces (aafm or aafm-bridge).
 *
 * Guards the enabled-bridged list and the registration walk so a polluted option can never
 * bridge one of our native abilities back onto itself as aafm-bridge/aafm-*.
 *
 * @param string $slug Ability slug, e.g. "woocommerce/list-products".
 * @return bool
 */
function aafm_bridge_is_native_namespace( string $slug ): bool {
	$pos = strpos( $slug, '/' );
	$ns  = false !== $pos ? substr( $slug, 0, $pos ) : '';
	return 'aafm' === $ns || 'aafm-bridge' === $ns;
}

/**
 * Record or read wrapper-name collisions from the last registration pass.
 *
 * Two distinct foreign slugs can normalize to the SAME aafm-bridge/<slug> wrapper (e.g.
 * "foo/bar-baz" and "foo/bar_baz" both become "aafm-bridge/foo-bar-baz"). Only the first
 * slug claims the wrapper; the loser is skipped and recorded here so the admin directory can
 * flag it inline instead of losing it silently. Passing an array replaces the store (the
 * registration pass writes its result once); calling with no argument reads it.
 *
 * @param array<string,array{wrapper:string,winner:string}>|null $collisions Map keyed by the
 *        losing foreign slug, or null to read.
 * @return array<string,array{wrapper:string,winner:string}>
 */
function aafm_bridge_collisions( ?array $collisions = null ): array {
	static $store = array();
	if ( null !== $collisions ) {
		$store = $collisions;
	}
	return $store;
}

/**
 * Register a governed wrapper for every enabled + currently-registered foreign ability.
 *
 * Runs on the abilities-init action, AFTER native registration. For each enabled foreign slug
 * whose ability is live, registers aafm-bridge/<slug> through aafm_register_ability_with_log()
 * so it inherits audit + rate-limit + gating. Permission and execute re-resolve the LIVE foreign
 * ability at call time (never a captured object), so a re-registered foreign ability is honored.
 * Idempotent: a wrapper already registered is skipped; an enabled-but-missing foreign slug (host
 * plugin inactive) is skipped silently.
 *
 * @return void
 */
function aafm_register_enabled_bridged_abilities(): void {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return;
	}
	$claimed    = array();
	$collisions = array();
	foreach ( aafm_get_enabled_bridged_abilities() as $foreign_slug ) {
		// Belt-and-suspenders: never register a wrapper for one of our own namespaces even if the
		// option was polluted past the accessor's guard.
		if ( aafm_bridge_is_native_namespace( $foreign_slug ) ) {
			continue;
		}
		$wrapper = aafm_bridge_tool_name( $foreign_slug );

		// A DIFFERENT foreign slug already mapped to this wrapper name this pass: the normalizer
		// collapsed both to the same slug. Skip the loser and record it so the admin can surface
		// the clash rather than losing it silently behind the idempotency guard below.
		if ( isset( $claimed[ $wrapper ] ) ) {
			$collisions[ $foreign_slug ] = array(
				'wrapper' => $wrapper,
				'winner'  => $claimed[ $wrapper ],
			);
			continue;
		}

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $wrapper ) ) {
			// Already registered (idempotent re-fire, or an earlier claimant). Remember the
			// owner so a later same-pass slug mapping here is still flagged as a collision.
			$claimed[ $wrapper ] = $foreign_slug;
			continue;
		}
		$foreign = wp_get_ability( $foreign_slug );
		if ( ! $foreign instanceof WP_Ability ) {
			continue; // Host plugin inactive / slug gone.
		}
		$claimed[ $wrapper ] = $foreign_slug;
		$risk                = aafm_bridge_risk( $foreign );

		$label = (string) $foreign->get_label();

		$args = array(
			'label'               => '' !== $label ? $label : $foreign_slug,
			'description'         => (string) $foreign->get_description() . ' (bridged: ' . $foreign_slug . ')',
			'category'            => $risk['readonly'] ? 'aafm-reads' : 'aafm-writes',
			'input_schema'        => aafm_bridge_input_schema( $foreign ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => $risk['readonly'],
					'destructive' => $risk['destructive'],
					'idempotent'  => $risk['idempotent'],
				),
			),
			'permission_callback' => static function ( $input = null ) use ( $foreign_slug ) {
				$live = wp_get_ability( $foreign_slug );
				if ( ! $live instanceof WP_Ability ) {
					return false;
				}
				return true === $live->check_permissions( is_array( $input ) ? $input : array() );
			},
			'execute_callback'    => static function ( $input = null ) use ( $foreign_slug ) {
				$live = wp_get_ability( $foreign_slug );
				if ( ! $live instanceof WP_Ability ) {
					return new WP_Error( 'aafm_bridge_missing', __( 'The bridged ability is no longer available.', 'agent-abilities-for-mcp' ) );
				}
				return $live->execute( is_array( $input ) ? $input : array() );
			},
		);

		// Copy the foreign output schema only when it actually exposes one (see helper).
		$output_schema = aafm_bridge_output_schema( $foreign );
		if ( null !== $output_schema ) {
			$args['output_schema'] = $output_schema;
		}

		aafm_register_ability_with_log( $wrapper, $args );
	}

	// Publish this pass's collisions so the admin directory can flag any losing slug.
	aafm_bridge_collisions( $collisions );
}
