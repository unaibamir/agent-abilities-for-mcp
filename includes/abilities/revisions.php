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
	$registry['aafm/list-revisions'] = array(
		'label'        => __( 'List revisions', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List the revisions of a post the agent can edit (metadata only — no body content).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_list_revisions',
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
