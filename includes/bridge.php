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
 * Default a foreign ability's JSON schema to an object type, and otherwise leave it alone.
 *
 * A call's arguments are always a JSON object, so a foreign get_input_schema() that declares no
 * type is advertised as one rather than typeless. Nothing else about the schema is rewritten.
 *
 * This function used to coerce every empty JSON-Schema container to stdClass so it would serialize
 * as {} instead of []. That was removed in 1.6.1 because it could not work and was actively
 * dangerous. The vendored MCP adapter routes every schema through
 * SchemaTransformer::transform_to_object_schema(), whose normalize() pass recursively converts
 * every stdClass at every depth back to an array and unsets an empty root properties outright, so
 * the coercion never reached the wire. Meanwhile the same stdClass WAS handed to WordPress core,
 * which reads schema nodes with array syntax - a fatal Error on PHP 8 against a non-ArrayAccess
 * object, including inside isset(). Seven schema positions fataled inside
 * rest_validate_value_from_schema(), three of them on the shipped 1.6.0.
 *
 * The residual (a nested empty subschema still emitting [] on the wire) is real, but the adapter
 * produces it on the adapter's side of the boundary and it belongs upstream in
 * WordPress/mcp-adapter, not here.
 *
 * @param mixed $schema Raw schema (array) or empty.
 * @return array<string,mixed>
 */
function aafm_normalize_json_schema( $schema ): array {
	if ( ! is_array( $schema ) || array() === $schema ) {
		return array( 'type' => 'object' );
	}
	if ( empty( $schema['type'] ) ) {
		$schema['type'] = 'object';
	}
	return $schema;
}

/**
 * The maximum nesting depth a recursive walk over attacker-influenced data will descend into.
 *
 * Read by aafm_sanitize_schema_array() in includes/integrations.php, the recursive JSON-LD
 * sanitizer, which falls back to 32 when this file has not defined it. That input is
 * attacker-influenced: a self-referential or pathologically deep graph would otherwise recurse
 * unbounded and exhaust the stack. Real JSON-LD graphs are only a handful of levels deep, so 30 is
 * far above any legitimate need and the cap doubles as a cycle breaker (a reference loop
 * terminates once it hits the depth).
 */
const AAFM_SCHEMA_MAX_DEPTH = 30;

/**
 * The namespace every bridged wrapper is registered under.
 *
 * Single-sourced because this string appears in two different shapes and they have to stay in
 * step. Ability names use `aafm-bridge/<slug>`, but the MCP adapter rewrites the slash to a
 * hyphen (RegisterAbilityAsMcpTool, mirrored by aafm_mcp_tool_name()), so on the wire the same
 * ability is `aafm-bridge-<slug>`. Anything matching the wire form must derive it from here
 * rather than hardcode it: a prefix that stops matching does not raise an error, it just
 * silently stops firing, which is the failure mode that looks exactly like success.
 */
const AAFM_BRIDGE_NAMESPACE = 'aafm-bridge';

/**
 * Whether an array has sequential integer keys starting at 0 (a list / tuple).
 *
 * A stand-in for array_is_list() (8.1+) that also works on this plugin's PHP 7.4 floor. Its one
 * production caller is aafm_filter_bridged_tool_call_result() below, which uses it to decide
 * whether a bridged tool's result is a bare list that needs wrapping under `data`. (It used to
 * also discriminate tuple-form JSON Schema `items` for the schema-coercion layer; that layer was
 * deleted in 1.6.1.)
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
	return AAFM_BRIDGE_NAMESPACE . '/' . $norm;
}

/**
 * Classify a foreign ability's risk from its annotations.
 *
 * A foreign ability's annotations are OPTIONAL and self-declared, so an omission is not evidence
 * of safety. We fail safe: an ability that neither declares itself read-only nor explicitly
 * declares a destructive flag is treated as destructive, so the operator's "can delete data"
 * confirm still shows and MCP clients are told destructive=true. Only an explicit, trusted
 * annotation - readonly:true, or destructive:false on a write - downgrades it to non-destructive.
 * Every case where the ability DOES declare its annotations keeps its previous classification.
 *
 * @param \WP_Ability $ability The foreign ability.
 * @return array{risk:string,readonly:bool,destructive:bool,idempotent:bool}
 */
