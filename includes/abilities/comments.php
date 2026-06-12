<?php
/**
 * Comment abilities (reads). The moderation write is appended in Phase 4.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_comments_definitions' );

/**
 * Contribute comment ability definitions to the registry (reads).
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_comments_definitions( array $registry ): array {
	$registry['aafm/get-comments']         = array(
		'label'        => __( 'Get comments', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List approved comments for a post (email and IP are never returned).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_get_comments',
	);
	$registry['aafm/get-pending-comments'] = array(
		'label'        => __( 'Get pending comments', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List the moderation queue (requires moderate_comments).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_get_pending_comments',
	);
	$registry['aafm/moderate-comment']     = array(
		'label'        => __( 'Moderate comment', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Approve, unapprove, spam, or trash a comment (requires moderate_comments).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_moderate_comment',
	);
	return $registry;
}

/**
 * Args for aafm/get-comments.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_comments(): array {
	return array(
		'label'               => __( 'Get comments', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List approved comments for a post (email and IP are never returned).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'comments' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_comments',
		'permission_callback' => 'aafm_perm_get_comments',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/get-comments: per-object post visibility.
 *
 * A comment is only as visible as the post it belongs to. Approved comments on a
 * public, non-password-protected post are readable by any logged-in caller; for
 * every other post (draft, private, scheduled, pending, password-protected, or
 * unknown) the caller must be able to read the post itself. This closes the gap
 * where a low-privilege agent could pass a hidden post's id and read its approved
 * comment bodies and author names.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_get_comments( array $input ): bool {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

	// No post targeted: the whole-site approved listing is readable by any logged-in
	// caller. The execute callback post-filters the results to comments whose parent
	// post the caller can read, so a hidden post's approved comments never leak.
	if ( $post_id <= 0 ) {
		return current_user_can( 'read' );
	}

	if ( ! get_post( $post_id ) instanceof WP_Post ) {
		// Default-deny on a missing post so the ability can't probe for ids.
		return false;
	}

	return aafm_comment_post_is_readable( $post_id );
}

/**
 * Execute aafm/get-comments.
 *
 * Returns APPROVED comments only. The status filter is pinned server-side so a
 * low-privilege caller can never reach a pending, spam, or trashed comment body
 * through this ability — unapproved bodies are only available via
 * aafm/get-pending-comments, which requires moderate_comments.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_get_comments( array $input ): array {
	$paging  = aafm_paginate_args( $input, 50 );
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

	$comments = get_comments(
		array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'number'  => $paging['per_page'],
			'paged'   => $paging['page'],
		)
	);

	// Whole-site listing (no post_id): a low-privilege caller must never receive a
	// comment whose parent post they cannot read. A caller who can moderate comments
	// already sees every comment in the dashboard, so the post-filter only applies to
	// everyone else. This mirrors the per-object visibility guard in
	// aafm_perm_get_comments() for the site-wide branch — "approved" is not "public"
	// when the parent post is private, draft, or password-protected.
	if ( $post_id <= 0 && ! current_user_can( 'moderate_comments' ) ) {
		$comments = array_filter(
			(array) $comments,
			static fn( $comment ): bool => $comment instanceof WP_Comment
				&& aafm_comment_post_is_readable( (int) $comment->comment_post_ID )
		);
	}

	return array( 'comments' => aafm_redact_comments( $comments ) );
}

/**
 * Whether the current user may read the post a comment belongs to.
 *
 * Approved comments on a public, non-password-protected post are readable by any
 * logged-in caller; for every other post (draft, private, scheduled, pending,
 * password-protected, or missing) the caller must be able to read the post itself.
 * This is the per-comment form of the visibility logic in aafm_perm_get_comments().
 *
 * @param int $post_id Parent post id.
 * @return bool
 */
