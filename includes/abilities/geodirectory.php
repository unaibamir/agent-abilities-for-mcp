<?php
/**
 * GeoDirectory integration (default-off, lowest priority per the release spec): a lean
 * list/get/create/update surface over gd_place listings.
 *
 * Registers ONLY when GeoDirectory is active (aafm_integration_active('geodirectory')). Native,
 * not bridged: `wp_register_ability` was confirmed absent from every file under includes/ in the
 * installed copy (2.8.179) - see Task 22's re-verification note in the plan close report.
 *
 * gd_place registers with the literal 'capability_type' => 'post' and 'map_meta_cap' => true
 * (includes/class-geodir-post-types.php:150,154, confirmed 2026-09-05 against the installed
 * copy), so its mapped capabilities are the SAME primitive names the built-in post type uses
 * (edit_posts, edit_others_posts, edit_published_posts, edit_post/read_post/delete_post per
 * object) - these abilities gate directly on those literal capability strings rather than routing
 * through this plugin's own post-type exposure allowlist (aafm_can_edit_post_object() and
 * friends), since GeoDirectory is its own separately-gated integration, exactly like TEC's own
 * per-object caps bypass that same allowlist.
 *
 * GeoDirectory does not store its fields as ordinary post meta: geodir_get_post_info() reads a
 * per-post-type custom database table (wp_geodir_gd_place_detail), and geodir_save_post_meta()
 * writes to it. The address/lat/lng column names below were re-verified against the installed
 * copy's own schema-creation code (includes/admin/class-geodir-admin-install.php:1146-1153,
 * 2026-09-05) - the plan's own placeholder guess of an `address` column was WRONG; the real
 * columns are `street`/`street2`, not `address`.
 *
 * geodir_save_post_meta() (includes/post-functions.php:106) concatenates its own $meta_value
 * argument directly into a raw SQL string rather than passing it through $wpdb->prepare() - only
 * the post_id parameter is prepared. This is a genuine SQL-injection surface in GeoDirectory's OWN
 * code, not something this plugin can fix by editing vendor files, so every value handed to it
 * below is escaped with esc_sql() first (strings) or cast to a float (lat/lng, which can carry no
 * quote character at all) - never a raw caller-supplied string.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_geodirectory_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_geodirectory_full_definitions' );

/**
 * Contribute GeoDirectory ability definitions to the registry, only while GeoDirectory is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_geodirectory_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'geodirectory' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_geodirectory_registry_definitions() );
}

/**
 * Contribute GeoDirectory ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_geodirectory_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_geodirectory_registry_definitions() );
}

/**
 * The four GeoDirectory ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_geodirectory_registry_definitions(): array {
	return array(
		'aafm/geodirectory-get-listings'   => array(
			'label'        => __( 'Get GeoDirectory listings', 'agent-abilities-for-mcp' ),
			'description'  => __( 'List GeoDirectory business/place listings (title, status, link). Default-off integration; enable it in the Integrations tab.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'geodirectory',
			'args_builder' => 'aafm_args_geodirectory_get_listings',
		),
		'aafm/geodirectory-get-listing'    => array(
			'label'        => __( 'Get a GeoDirectory listing', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Read one GeoDirectory listing by id, including its address and coordinates. Default-off integration.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'geodirectory',
			'args_builder' => 'aafm_args_geodirectory_get_listing',
		),
		'aafm/geodirectory-create-listing' => array(
			'label'        => __( 'Create a GeoDirectory listing', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Create a new GeoDirectory business/place listing with a title, content, and optional address/coordinates. Default-off integration.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'geodirectory',
			'args_builder' => 'aafm_args_geodirectory_create_listing',
		),
		'aafm/geodirectory-update-listing' => array(
			'label'        => __( 'Update a GeoDirectory listing', 'agent-abilities-for-mcp' ),
			'description'  => __( "Update an existing listing's title, content, or address/coordinates. Fields omitted from the call are left untouched. Default-off integration.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'geodirectory',
			'args_builder' => 'aafm_args_geodirectory_update_listing',
		),
	);
}

/**
 * The documented address/lat/lng custom-table columns this integration writes, re-verified
 * against the installed copy's own schema-creation code (see this file's own docblock).
 *
 * @return string[]
 */
