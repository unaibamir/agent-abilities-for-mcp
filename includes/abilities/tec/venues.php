<?php
/**
 * The Events Calendar abilities: venues.
 *
 * Writes go through the ORM repository (tribe_venues()->set_args()->create()/save()), using the
 * repository's OWN field aliases - confirmed directly from
 * Tribe__Events__Repositories__Venue's constructor (`venue` => post_title, `address` =>
 * _VenueAddress, `city` => _VenueCity, `state_province` => _VenueStateProvince, `zip` =>
 * _VenueZip, `country` => _VenueCountry, `phone` => _VenuePhone, `website` => _VenueURL) - NOT
 * the title-case shape the legacy tribe_create_venue()/tribe_update_venue() wrappers translate
 * internally, which the ORM's own alias table does not recognize.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_tec_venues_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_tec_venues_full_definitions' );

/**
 * Contribute TEC venue ability definitions, only while TEC is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_venues_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'tec' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_tec_venues_registry_definitions() );
}

/**
 * Contribute TEC venue ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_venues_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_tec_venues_registry_definitions() );
}

/**
 * The four TEC venue ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_tec_venues_registry_definitions(): array {
	return array(
		'aafm/tec-get-venues'   => array(
			'label'        => __( 'Get venues', 'agent-abilities-for-mcp' ),
			'description'  => __( 'List venues via the Events Calendar ORM (tribe_venues()).', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_get_venues',
		),
		'aafm/tec-get-venue'    => array(
			'label'        => __( 'Get venue', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Read a single venue by ID.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_get_venue',
		),
		'aafm/tec-create-venue' => array(
			'label'        => __( 'Create venue', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Create a venue via the Events Calendar ORM. Requires the edit_tribe_venues capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_create_venue',
		),
		'aafm/tec-update-venue' => array(
			'label'        => __( 'Update venue', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Update a venue by ID via the Events Calendar ORM. Requires edit access to that venue.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_update_venue',
		),
	);
}

/**
 * The wire shape for one venue.
 *
 * @param int $id Venue (post) id.
 * @return array<string,mixed>
 */
function aafm_tec_venue_shape( int $id ): array {
	$post = get_post( $id );
	return array(
		'id'      => $id,
		'title'   => $post instanceof WP_Post ? get_the_title( $post ) : '',
		'address' => (string) get_post_meta( $id, '_VenueAddress', true ),
		'city'    => (string) get_post_meta( $id, '_VenueCity', true ),
		'state'   => (string) get_post_meta( $id, '_VenueStateProvince', true ),
		'zip'     => (string) get_post_meta( $id, '_VenueZip', true ),
		'country' => (string) get_post_meta( $id, '_VenueCountry', true ),
		'phone'   => (string) get_post_meta( $id, '_VenuePhone', true ),
		'website' => (string) get_post_meta( $id, '_VenueURL', true ),
	);
}

