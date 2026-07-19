<?php
/**
 * Page abilities (reads and writes).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_pages_definitions' );

/**
 * Contribute page ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_pages_definitions( array $registry ): array {
	$registry['aafm/get-pages']   = array(
		'label'        => __( 'Get pages', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List pages filtered by status and search term. Each item returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per item. Response includes total.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_pages',
	);
	$registry['aafm/get-page']    = array(
		'label'        => __( 'Get page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Retrieve a single page by ID. Returns id, title, status, type, slug, link, author {id, display_name}, dates, full content (rendered HTML by default, or raw markup via content_format; omitted for password-protected pages), excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and meta (allowlisted scalar values only).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_page',
	);
	$registry['aafm/create-page'] = array(
		'label'        => __( 'Create page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create and publish a page (requires publish_pages). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only). Put any block styling in the block delimiter attributes, not inline style, or the editor marks the content invalid.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_page',
	);
	$registry['aafm/update-page'] = array(
		'label'        => __( 'Update page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Update an existing page by ID (publishing is a separate gate). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only). Put any block styling in the block delimiter attributes, not inline style, or the editor marks the content invalid.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_update_page',
	);
	$registry['aafm/trash-page']  = array(
		'label'        => __( 'Trash page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Move a page to trash (recoverable, never permanently deleted).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_trash_page',
	);
	$registry['aafm/delete-page'] = array(
		'label'        => __( 'Delete page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently delete a page, bypassing the Trash. This cannot be undone - use trash-page to remove a page recoverably instead.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_delete_page',
	);
	return $registry;
}

/**
 * Args for aafm/get-pages.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_pages(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-pages' ),
		'description'         => aafm_ability_description( 'aafm/get-pages' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
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
		'execute_callback'    => 'aafm_exec_get_pages',
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
 * Execute aafm/get-pages - delegates to the post query with post_type forced to page.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_pages( array $input ) {
	$input['post_type'] = 'page';
	return aafm_exec_get_posts( $input );
}

/**
 * Args for aafm/get-page.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_page(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-page' ),
		'description'         => aafm_ability_description( 'aafm/get-page' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'page_id'        => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'content_format' => array(
						'type'    => 'string',
						'enum'    => array( 'rendered', 'raw' ),
						'default' => 'rendered',
					),
				),
				aafm_lang_schema_fragment()
			),
			'required'             => array( 'page_id' ),
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
		'execute_callback'    => 'aafm_exec_get_page',
		'permission_callback' => 'aafm_perm_get_page',
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
 * Permission for aafm/get-page: read, plus per-object edit for non-public pages.
 *
 * When a `lang` is requested, the id is resolved to that language's translation
 * FIRST, so the capability check below runs against the id that will actually be
 * loaded - a caller cannot pass a readable id but have a different, unreadable
 * translation served.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_get_page( array $input ): bool {
	if ( ! current_user_can( 'read' ) ) {
		return false;
	}
	$id = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	if ( $id ) {
		$id = aafm_get_page_lang_resolved_id( $id, $input );
	}
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is still rejected, then delegate to the shared gate.
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	return aafm_can_read_post_object( $post );
}

/**
 * Resolve a requested page id to its WPML translation per the caller's `lang`
 * input. Feature-detected no-op: returns $id unchanged when WPML is off, no
 * `lang` was requested, or `lang` is 'all' (no single translation to resolve to).
 *
 * @param int                 $id    Original page id.
 * @param array<string,mixed> $input Ability input (read for `lang`).
 * @return int
 */
function aafm_get_page_lang_resolved_id( int $id, array $input ): int {
	$lang = aafm_resolve_lang( $input );
	return ( null !== $lang && 'all' !== $lang )
		? aafm_wpml_translated_id( $id, 'page', $lang )
		: $id;
}

/**
 * Execute aafm/get-page.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_page( array $input ) {
	$id   = aafm_get_page_lang_resolved_id( absint( $input['page_id'] ), $input );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return aafm_generic_error();
	}
	$format = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'rendered';
	return array(
		'post' => aafm_rich_post( $post, array( 'content_format' => $format ) ),
	);
}

/**
 * Args for aafm/create-page.
 *
 * Reuses the shared closed write schema (additionalProperties:false) from
 * posts.php, so post_author / post_type / meta_input cannot be smuggled in.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_page(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-page' ),
		'description'         => aafm_ability_description( 'aafm/create-page' ),
		'category'            => 'aafm-writes',
		'input_schema'        => aafm_write_content_schema( true ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_create_page',
		'permission_callback' => 'aafm_perm_publish_pages',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission: publish_pages (publish is a separate gate from edit).
 *
 * @return bool
 */
