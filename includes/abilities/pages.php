<?php
/**
 * Page abilities (reads and writes).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_pages_definitions' );

/**
 * Contribute page ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_pages_definitions( array $registry ): array {
	$registry['oversio/get-pages']   = array(
		'label'        => __( 'Get pages', 'oversio-agent-abilities' ),
		'description'  => __( 'List pages filtered by status and search term. Each item returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per item. Response includes total.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_get_pages',
	);
	$registry['oversio/get-page']    = array(
		'label'        => __( 'Get page', 'oversio-agent-abilities' ),
		'description'  => __( 'Retrieve a single page by ID. Returns id, title, status, type, slug, link, author {id, display_name}, dates, full content (rendered HTML by default, or raw markup via content_format; omitted for password-protected pages), excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and meta (allowlisted scalar values only).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_get_page',
	);
	$registry['oversio/create-page'] = array(
		'label'        => __( 'Create page', 'oversio-agent-abilities' ),
		'description'  => __( 'Create and publish a page (requires publish_pages). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_create_page',
	);
	$registry['oversio/update-page'] = array(
		'label'        => __( 'Update page', 'oversio-agent-abilities' ),
		'description'  => __( 'Update an existing page by ID (publishing is a separate gate). Optional: slug, featured_media (attachment id), terms ({taxonomy: [termId]}, replaces existing terms per taxonomy), and meta ({key: value}, allowlisted keys only).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_update_page',
	);
	$registry['oversio/trash-page']  = array(
		'label'        => __( 'Trash page', 'oversio-agent-abilities' ),
		'description'  => __( 'Move a page to trash (recoverable, never permanently deleted).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_trash_page',
	);
	$registry['oversio/delete-page'] = array(
		'label'        => __( 'Delete page', 'oversio-agent-abilities' ),
		'description'  => __( 'Permanently delete a page, bypassing the Trash. This cannot be undone — use trash-page to remove a page recoverably instead.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_delete_page',
	);
	return $registry;
}

/**
 * Args for oversio/get-pages.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_pages(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-pages' ),
		'description'         => oversio_ability_description( 'oversio/get-pages' ),
		'category'            => 'oversio-reads',
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
						'properties' => oversio_rich_post_output_properties(),
					),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_get_pages',
		'permission_callback' => 'oversio_perm_read',
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
 * Execute oversio/get-pages — delegates to the post query with post_type forced to page.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_pages( array $input ) {
	$input['post_type'] = 'page';
	return oversio_exec_get_posts( $input );
}

/**
 * Args for oversio/get-page.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_page(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-page' ),
		'description'         => oversio_ability_description( 'oversio/get-page' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
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
			'required'             => array( 'page_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post' => array(
					'type'       => 'object',
					'properties' => oversio_rich_post_output_properties(),
				),
			),
		),
		'execute_callback'    => 'oversio_exec_get_page',
		'permission_callback' => 'oversio_perm_get_page',
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
 * Permission for oversio/get-page: read, plus per-object edit for non-public pages.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_get_page( array $input ): bool {
	if ( ! current_user_can( 'read' ) ) {
		return false;
	}
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is still rejected, then delegate to the shared gate.
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	return oversio_can_read_post_object( $post );
}

/**
 * Execute oversio/get-page.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_page( array $input ) {
	$id   = absint( $input['page_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return oversio_generic_error();
	}
	$format = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'rendered';
	return array(
		'post' => oversio_rich_post( $post, array( 'content_format' => $format ) ),
	);
}

/**
 * Args for oversio/create-page.
 *
 * Reuses the shared closed write schema (additionalProperties:false) from
 * posts.php, so post_author / post_type / meta_input cannot be smuggled in.
 *
 * @return array<string,mixed>
 */
