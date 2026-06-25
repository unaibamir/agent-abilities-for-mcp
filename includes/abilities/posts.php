<?php
/**
 * Post abilities (reads). Writes are appended in Phase 4.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The default upper bound on a list ability's `per_page`. Used both as the schema `maximum`
 * and the cap passed to aafm_paginate_args() so the declared bound and the enforced bound
 * never drift. Shared across the post/page/user/media/comment/revision/search list tools.
 */
const AAFM_LIST_PER_PAGE_MAX = 50;

/**
 * The upper bound on `per_page` for taxonomy term lists. Terms are lightweight rows and a
 * tree often runs to the hundreds, so the term lists allow a higher ceiling than the
 * content lists (AAFM_LIST_PER_PAGE_MAX) to fetch a whole vocabulary in one page.
 */
const AAFM_TERMS_PER_PAGE_MAX = 100;

/**
 * The upper bound on a list ability's `page` number. A generous ceiling that still caps an
 * agent from requesting an unbounded page offset; declared on every list tool's `page` arg.
 */
const AAFM_LIST_PAGE_MAX = 100000;

add_filter( 'aafm_abilities_registry', 'aafm_register_posts_definitions' );

/**
 * Contribute post ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_posts_definitions( array $registry ): array {
	$registry['aafm/get-posts']       = array(
		'label'        => __( 'Get posts', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List posts filtered by type, status, and search term. Each item returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per item; content_format (rendered or raw) only takes effect when include_content is true. Response includes total.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_posts',
	);
	$registry['aafm/count-posts']     = array(
		'label'        => __( 'Count posts', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Count posts of an allowlisted post type: a total of active (non-trashed) items, plus a breakdown by status (publish, draft, pending, private, future, trash). Trash and auto-draft are shown in the breakdown but excluded from total.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_count_posts',
	);
	$registry['aafm/get-post']        = array(
		'label'        => __( 'Get post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Retrieve a single post by ID. Returns id, title, status, type, slug, link, author {id, display_name}, dates, full content (rendered HTML by default, or raw markup via content_format; omitted for password-protected posts), excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and meta (allowlisted scalar values only).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_post',
	);
	$registry['aafm/create-draft']    = array(
		'label'        => __( 'Create draft', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create a new draft post. The agent drafts; a human publishes. Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_draft',
	);
	$registry['aafm/create-post']     = array(
		'label'        => __( 'Create post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create and publish a post (requires publish capability). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_post',
	);
	$registry['aafm/update-post']     = array(
		'label'        => __( 'Update post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Update an existing post by ID (publishing is a separate gate). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_update_post',
	);
	$registry['aafm/replace-in-post'] = array(
		'label'        => __( 'Replace in post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Literal find-and-replace inside a post\'s content. Sanitizes the result and edits only the body; status is never touched. Reversible via revisions.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_replace_in_post',
	);
	$registry['aafm/trash-post']      = array(
		'label'        => __( 'Trash post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Move a post to trash (recoverable, never permanently deleted).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_trash_post',
	);
	$registry['aafm/create-cpt-item'] = array(
		'label'        => __( 'Create content item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create an item of an allowlisted custom content type (post_type). Drafts unless the type\'s publish capability is held.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_cpt_item',
	);
	$registry['aafm/update-cpt-item'] = array(
		'label'        => __( 'Update content item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Update an item of an allowlisted custom content type by ID (publishing requires that type\'s publish capability).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_update_cpt_item',
	);
	$registry['aafm/delete-post']     = array(
		'label'        => __( 'Delete post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently delete a post, bypassing the Trash. This cannot be undone — use trash-post to remove a post recoverably instead.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_delete_post',
	);
	return $registry;
}

/**
 * Args for aafm/get-posts.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_posts(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-posts' ),
		'description'         => aafm_ability_description( 'aafm/get-posts' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'       => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'status'          => array(
					'type'    => 'string',
					'default' => 'publish',
				),
				'search'          => array( 'type' => 'string' ),
				'page'            => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PAGE_MAX,
				),
				'per_page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PER_PAGE_MAX,
				),
				'content_format'  => array(
					'type'    => 'string',
					'enum'    => array( 'rendered', 'raw' ),
					'default' => 'rendered',
				),
				'include_content' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'posts' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_rich_post_output_properties(),
					),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_posts',
		'permission_callback' => 'aafm_perm_read',
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
 * Generic read permission.
 *
 * @return bool
 */
