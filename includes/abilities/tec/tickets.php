<?php
/**
 * Event Tickets abilities: read-only tickets and attendees/RSVP.
 *
 * Reads only, no write ability - per doc 224 and this plan's Task 19: Event Tickets' write paths
 * (creating a ticket type, processing an RSVP) are gated behind provider-specific commerce flows
 * (RSVP vs PayPal vs Tickets Commerce) whose $args shape was not verified during planning, and
 * shipping an unverified write against a commerce/inventory surface risks a silent money or
 * stock defect - exactly the class of bug [[silent-wrong-answer-bug-class]] warns about. A
 * deliberate, recorded scoping choice, not a gap.
 *
 * Reads use Event Tickets' own stable, long-lived static entry points
 * (Tribe__Tickets__Tickets::get_all_event_tickets(), ::load_ticket_object(),
 * ::get_event_attendees()) rather than the ticket/attendee ORM repositories directly: the
 * default tribe_tickets() repository ('tickets.ticket-repository', a Post_Repository over posts
 * that HAVE tickets) has no confirmed per-event 'event' filter, while these three static methods
 * are documented, provider-agnostic aggregation points already used by the plugin's own admin
 * screens - the safer, verified choice for a read-only surface.
 *
 * Every read is gated on the PARENT EVENT's per-object edit_tribe_event capability, not a bare
 * type-level cap on the ticket/attendee itself (Amendment 16): reading ticket/attendee data for
 * an event is scoped by whether the caller can edit that event, matching how this plugin already
 * scopes WooCommerce order reads to manage_woocommerce rather than a bare read.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_tec_tickets_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_tec_tickets_full_definitions' );

/**
 * Contribute Event Tickets ability definitions, only while Event Tickets is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_tickets_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'event_tickets' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_tec_tickets_registry_definitions() );
}

/**
 * Contribute Event Tickets ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_tec_tickets_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_tec_tickets_registry_definitions() );
}

/**
 * The three Event Tickets read ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_tec_tickets_registry_definitions(): array {
	return array(
		'aafm/tec-get-tickets'   => array(
			'label'        => __( 'Get tickets', 'agent-abilities-for-mcp' ),
			'description'  => __( 'List every ticket (RSVP and paid, across every provider) for one event. Requires edit access to that event.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'event_tickets',
			'args_builder' => 'aafm_args_tec_get_tickets',
		),
		'aafm/tec-get-ticket'    => array(
			'label'        => __( 'Get ticket', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Read a single ticket by ID. Requires edit access to its parent event.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'event_tickets',
			'args_builder' => 'aafm_args_tec_get_ticket',
		),
		'aafm/tec-get-attendees' => array(
			'label'        => __( 'Get attendees', 'agent-abilities-for-mcp' ),
			'description'  => __( 'List attendees/RSVPs for one event, across every provider. Requires edit access to that event.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'event_tickets',
			'args_builder' => 'aafm_args_tec_get_attendees',
		),
	);
}

/**
 * The wire shape for one ticket, from a Tribe__Tickets__Ticket_Object instance.
 *
 * @param Tribe__Tickets__Ticket_Object $ticket Ticket object.
 * @return array<string,mixed>
 */
function aafm_tec_ticket_shape( Tribe__Tickets__Ticket_Object $ticket ): array {
	$event = $ticket->get_event();
	return array(
		'id'          => (int) $ticket->ID,
		'event_id'    => $event instanceof WP_Post ? (int) $event->ID : 0,
		'name'        => (string) $ticket->name,
		'description' => (string) $ticket->description,
		'price'       => (float) $ticket->price,
		'capacity'    => (int) $ticket->capacity,
		'on_sale'     => (bool) $ticket->on_sale,
	);
}

/**
 * A safe subset of an attendee's data array, from Tribe__Tickets__Tickets::get_event_attendees().
 *
 * Only well-known, long-stable keys are surfaced; the full array carries provider-specific
 * fields not guaranteed to exist across RSVP/PayPal/Tickets Commerce alike.
 *
 * @param array<string,mixed> $attendee Raw attendee data array.
 * @return array<string,mixed>
 */