function oversio_args_create_page(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/create-page' ),
		'description'         => oversio_ability_description( 'oversio/create-page' ),
		'category'            => 'oversio-writes',
		'input_schema'        => oversio_write_content_schema( true ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'oversio_exec_create_page',
		'permission_callback' => 'oversio_perm_publish_pages',
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
function oversio_perm_publish_pages(): bool {
	return current_user_can( 'publish_pages' );
}

/**
 * Execute oversio/create-page — type pinned to 'page', status forced to 'publish'.
 *
 * Delegates to the shared oversio_insert_post(), which never threads post_author
 * (so authorship is forced to the agent user) and sanitizes title/content.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_create_page( array $input ) {
	return oversio_insert_post( $input, 'publish', 'page' );
}

/**
 * Args for oversio/update-page.
 *
 * @return array<string,mixed>
 */
function oversio_args_update_page(): array {
	$schema                          = oversio_write_content_schema( false );
	$schema['properties']['page_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$schema['required']              = array( 'page_id' );

	return array(
		'label'               => oversio_ability_label( 'oversio/update-page' ),
		'description'         => oversio_ability_description( 'oversio/update-page' ),
		'category'            => 'oversio-writes',
		'input_schema'        => $schema,
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'oversio_exec_update_page',
		'permission_callback' => 'oversio_perm_update_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/update-page: per-object edit_page, plus publish_pages when publishing.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_update_page( array $input ): bool {
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is rejected, then gate the edit through the
	// shared chokepoint (floor + allowlist + map_meta_cap; resolves to edit_page for pages).
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || ! oversio_can_edit_post_object( $post ) ) {
		return false;
	}
	if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
		// Derive the publish cap from the page type object for consistency; resolves to publish_pages.
		$obj = get_post_type_object( 'page' );
		return $obj instanceof WP_Post_Type && current_user_can( (string) $obj->cap->publish_posts );
	}
	return true;
}

/**
 * Execute oversio/update-page — pins to the page type, then reuses the post updater.
 *
 * The id must resolve to an existing page; a non-page id is rejected so the
 * ability can never be used to edit a post (or any other type) by ID confusion.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_update_page( array $input ) {
	$id   = absint( $input['page_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return oversio_generic_error();
	}
	$input['post_id'] = $id;
	return oversio_exec_update_post( $input );
}

/**
 * Args for oversio/trash-page.
 *
 * @return array<string,mixed>
 */
function oversio_args_trash_page(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/trash-page' ),
		'description'         => oversio_ability_description( 'oversio/trash-page' ),
		'category'            => 'oversio-writes',
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
		'execute_callback'    => 'oversio_exec_trash_page',
		'permission_callback' => 'oversio_perm_trash_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for oversio/trash-page: per-object delete_page.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_trash_page( array $input ): bool {
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is rejected, then delegate to the shared delete gate.
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	return oversio_can_delete_post_object( $post );
}

/**
 * Execute oversio/trash-page — wp_trash_post only (recoverable), never wp_delete_post.
 *
 * The id must resolve to an existing page; a non-page id is rejected so the
 * ability can never trash a post by ID confusion.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_trash_page( array $input ) {
	if ( ! oversio_trash_is_enabled() ) {
		return oversio_trash_disabled_error();
	}
	$id   = absint( $input['page_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return oversio_generic_error();
	}
	if ( ! wp_trash_post( $id ) ) {
		return oversio_generic_error();
	}
	return array( 'trashed' => true );
}

/**
 * Args for oversio/delete-page.
 *
 * @return array<string,mixed>
 */
function oversio_args_delete_page(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/delete-page' ),
		'description'         => oversio_ability_description( 'oversio/delete-page' ),
		'category'            => 'oversio-writes',
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
		'execute_callback'    => 'oversio_exec_delete_page',
		'permission_callback' => 'oversio_perm_delete_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for oversio/delete-page: type-pin to a page, then per-object delete_page.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_delete_page( array $input ): bool {
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	// Keep the type pin so a non-page id is rejected, then delegate to the shared delete gate.
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	return oversio_can_delete_post_object( $post );
}

/**
 * Execute oversio/delete-page — delegates to the single sanctioned force-delete executor
 * with the page type pinned, so pages.php never calls wp_delete_post directly (one call
 * site, in posts.php).
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_delete_page( array $input ) {
	return oversio_force_delete_post( absint( $input['page_id'] ?? 0 ), 'page' );
}