function aafm_perm_publish_pages(): bool {
	return current_user_can( 'publish_pages' );
}

/**
 * Execute aafm/create-page - type pinned to 'page', status forced to 'publish'.
 *
 * Delegates to the shared aafm_insert_post(), which never threads post_author
 * (so authorship is forced to the agent user) and sanitizes title/content.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_page( array $input ) {
	return aafm_insert_post( $input, 'publish', 'page' );
}

/**
 * Args for aafm/update-page.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_page(): array {
	$schema                          = aafm_write_content_schema( false );
	$schema['properties']['page_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$schema['required']              = array( 'page_id' );

	return array(
		'label'               => aafm_ability_label( 'aafm/update-page' ),
		'description'         => aafm_ability_description( 'aafm/update-page' ),
		'category'            => 'aafm-writes',
		'input_schema'        => $schema,
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_update_page',
		'permission_callback' => 'aafm_perm_update_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/update-page: per-object edit_page, plus publish_pages when publishing.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_update_page( array $input ): bool {
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is rejected, then gate the edit through the
	// shared chokepoint (floor + allowlist + map_meta_cap; resolves to edit_page for pages).
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || ! aafm_can_edit_post_object( $post ) ) {
		return false;
	}
	if ( isset( $input['status'] ) && aafm_status_requires_publish_cap( (string) $input['status'] ) ) {
		// Mirror aafm_perm_update_post: any public status (not just literal 'publish', so
		// custom public statuses are covered too) requires the type's publish cap, which
		// resolves to publish_pages for pages.
		$caps = aafm_type_caps( $post->post_type );
		return $caps['object'] instanceof WP_Post_Type
			&& current_user_can( (string) $caps['object']->cap->publish_posts );
	}
	return true;
}

/**
 * Execute aafm/update-page - pins to the page type, then reuses the post updater.
 *
 * The id must resolve to an existing page; a non-page id is rejected so the
 * ability can never be used to edit a post (or any other type) by ID confusion.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_page( array $input ) {
	$id   = absint( $input['page_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return aafm_generic_error();
	}
	$input['post_id'] = $id;
	return aafm_exec_update_post( $input );
}

/**
 * Args for aafm/trash-page.
 *
 * @return array<string,mixed>
 */
function aafm_args_trash_page(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/trash-page' ),
		'description'         => aafm_ability_description( 'aafm/trash-page' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'page_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'page_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'trashed' => array( 'type' => 'boolean' ) ),
		),
		'execute_callback'    => 'aafm_exec_trash_page',
		'permission_callback' => 'aafm_perm_trash_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/trash-page: per-object delete_page.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_trash_page( array $input ): bool {
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is rejected, then delegate to the shared delete gate.
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	return aafm_can_delete_post_object( $post );
}

/**
 * Execute aafm/trash-page - wp_trash_post only (recoverable), never wp_delete_post.
 *
 * The id must resolve to an existing page; a non-page id is rejected so the
 * ability can never trash a post by ID confusion.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_trash_page( array $input ) {
	if ( ! aafm_trash_is_enabled() ) {
		return aafm_trash_disabled_error();
	}
	$id   = absint( $input['page_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return aafm_generic_error();
	}
	if ( ! wp_trash_post( $id ) ) {
		return aafm_generic_error();
	}
	return array( 'trashed' => true );
}

/**
 * Args for aafm/delete-page.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_page(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-page' ),
		'description'         => aafm_ability_description( 'aafm/delete-page' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'page_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'page_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'execute_callback'    => 'aafm_exec_delete_page',
		'permission_callback' => 'aafm_perm_delete_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/delete-page: type-pin to a page, then per-object delete_page.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_delete_page( array $input ): bool {
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is rejected, then delegate to the shared delete gate.
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	return aafm_can_delete_post_object( $post );
}

/**
 * Execute aafm/delete-page - delegates to the single sanctioned force-delete executor
 * with the page type pinned, so pages.php never calls wp_delete_post directly (one call
 * site, in posts.php).
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_page( array $input ) {
	return aafm_force_delete_post( absint( $input['page_id'] ?? 0 ), 'page' );
}
