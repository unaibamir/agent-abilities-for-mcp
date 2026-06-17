<?php
/**
 * Post abilities (reads). Writes are appended in Phase 4.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_posts_definitions' );

/**
 * Contribute post ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_posts_definitions( array $registry ): array {
	$registry['aafm/get-posts']    = array(
		'label'        => __( 'Get posts', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List posts filtered by type, status, and search term. Each item returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per item. Response includes total.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_posts',
	);
	$registry['aafm/get-post']     = array(
		'label'        => __( 'Get post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Retrieve a single post by ID. Returns id, title, status, type, slug, link, author {id, display_name}, dates, full content (rendered HTML by default, or raw markup via content_format; omitted for password-protected posts), excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and meta (allowlisted scalar values only).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_post',
	);
	$registry['aafm/create-draft'] = array(
		'label'        => __( 'Create draft', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create a new draft post. The agent drafts; a human publishes. Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_draft',
	);
	$registry['aafm/create-post']  = array(
		'label'        => __( 'Create post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create and publish a post (requires publish capability). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_post',
	);
	$registry['aafm/update-post']  = array(
		'label'        => __( 'Update post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Update an existing post by ID (publishing is a separate gate). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_update_post',
	);
	$registry['aafm/trash-post']   = array(
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
	return $registry;
}

/**
 * Args for aafm/get-posts.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_posts(): array {
	return array(
		'label'               => __( 'Get posts', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List posts filtered by type, status, and search term. Each item returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per item. Response includes total.', 'agent-abilities-for-mcp' ),
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
				),
				'per_page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
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

	$paging = aafm_paginate_args( $input, 50 );

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
 * Args for aafm/get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_post(): array {
	return array(
		'label'               => __( 'Get post', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Retrieve a single post by ID. Returns id, title, status, type, slug, link, author {id, display_name}, dates, full content (rendered HTML by default, or raw markup via content_format; omitted for password-protected posts), excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and meta (allowlisted scalar values only).', 'agent-abilities-for-mcp' ),
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
		'type'      => 'string',
		'minLength' => 1,
	);
	$required           = $schema['required'] ?? array();
	$required[]         = 'post_type';
	$schema['required'] = array_values( array_unique( $required ) );
	return $schema;
}

/**
 * Args for aafm/create-draft.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_draft(): array {
	return array(
		'label'               => __( 'Create draft', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Create a new draft post. The agent drafts; a human publishes. Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
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
		'label'               => __( 'Create post', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Create and publish a post (requires publish capability). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
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
		'label'               => __( 'Create content item', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Create an item of an allowlisted custom content type (post_type). Publishing requires that type\'s publish capability; otherwise the item is created as a draft. Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
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
	// holds the type's publish cap. (force-draft inside aafm_insert_post may still coerce back.)
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
		'label'               => __( 'Update post', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Update an existing post by ID (publishing is a separate gate). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'agent-abilities-for-mcp' ),
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
	$id = absint( $input['post_id'] );
	if ( ! get_post( $id ) instanceof WP_Post ) {
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
		$status = aafm_validate_post_status( (string) $input['status'], current_user_can( 'edit_others_posts' ) );
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
 * Args for aafm/trash-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_trash_post(): array {
	return array(
		'label'               => __( 'Trash post', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Move a post to trash (recoverable, never permanently deleted).', 'agent-abilities-for-mcp' ),
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