function aafm_perm_read(): bool {
	return current_user_can( 'read' );
}

/**
 * Execute aafm/get-posts.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_posts( array $input ) {
	$type = aafm_validate_post_type( isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post' );
	if ( is_wp_error( $type ) ) {
		return $type;
	}

	// Resolve the read-private capability from the post type's own cap map so a page
	// query is gated by read_private_pages (and a custom type by its own cap), not the
	// post default — get-pages delegates here with post_type pinned to 'page'.
	$type_object = get_post_type_object( $type );
	$private_cap = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->read_private_posts : 'read_private_posts';
	$can_private = current_user_can( $private_cap );
	$status      = aafm_validate_post_status( isset( $input['status'] ) ? (string) $input['status'] : 'publish', $can_private );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	$paging = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );

	$query = new WP_Query(
		array(
			'post_type'        => $type,
			'post_status'      => $status,
			's'                => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			'posts_per_page'   => $paging['per_page'],
			'paged'            => $paging['page'],
			'no_found_rows'    => false,
			'suppress_filters' => false,
		)
	);

	$objects = array_filter(
		$query->posts,
		static fn( $post ): bool => $post instanceof WP_Post
	);

	$format          = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'rendered';
	$include_content = ! empty( $input['include_content'] );
	$options         = array(
		'content_format'  => $format,
		'include_content' => $include_content,
	);

	$posts = array_map(
		static fn( WP_Post $post ): array => aafm_rich_post( $post, $options ),
		array_values( $objects )
	);

	return array(
		'posts' => $posts,
		'total' => (int) $query->found_posts,
	);
}

/**
 * Args for aafm/count-posts — total plus per-status breakdown, optional post_type.
 *
 * @return array<string,mixed>
 */
