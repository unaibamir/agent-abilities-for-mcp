<?php
/**
 * The Events Calendar: venues and organizers (list/get/create/update, permission-denied,
 * non-venue/non-organizer object-type checks).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;

final class TecVenuesOrganizersTest extends TestCase {

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

	public function test_create_venue_requires_edit_tribe_venues(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( aafm_tec_perm_create_venue() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( aafm_tec_perm_create_venue() );
	}

	public function test_create_venue_persists_address_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_create_venue(
			array(
				'title'   => 'Test Hall',
				'address' => '1 Main St',
				'city'    => 'Springfield',
				'state'   => 'IL',
				'zip'     => '62701',
				'country' => 'US',
				'phone'   => '555-1234',
				'website' => 'https://example.com',
			)
		);

		$this->assertSame( 'Test Hall', $out['venue']['title'] );
		$this->assertSame( '1 Main St', $out['venue']['address'] );
		$this->assertSame( 'Springfield', $out['venue']['city'] );
		$this->assertSame( 'IL', $out['venue']['state'] );
	}

	public function test_get_venue_returns_error_for_a_non_venue_post(): void {
		$post = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_get_venue( array( 'venue_id' => $post ) );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_update_venue_requires_edit_access_to_that_venue(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $owner_id )->add_cap( 'edit_tribe_venues' );
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $other_id )->add_cap( 'edit_tribe_venues' );

		$venue_id = self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Venue::POSTTYPE,
				'post_author' => $owner_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $other_id );
		$this->assertFalse( aafm_tec_perm_edit_venue( array( 'venue_id' => $venue_id ) ) );

		wp_set_current_user( $owner_id );
		$this->assertTrue( aafm_tec_perm_edit_venue( array( 'venue_id' => $venue_id ) ) );
	}

	public function test_update_venue_changes_only_the_fields_given(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$venue_id = self::factory()->post->create(
			array(
				'post_type'  => \Tribe__Events__Venue::POSTTYPE,
				'post_title' => 'Original',
			)
		);
		update_post_meta( $venue_id, '_VenueCity', 'Original City' );

		$out = aafm_exec_tec_update_venue(
			array(
				'venue_id' => $venue_id,
				'title'    => 'Updated Hall',
			)
		);

		$this->assertSame( 'Updated Hall', $out['venue']['title'] );
		$this->assertSame( 'Original City', $out['venue']['city'] );
	}

	public function test_create_organizer_requires_edit_tribe_organizers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( aafm_tec_perm_create_organizer() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( aafm_tec_perm_create_organizer() );
	}

	public function test_create_organizer_persists_contact_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_create_organizer(
			array(
				'title'   => 'Test Organizer',
				'email'   => 'organizer@example.com',
				'phone'   => '555-5678',
				'website' => 'https://example.org',
			)
		);

		$this->assertSame( 'Test Organizer', $out['organizer']['title'] );
		$this->assertSame( 'organizer@example.com', $out['organizer']['email'] );
	}

	public function test_get_organizer_returns_error_for_a_non_organizer_post(): void {
		$post = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$out = aafm_exec_tec_get_organizer( array( 'organizer_id' => $post ) );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_update_organizer_requires_edit_access_to_that_organizer(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $owner_id )->add_cap( 'edit_tribe_organizers' );
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $other_id )->add_cap( 'edit_tribe_organizers' );

		$organizer_id = self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Organizer::POSTTYPE,
				'post_author' => $owner_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $other_id );
		$this->assertFalse( aafm_tec_perm_edit_organizer( array( 'organizer_id' => $organizer_id ) ) );

		wp_set_current_user( $owner_id );
		$this->assertTrue( aafm_tec_perm_edit_organizer( array( 'organizer_id' => $organizer_id ) ) );
	}

	public function test_get_organizers_lists(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Organizer::POSTTYPE,
				'post_title'  => 'Org A',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Organizer::POSTTYPE,
				'post_title'  => 'Org B',
				'post_status' => 'publish',
			)
		);

		$out = aafm_exec_tec_get_organizers( array() );

		$this->assertSame( 2, $out['total'] );
	}

	public function test_get_venues_hides_a_private_venue_from_a_caller_with_only_the_generic_private_read_cap(): void {
		self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Venue::POSTTYPE,
				'post_status' => 'private',
			)
		);

		// A role that carries WordPress's GENERIC read_private_posts cap (as Editor does) but not
		// the venue type's own mapped read_private_tribe_venues cap - Codex final round HIGH:
		// TEC's own repository defaults to the generic cap when no post_status is supplied, which
		// would otherwise leak a private venue's address/phone to a caller who cannot edit it.
		$role_name = 'aafm_generic_private_reader';
		add_role(
			$role_name,
			'AAFM Generic Private Reader',
			array(
				'read'               => true,
				'read_private_posts' => true,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role_name ) ) );

		$out = aafm_exec_tec_get_venues( array() );

		remove_role( $role_name );
		$this->assertSame( 0, $out['total'] );
	}

	public function test_get_venues_includes_a_private_venue_for_a_caller_with_the_venue_specific_private_read_cap(): void {
		self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Venue::POSTTYPE,
				'post_status' => 'private',
			)
		);

		$role_name = 'aafm_venue_private_reader';
		add_role(
			$role_name,
			'AAFM Venue Private Reader',
			array(
				'read'                      => true,
				'read_private_tribe_venues' => true,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role_name ) ) );

		$out = aafm_exec_tec_get_venues( array() );

		remove_role( $role_name );
		$this->assertSame( 1, $out['total'] );
	}

	public function test_get_organizers_hides_a_private_organizer_from_a_caller_with_only_the_generic_private_read_cap(): void {
		self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Organizer::POSTTYPE,
				'post_status' => 'private',
			)
		);

		$role_name = 'aafm_generic_private_reader_org';
		add_role(
			$role_name,
			'AAFM Generic Private Reader Org',
			array(
				'read'               => true,
				'read_private_posts' => true,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role_name ) ) );

		$out = aafm_exec_tec_get_organizers( array() );

		remove_role( $role_name );
		$this->assertSame( 0, $out['total'] );
	}
}
