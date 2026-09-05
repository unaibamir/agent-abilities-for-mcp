<?php
/**
 * Event Tickets: read-only tickets and attendees, gated on the PARENT EVENT's edit capability
 * (Amendment 16), not a bare ticket-object cap.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;

final class TecTicketsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_tec();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
		add_filter( 'aafm_integration_active_event_tickets', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		remove_filter( 'aafm_integration_active_event_tickets', '__return_true' );
		parent::tear_down();
	}

	private function create_event(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Main::POSTTYPE,
				'post_status' => 'publish',
			)
		);
	}

	public function test_get_tickets_lists_tickets_for_the_event(): void {
		$event_id = $this->create_event();
		$this->stub_add_ticket( $event_id, 'GA', 25.0, 100 );
		$this->stub_add_ticket( $event_id, 'VIP', 50.0, 20 );
		// A ticket for a DIFFERENT event must not leak in.
		$this->stub_add_ticket( $this->create_event(), 'Other event ticket' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_tec_get_tickets( array( 'event_id' => $event_id ) );

		$this->assertCount( 2, $out['tickets'] );
		$names = array_column( $out['tickets'], 'name' );
		sort( $names );
		$this->assertSame( array( 'GA', 'VIP' ), $names );
	}

	public function test_get_ticket_returns_the_shaped_ticket(): void {
		$event_id  = $this->create_event();
		$ticket_id = $this->stub_add_ticket( $event_id, 'GA', 25.0, 100 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_tec_get_ticket( array( 'ticket_id' => $ticket_id ) );

		$this->assertSame( $ticket_id, $out['ticket']['id'] );
		$this->assertSame( $event_id, $out['ticket']['event_id'] );
		$this->assertSame( 25.0, $out['ticket']['price'] );
	}

	public function test_get_ticket_is_denied_without_edit_access_to_the_parent_event(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $owner_id )->add_cap( 'edit_tribe_events' );
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $other_id )->add_cap( 'edit_tribe_events' );

		$event_id  = self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Main::POSTTYPE,
				'post_author' => $owner_id,
				'post_status' => 'draft',
			)
		);
		$ticket_id = $this->stub_add_ticket( $event_id, 'GA' );

		wp_set_current_user( $other_id );
		$this->assertFalse( aafm_tec_perm_get_ticket( array( 'ticket_id' => $ticket_id ) ) );

		wp_set_current_user( $owner_id );
		$this->assertTrue( aafm_tec_perm_get_ticket( array( 'ticket_id' => $ticket_id ) ) );
	}

	public function test_get_attendees_lists_shaped_rows(): void {
		$event_id = $this->create_event();
		$this->stub_add_attendee(
			$event_id,
			array(
				'attendee_id'     => 1,
				'order_id'        => 'order-1',
				'product_id'      => 55,
				'purchaser_name'  => 'Jane Doe',
				'purchaser_email' => 'jane@example.com',
				'check_in'        => 1,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_tec_get_attendees( array( 'event_id' => $event_id ) );

		$this->assertCount( 1, $out['attendees'] );
		$this->assertSame( 'Jane Doe', $out['attendees'][0]['purchaser_name'] );
		$this->assertTrue( $out['attendees'][0]['checked_in'] );
	}

	public function test_get_tickets_and_get_attendees_are_denied_without_edit_access_to_the_event(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $owner_id )->add_cap( 'edit_tribe_events' );
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $other_id )->add_cap( 'edit_tribe_events' );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Main::POSTTYPE,
				'post_author' => $owner_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $other_id );
		$this->assertFalse( aafm_tec_perm_edit_event( array( 'event_id' => $event_id ) ) );
	}
}
