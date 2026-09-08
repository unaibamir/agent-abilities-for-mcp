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
 * Post statuses a caller may see in a TEC venue/organizer LIST query when no `status` filter is
 * given.
 *
 * Both list abilities now also accept an explicit `status` filter (aafm_tec_resolve_list_status()
 * below, added alongside draft-default venue/organizer creation) that validates a caller-requested
 * status against that type's own capabilities, mirroring aafm/tec-get-events. This function is
 * still what a caller who omits `status` entirely sees - and TEC's own repository default there
 * is generous: Tribe__Repository's
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
 * The capability required to set a publish-equivalent status on a venue, same convention as
 * aafm_tec_event_publish_cap() above.
 *
 * @return string
 */
function aafm_tec_venue_publish_cap(): string {
	return 'publish_tribe_venues';
}

/**
 * The capability required to set a publish-equivalent status on an organizer, same convention
 * as aafm_tec_event_publish_cap() above.
 *
 * @return string
 */
function aafm_tec_organizer_publish_cap(): string {
	return 'publish_tribe_organizers';
}

/**
 * Resolve a caller-requested `status` filter for a TEC list ability (venues, organizers - NOT
 * events, which has its own inline logic already reviewed and tested separately), and whether
 * the resulting query must be contained to the caller's own objects.
 *
 * Silent-wrong-answer fix (sim coverage lane, 2026-09): tribe_venues()->create()/
 * tribe_organizers()->create() default a new object to 'draft', but aafm_tec_visible_statuses()
 * (this file) never included 'draft' in a list query - a caller could create a venue/organizer,
 * get a success response, and then never see it again through tec-get-venues/tec-get-organizers,
 * even as its own author. Mirrors aafm_exec_tec_get_events()'s already-reviewed split: 'private'
 * keeps the existing read_private_* gate (aafm_tec_visible_statuses(), unchanged, used when
 * $requested is '' or 'private'); 'draft'/'pending'/'future' require the edit capability instead,
 * and the caller is contained to their own objects unless they also hold the type's
 * edit_others_* capability. All three capabilities are resolved from the post type's own
 * capability map (map_meta_cap: true for venues/organizers, same as events), not hardcoded
 * literals - mirrors aafm_exec_tec_get_events()'s own derivation exactly.
 *
 * @param string $requested Raw requested status ('' when the caller omitted it).
 * @param string $post_type Venue or organizer post type constant.
 * @return array{status:string|array<string>,own_only:bool}|WP_Error
 */
function aafm_tec_resolve_list_status( string $requested, string $post_type ) {
	if ( '' === $requested ) {
		return array(
			'status'   => aafm_tec_visible_statuses( $post_type ),
			'own_only' => false,
		);
	}

	$type_object = get_post_type_object( $post_type );
	$private_cap = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->read_private_posts : 'read_private_posts';
	$edit_cap    = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->edit_posts : 'edit_posts';
	$edit_others = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->edit_others_posts : 'edit_others_posts';

	if ( in_array( $requested, array( 'draft', 'pending', 'future' ), true ) ) {
		if ( ! current_user_can( $edit_cap ) ) {
			return new WP_Error( 'aafm_invalid_status', __( 'Unsupported or unauthorized post status.', 'agent-abilities-for-mcp' ) );
		}
		return array(
			'status'   => $requested,
			'own_only' => ! current_user_can( $edit_others ),
		);
	}
	$status = aafm_validate_post_status( $requested, current_user_can( $private_cap ) );
	if ( is_wp_error( $status ) ) {
		return $status;
	}
	return array(
		'status'   => $status,
		'own_only' => false,
	);
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

/**
 * Enforce the operator's max-title-length and strict-block-validation settings against a TEC
 * ORM args array before it is persisted.
 *
 * Codex final round 9 MEDIUM: TEC events/venues/organizers build their own ORM args array
 * (aafm_tec_event_orm_args()/aafm_tec_venue_orm_args()/aafm_tec_organizer_orm_args()) instead of
 * routing through aafm_insert_post()/aafm_exec_update_post(), so neither setting ever applied to
 * them. Force-draft is fixed at its own shared chokepoint
 * (aafm_authorize_post_status()/aafm_resolve_create_status(), both in includes/abilities/posts.php)
 * since every TEC create/update already calls one of those for status; title length and block
 * validation have no equivalent shared call for TEC to hook into, so this is that hook.
 *
 * @param array<string,mixed> $args        ORM args about to be persisted.
 * @param string              $title_key   Key in $args holding the sanitized title, e.g. 'post_title'.
 * @param string              $content_key Key in $args holding the kses'd content, or '' when this
 *                                         object type has no content field (venues, organizers).
 * @return array{warnings: list<array{block:string,code:string,message:string}>}|WP_Error
 */
function aafm_tec_enforce_content_safety( array $args, string $title_key, string $content_key = '' ) {
	if ( isset( $args[ $title_key ] ) ) {
		$title_ok = aafm_enforce_title_limit( (string) $args[ $title_key ] );
		if ( is_wp_error( $title_ok ) ) {
			return $title_ok;
		}
	}
	$warnings = array();
	if ( '' !== $content_key && isset( $args[ $content_key ] ) ) {
		$guard = aafm_block_guard_evaluate( (string) $args[ $content_key ] );
		if ( $guard['error'] instanceof WP_Error ) {
			return $guard['error'];
		}
		$warnings = $guard['warnings'];
	}
	return array( 'warnings' => $warnings );
}
