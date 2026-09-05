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

	/**
	 * An Author must not be able to read another user's draft/private listing via
	 * aafm/geodirectory-get-listing - only edit_posts plus a post-type check was checked before
	 * this fix, with no per-object ownership gate for a non-public listing (Codex round C
	 * finding 4).
	 */
	public function test_get_listing_denies_a_non_public_listing_the_caller_cannot_edit(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner_id );
		$private_id = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( aafm_perm_geodirectory_get( array( 'listing_id' => $private_id ) ) );

		// The owner, and an administrator, may still read it.
		wp_set_current_user( $owner_id );
		$this->assertTrue( aafm_perm_geodirectory_get( array( 'listing_id' => $private_id ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( aafm_perm_geodirectory_get( array( 'listing_id' => $private_id ) ) );
	}

	public function test_get_listing_allows_a_public_listing_for_any_edit_posts_holder(): void {
		$place_id = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertTrue( aafm_perm_geodirectory_get( array( 'listing_id' => $place_id ) ) );
	}

	/**
	 * The list query must not disclose another user's draft/private listing either - 'any'
	 * status with no 'perm' argument returns every listing regardless of ownership (Codex round C
	 * finding 4, second half).
	 */
	public function test_get_listings_excludes_a_private_listing_the_caller_cannot_edit(): void {
		$owner_id   = self::factory()->user->create( array( 'role' => 'author' ) );
		$private_id = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);
		$public_id  = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$out = aafm_exec_geodirectory_get_listings( array() );
		$ids = wp_list_pluck( $out['listings'], 'listing_id' );

		$this->assertNotContains( $private_id, $ids );
		$this->assertContains( $public_id, $ids );

		wp_set_current_user( $owner_id );
		$out_owner = aafm_exec_geodirectory_get_listings( array() );
		$this->assertContains( $private_id, wp_list_pluck( $out_owner['listings'], 'listing_id' ) );
	}

	/**
	 * Codex final round MEDIUM: filtering after WP_Query had already paginated and counted meant
	 * an inaccessible listing could occupy a page slot the caller's own listing should have had,
	 * while `total` still counted listings the caller never saw. Twenty newer, inaccessible drafts
	 * owned by another author plus the caller's own older draft: page 1 must still surface the
	 * caller's listing, and `total` must equal exactly what the caller can see (1), not 21.
	 */
	public function test_get_listings_authorizes_before_pagination_and_counting(): void {
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create_many(
			20,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $other_id,
			)
		);

		$caller_id      = self::factory()->user->create( array( 'role' => 'author' ) );
		$own_listing_id = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $caller_id,
				'post_date'   => '2020-01-01 00:00:00',
			)
		);

		wp_set_current_user( $caller_id );
		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 20 ) );

		$this->assertSame( 1, $out['total'] );
		$this->assertContains( $own_listing_id, wp_list_pluck( $out['listings'], 'listing_id' ) );
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

	/**
	 * Codex final round MEDIUM: geodir_save_post_meta()'s return value never reflects a real
	 * underlying write failure (it returns false only for a missing column, nothing at all
	 * otherwise), so a create that silently failed to persist its street field would otherwise be
	 * reported as a successful listing with an empty/stale address - the same silent-wrong-answer
	 * shape this release exists to stop. A newly created listing whose fields cannot be confirmed
	 * must be removed, not left half-created under a success response.
	 */
	public function test_create_rolls_back_and_errors_when_a_field_write_cannot_be_confirmed(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'aafm_geodir_stub_simulate_write_failure',
			static fn( $simulate, $field ) => 'street' === $field,
			10,
			2
		);
		$out = aafm_exec_geodirectory_create_listing(
			array(
				'title'  => 'Never actually saved',
				'street' => '1 Main St',
			)
		);
		remove_all_filters( 'aafm_geodir_stub_simulate_write_failure' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_geodirectory_write_unconfirmed', $out->get_error_code() );

		$leftover = new \WP_Query(
			array(
				'post_type'      => 'gd_place',
				'post_status'    => 'any',
				'title'          => 'Never actually saved',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$this->assertSame( array(), $leftover->posts, 'A listing whose fields could not be confirmed must not be left behind.' );
	}

	/**
	 * Same failure class as the create-path test above, on update: a title change alone must not
	 * mask a field that failed to save.
	 */
	public function test_update_errors_when_a_field_write_cannot_be_confirmed(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$created = aafm_exec_geodirectory_create_listing( array( 'title' => 'Existing listing' ) );
		$this->assertIsArray( $created );

		add_filter(
			'aafm_geodir_stub_simulate_write_failure',
			static fn( $simulate, $field ) => 'street' === $field,
			10,
			2
		);
		$out = aafm_exec_geodirectory_update_listing(
			array(
				'listing_id' => $created['listing_id'],
				'street'     => 'Updated address',
			)
		);
		remove_all_filters( 'aafm_geodir_stub_simulate_write_failure' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_geodirectory_write_unconfirmed', $out->get_error_code() );
	}
}
