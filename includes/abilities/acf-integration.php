<?php
/**
 * ACF / SCF integration abilities - hydrated custom-field reads and writes (slice W4-A).
 *
 * Registers ONLY when ACF (or its Secure Custom Fields fork) is active
 * (aafm_integration_active('acf')); a host-inactive site contributes zero entries to the
 * registry. Field VALUES are read and written through ACF's own get_fields()/get_field()/
 * update_field() so a field's Return Format and storage are honoured. Every per-object ability
 * gates on the object's own edit capability: post fields on edit_post($id), term fields on
 * edit_term($term_id), user fields on edit_user($user_id). User fields may include a
 * user_email-type field; that PII is returned as-is under the disclaimer - the edit_user gate,
 * default-OFF, and audit are the governance, NOT a redactor (mirrors the Wave-2 "user email
 * exposed by default" locked decision).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_acf_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_acf_full_definitions' );

/**
 * Contribute the ACF definitions to the registry, but only when the ACF host plugin is active.
 * Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_acf_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'acf' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_acf_registry_definitions() );
}

/**
 * Contribute the ACF definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view enumerates every ACF ability even when the host is inactive,
 * for the Integrations tab and the manifest. The live registration path never reads this filter, so
 * an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_acf_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_acf_registry_definitions() );
}

/**
 * The ACF registry rows, keyed by ability name. The single source of truth for these abilities'
 * label, description, group, risk, and args builder - consumed by both the host-guarded live
 * registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_acf_registry_definitions(): array {
	return array(
		'aafm/acf-list-field-groups'  => array(
			'label'        => __( 'List ACF field groups', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the ACF field groups and the fields inside each (key, label, and type) for discovery. It returns structure only, never stored values. Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_list_field_groups',
		),
		'aafm/acf-get-post-fields'    => array(
			'label'        => __( 'Get post ACF fields', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads all of a post's ACF field values as a hydrated map keyed by field name. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_get_post_fields',
		),
		'aafm/acf-update-post-fields' => array(
			'label'        => __( 'Update post ACF fields', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Writes ACF field values on a post by field key, each value sanitized for its field type. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_update_post_fields',
		),
		'aafm/acf-get-term-fields'    => array(
			'label'        => __( 'Get term ACF fields', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads all of a term's ACF field values as a hydrated map keyed by field name. Requires edit access to that term.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_get_term_fields',
		),
		'aafm/acf-update-term-fields' => array(
			'label'        => __( 'Update term ACF fields', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Writes ACF field values on a term by field key, each value sanitized for its field type. Requires edit access to that term.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_update_term_fields',
		),
		'aafm/acf-get-user-fields'    => array(
			'label'        => __( 'Get user ACF fields', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads all of a user's ACF field values as a hydrated map keyed by field name. A field of the user_email type returns the real email address under the integration disclaimer. Requires edit access to that user.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_get_user_fields',
		),
		'aafm/acf-update-user-fields' => array(
			'label'        => __( 'Update user ACF fields', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Writes ACF field values on a user by field key, each value sanitized for its field type. Requires edit access to that user.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'acf',
			'args_builder' => 'aafm_args_acf_update_user_fields',
		),
	);
}

/**
 * Object-independent floor for acf-list-field-groups: the caller can author posts at all. Field
 * groups are site structure, not per-object data, so the edit_posts floor is the gate.
 *
 * @return bool
 */
function aafm_perm_acf_list_field_groups(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Args for aafm/acf-list-field-groups.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_list_field_groups(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-list-field-groups' ),
		'description'         => aafm_ability_description( 'aafm/acf-list-field-groups' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'field_groups' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'    => array( 'type' => 'string' ),
							'title'  => array( 'type' => 'string' ),
							'fields' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'key'   => array( 'type' => 'string' ),
										'label' => array( 'type' => 'string' ),
										'type'  => array( 'type' => 'string' ),
									),
									'required'             => array( 'key', 'label', 'type' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'key', 'title', 'fields' ),
						'additionalProperties' => false,
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_list_field_groups',
		'permission_callback' => 'aafm_perm_acf_list_field_groups',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/acf-list-field-groups.
 *
 * Walks every field group and its fields, returning only the discovery shape (key, label, type) -
 * never a stored value. Guards each ACF call with function_exists so the ability never fatals if
 * the host API shape changes.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_acf_list_field_groups( array $input ) {
	unset( $input );
	$out = array( 'field_groups' => array() );

	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return $out;
	}

	$groups = (array) acf_get_field_groups();
	foreach ( $groups as $group ) {
		$group     = (array) $group;
		$group_key = (string) ( $group['key'] ?? '' );
		$fields    = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $group ) : array();

		$field_shapes = array();
		foreach ( $fields as $field ) {
			$field          = (array) $field;
			$field_shapes[] = array(
				'key'   => (string) ( $field['key'] ?? '' ),
				'label' => (string) ( $field['label'] ?? '' ),
				'type'  => (string) ( $field['type'] ?? '' ),
			);
		}

		$out['field_groups'][] = array(
			'key'    => $group_key,
			'title'  => (string) ( $group['title'] ?? '' ),
			'fields' => $field_shapes,
		);
	}

	return $out;
}

/**
 * Read every hydrated ACF value for an object selector, keyed by field key.
 *
 * Uses ACF's get_fields() so each value honours its field's Return Format. An object with no ACF
 * data yields an empty map (get_fields returns false/empty). PII is returned as-is - the per-object
 * edit gate is the governance.
 *
 * @param int|string $selector ACF object selector (post id, "term_{id}", "user_{id}").
 * @return array<string,mixed>
 */
function aafm_acf_read_fields( $selector ): array {
	if ( ! function_exists( 'get_fields' ) ) {
		return array();
	}
	$values = get_fields( $selector );
	return is_array( $values ) ? $values : array();
}

