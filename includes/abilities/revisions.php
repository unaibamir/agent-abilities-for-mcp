<?php
/**
 * Governed revision abilities. Every revision operation passes the shared
 * aafm_revision_parent_editable() gate: the parent post must be editable by the agent
 * (Unit 1 per-object resolver). Revisions are exposed metadata-only — the redactor never
 * returns post_content, so no raw body reaches the agent through this surface.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_revisions_definitions' );

/**
 * Contribute the revision definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_revisions_definitions( array $registry ): array {
	$registry['aafm/list-revisions']   = array(
		'label'        => __( 'List revisions', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List the revisions of a post the agent can edit (metadata only — no body content).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_list_revisions',
	);
	$registry['aafm/get-revision']     = array(
		'label'        => __( 'Get revision', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Get a single revision of a post the agent can edit (metadata only — no body content).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_revision',
	);
	$registry['aafm/restore-revision'] = array(
		'label'        => __( 'Restore revision', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Restore a post to one of its revisions. The current state is first saved as a fresh revision, so the restore is reversible.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_restore_revision',
	);
	return $registry;
}

/**
 * Shared gate: the parent post must be editable by the agent (Unit 1 resolver).
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_revision_parent_editable( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
}

/**
 * Args for aafm/list-revisions.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_revisions(): array {
	return array(
		'label'               => __( 'List revisions', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List the revisions of a post the agent can edit (metadata only — no body content).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'revisions' => array( 'type' => 'array' ),
				'total'     => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_list_revisions',
		'permission_callback' => 'aafm_perm_list_revisions',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/list-revisions: the shared parent-editability gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_list_revisions( array $input ): bool {
	return aafm_revision_parent_editable( $input );
}

/**
 * Execute aafm/list-revisions.
 *
 * Returns the post's revisions newest-first, paginated, each reduced to the
 * metadata-only shape by aafm_redact_revision(). Autosaves are included (both are
 * post_type=revision); that is intentional — the redactor exposes no body regardless.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_list_revisions( array $input ) {
	$id = absint( $input['post_id'] );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$all    = wp_get_post_revisions( $id, array( 'fields' => 'ids' ) );
	$paging = aafm_paginate_args( $input, 50 );
	$slice  = array_slice( array_values( $all ), ( $paging['page'] - 1 ) * $paging['per_page'], $paging['per_page'] );
	$rows   = array();
	foreach ( $slice as $rid ) {
		// $rid is a revision id ('fields' => 'ids'); get_post() resolves it without the
		// pass-by-reference constraint of wp_get_post_revision() and stays analyzer-clean.
		$rev = get_post( $rid );
		if ( $rev instanceof WP_Post && 'revision' === $rev->post_type ) {
			$rows[] = aafm_redact_revision( $rev );
		}
	}
	return array(
		'revisions' => $rows,
		'total'     => count( $all ),
	);
}

/**
 * Args for aafm/get-revision.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_revision(): array {
	return array(
		'label'               => __( 'Get revision', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Get a single revision of a post the agent can edit (metadata only — no body content).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'revision_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id', 'revision_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'revision' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_revision',
		'permission_callback' => 'aafm_perm_get_revision',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/get-revision: parent editable AND the revision genuinely
 * belongs to that parent.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_get_revision( array $input ): bool {
	if ( ! aafm_revision_parent_editable( $input ) ) {
		return false;
	}
	$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;
	$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	return ! is_wp_error( aafm_validate_revision( $revision_id, $post_id ) );
}

/**
 * Execute aafm/get-revision.
 *
 * Returns the single revision reduced to the metadata-only shape by
 * aafm_redact_revision(). The validator guarantees the revision belongs to the
 * named parent before anything is returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_revision( array $input ) {
	$revision = aafm_validate_revision( absint( $input['revision_id'] ?? 0 ), absint( $input['post_id'] ?? 0 ) );
	if ( is_wp_error( $revision ) ) {
		return aafm_generic_error();
	}
	return array( 'revision' => aafm_redact_revision( $revision ) );
}

/**
 * Args for aafm/restore-revision.
 *
 * @return array<string,mixed>
 */
function aafm_args_restore_revision(): array {
	return array(
		'label'               => __( 'Restore revision', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Restore a post to one of its revisions. The current state is first saved as a fresh revision, so the restore is reversible.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'revision_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id', 'revision_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'restored'    => array( 'type' => 'boolean' ),
				'post_id'     => array( 'type' => 'integer' ),
				'revision_id' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_restore_revision',
		'permission_callback' => 'aafm_perm_restore_revision',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/restore-revision: parent editable AND the revision genuinely
 * belongs to that parent.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_restore_revision( array $input ): bool {
	if ( ! aafm_revision_parent_editable( $input ) ) {
		return false;
	}
	$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;
	$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	return ! is_wp_error( aafm_validate_revision( $revision_id, $post_id ) );
}

/**
 * Execute aafm/restore-revision.
 *
 * Restores the post to the named revision via wp_restore_post_revision(), which first
 * snapshots the current state as a fresh revision — making the restore reversible. The
 * validator guarantees the revision belongs to the named parent before any write.
 *
 * On failure, wp_restore_post_revision() may return null/false (nothing restored) OR the
 * WP_Error bubbled up from the underlying wp_update_post() — its documented int|false|null
 * return is incomplete. A WP_Error is a truthy object, so a falsy-only guard would treat a
 * failed write as success; we reject WP_Error and any non-positive int and surface the
 * generic error instead.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_restore_revision( array $input ) {
	$post_id     = absint( $input['post_id'] ?? 0 );
	$revision_id = absint( $input['revision_id'] ?? 0 );
	if ( is_wp_error( aafm_validate_revision( $revision_id, $post_id ) ) ) {
		return aafm_generic_error();
	}
	$restored = wp_restore_post_revision( $revision_id );
	if ( is_wp_error( $restored ) || (int) $restored < 1 ) {
		return aafm_generic_error();
	}
	return array(
		'restored'    => true,
		'post_id'     => $post_id,
		'revision_id' => $revision_id,
	);
}
