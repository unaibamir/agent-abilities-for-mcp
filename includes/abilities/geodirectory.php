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
			'description'  => __( 'List GeoDirectory business/place listings (title, status, link). Returns truncated: true if an internal enumeration cap was hit, meaning total is a floor rather than an exact count. Default-off integration; enable it in the Integrations tab.', 'agent-abilities-for-mcp' ),
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
			'description'  => __( "Update an existing listing's title, content, or address/coordinates. Fields omitted from the call are left untouched. Default-off integration. Refuses when the listing is owned by a foreign page builder (Elementor, Divi, Beaver Builder, Avada), since a write here would either have no visible effect or corrupt its own stored markup.", 'agent-abilities-for-mcp' ),
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
				'listings'  => array( 'type' => 'array' ),
				'total'     => array( 'type' => 'integer' ),
				'truncated' => array( 'type' => 'boolean' ),
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
 * The batch-count ceiling for aafm_exec_geodirectory_get_listings()'s enumeration loop.
 *
 * Codex final round 4 MEDIUM: 'aafm_geodirectory_list_batch_cap' used to have no ceiling, so a
 * hook returning e.g. PHP_INT_MAX defeated the cap's whole purpose as protection against a
 * pathological host filter that always returns a full batch. The filter may only narrow the cap,
 * never raise it past this hard ceiling.
 *
 * ponytail: 1000 is the hard ceiling this ability will ever examine in one call; raise it here
 * (not just in the filter's return value) if a real directory ever legitimately needs more.
 *
 * @return int
 */
function aafm_geodirectory_listing_batch_cap(): int {
	return min( 1000, max( 1, (int) apply_filters( 'aafm_geodirectory_list_batch_cap', 1000 ) ) );
}

/**
 * Whether a candidate listing is visible under this ability's own rule: public status, or the
 * current user can edit it. Shared by the enumeration loop and the truncation lookahead probe
 * below so the two can never disagree about what counts as visible.
 *
 * Codex round 5, R5-7: the probe used to rely only on WP_Query's 'perm' => 'readable', which (per
 * this file's own note above) does not exclude 'draft'/'pending' rows the caller cannot edit -
 * so a trailing draft owned by someone else could flip `truncated` to true even though the
 * caller's visible set was already complete.
 *
 * @param WP_Post  $post Candidate listing.
 * @param string[] $public_stati Public post statuses, from get_post_stati( array( 'public' => true ) ).
 * @return bool
 */
function aafm_geodirectory_listing_is_visible( WP_Post $post, array $public_stati ): bool {
	return in_array( $post->post_status, $public_stati, true ) || current_user_can( 'edit_post', $post->ID );
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
	// Codex round 5, R5-4: this filter only had a floor, no ceiling, so a hook returning
	// PHP_INT_MAX made the FIRST WP_Query itself unbounded - the iteration cap below never gets
	// a chance to engage, since the damage is inside one batch, not across many. Narrow-only,
	// same shape as aafm_geodirectory_listing_batch_cap()'s own hard ceiling.
	$batch_size = min( 500, max( 1, (int) apply_filters( 'aafm_geodirectory_list_batch_size', 500 ) ) );
	// Codex hunt F8: the cap below is filterable so a test can reach it without creating a
	// thousand-plus posts, by lowering the cap instead of the batch size.
	$batch_cap = aafm_geodirectory_listing_batch_cap();
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
	$truncated = false;
	try {
		$iterations = 0;
		do {
			// ponytail: 1000 batches at the default size of 500 covers 500,000 listings - a
			// pathological host filter that always returns a full batch stops here instead of
			// looping forever; raise the multiplier if a real directory ever legitimately exceeds it.
			if ( ++$iterations > $batch_cap ) {
				// Codex hunt F8: signal the cap in the response instead of silently
				// undercounting - a caller past the cap needs to know `total` is a floor, not
				// an exact count.
				//
				// Codex final round 4 LOW: reaching this branch only means the LAST permitted batch
				// came back full, which happens whenever the row count is an exact multiple of the
				// batch size too - nothing was actually omitted in that case. A lookahead probe
				// past the last-seen ID (same marker/filter, so it obeys the identical WHERE and
				// ordering) is the only way to tell "one more row exists" from "that batch just
				// happened to be full".
				//
				// Codex round 5, R5-7: a one-row 'perm' => 'readable' probe answered a different
				// question than the enumeration asks - it could see a trailing draft/pending row
				// the caller cannot edit and report `truncated` even though the visible set was
				// already complete. Fetch a full extra batch of real posts (not just ids) and
				// run each one through the SAME aafm_geodirectory_listing_is_visible() predicate
				// the enumeration uses below, so a probe never disagrees with what the loop itself
				// would have kept.
				//
				// Codex round 6, B6-5: a single probe batch answered a different question again - if
				// EVERY row in that one batch is invisible, a later visible row past it was still
				// missed and `truncated` came back false. Keep advancing the same keyset cursor
				// through further probe batches (never counted against $iterations, but bounded by
				// the same $batch_cap) until a visible row turns up or a short batch proves the real
				// end of the data was reached.
				$truncated        = false;
				$probe_iterations = 0;
				do {
					if ( ++$probe_iterations > $batch_cap ) {
						// ponytail: the probe hit the same hard ceiling the enumeration itself obeys
						// without ever resolving visible-or-not - report truncated rather than assert
						// a "nothing more" the scan never actually confirmed.
						$truncated = true;
						break;
					}
					$probe         = new WP_Query(
						array(
							'post_type'         => 'gd_place',
							'post_status'       => 'any',
							'perm'              => 'readable',
							'posts_per_page'    => $batch_size,
							'orderby'           => 'ID',
							'order'             => 'ASC',
							'no_found_rows'     => true,
							'aafm_query_marker' => $query_marker,
						)
					);
					$probe_fetched = count( $probe->posts );
					foreach ( $probe->posts as $probe_post ) {
						if ( ! $probe_post instanceof WP_Post ) {
							continue;
						}
						if ( $probe_post->ID > $last_id ) {
							$last_id = $probe_post->ID;
						}
						if ( aafm_geodirectory_listing_is_visible( $probe_post, $public_stati ) ) {
							$truncated = true;
							break 2;
						}
					}
				} while ( $probe_fetched === $batch_size );
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
			// public-status-or-per-object-edit rule aafm_perm_geodirectory_get() already uses (now
			// aafm_geodirectory_listing_is_visible(), shared with the cap-lookahead probe above),
			// so no non-public listing the caller cannot edit ever reaches the response regardless
			// of which status 'perm' missed.
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				if ( $post->ID > $last_id ) {
					$last_id = $post->ID;
				}
				if ( ! aafm_geodirectory_listing_is_visible( $post, $public_stati ) ) {
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
		'listings'  => $listings,
		'total'     => $total,
		'truncated' => $truncated,
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
 * Delete a listing whose creation could not be confirmed, and report whether the removal itself
 * succeeded - shared by the core-field check and the address/location-field check below so the
 * "delete, then pick one of two messages" shape lives in one place, not two.
 *
 * Codex final round 2 MEDIUM: wp_delete_post()'s own return was never checked, so a
 * pre_delete_post filter refusing the deletion (any plugin can register one) would leave the
 * half-written post behind while the message claimed nothing remained.
 *
 * @param int    $post_id Listing post id to remove.
 * @param string $unconfirmed_what Fragment describing what could not be confirmed, e.g. "its
 *                                 address or location fields did not save".
 * @return WP_Error
 */
function aafm_geodirectory_rollback_unconfirmed_create( int $post_id, string $unconfirmed_what ): WP_Error {
	$removed = wp_delete_post( $post_id, true );
	return new WP_Error(
		'aafm_geodirectory_write_unconfirmed',
		$removed instanceof WP_Post
			? sprintf(
				/* translators: %s: fragment describing what could not be confirmed, e.g. "its address or location fields did not save". */
				__( 'The listing could not be created: %s. Nothing was created.', 'agent-abilities-for-mcp' ),
				$unconfirmed_what
			)
			: sprintf(
				/* translators: %s: fragment describing what could not be confirmed, e.g. "its address or location fields did not save". */
				__( 'The listing could not be created: %s, and the incomplete listing could not be removed automatically. Delete it manually.', 'agent-abilities-for-mcp' ),
				$unconfirmed_what
			)
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
	// Codex final round 9 MEDIUM: this ability builds its own post array instead of routing
	// through aafm_insert_post(), so the operator's force-draft, max-title-length, and
	// strict-block-validation settings never applied to it. aafm_perm_geodirectory_create()
	// already gates a requested public status on the publish cap; force-draft is a separate,
	// stronger override on top of that authorization, matching aafm_insert_post()'s own
	// unconditional create-time rule (it wins even over an authorized 'pending' request).
	$status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
	if ( aafm_force_draft() ) {
		$status = 'draft';
	}

	$title    = aafm_sanitize_plain_text( (string) $input['title'] );
	$title_ok = aafm_enforce_title_limit( $title );
	if ( is_wp_error( $title_ok ) ) {
		return $title_ok;
	}

	$content = isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '';
	$guard   = aafm_block_guard_evaluate( $content );
	if ( $guard['error'] instanceof WP_Error ) {
		return $guard['error'];
	}

	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'gd_place',
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return aafm_generic_error();
	}

	// Codex round 5, R5-4: the update path already rereads and compares title/content after its
	// own wp_update_post() call (Codex hunt F4), but create only ever verified the address/
	// location fields below - a wp_insert_post_data filter silently reverting the title, content,
	// or status would still report success. Same confirm-by-reread principle, applied here too.
	// Codex round 6 B6-3: compare against each field's CANONICAL sanitize_post_field() form, not
	// the pre-write intent - a false mismatch here used to DELETE an otherwise valid listing, so
	// this is the highest-stakes site for the false-normalization bug this fix closes. Codex round
	// 7 R7-4: this is a CREATE, so the real wp_insert_post() sanitized these fields with id 0
	// (the row did not exist yet) - recompute the canonical form the same way, not with the id
	// just assigned, or an id-sensitive registered filter can disagree and this rolls back
	// (deletes) an otherwise valid listing.
	$after = get_post( $post_id );
	if ( ! $after instanceof WP_Post
		|| ! aafm_post_field_write_confirmed( (int) $post_id, 'post_title', $title, 0 )
		|| ! aafm_post_field_write_confirmed( (int) $post_id, 'post_content', $content, 0 )
		|| ! aafm_post_field_write_confirmed( (int) $post_id, 'post_status', $status, 0 )
	) {
		return aafm_geodirectory_rollback_unconfirmed_create( (int) $post_id, __( 'its title, content, or status could not be confirmed as saved', 'agent-abilities-for-mcp' ) );
	}

	if ( ! aafm_geodirectory_write_fields( (int) $post_id, $input ) ) {
		// The core post exists, but the caller's address/location fields could not be confirmed
		// as saved - a partially-created listing under a "success" report would be exactly the
		// silent-wrong-answer shape this release exists to stop, so remove it and say so instead.
		return aafm_geodirectory_rollback_unconfirmed_create( (int) $post_id, __( 'its address or location fields did not save', 'agent-abilities-for-mcp' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$response = aafm_geodirectory_shape_listing( $post );
	if ( ! empty( $guard['warnings'] ) ) {
		$response['content_warnings'] = $guard['warnings'];
	}
	return $response;
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
 * Codex final round 8 HIGH: this built and called wp_update_post() directly with no page-builder
 * ownership check anywhere in the function - the same corruption risk aafm_exec_update_post()
 * guards against, just at a chokepoint the generic guard's own coverage sweep
 * (tests/PageBuilderGuardSweepTest.php) never enumerated. Checked unconditionally, right after
 * the post-type validation every other check in this function already depends on.
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

	$owning_builder = aafm_post_has_foreign_builder_ownership( $id );
	if ( false !== $owning_builder ) {
		return aafm_page_builder_owned_error( $owning_builder );
	}

	// Codex final round 9 MEDIUM: this ability builds its own update array instead of routing
	// through aafm_exec_update_post(), so the operator's max-title-length and
	// strict-block-validation settings never applied to it (there is no status field on this
	// ability, so force-draft has nothing to override here).
	$warnings = array();
	$update   = array( 'ID' => $id );
	if ( array_key_exists( 'title', $input ) ) {
		$title    = aafm_sanitize_plain_text( (string) $input['title'] );
		$title_ok = aafm_enforce_title_limit( $title );
		if ( is_wp_error( $title_ok ) ) {
			return $title_ok;
		}
		$update['post_title'] = $title;
	}
	if ( array_key_exists( 'content', $input ) ) {
		$content = wp_kses_post( (string) $input['content'] );
		$guard   = aafm_block_guard_evaluate( $content );
		if ( $guard['error'] instanceof WP_Error ) {
			return $guard['error'];
		}
		$warnings               = $guard['warnings'];
		$update['post_content'] = $content;
	}
	if ( count( $update ) > 1 ) {
		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) {
			return aafm_generic_error();
		}
		// Codex hunt F4: only is_wp_error() was checked here, so a wp_insert_post_data (or
		// similar) filter silently vetoing or normalizing the title/content would report
		// success while the stored post kept its old values. Confirm by reread, the same
		// pattern aafm_geodirectory_write_fields() already applies one call below to the
		// address/location fields, extended to cover the core post fields too. Codex round 6
		// B6-3: compare against each field's CANONICAL sanitize_post_field() form, not the
		// pre-write intent, so a legitimate normalization is not mistaken for a veto.
		$after = get_post( $id );
		if ( ! $after instanceof WP_Post
			|| ( array_key_exists( 'post_title', $update ) && ! aafm_post_field_write_confirmed( $id, 'post_title', $update['post_title'] ) )
			|| ( array_key_exists( 'post_content', $update ) && ! aafm_post_field_write_confirmed( $id, 'post_content', $update['post_content'] ) )
		) {
			return new WP_Error(
				'aafm_geodirectory_write_unconfirmed',
				__( 'The listing was updated, but its title or content could not be confirmed as saved.', 'agent-abilities-for-mcp' )
			);
		}
	}

	if ( ! aafm_geodirectory_write_fields( $id, $input ) ) {
		return new WP_Error(
			'aafm_geodirectory_write_unconfirmed',
			__( 'The listing was updated, but its address or location fields did not save.', 'agent-abilities-for-mcp' )
		);
	}

	$fresh = get_post( $id );
	if ( ! $fresh instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$response = aafm_geodirectory_shape_listing( $fresh );
	if ( ! empty( $warnings ) ) {
		$response['content_warnings'] = $warnings;
	}
	return $response;
}
