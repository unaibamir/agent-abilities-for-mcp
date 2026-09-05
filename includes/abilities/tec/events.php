<?php
/**
 * The Events Calendar abilities: events.
 *
 * Registers ONLY when TEC is active and at or above AAFM_TEC_MIN_VERSION
 * (aafm_integration_active('tec')). Create/update writes go through the ORM repository
 * (tribe_events()->set_args()->create()/save()), NOT the legacy
 * tribe_create_event()/tribe_update_event() wrapper family - per
 * 228-plan-1-7-4-features.md Amendment 15, which reversed the plan's own earlier draft in favor
 * of the spec's later "Corrections" section. Verified directly against the installed plugin
 * (wp/wp-content/plugins/the-events-calendar/common/src/Tribe/Repository.php):
 *   - create() returns WP_Post|false.
 *   - save() returns an array keyed by post id (each value true on success or a WP_Error).
 *
 * Delete is the ONE case where this file deliberately does NOT use the ORM's delete() (or the
 * legacy tribe_delete_event()): both share the repository's default delete callback,
 * wp_delete_post() (confirmed no 'tribe_repository_events_delete_callback' filter exists in the
 * installed plugin) - and WordPress core's own wp_delete_post() (wp-includes/post.php) only
 * auto-trashes for the LITERAL post types 'post' or 'page'; every custom post type, tribe_events
 * included, goes straight to a PERMANENT delete. This was verified by direct read of core, not
 * assumed, after a test caught it deleting an event outright instead of trashing it. Deleting via
 * wp_trash_post() directly is therefore the only way to honor this ability's own "always
 * recoverable via Trash" contract - see aafm_exec_tec_delete_event() below.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_tec_events_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_tec_events_full_definitions' );

/**
 * Contribute TEC event ability definitions, only while TEC is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_events_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'tec' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_tec_events_registry_definitions() );
}

/**
 * Contribute TEC event ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_events_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_tec_events_registry_definitions() );
}

/**
 * The five TEC event ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_tec_events_registry_definitions(): array {
	return array(
		'aafm/tec-get-events'   => array(
			'label'        => __( 'Get events', 'agent-abilities-for-mcp' ),
			'description'  => __( 'List events via the Events Calendar ORM (tribe_events()), filtered by an optional free-text search term. Requires the object-independent read capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_get_events',
		),
		'aafm/tec-get-event'    => array(
			'label'        => __( 'Get event', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Read a single event by ID, including its start/end dates, venue, and organizers.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_get_event',
		),
		'aafm/tec-create-event' => array(
			'label'        => __( 'Create event', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Create an event via the Events Calendar ORM. Requires the edit_tribe_events capability; a publicly-visible status additionally requires publish_tribe_events.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_create_event',
		),
		'aafm/tec-update-event' => array(
			'label'        => __( 'Update event', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Update an event by ID via the Events Calendar ORM. Requires edit access to that event.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_update_event',
		),
		'aafm/tec-delete-event' => array(
			'label'        => __( 'Delete event', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Trash an event by ID (goes to Trash, matching this plugin\'s trash-post convention - never a permanent delete). Requires delete access to that event.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'tec',
			'args_builder' => 'aafm_args_tec_delete_event',
		),
	);
}

/**
 * The wire shape for one event.
 *
 * @param int $id Event (post) id.
 * @return array<string,mixed>
 */
function aafm_tec_event_shape( int $id ): array {
	$post = get_post( $id );
	return array(
		'id'            => $id,
		'title'         => $post instanceof WP_Post ? get_the_title( $post ) : '',
		'status'        => $post instanceof WP_Post ? (string) $post->post_status : '',
		'link'          => $post instanceof WP_Post ? (string) get_permalink( $post ) : '',
		'start_date'    => (string) tribe_get_start_date( $id, false, 'Y-m-d H:i:s' ),
		'end_date'      => (string) tribe_get_end_date( $id, false, 'Y-m-d H:i:s' ),
		'all_day'       => (bool) tribe_event_is_all_day( $id ),
		'venue_id'      => (int) tribe_get_venue_id( $id ),
		'organizer_ids' => array_map( 'intval', (array) tribe_get_organizer_ids( $id ) ),
	);
}

