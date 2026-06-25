<?php
/**
 * Governed revision abilities. Every revision operation passes the shared
 * oversio_revision_parent_editable() gate: the parent post must be editable by the agent
 * (Unit 1 per-object resolver). list-revisions stays metadata-only (the lean redactor
 * never returns post_content). get-revision additionally returns the revision body and
 * excerpt (Wave 1 enrichment) for a normal post, but withholds the body, excerpt, and
 * diff when the parent post is password-protected — see oversio_get_revision_payload().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_revisions_definitions' );

/**
 * Contribute the revision definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_revisions_definitions( array $registry ): array {
	$registry['oversio/list-revisions']   = array(
		'label'        => __( 'List revisions', 'oversio-agent-abilities' ),
		'description'  => __( 'List the revisions of a post the agent can edit (metadata only — no body content).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_list_revisions',
	);
	$registry['oversio/get-revision']     = array(
		'label'        => __( 'Get revision', 'oversio-agent-abilities' ),
		'description'  => __( "Get a single revision of a post the agent can edit, including its body content (rendered or raw) and an optional diff against the post's current content.", 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_get_revision',
	);
	$registry['oversio/restore-revision'] = array(
		'label'        => __( 'Restore revision', 'oversio-agent-abilities' ),
		'description'  => __( 'Restore a post to one of its revisions. The current state is first saved as a fresh revision, so the restore is reversible.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_restore_revision',
	);
	$registry['oversio/delete-revision']  = array(
		'label'        => __( 'Delete revision', 'oversio-agent-abilities' ),
		'description'  => __( "Permanently delete a single revision from a post's history. This cannot be undone (unlike trashing a post, there is no Trash for revisions). The live post is not changed. Requires edit access to the parent post.", 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_delete_revision',
	);
	return $registry;
}

/**
 * Shared gate: the parent post must be editable by the agent (Unit 1 resolver).
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_revision_parent_editable( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && oversio_can_edit_post_object( $post );
}

/**
 * Args for oversio/list-revisions.
 *
 * @return array<string,mixed>
 */
