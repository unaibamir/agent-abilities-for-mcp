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
	$registry['aafm/get-posts'] = array(
		'label'        => __( 'Get posts', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List posts filtered by type, status, and search term.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'args_builder' => 'aafm_args_get_posts',
	);
	$registry['aafm/get-post']  = array(
		'label'        => __( 'Get post', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Retrieve a single post by ID.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'args_builder' => 'aafm_args_get_post',
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
		'description'         => __( 'List posts filtered by type, status, and search term.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'status'    => array(
					'type'    => 'string',
					'default' => 'publish',
				),
				'search'    => array( 'type' => 'string' ),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page'  => array(
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

	$can_private = current_user_can( 'read_private_posts' );
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
	$posts   = array_map( 'aafm_redact_post', $objects );

	return array(
		'posts' => array_values( $posts ),
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
		'description'         => __( 'Retrieve a single post by ID.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
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
			'properties' => array( 'post' => array( 'type' => 'object' ) ),
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
	$public_statuses = get_post_stati( array( 'public' => true ) );
	if ( in_array( $post->post_status, $public_statuses, true ) ) {
		return true;
	}
	return current_user_can( 'edit_post', $id );
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
	return array( 'post' => aafm_redact_post( $post ) );
}