function aafm_bridge_risk( $ability ): array {
	$ann = array();
	if ( method_exists( $ability, 'get_meta_item' ) ) {
		$ann = (array) ( $ability->get_meta_item( 'annotations' ) ?? array() );
	}
	$readonly = ! empty( $ann['readonly'] );

	// The Abilities API always populates the 'destructive' annotation, defaulting it to null when the
	// ability declares nothing. So array_key_exists() can never see "absent" - null IS the unstated
	// case. Treat only an explicit boolean as trusted: an unstated (null) destructive on a non-readonly
	// ability fails safe to destructive, so the operator's "can delete data" confirm still shows and
	// MCP clients are told destructive=true. Only readonly:true or an explicit destructive:false
	// downgrades it.
	$declared    = $ann['destructive'] ?? null;
	$destructive = ( null === $declared )
		? ! $readonly
		: ! empty( $declared );

	$idempotent = ! empty( $ann['idempotent'] );
	$risk       = $destructive ? 'destructive' : ( $readonly ? 'read' : 'write' );
	return array(
		'risk'        => $risk,
		'readonly'    => $readonly,
		'destructive' => $destructive,
		'idempotent'  => $idempotent,
	);
}

/**
 * Strip JSON-Schema keywords an MCP client is not guaranteed to understand from a FOREIGN
 * ability's schema, before it is copied onto our wrapper's registration.
 *
 * Delegates to WP 7.1's wp_prepare_json_schema_for_client() (Rule 1 of the delegation audit: this
 * plugin has no way to know every keyword a third-party ability's author might have used, and core
 * already maintains the allow-listed keyword set its own REST API and Abilities API clients
 * expect). A no-op on the WP 6.9/7.0 floor, where the function does not exist yet. Only ever called
 * on a BRIDGED (third-party-authored) schema - a native aafm/* ability's own schema is authored by
 * this plugin and already kept to a known-safe keyword set, so running it through this too would
 * add a dependency for no benefit.
 *
 * @param array<string,mixed> $schema A normalized (type always present) JSON schema.
 * @return array<string,mixed> The same schema, with unsupported keywords stripped where core
 *                              provides the stripper; unchanged otherwise.
 */
function aafm_prepare_bridge_schema_for_client( array $schema ): array {
	if ( ! function_exists( 'wp_prepare_json_schema_for_client' ) ) {
		return $schema;
	}
	return wp_prepare_json_schema_for_client( $schema );
}

/**
 * The foreign ability's input schema, normalized. Empty when none is exposed.
 *
 * @param \WP_Ability $ability The foreign ability.
 * @return array<string,mixed>
 */
function aafm_bridge_input_schema( $ability ): array {
	$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : array();
	return aafm_prepare_bridge_schema_for_client( aafm_normalize_json_schema( $schema ) );
}

/**
 * Decide what input to forward to a bridged foreign ability.
 *
 * Our wrapper advertises a NORMALIZED schema (aafm_bridge_input_schema stamps type:object onto a
 * foreign ability that declares none), so the adapter always hands our wrapper an array - even when
 * the foreign ability itself declares no schema, or a non-object schema. Forwarding that array
 * verbatim to the foreign ability breaks two shapes:
 *
 *  - No input schema: core's WP_Ability::validate_input() accepts ONLY null for an empty schema
 *    (returns ability_missing_input_schema for anything else), so passing array() fails 100% of
 *    calls. Two of WordPress core's own abilities (core/get-user-info, core/get-environment-info)
 *    declare no schema, so this needs no third-party plugin to hit.
 *  - Non-object schema (e.g. {type:string}): the caller's scalar argument must reach the foreign
 *    validator unchanged; substituting array() silently discards it.
 *
 * @param mixed $live  The resolved foreign ability (WP_Ability, loosely typed for PHP 7.4).
 * @param mixed $input Input handed to our wrapper.
 * @return mixed The value to pass to the foreign ability's execute()/check_permissions().
 */
function aafm_bridge_forward_input( $live, $input ) {
	$schema = $live instanceof WP_Ability ? $live->get_input_schema() : array();

	if ( array() === $schema ) {
		return null; // Empty foreign schema: core's validate_input() accepts only null.
	}

	$type = isset( $schema['type'] ) ? $schema['type'] : '';
	if ( 'object' === $type || '' === $type || isset( $schema['properties'] ) ) {
		return is_array( $input ) ? $input : array();
	}

	// Non-object foreign schema: forward the caller's value unchanged.
	return $input;
}

