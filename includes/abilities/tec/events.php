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
			'description'  => __( 'Update an event by ID via the Events Calendar ORM. Requires edit access to that event. Refuses when the event is owned by a foreign page builder (Elementor, Divi, Beaver Builder, Avada), since a write here would either have no visible effect or corrupt its own stored markup.', 'agent-abilities-for-mcp' ),
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
					'status' => array(
						'type'        => 'string',
						'description' => __( 'Post status to filter by. Defaults to "publish"; draft/pending/future require edit access to events (scoped to your own unless you can edit others\' events); private requires read-private access.', 'agent-abilities-for-mcp' ),
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
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_events( array $input ) {
	$type_object    = get_post_type_object( Tribe__Events__Main::POSTTYPE );
	$private_cap    = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->read_private_posts : 'read_private_tribe_events';
	$edit_cap       = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->edit_posts : 'edit_tribe_events';
	$edit_others    = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->edit_others_posts : 'edit_others_tribe_events';
	$requested      = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish';
	$own_draft_only = false;

	// 'private' keeps the strict read_private_tribe_events gate (aafm_validate_post_status()'s
	// existing behavior, unchanged). 'draft'/'pending'/'future' are edit-in-progress statuses,
	// not private-read statuses: TEC's stock Author/Contributor roles have edit_tribe_events
	// without read_private_tribe_events, so gating them on the private-read cap meant an
	// Author could create their own draft (aafm_tec_perm_create_event() only requires
	// edit_tribe_events) and then be refused when listing it back. Gate these three on the
	// edit capability instead, and - since that capability is coarser than "your own posts" -
	// contain the query to the caller's own events unless they also hold
	// edit_others_tribe_events, so a role with edit_tribe_events but not edit-others never
	// receives another author's draft/pending/future event.
	if ( in_array( $requested, array( 'draft', 'pending', 'future' ), true ) ) {
		if ( ! current_user_can( $edit_cap ) ) {
			return new WP_Error( 'aafm_invalid_status', __( 'Unsupported or unauthorized post status.', 'agent-abilities-for-mcp' ) );
		}
		$status         = $requested;
		$own_draft_only = ! current_user_can( $edit_others );
	} else {
		$status = aafm_validate_post_status( $requested, current_user_can( $private_cap ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
	}

	$paging = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$repo   = tribe_events()->where( 'post_status', $status )->page( $paging['page'] )->per_page( $paging['per_page'] );
	if ( $own_draft_only ) {
		$repo = $repo->where( 'author', get_current_user_id() );
	}
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
		'permission_callback' => 'aafm_tec_perm_read_event',
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
 * organizer_ids maps to the repository's own 'organizers' alias (-> _EventOrganizerID), NOT a
 * manual delete_post_meta()/add_post_meta() pair: the repository's own update_organizers()
 * (Repositories/Event.php) already validates every id with tribe_is_organizer(), unpacks the
 * multi-row meta correctly on save, and - critically - treats an explicitly empty array as
 * "clear every organizer", which a manual meta rewrite driven by `!empty($input['organizer_ids'])`
 * could never express (an empty array reads as "not supplied"). Uses array_key_exists(), not
 * isset()/!empty(), for the same reason: a caller-supplied `[]` must still reach the ORM.
 *
 * Deliberately does NOT handle `status`: that field can only be set through the shared
 * aafm_authorize_post_status()/aafm_resolve_create_status() chokepoint every other create/update
 * ability routes through (posts.php), which whitelists exactly
 * {draft,pending,future,private} + the site's real public statuses - a bare sanitize_key() would
 * let a status like "trash" or "auto-draft" through this ability's own update path, bypassing
 * tec-delete-event's own delete capability and this plugin's destructive-ability classification
 * entirely.
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
	if ( array_key_exists( 'organizer_ids', $input ) && is_array( $input['organizer_ids'] ) ) {
		$args['organizers'] = array_values( array_unique( array_map( 'absint', $input['organizer_ids'] ) ) );
	}
	return $args;
}

/**
 * Confirm venue_id/organizer_ids in $input actually name existing venue/organizer posts.
 *
 * Codex hunt F5: aafm_tec_event_orm_args() above passes these ids through absint() alone.
 * TEC's own repository (Repositories/Event.php, confirmed against the installed plugin)
 * silently drops an invalid venue/organizer relationship on save rather than erroring, so a
 * caller-supplied id naming an ordinary post would be dropped with no error and no signal
 * in the response - the exact silent-wrong-answer shape this validation exists to stop.
 *
 * @param array<string,mixed> $input Raw ability input, before aafm_tec_event_orm_args().
 * @return WP_Error|null Error naming the first invalid id, or null when every given id is valid.
 */
function aafm_tec_validate_venue_organizer_ids( array $input ) {
	if ( ! empty( $input['venue_id'] ) ) {
		$venue_id = absint( $input['venue_id'] );
		if ( 'tribe_venue' !== get_post_type( $venue_id ) ) {
			return new WP_Error(
				'aafm_tec_invalid_venue',
				sprintf(
					/* translators: %d: the invalid post id supplied as venue_id. */
					__( 'venue_id %d does not name an existing venue.', 'agent-abilities-for-mcp' ),
					$venue_id
				)
			);
		}
	}
	if ( array_key_exists( 'organizer_ids', $input ) && is_array( $input['organizer_ids'] ) ) {
		foreach ( $input['organizer_ids'] as $organizer_id ) {
			$organizer_id = absint( $organizer_id );
			if ( 'tribe_organizer' !== get_post_type( $organizer_id ) ) {
				return new WP_Error(
					'aafm_tec_invalid_organizer',
					sprintf(
						/* translators: %d: the invalid post id found in organizer_ids. */
						__( 'organizer_ids contains %d, which does not name an existing organizer.', 'agent-abilities-for-mcp' ),
						$organizer_id
					)
				);
			}
		}
	}
	return null;
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
	$status = aafm_resolve_create_status( $input, 'draft', aafm_tec_event_publish_cap() );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	$invalid_relationship = aafm_tec_validate_venue_organizer_ids( $input );
	if ( $invalid_relationship instanceof WP_Error ) {
		return $invalid_relationship;
	}

	$args                = aafm_tec_event_orm_args( $input );
	$args['post_status'] = $status;

	$safety = aafm_tec_enforce_content_safety( $args, 'post_title', 'post_content' );
	if ( is_wp_error( $safety ) ) {
		return $safety;
	}

	$created = tribe_events()->set_args( $args )->create();
	if ( ! $created instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$response = array( 'event' => aafm_tec_event_shape( (int) $created->ID ) );
	if ( ! empty( $safety['warnings'] ) ) {
		$response['content_warnings'] = $safety['warnings'];
	}
	return $response;
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
 * Codex final round 8 HIGH: this maps 'content' straight to post_content and persists it through
 * the ORM (aafm_tec_event_orm_args()) with no page-builder ownership check anywhere in the
 * function - the same corruption risk aafm_exec_update_post() guards against, just at a
 * chokepoint the generic guard's own coverage sweep (tests/PageBuilderGuardSweepTest.php) never
 * enumerated. Checked unconditionally, before any field is even read, matching every other
 * content-write execute callback's own placement.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_update_event( array $input ) {
	$id = absint( $input['event_id'] ?? 0 );

	$owning_builder = aafm_post_has_foreign_builder_ownership( $id );
	if ( false !== $owning_builder ) {
		return aafm_page_builder_owned_error( $owning_builder );
	}

	$invalid_relationship = aafm_tec_validate_venue_organizer_ids( $input );
	if ( $invalid_relationship instanceof WP_Error ) {
		return $invalid_relationship;
	}

	$args = aafm_tec_event_orm_args( $input );
	if ( isset( $input['status'] ) ) {
		$status = aafm_authorize_post_status( (string) $input['status'], aafm_tec_event_publish_cap() );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$args['post_status'] = $status;
	}
	if ( array() === $args ) {
		return array( 'event' => aafm_tec_event_shape( $id ) ); // Nothing to change; no-op success.
	}

	// Codex final round 10 MEDIUM: round 9's content-safety fix wired this call into event
	// create and both venue/organizer paths, but missed this one - the fifth-of-six call sites
	// that got left out.
	$safety = aafm_tec_enforce_content_safety( $args, 'post_title', 'post_content' );
	if ( is_wp_error( $safety ) ) {
		return $safety;
	}

	$result = aafm_tec_force_sync_save(
		'events',
		static fn() => tribe_events()->where( 'id', $id )->where( 'post_status', 'any' )->set_args( $args )->save( false )
	);
	if ( empty( $result[ $id ] ) || is_wp_error( $result[ $id ] ) ) {
		return aafm_generic_error();
	}
	// Documented contract exception to "every event write goes through the ORM" (Codex final
	// round MEDIUM, re-verified against the installed plugin): TEC's own repository save step
	// (Repositories/Event.php) unsets the all-day meta input rather than writing a falsy value
	// whenever the requested all_day is falsy, so the ORM's own update never touches the existing
	// meta row - a real event that was already all-day stays all-day, silently, under a
	// successful save() response. TEC's repository offers no supported way to clear this key
	// (confirmed by reading the actual save path, not assumed), so this direct delete_post_meta()
	// call - core's own meta API, not a raw query, so cache invalidation is unaffected - is the
	// only mechanism that exists, runs strictly AFTER the ORM save above (never interleaved with
	// or in place of it), and is the confirmed inverse of the boolean cast this file's own read
	// applies when shaping an event for the wire. Proven against a stub that reproduces this exact
	// TEC quirk (see TecStubStore.php's write_meta()), not one that would pass regardless.
	if ( array_key_exists( 'all_day', $input ) && ! $input['all_day'] ) {
		delete_post_meta( $id, '_EventAllDay' );
		// Codex hunt F4: delete_post_meta()'s bool return was discarded here, so a
		// delete_post_metadata filter vetoing the delete would leave the event still marked
		// all-day while this ability reported an ordinary success. Confirm the key is
		// actually gone rather than trusting the call didn't error.
		if ( metadata_exists( 'post', $id, '_EventAllDay' ) ) {
			return new WP_Error(
				'aafm_tec_write_unconfirmed',
				__( 'The event was updated, but its all-day flag could not be confirmed as cleared.', 'agent-abilities-for-mcp' )
			);
		}
	}
	$response = array( 'event' => aafm_tec_event_shape( $id ) );
	if ( ! empty( $safety['warnings'] ) ) {
		$response['content_warnings'] = $safety['warnings'];
	}
	return $response;
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
 * tribe_events, it goes straight to a PERMANENT delete. Calling wp_trash_post() directly moves
 * the event to Trash the normal way, matching this plugin's own trash-post/trash-page/
 * delete-block abilities.
 *
 * Codex hunt F6, gate round 1 finding 6: the disclosure only weakened the "always recoverable"
 * claim to name the Trash-disabled risk (core's own wp_trash_post() permanently deletes when
 * EMPTY_TRASH_DAYS is falsy) rather than closing it, leaving this the one "trash" ability that
 * could still silently, permanently destroy data. Now guarded exactly like trash-post/trash-page/
 * delete-block: refuse outright on a Trash-disabled site instead of documenting the risk.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_delete_event( array $input ) {
	if ( ! aafm_trash_is_enabled() ) {
		return aafm_trash_disabled_error();
	}
	$id = absint( $input['event_id'] ?? 0 );
	if ( ! wp_trash_post( $id ) ) {
		return aafm_generic_error();
	}
	return array( 'trashed' => true );
}