function aafm_tec_attendee_shape( array $attendee ): array {
	return array(
		'attendee_id'     => isset( $attendee['attendee_id'] ) ? (int) $attendee['attendee_id'] : 0,
		'order_id'        => isset( $attendee['order_id'] ) ? (string) $attendee['order_id'] : '',
		'ticket_id'       => isset( $attendee['product_id'] ) ? (int) $attendee['product_id'] : 0,
		'purchaser_name'  => isset( $attendee['purchaser_name'] ) ? (string) $attendee['purchaser_name'] : '',
		'purchaser_email' => isset( $attendee['purchaser_email'] ) ? (string) $attendee['purchaser_email'] : '',
		'checked_in'      => ! empty( $attendee['check_in'] ),
	);
}

/**
 * Args for aafm/tec-get-tickets.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_tickets(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-tickets' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-tickets' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the event.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'tickets' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_tec_get_tickets',
		'permission_callback' => 'aafm_tec_perm_edit_event',
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
 * Execute aafm/tec-get-tickets.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_tickets( array $input ) {
	$event_id = absint( $input['event_id'] ?? 0 );
	if ( ! get_post( $event_id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$tickets = Tribe__Tickets__Tickets::get_all_event_tickets( $event_id );
	return array(
		'tickets' => array_map(
			'aafm_tec_ticket_shape',
			array_filter( $tickets, static fn( $t ) => $t instanceof Tribe__Tickets__Ticket_Object )
		),
	);
}

/**
 * Args for aafm/tec-get-ticket.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_ticket(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-ticket' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-ticket' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'ticket_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the ticket to read.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'ticket_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'ticket' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'aafm_exec_tec_get_ticket',
		'permission_callback' => 'aafm_tec_perm_get_ticket',
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
 * Resolve a ticket id to its parent event, or null when the ticket does not exist.
 *
 * Shared by the permission check and the executor, so both resolve the SAME ticket object -
 * load_ticket_object() queries every registered provider, so calling it twice for one request is
 * avoidable but not wrong; called twice here for simplicity, matching this plugin's existing
 * per-object permission_callback + separate executor shape used throughout.
 *
 * @param int $ticket_id Ticket id.
 * @return Tribe__Tickets__Ticket_Object|null
 */
function aafm_tec_load_ticket( int $ticket_id ): ?Tribe__Tickets__Ticket_Object {
	$ticket = Tribe__Tickets__Tickets::load_ticket_object( $ticket_id );
	return $ticket instanceof Tribe__Tickets__Ticket_Object ? $ticket : null;
}

/**
 * Permission for aafm/tec-get-ticket: edit access to the ticket's PARENT EVENT, not a bare cap
 * on the ticket post itself (Amendment 16).
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_tec_perm_get_ticket( array $input ): bool {
	$ticket_id = isset( $input['ticket_id'] ) ? absint( $input['ticket_id'] ) : 0;
	if ( ! $ticket_id ) {
		return false;
	}
	$ticket = aafm_tec_load_ticket( $ticket_id );
	if ( ! $ticket instanceof Tribe__Tickets__Ticket_Object ) {
		return false;
	}
	$event = $ticket->get_event();
	return $event instanceof WP_Post
		&& Tribe__Events__Main::POSTTYPE === $event->post_type
		&& current_user_can( 'edit_tribe_event', $event->ID );
}

/**
 * Execute aafm/tec-get-ticket.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_ticket( array $input ) {
	$ticket = aafm_tec_load_ticket( absint( $input['ticket_id'] ?? 0 ) );
	if ( ! $ticket instanceof Tribe__Tickets__Ticket_Object ) {
		return aafm_generic_error();
	}
	return array( 'ticket' => aafm_tec_ticket_shape( $ticket ) );
}

/**
 * Args for aafm/tec-get-attendees.
 *
 * @return array<string,mixed>
 */
function aafm_args_tec_get_attendees(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/tec-get-attendees' ),
		'description'         => aafm_ability_description( 'aafm/tec-get-attendees' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the event.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'attendees' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_tec_get_attendees',
		'permission_callback' => 'aafm_tec_perm_edit_event',
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
 * Execute aafm/tec-get-attendees.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_tec_get_attendees( array $input ) {
	$event_id = absint( $input['event_id'] ?? 0 );
	if ( ! get_post( $event_id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$attendees = Tribe__Tickets__Tickets::get_event_attendees( $event_id );
	return array(
		'attendees' => array_map(
			'aafm_tec_attendee_shape',
			array_filter( (array) $attendees, 'is_array' )
		),
	);
}
