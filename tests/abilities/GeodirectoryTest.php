<?php
/**
 * Native GeoDirectory integration (default-off): geodirectory-get-listings, -get-listing,
 * -create-listing, -update-listing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class GeodirectoryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_geodir_stub_activate();
		add_filter( 'aafm_integration_active_geodirectory', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		parent::tear_down();
	}

	public function test_all_four_abilities_are_in_the_registry(): void {
		$registry = aafm_get_abilities_registry();

		$this->assertArrayHasKey( 'aafm/geodirectory-get-listings', $registry );
		$this->assertSame( 'read', $registry['aafm/geodirectory-get-listings']['risk'] );
		$this->assertArrayHasKey( 'aafm/geodirectory-get-listing', $registry );
		$this->assertSame( 'read', $registry['aafm/geodirectory-get-listing']['risk'] );
		$this->assertArrayHasKey( 'aafm/geodirectory-create-listing', $registry );
		$this->assertSame( 'write', $registry['aafm/geodirectory-create-listing']['risk'] );
		$this->assertArrayHasKey( 'aafm/geodirectory-update-listing', $registry );
		$this->assertSame( 'write', $registry['aafm/geodirectory-update-listing']['risk'] );
	}

	public function test_create_then_get_listing_round_trips_the_address_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$created = aafm_exec_geodirectory_create_listing(
			array(
				'title'     => 'Test Cafe',
				'content'   => 'A nice place.',
				'status'    => 'publish',
				'street'    => '123 Main St',
				'city'      => 'Springfield',
				'latitude'  => 12.34,
				'longitude' => 56.78,
			)
		);

		$this->assertIsArray( $created );
		$listing_id = $created['listing_id'];

		$fetched = aafm_exec_geodirectory_get_listing( array( 'listing_id' => $listing_id ) );

		$this->assertSame( 'Test Cafe', $fetched['title'] );
		$this->assertSame( '123 Main St', $fetched['street'] );
		$this->assertSame( 'Springfield', $fetched['city'] );
		$this->assertSame( 12.34, $fetched['latitude'] );
		$this->assertSame( 56.78, $fetched['longitude'] );
	}

	public function test_create_requires_publish_posts_for_a_public_status(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertFalse(
			aafm_perm_geodirectory_create(
				array(
					'title'  => 'x',
					'status' => 'publish',
				)
			)
		);
		$this->assertTrue(
			aafm_perm_geodirectory_create( array( 'title' => 'x' ) )
		);
	}

	public function test_get_listings_lists_only_gd_place_posts(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$ordinary = self::factory()->post->create();
		$place_id = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array() );

		$ids = wp_list_pluck( $out['listings'], 'listing_id' );
		$this->assertContains( $place_id, $ids );
		$this->assertNotContains( $ordinary, $ids );
	}

	public function test_update_listing_leaves_omitted_fields_untouched(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$created = aafm_exec_geodirectory_create_listing(
			array(
				'title' => 'Original',
				'city'  => 'Old City',
			)
		);
		$id      = $created['listing_id'];

		$updated = aafm_exec_geodirectory_update_listing(
			array(
				'listing_id' => $id,
				'title'      => 'Renamed',
			)
		);

		$this->assertSame( 'Renamed', $updated['title'] );
		$this->assertSame( 'Old City', $updated['city'] );
	}

	public function test_update_denied_for_a_user_without_edit_access(): void {
		$created = null;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$created = aafm_exec_geodirectory_create_listing( array( 'title' => 'Owned by admin' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse(
			aafm_perm_geodirectory_update( array( 'listing_id' => $created['listing_id'] ) )
		);
	}

	public function test_geodir_save_post_meta_receives_escaped_values_not_raw_input(): void {
		// The real geodir_save_post_meta() concatenates $meta_value into raw SQL - a value with a
		// literal single quote must never reach it unescaped. This asserts the write path applies
		// esc_sql() before calling out, using a value crafted to break out of a SQL string literal
		// if it were passed through raw.
		//
		// Asserted against the stub's own RAW-argument capture, not the round-tripped stored
		// value: the stub's storage mechanism (update_post_meta()) calls wp_unslash() internally
		// (wp-includes/meta.php), which strips a caller's esc_sql() backslash before it would ever
		// reach a real database row - the real geodir_save_post_meta() has no such unslashing, so
		// only the raw argument this plugin's own code actually passed proves the escaping ran.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$raw_value = "1 Main St', latitude='0.0";
		$created   = aafm_exec_geodirectory_create_listing(
			array(
				'title'  => 'Injection probe',
				'street' => $raw_value,
			)
		);

		$this->assertIsArray( $created );

		$last = aafm_geodir_stub_last_call();
		$this->assertIsArray( $last );
		$this->assertSame( 'street', $last['postmeta'] );
		$this->assertSame( esc_sql( aafm_sanitize_plain_text( $raw_value ) ), $last['value'] );
		$this->assertStringContainsString( "\\'", (string) $last['value'], 'esc_sql() must have escaped the literal quote.' );
	}
}
