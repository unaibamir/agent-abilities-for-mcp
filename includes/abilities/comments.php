<?php
/**
 * Comment abilities: reads, moderation, and the full CRUD set
 * (get / create / update / delete, plus the moderation queue and actions).
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
		'label'        => __( 'Get comments', 'oversio-agent-abilities' ),
		'description'  => __( 'List approved comments for a post (email and IP are never returned).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_get_comments',
	);
	$registry['aafm/get-pending-comments'] = array(
		'label'        => __( 'Get pending comments', 'oversio-agent-abilities' ),
		'description'  => __( 'List the moderation queue (requires moderate_comments).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_get_pending_comments',
	);
	$registry['aafm/moderate-comment']     = array(
		'label'        => __( 'Moderate comment', 'oversio-agent-abilities' ),
		'description'  => __( 'Approve, unapprove, spam, or trash a comment (requires moderate_comments).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_moderate_comment',
	);
	$registry['aafm/get-comment']          = array(
		'label'        => __( 'Get comment', 'oversio-agent-abilities' ),
		'description'  => __( 'Read one comment by id (email and IP are never returned).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_get_comment',
	);
	$registry['aafm/create-comment']       = array(
		'label'        => __( 'Create comment', 'oversio-agent-abilities' ),
		'description'  => __( 'Add a pending comment to a post as the agent user (requires moderate_comments).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_create_comment',
	);
	$registry['aafm/update-comment']       = array(
		'label'        => __( 'Update comment', 'oversio-agent-abilities' ),
		'description'  => __( "Edit a comment's content (requires edit access to that comment).", 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_update_comment',
	);
	$registry['aafm/delete-comment']       = array(
		'label'        => __( 'Delete comment', 'oversio-agent-abilities' ),
		'description'  => __( 'Permanently delete a comment (not recoverable; use moderate-comment to trash recoverably).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'comments',
		'args_builder' => 'aafm_args_delete_comment',
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
		'label'               => aafm_ability_label( 'aafm/get-comments' ),
		'description'         => aafm_ability_description( 'aafm/get-comments' ),
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
					'maximum' => AAFM_LIST_PER_PAGE_MAX,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PAGE_MAX,
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
				'total'    => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_comments',
		'permission_callback' => 'aafm_perm_get_comments',
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
	$paging  = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
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

	// total is the count for the same status+post filter, BEFORE the site-wide visibility
	// post-filter above. For a post-scoped query (post_id given) the post is already gated, so
	// total is exact; for the whole-site unprivileged listing it counts approved comments and
	// may exceed the visible rows. The post-scoped case is the common one and pages correctly.
	$total = (int) get_comments(
		array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'count'   => true,
		)
	);

	return array(
		'comments' => aafm_redact_comments( $comments ),
		'total'    => $total,
	);
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
 * Args for aafm/get-comment.
 *
 * One comment by id, returned in the same redacted shape as the list reads — never
 * the author email or IP. The read is gated by the parent post's visibility, exactly
 * like aafm/get-comments, so an approved comment on a hidden post never leaks.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_comment(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-comment' ),
		'description'         => aafm_ability_description( 'aafm/get-comment' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'comment_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'comment' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_comment',
		'permission_callback' => 'aafm_perm_get_comment',
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
 * Permission for aafm/get-comment.
 *
 * A comment is only as visible as the post it belongs to. Approved comments on a
 * public, non-password-protected post are readable by any logged-in caller; every
 * other comment (pending, spam, trash, or on a hidden post) requires the caller to
 * be able to read that post AND to moderate/edit the comment — the same posture as
 * aafm/get-comments, refined to a single object. A missing comment default-denies
 * so the ability can't be used to probe for ids.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_get_comment( array $input ): bool {
	$id = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
	if ( $id <= 0 ) {
		return current_user_can( 'read' );
	}

	$comment = get_comment( $id );
	if ( ! $comment instanceof WP_Comment ) {
		// Default-deny on a missing comment so the ability can't probe for ids —
		// the same posture as aafm_perm_get_comments() for a missing target post.
		return false;
	}

	$post_id     = (int) $comment->comment_post_ID;
	$is_approved = '1' === (string) $comment->comment_approved || 'approve' === (string) $comment->comment_approved;

	// Approved comment on a readable post: the same floor as the list read.
	if ( $is_approved && aafm_comment_post_is_readable( $post_id ) ) {
		return true;
	}

	// Non-approved (hold/spam/trash) or on a hidden post: require moderation rights
	// on the specific comment.
	return current_user_can( 'moderate_comments' ) && current_user_can( 'edit_comment', $id );
}

/**
 * Execute aafm/get-comment.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_comment( array $input ) {
	$id      = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
	$comment = get_comment( $id );
	if ( ! $comment instanceof WP_Comment ) {
		return aafm_generic_error();
	}
	return array( 'comment' => aafm_redact_comment( $comment ) );
}

/**
 * Args for aafm/create-comment.
 *
 * Security posture (this is the main abuse surface):
 *  - Floor cap is moderate_comments: an autonomous agent posting comments is a
 *    privileged moderation action, not an anonymous public comment. This blocks a
 *    low-cap caller from mass-creating spam.
 *  - The author is ALWAYS the current (agent) user. No comment_author,
 *    comment_author_email, author_url, or comment_author_IP is accepted from input,
 *    so identity can never be spoofed or used as an injection vector.
 *  - Content is run through wp_kses_post() and wp_filter_comment() before insert, so
 *    raw script is never stored.
 *  - The comment is created PENDING (comment_approved '0'); a human or
 *    aafm/moderate-comment approves it. An agent never auto-publishes.
 *  - comment_post_ID must resolve to a real post. Optional parent must be a real
 *    comment ON THE SAME POST. comments_open() is deliberately not required —
 *    moderators add to closed threads in the dashboard and the cap floor gates abuse.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_comment(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-comment' ),
		'description'         => aafm_ability_description( 'aafm/create-comment' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'content' => array(
					'type'      => 'string',
					'minLength' => 1,
					// Character cap, not a byte cap: WordPress stores comment_content in a TEXT
					// column (~65,535 BYTES). A comment of multi-byte (UTF-8) characters can still
					// exceed the byte limit even under this length, so the DB write is the final
					// guard; this bound just rejects the obviously-oversized payload up front.
					'maxLength' => 65525,
				),
				'parent'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id', 'content' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'comment' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_create_comment',
		'permission_callback' => 'aafm_perm_create_comment',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/create-comment: moderate_comments.
 *
 * An agent creating comments is treated as a moderation-class action. The single
 * site-wide cap is the gate; there is no per-object refinement because the author is
 * pinned to the current user, not a target the caller might not own. Every denial is
 * audited by the registration wrapper.
 *
 * @return bool
 */