/**
 * The ACF field types whose value is a URL and so must be sanitized with esc_url_raw (which drops
 * a javascript: scheme) rather than the plain-text helper.
 *
 * @return string[]
 */
function aafm_acf_url_field_types(): array {
	return array( 'url', 'link', 'file', 'image', 'oembed' );
}

/**
 * The ACF field types whose value is rich HTML and so are sanitized with wp_kses_post rather than
 * stripped flat by the plain-text helper.
 *
 * @return string[]
 */
function aafm_acf_wysiwyg_field_types(): array {
	return array( 'wysiwyg', 'textarea' );
}

/**
 * The keys inside a structured URL-typed field value (link/image/file array return formats) whose
 * leaf is itself a URL and so must keep esc_url_raw. Every OTHER key in that array (title, target,
 * alt, caption, filename, …) is plain text and is sanitized as text, NOT run through esc_url_raw.
 *
 * @return string[]
 */
function aafm_acf_url_leaf_keys(): array {
	return array( 'url', 'src' );
}

/**
 * The ACF container field types whose value is a list of rows / a map keyed by sub-field name.
 *
 * Clone belongs here: acf_get_field() populates a clone def's `sub_fields` from the cloned fields
 * (class-acf-field-clone.php load_field), and its value is a flat sub-field map exactly like a
 * group's - leaving it out meant every clone leaf was sanitized blind as plain text.
 *
 * @return string[]
 */
function aafm_acf_container_field_types(): array {
	return array( 'repeater', 'group', 'flexible_content', 'clone' );
}

/**
 * The container types whose value is ONE flat sub-field map (no numeric row indices): group and
 * clone. Repeater and flexible-content values are numeric-indexed lists of such maps instead.
 *
 * @return string[]
 */
function aafm_acf_flat_container_field_types(): array {
	return array( 'group', 'clone' );
}

/**
 * Resolve a sub-field's ACF definition by its name within a parent field definition.
 *
 * ACF repeater/group/clone/flexible-content values are keyed by the sub-field NAME, so a nested
 * leaf's own type is found by matching that key against the parent def's sub-field definitions.
 * Where those live depends on the container: repeater/group/clone carry a top-level `sub_fields`,
 * but a flexible-content def nests them per layout under `layouts[*]['sub_fields']` - each row
 * names its layout via `acf_fc_layout`, and only THAT layout's sub-fields define the row. Pass the
 * row's layout name so the lookup descends into the right layout; with no layout name every
 * layout's sub-fields are searched as a fallback for a row missing its marker. A clone sub-field
 * also matches on `_name` (its pre-prefix name), which ACF itself accepts on write. Returns null
 * when nothing matches (e.g. a free-form nested array).
 *
 * @param array<string,mixed> $parent_def The parent field definition.
 * @param string              $name       The nested key (a sub-field name).
 * @param string              $layout     The row's `acf_fc_layout` layout name, when known.
 * @return array<string,mixed>|null The sub-field definition, or null when not found.
 */
function aafm_acf_sub_field_def( array $parent_def, string $name, string $layout = '' ): ?array {
	$sub_fields = isset( $parent_def['sub_fields'] ) && is_array( $parent_def['sub_fields'] ) ? $parent_def['sub_fields'] : array();
	if ( isset( $parent_def['layouts'] ) && is_array( $parent_def['layouts'] ) ) {
		foreach ( $parent_def['layouts'] as $candidate ) {
			if ( ! is_array( $candidate ) || ! isset( $candidate['sub_fields'] ) || ! is_array( $candidate['sub_fields'] ) ) {
				continue;
			}
			if ( '' !== $layout && (string) ( $candidate['name'] ?? '' ) !== $layout ) {
				continue; // The row names its layout: only that layout's sub-fields apply.
			}
			$sub_fields = array_merge( $sub_fields, $candidate['sub_fields'] );
		}
	}
	foreach ( $sub_fields as $sub ) {
		if ( ! is_array( $sub ) ) {
			continue;
		}
		$sub_name  = isset( $sub['name'] ) ? (string) $sub['name'] : '';
		$sub_alias = isset( $sub['_name'] ) ? (string) $sub['_name'] : '';
		if ( $name === $sub_name || ( '' !== $sub_alias && $name === $sub_alias ) ) {
			return $sub;
		}
	}
	return null;
}

/**
 * Recursively sanitize one ACF field value for writing.
 *
 * Scalars are sanitized by the field's resolved type: a URL-type value through esc_url_raw, a
 * wysiwyg/textarea value through wp_kses_post, everything else through aafm_sanitize_plain_text
 * (sanitize_text_field plus the invisible-character strip, since these values are stored). Arrays
 * recurse, and crucially each level resolves its OWN field type rather than carrying the top-level
 * type down: a repeater/group/flexible-content sub-field's type comes from the parent def's
 * `sub_fields`, so a URL/link/image sub-field nested in a repeater is still esc_url_raw'd at depth
 * (a stored `javascript:` scheme can't survive in a repeater row). A URL-typed field whose value is
 * a structured ARRAY (link/image/file return formats) keeps the existing url/src-leaf handling so
 * its plain-text members (title, target, alt, caption, …) survive intact. Values that are neither
 * scalar nor array are dropped. Caller input is NEVER passed to update_field() unsanitized.
 *
 * @param mixed  $value     Raw caller value.
 * @param string $field_key The top-level field key (to resolve its type).
 * @return mixed Sanitized value.
 */
function aafm_acf_sanitize_value( $value, string $field_key ) {
	$def = array();
	if ( function_exists( 'acf_get_field' ) ) {
		$resolved = acf_get_field( $field_key );
		$def      = is_array( $resolved ) ? $resolved : array();
	}
	return aafm_acf_sanitize_leaf( $value, $def );
}

