<?php
/**
 * Aafm/replace-sitewide: dry-run-by-default literal find-and-replace across multiple posts.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ReplaceSitewideTest extends TestCase {

	public function test_dry_run_defaults_true_and_makes_no_change(): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => 'the quick fox' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
			)
		);

		$this->assertTrue( $out['dry_run'] );
		$this->assertSame( 'the quick fox', get_post( $post->ID )->post_content );
		$this->assertSame( 1, $out['matched_posts'] );
		$this->assertSame( 0, $out['failed_updates'] );
	}

	public function test_explicit_dry_run_false_writes_the_change(): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => 'the quick fox' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
				'dry_run' => false,
			)
		);

		$this->assertSame( 'the slow fox', get_post( $post->ID )->post_content );
	}

	public function test_scoped_by_post_type_and_status(): void {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_content' => 'quick page',
			)
		);
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'post',
				'post_content' => 'quick post',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		aafm_exec_replace_sitewide(
			array(
				'search'    => 'quick',
				'replace'   => 'slow',
				'post_type' => 'page',
				'dry_run'   => false,
			)
		);

		$this->assertSame( 'slow page', get_post( $page->ID )->post_content );
		$this->assertSame( 'quick post', get_post( $post->ID )->post_content, 'A post outside the requested post_type must never be touched.' );
	}

	public function test_skips_a_post_the_caller_cannot_edit_and_reports_it(): void {
		$caller = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create_and_get(
			array(
				'post_content' => 'quick a',
				'post_author'  => $caller,
			)
		);
		$other_user = self::factory()->user->create();
		self::factory()->post->create_and_get(
			array(
				'post_content' => 'quick b',
				'post_author'  => $other_user,
			)
		);

		wp_set_current_user( $caller );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
				'dry_run' => false,
			)
		);

		$this->assertSame( 1, $out['skipped_no_permission'] );
	}

	public function test_a_match_inside_markup_is_refused_per_post_and_reported(): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => '<img src="quick.jpg">' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
				'dry_run' => false,
			)
		);

		$this->assertSame( 1, $out['skipped_structure_guard'] );
		$this->assertStringContainsString( 'quick.jpg', get_post( $post->ID )->post_content );
	}

	/**
	 * Codex-review finding: dry-run must run the same guards a real apply would, so its preview
	 * is an honest forecast - previously the guard checks sat AFTER the dry-run early return, so
	 * a dry-run always reported skipped_structure_guard:0 even for a post the real write would
	 * refuse.
	 */
	public function test_dry_run_still_reports_a_structure_guard_refusal(): void {
		self::factory()->post->create_and_get( array( 'post_content' => '<img src="quick.jpg">' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
			)
		);

		$this->assertTrue( $out['dry_run'] );
		$this->assertSame( 1, $out['skipped_structure_guard'] );
	}

	/**
	 * Codex-review finding: MySQL's default collation makes LIKE case-insensitive, so a naive
	 * SQL match for "quick" would also select a post containing only "Quick" - but str_replace()
	 * is case-sensitive and would leave it byte-for-byte unchanged, silently inflating
	 * total_matches/updated_posts for a write that touched nothing.
	 */
	public function test_search_is_case_sensitive_and_does_not_match_a_different_case(): void {
		self::factory()->post->create_and_get( array( 'post_content' => 'the Quick fox' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
			)
		);

		$this->assertSame( 0, $out['total_matches'] );
		$this->assertSame( 0, $out['matched_posts'] );
	}

	/**
	 * Codex-review amendment 19: the SQL-side match must find a real match even when it sits
	 * well past the first AAFM_REPLACE_SITEWIDE_MAX_POSTS posts by ascending ID - the exact case
	 * the plan's original fetched-page-then-filtered-in-PHP draft would have silently missed
	 * from both matched_posts and total_matches.
	 */
	public function test_a_match_beyond_the_page_cap_by_id_is_still_found_and_counted(): void {
		// 55 leading non-matching posts (by ascending ID), then one real match.
		self::factory()->post->create_many( 55, array( 'post_content' => 'nothing relevant here' ) );
		$matching = self::factory()->post->create_and_get( array( 'post_content' => 'a needlephrase appears here' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'needlephrase',
				'replace' => 'found',
			)
		);

		$this->assertSame( 1, $out['total_matches'], 'The real match must be counted even though it sits past the first 55 posts by ID.' );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 1, $out['matched_posts'] );
		$this->assertSame( $matching->ID, $matching->ID ); // Sanity: the fixture post exists.
	}

	public function test_truncated_true_and_real_total_when_matches_exceed_the_cap(): void {
		// 52 matching posts, one over AAFM_REPLACE_SITEWIDE_MAX_POSTS (50).
		self::factory()->post->create_many( 52, array( 'post_content' => 'shared needleterm here' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'needleterm',
				'replace' => 'x',
			)
		);

		$this->assertSame( 52, $out['total_matches'] );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 50, $out['matched_posts'] );
	}

	/**
	 * Codex final round 2 MEDIUM: the SQL-side cap applied before permission filtering, so 50
	 * matching posts the caller cannot edit could occupy the entire cap and the caller's own
	 * editable match (a later ID) was never even fetched. Repeating the call selected the exact
	 * same unreachable window every time. The caller's own post must be found and processed
	 * regardless of how many non-editable matches sit earlier in ID order.
	 */
	public function test_reaches_an_editable_match_past_50_non_editable_ones(): void {
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create_many(
			50,
			array(
				'post_content' => 'shared needleterm here',
				'post_author'  => $other_id,
			)
		);

		$caller_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$own_post  = self::factory()->post->create_and_get(
			array(
				'post_content' => 'shared needleterm here',
				'post_author'  => $caller_id,
			)
		);
		wp_set_current_user( $caller_id );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'needleterm',
				'replace' => 'x',
				'dry_run' => false,
			)
		);

		$this->assertSame( 51, $out['total_matches'] );
		$this->assertSame( 50, $out['skipped_no_permission'] );
		$this->assertSame( 1, $out['updated_posts'] );
		$this->assertSame( 'shared x here', get_post( $own_post->ID )->post_content );
	}

	/**
	 * Codex final round 3 MEDIUM: the candidate scan had no ceiling of its own, so a search term
	 * matching an enormous number of non-editable posts could force scanning all of them before
	 * giving up. Forces a tiny scan budget so a handful of non-editable posts (rather than
	 * thousands) proves the scan genuinely stops instead of continuing to the caller's own
	 * editable match sitting just past it.
	 */
	public function test_scan_stops_at_the_configured_ceiling_before_reaching_an_editable_match(): void {
		add_filter( 'aafm_replace_sitewide_max_scan', static fn() => 3 );

		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create_many(
			5,
			array(
				'post_content' => 'shared needleterm here',
				'post_author'  => $other_id,
			)
		);
		$caller_id = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_content' => 'shared needleterm here',
				'post_author'  => $caller_id,
			)
		);
		wp_set_current_user( $caller_id );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'needleterm',
				'replace' => 'x',
			)
		);

		remove_all_filters( 'aafm_replace_sitewide_max_scan' );

		// The scan stopped after 3 non-editable posts, never reaching the caller's own editable
		// 6th match - proving the ceiling actually bounds the scan rather than being decorative.
		$this->assertSame( 3, $out['skipped_no_permission'] );
		$this->assertSame( 0, $out['matched_posts'] );
	}

	/**
	 * Codex final round 4 MEDIUM: the LIKE-clause filter ran against EVERY WP_Query built while
	 * it was attached, not only this function's own count/scan queries - an unrelated nested
	 * query (fired from any hook during either query) would silently receive the same
	 * post_content LIKE clause even though it has nothing to do with the search.
	 */
	public function test_does_not_contaminate_an_unrelated_nested_query(): void {
		$other_post = self::factory()->post->create( array( 'post_content' => 'nothing to do with the search term' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$nested_result = null;
		$callback      = function ( $query ) use ( &$nested_result, $other_post ) {
			if ( 'post' !== $query->get( 'post_type' ) || ! $query->get( 'aafm_query_marker' ) ) {
				return;
			}
			// An unrelated nested query for a post that does NOT contain the search term at all -
			// if the LIKE filter leaked onto it, it would come back empty. Fires on both the
			// count and the scan query; either overwrite of $nested_result proves the same point.
			$nested        = new \WP_Query(
				array(
					'post_type' => 'post',
					'post__in'  => array( $other_post ),
				)
			);
			$nested_result = $nested->posts;
		};
		add_action( 'pre_get_posts', $callback );

		aafm_exec_replace_sitewide(
			array(
				'search'  => 'needletermxyz',
				'replace' => 'x',
			)
		);

		remove_action( 'pre_get_posts', $callback );

		$this->assertNotEmpty(
			$nested_result,
			'An unrelated nested query must not be contaminated by the LIKE-clause filter.'
		);
	}
}