function aafm_args_count_posts(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/count-posts' ),
		'description'         => aafm_ability_description( 'aafm/count-posts' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total'     => array( 'type' => 'integer' ),
				'by_status' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_count_posts',
		'permission_callback' => 'aafm_perm_read',
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
 * Execute aafm/count-posts. The post type defaults to 'post' and MUST clear the
 * eligibility floor + allowlist (aafm_validate_post_type); a non-allowlisted type is
 * refused. wp_count_posts() returns an object keyed by status; expose every status in
 * by_status, and a `total` of ACTIVE (non-trashed) items only.
 *
 * `total` deliberately excludes the `trash` and `auto-draft` statuses — trash is recoverable
 * removed content and auto-draft is abandoned editor scratch, neither of which is a live item.
 * This matches the non-trash "active" total convention used by the WooCommerce count siblings
 * (wc-count-products / wc-count-coupons / wc-count-orders). The per-status breakdown in
 * by_status still reports trash and auto-draft for transparency (B4).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_count_posts( array $input ) {
	$type = aafm_validate_post_type( isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post' );
	if ( is_wp_error( $type ) ) {
		return $type;
	}

	// Statuses excluded from the active total (still surfaced in by_status).
	$non_active = array( 'trash', 'auto-draft' );

	$counts    = (array) wp_count_posts( $type );
	$by_status = array();
	$total     = 0;
	foreach ( $counts as $status => $n ) {
		$status               = (string) $status;
		$n                    = (int) $n;
		$by_status[ $status ] = $n;
		if ( ! in_array( $status, $non_active, true ) ) {
			$total += $n;
		}
	}

	return array(
		'total'     => $total,
		// Cast so an empty breakdown JSON-encodes to "{}" (object) per the schema.
		'by_status' => (object) $by_status,
	);
}

/**
 * Args for aafm/get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-post' ),
		'description'         => aafm_ability_description( 'aafm/get-post' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'content_format' => array(
					'type'    => 'string',
					'enum'    => array( 'rendered', 'raw' ),
					'default' => 'rendered',
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post' => array(
					'type'       => 'object',
					'properties' => aafm_rich_post_output_properties(),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_post',
		'permission_callback' => 'aafm_perm_get_post',
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
 * Permission for aafm/get-post: read, plus per-object edit for non-public posts.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_get_post( array $input ): bool {
	if ( ! current_user_can( 'read' ) ) {
		return false;
	}
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post ) {
		return false;
	}
	// Single chokepoint: type must be exposed (floor + allowlist), and non-public
	// statuses are gated per-object (mapped types only). Closes the attachment leak.
	return aafm_can_read_post_object( $post );
}

/**
 * Execute aafm/get-post.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_post( array $input ) {
	$id   = absint( $input['post_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$format = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'rendered';
	return array(
		'post' => aafm_rich_post( $post, array( 'content_format' => $format ) ),
	);
}

/**
 * Shared content input schema for post/page writes.
 *
 * The schema is closed (additionalProperties:false) so callers cannot smuggle
 * post_author, post_type, meta_input, or other privileged fields — anything not
 * declared here is rejected by the Abilities API before execute runs.
 *
 * @param bool $require_title Whether title is required.
 * @return array<string,mixed>
 */
function aafm_write_content_schema( bool $require_title ): array {
	$schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'title'          => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'content'        => array( 'type' => 'string' ),
			'excerpt'        => array( 'type' => 'string' ),
			'status'         => array( 'type' => 'string' ),
			'slug'           => array( 'type' => 'string' ),
			'featured_media' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'terms'          => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			'meta'           => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
		),
		'additionalProperties' => false,
	);
	if ( $require_title ) {
		$schema['required'] = array( 'title' );
	}
	return $schema;
}

/**
 * Closed content input schema for the generic CPT writes.
 *
 * Starts from the shared post/page schema (so CPT items inherit the exact C2 enrichment
 * surface: title/content/excerpt/status/slug/featured_media/terms/meta) and adds a
 * REQUIRED post_type string. The schema stays closed (additionalProperties:false) so the
 * agent still cannot smuggle post_author, meta_input, or any other privileged field — the
 * only privileged field exposed is post_type, which the execute path re-validates against
 * the read allowlist + eligibility floor.
 *
 * @param bool $require_title Whether title is required (true for create, false for update).
 * @return array<string,mixed>
 */
function aafm_write_cpt_content_schema( bool $require_title ): array {
	$schema                            = aafm_write_content_schema( $require_title );
	$schema['properties']['post_type'] = array(
		'type'        => 'string',
		'minLength'   => 1,
		'description' => __( 'Slug of an agent-writable custom content type. Not every public type is writable — only types the operator has exposed to agents are accepted; others are rejected. Call aafm/get-post-types and use the writable flag to find valid slugs.', 'agent-abilities-for-mcp' ),
	);
	$required                          = $schema['required'] ?? array();
	$required[]                        = 'post_type';
	$schema['required']                = array_values( array_unique( $required ) );
	return $schema;
}

/**
 * Args for aafm/create-draft.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_draft(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-draft' ),
		'description'         => aafm_ability_description( 'aafm/create-draft' ),
		'category'            => 'aafm-writes',
		'input_schema'        => aafm_write_content_schema( true ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_create_draft',
		'permission_callback' => 'aafm_perm_edit_posts',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission: edit_posts (create drafts, not publish).
 *
 * @return bool
 */
function aafm_perm_edit_posts(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Permission: publish_posts.
 *
 * @return bool
 */
function aafm_perm_publish_posts(): bool {
	return current_user_can( 'publish_posts' );
}

/**
 * Insert a post with a forced status and type, returning the redacted post.
 *
 * Anti-escalation: post_author is never threaded from input — wp_insert_post
 * defaults it to the current (agent) user, so a caller cannot spoof authorship.
 * post_type and post_status are forced by the caller of this function, never by
 * the agent's input. Title is sanitized with sanitize_text_field and content with
 * wp_kses_post so even an unfiltered_html-capable agent cannot store script.
 *
 * @param array<string,mixed> $input  Validated input.
 * @param string              $status Forced status (draft|publish).
 * @param string              $type   Forced post type.
 * @return array<string,mixed>|WP_Error
 */
function aafm_insert_post( array $input, string $status, string $type ) {
	// Force-draft override applies to every create that routes here (post + page).
	if ( aafm_force_draft() ) {
		$status = 'draft';
	}

	// Max-title guard covers create (post + page via delegation) and, in the updater, update (post + page).
	$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
	if ( ! aafm_title_within_limit( $title ) ) {
		return new WP_Error( 'aafm_title_too_long', __( 'The title exceeds the maximum allowed length.', 'agent-abilities-for-mcp' ) );
	}

	// Validate enrichment BEFORE inserting so a bad term/attachment/meta aborts with nothing written.
	$enrichment = aafm_validate_write_enrichment( $input );
	if ( is_wp_error( $enrichment ) ) {
		return $enrichment;
	}

	$postarr = array(
		'post_type'    => $type,
		'post_status'  => $status,
		'post_title'   => $title,
		'post_content' => isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '',
		'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_text_field( (string) $input['excerpt'] ) : '',
	);

	// Optional slug → sanitize_title → post_name, folded into the same atomic row write.
	if ( isset( $input['slug'] ) ) {
		$slug = sanitize_title( (string) $input['slug'] );
		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}
	}

	$id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $id ) ) {
		return aafm_generic_error();
	}
	$created = get_post( $id );
	if ( ! $created instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Apply the pre-validated enrichment now that the id exists.
	aafm_apply_write_enrichment( (int) $id, $enrichment );

	return array( 'post' => aafm_redact_post( $created ) );
}