/**
 * The depth-recursing core of the ACF write sanitizer.
 *
 * @param mixed                    $value          Raw value at this depth.
 * @param array<string,mixed>|null $def            The ACF field definition for THIS value (resolves
 *                                                 type), or null when none applies (free-form key).
 * @param bool                     $in_url_struct  True when this value is a member of a URL-typed
 *                                                 field's structured array (link/image/file), so only
 *                                                 url/src-keyed leaves get esc_url_raw, the rest text.
 * @param string                   $key            The array key this leaf sits under (used only when
 *                                                 $in_url_struct is true).
 * @return mixed Sanitized value.
 */
function aafm_acf_sanitize_leaf( $value, ?array $def, bool $in_url_struct = false, string $key = '' ) {
	$type    = is_array( $def ) ? (string) ( $def['type'] ?? '' ) : '';
	$is_url  = in_array( $type, aafm_acf_url_field_types(), true );
	$is_cont = in_array( $type, aafm_acf_container_field_types(), true );

	if ( is_array( $value ) ) {
		$clean = array();
		// A flexible-content ROW names its layout via acf_fc_layout, and only THAT layout's
		// sub_fields define the row's leaves - the def resolver descends into it. At the flex
		// container level (a numeric-indexed list of rows) no marker exists and none is needed.
		$row_layout = isset( $value['acf_fc_layout'] ) && is_scalar( $value['acf_fc_layout'] ) ? (string) $value['acf_fc_layout'] : '';
		foreach ( $value as $sub_key => $sub ) {
			$safe_key = is_string( $sub_key ) ? aafm_sanitize_plain_text( $sub_key ) : $sub_key;
			if ( $is_cont && $def ) {
				// A repeater/group/clone/flexible-content level. Numeric keys are row indices that
				// keep the same container def; string keys are sub-field names whose own def
				// drives their sanitizing - so a URL sub-field is esc_url_raw'd at depth, and a
				// wysiwyg leaf inside a flex layout keeps wp_kses_post instead of being flattened.
				$child_def          = is_string( $sub_key ) ? aafm_acf_sub_field_def( $def, (string) $sub_key, $row_layout ) : $def;
				$clean[ $safe_key ] = aafm_acf_sanitize_leaf( $sub, $child_def, false, (string) $sub_key );
			} else {
				// A URL-typed field whose value is a structured array (link/image/file): recurse
				// with $in_url_struct so only the url/src leaf is esc_url_raw'd and the plain-text
				// members (title/target/alt/caption/…) survive. A free-form nested array (no def)
				// carries the same handling down.
				$struct             = $in_url_struct || $is_url;
				$clean[ $safe_key ] = aafm_acf_sanitize_leaf( $sub, $def, $struct, (string) $sub_key );
			}
		}
		return $clean;
	}
	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
		return $value; // Numeric / boolean leaves carry no markup; keep their type.
	}
	if ( ! is_scalar( $value ) ) {
		return ''; // Drop objects / resources / null to an empty string.
	}
	$as_string = (string) $value;

	if ( $in_url_struct ) {
		// A member of a URL-typed field's structured array. Only the url/src-keyed leaf is a URL;
		// the rest is plain text and must NOT be esc_url_raw'd.
		return in_array( $key, aafm_acf_url_leaf_keys(), true )
			? esc_url_raw( $as_string )
			: aafm_sanitize_plain_text( $as_string );
	}
	if ( $is_url ) {
		// A URL-typed field with a scalar value: the value IS the URL, regardless of key name.
		return esc_url_raw( $as_string );
	}
	if ( in_array( $type, aafm_acf_wysiwyg_field_types(), true ) ) {
		return wp_kses_post( $as_string );
	}
	return aafm_sanitize_plain_text( $as_string );
}

/**
 * Map an ACF selector type to the hard-block denylist that the dedicated meta abilities enforce.
 *
 * User fields are gated by the user-scoped floor (aafm_hard_blocked_user_meta_key - the CVE-class
 * control that blocks capability/session/app-password/2FA keys); post and term fields both reuse
 * the post-agnostic floor (aafm_hard_blocked_meta_key), mirroring how aafm_validate_term_meta_key()
 * itself reuses that same post-agnostic hard-block. This is the SAME denylist the meta and user-meta
 * write abilities use - it is reused here, never reinvented.
 *
 * @param string $key           The effective meta key ACF would store under.
 * @param string $selector_type One of 'post', 'term', 'user'.
 * @return bool True when the key is permanently blocked from agent writes.
 */
function aafm_acf_meta_key_hard_blocked( string $key, string $selector_type ): bool {
	if ( 'user' === $selector_type ) {
		return aafm_hard_blocked_user_meta_key( $key );
	}
	return aafm_hard_blocked_meta_key( $key );
}

/**
 * Normalize a value to the canonical form ACF's own storage round-trips it to, so a persisted write
 * can be verified without demanding JSON-exact equality with the value we sent.
 *
 * ACF writes through update_metadata() (acf_update_value() -> acf_update_metadata_by_field()), which
 * stores every scalar in a text column: an integer or float reads back a numeric string, boolean true
 * reads back '1' and false reads back '' (verified against ACF 6.8.6 - the number field's update_value
 * returns the value untouched and true_false has no update_value at all, so both land in metadata raw).
 * A caller who sends the JSON number 42 or boolean true has therefore genuinely persisted the value
 * even though get_field( ..., false ) then reads back '42' / '1'. Coercing BOTH the stored value and
 * the value we wrote to this same string form lets the verify step accept a type-normalizing write as
 * the success it is, while a value that never landed (null, or a genuinely different string) still
 * differs. Arrays recurse so structured field values (link/image/repeater rows) compare leaf by leaf.
 *
 * @param mixed $value Raw value.
 * @return mixed Canonicalized value: scalars as their stored-string form, arrays recursed, everything
 *               else (null/object/resource) collapsed to null so it stays distinct from a real value.
 */