function aafm_perm_create_comment(): bool {
	return current_user_can( 'moderate_comments' );
}

/**
 * Execute aafm/create-comment.
 *
 * Builds the insert array from the current user (never from input), sanitizes the
 * content, forces pending status, validates the target post and optional parent, runs
 * the array through wp_filter_comment(), and inserts. Returns the redacted comment.
 *
 * Two deliberate choices worth calling out:
 *  - The post-insert wp_set_comment_status( $id, 'hold' ) pin is intentional. Even if a
 *    filter on insert flips the status, the comment must land in the moderation queue;
 *    an agent should never auto-publish its own comment.
 *  - wp_insert_comment() bypasses wp_allow_comment()'s duplicate / flood / disallowed-key
 *    checks. That is acceptable here precisely because the caller already holds
 *    moderate_comments — this is a moderation action, not an anonymous public submission.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_comment( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$parent  = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
	$content = isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '';

	if ( '' === trim( $content ) ) {
		return aafm_generic_error();
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// An optional parent must be a real comment on the SAME post — no cross-post threading.
	if ( $parent > 0 ) {
		$parent_comment = get_comment( $parent );
		if ( ! $parent_comment instanceof WP_Comment || (int) $parent_comment->comment_post_ID !== $post_id ) {
			return aafm_generic_error();
		}
	}

	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User || 0 === (int) $user->ID ) {
		return aafm_generic_error();
	}

	// Author identity is the agent user — never free-form input. Status is pending.
	// wp_slash() the strings: wp_insert_comment() expects slashed data and unslashes
	// internally, matching the post-writer convention elsewhere in the plugin.
	$commentdata = array(
		'comment_post_ID'      => $post_id,
		'comment_parent'       => $parent,
		'comment_content'      => wp_slash( $content ),
		'comment_author'       => wp_slash( (string) $user->display_name ),
		'comment_author_email' => wp_slash( (string) $user->user_email ),
		'comment_author_url'   => '',
		'user_id'              => (int) $user->ID,
		'comment_approved'     => '0',
		'comment_type'         => 'comment',
		// Present but empty: wp_filter_comment() reads these keys, and we never record
		// the agent's IP/user-agent for an agent-authored comment. Neither is ever
		// returned — the response is built by aafm_redact_comment().
		'comment_author_IP'    => '',
		'comment_agent'        => '',
	);

	// Apply WordPress's own comment filters (pre_comment_content / kses) as a second pass.
	$commentdata = wp_filter_comment( $commentdata );

	$comment_id = wp_insert_comment( $commentdata );
	if ( ! $comment_id ) {
		return aafm_generic_error();
	}

	// Pin status to pending in case a filter flipped it on insert.
	wp_set_comment_status( $comment_id, 'hold' );

	$created = get_comment( $comment_id );
	if ( ! $created instanceof WP_Comment ) {
		return aafm_generic_error();
	}

	return array( 'comment' => aafm_redact_comment( $created ) );
}

/**
 * Args for aafm/get-pending-comments.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_pending_comments(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-pending-comments' ),
		'description'         => aafm_ability_description( 'aafm/get-pending-comments' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PER_PAGE_MAX,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PAGE_MAX,
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
				'total'    => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_pending_comments',
		'permission_callback' => 'aafm_perm_moderate_comments',
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
	$paging   = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$comments = get_comments(
		array(
			'status' => 'hold',
			'number' => $paging['per_page'],
			'paged'  => $paging['page'],
		)
	);

	$total = (int) get_comments(
		array(
			'status' => 'hold',
			'count'  => true,
		)
	);

	return array(
		'comments' => aafm_redact_comments( $comments ),
		'total'    => $total,
	);
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
		'label'               => aafm_ability_label( 'aafm/moderate-comment' ),
		'description'         => aafm_ability_description( 'aafm/moderate-comment' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'action'     => array(
					'type'        => 'string',
					'enum'        => array( 'approve', 'unapprove', 'spam', 'trash' ),
					'description' => __( 'Moderation action: approve (publish the comment), unapprove (return it to the pending queue), spam (mark as spam), or trash (move to the Trash).', 'oversio-agent-abilities' ),
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
				__( 'Unsupported moderation action.', 'oversio-agent-abilities' )
			);
	}

	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array( 'status' => wp_get_comment_status( $id ) );
}

/**
 * Args for aafm/update-comment.
 *
 * Edits ONLY the comment body. The closed schema accepts comment_id + content; it
 * never accepts comment_post_ID, email, IP, or author fields, so an edit can't be
 * used to re-target, re-author, or de-redact a comment. Content is sanitized before
 * the update. The moderate_comments floor plus per-object edit_comment is the gate.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_comment(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-comment' ),
		'description'         => aafm_ability_description( 'aafm/update-comment' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'content'    => array(
					'type'      => 'string',
					'minLength' => 1,
					// Character cap, not a byte cap: WordPress stores comment_content in a TEXT
					// column (~65,535 BYTES). A comment of multi-byte (UTF-8) characters can still
					// exceed the byte limit even under this length, so the DB write is the final
					// guard; this bound just rejects the obviously-oversized payload up front.
					'maxLength' => 65525,
				),
			),
			'required'             => array( 'comment_id', 'content' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'comment' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_update_comment',
		'permission_callback' => 'aafm_perm_edit_comment_obj',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Per-object edit gate shared by update-comment and delete-comment.
 *
 * The site-wide moderate_comments cap is the floor — the same posture as
 * aafm_perm_moderate_comment_obj() — so a low-cap user who happens to be able to
 * edit their own comment can never reach these writes. On top of the floor the caller
 * must be able to edit the specific comment (edit_comment maps through the parent
 * post's edit caps), so a moderator can't touch a comment they couldn't edit in the
 * dashboard. A missing id denies. Every denial is audited by the registration wrapper.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_edit_comment_obj( array $input ): bool {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return false;
	}
	$id = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
	return $id > 0 && current_user_can( 'edit_comment', $id );
}

/**
 * Execute aafm/update-comment.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_comment( array $input ) {
	$id      = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
	$content = isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '';

	if ( ! get_comment( $id ) instanceof WP_Comment ) {
		return aafm_generic_error();
	}
	if ( '' === trim( $content ) ) {
		return aafm_generic_error();
	}

	// wp_update_comment() unslashes internally, so the content is slashed on the way in
	// to match the post-writer convention. Only comment_content is written — never the
	// post id, author, email, or IP.
	$ok = wp_update_comment(
		array(
			'comment_ID'      => $id,
			'comment_content' => wp_slash( $content ),
		)
	);

	// wp_update_comment() returns 1 on success, 0 when unchanged, false/WP_Error on failure.
	if ( false === $ok || is_wp_error( $ok ) ) {
		return aafm_generic_error();
	}

	// The comment was confirmed to exist above and the update did not fail, so the
	// re-fetch is the fresh, post-update record. aafm_redact_comment() tolerates a
	// non-WP_Comment by returning an empty array, so the response is always well-formed.
	return array( 'comment' => aafm_redact_comment( get_comment( $id ) ) );
}

/**
 * Args for aafm/delete-comment.
 *
 * PERMANENT delete (wp_delete_comment with force=true) — this bypasses the Trash and
 * cannot be undone. It is deliberately distinct from aafm/moderate-comment's 'trash'
 * action, which is recoverable. risk=destructive, destructive:true, and the disclosure
 * states the permanence plainly. The moderate_comments floor plus per-object
 * edit_comment is the gate.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_comment(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-comment' ),
		'description'         => aafm_ability_description( 'aafm/delete-comment' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'comment_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted'    => array( 'type' => 'boolean' ),
				'comment_id' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_comment',
		'permission_callback' => 'aafm_perm_edit_comment_obj',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/delete-comment.
 *
 * Force-deletes via wp_delete_comment( $id, true ): the row is removed, not trashed,
 * and cannot be recovered. We resolve the comment first (so a missing id is an honest
 * error, not a silent no-op), then honor the bool return.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_comment( array $input ) {
	$id = isset( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;

	if ( ! get_comment( $id ) instanceof WP_Comment ) {
		return aafm_generic_error();
	}

	$deleted = wp_delete_comment( $id, true );
	if ( ! $deleted ) {
		return aafm_generic_error();
	}

	return array(
		'deleted'    => true,
		'comment_id' => $id,
	);
}