/**
 * The foreign ability's output schema exactly as it declared it - or null when it exposes none.
 *
 * Deliberately NOT routed through aafm_normalize_json_schema(). That function is INPUT-oriented:
 * a call's arguments are always a JSON object, so it defaults a typeless schema to type:object. An
 * output schema carries no such guarantee, because a foreign ability may legally declare a bare
 * {description:'...'} or a oneOf/const schema and return a scalar. Stamping type:object onto it
 * made OUR OWN wrapper's execute() reject the foreign ability's own result with
 * ability_invalid_output (WP_Ability::execute() validates every result against output_schema),
 * even though the same ability called directly succeeded against its real, typeless schema.
 *
 * Returns null (not a default object schema) when the foreign ability has no output schema, so the
 * wrapper simply omits output_schema and inherits core's no-output-validation default.
 *
 * One caveat worth naming rather than leaving as a silent side effect: this is about our wrapper's
 * own validate_output() call, not about what a client sees.
 * SchemaTransformer::transform_to_object_schema() stamps type:object onto a typeless schema itself
 * when building the advertised outputSchema, and McpTool::execute() wraps a scalar result under
 * `result`. So for a bare oneOf schema returning a string the bridged call now EXECUTES instead of
 * erroring, but the advertised schema still ends up {oneOf:[...], type:'object'} against a
 * {"result":"..."} body, which does not validate either. That is an adapter-side shape.
 *
 * @param \WP_Ability $ability The foreign ability.
 * @return array<string,mixed>|null
 */
