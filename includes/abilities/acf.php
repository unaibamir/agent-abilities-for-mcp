<?php
/**
 * ACF / SCF integration abilities — hydrated custom-field reads and writes (slice W4-A).
 *
 * Registers ONLY when ACF (or its Secure Custom Fields fork) is active
 * (aafm_integration_active('acf')); a host-inactive site contributes zero entries to the
 * registry. Field VALUES are read and written through ACF's own get_fields()/get_field()/
 * update_field() so a field's Return Format and storage are honoured. Every per-object ability
 * gates on the object's own edit capability: post fields on edit_post($id), term fields on
 * edit_term($term_id), user fields on edit_user($user_id). User fields may include a
 * user_email-type field; that PII is returned as-is under the disclaimer — the edit_user gate,
 * default-OFF, and audit are the governance, NOT a redactor (mirrors the Wave-2 "user email
 * exposed by default" locked decision).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_acf_definitions' );

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

	$registry['aafm/acf-list-field-groups']  = array(
		'label'        => __( 'List ACF field groups', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the ACF field groups and the fields inside each (key, label, and type) for discovery. It returns structure only, never stored values. Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_list_field_groups',
	);
	$registry['aafm/acf-get-post-fields']    = array(
		'label'        => __( 'Get post ACF fields', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads all of a post's ACF field values, hydrated by field key. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_get_post_fields',
	);
	$registry['aafm/acf-update-post-fields'] = array(
		'label'        => __( 'Update post ACF fields', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes ACF field values on a post by field key, each value sanitized for its field type. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_update_post_fields',
	);
	$registry['aafm/acf-get-term-fields']    = array(
		'label'        => __( 'Get term ACF fields', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads all of a term's ACF field values, hydrated by field key. Requires edit access to that term.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_get_term_fields',
	);
	$registry['aafm/acf-update-term-fields'] = array(
		'label'        => __( 'Update term ACF fields', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes ACF field values on a term by field key, each value sanitized for its field type. Requires edit access to that term.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_update_term_fields',
	);
	$registry['aafm/acf-get-user-fields']    = array(
		'label'        => __( 'Get user ACF fields', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads all of a user's ACF field values, hydrated by field key. A field of the user_email type returns the real email address under the integration disclaimer. Requires edit access to that user.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_get_user_fields',
	);
	$registry['aafm/acf-update-user-fields'] = array(
		'label'        => __( 'Update user ACF fields', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes ACF field values on a user by field key, each value sanitized for its field type. Requires edit access to that user.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'acf',
		'args_builder' => 'aafm_args_acf_update_user_fields',
	);

	return $registry;
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
		'label'               => __( 'List ACF field groups', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists ACF field groups and their fields (key, label, type) for discovery — structure only, no values. Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
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
						'type'       => 'object',
						'properties' => array(
							'key'    => array( 'type' => 'string' ),
							'title'  => array( 'type' => 'string' ),
							'fields' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'key'   => array( 'type' => 'string' ),
										'label' => array( 'type' => 'string' ),
										'type'  => array( 'type' => 'string' ),
									),
								),
							),
						),
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
			),
		),
	);
}

/**
 * Execute aafm/acf-list-field-groups.
 *
 * Walks every field group and its fields, returning only the discovery shape (key, label, type) —
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
 * data yields an empty map (get_fields returns false/empty). PII is returned as-is — the per-object
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
 * Recursively sanitize one ACF field value for writing.
 *
 * Scalars are sanitized by the field's resolved type: a URL-type value through esc_url_raw, a
 * wysiwyg/textarea value through wp_kses_post, everything else through sanitize_text_field. Arrays
 * (repeaters, relationships, nested groups) recurse — every leaf is sanitized, so a script payload
 * cannot survive at any depth. Values that are neither scalar nor array (objects/resources) are
 * dropped. Caller input is NEVER passed to update_field() unsanitized.
 *
 * The field type is resolved once for the top-level field key; nested leaves reuse that type, which
 * is the conservative choice (a repeater's sub-fields are sanitized as text/url unless the parent is
 * a url type). This favours stripping over trusting.
 *
 * @param mixed  $value     Raw caller value.
 * @param string $field_key The top-level field key (to resolve its type).
 * @return mixed Sanitized value.
 */
function aafm_acf_sanitize_value( $value, string $field_key ) {
	$type = '';
	if ( function_exists( 'acf_get_field' ) ) {
		$def  = acf_get_field( $field_key );
		$type = is_array( $def ) ? (string) ( $def['type'] ?? '' ) : '';
	}
	return aafm_acf_sanitize_leaf( $value, $type );
}

/**
 * The depth-recursing core of the ACF write sanitizer (the resolved field type is carried down).
 *
 * @param mixed  $value Raw value at this depth.
 * @param string $type  Resolved ACF field type for the top-level field.
 * @return mixed Sanitized value.
 */
function aafm_acf_sanitize_leaf( $value, string $type ) {
	if ( is_array( $value ) ) {
		$clean = array();
		foreach ( $value as $key => $sub ) {
			$safe_key           = is_string( $key ) ? sanitize_text_field( $key ) : $key;
			$clean[ $safe_key ] = aafm_acf_sanitize_leaf( $sub, $type );
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
	if ( in_array( $type, aafm_acf_url_field_types(), true ) ) {
		return esc_url_raw( $as_string );
	}
	if ( in_array( $type, aafm_acf_wysiwyg_field_types(), true ) ) {
		return wp_kses_post( $as_string );
	}
	return sanitize_text_field( $as_string );
}

/**
 * Apply a sanitized field map to an object selector via update_field(), then return the refreshed
 * read shape so the agent sees ground truth after the write.
 *
 * @param array<string,mixed> $fields   Caller field map: field key => raw value.
 * @param int|string          $selector ACF object selector.
 * @return array<string,mixed> The refreshed hydrated values, keyed by field key.
 */
function aafm_acf_write_fields( array $fields, $selector ): array {
	if ( function_exists( 'update_field' ) ) {
		foreach ( $fields as $field_key => $raw ) {
			$clean = aafm_acf_sanitize_value( $raw, (string) $field_key );
			update_field( (string) $field_key, $clean, $selector );
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
	$id = absint( $input['post_id'] ?? 0 );
	return $id > 0 && get_post( $id ) instanceof WP_Post && current_user_can( 'edit_post', $id );
}

/**
 * Args for aafm/acf-get-post-fields.
 *
 * @return array<string,mixed>
 */
function aafm_args_acf_get_post_fields(): array {
	return array(
		'label'               => __( 'Get post ACF fields', 'agent-abilities-for-mcp' ),
		'description'         => __( "Reads all of a post's ACF field values, hydrated by field key. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
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
				'fields'  => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_acf_get_post_fields',
		'permission_callback' => 'aafm_perm_acf_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
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
		'fields'  => aafm_acf_read_fields( $id ),
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
		'label'               => __( 'Update post ACF fields', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Writes ACF field values on a post by field key, each value sanitized for its field type. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
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
				'fields'  => array( 'type' => 'object' ),
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
	return array(
		'post_id' => $id,
		'fields'  => aafm_acf_write_fields( $fields, $id ),
	);
}
