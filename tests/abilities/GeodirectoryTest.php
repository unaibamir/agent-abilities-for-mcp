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

	/**
	 * Codex hunt F10: discovery for geodirectory-get-listing used the broader
	 * edit_posts/edit_others_posts/edit_published_posts family, while its real permission
	 * callback (aafm_perm_geodirectory_get()) requires the literal edit_posts capability as an
	 * unconditional first check. A role holding only edit_others_posts saw the tool in
	 * tools/list but could never actually call it - discovery must match that literal floor.
	 */
	public function test_get_listing_discovery_matches_its_literal_edit_posts_floor(): void {
		$role = get_role( 'subscriber' );
		$role->add_cap( 'edit_others_posts' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		try {
			$this->assertFalse(
				aafm_user_can_discover_ability( 'aafm/geodirectory-get-listing' ),
				'edit_others_posts alone must not surface a tool whose real floor requires edit_posts.'
			);
		} finally {
			$role->remove_cap( 'edit_others_posts' );
		}

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/geodirectory-get-listing' ),
			'edit_posts (author\'s native cap) must still surface the tool.'
		);
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

	/**
	 * R3-1 (1.7.5 deferred, round 3): this create never sets a post_date, so core's own
	 * publish<->future date transition (wp_insert_post()) lands a status:"future" request at
	 * "publish" - the confirmation used to compare against the literal requested "future",
	 * disagree, and roll back (delete) the listing that had just been legitimately created.
	 *
	 * What would break this: reverting the create path to confirm post_status against $status
	 * makes this assert an error instead of an array, and the listing would not survive.
	 */
	public function test_create_with_future_status_and_no_future_date_lands_at_publish(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$created = aafm_exec_geodirectory_create_listing(
			array(
				'title'   => 'Scheduled Cafe',
				'content' => 'Not actually in the future.',
				'status'  => 'future',
			)
		);

		$this->assertIsArray( $created, 'Core normalizing future->publish must not be mistaken for a vetoed write.' );
		$this->assertSame( 'publish', get_post_status( $created['listing_id'] ) );
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
	 * R3-5 (1.7.5 deferred, round 3): a password-protected published listing is still a PUBLIC
	 * status, so it used to pass the public-status shortcut with no password check at all - a
	 * Contributor could read another author's password-protected listing body. Mirrors the fix
	 * in CommentsReadTest for the same class of bug.
	 *
	 * What would break this: reverting aafm_perm_geodirectory_get() to skip the
	 * post_password_required() check makes the first assertion below fail (a non-owning
	 * Contributor would be let through).
	 */
	public function test_get_listing_denies_a_password_protected_public_listing_the_caller_cannot_edit(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$place_id = self::factory()->post->create(
			array(
				'post_type'     => 'gd_place',
				'post_status'   => 'publish',
				'post_author'   => $owner_id,
				'post_password' => 'secret',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertFalse( aafm_perm_geodirectory_get( array( 'listing_id' => $place_id ) ) );

		wp_set_current_user( $owner_id );
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

	/**
	 * Codex final round 2 MEDIUM: an earlier fix capped the underlying fetch at a single
	 * 2000-row batch, silently dropping every listing past it with no truncation signal -
	 * reproducing the exact same undercount bug the fix above was meant to close, just at a
	 * larger scale. Forces a tiny batch size so 7 real listings require 3 batches (3+3+1) to
	 * prove the loop actually exhausts the table instead of stopping after one page.
	 */
	public function test_get_listings_exhausts_every_batch_not_just_the_first(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = self::factory()->post->create_many(
			7,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );

		$this->assertSame( 7, $out['total'] );
		$this->assertCount( 7, $out['listings'] );
		$listed_ids = wp_list_pluck( $out['listings'], 'listing_id' );
		foreach ( $ids as $id ) {
			$this->assertContains( $id, $listed_ids );
		}
	}

	/**
	 * Codex hunt F8: the enumeration loop caps out silently after a fixed number of full
	 * batches, undercounting `total` with no signal. `truncated` must be false below the cap.
	 */
	public function test_get_listings_reports_not_truncated_below_the_cap(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create_many(
			5,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );

		$this->assertFalse( $out['truncated'] );
	}

	/**
	 * Codex hunt F8: lowering the (filterable) batch cap, rather than creating a thousand-plus
	 * posts, is the only practical way to exercise the cap in a test. With a batch size of 2 and
	 * a cap of 2, the loop can examine at most 4 rows before breaking - 5 real rows guarantees
	 * the cap is hit.
	 */
	public function test_get_listings_reports_truncated_once_the_batch_cap_is_hit(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create_many(
			5,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 4, $out['total'] );
	}

	/**
	 * Codex final round 4 LOW: hitting the cap only proves the last permitted batch came back
	 * full, not that a row was actually omitted - the row count here is an exact multiple of the
	 * batch size (cap 2 x batch size 2 = 4 rows, 4 real rows), so nothing was left out and
	 * `truncated` must be false.
	 */
	public function test_get_listings_reports_not_truncated_when_the_row_count_exactly_fills_the_cap(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create_many(
			4,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 4, $out['total'] );
	}

	/**
	 * Codex round 5, R5-7: the cap-lookahead probe used only 'perm' => 'readable', which does not
	 * exclude a draft/pending row the caller cannot edit - so a trailing draft owned by another
	 * user could flip `truncated` to true even though the caller's own visible set (four published
	 * listings) was already complete. The author here cannot edit the other user's draft, so it
	 * must never be counted as "one more visible row".
	 */
	public function test_get_listings_probe_does_not_count_another_users_trailing_draft(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => 2 );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		self::factory()->post->create_many(
			4,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $other,
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertSame( 4, $out['total'] );
		$this->assertFalse(
			$out['truncated'],
			'A trailing draft the caller cannot edit must not flip truncated once the visible set is complete.'
		);
	}

	/**
	 * Codex round 6, B6-5: the cap-lookahead probe used to fetch only ONE extra batch. If every
	 * row in that single batch happened to be invisible to the caller, a later visible row past
	 * it was still missed and `truncated` came back false even though more visible data existed.
	 * Four visible listings exactly fill the cap (batch size 2, cap 2), a full batch of another
	 * user's drafts follows (all invisible), and one more visible listing sits after that - the
	 * probe must keep advancing past the all-invisible batch to find it.
	 */
	public function test_get_listings_probe_advances_past_an_entirely_invisible_batch(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => 2 );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		self::factory()->post->create_many(
			4,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create_many(
			2,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $other,
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertTrue(
			$out['truncated'],
			'A visible listing past a fully-invisible probe batch must still flip truncated to true.'
		);
	}

	/**
	 * Codex round 6, B6-5: the mirror case. Once the probe has advanced past every invisible
	 * batch and reaches the real end of the data with nothing visible left, `truncated` must
	 * settle back to false rather than get stuck true from having looped at all.
	 */
	public function test_get_listings_probe_reports_not_truncated_when_only_invisible_rows_remain(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => 2 );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		self::factory()->post->create_many(
			4,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create_many(
			3,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $other,
			)
		);

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertFalse(
			$out['truncated'],
			'Once every remaining row is invisible to the caller, truncated must settle to false.'
		);
	}

	/**
	 * Codex final round 4 MEDIUM: the batch cap filter had no ceiling, so a hook returning
	 * PHP_INT_MAX defeated the cap's purpose entirely. It may only narrow the cap, never raise it
	 * past the hard 1000 ceiling.
	 */
	public function test_geodirectory_listing_batch_cap_cannot_be_raised_past_1000(): void {
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => PHP_INT_MAX );

		$cap = aafm_geodirectory_listing_batch_cap();

		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertSame( 1000, $cap );
	}

	/**
	 * Codex round 5, R5-6: the test above only proves aafm_geodirectory_listing_batch_cap()
	 * itself clamps correctly - it says nothing about whether the executor's own loop actually
	 * calls that helper rather than reading the raw filter value. A 'the_posts' filter that keeps
	 * padding every batch back up to full size, the way a pathological host filter would, means
	 * the ONLY thing that can stop this loop is the executor honoring the clamped 1000-iteration
	 * ceiling, not the two real posts it is fed.
	 */
	public function test_get_listings_executor_honours_the_batch_cap_ceiling_even_when_the_filter_tries_to_raise_it(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => PHP_INT_MAX );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = self::factory()->post->create_many(
			2,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);

		// B7 (1.7.5 deferred): a broken production clamp used to have nothing to stop this loop -
		// the padding below reports a full batch forever, so a regression would run until PHP's
		// own execution-time limit killed the test, not until an assertion failed. $query_count
		// turns that into a bounded, diagnosable failure: 1010 comfortably covers the documented
		// 1000-iteration enumeration ceiling plus the disambiguation probe's own small reserve
		// (never more than 2, see the sibling test below), so a genuine regression trips the
		// safety valve and then fails loudly on the assertion, rather than hanging.
		$query_count = 0;
		$pad         = static function ( $posts, $query ) use ( $ids, &$query_count ) {
			if ( ! $query->get( 'aafm_query_marker' ) ) {
				return $posts;
			}
			++$query_count;
			if ( $query_count > 1010 ) {
				return array();
			}
			// Always report a full batch of the same two real posts, exactly what a host filter
			// that never runs dry would do - so only the executor's own clamp of the cap filter
			// down to 1000 can end this loop.
			return array( get_post( $ids[0] ), get_post( $ids[1] ) );
		};
		add_filter( 'the_posts', $pad, 10, 2 );

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_filter( 'the_posts', $pad );
		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertTrue(
			$out['truncated'],
			'The executor must stop at the hard 1000-iteration ceiling even when the cap filter tries to raise it past that.'
		);
		$this->assertLessThanOrEqual(
			1002,
			$query_count,
			'A broken ceiling clamp must fail this test on this assertion, not run until PHP\'s own execution-time limit kills it.'
		);
	}

	/**
	 * Codex round 7, R7-5: the test above only checks `truncated`, not how many real queries it
	 * took to get there - it stayed green while the disambiguation probe quietly doubled the
	 * documented ceiling (1000 for enumeration, then a fresh 1000 more for the probe). Padding
	 * every batch with drafts the caller cannot edit means the probe can never resolve truncation
	 * by finding a visible row, so it can only stop by exhausting its own reserve - proving the
	 * combined total stays near the documented 1000-iteration ceiling instead of doubling it.
	 */
	public function test_get_listings_probe_draws_from_a_small_shared_reserve_not_a_second_full_budget(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		add_filter( 'aafm_geodirectory_list_batch_cap', static fn() => PHP_INT_MAX );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$ids   = self::factory()->post->create_many(
			2,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'draft',
				'post_author' => $other,
			)
		);

		// F12 (1.7.5 deferred): the same escape as the sibling ceiling test above (B7) - a broken
		// production clamp has nothing else to stop this loop, since the padding below reports a
		// full batch forever with the cap filtered to PHP_INT_MAX. Without this, a regression here
		// hangs until PHP's own execution-time limit kills the test instead of failing on the
		// assertion below.
		$query_count = 0;
		$pad         = static function ( $posts, $query ) use ( $ids, &$query_count ) {
			if ( ! $query->get( 'aafm_query_marker' ) ) {
				return $posts;
			}
			++$query_count;
			if ( $query_count > 1010 ) {
				return array();
			}
			// Always report a full batch of the same two drafts, invisible to the current author -
			// so neither the enumeration's own cap nor the probe's reserve can ever be ended early
			// by finding a short batch or a visible row.
			return array( get_post( $ids[0] ), get_post( $ids[1] ) );
		};
		add_filter( 'the_posts', $pad, 10, 2 );

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_filter( 'the_posts', $pad );
		remove_all_filters( 'aafm_geodirectory_list_batch_size' );
		remove_all_filters( 'aafm_geodirectory_list_batch_cap' );

		$this->assertTrue( $out['truncated'] );
		$this->assertLessThanOrEqual(
			1002,
			$query_count,
			'The probe must draw from a small shared reserve instead of its own independent 1000-iteration budget, so the combined total stays near the documented ceiling instead of doubling it.'
		);
	}

	/**
	 * Codex final round 3 MEDIUM: an OFFSET-based batch loop is unstable under mutation - trashing
	 * a row from an earlier batch shifts every later OFFSET window down by one, so the next batch
	 * skips exactly one real row. Keyset pagination (WHERE ID > last-seen-ID, no offset at all)
	 * must not exhibit this: the 4th listing by ID is exactly the row an offset-based scan would
	 * have skipped after a row from the first batch is trashed mid-scan.
	 */
	public function test_get_listings_does_not_skip_a_row_when_an_earlier_one_is_removed_mid_scan(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = self::factory()->post->create_many(
			5,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);
		sort( $ids );

		$batches  = 0;
		$callback = function ( $query ) use ( &$batches, $ids ) {
			if ( 'gd_place' !== $query->get( 'post_type' ) ) {
				return;
			}
			++$batches;
			if ( 2 === $batches ) {
				wp_trash_post( $ids[0] );
			}
		};
		add_action( 'pre_get_posts', $callback );

		$out = aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_action( 'pre_get_posts', $callback );
		remove_all_filters( 'aafm_geodirectory_list_batch_size' );

		$listed_ids = wp_list_pluck( $out['listings'], 'listing_id' );
		$this->assertContains(
			$ids[3],
			$listed_ids,
			'A row must not be skipped when an earlier row is removed mid-scan.'
		);
	}

	/**
	 * Codex final round 4 MEDIUM: the keyset filter was attached to 'posts_where' unscoped, so it
	 * ran against EVERY WP_Query built while it was active, not only this function's own batch
	 * queries - an unrelated nested WP_Query (fired from any hook during the scan) for an
	 * already-passed ID would incorrectly receive the same "ID > last-seen" clause and come back
	 * empty. A private per-call marker in the query args must keep the filter scoped to its own
	 * queries only.
	 */
	public function test_get_listings_does_not_contaminate_an_unrelated_nested_query(): void {
		add_filter( 'aafm_geodirectory_list_batch_size', static fn() => 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = self::factory()->post->create_many(
			4,
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);
		sort( $ids );

		$batches       = 0;
		$nested_result = null;
		$callback      = function ( $query ) use ( &$batches, &$nested_result, $ids ) {
			// Only count OUR OWN marked batch queries; an unmarked query (including the nested
			// probe fired below) must fall through untouched.
			if ( 'gd_place' !== $query->get( 'post_type' ) || ! $query->get( 'aafm_query_marker' ) ) {
				return;
			}
			++$batches;
			if ( 2 === $batches ) {
				// An unrelated nested query, fired mid-scan, for a post ID from the FIRST batch -
				// exactly the id our own keyset cursor has already advanced past.
				$nested        = new \WP_Query(
					array(
						'post_type'   => 'gd_place',
						'post__in'    => array( $ids[0] ),
						'post_status' => 'any',
					)
				);
				$nested_result = $nested->posts;
			}
		};
		add_action( 'pre_get_posts', $callback );

		aafm_exec_geodirectory_get_listings( array( 'per_page' => 100 ) );

		remove_action( 'pre_get_posts', $callback );
		remove_all_filters( 'aafm_geodirectory_list_batch_size' );

		$this->assertNotEmpty(
			$nested_result,
			'An unrelated nested query must not be contaminated by our own keyset filter.'
		);
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

	/**
	 * Codex hunt F4: the title/content wp_update_post() call was checked only via
	 * is_wp_error(), never confirmed by reread - a wp_insert_post_data filter that reverts the
	 * title back to its old value must surface as a structured error, not a success response
	 * claiming the requested title landed.
	 */
	public function test_update_listing_returns_an_error_when_the_title_write_is_vetoed(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$created = aafm_exec_geodirectory_create_listing( array( 'title' => 'Original' ) );
		$id      = $created['listing_id'];

		$veto = static function ( $data ) {
			$data['post_title'] = 'Original';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $veto );
		$out = aafm_exec_geodirectory_update_listing(
			array(
				'listing_id' => $id,
				'title'      => 'Renamed',
			)
		);
		remove_filter( 'wp_insert_post_data', $veto );

		$this->assertInstanceOf(
			\WP_Error::class,
			$out,
			'A vetoed title write must return an error, not a success claiming the new title was saved.'
		);
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
	 * Codex round 5, R5-4: create only ever verified the address/location fields (the test
	 * above), never the core title/content/status the update path already confirms (Codex hunt
	 * F4) - a wp_insert_post_data filter that silently reverts the requested title must roll the
	 * create back and report an error, not a success response claiming the requested title landed.
	 */
	public function test_create_rolls_back_and_errors_when_the_title_write_is_vetoed(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$veto = static function ( $data ) {
			$data['post_title'] = 'Vetoed back to this';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $veto );
		$out = aafm_exec_geodirectory_create_listing( array( 'title' => 'Requested title' ) );
		remove_filter( 'wp_insert_post_data', $veto );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_geodirectory_write_unconfirmed', $out->get_error_code() );

		$leftover = new \WP_Query(
			array(
				'post_type'      => 'gd_place',
				'post_status'    => 'any',
				'title'          => 'Vetoed back to this',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$this->assertSame( array(), $leftover->posts, 'A create whose title could not be confirmed must not be left behind.' );
	}

	/**
	 * Codex round 6 B6-3: the title/content/status confirmation compared the fresh read against
	 * the pre-write intent, so a legitimate save-time normalization was indistinguishable from a
	 * veto - and here that false mismatch DELETES an otherwise valid listing
	 * (aafm_geodirectory_rollback_unconfirmed_create()), not merely reports an error. An acting
	 * user who lacks unfiltered_html gets core's own title_save_pre kses on save (kses_init()),
	 * which unconditionally entity-encodes a bare ampersand the same way it always does for that
	 * role. The listing must survive with its title in the canonical, actually-stored form.
	 */
	public function test_create_confirms_a_legitimate_ampersand_title_normalization_and_does_not_delete_the_listing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'precondition: an author must not hold unfiltered_html.' );

		$out = aafm_exec_geodirectory_create_listing( array( 'title' => 'Fish & Chips Diner' ) );

		$this->assertIsArray( $out, 'A create whose title landed in its kses-normalized form must not be rolled back as unconfirmed.' );
		$this->assertSame( 'Fish &amp; Chips Diner', get_post( $out['listing_id'] )->post_title );
	}

	/**
	 * Codex round 7 R7-4: core's own wp_insert_post() sanitizes a CREATE's fields
	 * (sanitize_post( $postarr, 'db' ), wp-includes/post.php) BEFORE the row exists and before an
	 * id is assigned - sanitize_post() defaults the missing id to 0. The confirmation guard used
	 * to recompute the expected value with the newly assigned, positive id instead, so an
	 * id-sensitive registered filter could disagree with what core actually did and the guard
	 * would roll back (delete) an otherwise valid listing.
	 *
	 * Pre_post_title/title_save_pre receive only the value, never the post id, as an argument
	 * (verified by reading sanitize_post_field()'s 'db' branch and by probing it directly against
	 * this WP install), so this filter reads the id sanitize_post_field() was actually invoked
	 * with off the call stack - the same fact an id-sensitive vendor filter would ultimately be
	 * keying off through some other means (a plugin-tracked "is this an update" flag, for
	 * instance).
	 */
	public function test_create_survives_an_id_sensitive_title_filter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$decorate = static function ( $value ) {
			foreach ( debug_backtrace( 0 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- deliberate test-fixture use to detect the id sanitize_post_field() was invoked with, not production debug code.
				if ( isset( $frame['function'], $frame['args'][2] ) && 'sanitize_post_field' === $frame['function'] ) {
					return 0 === (int) $frame['args'][2] ? $value : $value . ' (edited)';
				}
			}
			return $value;
		};
		add_filter( 'pre_post_title', $decorate );
		$out = aafm_exec_geodirectory_create_listing( array( 'title' => 'New Listing' ) );
		remove_filter( 'pre_post_title', $decorate );

		$this->assertIsArray(
			$out,
			'A create whose title was sanitized with id 0, exactly as core itself does before the row exists, must not be rolled back because the confirmation recomputed it with the newly assigned id instead.'
		);
		$this->assertSame( 'New Listing', get_post( $out['listing_id'] )->post_title );
	}

	/**
	 * Codex final round 2 MEDIUM: a legitimate third-party filter on 'geodir_get_post_info' that
	 * merely reformats the returned value (not GeoDirectory's own default behavior - something
	 * another active plugin or theme could add) must not make the write-confirmation check see a
	 * mismatch and wrongly roll back a listing that was actually written correctly.
	 */
	public function test_create_survives_a_decorating_geodir_get_post_info_filter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'geodir_get_post_info',
			static function ( $row ) {
				if ( is_object( $row ) && isset( $row->street ) ) {
					$row->street = strtoupper( (string) $row->street );
				}
				return $row;
			}
		);
		$out = aafm_exec_geodirectory_create_listing(
			array(
				'title'  => 'Decorated read survives',
				'street' => '1 main st',
			)
		);
		remove_all_filters( 'geodir_get_post_info' );

		// The create must NOT be rolled back: the confirmation check bypasses the decorating
		// filter and sees the real stored value matches what was written. The RETURNED shape,
		// by contrast, still goes through the normal (filtered) read - so it correctly shows the
		// decorated value, proving the filter genuinely ran and only the confirmation ignored it.
		$this->assertIsArray( $out );
		$this->assertSame( '1 MAIN ST', $out['street'] );
	}

	/**
	 * Codex final round 3 MEDIUM: geodir_get_post_info() has a SECOND filter point the round-2
	 * fix missed - 'geodir_post_info_query' reshapes the SQL query itself, before either the
	 * database read or the round-2 filter ever run. Reproduces Codex's own repro: a filter that
	 * rewrites the query to return an uppercased street column. The confirmation must not go
	 * through geodir_get_post_info() at all (query filter included), only a direct table read.
	 */
	public function test_create_survives_a_decorating_geodir_post_info_query_filter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'geodir_post_info_query',
			static function ( $query ) {
				return preg_replace( '/SELECT \*/', 'SELECT post_id, UPPER(street) AS street, street2, city, region, country, zip, latitude, longitude', (string) $query );
			}
		);
		$out = aafm_exec_geodirectory_create_listing(
			array(
				'title'  => 'Query-filter decoration survives',
				'street' => '1 main st',
			)
		);
		remove_all_filters( 'geodir_post_info_query' );

		// Must NOT be rolled back: the confirmation's direct table read never runs this query at
		// all, so it sees the real lowercase stored value. The returned shape, still going
		// through the normal (filtered) read, correctly shows the query-decorated uppercase value.
		$this->assertIsArray( $out );
		$this->assertSame( '1 MAIN ST', $out['street'] );
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

	/**
	 * Codex final round 9 MEDIUM: aafm_exec_geodirectory_create_listing() built its own
	 * wp_insert_post() call instead of routing through aafm_insert_post(), so none of the
	 * operator's three global content-safety settings ever applied to it.
	 */
	public function test_create_listing_honours_force_draft_even_for_an_authorized_publish_request(): void {
		update_option( 'aafm_force_draft', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$out = aafm_exec_geodirectory_create_listing(
			array(
				'title'  => 'Force-drafted listing',
				'status' => 'publish',
			)
		);

		delete_option( 'aafm_force_draft' );

		$this->assertIsArray( $out );
		$this->assertSame( 'draft', $out['status'] );
	}

	public function test_create_listing_enforces_the_max_title_length(): void {
		update_option( 'aafm_max_title_len', 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$out = aafm_exec_geodirectory_create_listing( array( 'title' => 'This title is far too long' ) );

		delete_option( 'aafm_max_title_len' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_title_too_long', $out->get_error_code() );
	}

	public function test_create_listing_enforces_strict_block_validation(): void {
		update_option( 'aafm_block_guard_strict', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$out = aafm_exec_geodirectory_create_listing(
			array(
				'title'   => 'Bad markup listing',
				'content' => '<!-- wp:heading --><h2 class="has-text-color">Hi</h2><!-- /wp:heading -->',
			)
		);

		delete_option( 'aafm_block_guard_strict' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_block_content', $out->get_error_code() );
	}

	public function test_update_listing_enforces_the_max_title_length(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$created = aafm_exec_geodirectory_create_listing( array( 'title' => 'Existing listing' ) );
		$this->assertIsArray( $created );

		update_option( 'aafm_max_title_len', 5 );
		$out = aafm_exec_geodirectory_update_listing(
			array(
				'listing_id' => $created['listing_id'],
				'title'      => 'This title is far too long',
			)
		);
		delete_option( 'aafm_max_title_len' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_title_too_long', $out->get_error_code() );
	}

	public function test_update_listing_enforces_strict_block_validation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$created = aafm_exec_geodirectory_create_listing( array( 'title' => 'Existing listing' ) );
		$this->assertIsArray( $created );

		update_option( 'aafm_block_guard_strict', true );
		$out = aafm_exec_geodirectory_update_listing(
			array(
				'listing_id' => $created['listing_id'],
				'content'    => '<!-- wp:heading --><h2 class="has-text-color">Hi</h2><!-- /wp:heading -->',
			)
		);
		delete_option( 'aafm_block_guard_strict' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_block_content', $out->get_error_code() );
	}
}