/**
 * Shared venue input properties (create + update).
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_tec_venue_input_properties(): array {
	return array(
		'title'   => array(
			'type'        => 'string',
			'minLength'   => 1,
			'description' => __( 'Venue name.', 'agent-abilities-for-mcp' ),
		),
		'address' => array(
			'type'        => 'string',
			'description' => __( 'Street address.', 'agent-abilities-for-mcp' ),
		),
		'city'    => array(
			'type'        => 'string',
			'description' => __( 'City.', 'agent-abilities-for-mcp' ),
		),
		'state'   => array(
			'type'        => 'string',
			'description' => __( 'State or province.', 'agent-abilities-for-mcp' ),
		),
		'zip'     => array(
			'type'        => 'string',
			'description' => __( 'Postal/zip code.', 'agent-abilities-for-mcp' ),
		),
		'country' => array(
			'type'        => 'string',
			'description' => __( 'Country.', 'agent-abilities-for-mcp' ),
		),
		'phone'   => array(
			'type'        => 'string',
			'description' => __( 'Phone number.', 'agent-abilities-for-mcp' ),
		),
		'website' => array(
			'type'        => 'string',
			'format'      => 'uri',
			'description' => __( 'Website URL.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Translate this ability's lowercase input into the venue repository's own field aliases.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_tec_venue_orm_args( array $input ): array {
	$args = array();
	if ( isset( $input['title'] ) ) {
		$args['venue'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	foreach ( array( 'address', 'city', 'zip', 'country', 'phone' ) as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$args[ $field ] = aafm_sanitize_plain_text( (string) $input[ $field ] );
		}
	}
	if ( isset( $input['state'] ) ) {
		$args['state_province'] = aafm_sanitize_plain_text( (string) $input['state'] );
	}
	if ( isset( $input['website'] ) ) {
		$args['website'] = esc_url_raw( (string) $input['website'] );
	}
	return $args;
}

/**
 * Args for aafm/tec-get-venues.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_venues(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-venues' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-venues' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_pagination_schema_props(
				AAFM_LIST_PER_PAGE_MAX,
				__( 'Number of venues per page, clamped to the 1-50 range. Defaults to 10 when omitted.', 'agent-abilities-for-mcp' ),
				__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'venues' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total'  => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_tec_get_venues',
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
 * Execute aafm/tec-get-venues.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_tec_get_venues( array $input ) {
	$paging = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$repo   = tribe_venues()
		->where( 'post_status', aafm_tec_visible_statuses( Tribe__Events__Venue::POSTTYPE ) )
		->page( $paging['page'] )
		->per_page( $paging['per_page'] );
	$ids    = $repo->get_ids();
	return array(
		'venues' => array_map( 'aafm_tec_venue_shape', array_map( 'intval', $ids ) ),
		'total'  => (int) $repo->found(),
	);
}

/**
 * Args for aafm/tec-get-venue.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_venue(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-venue' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-venue' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the venue to read.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'venue_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'venue' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_get_venue',
		'permission_callback' => 'aafm_tec_perm_read_venue',
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
 * Execute aafm/tec-get-venue.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_venue( array $input ) {
	$id   = absint( $input['venue_id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || Tribe__Events__Venue::POSTTYPE !== $post->post_type ) {
		return aafm_generic_error();
	}
	return array( 'venue' => aafm_tec_venue_shape( $id ) );
}

/**
 * Args for aafm/tec-create-venue.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_create_venue(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-create-venue' ),
		'description'         => aafm_ability_description( 'aafm/tec-create-venue' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_tec_venue_input_properties(),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'venue' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_create_venue',
		'permission_callback' => 'aafm_tec_perm_create_venue',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/tec-create-venue.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_create_venue( array $input ) {
	$created = tribe_venues()->set_args( aafm_tec_venue_orm_args( $input ) )->create();
	if ( ! $created instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return array( 'venue' => aafm_tec_venue_shape( (int) $created->ID ) );
}

/**
 * Args for aafm/tec-update-venue.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_update_venue(): array {
	$properties             = aafm_tec_venue_input_properties();
	$properties['title']    = array(
		'type'        => 'string',
		'description' => __( 'Venue name. Optional on update, required only on create.', 'agent-abilities-for-mcp' ),
	);
	$properties['venue_id'] = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'ID of the venue to update.', 'agent-abilities-for-mcp' ),
	);

	return array(
		'label'               => aafm_ability_label( 'aafm/tec-update-venue' ),
		'description'         => aafm_ability_description( 'aafm/tec-update-venue' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'venue_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'venue' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_update_venue',
		'permission_callback' => 'aafm_tec_perm_edit_venue',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/tec-update-venue.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_update_venue( array $input ) {
	$id   = absint( $input['venue_id'] ?? 0 );
	$args = aafm_tec_venue_orm_args( $input );
	if ( array() === $args ) {
		return array( 'venue' => aafm_tec_venue_shape( $id ) );
	}
	$result = aafm_tec_force_sync_save(
		'venues',
		static fn() => tribe_venues()->where( 'id', $id )->where( 'post_status', 'any' )->set_args( $args )->save( false )
	);
	if ( empty( $result[ $id ] ) || is_wp_error( $result[ $id ] ) ) {
		return aafm_generic_error();
	}
	return array( 'venue' => aafm_tec_venue_shape( $id ) );
}
