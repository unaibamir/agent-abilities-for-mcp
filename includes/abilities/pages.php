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
		'description'  => __( 'List pages filtered by status and search term.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_pages',
	);
	$registry['aafm/get-page']    = array(
		'label'        => __( 'Get page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Retrieve a single page by ID.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_get_page',
	);
	$registry['aafm/create-page'] = array(
		'label'        => __( 'Create page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create and publish a page (requires publish_pages).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_create_page',
	);
	$registry['aafm/update-page'] = array(
		'label'        => __( 'Update page', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Update an existing page by ID (publishing is a separate gate).', 'agent-abilities-for-mcp' ),
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
	return $registry;
}

/**
 * Args for aafm/get-pages.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_pages(): array {
	return array(
		'label'               => __( 'Get pages', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List pages filtered by status and search term.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'status'   => array(
					'type'    => 'string',
					'default' => 'publish',
				),
				'search'   => array( 'type' => 'string' ),
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
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'posts' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
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
			),
		),
	);
}

/**
 * Execute aafm/get-pages — delegates to the post query with post_type forced to page.
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
		'label'               => __( 'Get page', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Retrieve a single page by ID.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
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
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_get_page',
		'permission_callback' => 'aafm_perm_get_page',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/get-page: read, plus per-object edit for non-public pages.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_get_page( array $input ): bool {
	if ( ! current_user_can( 'read' ) ) {
		return false;
	}
	$id   = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}
	$public_statuses = get_post_stati( array( 'public' => true ) );
	if ( in_array( $post->post_status, $public_statuses, true ) ) {
		return true;
	}
	return current_user_can( 'edit_page', $id );
}

/**
 * Execute aafm/get-page.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_page( array $input ) {
	$id   = absint( $input['page_id'] );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return aafm_generic_error();
	}
	return array( 'post' => aafm_redact_post( $post ) );
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
		'label'               => __( 'Create page', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Create and publish a page (requires publish_pages).', 'agent-abilities-for-mcp' ),
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
 * Execute aafm/create-page — type pinned to 'page', status forced to 'publish'.
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
		'label'               => __( 'Update page', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Update an existing page by ID (publishing is a separate gate).', 'agent-abilities-for-mcp' ),
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
	$id = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	if ( ! $id || ! current_user_can( 'edit_page', $id ) ) {
		return false;
	}
	if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
		return current_user_can( 'publish_pages' );
	}
	return true;
}

/**
 * Execute aafm/update-page — pins to the page type, then reuses the post updater.
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
		'label'               => __( 'Trash page', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Move a page to trash (recoverable, never permanently deleted).', 'agent-abilities-for-mcp' ),
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
	$id = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
	return $id > 0 && current_user_can( 'delete_page', $id );
}

/**
 * Execute aafm/trash-page — wp_trash_post only (recoverable), never wp_delete_post.
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
