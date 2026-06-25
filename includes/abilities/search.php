<?php
/**
 * Cross-type search ability (read). One query across every exposed content type.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_search_definitions' );

/**
 * Contribute the cross-type search ability definition to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_search_definitions( array $registry ): array {
	$registry['oversio/search-content'] = array(
		'label'        => __( 'Search content', 'oversio-agent-abilities' ),
		'description'  => __( 'Search across the exposed content types in one query. Each result returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per result. Response includes total.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_search_content',
	);
	return $registry;
}

/**
 * Args for oversio/search-content.
 *
 * @return array<string,mixed>
 */
function oversio_args_search_content(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/search-content' ),
		'description'         => oversio_ability_description( 'oversio/search-content' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'search'          => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'post_types'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'status'          => array(
					'type'        => 'string',
					'default'     => 'publish',
					'description' => __( 'A single post status to search within. Public statuses (publish and any custom public status) are always allowed; the non-public statuses draft, pending, future, and private are accepted only when the caller can read private content. Aggregate values (any, trash, auto-draft, inherit) are rejected.', 'oversio-agent-abilities' ),
				),
				'page'            => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_LIST_PAGE_MAX,
				),
				'per_page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_LIST_PER_PAGE_MAX,
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
			'required'             => array( 'search' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'results' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => oversio_rich_post_output_properties(),
					),
				),
				'total'   => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_search_content',
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
 * Execute oversio/search-content.
 *
 * Searches across the allowlisted post types (narrowed, never widened, by post_types),
 * status-guarded with per-type private-read containment, returning redacted metadata only.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_search_content( array $input ) {
	$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
	if ( '' === $search ) {
		return oversio_generic_error();
	}
	$requested = ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) ? $input['post_types'] : array();
	$types     = oversio_resolve_search_post_types( $requested );
	if ( empty( $types ) ) {
		return array(
			'results' => array(),
			'total'   => 0,
		);
	}

	// read_private_posts is a deliberate FLOOR gate here, NOT the per-type cap that get-posts
	// derives from each object. The per-type filter below is what actually contains cross-type
	// private leakage. Do not "harmonize" this with get-posts' per-object cap — that would
	// loosen the floor and let a caller through who can't privately read any exposed type.
	$status = oversio_validate_post_status( (string) ( $input['status'] ?? 'publish' ), current_user_can( 'read_private_posts' ) );
	if ( is_wp_error( $status ) ) {
		return $status;
	}
	// Per-type private-read containment: for a non-public status, keep only types whose own
	// read_private cap the caller holds, so a cross-type search can't leak private CPT content.
	if ( ! in_array( $status, get_post_stati( array( 'public' => true ) ), true ) ) {
		$types = array_values(
			array_filter(
				$types,
				static function ( string $t ): bool {
					$o = get_post_type_object( $t );
					return $o instanceof WP_Post_Type && current_user_can( (string) $o->cap->read_private_posts );
				}
			)
		);
		if ( empty( $types ) ) {
			return array(
				'results' => array(),
				'total'   => 0,
			);
		}
	}

	$paging  = oversio_paginate_args( $input, OVERSIO_LIST_PER_PAGE_MAX );
	$query   = new WP_Query(
		array(
			'post_type'        => $types,
			'post_status'      => $status,
			's'                => $search,
			'posts_per_page'   => $paging['per_page'],
			'paged'            => $paging['page'],
			'no_found_rows'    => false,
			'suppress_filters' => false,
		)
	);
	$objects = array_filter( $query->posts, static fn( $p ): bool => $p instanceof WP_Post );

	$format          = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'rendered';
	$include_content = ! empty( $input['include_content'] );
	$options         = array(
		'content_format'  => $format,
		'include_content' => $include_content,
	);

	return array(
		'results' => array_map(
			static fn( WP_Post $p ): array => oversio_rich_post( $p, $options ),
			array_values( $objects )
		),
		'total'   => (int) $query->found_posts,
	);
}
