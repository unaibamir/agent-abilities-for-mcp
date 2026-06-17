<?php
/**
 * Governed post-meta abilities (read + write). Every meta operation passes the
 * shared aafm_can_access_post_meta() gate: the post must be editable by the agent
 * (Unit 1 per-object resolver) AND the key must clear the hard-block + allowlist.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_meta_definitions' );

/**
 * Contribute the post-meta definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_meta_definitions( array $registry ): array {
	$registry['aafm/get-post-meta']     = array(
		'label'        => __( 'Get post meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Read a single allowlisted meta value from a post the agent can edit (scalar only).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_post_meta',
	);
	$registry['aafm/get-all-post-meta'] = array(
		'label'        => __( 'Get all post meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Read every allowlisted scalar meta value from a post the agent can edit, returned as a key/value map. Protected, underscore, and non-scalar values are excluded.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_all_post_meta',
	);
	$registry['aafm/update-post-meta']  = array(
		'label'        => __( 'Update post meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Write a single allowlisted scalar meta value to a post the agent can edit.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_update_post_meta',
	);
	$registry['aafm/delete-post-meta']  = array(
		'label'        => __( 'Delete post meta', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Delete an allowlisted meta key from a post the agent can edit. Removes all values of that key.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_delete_post_meta',
	);
	return $registry;
}

/**
 * Shared meta gate: the post must be editable by the agent (Unit 1 resolver) AND the key
 * must clear the hard-block + allowlist. Used by all three meta permission callbacks.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_can_access_post_meta( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || ! aafm_can_edit_post_object( $post ) ) {
		return false;
	}
	$key = isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '';
	return ! is_wp_error( aafm_validate_meta_key( $key ) );
}

/**
 * Args for aafm/get-post-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_post_meta(): array {
	return array(
		'label'               => __( 'Get post meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Read a single allowlisted meta value from a post the agent can edit (scalar only).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'meta_key' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'post_id', 'meta_key' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'meta_key' => array( 'type' => 'string' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
				'value'    => array(
					'type' => array( 'string', 'number', 'boolean', 'null' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_post_meta',
		'permission_callback' => 'aafm_perm_get_post_meta',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/get-post-meta: the shared per-object + per-key gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_get_post_meta( array $input ): bool {
	return aafm_can_access_post_meta( $input );
}

/**
 * Execute aafm/get-post-meta.
 *
 * Re-validates the key (defence in depth — the permission callback already gated it),
 * then reads a single value. Non-scalar values are refused so a serialized array/object
 * can never be dumped to the agent.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_post_meta( array $input ) {
	$id  = absint( $input['post_id'] );
	$key = aafm_validate_meta_key( isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '' );
	if ( is_wp_error( $key ) || ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$value = get_post_meta( $id, $key, true );
	if ( '' !== $value && ! is_scalar( $value ) ) {
		return aafm_generic_error(); // never dump arrays/serialized blobs.
	}
	return array(
		'post_id'  => $id,
		'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response array key, not a meta query.
		'value'    => $value,
	);
}

/**
 * Args for aafm/get-all-post-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_all_post_meta(): array {
	return array(
		'label'               => __( 'Get all post meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Read every allowlisted scalar meta value from a post the agent can edit (key/value map).', 'agent-abilities-for-mcp' ),
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
				'meta' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_all_post_meta',
		'permission_callback' => 'aafm_perm_get_all_post_meta',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/get-all-post-meta: per-object edit_post only.
 *
 * Unlike the single get-post-meta gate, there is no meta_key to validate here — the bulk
 * read iterates the allowlist itself. So this checks the post is editable by the agent
 * (the same Unit 1 per-object resolver), and the execute body enforces the key allowlist
 * and scalar-only floor for each value.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_get_all_post_meta( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
}

/**
 * Execute aafm/get-all-post-meta.
 *
 * Iterates the post-meta allowlist (aafm_allowed_meta_keys, already hard-block-floored).
 * For each present key, returns its single scalar value; keys with no value, or whose
 * stored value is non-scalar (a serialized array/object), are skipped so nothing
 * unsanitized or structured is ever dumped to the agent. Default-deny by construction:
 * with no allowlist configured the map is empty.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_all_post_meta( array $input ) {
	$id = absint( $input['post_id'] );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$meta = array();
	foreach ( aafm_allowed_meta_keys() as $key ) {
		$value = get_post_meta( $id, $key, true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- allowlisted key, bounded loop.
		// Deliberately SKIPS empty/missing keys (a bulk map omits absent keys),
		// unlike the single get-post-meta reader which returns an empty value as-is.
		// The opposite-looking phrasing is intentional; both keep a stored '0'.
		if ( '' === $value || ! is_scalar( $value ) ) {
			continue; // skip empty/missing and never dump arrays/serialized blobs.
		}
		$meta[ $key ] = $value;
	}

	return array(
		// Cast so an empty map JSON-encodes to "{}" (object) per the schema.
		'meta' => (object) $meta,
	);
}

/**
 * Args for aafm/update-post-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_post_meta(): array {
	return array(
		'label'               => __( 'Update post meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Write a single allowlisted scalar meta value to a post the agent can edit.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'meta_key' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
					'type'      => 'string',
					'minLength' => 1,
				),
				'value'    => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
			'required'             => array( 'post_id', 'meta_key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'meta_key' => array( 'type' => 'string' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
				'value'    => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_update_post_meta',
		'permission_callback' => 'aafm_perm_update_post_meta',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/update-post-meta: the shared per-object + per-key gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_update_post_meta( array $input ): bool {
	return aafm_can_access_post_meta( $input );
}

/**
 * Execute aafm/update-post-meta.
 *
 * Re-validates the key, refuses non-scalar values via aafm_sanitize_meta_value(),
 * then writes a single value. wp_slash() guards update_post_meta()'s internal unslash.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_post_meta( array $input ) {
	$id  = absint( $input['post_id'] );
	$key = aafm_validate_meta_key( isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '' );
	if ( is_wp_error( $key ) || ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$value = aafm_sanitize_meta_value( $key, $input['value'] ?? '' );
	if ( is_wp_error( $value ) ) {
		return $value;
	}
	if ( false === update_post_meta( $id, $key, wp_slash( $value ) ) ) {
		// update_post_meta returns false on a same-value no-op too. Meta round-trips through a
		// longtext column, so the stored value reads back as a string; compare stringified forms
		// to avoid a false failure on a genuine no-op (e.g. re-sending an int or bool).
		if ( (string) get_post_meta( $id, $key, true ) !== (string) $value ) {
			return aafm_generic_error();
		}
	}
	$stored = get_post_meta( $id, $key, true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-key read-back of the just-written value, not a meta query.
	return array(
		'post_id'  => $id,
		'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response array key, not a meta query.
		'value'    => $stored,
	);
}

/**
 * Args for aafm/delete-post-meta.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_post_meta(): array {
	return array(
		'label'               => __( 'Delete post meta', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Delete an allowlisted meta key from a post the agent can edit. Removes all values of that key.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'meta_key' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'post_id', 'meta_key' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_post_meta',
		'permission_callback' => 'aafm_perm_delete_post_meta',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/delete-post-meta: the shared per-object + per-key gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_delete_post_meta( array $input ): bool {
	return aafm_can_access_post_meta( $input );
}

/**
 * Execute aafm/delete-post-meta.
 *
 * Re-validates the key (defence in depth — the permission callback already gated it),
 * then removes every value of that key. delete_post_meta() with no value arg deletes
 * all values of the key, which is the intended destructive behaviour here.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_post_meta( array $input ) {
	$id  = absint( $input['post_id'] );
	$key = aafm_validate_meta_key( isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '' );
	if ( is_wp_error( $key ) || ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	delete_post_meta( $id, $key );
	return array( 'deleted' => true );
}
