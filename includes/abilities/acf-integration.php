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
 * Every layout name a flexible-content definition declares.
 *
 * Deliberately separate from aafm_acf_sub_field_defs(), which cannot answer this question: that
 * function skips a layout carrying no `sub_fields` array BEFORE it ever compares the layout name,
 * so an undeclared layout and a DECLARED layout that happens to declare no sub-fields both come
 * back as "no sub-fields here" and are indistinguishable to it. Telling those two apart is the
 * whole point - one is a caller error and the other is an ordinary write that must keep working.
 * A layout with an empty sub-field list is legal in ACF and was measured succeeding on the bench.
 *
 * Returns an empty list for any non-flexible-content definition, and for a flexible-content
 * definition that declares no layouts at all. Both mean the same thing to the caller: there is
 * nothing here to judge a row's layout against, so no row may be refused on this ground.
 *
 * @param array<string,mixed> $def The field definition, from acf_get_field().
 * @return array<int,string> The declared layout names, in declaration order.
 */
function aafm_acf_declared_layout_names( array $def ): array {
	$names = array();
	if ( ! isset( $def['layouts'] ) || ! is_array( $def['layouts'] ) ) {
		return $names;
	}
	foreach ( $def['layouts'] as $candidate ) {
		if ( ! is_array( $candidate ) || ! isset( $candidate['name'] ) || ! is_scalar( $candidate['name'] ) ) {
			continue;
		}
		$name = (string) $candidate['name'];
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}
	return $names;
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
	foreach ( aafm_acf_sub_field_defs( $parent_def, $layout ) as $sub ) {
		$sub_name  = isset( $sub['name'] ) ? (string) $sub['name'] : '';
		$sub_alias = isset( $sub['_name'] ) ? (string) $sub['_name'] : '';
		if ( $name === $sub_name || ( '' !== $sub_alias && $name === $sub_alias ) ) {
			return $sub;
		}
	}
	return null;
}

/**
 * Every sub-field definition a container declares at the depth a row of $layout sits at.
 *
 * Extracted verbatim from aafm_acf_sub_field_def() so the name lookup and the effective-meta-key
 * derivation share ONE copy of the layout-descent rule. A second copy is how this project's
 * documented "fixed at one call site, never swept" archetype starts.
 *
 * @param array<string,mixed> $parent_def The parent field definition.
 * @param string              $layout     The row's `acf_fc_layout` layout name, when known.
 * @return array<int,array<string,mixed>> The sub-field definitions, in declaration order.
 */