function aafm_bridge_output_schema( $ability ): ?array {
	$schema = method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : array();
	if ( ! is_array( $schema ) || array() === $schema ) {
		return null;
	}
	return aafm_prepare_bridge_schema_for_client( $schema );
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
	// WP 7.1: wp_get_abilities() now runs through two new global filters
	// (wp_get_abilities_item_include, wp_get_abilities_result) even on a zero-arg call, so a
	// third-party filter could hide an ability from this admin governance screen. Read the
	// registry directly instead: WP_Abilities_Registry::get_all_registered() is public since this
	// plugin's WP 6.9.0 floor and bypasses both filters, giving the site owner ground truth about
	// what is actually registered rather than whatever a rogue plugin lets through. Investigated
	// (delegation audit, WP 7.1 findings): the new $args filter on wp_get_abilities() cannot
	// express "every namespace except aafm and aafm-bridge", so it is not a substitute for this
	// function's own exclusion loop below - this is a bypass of the filtering layer, not a
	// delegation to it.
	//
	// Deliberate departure from core's documented contract (delegation audit, review round 1,
	// 2026-08-26): core documents wp_get_abilities_item_include as enforcing "universal inclusion
	// rules" that every caller is expected to respect, and get_all_registered() skips it entirely.
	// The cost: a vendor that hides an internal, deprecated, license-gated or feature-flagged
	// ability from wp_get_abilities() on purpose will see it listed here anyway, and an operator
	// could select it in the bridge directory even though the vendor never intended it to be
	// offered. Accepted because this is an ADMIN-facing governance screen, not a public listing -
	// the operator is deciding what to enable on their own site, and should be able to see what is
	// genuinely registered rather than whatever a third party's filter lets through. Execution still
	// reaches the foreign ability's own permission_callback regardless of what this function
	// displays, so the bypass affects visibility only, never authorization.
	if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
		return array();
	}
	$registry = \WP_Abilities_Registry::get_instance();
	if ( null === $registry ) {
		return array();
	}
	$groups = array();
	foreach ( $registry->get_all_registered() as $slug => $ability ) {
		$slug = (string) $slug;
		$pos  = strpos( $slug, '/' );
		$ns   = false !== $pos ? substr( $slug, 0, $pos ) : 'core';
		if ( 'aafm' === $ns || AAFM_BRIDGE_NAMESPACE === $ns ) {
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
		// usort() only became stable in PHP 8.0. Duplicate labels are especially plausible here -
		// these come from foreign plugins we do not control - so a tie could reorder on this
		// plugin's PHP 7.4 floor. Pair each row with its original position and tie-break on it,
		// so every PHP version reproduces PHP 8's current stable order byte-for-byte.
		$paired = array_map(
			static function ( array $item, int $index ): array {
				return array( $item, $index );
			},
			$group['abilities'],
			array_keys( $group['abilities'] )
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
		$group['abilities'] = array_column( $paired, 0 );
	}
	unset( $group );
	return $groups;
}

/**
 * The foreign slugs stored in the operator's bridge allow-list (sanitized, de-duplicated).
 *
 * Reads option aafm_enabled_bridged_abilities, kept SEPARATE from aafm_enabled_abilities so a
 * foreign plugin deactivating can never corrupt the native enabled list. This is the RAW list: no
 * floor is applied, so it is what the admin screen renders its switches from and what a save must
 * carry forward. Anything deciding what actually registers wants
 * aafm_get_enabled_bridged_abilities() instead.
 *
 * @return array<int,string>
 */
function aafm_get_stored_bridged_abilities_raw(): array {
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
 * The foreign slugs that may actually be bridged right now.
 *
 * The stored list with the read-only floor applied. Read-only mode covers bridged abilities the
 * same way it covers native ones, classified by the foreign ability's own annotations through
 * aafm_bridge_risk() - the same annotation this plugin already trusts to decide the operator's
 * "can delete data" confirm and what it reports to MCP clients, so filtering on it here is not a
 * new trust assumption.
 *
 * Fails closed: a slug whose ability is not resolvable right now (host plugin inactive) is not a
 * read, and neither is one that declares nothing, since aafm_bridge_risk() already treats an
 * unannotated ability as destructive.
 *
 * The stored option is never rewritten by this, so turning the mode off restores exactly what was
 * enabled before.
 *
 * @return array<int,string>
 */
function aafm_get_enabled_bridged_abilities(): array {
	$clean = aafm_get_stored_bridged_abilities_raw();

	if ( ! aafm_read_only_mode() ) {
		return $clean;
	}

	if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
		return array();
	}

	$reads = array();
	foreach ( $clean as $slug ) {
		// wp_has_ability() first: an enabled slug whose host plugin is currently inactive is an
		// ordinary state here, and wp_get_ability() would raise a _doing_it_wrong notice for it on
		// every request that asks what may register.
		if ( ! wp_has_ability( $slug ) ) {
			continue;
		}
		$ability = wp_get_ability( $slug );
		if ( ! $ability instanceof WP_Ability ) {
			continue;
		}
		if ( 'read' === aafm_bridge_risk( $ability )['risk'] ) {
			$reads[] = $slug;
		}
	}

	return $reads;
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
	return 'aafm' === $ns || AAFM_BRIDGE_NAMESPACE === $ns;
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
		// wp_has_ability() first: an enabled slug whose host plugin is currently inactive is an
		// ordinary state here, and wp_get_ability() would raise a _doing_it_wrong notice for it on
		// every request when read-only mode is off. aafm_get_enabled_bridged_abilities()'s
		// read-only-mode branch already carries this exact guard; this is the same fix for the
		// registration walk, which reaches wp_get_ability() on every enabled slug regardless of
		// read-only mode.
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $foreign_slug ) ) {
			continue;
		}
		$foreign = wp_get_ability( $foreign_slug );
		if ( ! $foreign instanceof WP_Ability ) {
			continue; // Belt-and-suspenders: still guard the return type.
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
				// check_permissions() runs the FOREIGN plugin's own callback, which can throw.
				// Deliberately no try/catch here: every caller guards it already - the decorated
				// closure in aafm_register_ability_with_log() on the tools/call path, and
				// aafm_user_can_call_ability() on the tools/list path, which reaches this same
				// closure raw and undecorated. aafm_user_can_discover_ability() is the third, and it
				// carries its own guard over the branch that never reaches
				// aafm_user_can_call_ability(). A catch here would be the per-site drift the
				// choke-point design exists to avoid. If a NEW caller ever invokes this closure
				// directly, it must carry its own guard.
				return true === $live->check_permissions( aafm_bridge_forward_input( $live, $input ) );
			},
			'execute_callback'    => static function ( $input = null ) use ( $foreign_slug ) {
				$live = wp_get_ability( $foreign_slug );
				if ( ! $live instanceof WP_Ability ) {
					return new WP_Error( 'aafm_bridge_missing', __( 'The bridged ability is no longer available.', 'agent-abilities-for-mcp' ) );
				}
				// Our OWN abilities are contracted to return array|WP_Error, and
				// aafm_register_ability_with_log() enforces that at runtime for native names
				// only (see the malformed-result guard in register.php). A bridged ability is
				// someone else's code: core documents WP_Ability::execute() as returning
				// mixed|WP_Error, so a foreign ability legitimately returning e.g. true after a
				// successful write is not a contract violation. An earlier revision of this
				// closure wrapped that scalar into array( 'data' => $out ) so the guard would
				// only ever see a shape legal under our own contract - but the output_schema
				// copied from the foreign ability onto our wrapper's registration below still
				// describes the UNWRAPPED value, so core's own output validation then rejected
				// the wrapped array against that schema, turning a real success into a spurious
				// ability_invalid_output error. Returning the foreign result unchanged avoids
				// that collision; the guard downstream is scoped to skip bridged names instead.
				return $live->execute( aafm_bridge_forward_input( $live, $input ) );
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

/**
 * Whether $value contains, at $value itself or nested at any depth inside it, an object that is
 * not an exact empty stdClass.
 *
 * Final gate round 2: the ORIGINAL top-level-only check inspected the wrong layer. On the real
 * MCP wire, McpTool::execute() already wraps any non-array bridged result as
 * array('result' => $value) BEFORE mcp_adapter_tool_call_result (this file's filter) ever runs
 * (vendor/wordpress/mcp-adapter/includes/Domain/Tools/McpTool.php:295-299,
 * ToolsHandler.php:189/205) - so a bare object at the FUNCTION's top level is a shape the real
 * adapter never delivers; a hidden object one or more levels inside an array is exactly what it
 * delivers instead. This walks the whole structure so the guard fires where the danger actually
 * is, not only where the old (unreachable in production) shape assumed it would be.
 *
 * The house idiom (object) array() can legitimately appear at ANY depth, not only the root - a
 * bridged ability might return {"items": [...], "meta": {}} where the empty map sits one level
 * down. So the exemption for an exact, empty stdClass (get_class() === 'stdClass', not
 * `instanceof`, which a subclass also satisfies - see the fix round 1 note this replaces) applies
 * at whatever depth it is found, and nothing else does: any other object, at any depth, is
 * refused.
 *
 * Recursion is depth-bounded, deliberately, using the same AAFM_SCHEMA_MAX_DEPTH bound
 * aafm_sanitize_schema_array() uses for the identical reason: a bridged result is foreign data of
 * unknown shape, and an unbounded walk risks exhausting the stack on a pathologically deep or (via
 * a leaked reference) self-referential array. Real vendor response shapes are only a handful of
 * levels deep, so the bound never clips legitimate output. Unlike the schema sanitizer, which
 * drops a sub-tree past its bound (safe, because dropping IS the sanitizing action), this function
 * REFUSES once the bound is hit rather than reporting "nothing found" - past the bound this
 * function no longer knows what is down there, and this guard's whole job is refusing what it
 * cannot vouch for, not assuming the unexamined remainder is safe.
 *
 * @param mixed $value Value to inspect (array, scalar, or object).
 * @param int   $depth Current recursion depth (internal; callers pass 0).
 * @return bool True if an unsafe object was found (or the depth bound was hit before ruling it out).
 */
function aafm_bridge_result_hides_an_object( $value, int $depth = 0 ): bool {
	if ( is_object( $value ) ) {
		return ! ( 'stdClass' === get_class( $value ) && array() === get_object_vars( $value ) );
	}
	if ( ! is_array( $value ) ) {
		return false;
	}
	if ( $depth >= AAFM_SCHEMA_MAX_DEPTH ) {
		return true; // Past the bound: cannot vouch for what is here, so refuse rather than assume.
	}
	foreach ( $value as $item ) {
		if ( aafm_bridge_result_hides_an_object( $item, $depth + 1 ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Safety net for a bridged ability whose result is a bare top-level JSON list.
 *
 * When a foreign ability declares no output_schema, aafm_bridge_output_schema() returns null and
 * our wrapper's own registration omits output_schema too (see
 * aafm_register_enabled_bridged_abilities() above). Core's WP_Ability::execute() ->
 * validate_output() then short-circuits true on an empty schema without checking anything, so
 * whatever the foreign ability returns reaches structuredContent under our tool name unchecked.
 * If that is a bare top-level array, strict MCP clients reject the response - upstream issue
 * WordPress/mcp-adapter#253, reproduced there against real WooCommerce REST list/report
 * endpoints, which we bridge. This hooks the adapter's mcp_adapter_tool_call_result filter
 * (fired after $mcp_tool->execute() in ToolsHandler::handle_tool_call(), since 0.5.0) and wraps
 * a bare list under a `data` key so the wire shape is always an object.
 *
 * The wrap is UNCONDITIONAL for every bare list, whether or not the foreign ability declared an
 * output_schema. An earlier revision of this function tried to skip the wrap whenever a schema
 * was declared, reasoning that the adapter advertises {type:object, properties:{result:<schema>},
 * required:['result']} for a non-object-root schema (SchemaTransformer::
 * transform_to_object_schema()) and that a {"data":[...]} body would then satisfy neither side.
 * That reasoning does not survive contact with the adapter's actual call order: for a DECLARED
 * non-object-root schema, McpTool::execute() (`wrap_output_if_needed()`,
 * vendor/wordpress/mcp-adapter/includes/Domain/Tools/McpTool.php:329-341) already wraps the value
 * under `result` BEFORE this filter ever runs (ToolsHandler.php:189/:205), so by the time we see
 * it the result is string-keyed - aafm_bridge_is_list() is false, and the guard below returns
 * early without ever reaching the schema question. The schema-aware gate this docblock used to
 * describe was therefore dead code for the one case it existed to protect, and where it WAS
 * reachable (a foreign ability declaring {type:object} but still returning a bare PHP list - WP
 * core's rest_is_object() accepts any array, so validate_output() lets it through) it made things
 * worse: it left a bare `[...]` in structuredContent against an advertised object schema, where
 * the unconditional wrap at least produces a valid JSON object.
 *
 * Known, deliberately unfixed limitation (plan finding M2): a foreign ability that declares
 * {type:object, additionalProperties:false} and returns array() for "nothing found" still
 * receives {"data":[]} from this filter, which its own schema forbids. The only alternative is to
 * leave that one case as a bare [], which is a top-level JSON array and violates the MCP spec for
 * EVERY consumer, not just that one foreign ability's self-declared schema. Contradicting one
 * foreign ability's schema is strictly better than shipping a spec-invalid response to every
 * client, so this is intentionally not special-cased. Do not reintroduce the array()===$result
 * exclusion to "fix" this - it previously ran before any schema check at all, which meant a
 * bridged NO-SCHEMA tool returning "nothing found" reached the wire as a bare [] instead of
 * {"data":[]}, regressing exactly the class of bug this filter exists to close.
 *
 * Scoped to aafm-bridge-* tool names only, not applied to our own aafm-* results. Every native
 * ability's result is already asserted object-shaped by tests/WireShapeTest.php against the
 * exact same execute() call this filter would otherwise see, so a native list-shape defect is a
 * bug to fix at the source, not to paper over here. A blanket wrap would also produce a
 * `{data: [...]}` shape that matches neither a native ability's documented output_schema nor a
 * hypothetical buggy shape, masking the defect instead of surfacing it.
 *
 * Uses aafm_bridge_is_list() (defined above in this file), a hand-rolled sequential-key check
 * that never calls PHP 8.1's array_is_list() - this plugin's floor is PHP 7.4 - so no version
 * branching is needed here. Cannot double-wrap: once wrapped, the result carries a string key
 * and is no longer a list, so a second pass is a no-op by construction.
 *
 * @param mixed $result    The raw tool execution result (may be WP_Error).
 * @param mixed $args      The tool arguments used (unused here).
 * @param mixed $tool_name The MCP tool name that was called.
 * @return mixed The result, wrapped under `data` for every bridged, bare-list result (including
 *               an empty one - see the M2 note above).
 */
function aafm_filter_bridged_tool_call_result( $result, $args, $tool_name ) {
	unset( $args );

	if ( ! is_string( $tool_name ) || ! str_starts_with( $tool_name, AAFM_BRIDGE_NAMESPACE . '-' ) ) {
		return $result;
	}

	// A genuine vendor failure is not a shape to inspect - pass it through so
	// ToolsHandler::handle_tool_call() turns it into a proper MCP error result.
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Nothing in this plugin or the adapter inspects an object's own properties before the wire, and
	// this plugin cannot know a third-party object's shape well enough to redact it selectively - so
	// it refuses the call outright rather than guess at what is safe to keep, exactly as the
	// adapter's own docblock on this filter recommends (PII redaction). aafm_bridge_result_hides_an_
	// object() (defined above) walks $result at every depth: on the real wire $result here is
	// typically already the adapter's own array('result' => $value) wrapper (or a plain array a
	// foreign ability returned directly), never a bare object at THIS function's top level - see
	// that function's docblock for the full wire-order finding this replaces.
	if ( aafm_bridge_result_hides_an_object( $result ) ) {
		return new WP_Error(
			'aafm_bridge_unsupported_result_shape',
			__( 'This bridged ability returned a raw object, which cannot be safely relayed over MCP. Contact the site administrator.', 'agent-abilities-for-mcp' )
		);
	}

	if ( ! is_array( $result ) || ! aafm_bridge_is_list( $result ) ) {
		return $result;
	}

	return array( 'data' => $result );
}
