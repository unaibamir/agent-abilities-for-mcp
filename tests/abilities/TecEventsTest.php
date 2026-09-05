<?php
/**
 * The Events Calendar: events (list/get/create/update/delete, permission-denied).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;

final class TecEventsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_tec();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		parent::tear_down();
	}

	private function create_event( array $overrides = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => \Tribe__Events__Main::POSTTYPE,
					'post_status' => 'publish',
				),
				$overrides
			)
		);
	}

	public function test_create_event_requires_edit_tribe_events(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( aafm_tec_perm_create_event() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( aafm_tec_perm_create_event() );
	}

	public function test_create_event_persists_title_dates_and_venue(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$venue_id = $this->create_venue_for_test();

		$out = aafm_exec_tec_create_event(
			array(
				'title'      => 'Test Event',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
				'venue_id'   => $venue_id,
			)
		);

		$this->assertArrayHasKey( 'event', $out );
		$this->assertSame( 'Test Event', $out['event']['title'] );
		$this->assertSame( '2027-01-01 09:00:00', $out['event']['start_date'] );
		$this->assertSame( $venue_id, $out['event']['venue_id'] );
	}

	public function test_create_event_with_multiple_organizers_stores_each_as_a_separate_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$o1 = $this->create_organizer_for_test( 'Org One' );
		$o2 = $this->create_organizer_for_test( 'Org Two' );

		$out = aafm_exec_tec_create_event(
			array(
				'title'         => 'Multi-organizer event',
				'start_date'    => '2027-02-01 09:00:00',
				'end_date'      => '2027-02-01 12:00:00',
				'organizer_ids' => array( $o1, $o2 ),
			)
		);

		sort( $out['event']['organizer_ids'] );
		$expected = array( $o1, $o2 );
		sort( $expected );
		$this->assertSame( $expected, $out['event']['organizer_ids'] );
	}

	public function test_create_event_with_a_public_status_requires_publish_capability(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		// Grant only the create-level cap, not publish, mirroring the shared _shared.php floor.
		get_userdata( $author )->add_cap( 'edit_tribe_events' );

		$out = aafm_exec_tec_create_event(
			array(
				'title'      => 'Should be refused',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
				'status'     => 'publish',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_status_forbidden', $out->get_error_code() );
	}

	/**
	 * Codex final round 9 MEDIUM: aafm_exec_tec_create_event() never called aafm_force_draft(),
	 * so the operator's force-draft-on-create setting silently never applied to events - fixed at
	 * the shared aafm_resolve_create_status()/aafm_authorize_post_status() chokepoint (posts.php)
	 * that this ability, like every other create ability, routes status through.
	 */
	public function test_create_event_honours_force_draft_even_for_an_authorized_publish_request(): void {
		update_option( 'aafm_force_draft', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_create_event(
			array(
				'title'      => 'Force-drafted event',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
				'status'     => 'publish',
			)
		);

		delete_option( 'aafm_force_draft' );

		$this->assertArrayHasKey( 'event', $out );
		$this->assertSame( 'draft', $out['event']['status'] );
	}

	/**
	 * Codex final round 9 MEDIUM: aafm_exec_tec_create_event() built its own ORM args array
	 * instead of routing through aafm_insert_post(), so the max-title-length setting never
	 * applied to it - fixed via aafm_tec_enforce_content_safety() (tec/_shared.php).
	 */
	public function test_create_event_enforces_the_max_title_length(): void {
		update_option( 'aafm_max_title_len', 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_create_event(
			array(
				'title'      => 'This title is far too long',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
			)
		);

		delete_option( 'aafm_max_title_len' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_title_too_long', $out->get_error_code() );
	}

	/**
	 * Codex final round 9 MEDIUM: same gap as the title-length case above, for strict block
	 * validation - fixed via the same aafm_tec_enforce_content_safety() call.
	 */
	public function test_create_event_enforces_strict_block_validation(): void {
		update_option( 'aafm_block_guard_strict', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_create_event(
			array(
				'title'      => 'Bad markup event',
				'content'    => '<!-- wp:heading --><h2 class="has-text-color">Hi</h2><!-- /wp:heading -->',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
			)
		);

		delete_option( 'aafm_block_guard_strict' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_block_content', $out->get_error_code() );
	}

	public function test_get_event_returns_null_error_for_a_non_event_post(): void {
		$post = self::factory()->post->create(); // ordinary post, not an event.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_get_event( array( 'event_id' => $post ) );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_update_event_requires_edit_access_to_that_event(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $owner_id )->add_cap( 'edit_tribe_events' );
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $other_id )->add_cap( 'edit_tribe_events' );

		$event_id = $this->create_event(
			array(
				'post_author' => $owner_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $other_id );
		$this->assertFalse( aafm_tec_perm_edit_event( array( 'event_id' => $event_id ) ) );

		wp_set_current_user( $owner_id );
		$this->assertTrue( aafm_tec_perm_edit_event( array( 'event_id' => $event_id ) ) );
	}

	public function test_update_event_changes_only_the_fields_given(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$event_id = $this->create_event( array( 'post_title' => 'Original' ) );
		update_post_meta( $event_id, '_EventStartDate', '2027-03-01 10:00:00' );

		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $event_id,
				'title'    => 'Updated title',
			)
		);

		$this->assertSame( 'Updated title', $out['event']['title'] );
		$this->assertSame( '2027-03-01 10:00:00', $out['event']['start_date'] );
	}

	/**
	 * Codex final round 10 MEDIUM: round 9's content-safety fix wired aafm_tec_enforce_content_safety()
	 * into event creation and both venue/organizer paths, but missed this one - the update path is
	 * a separate execute function that builds and saves its own ORM args, so the check has to be
	 * called here too, not inherited from the create-side fix.
	 */
	public function test_update_event_enforces_the_max_title_length(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$event_id = $this->create_event();

		update_option( 'aafm_max_title_len', 5 );
		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $event_id,
				'title'    => 'This title is far too long',
			)
		);
		delete_option( 'aafm_max_title_len' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_title_too_long', $out->get_error_code() );
	}

	public function test_update_event_enforces_strict_block_validation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$event_id = $this->create_event();

		update_option( 'aafm_block_guard_strict', true );
		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $event_id,
				'content'  => '<!-- wp:heading --><h2 class="has-text-color">Hi</h2><!-- /wp:heading -->',
			)
		);
		delete_option( 'aafm_block_guard_strict' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_block_content', $out->get_error_code() );
	}

	/**
	 * Codex final round MEDIUM: TEC's own repository unsets a falsy all_day meta_input entirely
	 * rather than writing it, so the ORM save alone never clears an existing 'yes' - proven here
	 * against a stub that reproduces that exact quirk (TecStubStore.php's write_meta()), not one
	 * that would pass this assertion regardless of whether the separate delete_post_meta() call
	 * in aafm_exec_tec_update_event() actually runs.
	 */
	public function test_update_event_all_day_false_actually_clears_a_real_all_day_event(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$event_id = $this->create_event();
		update_post_meta( $event_id, '_EventAllDay', 'yes' );

		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $event_id,
				'all_day'  => false,
			)
		);

		$this->assertFalse( $out['event']['all_day'] );
		$this->assertSame( '', get_post_meta( $event_id, '_EventAllDay', true ) );
	}

	public function test_delete_event_trashes_not_permanently_deletes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$event_id = $this->create_event();

		$out = aafm_exec_tec_delete_event( array( 'event_id' => $event_id ) );

		$this->assertSame( array( 'trashed' => true ), $out );
		$this->assertSame( 'trash', get_post_status( $event_id ) );
	}

	public function test_get_events_lists_and_searches(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->create_event( array( 'post_title' => 'Findable Concert' ) );
		$this->create_event( array( 'post_title' => 'Other Thing' ) );

		$out = aafm_exec_tec_get_events( array( 'search' => 'Findable' ) );

		$this->assertSame( 1, $out['total'] );
		$this->assertSame( 'Findable Concert', $out['events'][0]['title'] );
	}

	/**
	 * Codex round-b finding 6: tec-get-events defaulted to the repository's own published-only
	 * query, so a draft event a caller had just created (tec-create-event defaults to draft) was
	 * invisible to the matching list ability. This admin fixture holds every TEC capability
	 * (stub_tec()), so it does not by itself distinguish which capability actually authorizes
	 * status=draft - that split (edit_tribe_events for draft/pending/future, read_private_
	 * tribe_events for private) is proven by the dedicated tests below, added by the final Codex
	 * round that gave draft/pending/future their own editable-events gate.
	 */
	public function test_get_events_can_list_drafts_with_read_private_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->create_event( array( 'post_title' => 'Published Event' ) );
		$this->create_event(
			array(
				'post_title'  => 'Draft Event',
				'post_status' => 'draft',
			)
		);

		$default = aafm_exec_tec_get_events( array() );
		$this->assertSame( 1, $default['total'], 'The default status filter must stay published-only.' );

		$drafts = aafm_exec_tec_get_events( array( 'status' => 'draft' ) );
		$this->assertSame( 1, $drafts['total'] );
		$this->assertSame( 'Draft Event', $drafts['events'][0]['title'] );
	}

	/**
	 * Codex final round 7 LOW: this test's name and its original docblock (now above, on the
	 * previous test) both attributed this refusal to a missing read_private_tribe_events
	 * capability, but a bare 'author' fixture is refused because it has neither edit_tribe_events
	 * NOR read_private_tribe_events - the real read_private-specific gate is now proven by
	 * test_get_events_private_status_still_requires_read_private_capability() below, which uses
	 * status=private and a fixture that DOES hold edit_tribe_events.
	 */
	public function test_get_events_refuses_a_draft_status_without_edit_capability(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$out = aafm_exec_tec_get_events( array( 'status' => 'draft' ) );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_status', $out->get_error_code() );
	}

	/**
	 * Final Codex round MEDIUM: 'draft'/'pending'/'future' must be gated on the EDIT capability,
	 * not the private-read one - a caller who only holds edit_tribe_events (TEC's stock
	 * Author/Contributor shape) can create a draft via aafm_tec_perm_create_event()'s own gate,
	 * and must be able to list it back, without ever holding read_private_tribe_events.
	 */
	public function test_a_role_with_only_edit_capability_lists_its_own_draft(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( 'edit_tribe_events' );
		wp_set_current_user( $author );

		$created = aafm_exec_tec_create_event(
			array(
				'title'      => 'My Own Draft',
				'start_date' => '2027-04-01 09:00:00',
				'end_date'   => '2027-04-01 12:00:00',
			)
		);
		$this->assertArrayHasKey( 'event', $created, 'Setup: creating an event with only edit_tribe_events must succeed and default to draft.' );

		// A second author's own draft must stay invisible - the containment this MEDIUM fixed.
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->create_event(
			array(
				'post_author' => $other_author,
				'post_status' => 'draft',
				'post_title'  => 'Someone Elses Draft',
			)
		);

		$out = aafm_exec_tec_get_events( array( 'status' => 'draft' ) );

		$this->assertIsArray( $out, 'A caller with only edit_tribe_events must be allowed to request status=draft.' );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( 'My Own Draft', $out['events'][0]['title'] );
	}

	/**
	 * Final Codex round MEDIUM: read_private_tribe_events alone must not widen a draft/pending/
	 * future listing to every author's events - a role with the private-read cap but not
	 * edit_others_tribe_events is still contained to its own.
	 */
	public function test_a_role_with_read_private_but_not_edit_others_cannot_see_another_authors_draft(): void {
		$viewer = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $viewer )->add_cap( 'edit_tribe_events' );
		get_userdata( $viewer )->add_cap( 'read_private_tribe_events' );

		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->create_event(
			array(
				'post_author' => $other_author,
				'post_status' => 'draft',
				'post_title'  => 'Someone Elses Draft',
			)
		);
		$this->create_event(
			array(
				'post_author' => $viewer,
				'post_status' => 'draft',
				'post_title'  => 'My Own Draft',
			)
		);

		wp_set_current_user( $viewer );
		$out = aafm_exec_tec_get_events( array( 'status' => 'draft' ) );

		$this->assertSame( 1, $out['total'], 'read_private_tribe_events must not widen visibility beyond the caller\'s own drafts without edit_others_tribe_events.' );
		$this->assertSame( 'My Own Draft', $out['events'][0]['title'] );
	}

	/**
	 * 'private' keeps its own, unchanged gate: read_private_tribe_events, not edit_tribe_events.
	 */
	public function test_get_events_private_status_still_requires_read_private_capability(): void {
		$this->create_event(
			array(
				'post_status' => 'private',
				'post_title'  => 'A Private Event',
			)
		);

		$editor_only = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $editor_only )->add_cap( 'edit_tribe_events' );
		wp_set_current_user( $editor_only );
		$refused = aafm_exec_tec_get_events( array( 'status' => 'private' ) );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'edit_tribe_events alone must not unlock the private status.' );
		$this->assertSame( 'aafm_invalid_status', $refused->get_error_code() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$allowed = aafm_exec_tec_get_events( array( 'status' => 'private' ) );
		$this->assertSame( 1, $allowed['total'] );
		// get_the_title() prefixes a private post's title with "Private: " (core behavior).
		$this->assertSame( 'Private: A Private Event', $allowed['events'][0]['title'] );
	}

	/**
	 * Helper: create a venue post directly (bypassing the ability) for use as a fixture.
	 */
	private function create_venue_for_test(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Venue::POSTTYPE,
				'post_title'  => 'Test Venue',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Helper: create an organizer post directly (bypassing the ability) for use as a fixture.
	 *
	 * @param string $title Organizer post title.
	 */
	private function create_organizer_for_test( string $title = 'Test Organizer' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Organizer::POSTTYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}
}