function aafm_acf_sub_field_defs( array $parent_def, string $layout = '' ): array {
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
	$out = array();
	foreach ( $sub_fields as $sub ) {
		if ( is_array( $sub ) ) {
			$out[] = $sub;
		}
	}
	return $out;
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
 * Pass $def when the caller has already resolved the field. acf_get_field() is NOT a pure function
 * of its key: it runs the `acf/load_field` filter chain, which third-party plugins are invited to
 * use, so a stateful filter can return a different definition on a second call. Measured against
 * real ACF free 6.3.6: with a stateful `acf/load_field` in place, two calls for the same key
 * returned `text` and then `wysiwyg` once the field store was reset - and a `text` sub-field
 * re-presented as `wysiwyg` routes to wp_kses_post instead of the plain-text sanitizer, which is a
 * different set of characters surviving into storage. What stops that today is ACF's per-request
 * field store, not any property of the function: without the reset the filter ran ONCE across both
 * calls and the results agreed. That is vendor caching behaviour nobody wrote down, which is this
 * project's worst shipped defect class, so the write path resolves each field ONCE and hands the
 * definition down rather than depending on repeated resolutions agreeing.
 *
 * @param mixed                    $value     Raw caller value.
 * @param string                   $field_key The top-level field key (to resolve its type).
 * @param array<string,mixed>|null $def       The already-resolved definition, or null to resolve here.
 * @return mixed Sanitized value.
 */
function aafm_acf_sanitize_value( $value, string $field_key, ?array $def = null ) {
	if ( null === $def ) {
		$def = array();
		if ( function_exists( 'acf_get_field' ) ) {
			$resolved = acf_get_field( $field_key );
			$def      = is_array( $resolved ) ? $resolved : array();
		}
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
	// A flexible-content layout marker is a NAME, so it must not take the keep-your-type exit below.
	// Everything downstream reads it as a string: the layout guard casts it before its strict
	// membership test, and ACF's own get_layout() compares $layout['name'] === $name with no cast
	// at all. Leaving a JSON number as an int made those two disagree - our guard saw "5", matched
	// a layout genuinely named "5" and let the row through, while ACF compared '5' === 5, failed to
	// resolve it, and took the destructive branch: the row's stored sub-field values deleted, the
	// unusable marker written in their place, and a generic read-back failure reported afterwards.
	// Same shape as R5-1, one type away.
	//
	// It falls through to the ordinary string handling rather than returning here, so the existing
	// plain-text normalisation still applies. A pinned corpus row requires `hero ` to be trimmed
	// and ACCEPTED, and short-circuiting past that turned a case that works today into a refusal.
	if ( ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) && 'acf_fc_layout' !== $key ) {
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
 * The prefix ACF puts in front of a clone's sub-field names before writing them.
 *
 * Mirrors ACF's own class-acf-field-clone.php::prepare_field_for_db(), which is the ONLY place a
 * clone's sub-field names are rewritten at write time: it computes the prefix by stripping the
 * clone's own `_name` off the end of its effective `name`, and it BAILS ENTIRELY when the two are
 * equal. They are equal for a top-level clone - which is exactly why a top-level clone writes its
 * sub-fields under their own bare names and why the hard block used to miss them.
 *
 * Note this is independent of the clone's `display` setting. ACF's acf_clone_field() rewrites a
 * sub-field's `name` under `prefix_name` only; the seamless branch touches `key`, `prefix`,
 * `parent` and `label` and never `name`. Measured against ACF Pro 6.8.7, both display modes with
 * `prefix_name => 0` land on the bare sub-field name.
 *
 * @param array<string,mixed> $def  The clone field definition.
 * @param string              $name The clone's EFFECTIVE name at this depth (its own name at top
 *                                  level; the parent-prefixed one when it is nested).
 * @return string The prefix, or '' when ACF adds none.
 */
function aafm_acf_clone_sub_prefix( array $def, string $name ): string {
	$own = isset( $def['_name'] ) && '' !== (string) $def['_name'] ? (string) $def['_name'] : (string) ( $def['name'] ?? '' );
	if ( '' === $own || $name === $own ) {
		return ''; // prepare_field_for_db() bails: a top-level clone prefixes nothing.
	}
	$prefix = (string) substr( $name, 0, - strlen( $own ) );
	// ACF bails the same way when _name is not the tail of name ("unknown potential error").
	return $prefix . $own === $name ? $prefix : '';
}

/**
 * Every meta key a write of $sent to $def would actually land on.
 *
 * The hard block used to test the TOP-LEVEL field's key and name only. That is the whole key for a
 * scalar field, but a container writes one meta row per sub-field under a key ACF derives, and for
 * one container shape that derived key is the sub-field's own bare name - so a sub-field named
 * `wp_capabilities` inside a top-level unprefixed clone reached a protected key the block exists to
 * stop. This derives the real key set instead of reasoning about which shapes "cannot collide";
 * that reasoning was also wrong in the other direction, because the denylist is not only bare names
 * (it covers any `_`-prefixed key via is_protected_meta(), and `{$wpdb->prefix}\d*_?capabilities`),
 * so a group literally named `wp` holding a sub-field named `capabilities` composes to a blocked key
 * as well.
 *
 * The four container types are NOT handled uniformly, because ACF does not handle them uniformly.
 * Each rule below is read off the vendor's own write path and confirmed by a zero-write probe that
 * drives the production `update_value()` methods with `acf/pre_update_metadata` short-circuited:
 *
 *   - `clone`             `{clone prefix}{sub name}` - and the prefix is EMPTY at top level.
 *                         class-acf-field-clone.php::prepare_field_for_db(). The defect.
 *   - `group`             `{name}_{sub _name}` - class-acf-field-group.php::prepare_field_for_db().
 *                         Note `_name`, not `name`: an asymmetry the other three do not share.
 *   - `repeater`          `{name}_{row index}_{sub name}` - class-acf-field-repeater.php::update_row().
 *   - `flexible_content`  `{name}_{row index}_{sub name}` - the flexible-content update_row().
 *   - anything else       the field's own effective name; nothing further is written.
 *
 * A container's own effective name is always included, because ACF writes it too (a repeater stores
 * its row count there, a group and a clone an empty string).
 *
 * Sub-fields are addressed the way ACF itself addresses them - by the sub-field KEY first, then by
 * its name. Resolving names alone would have left a one-line bypass: send the sub-field's key
 * instead of its name and the derivation would find nothing to check while ACF wrote the row.
 *
 * What this does NOT claim, stated as measured rather than intended. It walks the caller's own
 * structure, so it enumerates keys for the sub-fields the caller actually addressed and no others -
 * which is the point, since an unaddressed sub-field is never written.
 *
 * Two families of ACF-internal row are deliberately excluded, and the second one is not an
 * omission but a requirement:
 *
 *   - The reference rows `_{name}`, written through acf_update_metadata_by_field()'s hidden branch
 *     to record which field key owns a value.
 *   - A flexible-content field's `_{name}_layout_meta` bookkeeping row (the disabled/renamed layout
 *     record, written by that field type's update_layout_meta()). Deriving it would REFUSE EVERY
 *     FLEXIBLE-CONTENT WRITE, because its leading underscore makes is_protected_meta() true. It is
 *     safe to exclude for the same reason it is not a vector: its name is `_` plus the flex field's
 *     own name, which the site owner chose and which this same floor pass already checks, and no
 *     part of it is caller-controlled.
 *
 * A definition carrying no `_name` at all falls back to `name`; real ACF always sets `_name` in
 * acf_validate_field(), so that branch is reachable only with a hand-built definition.
 *
 * Measured coverage, from a mutation pass over the corpus rather than asserted: eighteen mutants,
 * sixteen killed. That includes blinding this derivation to each container type in turn, because a
 * single whole-derivation mutant cannot tell a version that handles clone correctly and flexible
 * content not at all from one that handles both. All four blindings go red on a refuse row that
 * names their own type. The two mutants that survive are named rather than counted as proof.
 *
 *   - Removing the `acf_fc_layout` skip changes nothing, because no sub-field resolves under that
 *     address anyway. It is EQUIVALENT unless a layout declares a sub-field literally named
 *     `acf_fc_layout`, which ACF's own row format could not survive. Kept because it mirrors the
 *     identical skip in aafm_acf_rekey_row_to_names() and says what the marker is.
 *   - Removing the empty-`$own` guards likewise changes no row: a definition with no name at all
 *     would make the derivation emit the bare prefix, and no prefix in the corpus is protected.
 *     DEFENSIVE, not demonstrated load-bearing.
 *
 * @param array<string,mixed> $def  The field definition, from acf_get_field().
 * @param mixed               $sent The raw value the caller asked to write at this depth.
 * @param string              $name The field's effective name at this depth. Defaults to the
 *                                  definition's own name, which is correct at top level.
 * @return array<int,string> The meta keys, in no particular order and possibly with duplicates.
 */
function aafm_acf_effective_meta_keys( array $def, $sent, string $name = '' ): array {
	$name = '' !== $name ? $name : (string) ( $def['name'] ?? '' );
	if ( '' === $name ) {
		return array();
	}
	$keys = array( $name );
	$type = (string) ( $def['type'] ?? '' );
	if ( ! is_array( $sent ) || ! in_array( $type, aafm_acf_container_field_types(), true ) ) {
		return $keys;
	}

	// group and clone: ONE flat map of sub-field values, no row indices.
	if ( in_array( $type, aafm_acf_flat_container_field_types(), true ) ) {
		$prefix = 'clone' === $type ? aafm_acf_clone_sub_prefix( $def, $name ) : $name . '_';
		foreach ( $sent as $address => $sub_value ) {
			$sub = aafm_acf_sub_field_by_address( $def, (string) $address );
			if ( null === $sub ) {
				continue; // ACF skips a value that matches no sub-field; nothing is written for it.
			}
			// Clone reads the sub-field's own `name` (already prefix_name-rewritten); group reads
			// `_name` and composes the prefix itself. Following the vendor exactly matters here:
			// under prefix_name the two differ, and picking the wrong one derives a key ACF never
			// writes, which would refuse a legitimate write.
			$own = 'clone' === $type
				? (string) ( $sub['name'] ?? '' )
				: ( isset( $sub['_name'] ) && '' !== (string) $sub['_name'] ? (string) $sub['_name'] : (string) ( $sub['name'] ?? '' ) );
			if ( '' === $own ) {
				continue;
			}
			$keys = array_merge( $keys, aafm_acf_effective_meta_keys( $sub, $sub_value, $prefix . $own ) );
		}
		return $keys;
	}

	// repeater and flexible_content: a numeric-indexed list of rows. ACF re-indexes the rows it
	// writes from zero in the order they arrive, so the index is the position, not the sent key.
	$row_index = -1;
	foreach ( $sent as $row ) {
		++$row_index;
		if ( ! is_array( $row ) ) {
			continue;
		}
		$layout = isset( $row['acf_fc_layout'] ) && is_scalar( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
		foreach ( $row as $address => $sub_value ) {
			if ( 'acf_fc_layout' === $address ) {
				continue; // The layout marker is not a sub-field and is not stored as one.
			}
			$sub = aafm_acf_sub_field_by_address( $def, (string) $address, $layout );
			if ( null === $sub ) {
				continue;
			}
			$own = (string) ( $sub['name'] ?? '' );
			if ( '' === $own ) {
				continue;
			}
			$keys = array_merge( $keys, aafm_acf_effective_meta_keys( $sub, $sub_value, $name . '_' . $row_index . '_' . $own ) );
		}
	}
	return $keys;
}

/**
 * Resolve a sub-field definition the way ACF's own container write path resolves one.
 *
 * Every container's update_value()/update_row() looks the sent value up by the sub-field's `key`
 * first and falls back to its name. aafm_acf_sub_field_def() only ever matched names, which is
 * right for the sanitizer and the re-keyer (both work in names by then) but not for a derivation
 * that has to see everything ACF will write.
 *
 * @param array<string,mixed> $parent_def The parent container definition.
 * @param string              $address    The key the caller used: a sub-field key or a sub-field name.
 * @param string              $layout     The row's `acf_fc_layout` layout name, when known.
 * @return array<string,mixed>|null The sub-field definition, or null when nothing matches.
 */
function aafm_acf_sub_field_by_address( array $parent_def, string $address, string $layout = '' ): ?array {
	foreach ( aafm_acf_sub_field_defs( $parent_def, $layout ) as $sub ) {
		if ( isset( $sub['key'] ) && $address === (string) $sub['key'] ) {
			return $sub;
		}
	}
	return aafm_acf_sub_field_def( $parent_def, $address, $layout );
}

/**
 * Every address in a container write that names no sub-field the definition declares.
 *
 * ACF never writes such an address. Each container type iterates the sub-fields its DEFINITION
 * declares and pulls the caller's value out by the sub-field's key and then its name/`_name`
 * (class-acf-field-repeater.php::update_row, the group/clone update_value, the flexible-content
 * update_row); an address matching none of them is simply never read. Measured against real ACF:
 * a group write carrying `alpha` plus an undeclared `nosuchsub` stores `{name}_alpha` and no
 * `{name}_nosuchsub` row at all.
 *
 * WHAT IS DOCUMENTED AND WHAT IS ONLY OBSERVED, because this plugin has already shipped a guard
 * resting on a vendor contract the vendor never offered, and the difference decides how much this
 * may safely rely on. Documentation says what ACF INTENDS and may therefore keep; source says only
 * what 6.3.6 and 6.8.7 happen to do today.
 *
 *   DOCUMENTED - the accepted value SHAPE of each container. A repeater or flexible-content value
 *   is a list of rows keyed by sub-field name, and a flexible-content row carries `acf_fc_layout`
 *   (advancedcustomfields.com/resources/update_field, whose own examples send it in every row;
 *   /resources/rest-api calls it a required `acf_fc_layout` property). A group composes its
 *   storage key from its own name plus the sub-field's (/resources/group: a group `hero` with a
 *   sub-field `image` saves as `hero_image`). A clone set to Prefix Field Names is addressed at
 *   TOP level as `{clone}_{original}` (/resources/update_field).
 *   DOCUMENTED - addressing a sub-field by its field KEY rather than its name. /resources/add_row
 *   shows a whole row keyed by field keys, and /resources/update_field recommends the key form
 *   when saving a value that does not yet exist. So aafm_acf_sub_field_by_address() handling keys
 *   satisfies the documented contract; it is not defensive hardening.
 *   OBSERVED ONLY - that ACF IGNORES an address matching no declared sub-field. The docs specify
 *   the accepted shapes and say nothing about a key that is not one of them. True of ACF free
 *   6.3.6 (driven end to end in the contract suite) and ACF Pro 6.8.7 (zero-write probe on the
 *   bench), with no documented boundary between them, and free to change in a future release
 *   without ACF breaking any promise.
 *
 * That last line is why this REFUSES up front instead of stripping unknown addresses and reporting
 * what landed. Stripping would be correct only for as long as ACF keeps ignoring them, which is
 * exactly the kind of undocumented detail this project has been burned by before. Refusing does
 * not depend on that behaviour at all, because the undeclared address never reaches ACF and the
 * outcome is the same whichever way ACF changes it. Do not "simplify" this back.
 *
 * That is also what made the address worth refusing up front rather than discovering afterwards. The
 * write of the recognised sub-fields LANDS, the read-back verify then cannot find the undeclared
 * address in storage, and the whole request is reported as a failure - so the caller is told the
 * write failed while the data changed underneath them. Measured end to end against real ACF: a
 * sub-field went from `BEFORE` to `AFTER-WRITE-LANDED` on the very call that returned an error.
 * Refusing before any write makes the sub-field level behave the way the top-level key already
 * behaves, where an unresolved field key rejects the request with the database untouched.
 *
 * Addresses are resolved exactly the way ACF resolves them, through
 * aafm_acf_sub_field_by_address(): by sub-field key first, then by name and `_name`, and inside a
 * flexible-content row through the layout that row names. Two things are deliberately NOT flagged:
 *
 *   - `acf_fc_layout`, the marker every flexible-content row must carry. It resolves to no
 *     sub-field by design, so flagging it would refuse every flexible-content write - the same
 *     trap the `_layout_meta` row set for the effective-key derivation.
 *   - Anything below a definition that declares no sub-fields at this depth. A definition we
 *     cannot see the shape of tells us nothing about what is undeclared, and refusing a whole
 *     write on no information is a far worse failure than the one being fixed. That shape keeps
 *     today's behaviour: ACF writes nothing, the read-back verify mismatches, and the request
 *     reports the failure it really is.
 *
 * The walk deliberately descends only into container-typed sub-fields. A URL/link/image field's
 * value is a structured array whose members (`url`, `title`, `target`, ...) are not sub-fields,
 * and a checkbox/select/gallery value is a plain list; neither declares sub-fields and neither is
 * addressed the way a container is.
 *
 * This is a second walk over the caller's structure alongside aafm_acf_effective_meta_keys(), and
 * two walks are how this project's documented drift starts. They share every resolution rule
 * through the same helpers (aafm_acf_sub_field_by_address, aafm_acf_sub_field_defs,
 * aafm_acf_container_field_types, aafm_acf_flat_container_field_types), and what remains is pinned
 * by a test asserting the two agree across the whole corpus: every address the derivation resolves
 * to a key is one this function does not flag, and every address this function flags is one the
 * derivation derives no key for. Blinding this function to a single container type while leaving
 * the derivation alone makes that test go red by name, so the pin is load-bearing rather than
 * decorative.
 *
 * Measured coverage, from a mutation pass rather than asserted: twenty-one mutants, twenty killed.
 * That includes blinding this walk to each container type individually - clone, group, repeater and
 * flexible_content each kill an OUTCOME-level refuse row that names them, because a single
 * whole-function mutant cannot tell a version that handles clone correctly and flexible content not
 * at all from one that handles both. It also includes dropping the `acf_fc_layout` skip (which
 * refuses every flexible-content write), dropping the no-shape fall-through, judging the raw value
 * instead of the sanitized one, walking only the first row of a list, walking only the first field
 * of the request, and not recursing into nested containers. The one survivor is named where it
 * lives, in aafm_acf_unknown_sub_field_error(), rather than counted as proof.
 *
 * A flexible-content row can also be unwritable for a reason that is not an address at all: its
 * `acf_fc_layout` marker naming a layout the field does not declare, or missing entirely. Those
 * rows come out through the $bad_layouts out-parameter rather than the return value, because they
 * are a different claim and get a different refusal - see aafm_acf_unknown_layout_error(). It is an
 * out-parameter rather than a second function so that ONE traversal answers both questions: a
 * second walk would have to repeat this function's descent rules (which values are rows, which
 * index a row sits at, which layout a row resolves under), and two copies of a descent rule is
 * where this project's documented drift starts.
 *
 * @param array<string,mixed> $def         The field definition, from acf_get_field().
 * @param mixed               $sent        The sanitized value the caller asked to write at this depth.
 * @param string              $path        The dotted address of $def within the request, for the message.
 * @param array<int,string>   $bad_layouts Out: the dotted addresses of rows whose layout cannot resolve.
 * @return array<int,string> The unresolved addresses, dotted from the top-level field name.
 */
function aafm_acf_unresolved_sub_addresses( array $def, $sent, string $path = '', array &$bad_layouts = array() ): array {
	$type = (string) ( $def['type'] ?? '' );
	if ( ! is_array( $sent ) || ! in_array( $type, aafm_acf_container_field_types(), true ) ) {
		return array();
	}
	$path = '' !== $path ? $path : (string) ( $def['name'] ?? '' );

	// group and clone: ONE flat map of sub-field values. repeater and flexible_content: a list of
	// such maps, one per row, so the row's own position joins the address. That position is
	// ZERO-BASED, matching the `{name}_{row}_{sub}` storage key ACF writes and the index the
	// sibling key derivation uses. It is deliberately NOT the 1-based row number ACF's
	// update_row()/add_sub_row() API takes (/resources/update_row: row numbers begin from 1).
	// Nothing here calls those functions, and the two bases must never meet.
	if ( in_array( $type, aafm_acf_flat_container_field_types(), true ) ) {
		return aafm_acf_unresolved_in_row( $def, $sent, $path, $bad_layouts );
	}
	$out       = array();
	$row_index = -1;
	foreach ( $sent as $row ) {
		++$row_index;
		if ( ! is_array( $row ) ) {
			continue; // Not a row shape at all; ACF writes nothing and the verify still catches it.
		}
		$out = array_merge( $out, aafm_acf_unresolved_in_row( $def, $row, $path . '.' . $row_index, $bad_layouts ) );
	}
	return $out;
}

/**
 * The per-row half of aafm_acf_unresolved_sub_addresses(): one container row, or a group's or
 * clone's flat sub-field map.
 *
 * @param array<string,mixed>     $def         The parent container definition.
 * @param array<int|string,mixed> $row         The row the caller sent.
 * @param string                  $path        The dotted address of $row within the request.
 * @param array<int,string>       $bad_layouts Out: row addresses whose flexible-content layout cannot resolve.
 * @return array<int,string> The unresolved addresses within this row.
 */
function aafm_acf_unresolved_in_row( array $def, array $row, string $path, array &$bad_layouts = array() ): array {
	$type   = (string) ( $def['type'] ?? '' );
	$layout = isset( $row['acf_fc_layout'] ) && is_scalar( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';

	// A flexible-content row is addressed THROUGH its layout: acf_fc_layout is how ACF decides which
	// layout's sub-fields the row is made of, and the docs make it a required property of every row
	// (/resources/rest-api: "arrays of layout objects with a required 'acf_fc_layout' property";
	// every row of /resources/update_field's flex example carries one). A row naming a layout the
	// field does not declare, or carrying no marker at all, is therefore a caller error the
	// definition can SEE - unlike a shape we cannot introspect, which is what the softening below
	// exists to keep protecting.
	//
	// Letting such a row through is not a report-only bug, it DESTROYS DATA. Measured at the
	// database against real ACF Pro 6.8.7: a field holding heading='BEFORE-F8' and body='BODY-BEFORE'
	// was sent one row layout-marked `no_such_layout`; ACF replaced the whole field value, BOTH
	// sub-field rows were deleted, the stored value became a single unusable layout name, and the
	// request then reported FAILURE because the read-back could not match. Omitting the marker
	// instead empties the field completely. So the agent is told the write failed, the previous
	// content is gone, and there is no recovery path. Refusing before update_field() is what makes
	// the failure report true. Behaviour-change bound: nobody who succeeds today starts failing,
	// because every shape refused here already came back an error - the only thing that changes is
	// that the database is now untouched when we say so.
	//
	// Deliberately NOT swept to the other container types: repeater, group and clone have no row
	// selector at all, so there is nothing about their rows the definition could contradict. This
	// is per-type because the QUESTION is per-type, not because a uniform rule was narrowed.
	if ( 'flexible_content' === $type ) {
		$declared = aafm_acf_declared_layout_names( $def );
		if ( array() !== $declared && ! in_array( $layout, $declared, true ) ) {
			// '' is never a declared name, so a missing marker and an undeclared one are one
			// predicate. Both are pinned separately in the corpus, and blinding this to the named
			// case alone kills the missing-marker row by name.
			$bad_layouts[] = $path;
			return array(); // Judging this row's addresses against a layout it cannot have is noise.
		}
	}

	if ( array() === aafm_acf_sub_field_defs( $def, $layout ) ) {
		return array(); // No declared shape to judge against; see the docblock's second exclusion.
	}
	$out = array();
	foreach ( $row as $address => $sub_value ) {
		$address = (string) $address;
		if ( 'acf_fc_layout' === $address && 'flexible_content' === $type ) {
			// The layout marker names the row's layout, not a sub-field. The skip MUST stay for
			// flexible content - dropping it refuses every flexible-content write, which a mutant
			// has already demonstrated. It is scoped to that one type because on a repeater, group
			// or clone the key names nothing at all: ACF resolves a row's values against that
			// container's own sub-fields and never reads a marker, so the value is silently
			// dropped. Skipping it there hid the SAME fail-after-write defect at a third trigger,
			// measured against real ACF Pro 6.8.7: a group row sent
			// {alpha, acf_fc_layout} moved alpha from GROUP-BEFORE to GROUP-AFTER and the request
			// still returned an error, and a repeater row behaved identically. Milder than the
			// flexible-content case because the caller's intent does land, but it is the same
			// silent wrong answer. Judging it as an ordinary undeclared address refuses it before
			// the write, which is what the rest of this floor already does for such an address.
			continue;
		}
		$sub = aafm_acf_sub_field_by_address( $def, $address, $layout );
		if ( null === $sub ) {
			$out[] = '' !== $path ? $path . '.' . $address : $address;
			continue;
		}
		$out = array_merge(
			$out,
			aafm_acf_unresolved_sub_addresses( $sub, $sub_value, ( '' !== $path ? $path . '.' : '' ) . $address, $bad_layouts )
		);
	}
	return $out;
}

/**
 * Whether ACF will read a value back under the address the caller wrote it under.
 *
 * This mirrors the branch ACF itself takes, rather than modelling it. acf_maybe_get_field()
 * (api-template.php:300-324) tries three things in order: acf_is_field_key(), which short-circuits
 * to acf_get_field(); then acf_get_meta_field(), an EXACT read of the '_' . $address reference
 * meta; and only when the caller passed $strict false, acf_get_field() on the name. get_field()
 * passes $strict TRUE and update_field() passes FALSE, which is the whole asymmetry: the write can
 * reach the name lookup and the read cannot.
 *
 * So an address reads back in exactly two shapes.
 *
 *   - KEY-SHAPED. acf_is_field_key() is the first branch on both sides, and it leads to the same
 *     acf_get_field() either way, so a key address round-trips whatever that lookup does with it.
 *     Measured on ACF Pro 6.8.7: `field_63f89ebc53c27` with a trailing space still resolves, still
 *     writes, and still reads back, because the value read uses the resolved definition's own name.
 *     Refusing it would have broken a call that works today, which is why this is not simply a
 *     byte-equality check.
 *   - THE DEFINITION'S OWN NAME. update_field() writes the value under $field['name'] and the
 *     reference under '_' . $field['name'], so the exact-read branch finds it only for that string.
 *
 * Anything else resolves for the write and reads back null. On a DB-stored field that set is not
 * empty, because acf_get_field() resolves a name through a database query and the column carries a
 * collation: measured on this site's wp_posts (utf8mb4_unicode_520_ci), `icon` also matches `icon`
 * followed by a space, U+202E, U+200B or BEL, though not by a tab. A PHP-registered (local) field
 * is looked up in an in-memory store by exact key, so none of this reaches it - the trigger is
 * DB-stored versus PHP-registered, not the selector type.
 *
 * The exact-key comparison is not redundant with acf_is_field_key(): it is what keeps a key address
 * working on a fork or an older ACF that does not ship that function, where the function_exists
 * guard would otherwise answer false for every key.
 *
 * Compared against `name` and not `_name` deliberately. acf_validate_field() sets _name = name
 * (acf-field-functions.php), and a top-level clone is the one place the two could diverge except
 * that class-acf-field-clone.php's prepare_field_for_db() bails when name === _name, which is
 * exactly a top-level clone. Both are top-level facts; this function is only ever asked about a
 * top-level address.
 *
 * DOCUMENTED versus OBSERVED, per this project's grounding rule. Documented: field-key addressing
 * is first-class in ACF's own resources (/resources/update_field recommends the key form when
 * saving a value that does not yet exist). OBSERVED ONLY, measured against ACF Pro 6.8.7 and ACF
 * free 6.3.6: that a name resolves collation-fuzzily for the write while the read is exact. ACF
 * documents neither half of that, which is the reason this refuses rather than trying to be clever
 * about it - refusing needs no promise from the vendor at all.
 *
 * The measured standing of each part, so nothing here reads as more load-bearing than it is. The
 * name comparison and the key-shaped branch are both demonstrated: blinding the name comparison
 * reds the exact-name control in both suites, and blinding the key-shaped branch reds the
 * fuzzy-key row against real ACF, which is the over-block direction. The exact-key comparison is
 * demonstrated too, by the unit suite, where acf_is_field_key() is not stubbed and every existing
 * ACF write addresses its field by key: removing it turns 103 passing writes into refusals. Reading
 * _name instead of name SURVIVES both suites and is named EQUIVALENT rather than counted, because
 * the two are the same string for a top-level field and the fixture carries both. The empty-string
 * guards are killed only by a row that floor 1 makes unreachable, so they are DEFENSIVE.
 *
 * @param string              $address The caller's top-level address.
 * @param array<string,mixed> $def     The definition acf_get_field() resolved for it.
 * @return bool True when a write under this address will read back under it.
 */
function aafm_acf_address_reads_back( string $address, array $def ): bool {
	// Both halves are guarded against the empty string on purpose. Without that guard a definition
	// carrying no name would make the EMPTY address acceptable, because '' === '' - the comparison
	// answering true from an absent value rather than from a match. Floor 1 has already refused a
	// definition with no key by the time this runs, so it is defence in depth rather than a
	// reachable case, and it is labelled that way rather than counted as coverage of a live path.
	$name = (string) ( $def['name'] ?? '' );
	$key  = (string) ( $def['key'] ?? '' );
	if ( ( '' !== $name && $address === $name ) || ( '' !== $key && $address === $key ) ) {
		return true;
	}
	return function_exists( 'acf_is_field_key' ) && acf_is_field_key( $address );
}

/**
 * The refusal for a top-level address ACF resolves for the write but cannot read back.
 *
 * Named rather than generic, on the same reasoning as the sub-field refusal below: the echoed
 * string is the caller's own, so it discloses nothing the caller did not supply, and naming it is
 * what lets an agent drop the stray character and retry instead of escalating a bare failure to a
 * human. It runs after the hard block, so a protected key is still refused generically and is never
 * named back here.
 *
 * The message says what to do rather than describing ACF's internals, because the caller cannot act
 * on the collation and can act on "use the field's exact name or its key". It goes through the same
 * bounded, sanitized address list as the two container refusals so there is one copy of that cap.
 *
 * @param string $address The address that resolved inexactly.
 * @return \WP_Error
 */
function aafm_acf_inexact_address_error( string $address ): WP_Error {
	return new WP_Error(
		'aafm_acf_inexact_field_address',
		sprintf(
			/* translators: %s: the field address the caller sent. */
			__( 'This does not exactly name a field, so nothing was written. Send the field\'s exact name or its field key: %s', 'agent-abilities-for-mcp' ),
			implode( ', ', aafm_acf_safe_address_list( array( $address ) ) )
		)
	);
}

/**
 * The refusal for a container write addressing sub-fields the field definition does not declare.
 *
 * Named rather than generic, and it echoes the offending addresses back, unlike the hard-block
 * refusal one line above it. The two are not the same kind of secret. The hard block stays generic
 * because saying which meta key was protected tells a caller something about the site it did not
 * supply; an unrecognised sub-field address is the caller's own string handed straight back, so it
 * discloses nothing the caller did not already have. Naming it is what turns "the request could not
 * be completed" into something an agent can correct on its next turn, which is most of the point of
 * refusing early.
 *
 * An earlier version of this note also argued the names were public anyway, through
 * aafm-acf-list-field-groups. That was wrong: that ability returns key, label and type per TOP-LEVEL
 * field only, so no ability exposes sub-field or layout names. The reason above does not rest on it,
 * because echoing the caller's own input reveals nothing either way, but the claim was untrue and a
 * caveat asserting more than it can support is how this file grew two of its worst defects.
 *
 * The addresses are capped and sanitized before they reach the message. That step now lives in
 * aafm_acf_safe_address_list(), shared with the sibling layout refusal so the bound cannot drift
 * between two messages; its docblock carries the measured standing of each half, including the
 * labelled survivor for the sanitize.
 *
 * @param array<int,string> $addresses The unresolved addresses.
 * @return \WP_Error
 */
function aafm_acf_unknown_sub_field_error( array $addresses ): WP_Error {
	return new WP_Error(
		'aafm_acf_unknown_sub_field',
		sprintf(
			/* translators: %s: comma-separated list of sub-field addresses the caller sent. */
			__( 'These do not name a sub-field of the field they were sent under, so nothing was written: %s', 'agent-abilities-for-mcp' ),
			implode( ', ', aafm_acf_safe_address_list( $addresses ) )
		)
	);
}

/**
 * The bounded, sanitized address list both container refusals put in their message.
 *
 * Extracted so the two refusals share ONE copy of the cap and the strip rather than each carrying
 * its own; a second copy is where this project's "fixed at one call site, never swept" archetype
 * starts, and a cap that drifted apart between two messages would be invisible.
 *
 * The two halves have different standing, measured rather than assumed. The CAP is load-bearing:
 * removing it left the whole corpus green until a row was added for it, so a caller could have had
 * its entire request echoed back. The SANITIZE is DEFENSIVE, not demonstrated load-bearing:
 * removing it changes no row, because both callers walk a value aafm_acf_sanitize_value() already
 * returned and that sanitizer puts every string key through aafm_sanitize_plain_text() at every
 * depth. It stays because this is the last step before caller-derived text becomes a rendered
 * string, and because that upstream guarantee lives in a different function than this one.
 *
 * @param array<int,string> $addresses The raw addresses.
 * @return array<int,string> At most five sanitized, non-empty, de-duplicated addresses.
 */
function aafm_acf_safe_address_list( array $addresses ): array {
	$clean = array();
	foreach ( array_values( array_unique( $addresses ) ) as $address ) {
		// Cast the substr: on PHP 7.4 it returns false rather than '' when it cannot cut, and
		// strict_types would make that a TypeError. Offset zero cannot actually reach that branch,
		// so this is the same belt-and-braces cast the clone prefix helper already carries.
		$safe = aafm_sanitize_plain_text( (string) substr( (string) $address, 0, 200 ) );
		if ( '' !== $safe ) {
			$clean[] = $safe;
		}
		if ( count( $clean ) >= 5 ) {
			break; // A bounded message; the caller can fix these and re-send to see any others.
		}
	}
	return $clean;
}

/**
 * The refusal for a flexible-content row whose layout the field does not declare, or which carries
 * no layout marker at all.
 *
 * Separate from aafm_acf_unknown_sub_field_error() because it is a different claim, and reusing
 * that one would have made the message untrue: it says the listed strings do not name a sub-field,
 * and for a row that simply OMITS its marker the caller sent no such address at all. This release
 * has already had to correct one user-facing string that asserted something untrue, so a message
 * that would only be accurate for half its rows is not an option.
 *
 * It names the ROW, not the layout the caller sent and not the layouts the field declares. The row
 * address is the actionable part and is the same kind of string the sibling refusal already echoes;
 * the declared layout names are site configuration the caller did not supply, and nothing in this
 * plugin's read surface exposes them, so listing them here would disclose rather than help.
 *
 * @param array<int,string> $rows The dotted addresses of the offending rows.
 * @return \WP_Error
 */
function aafm_acf_unknown_layout_error( array $rows ): WP_Error {
	return new WP_Error(
		'aafm_acf_unknown_layout',
		sprintf(
			/* translators: %s: comma-separated list of flexible-content row addresses. */
			__( 'Each flexible content row must name one of the field\'s own layouts in acf_fc_layout. These rows do not, so nothing was written: %s', 'agent-abilities-for-mcp' ),
			implode( ', ', aafm_acf_safe_address_list( $rows ) )
		)
	);
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
 * The bound, stated as measured rather than as intended. All four clone modes above were checked
 * against ACF Pro 6.8.7 by calling acf_clone_field() and this function in memory, with nothing
 * written: each one emits a name that clone's update_value() accepts. What is NOT demonstrated is
 * the end-to-end path, because no clone field is defined on the development site, so no real
 * acf-update-post-fields call has ever been driven through a real clone. That half is modelled by
 * the stub in the write-verify corpus, not observed. A clone that is nested inside another
 * container is also unmeasured; the `_name` handling is the same code, but nobody has driven it.
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
 * It runs over BOTH operands of that comparison, not only storage. The caller's own value goes
 * through it as well, via aafm_acf_rekey_sent_to_names(), because ACF documents and accepts a
 * sub-field addressed by its field KEY and storage always reads back under the name - so a sent key
 * had nothing to match until both sides shared one name-space. Read that function's docblock before
 * changing anything here: this is now a rule two values depend on, not a storage-side detail.
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
 * Rewrite a SENT container value's sub-field addresses to names, so the write-verify compares two
 * values in one name-space instead of two.
 *
 * Deliberately a delegation to aafm_acf_rekey_stored_to_names() and not a second implementation.
 * The transformation both operands need is identical - rewrite sub-field KEYS to the name ACF
 * accepts on write, recursing through each child definition and leaving a flexible-content row's
 * acf_fc_layout marker alone - and the whole defect this closes came from the two sides being
 * normalised by different rules. One function means a future edit cannot desynchronise them, which
 * is a property no test can assert. This exists for the name and the docblock; if the two sides ever
 * genuinely need to diverge, that has to be a deliberate decision made here rather than a drift.
 *
 * WHY THE SENT SIDE NEEDED NORMALISING AT ALL. ACF documents addressing a sub-field by its field
 * KEY: /resources/add_row shows a whole row keyed by sub-field keys ("Add a new row using field
 * keys"), and /resources/update_field states the key form is the one to use when saving a value that
 * does not yet exist - the create case. ACF accepts such an address and writes the row. This
 * plugin's read-back then re-keyed STORAGE to sub-field names while comparing it against the sent
 * KEY, which no re-keyed storage can ever hold, so the projection abandoned, the comparison
 * mismatched and the request reported failure with the data already written. Measured end to end
 * against real ACF free 6.3.6: a sub-field went from `BEFORE` to `AFTER-BY-KEY` on the call that
 * returned WP_Error(aafm_error). The same re-keyer feeds the same comparison in shipped 1.6.3.
 *
 * A STRICT NO-OP FOR EVERY ADDRESS THAT IS ALREADY A NAME. The rewrite is driven by
 * aafm_acf_sub_field_key_map(), which is keyed by sub-field KEY, and an address matching no entry is
 * passed through untouched. So a value addressed entirely by names - every shape this plugin
 * accepted before - comes back identical and the comparison is byte-for-byte what it was. Mixed
 * addressing within one request is handled per address, which is what ACF does too.
 *
 * WHAT IT DOES NOT DO, stated so nobody reads more into it. It never DROPS an address. An address
 * that names nothing the definition declares survives the rewrite unchanged, still finds no match in
 * storage, and still fails the comparison - so this cannot turn a real persistence failure into a
 * reported success. (Such an address is refused before any write by the unresolved-address floor;
 * the pass-through is what keeps that true if it ever were not.) And where a sub-field's key
 * collides with a different sub-field's name, the key wins, matching ACF's own container write path,
 * which reads $value[$sub['key']] before it looks at the name.
 *
 * @param mixed                    $sent The sanitized value the caller asked to write.
 * @param array<string,mixed>|null $def  The field definition, or null when it cannot be resolved.
 * @return mixed The value with container sub-field keys rewritten to names.
 */
function aafm_acf_rekey_sent_to_names( $sent, ?array $def ) {
	return aafm_acf_rekey_stored_to_names( $sent, $def );
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
 *     sub-field that failed to persist still fails. This one is DEFENSIVE, not load-bearing, and
 *     the distinction is measured rather than assumed: deleting it leaves the whole regression
 *     corpus green, because the missing key reads back as null and then mismatches the sent value
 *     anyway. Attempts to build a row that dies without it (sending '', false, and array values)
 *     all still refuse. What it actually contributes is avoiding an undefined-array-key read, and
 *     saying so plainly beats leaving a comment that claims a guarantee nothing demonstrates.
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
 * Apply a sanitized field map to an object selector via update_field(), then return the object's
 * refreshed values so the agent sees the current state rather than an echo of its own input. Note
 * that return is ACF's FORMATTED read (aafm_acf_read_fields() -> get_fields()), which is not the
 * stored shape for image, file, date and relationship fields; the verify below is what compares
 * against storage, and it reads raw.
 *
 * Every key is gated up front, before any write lands - four floors, all required:
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
 *   3. Every address INSIDE a container value must name a sub-field that container declares.
 *      ACF resolves a row's values against its own sub-field definitions and never reads an
 *      address matching none of them, so it writes the sub-fields it recognises and silently
 *      ignores the rest - and the read-back verify below then cannot find the ignored address in
 *      storage and reports the whole request as failed, AFTER the recognised sub-fields have
 *      already landed. Refusing before any write is what makes the report true, and it makes the
 *      sub-field level behave the way an unresolved top-level key already behaves. See
 *      aafm_acf_unresolved_sub_addresses() for what is deliberately not flagged.
 *   4. The top-level address must be one ACF will READ BACK, not merely one it resolves for the
 *      write. Listed fourth because it was added last; it RUNS between floors 2 and 3, so the hard
 *      block still refuses a protected key generically first. ACF looks a name up for the write
 *      through a database query and reads the value back through an exact meta read, so on a
 *      DB-stored field an address that merely collates equal (a trailing space is enough) wrote
 *      successfully and then read back as null, and the verify below reported failure on a write
 *      that had landed. See aafm_acf_address_reads_back() for the two shapes that do read back and
 *      for why this is not byte equality.
 *
 * A single offending key rejects the whole request with a WP_Error before any update_field()
 * runs - matching this function's single-error convention (the verify step below likewise
 * collapses any non-persisting write to one generic error) and avoiding a partial write when any key
 * in the map is rejected. Floors 1 and 2 refuse generically, because what they refuse says something
 * about the site; floors 3 and 4 name the caller's own addresses so an agent can correct them.
 * Legitimate, defined fields are written and verified exactly as before.
 *
 * After every field is written its stored value is read back and compared to the sanitized value
 * written, so a failed update_field() that stored nothing is never audited as a success.
 *
 * update_field() DOES have a documented return value and this code deliberately does not use it as
 * the success signal. ACF documents it as "Meta ID if the field value didn't exist and the field is
 * not being saved as an option. Otherwise true on successful update, false on failure"
 * (advancedcustomfields.com/resources/update_field). It cannot answer the question this verify
 * asks. For a container it reports on the write as a whole, so a row whose recognised sub-fields
 * all persisted returns success whatever else was in the request, and it says nothing at all about
 * what storage now holds after ACF's own typing, hydration and re-keying. The read-back is used
 * because it is the only thing that compares storage against intent, not because no return signal
 * exists. Two things make that comparison honest. First, it respects ACF's stored typing (aafm_acf_normalize_stored):
 * ACF persists through update_metadata(), so a numeric value reads back a numeric string and a
 * boolean reads back '1'/'' - demanding JSON-exact equality would wrongly fail those type-normalizing
 * writes even though the value did persist, while a no-op write of an unchanged value still matches.
 * Second, the loop does NOT abort on the first mismatch: every field in the map is written and
 * verified, so one field that fails to persist can never silently skip the fields that follow it. The
 * request reports failure only after all fields have been attempted.
 *
 * Both sides of that comparison are first put into ONE name-space. Storage reads back keyed by
 * sub-field key, and the caller may legitimately have addressed a sub-field by its key too - ACF
 * documents that form and writes the row for it - so the sent value goes through the same
 * key-to-name rewrite as storage (aafm_acf_rekey_sent_to_names). Without that, a by-key write
 * landed and was then reported as a failure, because the sent address could never appear in
 * re-keyed storage. The rewrite is a strict no-op for a value addressed by names.
 *
 * What the verify GUARANTEES, stated exactly: for every value the caller sent - each field, each row
 * index of a sent list, each sub-field of a sent row, recursively, addressed by name or by key -
 * storage holds that value after the write, allowing for ACF's storage typing; and a sent list holds
 * exactly as many rows as were sent. A value that differs, a sent sub-field storage does not hold,
 * or a row count that does not match, all still report failure.
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
	// field, or any meta key the write would land on is hard-blocked. Fail-closed - if
	// acf_get_field() is somehow unavailable while update_field() exists, no key can resolve and
	// every write is refused.
	//
	// The key set comes from aafm_acf_effective_meta_keys(), which walks the caller's own value
	// against the definition and derives every key ACF would write, sub-fields included. Testing
	// the top-level name alone was not enough: a container writes one row per sub-field, and a
	// top-level clone with prefix_name off writes them under their own bare names, so a sub-field
	// named after a protected key reached it. Every key refused here is a key this same block
	// already refuses when the field carrying that name is addressed directly, so this closes a
	// hole rather than inventing a new class of refusal.
	$cleaned = array();
	foreach ( $fields as $field_key => $raw ) {
		$field_key = (string) $field_key;
		$def       = function_exists( 'acf_get_field' ) ? acf_get_field( $field_key ) : false;
		if ( ! is_array( $def ) || empty( $def['key'] ) ) {
			return aafm_generic_error(); // Unresolved key - the update_field() raw-meta fallback path.
		}

		// Sanitize ONCE, here, so every floor below judges the very value the write will use.
		// The sanitizer rewrites array KEYS as well as values - aafm_acf_sanitize_leaf() puts every
		// string key through aafm_sanitize_plain_text() at every depth - so deriving keys from $raw
		// while handing $clean to update_field() checked a different object than it wrote. That gap
		// was a live escalation, reproduced on a user selector against a top-level unprefixed
		// clone: an address of `wp_capabilities` carrying a U+202E matched no sub-field while raw,
		// so the derivation produced no key and the hard block had nothing to refuse, and the strip
		// then handed ACF the bare protected name. Refused without the character, WRITTEN with it.
		// Judging $raw instead could only ever over-block, because $raw is never what gets stored.
		//
		// THE BOUND IS A RULE, NOT A LIST OF CHARACTERS, and this is the formulation to keep:
		//
		// the gap was open iff sanitize( address ) resolves to a declared sub-field
		// while the RAW address does not.
		//
		// Enumerating characters here would be a caveat doing work it cannot do, because
		// aafm_sanitize_plain_text() is str_replace( aafm_unsafe_text_characters(), '',
		// sanitize_text_field( $value ) ) and CORE TRANSFORMS THE STRING FIRST. The cheapest way in
		// was not an exotic character at all: `wp_capabilities` with a TRAILING SPACE, which core
		// trims. Typo-shaped input, no Trojan Source needed. Also measured as doors on the same
		// address, illustrative rather than exhaustive: a leading space, a tab, a newline, a NUL, an
		// HTML tag at either END, and a percent-encoded octet - every one of them core's doing
		// before this plugin's list is consulted - plus U+202E and BEL, which are the list's.
		//
		// The rule is what makes the fix complete without the count being known. Computing $clean
		// once closes every case by construction, because the floors judge the post-sanitize value
		// whatever produced it. Two controls show this is a boundary and not "refuse anything odd":
		// U+200B SURVIVES sanitization, so the cleaned address still resolves to nothing, and
		// `<b>x</b>` sanitizes to `x`, a DIFFERENT name that resolves to nothing either. Both are
		// correctly refused, and by the unknown-sub-field floor below rather than by this one. A
		// character that survives is caught there; a transformation that lands on the protected name
		// is caught here.
		//
		// $def is passed down rather than re-resolved. acf_get_field() runs the `acf/load_field`
		// filter chain, so it is not a pure function of its key; what makes repeated calls agree is
		// ACF's per-request field store, measured, not any property of the function. Resolving once
		// per field removes that dependency entirely - the same move, and the same reason, as
		// computing $clean once.
		$clean = aafm_acf_sanitize_value( $raw, $field_key, $def );

		$candidates = array_merge( array( $field_key ), aafm_acf_effective_meta_keys( $def, $clean ) );
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && aafm_acf_meta_key_hard_blocked( $candidate, $selector_type ) ) {
				return aafm_generic_error(); // Defense in depth: protected meta key.
			}
		}

		// Fourth floor, numbered fourth because it was added last but running HERE, between the hard
		// block and the sub-address walk: a protected key is still refused generically before
		// anything names the caller's address back, and a problem with the outer address is
		// reported before any complaint about what was inside it.
		//
		// The address must be one ACF will READ BACK, not merely one it will resolve for the write.
		// Those are different questions, and ACF answers them with different machinery:
		// update_field() calls acf_maybe_get_field() with $strict false, which falls through to
		// acf_get_field() and a DATABASE lookup on the field's name, while get_field() calls it with
		// $strict true, which stops at acf_get_meta_field() and an EXACT postmeta read of
		// '_' . $address (api-template.php:300-324). A database lookup inherits the column's
		// collation; a meta read does not. So on a DB-stored field an address that merely collates
		// equal - a trailing space is enough - resolves for the write and reads back as null, and
		// this function's verify-after-write then reported failure on a write that had landed.
		//
		// Refusing before the write is what makes that report true. Nothing that succeeds today
		// starts failing, and this is provable rather than enumerated: for an address that is not
		// the definition's own name, get_field() returns null, so the comparison below is against
		// the JSON `null`, and aafm_acf_sanitize_value() never yields null - it drops null, objects
		// and resources to an empty string (aafm_acf_sanitize_leaf(), :491). The two sides could
		// therefore never match, so every such call already reported failure. What changes is only
		// what is left behind: the database is now untouched when we say the write did not happen.
		//
		// The alternative - reading back through the definition ACF resolved, so the fuzzy write is
		// reported as the success it was - was rejected. It would make our success verdict depend on
		// the site's database collation, so the same request would succeed or be refused according
		// to how wp_posts was created, and it would report success without telling the caller which
		// field actually changed. Never handing ACF the inexact address is robust either way.
		if ( ! aafm_acf_address_reads_back( $field_key, $def ) ) {
			return aafm_acf_inexact_address_error( $field_key );
		}

		// Third floor: every address inside a container must name a sub-field the definition
		// declares, because ACF silently ignores one that does not while still writing the rest -
		// which used to report the whole request as failed AFTER the recognised sub-fields had
		// already landed.
		//
		// The same walk also reports flexible-content rows whose layout the field does not declare.
		// Those are refused FIRST and with their own error, because they are the worse half: an
		// unresolvable layout does not merely go unwritten, it makes ACF replace the field's whole
		// value, so the row's existing sub-field content is DELETED on the call that reports
		// failure. Sub-field complaints about a row whose layout cannot resolve would be noise
		// anyway, since the layout is what decides which sub-fields the row even has.
		$bad_layouts = array();
		$unresolved  = aafm_acf_unresolved_sub_addresses( $def, $clean, '', $bad_layouts );
		if ( array() !== $bad_layouts ) {
			return aafm_acf_unknown_layout_error( $bad_layouts );
		}
		if ( array() !== $unresolved ) {
			return aafm_acf_unknown_sub_field_error( $unresolved );
		}

		// Carry the ONE resolved definition forward with the value, so the verify below judges the
		// same definition the floors did.
		$cleaned[ $field_key ] = array(
			'def'   => $def,
			'value' => $clean,
		);
	}

	$failed = array();
	foreach ( $cleaned as $field_key => $entry ) {
		$def   = $entry['def'];
		$clean = $entry['value'];
		// update_field() persists through update_metadata()/update_option(), both of which unslash
		// the value, so a backslash in a value (C:\Users) is stripped one level unless it is slashed
		// first. Every sibling meta writer (meta.php, terms.php, user-meta.php, the SEO writers)
		// slashes; this ACF writer must too - without it the read-back verify below then reports the
		// mangled persist as a failed write. The verify keeps comparing against the unslashed $clean,
		// which is exactly what storage holds after the round trip.
		update_field( (string) $field_key, wp_slash( $clean ), $selector );

		// Verify the write persisted. update_field()'s documented int|bool return is not the signal
		// used here: for a container it reports the write as a whole, so it cannot say what storage
		// actually holds afterwards. A failed update_field() stores nothing, so the read-back
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
			// Both operands go through the SAME key-to-name rule before they meet. ACF documents
			// addressing a sub-field by its field KEY (/resources/add_row keys a whole row that
			// way), accepts it, and writes the row - but storage then reads back under the
			// sub-field's name, so a caller who used a key was comparing its own address against a
			// name that could never match. The projection abandoned, the comparison mismatched, and
			// the request reported failure AFTER the write had landed. Normalising the sent value
			// too is what makes the two sides the same name-space; see aafm_acf_rekey_sent_to_names.
			$expected = aafm_acf_rekey_sent_to_names( $clean, $def );
			$stored   = aafm_acf_rekey_stored_to_names( $stored, $def );
			$stored   = aafm_acf_project_stored_onto_sent( $stored, $expected );
			$as_read  = wp_json_encode( aafm_acf_canonicalize_for_compare( $stored ) );
			$as_sent  = wp_json_encode( aafm_acf_canonicalize_for_compare( $expected ) );
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