function aafm_comment_post_is_readable( int $post_id ): bool {
	if ( $post_id <= 0 ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	$status_object = get_post_status_object( (string) get_post_status( $post ) );
	$is_public     = null !== $status_object && ! empty( $status_object->public );

	if ( $is_public && '' === (string) $post->post_password ) {
		return current_user_can( 'read' );
	}

	return current_user_can( 'read_post', $post_id );
}

/**
 * Redact a list of comment results to safe public shapes.
 *
 * A get_comments() result can be comment IDs or objects depending on the query;
 * keep only WP_Comment instances before redacting so no partial record leaks.
 *
 * @param mixed $comments Result of get_comments().
 * @return array<int,array<string,mixed>>
 */
function aafm_redact_comments( $comments ): array {
	$objects = array_filter(
		(array) $comments,
		static fn( $comment ): bool => $comment instanceof WP_Comment
	);

	return array_values( array_map( 'aafm_redact_comment', $objects ) );
}

/**
 * Args for aafm/get-pending-comments.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_pending_comments(): array {
	return array(
		'label'               => __( 'Get pending comments', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List the moderation queue (requires moderate_comments).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'comments' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_pending_comments',
		'permission_callback' => 'aafm_perm_moderate_comments',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for the moderation queue: moderate_comments.
 *
 * This is the only gate to unapproved comment bodies. A caller without
 * moderate_comments is denied (and the denial is audited by the registration
 * wrapper) before any pending/spam content is read.
 *
 * @return bool
 */
function aafm_perm_moderate_comments(): bool {
	return current_user_can( 'moderate_comments' );
}

/**
 * Execute aafm/get-pending-comments (moderation queue).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_get_pending_comments( array $input ): array {
	$paging   = aafm_paginate_args( $input, 50 );
	$comments = get_comments(
		array(
			'status' => 'hold',
			'number' => $paging['per_page'],
			'paged'  => $paging['page'],
		)
	);

	return array( 'comments' => aafm_redact_comments( $comments ) );
}


/**
 * Args for aafm/moderate-comment.
 *
 * Moderation only — this write never edits the comment content or author. The
 * action is constrained to a closed allowlist by the input schema, and again at
 * execute, so an arbitrary status can never be set.
 *
 * @return array<string,mixed>
 */
function aafm_args_moderate_comment(): array {
	return array(
		'label'               => __( 'Moderate comment', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Approve, unapprove, spam, or trash a comment (requires moderate_comments).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'action'     => array(
					'type' => 'string',
					'enum' => array( 'approve', 'unapprove', 'spam', 'trash' ),
				),
			),
			'required'             => array( 'comment_id', 'action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'status' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_moderate_comment',
		'permission_callback' => 'aafm_perm_moderate_comment_obj',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/moderate-comment: moderate_comments, then per-object edit.
 *
 * The site-wide moderate_comments cap is the floor; on top of it the caller must
 * be able to edit the specific comment (edit_comment maps through the post's
 * edit caps), so a moderator can't act on a comment they couldn't touch in the
 * dashboard. Every denial is audited by the registration wrapper.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_moderate_comment_obj( array $input ): bool {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return false;
	}
	$id = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
	return $id > 0 && current_user_can( 'edit_comment', $id );
}

/**
 * Execute aafm/moderate-comment.
 *
 * Applies one moderation action from the closed allowlist. Destructive actions
 * are trash/spam only — both recoverable — never a permanent wp_delete_comment.
 * The action is re-validated here so the switch's default branch hard-fails any
 * value that somehow bypassed the schema.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_moderate_comment( array $input ) {
	$id     = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
	$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';

	if ( ! get_comment( $id ) instanceof WP_Comment ) {
		return aafm_generic_error();
	}

	switch ( $action ) {
		case 'approve':
			$ok = wp_set_comment_status( $id, 'approve' );
			break;
		case 'unapprove':
			$ok = wp_set_comment_status( $id, 'hold' );
			break;
		case 'spam':
			$ok = (bool) wp_spam_comment( $id );
			break;
		case 'trash':
			if ( ! aafm_trash_is_enabled() ) {
				// wp_trash_comment() force-deletes when the Trash is disabled;
				// refuse rather than permanently destroy the comment.
				return aafm_trash_disabled_error();
			}
			$ok = (bool) wp_trash_comment( $id );
			break;
		default:
			return new WP_Error(
				'aafm_invalid_action',
				__( 'Unsupported moderation action.', 'agent-abilities-for-mcp' )
			);
	}

	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array( 'status' => wp_get_comment_status( $id ) );
}
