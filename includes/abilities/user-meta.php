<?php
/**
 * Governed user-meta abilities (read + write + delete). Every operation passes the same
 * two gates: the target user must be editable by the agent (per-object edit_user($id)) AND
 * the key must clear the auth-key hard-block + the default-deny allowlist
 * (aafm_validate_user_meta_key). The hard-block is CVE-class — session tokens, application
 * passwords, capability/user-level keys, and 2FA/reset keys can never be read, written, or
 * deleted, even when a filter tries to allowlist them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_user_meta_definitions' );

/**
 * Contribute the user-meta definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_user_meta_definitions( array $registry ): array {
	$registry['aafm/get-user-meta']    = array(
		'label'        => __( 'Get user meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Read a single allowlisted scalar meta value from a user the agent can edit. Auth, capability, and 2FA keys are never readable.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_get_user_meta',
	);
	$registry['aafm/update-user-meta'] = array(
		'label'        => __( 'Update user meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Write a single allowlisted scalar meta value to a user the agent can edit. Auth, capability, and 2FA keys are blocked outright.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_update_user_meta',
	);
	$registry['aafm/delete-user-meta'] = array(
		'label'        => __( 'Delete user meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Delete an allowlisted meta key from a user the agent can edit. Removes all values of that key. Auth and capability keys can never be touched.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_delete_user_meta',
	);
	return $registry;
}

/**
 * Shared user-meta gate: the target user must be editable by the agent (per-object
 * edit_user($id)) AND the key must clear the hard-block + allowlist. Used by all three
 * user-meta permission callbacks. Reads are gated identically to writes because user meta
 * can hold private data.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_can_access_user_meta( array $input ): bool {
	$id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	if ( $id < 1 || ! current_user_can( 'edit_user', $id ) ) {
		return false;
	}
	$key = isset( $input['key'] ) ? (string) $input['key'] : '';
	return ! is_wp_error( aafm_validate_user_meta_key( $key ) );
}

/**
 * Args for aafm/get-user-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_user_meta(): array {
	return array(
		'label'               => __( 'Get user meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Read a single allowlisted scalar meta value from a user the agent can edit.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'     => array(
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'user_id', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array( 'type' => 'integer' ),
				'key'     => array( 'type' => 'string' ),
				'value'   => array(
					'type' => array( 'string', 'number', 'boolean', 'null' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_user_meta',
		'permission_callback' => 'aafm_perm_user_meta_access',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Args for aafm/update-user-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_user_meta(): array {
	return array(
		'label'               => __( 'Update user meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Write a single allowlisted scalar meta value to a user the agent can edit.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'     => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'value'   => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
			'required'             => array( 'user_id', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array( 'type' => 'integer' ),
				'key'     => array( 'type' => 'string' ),
				'value'   => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_update_user_meta',
		'permission_callback' => 'aafm_perm_user_meta_access',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Args for aafm/delete-user-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_user_meta(): array {
	return array(
		'label'               => __( 'Delete user meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Delete an allowlisted meta key from a user the agent can edit. Removes all values of that key.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'     => array(
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'user_id', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_user_meta',
		'permission_callback' => 'aafm_perm_user_meta_access',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for all three user-meta abilities: the shared per-object + per-key gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_user_meta_access( array $input ): bool {
	return aafm_can_access_user_meta( $input );
}

/**
 * Execute aafm/get-user-meta.
 *
 * Re-validates the key (defence in depth — the permission callback already gated it),
 * confirms the user exists, then reads a single value. Non-scalar values are refused so a
 * serialized array/object stored under an allowlisted key can never leak its structure (M3).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_user_meta( array $input ) {
	$id  = absint( $input['user_id'] );
	$key = aafm_validate_user_meta_key( isset( $input['key'] ) ? (string) $input['key'] : '' );
	if ( is_wp_error( $key ) || ! get_userdata( $id ) instanceof WP_User ) {
		return aafm_generic_error();
	}
	$value = get_user_meta( $id, $key, true );
	if ( '' !== $value && ! is_scalar( $value ) ) {
		return aafm_generic_error(); // never dump arrays/serialized blobs.
	}
	return array(
		'user_id' => $id,
		'key'     => $key,
		'value'   => $value,
	);
}

/**
 * Execute aafm/update-user-meta.
 *
 * Re-validates the key, refuses non-scalar values via aafm_sanitize_user_meta_value(),
 * then writes a single value. wp_slash() guards update_user_meta()'s internal unslash. The
 * stored value reads back as a longtext string, so the no-op guard compares stringified
 * forms to avoid a false failure when an int/bool is re-sent unchanged.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_user_meta( array $input ) {
	$id  = absint( $input['user_id'] );
	$key = aafm_validate_user_meta_key( isset( $input['key'] ) ? (string) $input['key'] : '' );
	if ( is_wp_error( $key ) || ! get_userdata( $id ) instanceof WP_User ) {
		return aafm_generic_error();
	}
	$value = aafm_sanitize_user_meta_value( $key, $input['value'] ?? '' );
	if ( is_wp_error( $value ) ) {
		return $value;
	}
	if ( false === update_user_meta( $id, $key, wp_slash( $value ) ) ) {
		// update_user_meta returns false on a same-value no-op too. User meta round-trips
		// through a longtext column, so the stored value reads back as a string; compare
		// stringified forms to avoid a false failure on a genuine no-op (e.g. re-sending an
		// int or bool).
		if ( (string) get_user_meta( $id, $key, true ) !== (string) $value ) {
			return aafm_generic_error();
		}
	}
	$stored = get_user_meta( $id, $key, true );
	return array(
		'user_id' => $id,
		'key'     => $key,
		'value'   => $stored,
	);
}

/**
 * Execute aafm/delete-user-meta.
 *
 * Re-validates the key (defence in depth — the permission callback already gated it),
 * then removes every value of that key. delete_user_meta() with no value arg deletes all
 * values of the key, which is the intended destructive behaviour here.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_user_meta( array $input ) {
	$id  = absint( $input['user_id'] );
	$key = aafm_validate_user_meta_key( isset( $input['key'] ) ? (string) $input['key'] : '' );
	if ( is_wp_error( $key ) || ! get_userdata( $id ) instanceof WP_User ) {
		return aafm_generic_error();
	}
	delete_user_meta( $id, $key );
	return array( 'deleted' => true );
}
