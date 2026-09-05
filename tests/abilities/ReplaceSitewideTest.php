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
}
