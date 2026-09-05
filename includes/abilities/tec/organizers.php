<?php
/**
 * The Events Calendar abilities: organizers.
 *
 * Writes go through the ORM repository (tribe_organizers()->set_args()->create()/save()), using
 * the repository's OWN field aliases - confirmed directly from
 * Tribe__Events__Repositories__Organizer's constructor (`organizer` => post_title, `email` =>
 * _OrganizerEmail, `phone` => _OrganizerPhone, `website` => _OrganizerWebsite).
 *
 * A superset of Easy MCP AI's organizer coverage (list and create only, per doc 224's
 * "Corrections" section): this integration additionally ships a single-organizer read and an
 * update.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_tec_organizers_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_tec_organizers_full_definitions' );

/**
 * Contribute TEC organizer ability definitions, only while TEC is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_organizers_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'tec' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_tec_organizers_registry_definitions() );
}

/**
 * Contribute TEC organizer ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_organizers_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_tec_organizers_registry_definitions() );
}

/**
 * The four TEC organizer ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_tec_organizers_registry_definitions(): array {
	return array(
		'aafm/tec-get-organizers'   => array(
			'label'        => __( 'Get organizers', 'agent-abilities-for-mcp' ),
			'description'  => __( 'List organizers via the Events Calendar ORM (tribe_organizers()).', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_get_organizers',
		),
		'aafm/tec-get-organizer'    => array(
			'label'        => __( 'Get organizer', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Read a single organizer by ID.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_get_organizer',
		),
		'aafm/tec-create-organizer' => array(
			'label'        => __( 'Create organizer', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Create an organizer via the Events Calendar ORM. Requires the edit_tribe_organizers capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_create_organizer',
		),
		'aafm/tec-update-organizer' => array(
			'label'        => __( 'Update organizer', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Update an organizer by ID via the Events Calendar ORM. Requires edit access to that organizer.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_update_organizer',
		),
	);
}

/**
 * The wire shape for one organizer.
 *
 * @param int $id Organizer (post) id.
 * @return array<string,mixed>
 */
function aafm_tec_organizer_shape( int $id ): array {
	$post = get_post( $id );
	return array(
		'id'      => $id,
		'title'   => $post instanceof WP_Post ? get_the_title( $post ) : '',
		'email'   => (string) get_post_meta( $id, '_OrganizerEmail', true ),
		'phone'   => (string) get_post_meta( $id, '_OrganizerPhone', true ),
		'website' => (string) get_post_meta( $id, '_OrganizerWebsite', true ),
	);
}

/**
 * Translate this ability's lowercase input into the organizer repository's own field aliases.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_tec_organizer_orm_args( array $input ): array {
	$args = array();
	if ( isset( $input['title'] ) ) {
		$args['organizer'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	if ( isset( $input['email'] ) ) {
		$args['email'] = sanitize_email( (string) $input['email'] );
	}
	if ( isset( $input['phone'] ) ) {
		$args['phone'] = aafm_sanitize_plain_text( (string) $input['phone'] );
	}
	if ( isset( $input['website'] ) ) {
		$args['website'] = esc_url_raw( (string) $input['website'] );
	}
	return $args;
}

/**
 * Args for aafm/tec-get-organizers.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_organizers(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-organizers' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-organizers' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_pagination_schema_props(
				AAFM_LIST_PER_PAGE_MAX,
				__( 'Number of organizers per page, clamped to the 1-50 range. Defaults to 10 when omitted.', 'agent-abilities-for-mcp' ),
				__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'organizers' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total'      => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_tec_get_organizers',
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
 * Execute aafm/tec-get-organizers.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_tec_get_organizers( array $input ) {
	$paging = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$repo   = tribe_organizers()->page( $paging['page'] )->per_page( $paging['per_page'] );
	$ids    = $repo->get_ids();
	return array(
		'organizers' => array_map( 'aafm_tec_organizer_shape', array_map( 'intval', $ids ) ),
		'total'      => (int) $repo->found(),
	);
}

/**
 * Args for aafm/tec-get-organizer.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_organizer(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-organizer' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-organizer' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'organizer_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the organizer to read.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'organizer_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'organizer' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_get_organizer',
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
 * Execute aafm/tec-get-organizer.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_organizer( array $input ) {
	$id   = absint( $input['organizer_id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || Tribe__Events__Organizer::POSTTYPE !== $post->post_type ) {
		return aafm_generic_error();
	}
	return array( 'organizer' => aafm_tec_organizer_shape( $id ) );
}

/**
 * Args for aafm/tec-create-organizer.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_create_organizer(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-create-organizer' ),
		'description'         => aafm_ability_description( 'aafm/tec-create-organizer' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'title'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Organizer name.', 'agent-abilities-for-mcp' ),
				),
				'email'   => array(
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'Contact email.', 'agent-abilities-for-mcp' ),
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
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'organizer' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_create_organizer',
		'permission_callback' => 'aafm_tec_perm_create_organizer',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/tec-create-organizer.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_create_organizer( array $input ) {
	$created = tribe_organizers()->set_args( aafm_tec_organizer_orm_args( $input ) )->create();
	if ( ! $created instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return array( 'organizer' => aafm_tec_organizer_shape( (int) $created->ID ) );
}

/**
 * Args for aafm/tec-update-organizer.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_update_organizer(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-update-organizer' ),
		'description'         => aafm_ability_description( 'aafm/tec-update-organizer' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'organizer_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the organizer to update.', 'agent-abilities-for-mcp' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Organizer name.', 'agent-abilities-for-mcp' ),
				),
				'email'        => array(
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'Contact email.', 'agent-abilities-for-mcp' ),
				),
				'phone'        => array(
					'type'        => 'string',
					'description' => __( 'Phone number.', 'agent-abilities-for-mcp' ),
				),
				'website'      => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Website URL.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'organizer_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'organizer' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_update_organizer',
		'permission_callback' => 'aafm_tec_perm_edit_organizer',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/tec-update-organizer.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_update_organizer( array $input ) {
	$id   = absint( $input['organizer_id'] ?? 0 );
	$args = aafm_tec_organizer_orm_args( $input );
	if ( array() === $args ) {
		return array( 'organizer' => aafm_tec_organizer_shape( $id ) );
	}
	$result = aafm_tec_force_sync_save(
		'organizers',
		static fn() => tribe_organizers()->where( 'id', $id )->where( 'post_status', 'any' )->set_args( $args )->save( false )
	);
	if ( empty( $result[ $id ] ) || is_wp_error( $result[ $id ] ) ) {
		return aafm_generic_error();
	}
	return array( 'organizer' => aafm_tec_organizer_shape( $id ) );
}
