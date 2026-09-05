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
	$info = geodir_get_post_info( $post_id, false );
	$row  = is_object( $info ) ? (array) $info : array();

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
 * @param int                 $post_id Listing post id.
 * @param array<string,mixed> $input Validated ability input.
 * @return void
 */
function aafm_geodirectory_write_fields( int $post_id, array $input ): void {
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
	return $post instanceof WP_Post && 'gd_place' === $post->post_type;
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
	$page     = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;

	$query = new WP_Query(
		array(
			'post_type'      => 'gd_place',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		)
	);

	$listings = array();
	foreach ( $query->posts as $post ) {
		if ( $post instanceof WP_Post ) {
			$listings[] = array(
				'listing_id' => $post->ID,
				'title'      => get_the_title( $post ),
				'status'     => $post->post_status,
				'link'       => (string) get_permalink( $post ),
			);
		}
	}

	return array(
		'listings' => $listings,
		'total'    => (int) $query->found_posts,
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

	aafm_geodirectory_write_fields( (int) $post_id, $input );

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

	aafm_geodirectory_write_fields( $id, $input );

	$fresh = get_post( $id );
	return $fresh instanceof WP_Post ? aafm_geodirectory_shape_listing( $fresh ) : aafm_generic_error();
}
