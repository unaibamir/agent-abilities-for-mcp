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
			'description'  => __( "Reads all of a post's ACF field values, hydrated by field key. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
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
			'description'  => __( "Reads all of a term's ACF field values, hydrated by field key. Requires edit access to that term.", 'agent-abilities-for-mcp' ),
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
			'description'  => __( "Reads all of a user's ACF field values, hydrated by field key. A field of the user_email type returns the real email address under the integration disclaimer. Requires edit access to that user.", 'agent-abilities-for-mcp' ),
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
 * a javascript: scheme) rather than sanitize_text_field.
 *
 * @return string[]
 */
function aafm_acf_url_field_types(): array {
	return array( 'url', 'link', 'file', 'image', 'oembed' );
}

/**
 * The ACF field types whose value is rich HTML and so are sanitized with wp_kses_post rather than
 * stripped flat by sanitize_text_field.
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
 * The ACF container field types whose value is a list of rows / a group keyed by sub-field name.
 *
 * @return string[]
 */
function aafm_acf_container_field_types(): array {
	return array( 'repeater', 'group', 'flexible_content' );
}

/**
 * Resolve a sub-field's ACF definition by its name within a parent field definition.
 *
 * ACF repeater/group/flexible-content values are keyed by the sub-field NAME, so a nested leaf's
 * own type is found by matching that key against the parent def's `sub_fields`. Returns null when
 * the parent has no matching sub-field (e.g. a free-form nested array).
 *
 * @param array<string,mixed> $parent_def The parent field definition.
 * @param string              $name       The nested key (a sub-field name).
 * @return array<string,mixed>|null The sub-field definition, or null when not found.
 */
function aafm_acf_sub_field_def( array $parent_def, string $name ): ?array {
	$sub_fields = isset( $parent_def['sub_fields'] ) && is_array( $parent_def['sub_fields'] ) ? $parent_def['sub_fields'] : array();
	foreach ( $sub_fields as $sub ) {
		if ( is_array( $sub ) && isset( $sub['name'] ) && (string) $sub['name'] === $name ) {
			return $sub;
		}
	}
	return null;
}

/**
 * Recursively sanitize one ACF field value for writing.
 *
 * Scalars are sanitized by the field's resolved type: a URL-type value through esc_url_raw, a
 * wysiwyg/textarea value through wp_kses_post, everything else through sanitize_text_field. Arrays
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
		foreach ( $value as $sub_key => $sub ) {
			$safe_key = is_string( $sub_key ) ? sanitize_text_field( $sub_key ) : $sub_key;
			if ( $is_cont && $def ) {
				// A repeater/group/flexible-content level. Numeric keys are row indices that
				// keep the same container def; string keys are sub-field names whose own def
				// drives their sanitizing - so a URL sub-field is esc_url_raw'd at depth.
				$child_def          = is_string( $sub_key ) ? aafm_acf_sub_field_def( $def, (string) $sub_key ) : $def;
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
			: sanitize_text_field( $as_string );
	}
	if ( $is_url ) {
		// A URL-typed field with a scalar value: the value IS the URL, regardless of key name.
		return esc_url_raw( $as_string );
	}
	if ( in_array( $type, aafm_acf_wysiwyg_field_types(), true ) ) {
		return wp_kses_post( $as_string );
	}
	return sanitize_text_field( $as_string );
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
 * runs - matching this function's existing fail-fast, single-generic-error convention (the verify
 * step below already returns aafm_generic_error() on the first non-persisting write) and avoiding a
 * partial write when any key in the map is rejected. Legitimate, defined fields are written and
 * verified exactly as before.
 *
 * After each write the stored value is read back and compared to the sanitized value written; a
 * mismatch (a failed update_field that stored nothing) surfaces as an error so a failed write is
 * never audited as a success. A no-op write of an unchanged value still matches, so it is not
 * treated as a failure.
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

	foreach ( $fields as $field_key => $raw ) {
		$clean = aafm_acf_sanitize_value( $raw, (string) $field_key );
		update_field( (string) $field_key, $clean, $selector );

		// Verify the write persisted. A failed update_field() stores nothing, so the read-back
		// will not equal the value we intended. Read the RAW (unformatted) value - get_field()'s
		// third arg false - because the formatted read diverges from the stored value for whole
		// field families (image/file return an array or URL while storing an attachment ID, date
		// pickers reformat, relationship/post-object return objects); comparing the formatted
		// shape would flag those successful writes as failures. Compare normalized JSON so
		// scalars and structured arrays both compare cleanly, and a same-value no-op still
		// matches.
		if ( function_exists( 'get_field' ) ) {
			$stored = get_field( (string) $field_key, $selector, false );
			if ( wp_json_encode( $stored ) !== wp_json_encode( $clean ) ) {
				return aafm_generic_error();
			}
		}
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
					'type'    => 'integer',
					'minimum' => 1,
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
					'type'    => 'integer',
					'minimum' => 1,
				),
				'fields'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
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
					'type'    => 'integer',
					'minimum' => 1,
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
					'type'    => 'integer',
					'minimum' => 1,
				),
				'fields'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
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
	return current_user_can( 'edit_user', $id );
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
					'type'    => 'integer',
					'minimum' => 1,
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
					'type'    => 'integer',
					'minimum' => 1,
				),
				'fields'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
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
