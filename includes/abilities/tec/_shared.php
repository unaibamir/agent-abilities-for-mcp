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
 * A private closure (not the shared '__return_false' callable) registered at PHP_INT_MAX
 * priority, not the default 10: '__return_false' would also match and remove an identical
 * pre-existing site filter on the same hook, and a lower priority could still be overridden by
 * another callback registered later on the same hook during this call.
 *
 * @param string   $filter_name Repository filter_name ('events' | 'venues' | 'organizers').
 * @param callable $save_call  Closure that performs and returns the ->save() call.
 * @return array<int|string,mixed> The save() result, normalized to an array.
 */
function aafm_tec_force_sync_save( string $filter_name, callable $save_call ): array {
	$hook       = "tribe_repository_{$filter_name}_update_background_activated";
	$force_sync = static fn(): bool => false;
	add_filter( $hook, $force_sync, PHP_INT_MAX );
	try {
		$result = $save_call();
	} finally {
		remove_filter( $hook, $force_sync, PHP_INT_MAX );
	}
	return is_array( $result ) ? $result : array();
}

/**
 * Per-object READ permission for a single event/venue/organizer: a published object is readable
 * by anyone who clears the object-independent 'read' floor (matching aafm_can_read_post_object()'s
 * established convention for core content); a non-published one (draft, private, pending) falls
 * back to the object's own edit capability. Using the coarse aafm_perm_read() floor alone for a
 * single-object read - the shape this file originally shipped with - would let any authenticated
 * low-privilege caller read a draft event's content, or a venue/organizer's stored contact details,
 * by ID alone, without ever holding edit access to it.
 *
 * @param int                 $id            Object (post) id.
 * @param string              $post_type     Expected post type constant.
 * @param callable            $edit_callback Per-object edit permission callback, called with $input.
 * @param array<string,mixed> $input Original ability input (forwarded to $edit_callback).
 * @return bool
 */
function aafm_tec_perm_read_object( int $id, string $post_type, callable $edit_callback, array $input ): bool {
	if ( ! current_user_can( 'read' ) ) {
		return false;
	}
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) {
		return false;
	}
	if ( 'publish' === $post->post_status ) {
		return true;
	}
	return $edit_callback( $input );
}

/**
 * Per-object read permission for a single event.
 *
 * @param array<string,mixed> $input Input carrying event_id.
 * @return bool
 */
function aafm_tec_perm_read_event( array $input ): bool {
	$id = isset( $input['event_id'] ) ? absint( $input['event_id'] ) : 0;
	return aafm_tec_perm_read_object( $id, Tribe__Events__Main::POSTTYPE, 'aafm_tec_perm_edit_event', $input );
}

/**
 * Per-object read permission for a single venue.
 *
 * @param array<string,mixed> $input Input carrying venue_id.
 * @return bool
 */
function aafm_tec_perm_read_venue( array $input ): bool {
	$id = isset( $input['venue_id'] ) ? absint( $input['venue_id'] ) : 0;
	return aafm_tec_perm_read_object( $id, Tribe__Events__Venue::POSTTYPE, 'aafm_tec_perm_edit_venue', $input );
}

/**
 * Per-object read permission for a single organizer.
 *
 * @param array<string,mixed> $input Input carrying organizer_id.
 * @return bool
 */
function aafm_tec_perm_read_organizer( array $input ): bool {
	$id = isset( $input['organizer_id'] ) ? absint( $input['organizer_id'] ) : 0;
	return aafm_tec_perm_read_object( $id, Tribe__Events__Organizer::POSTTYPE, 'aafm_tec_perm_edit_organizer', $input );
}

/**
 * Post statuses a caller may see in a TEC venue/organizer LIST query.
 *
 * Neither list ability exposes a `status` input (unlike aafm/tec-get-events, which validates a
 * caller-requested status against that type's own private-read cap via
 * aafm_validate_post_status()), so an unscoped `where('post_status', ...)` call would leave TEC's
 * own repository to pick a default - and that default is generous: Tribe__Repository's
 * build_query_internally() adds 'private' to the query whenever current_user_can(
 * 'read_private_posts' ) is true, using WordPress's GENERIC core capability rather than the
 * venue/organizer type's own mapped read_private_tribe_venues/read_private_tribe_organizers cap
 * (verified directly against the installed plugin, common/src/Tribe/Repository.php). A role that
 * carries the generic cap without the type-specific one would otherwise see private venues'
 * addresses/phone numbers or organizers' emails/phone numbers it cannot edit. Gate on the type's
 * own private cap instead of trusting the repository's default.
 *
 * @param string $post_type Venue or organizer post type constant.
 * @return string[]
 */
function aafm_tec_visible_statuses( string $post_type ): array {
	$type_object = get_post_type_object( $post_type );
	$private_cap = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->read_private_posts : 'read_private_posts';
	return current_user_can( $private_cap ) ? array( 'publish', 'private' ) : array( 'publish' );
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
 * The capability required to set a publish-equivalent status on an event, resolved from the
 * event post type's own capability map rather than a hardcoded 'publish_posts' literal - the
 * shared status chokepoint (aafm_authorize_post_status()/aafm_resolve_create_status(), both in
 * posts.php) requires every caller to resolve $publish_cap this way per its own docblock.
 *
 * @return string
 */
function aafm_tec_event_publish_cap(): string {
	return 'publish_tribe_events';
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
