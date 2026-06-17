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