function aafm_geodirectory_address_fields(): array {
	return array( 'street', 'street2', 'city', 'region', 'country', 'zip' );
}

/**
 * Read a listing's documented custom-table fields via GeoDirectory's own accessor.
 *
 * @param int $post_id Listing (gd_place) post id.
 * @return array<string,mixed>
 */
function aafm_geodirectory_read_fields( int $post_id ): array {
	return aafm_geodirectory_shape_row( geodir_get_post_info( $post_id, false ) );
}

/**
 * Read a listing's documented custom-table fields with a direct, prepared database read of
 * GeoDirectory's own detail table - no GeoDirectory function, and therefore neither of its two
 * public filters, involved at all.
 *
 * Codex final round 2 MEDIUM, then round 3 MEDIUM: aafm_geodirectory_write_fields()'s own
 * write-confirmation check first went through geodir_get_post_info()'s RETURN-value filter
 * ('geodir_get_post_info'), then, once that was suppressed, round 3 found the SAME function's
 * QUERY-building filter ('geodir_post_info_query', post-functions.php:61) still reached the
 * confirmation read - either one could make a legitimate third-party filter that merely
 * reformats a value (or the query that fetches it) look like a mismatch even though the field
 * persisted exactly as written, wrongly rolling back a real, successful create. Round 3 also
 * found the filter-suppression helper this fix used (aafm_call_without_filter(), removed here)
 * was not WP_Hook-lifecycle-safe: a callback added to the hook WHILE it was suppressed would be
 * silently discarded on restore. A direct read of GeoDirectory's own table - the exact query
 * geodir_get_post_info() would run before either filter touches it - has neither problem.
 *
 * @param int $post_id Listing (gd_place) post id.
 * @return array<string,mixed>
 */
function aafm_geodirectory_read_fields_unfiltered( int $post_id ): array {
	global $wpdb, $plugin_prefix;
	// Same table-name computation geodir_save_post_meta() itself uses (this file's own docblock);
	// %i is this codebase's own convention for an identifier placeholder in $wpdb->prepare().
	$table = ( is_string( $plugin_prefix ) ? $plugin_prefix : $wpdb->prefix . 'geodir_' ) . 'gd_place_detail';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a fresh, uncached, unfiltered read is the entire point (see docblock above).
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT street, street2, city, region, country, zip, latitude, longitude FROM %i WHERE post_id = %d', $table, $post_id ), ARRAY_A );
	return aafm_geodirectory_shape_row( is_array( $row ) ? (object) $row : null );
}

/**
 * Shape a raw GeoDirectory post-info row (or non-object) into the documented field map.
 *
 * @param mixed $info Whatever geodir_get_post_info() returned.
 * @return array<string,mixed>
 */
function aafm_geodirectory_shape_row( $info ): array {
	$row = is_object( $info ) ? (array) $info : array();

	$out = array();
	foreach ( aafm_geodirectory_address_fields() as $field ) {
		$out[ $field ] = isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ? (string) $row[ $field ] : '';
	}
	$out['latitude']  = isset( $row['latitude'] ) && is_scalar( $row['latitude'] ) ? (float) $row['latitude'] : 0.0;
	$out['longitude'] = isset( $row['longitude'] ) && is_scalar( $row['longitude'] ) ? (float) $row['longitude'] : 0.0;

	return $out;
}