/**
 * Execute aafm/create-draft — status is ALWAYS draft regardless of input.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_draft( array $input ) {
	return aafm_insert_post( $input, 'draft', 'post' );
}

/**
 * Args for aafm/create-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-post' ),
		'description'         => aafm_ability_description( 'aafm/create-post' ),
		'category'            => 'aafm-writes',
		'input_schema'        => aafm_write_content_schema( true ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_create_post',
		'permission_callback' => 'aafm_perm_publish_posts',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/create-post.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_post( array $input ) {
	return aafm_insert_post( $input, 'publish', 'post' );
}

/**
 * Args for aafm/create-cpt-item.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_cpt_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-cpt-item' ),
		'description'         => aafm_ability_description( 'aafm/create-cpt-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => aafm_write_cpt_content_schema( true ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_create_cpt_item',
		'permission_callback' => 'aafm_perm_create_cpt_item',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/create-cpt-item: the type must be allowlisted+eligible AND the agent
 * must hold that type's own create cap. When the request asks to publish, the type's publish
 * cap is also required. Never bypasses the type's WP capability model.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_create_cpt_item( array $input ): bool {
	$type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
	if ( is_wp_error( aafm_validate_post_type( $type ) ) ) {
		return false;
	}
	$caps = aafm_type_caps( $type );
	if ( ! $caps['object'] instanceof WP_Post_Type ) {
		return false;
	}
	// Deliberately NO $caps['mapped']/map_meta_cap gate here, unlike the update/read paths.
	// Create is pre-insert: there is no per-object edit_post to degrade-open against, the author
	// is forced to the current user (no spoof), and edit_posts/publish_posts are real assigned
	// primitives that hold regardless of map_meta_cap. The mapped check is unnecessary here and
	// must NOT be copied to the per-object edit/delete paths where degraded caps can fail open.
	// Base authoring cap for the type (edit_posts-equivalent).
	if ( ! current_user_can( (string) $caps['object']->cap->edit_posts ) ) {
		return false;
	}
	// Publish requested → require the type's publish cap too.
	if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
		return current_user_can( (string) $caps['object']->cap->publish_posts );
	}
	return true;
}

/**
 * Execute aafm/create-cpt-item.
 *
 * Re-validates post_type against the allowlist+floor at execute time (defense in depth: the
 * type could have been de-allowlisted between the permission check and execute), then resolves
 * the forced status from the publish request + the type's publish cap and delegates to the
 * shared aafm_insert_post(), so the CPT item inherits force-draft, author-forcing, content
 * sanitization, the status floor, and the C2 enrichment exactly as post/page creates do.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_cpt_item( array $input ) {
	$type      = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
	$validated = aafm_validate_post_type( $type );
	if ( is_wp_error( $validated ) ) {
		return aafm_generic_error();
	}

	// Default to draft; only escalate to publish when the request asked for it AND the agent
	// holds the type's publish cap. Force-draft inside aafm_insert_post may still coerce back.
	$status = 'draft';
	if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
		$caps = aafm_type_caps( $validated );
		if ( $caps['object'] instanceof WP_Post_Type
			&& current_user_can( (string) $caps['object']->cap->publish_posts ) ) {
			$status = 'publish';
		}
	}

	return aafm_insert_post( $input, $status, $validated );
}

/**
 * Args for aafm/update-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_post(): array {
	$schema                          = aafm_write_content_schema( false );
	$schema['properties']['post_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$schema['required']              = array( 'post_id' );

	return array(
		'label'               => aafm_ability_label( 'aafm/update-post' ),
		'description'         => aafm_ability_description( 'aafm/update-post' ),
		'category'            => 'aafm-writes',
		'input_schema'        => $schema,
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_update_post',
		'permission_callback' => 'aafm_perm_update_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/update-post: per-object edit_post, plus publish_posts when publishing.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_update_post( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || ! aafm_can_edit_post_object( $post ) ) {
		return false;
	}
	if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
		$caps = aafm_type_caps( $post->post_type );
		return $caps['object'] instanceof WP_Post_Type
			&& current_user_can( (string) $caps['object']->cap->publish_posts );
	}
	return true;
}

/**
 * Execute aafm/update-post. Status may only become a validated allow-list value.
 *
 * Only the four declared fields are ever written. Content is re-sanitized with
 * wp_kses_post; status is run through the strict allow-list validator (no 'any',
 * 'trash', or unknown statuses).
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_post( array $input ) {
	$id   = absint( $input['post_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Validate enrichment BEFORE wp_update_post so a bad term/attachment/meta aborts
	// with the post left exactly as it was (no half-applied update).
	$enrichment = aafm_validate_write_enrichment( $input );
	if ( is_wp_error( $enrichment ) ) {
		return $enrichment;
	}

	$postarr = array( 'ID' => $id );
	if ( isset( $input['title'] ) ) {
		$title = sanitize_text_field( (string) $input['title'] );
		if ( ! aafm_title_within_limit( $title ) ) {
			return new WP_Error( 'aafm_title_too_long', __( 'The title exceeds the maximum allowed length.', 'agent-abilities-for-mcp' ) );
		}
		$postarr['post_title'] = $title;
	}
	if ( isset( $input['content'] ) ) {
		$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( isset( $input['excerpt'] ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( (string) $input['excerpt'] );
	}
	if ( isset( $input['slug'] ) ) {
		$slug = sanitize_title( (string) $input['slug'] );
		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}
	}
	if ( isset( $input['status'] ) ) {
		// Gate non-public target statuses on the POST TYPE's own edit_others cap, not the
		// hardcoded 'edit_others_posts'. A custom type maps this to its own cap (e.g.
		// edit_others_products), so a CPT update is judged by that type's capability model,
		// never the generic post primitive (B5).
		$type_object = get_post_type_object( $post->post_type );
		$others_cap  = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->edit_others_posts : 'edit_others_posts';
		$status      = aafm_validate_post_status( (string) $input['status'], current_user_can( $others_cap ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		// Mirror the create-path force-draft guard: when the operator has force-draft on,
		// an explicit request for a public status is coerced to 'draft'. This only fires on
		// an explicit public-status request — an edit-only update with no 'status' field never
		// reaches here, so force-draft can never retro-unpublish an already-published post.
		$public_statuses = array_values( get_post_stati( array( 'public' => true ) ) );
		if ( aafm_force_draft() && in_array( $status, $public_statuses, true ) ) {
			$status = 'draft';
		}
		$postarr['post_status'] = $status;
	}

	$result = wp_update_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $result ) ) {
		return aafm_generic_error();
	}

	// Apply the pre-validated enrichment after the core fields land.
	aafm_apply_write_enrichment( (int) $result, $enrichment );

	// Re-fetch by the id wp_update_post() returned. A destructive save_post/post_updated
	// hook (or a TOCTOU race) can delete the post during the update, so this can be null;
	// guard it so the typed aafm_redact_post() degrades to a generic error, never a fatal.
	$updated = get_post( (int) $result );
	if ( ! $updated instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return array( 'post' => aafm_redact_post( $updated ) );
}

/**
 * Args for aafm/replace-in-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_replace_in_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/replace-in-post' ),
		'description'         => aafm_ability_description( 'aafm/replace-in-post' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'search'  => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'replace' => array( 'type' => 'string' ),
			),
			'required'             => array( 'post_id', 'search', 'replace' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post'         => array( 'type' => 'object' ),
				'replacements' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_replace_in_post',
		'permission_callback' => 'aafm_perm_replace_in_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/replace-in-post: per-object edit_post on the target post — the
 * established edit chokepoint. Reuses aafm_can_edit_post_object (floor + allowlist +
 * map_meta_cap + current_user_can edit_post).
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_replace_in_post( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
}

/**
 * Execute aafm/replace-in-post.
 *
 * Literal str_replace (no regex — avoids ReDoS/injection). Counts occurrences of the
 * search term BEFORE replacing. The replaced body is run through wp_kses_post so an agent
 * cannot inject script even via the replacement string, then written with
 * wp_update_post( wp_slash(...) ). Only post_content is written — status is never touched,
 * so this inherits nothing status-related and can never publish/unpublish. A search term
 * that does not occur is a no-op (replacements:0) returning the unchanged post, not an error.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_replace_in_post( array $input ) {
	$id   = absint( $input['post_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$search  = (string) $input['search'];
	$replace = (string) $input['replace'];
	$content = (string) $post->post_content;

	// Count occurrences against the original body; '' search is barred by schema minLength.
	$replacements = substr_count( $content, $search );

	if ( 0 === $replacements ) {
		// No-op: return the unchanged post with a zero count, never an error.
		return array(
			'post'         => aafm_redact_post( $post ),
			'replacements' => 0,
		);
	}

	$new = wp_kses_post( str_replace( $search, $replace, $content ) );

	$result = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_content' => $new,
			)
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return aafm_generic_error();
	}

	$updated = get_post( (int) $result );
	if ( ! $updated instanceof WP_Post ) {
		return aafm_generic_error();
	}

	return array(
		'post'         => aafm_redact_post( $updated ),
		'replacements' => $replacements,
	);
}

/**
 * Args for aafm/update-cpt-item.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_cpt_item(): array {
	$schema                          = aafm_write_content_schema( false );
	$schema['properties']['post_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$schema['required']              = array( 'post_id' );

	return array(
		'label'               => aafm_ability_label( 'aafm/update-cpt-item' ),
		'description'         => aafm_ability_description( 'aafm/update-cpt-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => $schema,
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_update_cpt_item',
		'permission_callback' => 'aafm_perm_update_cpt_item',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/update-cpt-item: per-object edit on a type that clears the floor+allowlist
 * AND is map_meta_cap (via aafm_can_edit_post_object), plus the type's publish cap when the
 * request asks to publish. The post_id resolves the type, so no post_type arg is needed here —
 * editing is per-object and the object knows its own type.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_update_cpt_item( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || ! aafm_can_edit_post_object( $post ) ) {
		return false;
	}
	if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
		$caps = aafm_type_caps( $post->post_type );
		return $caps['object'] instanceof WP_Post_Type
			&& current_user_can( (string) $caps['object']->cap->publish_posts );
	}
	return true;
}

/**
 * Execute aafm/update-cpt-item.
 *
 * Loads the target, re-validates its post_type against the allowlist+floor at execute time
 * (defense in depth against a de-allowlist race), then delegates to the existing, type-generic
 * aafm_exec_update_post() so the CPT update inherits status validation, content sanitization,
 * the force-draft public-status coercion, and the C2 enrichment with no duplicated logic.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_cpt_item( array $input ) {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || is_wp_error( aafm_validate_post_type( $post->post_type ) ) ) {
		return aafm_generic_error();
	}
	// The per-type publish gate is enforced in aafm_perm_update_cpt_item(), which the Abilities API
	// always runs before execute, so this executor intentionally does not re-check the publish cap.
	return aafm_exec_update_post( $input );
}

/**
 * Args for aafm/trash-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_trash_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/trash-post' ),
		'description'         => aafm_ability_description( 'aafm/trash-post' ),
		'category'            => 'aafm-writes',
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
			'properties' => array( 'trashed' => array( 'type' => 'boolean' ) ),
		),
		'execute_callback'    => 'aafm_exec_trash_post',
		'permission_callback' => 'aafm_perm_trash_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/trash-post: per-object delete_post.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_trash_post( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && aafm_can_delete_post_object( $post );
}

/**
 * Execute aafm/trash-post — wp_trash_post only (recoverable), never wp_delete_post.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_trash_post( array $input ) {
	if ( ! aafm_trash_is_enabled() ) {
		return aafm_trash_disabled_error();
	}
	$id = absint( $input['post_id'] );
	$ok = wp_trash_post( $id );
	if ( ! $ok ) {
		return aafm_generic_error();
	}
	return array( 'trashed' => true );
}

/**
 * Args for aafm/delete-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-post' ),
		'description'         => aafm_ability_description( 'aafm/delete-post' ),
		'category'            => 'aafm-writes',
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
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'execute_callback'    => 'aafm_exec_delete_post',
		'permission_callback' => 'aafm_perm_delete_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/delete-post: per-object delete_post (mirrors aafm_perm_trash_post).
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_delete_post( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && aafm_can_delete_post_object( $post );
}

/**
 * The single sanctioned force-delete executor: the only wp_delete_post(...,true) call site
 * in the abilities layer. delete-page delegates here with the page type pinned, so pages.php
 * never force-deletes directly — proven by SecurityRegressionTest::test_no_force_delete_in_source,
 * which sanctions only this file. Callers must have already capability-checked the id.
 *
 * Naming note: "force_delete" mirrors WordPress's own `wp_delete_post( $id, $force_delete = true )`
 * argument — it means "bypass the Trash and delete permanently," NOT "bypass the capability
 * guard." The permission callback (aafm_perm_delete_post / aafm_perm_delete_page) has already
 * run before any caller reaches here; this function performs no capability check of its own.
 *
 * @param int    $id            Post id (already capability-checked by the caller).
 * @param string $expected_type Post type to pin (e.g. 'page'), or '' to accept any.
 * @return array<string,mixed>|WP_Error
 */
function aafm_force_delete_post( int $id, string $expected_type = '' ) {
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( '' !== $expected_type && $expected_type !== $post->post_type ) {
		return aafm_generic_error();
	}
	$result = wp_delete_post( $id, true );
	if ( ! ( $result instanceof WP_Post ) && false === $result ) {
		return aafm_generic_error();
	}
	return array( 'deleted' => true );
}

/**
 * Execute aafm/delete-post — force-deletes through the single shared executor.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_post( array $input ) {
	return aafm_force_delete_post( absint( $input['post_id'] ?? 0 ) );
}
