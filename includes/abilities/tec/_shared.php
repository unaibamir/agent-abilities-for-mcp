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
 * Run a repository ->save() call with the repository's own background-update queueing forced
 * off, and normalize its return value to a plain array.
 *
 * Tribe__Repository::save() returns Tribe__Promise (not the documented per-id array) whenever
 * is_background_update_active() is true AND the batch size exceeds
 * get_background_update_threshold() (default 20, filterable per repository via
 * tribe_repository_{filter_name}_update_background_threshold). Found live against a real TEC
 * install whose threshold filter was tuned down: a single-event update went async, the write
 * itself succeeded, but this plugin read the Promise as "not an array" and reported failure for
 * a call that had, in fact, worked - the exact silent-wrong-answer shape this release exists to
 * catch, just inverted (false failure rather than false success). An MCP tool call promises the
 * caller a synchronous, verifiable answer; a queued background job cannot honor that contract,
 * so this always forces the synchronous path for the duration of the call rather than trying to
 * interpret a Promise.
 *
 * @param string   $filter_name Repository filter_name ('events' | 'venues' | 'organizers').
 * @param callable $save_call  Closure that performs and returns the ->save() call.
 * @return array<int|string,mixed> The save() result, normalized to an array.
 */
function aafm_tec_force_sync_save( string $filter_name, callable $save_call ): array {
	add_filter( "tribe_repository_{$filter_name}_update_background_activated", '__return_false' );
	try {
		$result = $save_call();
	} finally {
		remove_filter( "tribe_repository_{$filter_name}_update_background_activated", '__return_false' );
	}
	return is_array( $result ) ? $result : array();
}

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