/**
 * Write the documented address/lat/lng subset through geodir_save_post_meta(), escaping every
 * value first - that function concatenates $meta_value directly into raw SQL rather than
 * preparing it (see this file's own docblock), so this plugin must never hand it a raw string.
 *
 * Codex final round MEDIUM: geodir_save_post_meta() returns false only when the detail table or
 * column is missing; on the actual write path it runs $wpdb->query() and returns nothing at all,
 * regardless of whether that query succeeded. This plugin has no way to see a failed
 * UPDATE/INSERT through its return value, so the only way to know a supplied field actually
 * persisted is to read every one of them back and compare - the same "certify against the real
 * row" principle this codebase already applies to its own option writes.
 *
 * @param int                 $post_id Listing post id.
 * @param array<string,mixed> $input Validated ability input.
 * @return bool True when every field the caller supplied reads back with the value written.
 */
function aafm_geodirectory_write_fields( int $post_id, array $input ): bool {
	foreach ( aafm_geodirectory_address_fields() as $field ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		geodir_save_post_meta( $post_id, $field, esc_sql( aafm_sanitize_plain_text( (string) $input[ $field ] ) ) );
	}
	if ( array_key_exists( 'latitude', $input ) ) {
		geodir_save_post_meta( $post_id, 'latitude', (float) $input['latitude'] );
	}
	if ( array_key_exists( 'longitude', $input ) ) {
		geodir_save_post_meta( $post_id, 'longitude', (float) $input['longitude'] );
	}

	$stored = aafm_geodirectory_read_fields_unfiltered( $post_id );
	foreach ( aafm_geodirectory_address_fields() as $field ) {
		if ( array_key_exists( $field, $input )
			&& aafm_sanitize_plain_text( (string) $input[ $field ] ) !== $stored[ $field ] ) {
			return false;
		}
	}
	if ( array_key_exists( 'latitude', $input )
		&& abs( $stored['latitude'] - (float) $input['latitude'] ) > 0.0000001 ) {
		return false;
	}
	if ( array_key_exists( 'longitude', $input )
		&& abs( $stored['longitude'] - (float) $input['longitude'] ) > 0.0000001 ) {
		return false;
	}
	return true;
}

/**
 * Shape a gd_place post plus its documented custom-table fields for output.
 *
 * @param WP_Post $post Listing post.
 * @return array<string,mixed>
 */
function aafm_geodirectory_shape_listing( WP_Post $post ): array {
	return array_merge(
		array(
			'listing_id' => $post->ID,
			'title'      => get_the_title( $post ),
			'content'    => (string) $post->post_content,
			'status'     => $post->post_status,
			'link'       => (string) get_permalink( $post ),
		),
		aafm_geodirectory_read_fields( $post->ID )
	);
}

/**
 * Object-independent floor for listing listings and creating one: author-level access,
 * matching this plan's own "lean, conservative default" framing for a default-off integration.
 *
 * @return bool
 */