function oversio_args_list_revisions(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/list-revisions' ),
		'description'         => oversio_ability_description( 'oversio/list-revisions' ),
		'category'            => 'oversio-reads',
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
					'maximum' => OVERSIO_LIST_PAGE_MAX,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_LIST_PER_PAGE_MAX,
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
		'execute_callback'    => 'oversio_exec_list_revisions',
		'permission_callback' => 'oversio_perm_list_revisions',
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
 * Permission for oversio/list-revisions: the shared parent-editability gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_list_revisions( array $input ): bool {
	return oversio_revision_parent_editable( $input );
}

/**
 * Execute oversio/list-revisions.
 *
 * Returns the post's revisions newest-first, paginated, each reduced to the
 * metadata-only shape by oversio_redact_revision(). Autosaves are included (both are
 * post_type=revision); that is intentional — the redactor exposes no body regardless.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_list_revisions( array $input ) {
	$id = absint( $input['post_id'] );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return oversio_generic_error();
	}
	$all    = wp_get_post_revisions( $id, array( 'fields' => 'ids' ) );
	$paging = oversio_paginate_args( $input, OVERSIO_LIST_PER_PAGE_MAX );
	$slice  = array_slice( array_values( $all ), ( $paging['page'] - 1 ) * $paging['per_page'], $paging['per_page'] );
	$rows   = array();
	foreach ( $slice as $rid ) {
		// $rid is a revision id ('fields' => 'ids'); get_post() resolves it without the
		// pass-by-reference constraint of wp_get_post_revision() and stays analyzer-clean.
		$rev = get_post( $rid );
		if ( $rev instanceof WP_Post && 'revision' === $rev->post_type ) {
			$rows[] = oversio_redact_revision( $rev );
		}
	}
	return array(
		'revisions' => $rows,
		'total'     => count( $all ),
	);
}

/**
 * Args for oversio/get-revision.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_revision(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-revision' ),
		'description'         => oversio_ability_description( 'oversio/get-revision' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'revision_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'content_format' => array(
					'type'    => 'string',
					'enum'    => array( 'rendered', 'raw' ),
					'default' => 'rendered',
				),
				'with_diff'      => array(
					'type'    => 'boolean',
					'default' => false,
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
		'execute_callback'    => 'oversio_exec_get_revision',
		'permission_callback' => 'oversio_perm_get_revision',
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
 * Permission for oversio/get-revision: parent editable AND the revision genuinely
 * belongs to that parent.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_get_revision( array $input ): bool {
	if ( ! oversio_revision_parent_editable( $input ) ) {
		return false;
	}
	$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;
	$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	return ! is_wp_error( oversio_validate_revision( $revision_id, $post_id ) );
}

/**
 * Execute oversio/get-revision.
 *
 * Returns the single revision's metadata plus its body content, excerpt, and an optional
 * diff, assembled by oversio_get_revision_payload(). The validator guarantees the revision
 * belongs to the named parent before anything is returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_revision( array $input ) {
	$revision = oversio_validate_revision( absint( $input['revision_id'] ?? 0 ), absint( $input['post_id'] ?? 0 ) );
	if ( is_wp_error( $revision ) ) {
		return oversio_generic_error();
	}
	return array( 'revision' => oversio_get_revision_payload( $revision, $input ) );
}

/**
 * Args for oversio/restore-revision.
 *
 * @return array<string,mixed>
 */
function oversio_args_restore_revision(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/restore-revision' ),
		'description'         => oversio_ability_description( 'oversio/restore-revision' ),
		'category'            => 'oversio-writes',
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
		'execute_callback'    => 'oversio_exec_restore_revision',
		'permission_callback' => 'oversio_perm_restore_revision',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/restore-revision: parent editable AND the revision genuinely
 * belongs to that parent.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_restore_revision( array $input ): bool {
	if ( ! oversio_revision_parent_editable( $input ) ) {
		return false;
	}
	$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;
	$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	return ! is_wp_error( oversio_validate_revision( $revision_id, $post_id ) );
}

/**
 * Execute oversio/restore-revision.
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
function oversio_exec_restore_revision( array $input ) {
	$post_id     = absint( $input['post_id'] ?? 0 );
	$revision_id = absint( $input['revision_id'] ?? 0 );
	if ( is_wp_error( oversio_validate_revision( $revision_id, $post_id ) ) ) {
		return oversio_generic_error();
	}
	$restored = wp_restore_post_revision( $revision_id );
	if ( is_wp_error( $restored ) || (int) $restored < 1 ) {
		return oversio_generic_error();
	}
	return array(
		'restored'    => true,
		'post_id'     => $post_id,
		'revision_id' => $revision_id,
	);
}

/**
 * Args for oversio/delete-revision.
 *
 * @return array<string,mixed>
 */
function oversio_args_delete_revision(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/delete-revision' ),
		'description'         => oversio_ability_description( 'oversio/delete-revision' ),
		'category'            => 'oversio-writes',
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
				'deleted'     => array( 'type' => 'boolean' ),
				'post_id'     => array( 'type' => 'integer' ),
				'revision_id' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_delete_revision',
		'permission_callback' => 'oversio_perm_delete_revision',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for oversio/delete-revision: the SAME gate as restore — parent editable AND
 * the revision genuinely belongs to that parent. An agent that cannot edit the parent
 * cannot delete its revisions, and a revision_id that is not a child of the named
 * post_id is rejected.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_delete_revision( array $input ): bool {
	if ( ! oversio_revision_parent_editable( $input ) ) {
		return false;
	}
	$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;
	$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	return ! is_wp_error( oversio_validate_revision( $revision_id, $post_id ) );
}

/**
 * Execute oversio/delete-revision.
 *
 * Permanently removes one revision via wp_delete_post_revision(). The validator
 * guarantees the revision belongs to the named parent before any delete. The live post
 * is never touched — this prunes change history only.
 *
 * wp_delete_post_revision() returns WP_Post|false|null|0|WP_Error: false/null/0 when
 * nothing was deleted, the deleted WP_Post on success, and a WP_Error bubbled up from
 * wp_delete_post(). A WP_Error is a truthy object, so — mirroring oversio_exec_restore_revision
 * — we reject WP_Error and any falsy return and surface the generic error instead.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_delete_revision( array $input ) {
	$post_id     = absint( $input['post_id'] ?? 0 );
	$revision_id = absint( $input['revision_id'] ?? 0 );
	if ( is_wp_error( oversio_validate_revision( $revision_id, $post_id ) ) ) {
		return oversio_generic_error();
	}
	$deleted = wp_delete_post_revision( $revision_id );
	if ( is_wp_error( $deleted ) || ! $deleted ) {
		return oversio_generic_error();
	}
	return array(
		'deleted'     => true,
		'post_id'     => $post_id,
		'revision_id' => $revision_id,
	);
}