/**
 * Args for aafm/tec-get-events.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_events(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-events' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-events' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Free-text search term matched against the event title.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_pagination_schema_props(
					AAFM_LIST_PER_PAGE_MAX,
					__( 'Number of events per page, clamped to the 1-50 range. Defaults to 10 when omitted.', 'agent-abilities-for-mcp' ),
					__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
				)
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'events' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total'  => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_tec_get_events',
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
 * Execute aafm/tec-get-events.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_tec_get_events( array $input ) {
	$paging = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$repo   = tribe_events()->page( $paging['page'] )->per_page( $paging['per_page'] );
	if ( ! empty( $input['search'] ) ) {
		$repo = $repo->search( sanitize_text_field( (string) $input['search'] ) );
	}
	$ids = $repo->get_ids();
	return array(
		'events' => array_map( 'aafm_tec_event_shape', array_map( 'intval', $ids ) ),
		'total'  => (int) $repo->found(),
	);
}

/**
 * Args for aafm/tec-get-event.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_event(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-event' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-event' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the event to read.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'event' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_get_event',
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
 * Execute aafm/tec-get-event.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_event( array $input ) {
	$id   = absint( $input['event_id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || Tribe__Events__Main::POSTTYPE !== $post->post_type ) {
		return aafm_generic_error();
	}
	return array( 'event' => aafm_tec_event_shape( $id ) );
}

/**
 * Build the ORM-facing $args map shared by create and update, from this ability's
 * lowercase/snake input shape.
 *
 * Field names are the repository's OWN update_fields_aliases, read directly from
 * Tribe__Events__Repositories__Event's constructor (start_date/end_date/all_day/venue), NOT the
 * title-case shape the legacy tribe_create_event()/tribe_update_event() wrappers translate
 * internally (EventStartDate/EventEndDate/EventAllDay/Venue) - the two are genuinely different
 * key sets and only the lowercase ones are understood by set_args() on the repository.
 * organizer_ids is handled separately (aafm_tec_set_event_organizers()): _EventOrganizerID is a
 * MULTI-row meta key (confirmed via tribe_get_organizer_ids()'s own
 * tribe_get_event_meta($id, '_EventOrganizerID', false) read), and the repository's generic
 * meta_input path (WP core's wp_insert_post()) writes an array value as ONE serialized row, not
 * one row per organizer - so it is written with add_post_meta() directly instead, the confirmed
 * inverse of the read.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_tec_event_orm_args( array $input ): array {
	$args = array();
	if ( isset( $input['title'] ) ) {
		$args['post_title'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	if ( isset( $input['content'] ) ) {
		$args['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( isset( $input['status'] ) ) {
		$args['post_status'] = sanitize_key( (string) $input['status'] );
	}
	if ( isset( $input['start_date'] ) ) {
		$args['start_date'] = aafm_sanitize_plain_text( (string) $input['start_date'] );
	}
	if ( isset( $input['end_date'] ) ) {
		$args['end_date'] = aafm_sanitize_plain_text( (string) $input['end_date'] );
	}
	if ( array_key_exists( 'all_day', $input ) ) {
		$args['all_day'] = ! empty( $input['all_day'] );
	}
	if ( ! empty( $input['venue_id'] ) ) {
		$args['venue'] = absint( $input['venue_id'] );
	}
	return $args;
}

/**
 * Replace an event's organizers with exactly the given set of ids.
 *
 * _EventOrganizerID is a multi-row meta key (one row per organizer), so this deletes every
 * existing row before adding the requested ones - the confirmed inverse of
 * tribe_get_organizer_ids()'s tribe_get_event_meta($id, '_EventOrganizerID', false) read.
 *
 * @param int          $event_id      Event id.
 * @param array<mixed> $organizer_ids Organizer post ids.
 * @return void
 */
function aafm_tec_set_event_organizers( int $event_id, array $organizer_ids ): void {
	delete_post_meta( $event_id, '_EventOrganizerID' );
	foreach ( array_unique( array_map( 'absint', $organizer_ids ) ) as $organizer_id ) {
		if ( $organizer_id > 0 ) {
			add_post_meta( $event_id, '_EventOrganizerID', $organizer_id );
		}
	}
}

