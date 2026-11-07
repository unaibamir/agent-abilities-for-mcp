<?php
/**
 * Cross-type search ability (read). One query across every exposed content type.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_search_definitions' );

/**
 * Contribute the cross-type search ability definition to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_search_definitions( array $registry ): array {
	$registry['aafm/search-content'] = array(
		'label'        => __( 'Search content', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Search across the exposed content types in one query. Each result returns id, title, status, type, slug, link, author {id, display_name}, dates, excerpt, terms grouped by taxonomy, featured_image {id, url, alt} or null, and allowlisted meta. Set include_content=true to also return full content per result. Response includes total.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_search_content',
	);
	return $registry;
}

/**
 * Args for aafm/search-content.
 *
 * @return array<string,mixed>
 */
function aafm_args_search_content(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/search-content' ),
		'description'         => aafm_ability_description( 'aafm/search-content' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'search'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Free-text search term matched against title and content across every searched type, using WordPress\'s normal search matching.', 'agent-abilities-for-mcp' ),
					),
					'post_types' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Content type slugs to narrow the search to. Only types already on the operator\'s exposed allowlist are searched; a requested type outside it is dropped rather than rejected and named in skipped_post_types. Omit to search every exposed type.', 'agent-abilities-for-mcp' ),
					),
					'status'     => array(
						'type'        => 'string',
						'default'     => 'publish',
						'description' => __( 'A single post status to search within. Public statuses (publish and any custom public status) are always allowed; the non-public statuses draft, pending, future, and private are accepted only when the caller can read private content. Aggregate values (any, trash, auto-draft, inherit) are rejected.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_pagination_schema_props(
					AAFM_LIST_PER_PAGE_MAX,
					__( 'Number of results per page, clamped to the 1-50 range regardless of the value requested. Defaults to 10 when omitted.', 'agent-abilities-for-mcp' ),
					__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
				),
				array(
					'content_format'  => array(
						'type'        => 'string',
						'enum'        => array( 'rendered', 'raw' ),
						'default'     => 'rendered',
						'description' => __( 'Format for each result\'s content when include_content is true: rendered HTML or raw block markup. Has no effect when include_content is false.', 'agent-abilities-for-mcp' ),
					),
					'include_content' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When true, also returns each result\'s full content (in content_format). Defaults to false, returning metadata only.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_lang_schema_fragment()
			),
			'required'             => array( 'search' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'results'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_rich_post_output_properties(),
					),
				),
				'total'              => array( 'type' => 'integer' ),
				'skipped_post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Requested or exposed types left out of this search: outside the allowlist, or not privately readable by the caller for a non-public status. Present only when something was left out.', 'agent-abilities-for-mcp' ),
				),
				'language'           => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The WPML language the list was scoped to ("all" for every language), or null when WPML is inactive.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_search_content',
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
 * Execute aafm/search-content.
 *
 * Searches across the allowlisted post types (narrowed, never widened, by post_types),
 * status-guarded with per-type private-read containment, returning redacted metadata only.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_search_content( array $input ) {
	$lang = aafm_resolve_lang( $input );
	if ( is_wp_error( $lang ) ) {
		return $lang;
	}
	$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
	if ( '' === $search ) {
		return aafm_generic_error();
	}
	$requested = ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) ? $input['post_types'] : array();
	$types     = aafm_resolve_search_post_types( $requested );
	// Types the caller asked for but the allowlist dropped, so an empty or thin result is not read
	// as "nothing matched there".
	$skipped = array_values( array_diff( array_map( 'sanitize_key', array_map( 'strval', $requested ) ), $types ) );
	$respond = static function ( array $results, int $total ) use ( $lang, &$skipped ): array {
		$out     = array(
			'results'  => $results,
			'total'    => $total,
			'language' => $lang,
		);
		$skipped = array_slice( array_values( array_unique( array_filter( $skipped ) ) ), 0, 50 );
		if ( ! empty( $skipped ) ) {
			$out['skipped_post_types'] = $skipped;
		}
		return $out;
	};
	if ( empty( $types ) ) {
		return $respond( array(), 0 );
	}

	// read_private_posts is a deliberate FLOOR gate here, NOT the per-type cap that get-posts
	// derives from each object. The per-type filter below is what actually contains cross-type
	// private leakage. Do not "harmonize" this with get-posts' per-object cap - that would
	// loosen the floor and let a caller through who can't privately read any exposed type.
	$status = aafm_validate_post_status( (string) ( $input['status'] ?? 'publish' ), current_user_can( 'read_private_posts' ) );
	if ( is_wp_error( $status ) ) {
		return $status;
	}
	// Per-type private-read containment: for a non-public status, keep only types whose own
	// read_private cap the caller holds, so a cross-type search can't leak private CPT content.
	if ( ! in_array( $status, get_post_stati( array( 'public' => true ) ), true ) ) {
		$readable = array_values(
			array_filter(
				$types,
				static function ( string $t ): bool {
					$o = get_post_type_object( $t );
					return $o instanceof WP_Post_Type && current_user_can( (string) $o->cap->read_private_posts );
				}
			)
		);
		$skipped  = array_merge( $skipped, array_values( array_diff( $types, $readable ) ) );
		$types    = $readable;
		if ( empty( $types ) ) {
			return $respond( array(), 0 );
		}
	}

	// Someone else's draft, pending or scheduled item is found only in the types where this user
	// may edit others' items. One query covers every type, so a global author limit would also hide
	// drafts the caller may see in other types: the limit is per type, through a posts_where filter
	// scoped to this query by a marker, which keeps pagination and total exact.
	$restricted   = array_values(
		array_filter(
			$types,
			static function ( string $t ) use ( $status ): bool {
				$o = get_post_type_object( $t );
				return $o instanceof WP_Post_Type && aafm_status_needs_own_posts_only( $status, $o );
			}
		)
	);
	$open         = array_values( array_diff( $types, $restricted ) );
	$query_marker = array() === $restricted ? '' : 'aafm_search_own_' . wp_generate_password( 12, false, false );
	$own_filter   = static function ( string $where, WP_Query $query ) use ( $restricted, $open, $query_marker ): string {
		if ( '' === $query_marker || $query_marker !== $query->get( 'aafm_query_marker' ) ) {
			return $where;
		}
		global $wpdb;
		$own = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is internal; the IN list holds only generated %s placeholders.
			"( {$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $restricted ), '%s' ) ) . ") AND {$wpdb->posts}.post_author = %d )",
			array_merge( $restricted, array( get_current_user_id() ) )
		);
		if ( array() === $open ) {
			return $where . ' AND ' . $own;
		}
		$any = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is internal; the IN list holds only generated %s placeholders.
			"{$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $open ), '%s' ) ) . ')',
			$open
		);
		return $where . ' AND ( ' . $any . ' OR ' . $own . ' )';
	};

	$paging      = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$build_query = static function () use ( $types, $status, $search, $paging, $query_marker, $own_filter ): WP_Query {
		$args = array(
			'post_type'              => $types,
			'post_status'            => $status,
			's'                      => $search,
			'posts_per_page'         => $paging['per_page'],
			'paged'                  => $paging['page'],
			'no_found_rows'          => false,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
		);
		if ( '' === $query_marker ) {
			return new WP_Query( $args );
		}
		$args['aafm_query_marker'] = $query_marker;
		add_filter( 'posts_where', $own_filter, 10, 2 );
		try {
			return new WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', $own_filter, 10 );
		}
	};

	$format          = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'rendered';
	$include_content = ! empty( $input['include_content'] );
	$options         = array(
		'content_format'  => $format,
		'include_content' => $include_content,
	);

	// aafm_rich_post() must run
	// INSIDE the query's own aafm_with_language() scope on BOTH branches, not only 'all' - an
	// explicit single language has the identical gap, since aafm_with_language() restores the
	// original language before returning and the shape step used to run after that restore.
	// Same reasoning as aafm_exec_get_posts() in posts.php - see that function's comment for
	// the full explanation and WpmlLangAllTest/WpmlLanguageTest for the regression proofs.
	$shape_language = static function ( ?string $code ) use ( $build_query, $options ): array {
		return aafm_with_language(
			$code,
			static function () use ( $build_query, $options ): array {
				$query = $build_query();
				aafm_prime_post_meta_checked( wp_list_pluck( $query->posts, 'ID' ) );
				return array(
					'rows'  => array_map(
						static fn( WP_Post $p ): array => aafm_rich_post( $p, $options ),
						array_values( array_filter( $query->posts, static fn( $p ): bool => $p instanceof WP_Post ) )
					),
					'found' => (int) $query->found_posts,
				);
			}
		);
	};

	list( $results, $total ) = aafm_collect_by_language( $lang, $shape_language );

	return $respond( $results, (int) $total );
}
