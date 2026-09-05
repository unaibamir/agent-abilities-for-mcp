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