function aafm_acf_normalize_stored( $value ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $key => $sub ) {
			$out[ $key ] = aafm_acf_normalize_stored( $sub );
		}
		return $out;
	}
	if ( is_bool( $value ) ) {
		return $value ? '1' : '';
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (string) $value;
	}
	if ( is_string( $value ) ) {
		return $value;
	}
	return null;
}

/**
 * A field definition's sub-field KEY => NAME map, spanning repeater/group sub_fields and every
 * flexible-content layout's sub_fields.
 *
 * ACF reads a container's raw value back keyed by sub-field key (field_abc123), while the write API
 * and this plugin's documented shape key rows by sub-field name. This map lets the verify translate
 * one into the other.
 *
 * The name it maps to is `_name`, the ORIGINAL name, falling back to `name`. For every ordinary
 * sub-field the two are the same string (acf-field-functions.php acf_validate_field sets
 * `_name = name`), so this is a no-op for repeater, group and flexible-content sub-fields. It is
 * not a no-op for a clone field with "Prefix Field Names" turned on, which is the one place ACF
 * makes them diverge, and getting it wrong there reported failure on writes that had persisted:
 *
 *   - `acf_clone_field()` rewrites the cloned sub-field's `name` to `<clone>_<name>` but leaves
 *     `_name` alone unless the clone also displays seamlessly (class-acf-field-clone.php:291-302).
 *   - The clone's `update_value()` accepts the sent value under `key` or `_name`, and NOTHING else
 *     (:551-561), so `_name` is the only name form a write can arrive under.
 *   - Measured on ACF Pro 6.8.7, cloning a sub-field named `email` into a clone named `contact`:
 *     display=group + prefix -> name `contact_email`, `_name` `email`; seamless + prefix -> both
 *     `contact_email`; either display without prefix -> both `email`. Mapping to `_name` is right
 *     in all four, mapping to `name` is wrong in the first.
 *
 * Mapping to the name ACF itself accepts is also what keeps this function agreeing with
 * aafm_acf_sub_field_def(), which already resolves a sub-field by `_name` as well as by `name`.
 * The two halves disagreeing is what produced the bug.
 *
 * @param array<string,mixed> $def The container field definition (from acf_get_field()).
 * @return array<string,string> key => name.
 */
function aafm_acf_sub_field_key_map( array $def ): array {
	$map     = array();
	$collect = static function ( $sub_fields ) use ( &$map ): void {
		foreach ( (array) $sub_fields as $sub ) {
			if ( is_array( $sub ) && ! empty( $sub['key'] ) && isset( $sub['name'] ) && '' !== (string) $sub['name'] ) {
				$alias                       = isset( $sub['_name'] ) ? (string) $sub['_name'] : '';
				$map[ (string) $sub['key'] ] = '' !== $alias ? $alias : (string) $sub['name'];
			}
		}
	};
	if ( isset( $def['sub_fields'] ) ) {
		$collect( $def['sub_fields'] );
	}
	if ( isset( $def['layouts'] ) && is_array( $def['layouts'] ) ) {
		foreach ( $def['layouts'] as $layout ) {
			if ( is_array( $layout ) && isset( $layout['sub_fields'] ) ) {
				$collect( $layout['sub_fields'] );
			}
		}
	}
	return $map;
}

/**
 * Re-key a container field's raw stored value from sub-field keys to sub-field names.
 *
 * ACF returns a repeater/group/flexible-content raw value keyed by sub-field KEY, but the write
 * side (and aafm_acf_write_fields' verify) works in sub-field NAMES. Translating the stored value
 * back to names is what lets the verify compare like with like. A non-container definition, or a
 * non-array value, is returned untouched, so scalar fields are unaffected. Nested containers recurse
 * through the child definition resolved by name.
 *
 * @param mixed                    $stored The raw stored value from get_field(..., false).
 * @param array<string,mixed>|null $def    The field definition, or null when it cannot be resolved.
 * @return mixed The value with container sub-field keys rewritten to names.
 */
function aafm_acf_rekey_stored_to_names( $stored, ?array $def ) {
	if ( ! is_array( $stored ) || ! is_array( $def ) ) {
		return $stored;
	}
	$type = (string) ( $def['type'] ?? '' );
	if ( ! in_array( $type, aafm_acf_container_field_types(), true ) ) {
		return $stored;
	}

	// A group or clone stores one flat map of sub-field keys; a repeater or flexible-content field
	// stores a numeric-indexed list of such maps (one per row).
	if ( in_array( $type, aafm_acf_flat_container_field_types(), true ) ) {
		return aafm_acf_rekey_row_to_names( $stored, $def );
	}

	$out = array();
	foreach ( $stored as $row_index => $row ) {
		$out[ $row_index ] = is_array( $row ) ? aafm_acf_rekey_row_to_names( $row, $def ) : $row;
	}
	return $out;
}

/**
 * Re-key one container row's sub-field keys to names, recursing into nested containers.
 *
 * A flexible-content row's stored shape keeps its acf_fc_layout marker, and that layout name is
 * what routes the child-def lookup into the right layouts[*]['sub_fields'] - so a container
 * (repeater/group) nested INSIDE a flex layout re-keys its own rows too, instead of being left
 * key-keyed and false-failing the verify.
 *
 * @param array<int|string,mixed> $row One stored row (or a group's/clone's flat map).
 * @param array<string,mixed>     $def The parent container field definition.
 * @return array<int|string,mixed> The row keyed by sub-field name.
 */