function aafm_perm_geodirectory_list(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Per-object gate for reading one listing. Checks the object-independent floor first so a call
 * with no matching post still resolves cleanly to false rather than depending on get_post()'s
 * behavior for id 0.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_geodirectory_get( array $input ): bool {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	$id   = isset( $input['listing_id'] ) ? absint( $input['listing_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || 'gd_place' !== $post->post_type ) {
		return false;
	}
	// Codex round C finding 4: the object-independent edit_posts floor alone let an Author read
	// another user's draft/private listing (raw content and coordinates included). Mirrors
	// aafm_can_read_post_object()'s own public-status-or-per-object-edit rule.
	if ( in_array( $post->post_status, get_post_stati( array( 'public' => true ) ), true ) ) {
		return true;
	}
	return current_user_can( 'edit_post', $post->ID );
}

/**
 * Create gate: edit_posts to create at all, publish_posts additionally required for a public
 * status - the same shape aafm_perm_create_draft() uses for core posts.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_geodirectory_create( array $input ): bool {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	if ( isset( $input['status'] ) && aafm_status_requires_publish_cap( (string) $input['status'] ) ) {
		return current_user_can( 'publish_posts' );
	}
	return true;
}

/**
 * Per-object gate for updating a listing: gd_place's mapped edit_post cap resolves against the
 * SAME primitive names as the built-in post type (capability_type is the literal string 'post').
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_geodirectory_update( array $input ): bool {
	$id   = isset( $input['listing_id'] ) ? absint( $input['listing_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && 'gd_place' === $post->post_type && current_user_can( 'edit_post', $post->ID );
}

/**
 * Args for aafm/geodirectory-get-listings.
 *
 * @return array<string,mixed>
 */
function aafm_args_geodirectory_get_listings(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/geodirectory-get-listings' ),
		'description'         => aafm_ability_description( 'aafm/geodirectory-get-listings' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PAGE_MAX,
					'default' => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'listings' => array( 'type' => 'array' ),
				'total'    => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_geodirectory_get_listings',
		'permission_callback' => 'aafm_perm_geodirectory_list',
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
 * Execute aafm/geodirectory-get-listings: a plain WP_Query against gd_place, no custom-table join
 * needed for a bare list.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_geodirectory_get_listings( array $input ) {
	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 20;
	$page     = isset( $input['page'] ) ? min( AAFM_LIST_PAGE_MAX, max( 1, absint( $input['page'] ) ) ) : 1;

	// Codex final round MEDIUM: filtering AFTER WP_Query had already paginated and counted meant
	// an inaccessible listing could displace an accessible one to a later page while `total` still
	// counted it - the same "reported total doesn't match what was actually returned" shape this
	// release exists to stop. Fetch every candidate, apply the per-object authorization filter
	// first, then paginate and count the AUTHORIZED set.
	//
	// Codex final round 2 MEDIUM: an earlier fix capped this fetch at a single 2000-row batch,
	// which reproduces the exact same bug at a larger scale (a directory with 2000+ listings would
	// silently drop everything past the cap, with no truncation signal). Loop in batches until a
	// batch comes back short, so every candidate is genuinely examined regardless of directory
	// size - the filterable batch size lets a test prove multi-batch iteration without creating
	// thousands of posts.
	//
	// Codex final round 3 MEDIUM: the first fix advanced with 'paged', an OFFSET into whatever
	// currently matches - if a row is trashed/deleted between batches, every row after it shifts
	// down by one and the next offset-based batch skips one real row; the reverse (a row becoming
	// newly eligible) can duplicate one instead. Keyset pagination (WHERE ID > last-seen-ID, no
	// offset at all) is immune to both: a row's own position never depends on how many OTHER rows
	// currently exist before it, only on IDs already fully processed. An iteration cap guards
	// against a pathological host filter that always returns a full batch.
	$batch_size = max( 1, (int) apply_filters( 'aafm_geodirectory_list_batch_size', 500 ) );
	// Codex final round 4 MEDIUM: an unscoped 'posts_where' filter runs against EVERY WP_Query
	// built while it's attached, not just this function's own - a plugin or theme hook fired
	// from inside this loop (e.g. its own nested WP_Query in a 'the_posts' callback) would
	// silently get this same "ID > last-seen" clause appended to an unrelated query. A private,
	// per-call marker in the query args (harmless to core - an unrecognized key is simply
	// ignored when building SQL, but still readable back via $query->get()) lets the filter
	// check "is this actually my query?" before touching $where.
	$query_marker  = 'aafm_geodirectory_list_' . wp_generate_password( 12, false, false );
	$public_stati  = get_post_stati( array( 'public' => true ) );
	$visible       = array();
	$last_id       = 0;
	$keyset_filter = static function ( string $where, WP_Query $query ) use ( &$last_id, $query_marker ): string {
		if ( $query_marker !== $query->get( 'aafm_query_marker' ) ) {
			return $where;
		}
		global $wpdb;
		if ( $last_id > 0 ) { // @phpstan-ignore-line greater.alwaysFalse ($last_id is mutated by reference between calls; phpstan analyses this closure body in isolation)
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant ($wpdb->posts).
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last_id );
		}
		return $where;
	};

	add_filter( 'posts_where', $keyset_filter, 10, 2 );
	try {
		$iterations = 0;
		do {
			// ponytail: 1000 batches at the default size of 500 covers 500,000 listings - a
			// pathological host filter that always returns a full batch stops here instead of
			// looping forever; raise the multiplier if a real directory ever legitimately exceeds it.
			if ( ++$iterations > 1000 ) {
				break;
			}
			$query = new WP_Query(
				array(
					'post_type'         => 'gd_place',
					'post_status'       => 'any',
					// 'readable' narrows the SQL for the 'private' status specifically -
					// WP_Query's own 'perm' handling (wp-includes/class-wp-query.php) only ever
					// special-cases 'private', never 'draft'/'pending', so it alone is not
					// sufficient (see the PHP-level filter below, which covers every non-public
					// status uniformly).
					'perm'              => 'readable',
					'posts_per_page'    => $batch_size,
					'orderby'           => 'ID',
					'order'             => 'ASC',
					'no_found_rows'     => true,
					'aafm_query_marker' => $query_marker,
				)
			);
			// Codex round C finding 4: 'perm' => 'readable' does not cover 'draft'/'pending' at
			// all (only 'private'), so an Author could still see another user's draft listing
			// through the SQL layer alone. Filter every result through the SAME
			// public-status-or-per-object-edit rule aafm_perm_geodirectory_get() already uses, so
			// no non-public listing the caller cannot edit ever reaches the response regardless
			// of which status 'perm' missed.
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				if ( $post->ID > $last_id ) {
					$last_id = $post->ID;
				}
				if ( ! in_array( $post->post_status, $public_stati, true ) && ! current_user_can( 'edit_post', $post->ID ) ) {
					continue;
				}
				$visible[] = $post;
			}
			$fetched = count( $query->posts );
		} while ( $fetched === $batch_size );
	} finally {
		remove_filter( 'posts_where', $keyset_filter );
	}

	$total      = count( $visible );
	$page_posts = array_slice( $visible, ( $page - 1 ) * $per_page, $per_page );

	$listings = array();
	foreach ( $page_posts as $post ) {
		$listings[] = array(
			'listing_id' => $post->ID,
			'title'      => get_the_title( $post ),
			'status'     => $post->post_status,
			'link'       => (string) get_permalink( $post ),
		);
	}

	return array(
		'listings' => $listings,
		'total'    => $total,
	);
}

/**
 * Args for aafm/geodirectory-get-listing.
 *
 * @return array<string,mixed>
 */
function aafm_args_geodirectory_get_listing(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/geodirectory-get-listing' ),
		'description'         => aafm_ability_description( 'aafm/geodirectory-get-listing' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'listing_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'listing_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
		),
		'execute_callback'    => 'aafm_exec_geodirectory_get_listing',
		'permission_callback' => 'aafm_perm_geodirectory_get',
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
 * Execute aafm/geodirectory-get-listing.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_geodirectory_get_listing( array $input ) {
	$id   = absint( $input['listing_id'] ?? 0 );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'gd_place' !== $post->post_type ) {
		return aafm_generic_error();
	}

	return aafm_geodirectory_shape_listing( $post );
}

/**
 * Args for aafm/geodirectory-create-listing.
 *
 * @return array<string,mixed>
 */
function aafm_args_geodirectory_create_listing(): array {
	$properties = array(
		'title'   => array(
			'type'      => 'string',
			'minLength' => 1,
		),
		'content' => array(
			'type' => 'string',
		),
		'status'  => array(
			'type' => 'string',
			'enum' => array( 'publish', 'draft', 'pending' ),
		),
	);
	foreach ( aafm_geodirectory_address_fields() as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}
	$properties['latitude']  = array( 'type' => 'number' );
	$properties['longitude'] = array( 'type' => 'number' );

	return array(
		'label'               => aafm_ability_label( 'aafm/geodirectory-create-listing' ),
		'description'         => aafm_ability_description( 'aafm/geodirectory-create-listing' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
		),
		'execute_callback'    => 'aafm_exec_geodirectory_create_listing',
		'permission_callback' => 'aafm_perm_geodirectory_create',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/geodirectory-create-listing: core wp_insert_post() for title/content/status,
 * geodir_save_post_meta() for the documented address/lat/lng subset.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_geodirectory_create_listing( array $input ) {
	$status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';

	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'gd_place',
				'post_title'   => aafm_sanitize_plain_text( (string) $input['title'] ),
				'post_content' => isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '',
				'post_status'  => $status,
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return aafm_generic_error();
	}

	if ( ! aafm_geodirectory_write_fields( (int) $post_id, $input ) ) {
		// The core post exists, but the caller's address/location fields could not be confirmed
		// as saved - a partially-created listing under a "success" report would be exactly the
		// silent-wrong-answer shape this release exists to stop, so remove it and say so instead.
		// Codex final round 2 MEDIUM: wp_delete_post()'s own return was never checked, so a
		// pre_delete_post filter refusing the deletion (any plugin can register one) would leave
		// the half-written post behind while this message claimed nothing remained.
		$removed = wp_delete_post( (int) $post_id, true );
		return new WP_Error(
			'aafm_geodirectory_write_unconfirmed',
			$removed instanceof WP_Post
				? __( 'The listing could not be created: its address or location fields did not save. Nothing was created.', 'agent-abilities-for-mcp' )
				: __( 'The listing could not be created: its address or location fields did not save, and the incomplete listing could not be removed automatically. Delete it manually.', 'agent-abilities-for-mcp' )
		);
	}

	$post = get_post( $post_id );
	return $post instanceof WP_Post ? aafm_geodirectory_shape_listing( $post ) : aafm_generic_error();
}

/**
 * Args for aafm/geodirectory-update-listing.
 *
 * @return array<string,mixed>
 */
function aafm_args_geodirectory_update_listing(): array {
	$properties = array(
		'listing_id' => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
		'title'      => array( 'type' => 'string' ),
		'content'    => array( 'type' => 'string' ),
	);
	foreach ( aafm_geodirectory_address_fields() as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}
	$properties['latitude']  = array( 'type' => 'number' );
	$properties['longitude'] = array( 'type' => 'number' );

	return array(
		'label'               => aafm_ability_label( 'aafm/geodirectory-update-listing' ),
		'description'         => aafm_ability_description( 'aafm/geodirectory-update-listing' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'listing_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
		),
		'execute_callback'    => 'aafm_exec_geodirectory_update_listing',
		'permission_callback' => 'aafm_perm_geodirectory_update',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/geodirectory-update-listing. A field omitted from $input is left untouched.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_geodirectory_update_listing( array $input ) {
	$id   = absint( $input['listing_id'] ?? 0 );
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || 'gd_place' !== $post->post_type ) {
		return aafm_generic_error();
	}

	$update = array( 'ID' => $id );
	if ( array_key_exists( 'title', $input ) ) {
		$update['post_title'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	if ( array_key_exists( 'content', $input ) ) {
		$update['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( count( $update ) > 1 ) {
		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) {
			return aafm_generic_error();
		}
	}

	if ( ! aafm_geodirectory_write_fields( $id, $input ) ) {
		return new WP_Error(
			'aafm_geodirectory_write_unconfirmed',
			__( 'The listing was updated, but its address or location fields did not save.', 'agent-abilities-for-mcp' )
		);
	}

	$fresh = get_post( $id );
	return $fresh instanceof WP_Post ? aafm_geodirectory_shape_listing( $fresh ) : aafm_generic_error();
}
