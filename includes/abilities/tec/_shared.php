<?php
/**
 * The Events Calendar / Event Tickets integration - shared cross-domain helpers.
 *
 * Loaded FIRST among the tec/ domain files (mirrors woocommerce/_shared.php). TEC's events,
 * venue, and organizer post types each register with their own capability_type and
 * map_meta_cap: true (verified directly against the installed plugin source):
 *   - events:     capability_type => ['tribe_event', 'tribe_events']    (src/Tribe/Main.php)
 *   - venues:     capability_type => ['tribe_venue', 'tribe_venues']    (src/Tribe/Venue.php)
 *   - organizers: capability_type => ['tribe_organizer', 'tribe_organizers'] (src/Tribe/Organizer.php)
 * so the standard mapped-capability current_user_can() shape applies exactly like a core post
 * type - only the capability name differs per object type.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Per-object edit permission for a single event.
 *
 * @param array<string,mixed> $input Input carrying event_id.
 * @return bool
 */
function aafm_tec_perm_edit_event( array $input ): bool {
	$id   = isset( $input['event_id'] ) ? absint( $input['event_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && Tribe__Events__Main::POSTTYPE === $post->post_type && current_user_can( 'edit_tribe_event', $id );
}

/**
 * Per-object delete permission for a single event.
 *
 * @param array<string,mixed> $input Input carrying event_id.
 * @return bool
 */
function aafm_tec_perm_delete_event( array $input ): bool {
	$id   = isset( $input['event_id'] ) ? absint( $input['event_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && Tribe__Events__Main::POSTTYPE === $post->post_type && current_user_can( 'delete_tribe_event', $id );
}

/**
 * Object-independent create permission for events (discovery + create-time gate).
 *
 * @return bool
 */
function aafm_tec_perm_create_event(): bool {
	return current_user_can( 'edit_tribe_events' );
}

/**
 * Object-independent publish permission for events, checked only when a create/update call
 * asks for a publicly-visible status.
 *
 * @return bool
 */
function aafm_tec_perm_publish_event(): bool {
	return current_user_can( 'publish_tribe_events' );
}

/**
 * Per-object edit permission for a single venue.
 *
 * @param array<string,mixed> $input Input carrying venue_id.
 * @return bool
 */
function aafm_tec_perm_edit_venue( array $input ): bool {
	$id   = isset( $input['venue_id'] ) ? absint( $input['venue_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && Tribe__Events__Venue::POSTTYPE === $post->post_type && current_user_can( 'edit_tribe_venue', $id );
}

/**
 * Object-independent create permission for venues.
 *
 * @return bool
 */
function aafm_tec_perm_create_venue(): bool {
	return current_user_can( 'edit_tribe_venues' );
}

/**
 * Per-object edit permission for a single organizer.
 *
 * @param array<string,mixed> $input Input carrying organizer_id.
 * @return bool
 */
function aafm_tec_perm_edit_organizer( array $input ): bool {
	$id   = isset( $input['organizer_id'] ) ? absint( $input['organizer_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && Tribe__Events__Organizer::POSTTYPE === $post->post_type && current_user_can( 'edit_tribe_organizer', $id );
}

/**
 * Object-independent create permission for organizers.
 *
 * @return bool
 */
function aafm_tec_perm_create_organizer(): bool {
	return current_user_can( 'edit_tribe_organizers' );
}