/**
 * Args for aafm/tec-create-event.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_create_event(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-create-event' ),
		'description'         => aafm_ability_description( 'aafm/tec-create-event' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'title'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Event title.', 'agent-abilities-for-mcp' ),
				),
				'content'       => array(
					'type'        => 'string',
					'description' => __( 'Event description/body.', 'agent-abilities-for-mcp' ),
				),
				'start_date'    => array(
					'type'        => 'string',
					'description' => __( 'Event start, e.g. 2027-01-01 09:00:00.', 'agent-abilities-for-mcp' ),
				),
				'end_date'      => array(
					'type'        => 'string',
					'description' => __( 'Event end, e.g. 2027-01-01 12:00:00.', 'agent-abilities-for-mcp' ),
				),
				'all_day'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the event runs all day.', 'agent-abilities-for-mcp' ),
				),
				'venue_id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of an existing venue to associate with this event.', 'agent-abilities-for-mcp' ),
				),
				'organizer_ids' => array(
					'type'        => 'array',
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'description' => __( 'IDs of existing organizers to associate with this event.', 'agent-abilities-for-mcp' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Post status, e.g. draft or publish. Defaults to draft.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'title', 'start_date', 'end_date' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'event' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_create_event',
		'permission_callback' => 'aafm_tec_perm_create_event',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/tec-create-event.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_create_event( array $input ) {
	if ( isset( $input['status'] ) && aafm_status_requires_publish_cap( sanitize_key( (string) $input['status'] ) ) && ! aafm_tec_perm_publish_event() ) {
		return new WP_Error( 'aafm_status_forbidden', __( 'You do not have permission to set that status.', 'agent-abilities-for-mcp' ) );
	}

	$args = aafm_tec_event_orm_args( $input );
	if ( ! isset( $args['post_status'] ) ) {
		$args['post_status'] = 'draft';
	}

	$created = tribe_events()->set_args( $args )->create();
	if ( ! $created instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! empty( $input['organizer_ids'] ) && is_array( $input['organizer_ids'] ) ) {
		aafm_tec_set_event_organizers( (int) $created->ID, $input['organizer_ids'] );
	}
	return array( 'event' => aafm_tec_event_shape( (int) $created->ID ) );
}

/**
 * Args for aafm/tec-update-event.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_update_event(): array {
	$schema                               = aafm_args_tec_create_event();
	$properties                           = $schema['input_schema']['properties'];
	$properties['event_id']               = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'ID of the event to update.', 'agent-abilities-for-mcp' ),
	);
	$schema['input_schema']['properties'] = $properties;
	$schema['input_schema']['required']   = array( 'event_id' );

	return array(
		'label'               => aafm_ability_label( 'aafm/tec-update-event' ),
		'description'         => aafm_ability_description( 'aafm/tec-update-event' ),
		'category'            => 'aafm-writes',
		'input_schema'        => $schema['input_schema'],
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'event' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_update_event',
		'permission_callback' => 'aafm_tec_perm_edit_event',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/tec-update-event.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_update_event( array $input ) {
	$id = absint( $input['event_id'] ?? 0 );
	if ( isset( $input['status'] ) && aafm_status_requires_publish_cap( sanitize_key( (string) $input['status'] ) ) && ! aafm_tec_perm_publish_event() ) {
		return new WP_Error( 'aafm_status_forbidden', __( 'You do not have permission to set that status.', 'agent-abilities-for-mcp' ) );
	}

	$args             = aafm_tec_event_orm_args( $input );
	$organizers_given = ! empty( $input['organizer_ids'] ) && is_array( $input['organizer_ids'] );
	if ( array() === $args && ! $organizers_given ) {
		return array( 'event' => aafm_tec_event_shape( $id ) ); // Nothing to change; no-op success.
	}

	if ( array() !== $args ) {
		$result = tribe_events()->where( 'id', $id )->set_args( $args )->save();
		if ( ! is_array( $result ) || empty( $result[ $id ] ) || is_wp_error( $result[ $id ] ) ) {
			return aafm_generic_error();
		}
	}
	if ( $organizers_given ) {
		aafm_tec_set_event_organizers( $id, $input['organizer_ids'] );
	}
	return array( 'event' => aafm_tec_event_shape( $id ) );
}

/**
 * Args for aafm/tec-delete-event.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_delete_event(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-delete-event' ),
		'description'         => aafm_ability_description( 'aafm/tec-delete-event' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the event to trash.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'trashed' => array( 'type' => 'boolean' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_delete_event',
		'permission_callback' => 'aafm_tec_perm_delete_event',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/tec-delete-event.
 *
 * Uses wp_trash_post() directly rather than the ORM's delete() method: WordPress core's
 * wp_delete_post() (the repository's own default delete callback, confirmed no
 * 'tribe_repository_events_delete_callback' override exists in the installed plugin) only
 * auto-trashes for the literal post types 'post' or 'page' - for any custom post type, including
 * tribe_events, it goes straight to a PERMANENT delete. Calling wp_trash_post() directly is the
 * only way to guarantee this ability's own "always recoverable via Trash" contract, matching this
 * plugin's own trash-post convention (aafm_exec_trash_post()) exactly.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_delete_event( array $input ) {
	$id = absint( $input['event_id'] ?? 0 );
	if ( ! wp_trash_post( $id ) ) {
		return aafm_generic_error();
	}
	return array( 'trashed' => true );
}
