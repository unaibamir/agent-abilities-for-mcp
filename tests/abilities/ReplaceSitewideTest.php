<?php
/**
 * Aafm/replace-sitewide: dry-run-by-default literal find-and-replace across multiple posts.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;
use WP_Query;

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

	/**
	 * Codex round 5 R5-2: only is_wp_error() was checked on each post's wp_update_post() result,
	 * so a wp_insert_post_data filter that reverts the content must count that post as a failed
	 * write, not an updated one, for a change that never actually landed in storage.
	 */
	public function test_a_vetoed_write_is_counted_as_failed_not_updated(): void {
		$content = 'the quick fox';
		$post    = self::factory()->post->create_and_get( array( 'post_content' => $content ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$veto = static function ( $data ) use ( $content ) {
			$data['post_content'] = $content;
			return $data;
		};
		add_filter( 'wp_insert_post_data', $veto );
		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
				'dry_run' => false,
			)
		);
		remove_filter( 'wp_insert_post_data', $veto );

		$this->assertSame( $content, get_post( $post->ID )->post_content, 'precondition: the veto filter must have kept the content unwritten.' );
		$this->assertSame( 0, $out['updated_posts'], 'A vetoed write must not be counted as updated.' );
		$this->assertSame( 1, $out['failed_updates'], 'A vetoed write must be counted as a failed update.' );
	}

	/**
	 * The candidate scan's SELECT, told apart from the count probe by its ID order.
	 */
	private const SCAN_NEEDLE = array( 'LIKE BINARY', '.ID ASC' );

	/**
	 * A post (or page) holding the needle, with an editor acting.
	 *
	 * @param string $type    Post type.
	 * @param string $content Post content.
	 */
	private function needle_post( string $type = 'post', string $content = 's14-needle' ): \WP_Post {
		return self::factory()->post->create_and_get(
			array(
				'post_type'    => $type,
				'post_content' => $content,
			)
		);
	}

	/**
	 * Run a real replace for s14-needle, scoped to posts.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function replace_needle_in_posts() {
		return aafm_exec_replace_sitewide(
			array(
				'search'    => 's14-needle',
				'replace'   => 's14-done',
				'post_type' => 'post',
				'dry_run'   => false,
			)
		);
	}

	/**
	 * Answer the scan from an earlier posts_pre_query callback with $ids.
	 *
	 * @param int[] $ids Ids the callback hands WP_Query.
	 * @return callable The callback, already added at priority 10.
	 */
	private function answer_the_scan_with( array $ids ): callable {
		$callback = static function ( $posts, WP_Query $query ) use ( $ids ) {
			if ( 0 === strpos( (string) $query->get( 'aafm_query_marker' ), 'aafm_replace_sitewide_' ) && 'ID' === $query->get( 'orderby' ) ) {
				return $ids;
			}
			return $posts;
		};
		add_filter( 'posts_pre_query', $callback, 10, 2 );
		return $callback;
	}

	/**
	 * W4-T1 (ledger s14hunta-4, row W4-b): a scan SELECT that fails with a real error returns the
	 * generic error and writes nothing, instead of reporting zero matches as a success.
	 */
	public function test_a_failed_scan_returns_the_generic_error_and_writes_nothing(): void {
		$post = $this->needle_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		QueryFaultInjector::reset_fired_count();
		$out = QueryFaultInjector::break_query_with_real_error( self::SCAN_NEEDLE, fn() => $this->replace_needle_in_posts() );

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count(), 'The scan fault must fire.' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( 's14-needle', get_post( $post->ID )->post_content );
	}

	/**
	 * W4-T2 (the hunt probe, row W4-c): a scan that fails without flushing hands back the previous
	 * query's ids, here a page, in a request scoped to posts. The failure is seen and nothing is
	 * written.
	 */
	public function test_a_scan_that_leaks_another_types_id_returns_the_generic_error(): void {
		global $wpdb;
		$post = $this->needle_post();
		$page = $this->needle_post( 'page' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		QueryFaultInjector::reset_fired_count();
		$filter = QueryFaultInjector::leak_row_filter( self::SCAN_NEEDLE, $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->posts, $page->ID ), 1 );
		add_filter( 'query', $filter );
		try {
			$out = $this->replace_needle_in_posts();
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( 's14-needle', get_post( $page->ID )->post_content, 'A page must never be written by a request scoped to posts.' );
		$this->assertSame( 's14-needle', get_post( $post->ID )->post_content );
	}

	/**
	 * States for W4-T3: when the second candidate changes, and into what.
	 *
	 * @return array<string, array{0: string, 1: array<string,string>}>
	 */
	public function changed_candidate_cases(): array {
		return array(
			'type, before the load'    => array( 'map_meta_cap', array( 'post_type' => 'page' ) ),
			'status, before the load'  => array( 'map_meta_cap', array( 'post_status' => 'draft' ) ),
			'type, before the write'   => array( 'save_post', array( 'post_type' => 'page' ) ),
			'status, before the write' => array( 'save_post', array( 'post_status' => 'draft' ) ),
		);
	}

	/**
	 * W4-T3 (row W4-d): a scanned post whose type or status changes before it is loaded, or before
	 * it is written, counts in failed_updates and is not written; the rest of the batch proceeds.
	 *
	 * @dataProvider changed_candidate_cases
	 *
	 * @param string               $hook   Hook that changes the second post, once.
	 * @param array<string,string> $change Columns to change.
	 */
	public function test_a_candidate_that_changed_type_or_status_is_counted_failed_and_not_written( string $hook, array $change ): void {
		global $wpdb;
		$first  = $this->needle_post();
		$second = $this->needle_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// Fire once, while the first post is checked (map_meta_cap for edit_post) or saved.
		$done = false;
		$flip = static function ( ...$args ) use ( &$done, $wpdb, $first, $second, $change ) {
			$is_first = 'save_post' === current_filter()
				? (int) $args[0] === $first->ID
				: 'edit_post' === $args[1] && (int) ( $args[3][0] ?? 0 ) === $first->ID;
			if ( ! $done && $is_first ) {
				$done = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- another request changing the post mid-run.
				$wpdb->update( $wpdb->posts, $change, array( 'ID' => $second->ID ) );
				clean_post_cache( $second->ID );
			}
			return $args[0];
		};
		add_filter( $hook, $flip, 10, 4 );
		try {
			$out = $this->replace_needle_in_posts();
		} finally {
			remove_filter( $hook, $flip );
		}

		$this->assertTrue( $done, 'The change must happen.' );
		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['updated_posts'] );
		$this->assertSame( 1, $out['failed_updates'] );
		$this->assertSame( 's14-done', get_post( $first->ID )->post_content );
		$this->assertSame( 's14-needle', get_post( $second->ID )->post_content );
	}

	/**
	 * W4-T5 pin (row W4-e, NC-W4-1): a failed count probe still reads total_matches 0 and
	 * truncated false, and the scan and its writes run as before; the failure-aware scan is not
	 * attached to the probe.
	 */
	public function test_a_failed_count_probe_leaves_the_scan_and_its_writes_alone(): void {
		$post = $this->needle_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		QueryFaultInjector::reset_fired_count();
		$out = QueryFaultInjector::break_query_with_real_error( array( 'SQL_CALC_FOUND_ROWS', 'LIKE BINARY' ), fn() => $this->replace_needle_in_posts() );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['total_matches'] );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 1, $out['updated_posts'] );
		$this->assertSame( 's14-done', get_post( $post->ID )->post_content );
	}

	/**
	 * W4-T6 (row W4-f): an earlier posts_pre_query callback's answer is used untouched and is not a
	 * failed scan. An empty answer matches nothing; a shorter one is all that gets written.
	 */
	public function test_an_earlier_posts_pre_query_answer_is_used_untouched(): void {
		$first  = $this->needle_post();
		$second = $this->needle_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$callback = $this->answer_the_scan_with( array() );
		try {
			$empty = $this->replace_needle_in_posts();
		} finally {
			remove_filter( 'posts_pre_query', $callback, 10 );
		}
		$this->assertIsArray( $empty );
		$this->assertSame( 0, $empty['matched_posts'] );
		$this->assertSame( 0, $empty['failed_updates'] );
		$this->assertSame( 's14-needle', get_post( $first->ID )->post_content );

		$callback = $this->answer_the_scan_with( array( $second->ID ) );
		try {
			$one = $this->replace_needle_in_posts();
		} finally {
			remove_filter( 'posts_pre_query', $callback, 10 );
		}
		$this->assertIsArray( $one );
		$this->assertSame( 1, $one['updated_posts'] );
		$this->assertSame( 0, $one['failed_updates'] );
		$this->assertSame( 's14-needle', get_post( $first->ID )->post_content );
		$this->assertSame( 's14-done', get_post( $second->ID )->post_content );
	}

	/**
	 * W4-T7 (row W4-g, ruling B): an earlier answer that holds a page in a request scoped to posts
	 * does not write the page; it counts in failed_updates and the post is still written.
	 */
	public function test_a_filtered_scan_answer_of_another_type_is_not_written(): void {
		$page = $this->needle_post( 'page' );
		$post = $this->needle_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$callback = $this->answer_the_scan_with( array( $page->ID, $post->ID ) );
		try {
			// A dry run has no pre-write reload, so the scope check at the load decides alone.
			$preview = aafm_exec_replace_sitewide(
				array(
					'search'    => 's14-needle',
					'replace'   => 's14-done',
					'post_type' => 'post',
				)
			);
			$out     = $this->replace_needle_in_posts();
		} finally {
			remove_filter( 'posts_pre_query', $callback, 10 );
		}

		$this->assertIsArray( $preview );
		$this->assertSame( 1, $preview['matched_posts'] );
		$this->assertSame( 1, $preview['failed_updates'] );
		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['updated_posts'] );
		$this->assertSame( 1, $out['failed_updates'] );
		$this->assertSame( 's14-needle', get_post( $page->ID )->post_content );
		$this->assertSame( 's14-done', get_post( $post->ID )->post_content );
	}

	/**
	 * W4-T7 pre_get_posts row (ledger s14c1r1-code-2, ruling B widened to any filter that widens
	 * the scan): a pre_get_posts callback that widens a posts-only scan to pages does not write the
	 * page; it counts in failed_updates and the post is still written.
	 */
	public function test_a_pre_get_posts_widened_scan_does_not_write_another_type(): void {
		$page = $this->needle_post( 'page' );
		$post = $this->needle_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$widen = static function ( \WP_Query $query ): void {
			if ( 'post' === $query->get( 'post_type' ) ) {
				$query->set( 'post_type', array( 'post', 'page' ) );
			}
		};
		add_action( 'pre_get_posts', $widen );
		try {
			$preview = aafm_exec_replace_sitewide(
				array(
					'search'    => 's14-needle',
					'replace'   => 's14-done',
					'post_type' => 'post',
				)
			);
			$out     = $this->replace_needle_in_posts();
		} finally {
			remove_action( 'pre_get_posts', $widen );
		}

		$this->assertIsArray( $preview );
		$this->assertSame( 1, $preview['matched_posts'] );
		$this->assertSame( 1, $preview['failed_updates'] );
		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['updated_posts'] );
		$this->assertSame( 1, $out['failed_updates'] );
		$this->assertSame( 's14-needle', get_post( $page->ID )->post_content );
		$this->assertSame( 's14-done', get_post( $post->ID )->post_content );
	}

	/**
	 * W4-T8 pin (row W4-h): an earlier answer with a right-type post that lacks the needle, the
	 * shape a search plugin's posts_pre_query gives, is not written and counts in
	 * skipped_structure_guard, not failed_updates.
	 */
	public function test_a_filtered_scan_answer_without_the_needle_is_skipped_by_the_structure_guard(): void {
		$other = $this->needle_post( 'post', 'nothing to replace here' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$callback = $this->answer_the_scan_with( array( $other->ID ) );
		try {
			$out = $this->replace_needle_in_posts();
		} finally {
			remove_filter( 'posts_pre_query', $callback, 10 );
		}

		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['updated_posts'] );
		$this->assertSame( 1, $out['skipped_structure_guard'] );
		$this->assertSame( 0, $out['failed_updates'] );
		$this->assertSame( 'nothing to replace here', get_post( $other->ID )->post_content );
	}
}