function aafm_acf_rekey_row_to_names( array $row, array $def ) {
	$key_to_name = aafm_acf_sub_field_key_map( $def );
	$row_layout  = isset( $row['acf_fc_layout'] ) && is_scalar( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
	$out         = array();
	foreach ( $row as $sub_key => $sub_val ) {
		$sub_key = (string) $sub_key;
		if ( 'acf_fc_layout' === $sub_key ) {
			$out[ $sub_key ] = $sub_val; // Flexible-content layout marker: preserve as-is.
			continue;
		}
		$name         = isset( $key_to_name[ $sub_key ] ) ? $key_to_name[ $sub_key ] : $sub_key;
		$child_def    = aafm_acf_sub_field_def( $def, $name, $row_layout );
		$out[ $name ] = aafm_acf_rekey_stored_to_names( $sub_val, $child_def );
	}
	return $out;
}

/**
 * Reduce a re-keyed stored value to just the shape the caller actually sent, so the write-verify
 * compares what was asked for and not what ACF materialises around it.
 *
 * ACF hydrates a container row with EVERY sub-field its definition declares. Write a repeater row
 * carrying three of its eight sub-fields - the ordinary way to write one - and get_field(..., false)
 * hands back all eight, the five unsent ones filled with their defaults or an empty value. A
 * whole-row comparison therefore differs by keys the caller never mentioned, and the write is
 * reported as failed although it persisted exactly as asked. Measured against ACF Pro 6.x: a
 * three-sub-field row read back with all eight keys present, `''` for text/wysiwyg/radio, `false`
 * for select, the configured default for true_false.
 *
 * So project storage onto the sent shape: keep the keys and row indexes the caller supplied, drop
 * the rest, then let the existing canonicalise-and-compare judge the result. The projection is
 * deliberately conservative - anything that cannot be projected is handed back untouched, which
 * leaves the mismatch (and the failure report) in place:
 *
 *   - A sent list keeps its exact length. A dropped or extra row is a real persistence failure and
 *     must still be caught, so a length mismatch abandons the projection.
 *   - A key the caller sent that storage does not hold at all abandons the projection, so a
 *     sub-field that failed to persist still fails.
 *   - A scalar is returned as-is, leaving the scalar verify path byte-for-byte as it was.
 *
 * @param mixed $stored The re-keyed stored value (already through aafm_acf_rekey_stored_to_names).
 * @param mixed $sent   The sanitized value the caller asked to write.
 * @return mixed The stored value reduced to $sent's shape, or $stored untouched when it cannot be.
 */
function aafm_acf_project_stored_onto_sent( $stored, $sent ) {
	if ( ! is_array( $sent ) ) {
		return $stored;
	}

	if ( array() === $sent ) {
		// An emptied container. ACF stores "no rows" and then reads the whole field back as false -
		// the same thing a never-written container reads back as - so the honest read-back of a
		// successful clear is an empty value, not an empty array. Count any empty read-back as the
		// match it is. A clear that did NOT happen still reads back its surviving rows, which is not
		// empty, so it still mismatches and still reports failure.
		$is_empty = ( false === $stored || null === $stored || '' === $stored || array() === $stored );
		return $is_empty ? array() : $stored;
	}

	if ( ! is_array( $stored ) ) {
		return $stored;
	}

	$out = array();
	if ( array_keys( $sent ) === range( 0, count( $sent ) - 1 ) ) {
		if ( count( $stored ) !== count( $sent ) ) {
			return $stored;
		}
		foreach ( $sent as $index => $sub ) {
			if ( ! array_key_exists( $index, $stored ) ) {
				return $stored;
			}
			$out[ $index ] = aafm_acf_project_stored_onto_sent( $stored[ $index ], $sub );
		}
		return $out;
	}

	foreach ( $sent as $key => $sub ) {
		if ( ! array_key_exists( $key, $stored ) ) {
			return $stored;
		}
		$out[ $key ] = aafm_acf_project_stored_onto_sent( $stored[ $key ], $sub );
	}
	return $out;
}

/**
 * Canonicalise a value for the write-verify comparison: normalise scalar typing (via
 * aafm_acf_normalize_stored) and sort string-keyed maps by key so a container write the caller sent
 * in a different sub-field order still compares equal, while preserving numeric-indexed (row) order,
 * which is meaningful in a repeater.
 *
 * @param mixed $value The value to canonicalise.
 * @return mixed A value whose wp_json_encode() is stable under sub-field reordering.
 */
function aafm_acf_canonicalize_for_compare( $value ) {
	if ( ! is_array( $value ) ) {
		return aafm_acf_normalize_stored( $value );
	}
	$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	$out     = array();
	foreach ( $value as $key => $sub ) {
		$out[ $key ] = aafm_acf_canonicalize_for_compare( $sub );
	}
	if ( ! $is_list ) {
		ksort( $out );
	}
	return $out;
}

/**
 * Apply a sanitized field map to an object selector via update_field(), then return the refreshed
 * read shape so the agent sees ground truth after the write.
 *
 * Every key is gated up front, before any write lands - two floors, both required:
 *
 *   1. The key MUST resolve to a real, defined ACF field (acf_get_field() returns an array, not
 *      false). This is the privilege-escalation fix: for an UNRESOLVED key, ACF's update_field()
 *      falls back to writing raw object metadata under the literal key, an unrestricted meta-write
 *      primitive that the dedicated meta abilities never permit. On a user selector that fallback
 *      let a caller who can edit_user() their own account write wp_capabilities and self-promote to
 *      administrator; on posts/terms it wrote arbitrary meta outside any allowlist. acf_get_field()
 *      returns false in exactly the cases where update_field() would take that fallback, so it is
 *      the precise gate.
 *   2. Even a resolved field's effective storage key must clear the SAME hard-block denylist the
 *      dedicated meta/user-meta abilities enforce, scoped to the selector type. Both the
 *      caller-supplied key and the field's real storage name (the meta_key ACF writes under) are
 *      checked, so a field whose name collides with a protected key (wp_capabilities, session
 *      tokens, application passwords, 2FA keys, …) can never be written through this path either.
 *
 * A single offending key rejects the whole request with a generic WP_Error before any update_field()
 * runs - matching this function's single-generic-error convention (the verify step below likewise
 * collapses any non-persisting write to one generic error) and avoiding a partial write when any key
 * in the map is rejected. Legitimate, defined fields are written and verified exactly as before.
 *
 * After every field is written its stored value is read back and compared to the sanitized value
 * written, so a failed update_field() that stored nothing is never audited as a success. Two things
 * make that comparison honest. First, it respects ACF's stored typing (aafm_acf_normalize_stored):
 * ACF persists through update_metadata(), so a numeric value reads back a numeric string and a
 * boolean reads back '1'/'' - demanding JSON-exact equality would wrongly fail those type-normalizing
 * writes even though the value did persist, while a no-op write of an unchanged value still matches.
 * Second, the loop does NOT abort on the first mismatch: every field in the map is written and
 * verified, so one field that fails to persist can never silently skip the fields that follow it. The
 * request reports failure only after all fields have been attempted.
 *
 * What the verify GUARANTEES, stated exactly: for every value the caller sent - each field, each row
 * index of a sent list, each sub-field key of a sent row, recursively - storage holds that value
 * after the write, allowing for ACF's storage typing; and a sent list holds exactly as many rows as
 * were sent. A value that differs, a sent key storage does not hold, or a row count that does not
 * match, all still report failure.
 *
 * What it does NOT guarantee: sub-fields the caller did not send are not inspected at all. ACF
 * materialises every declared sub-field of a container row on read-back, and those keys are
 * deliberately excluded from the comparison (aafm_acf_project_stored_onto_sent) - without that
 * exclusion an ordinary partial-row write reported failure on a write that had persisted correctly.
 * So if ACF put something unexpected into a sub-field the caller never mentioned, this verify will
 * not see it. Nor can it distinguish a container the caller emptied from one that was never written:
 * ACF reads both back as false, so an empty read-back is accepted for a sent empty container. And it
 * says nothing about fields absent from the caller's map.
 *
 * @param array<string,mixed> $fields        Caller field map: field key => raw value.
 * @param int|string          $selector      ACF object selector.
 * @param string              $selector_type One of 'post', 'term', 'user' - selects the denylist.
 * @return array<string,mixed>|\WP_Error The refreshed hydrated values, or a WP_Error when a key is
 *                                       refused or a write did not persist.
 */
function aafm_acf_write_fields( array $fields, $selector, string $selector_type ) {
	if ( ! function_exists( 'update_field' ) ) {
		return aafm_acf_read_fields( $selector );
	}

	// Floor pass: reject the whole request before writing anything if any key is not a defined ACF
	// field, or its effective meta key is hard-blocked. Fail-closed - if acf_get_field() is somehow
	// unavailable while update_field() exists, no key can resolve and every write is refused.
	foreach ( $fields as $field_key => $raw ) {
		unset( $raw );
		$field_key = (string) $field_key;
		$def       = function_exists( 'acf_get_field' ) ? acf_get_field( $field_key ) : false;
		if ( ! is_array( $def ) || empty( $def['key'] ) ) {
			return aafm_generic_error(); // Unresolved key - the update_field() raw-meta fallback path.
		}
		foreach ( array( $field_key, (string) ( $def['name'] ?? '' ) ) as $candidate ) {
			if ( '' !== $candidate && aafm_acf_meta_key_hard_blocked( $candidate, $selector_type ) ) {
				return aafm_generic_error(); // Defense in depth: protected meta key.
			}
		}
	}

	$failed = array();
	foreach ( $fields as $field_key => $raw ) {
		$clean = aafm_acf_sanitize_value( $raw, (string) $field_key );
		// update_field() persists through update_metadata()/update_option(), both of which unslash
		// the value, so a backslash in a value (C:\Users) is stripped one level unless it is slashed
		// first. Every sibling meta writer (meta.php, terms.php, user-meta.php, the SEO writers)
		// slashes; this ACF writer must too - without it the read-back verify below then reports the
		// mangled persist as a failed write. The verify keeps comparing against the unslashed $clean,
		// which is exactly what storage holds after the round trip.
		update_field( (string) $field_key, wp_slash( $clean ), $selector );

		// Verify the write persisted. A failed update_field() stores nothing, so the read-back
		// will not equal the value we intended. Read the RAW (unformatted) value - get_field()'s
		// third arg false - because the formatted read diverges from the stored value for whole
		// field families (image/file return an array or URL while storing an attachment ID, date
		// pickers reformat, relationship/post-object return objects); comparing the formatted
		// shape would flag those successful writes as failures. Compare after normalizing both
		// sides to ACF's stored typing (aafm_acf_normalize_stored) so a numeric or boolean value
		// that ACF persisted as a string is still recognised as the write it is, while structured
		// arrays and a same-value no-op both still compare cleanly. Record - do NOT return on - a
		// mismatch, so a field that fails to persist never skips the fields still queued after it.
		if ( function_exists( 'get_field' ) ) {
			$stored = get_field( (string) $field_key, $selector, false );
			// A container field (repeater/group/flexible-content) is written keyed by sub-field NAME,
			// but ACF stores and reads the raw value back keyed by sub-field KEY, and it persists rows
			// in field-definition order regardless of the order the caller sent them. Re-key the stored
			// value back to names and compare order-insensitively for string-keyed maps, so a container
			// write that actually persisted is recognised as the success it is instead of always
			// mismatching. Scalars and non-container values pass straight through both helpers.
			// ACF then hydrates a container row with every sub-field its definition declares, so a
			// partial row - three of eight sub-fields, the ordinary way to write one - reads back with
			// all eight and a whole-row comparison fails on the five the caller never mentioned.
			// Project storage onto the sent shape so the verify judges what was asked for. A missing
			// key, a changed value, and a wrong row count all still mismatch; see the projection's
			// own docblock for the exact bound.
			$def     = function_exists( 'acf_get_field' ) ? acf_get_field( (string) $field_key ) : false;
			$stored  = aafm_acf_rekey_stored_to_names( $stored, is_array( $def ) ? $def : null );
			$stored  = aafm_acf_project_stored_onto_sent( $stored, $clean );
			$as_read = wp_json_encode( aafm_acf_canonicalize_for_compare( $stored ) );
			$as_sent = wp_json_encode( aafm_acf_canonicalize_for_compare( $clean ) );
			if ( $as_read !== $as_sent ) {
				$failed[] = (string) $field_key;
			}
		}
	}

	if ( array() !== $failed ) {
		return aafm_generic_error(); // At least one field did not persist; the write as a whole failed.
	}

	return aafm_acf_read_fields( $selector );
}

/**
 * Per-object permission: the caller may edit THIS post (ACF post fields are post content).
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_acf_post( array $input ): bool {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = $id > 0 ? get_post( $id ) : null;
	// Delegate to the shared content-edit gate (not a bare edit_post): it enforces the operator's
	// post-type exposure allowlist AND the map_meta_cap===true fail-open guard, so ACF fields on a
	// non-exposed or non-mapped type are refused exactly as the core content writes are.
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
}

/**
 * Args for aafm/acf-get-post-fields.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_get_post_fields(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-get-post-fields' ),
		'description'         => aafm_ability_description( 'aafm/acf-get-post-fields' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose ACF field values to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'fields'  => array(
					'type'                 => 'object',
					// Keys are the site-defined ACF field names/keys, so the map is open; values follow
					// each field's ACF Return Format and are therefore not individually typed here (A3).
					'additionalProperties' => true,
					'description'          => 'A map of ACF field name (or field key) to its hydrated value. Keys are defined by the site\'s ACF field groups; each value follows that field\'s ACF Return Format.',
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_get_post_fields',
		'permission_callback' => 'aafm_perm_acf_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/acf-get-post-fields.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_acf_get_post_fields( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return array(
		'post_id' => $id,
		// Cast the top-level map so an empty fields set JSON-encodes to "{}" (object) per the
		// schema, never "[]". Only the top level - nested repeater/relationship arrays stay lists.
		'fields'  => (object) aafm_acf_read_fields( $id ),
	);
}

/**
 * Args for aafm/acf-update-post-fields.
 *
 * The closed top-level schema accepts exactly post_id + a free-form `fields` object map; a smuggled
 * sibling key (e.g. a stray role) is rejected by additionalProperties:false. The field map itself is
 * open (additionalProperties:true) because the field keys are site-defined, but every value is
 * recursively type-sanitized before it reaches update_field().
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_update_post_fields(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-update-post-fields' ),
		'description'         => aafm_ability_description( 'aafm/acf-update-post-fields' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose ACF field values to write. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
				'fields'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'Map of ACF field name or field key to its new value. Each key must resolve to a real, defined ACF field (an unresolved key is refused rather than falling back to a raw meta write) and must clear the same protected-key hard-block the meta abilities enforce. Each value is sanitized per the field\'s ACF type: URL-typed fields via esc_url_raw, wysiwyg/textarea via wp_kses_post, everything else as plain text.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'fields' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'fields'  => array(
					'type'                 => 'object',
					// Keys are the site-defined ACF field names/keys, so the map is open; values follow
					// each field's ACF Return Format and are therefore not individually typed here (A3).
					'additionalProperties' => true,
					'description'          => 'A map of ACF field name (or field key) to its hydrated value. Keys are defined by the site\'s ACF field groups; each value follows that field\'s ACF Return Format.',
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_update_post_fields',
		'permission_callback' => 'aafm_perm_acf_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/acf-update-post-fields.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_acf_update_post_fields( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$fields = $input['fields'] ?? null;
	if ( ! is_array( $fields ) ) {
		return aafm_generic_error();
	}
	$written = aafm_acf_write_fields( $fields, $id, 'post' );
	if ( is_wp_error( $written ) ) {
		return $written;
	}
	return array(
		'post_id' => $id,
		// (object) so an empty refreshed map encodes to "{}" per the schema (see the read executor).
		'fields'  => (object) $written,
	);
}

/**
 * Per-object permission: the term exists and the caller may edit it. ACF term fields are term
 * data, so the gate is edit_term on that specific term (mirrors the term-meta family).
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_acf_term( array $input ): bool {
	$id = absint( $input['term_id'] ?? 0 );
	if ( $id < 1 || ! get_term( $id ) instanceof WP_Term ) {
		return false;
	}
	return current_user_can( 'edit_term', $id );
}

/**
 * The ACF object selector for a term: "term_{$id}".
 *
 * @param int $id Term id.
 * @return string
 */
function aafm_acf_term_selector( int $id ): string {
	return 'term_' . $id;
}

/**
 * Args for aafm/acf-get-term-fields.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_get_term_fields(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-get-term-fields' ),
		'description'         => aafm_ability_description( 'aafm/acf-get-term-fields' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the term whose ACF field values to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'term_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term_id' => array( 'type' => 'integer' ),
				'fields'  => array(
					'type'                 => 'object',
					// Keys are the site-defined ACF field names/keys, so the map is open; values follow
					// each field's ACF Return Format and are therefore not individually typed here (A3).
					'additionalProperties' => true,
					'description'          => 'A map of ACF field name (or field key) to its hydrated value. Keys are defined by the site\'s ACF field groups; each value follows that field\'s ACF Return Format.',
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_get_term_fields',
		'permission_callback' => 'aafm_perm_acf_term',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/acf-get-term-fields.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_acf_get_term_fields( array $input ) {
	$id = absint( $input['term_id'] ?? 0 );
	if ( ! get_term( $id ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	return array(
		'term_id' => $id,
		// (object) so an empty fields map encodes to "{}" per the schema (see the post read executor).
		'fields'  => (object) aafm_acf_read_fields( aafm_acf_term_selector( $id ) ),
	);
}

/**
 * Args for aafm/acf-update-term-fields.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_update_term_fields(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-update-term-fields' ),
		'description'         => aafm_ability_description( 'aafm/acf-update-term-fields' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the term whose ACF field values to write. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
				'fields'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'Map of ACF field name or field key to its new value, sanitized and gated exactly like acf-update-post-fields (see that ability for the per-key resolution and hard-block rules).', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'term_id', 'fields' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term_id' => array( 'type' => 'integer' ),
				'fields'  => array(
					'type'                 => 'object',
					// Keys are the site-defined ACF field names/keys, so the map is open; values follow
					// each field's ACF Return Format and are therefore not individually typed here (A3).
					'additionalProperties' => true,
					'description'          => 'A map of ACF field name (or field key) to its hydrated value. Keys are defined by the site\'s ACF field groups; each value follows that field\'s ACF Return Format.',
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_update_term_fields',
		'permission_callback' => 'aafm_perm_acf_term',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/acf-update-term-fields.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_acf_update_term_fields( array $input ) {
	$id = absint( $input['term_id'] ?? 0 );
	if ( ! get_term( $id ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$fields = $input['fields'] ?? null;
	if ( ! is_array( $fields ) ) {
		return aafm_generic_error();
	}
	$written = aafm_acf_write_fields( $fields, aafm_acf_term_selector( $id ), 'term' );
	if ( is_wp_error( $written ) ) {
		return $written;
	}
	return array(
		'term_id' => $id,
		// (object) so an empty refreshed map encodes to "{}" per the schema (see the read executor).
		'fields'  => (object) $written,
	);
}

/**
 * Per-object permission: the target user exists and the caller may edit it. ACF user fields are
 * user data, so the gate is edit_user on that specific account (mirrors the user-meta family).
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_acf_user( array $input ): bool {
	$id = absint( $input['user_id'] ?? 0 );
	if ( $id < 1 || ! get_userdata( $id ) instanceof WP_User ) {
		return false;
	}
	// The object-independent edit_users floor comes first: edit_user($id) alone is true for every
	// user against their own id (map_meta_cap self short-circuit), so without the floor a subscriber
	// could read or write its own ACF user fields. Mirrors aafm_perm_update_user() and the
	// user-meta family; matches the edit_users discovery floor in server.php.
	return current_user_can( 'edit_users' ) && current_user_can( 'edit_user', $id );
}

/**
 * The ACF object selector for a user: "user_{$id}".
 *
 * @param int $id User id.
 * @return string
 */
function aafm_acf_user_selector( int $id ): string {
	return 'user_' . $id;
}

/**
 * Args for aafm/acf-get-user-fields.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_get_user_fields(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-get-user-fields' ),
		'description'         => aafm_ability_description( 'aafm/acf-get-user-fields' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the user whose ACF field values to read. The current user must have edit access to this user. A field of the user_email type returns the real email address (PII), governed by the edit-user capability gate rather than being redacted.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array( 'type' => 'integer' ),
				'fields'  => array(
					'type'                 => 'object',
					// Keys are the site-defined ACF field names/keys, so the map is open; values follow
					// each field's ACF Return Format and are therefore not individually typed here (A3).
					'additionalProperties' => true,
					'description'          => 'A map of ACF field name (or field key) to its hydrated value. Keys are defined by the site\'s ACF field groups; each value follows that field\'s ACF Return Format.',
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_get_user_fields',
		'permission_callback' => 'aafm_perm_acf_user',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/acf-get-user-fields.
 *
 * Returns the hydrated user ACF values AS-IS. A user_email-type field's value (PII) is included,
 * not stripped - the edit_user gate, default-OFF state, and audit are the governance, mirroring the
 * locked "user email exposed by default" decision. No projection removes it.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_acf_get_user_fields( array $input ) {
	$id = absint( $input['user_id'] ?? 0 );
	if ( ! get_userdata( $id ) instanceof WP_User ) {
		return aafm_generic_error();
	}
	return array(
		'user_id' => $id,
		// (object) so an empty fields map encodes to "{}" per the schema (see the post read executor).
		'fields'  => (object) aafm_acf_read_fields( aafm_acf_user_selector( $id ) ),
	);
}

/**
 * Args for aafm/acf-update-user-fields.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_update_user_fields(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/acf-update-user-fields' ),
		'description'         => aafm_ability_description( 'aafm/acf-update-user-fields' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the user whose ACF field values to write. The current user must have edit access to this user.', 'agent-abilities-for-mcp' ),
				),
				'fields'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'Map of ACF field name or field key to its new value, sanitized and gated exactly like acf-update-post-fields (see that ability for the per-key resolution and hard-block rules). The user-scoped hard-block also blocks capability, session, application-password, and 2FA keys even if a field\'s storage name collides with one.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'user_id', 'fields' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array( 'type' => 'integer' ),
				'fields'  => array(
					'type'                 => 'object',
					// Keys are the site-defined ACF field names/keys, so the map is open; values follow
					// each field's ACF Return Format and are therefore not individually typed here (A3).
					'additionalProperties' => true,
					'description'          => 'A map of ACF field name (or field key) to its hydrated value. Keys are defined by the site\'s ACF field groups; each value follows that field\'s ACF Return Format.',
				),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_update_user_fields',
		'permission_callback' => 'aafm_perm_acf_user',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/acf-update-user-fields.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_acf_update_user_fields( array $input ) {
	$id = absint( $input['user_id'] ?? 0 );
	if ( ! get_userdata( $id ) instanceof WP_User ) {
		return aafm_generic_error();
	}
	$fields = $input['fields'] ?? null;
	if ( ! is_array( $fields ) ) {
		return aafm_generic_error();
	}
	$written = aafm_acf_write_fields( $fields, aafm_acf_user_selector( $id ), 'user' );
	if ( is_wp_error( $written ) ) {
		return $written;
	}
	return array(
		'user_id' => $id,
		// (object) so an empty refreshed map encodes to "{}" per the schema (see the read executor).
		'fields'  => (object) $written,
	);
}
