<?php
/**
 * Revision abilities: the list-revisions read path and its shared parent-editability gate.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

// Task 5 wires revisions.php into the plugin bootstrap's require list. Until then,
// load the ability file here so its global aafm_* functions resolve for this suite.
if ( ! function_exists( 'aafm_perm_list_revisions' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/abilities/revisions.php';
}

final class RevisionsTest extends TestCase {

	public function test_list_revisions_happy_and_gates(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v3',
			)
		);

		$this->assertTrue( aafm_perm_list_revisions( array( 'post_id' => $pid ) ) );
		$out = aafm_exec_list_revisions( array( 'post_id' => $pid ) );
		$this->assertGreaterThanOrEqual( 2, $out['total'] );
		$this->assertArrayHasKey( 'id', $out['revisions'][0] );
		$this->assertArrayNotHasKey( 'content', $out['revisions'][0] );

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$this->assertFalse( aafm_perm_list_revisions( array( 'post_id' => $pid ) ) );
	}

	public function test_get_revision_enforces_parent(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$a = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'a1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => 'a2',
			)
		);
		$revs = wp_get_post_revisions( $a );
		$rev  = array_shift( $revs );

		$this->assertTrue(
			aafm_perm_get_revision(
				array(
					'post_id'     => $a,
					'revision_id' => (int) $rev->ID,
				)
			)
		);
		$out = aafm_exec_get_revision(
			array(
				'post_id'     => $a,
				'revision_id' => (int) $rev->ID,
			)
		);
		$this->assertSame( $a, $out['revision']['post_id'] );

		$b = self::factory()->post->create( array( 'post_author' => $author ) );
		$this->assertFalse(
			aafm_perm_get_revision(
				array(
					'post_id'     => $b,
					'revision_id' => (int) $rev->ID,
				)
			)
		);
	}

	public function test_restore_revision_is_reversible(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'v1',
			)
		);
		// Two updates so a genuine earlier revision exists. WordPress snapshots the content
		// being saved, so the oldest revision holds 'v2' (the current insert 'v1' is never
		// itself revisioned), and the post currently sits at 'v3'.
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v3',
			)
		);
		$revs    = wp_get_post_revisions( $pid );      // newest first; the earlier 'v2' snapshot is the last.
		$oldest  = end( $revs );
		$content = $oldest->post_content;              // the state we expect to be restored ('v2').
		$before  = count( $revs );

		$out = aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $oldest->ID,
			)
		);
		$this->assertSame(
			array(
				'restored'    => true,
				'post_id'     => $pid,
				'revision_id' => (int) $oldest->ID,
			),
			$out
		);
		$this->assertStringContainsString( $content, get_post( $pid )->post_content );
		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $pid ) ) ); // a new revision was written.
	}

	/**
	 * A restore whose underlying write fails must surface the generic error, never a false
	 * {restored:true}. wp_restore_post_revision() returns the wp_update_post() result, which is
	 * falsy (0/false/null) on failure and — per its documented int|false|null contract being
	 * incomplete — may be a WP_Error. A WP_Error is a truthy object, so the old falsy-only guard
	 * would have reported success for a failed write and the audit layer would have logged it as
	 * one. We force the restore write to fail (via wp_insert_post_empty_content, which makes
	 * wp_update_post bail), then assert the guard returns a WP_Error.
	 */
	public function test_restore_failure_returns_error_not_false_success(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v3',
			)
		);
		$revs   = wp_get_post_revisions( $pid );
		$oldest = end( $revs );

		// Force the restore's wp_update_post() to bail. Returning true from this filter makes
		// wp_insert_post treat the write as empty and return a falsy 0, so the real production
		// path (wp_restore_post_revision → wp_update_post) yields a non-positive int.
		$fail_restore = static fn (): bool => true;
		add_filter( 'wp_insert_post_empty_content', $fail_restore );

		try {
			$out = aafm_exec_restore_revision(
				array(
					'post_id'     => $pid,
					'revision_id' => (int) $oldest->ID,
				)
			);
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $fail_restore );
		}

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertFalse( is_array( $out ) ); // never a false {restored:true}.

		// The filter is gone: a subsequent restore on the same post now succeeds, proving no leak.
		$out2 = aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $oldest->ID,
			)
		);
		$this->assertIsArray( $out2 );
		$this->assertTrue( $out2['restored'] );
	}

	public function test_revision_abilities_registered_and_discoverable(): void {
		$reg = aafm_get_abilities_registry();
		foreach ( array( 'aafm/list-revisions', 'aafm/get-revision', 'aafm/restore-revision' ) as $name ) {
			$this->assertArrayHasKey( $name, $reg );
			$this->assertNotNull( aafm_ability_list_permission( $name ) );
		}
	}

	public function test_restore_revision_enforces_parent_editable(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $owner,
				'post_content' => 'o1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'o2',
			)
		);
		$revs = wp_get_post_revisions( $pid );
		$rev  = array_shift( $revs );

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$this->assertFalse(
			aafm_perm_restore_revision(
				array(
					'post_id'     => $pid,
					'revision_id' => (int) $rev->ID,
				)
			)
		);
	}

	public function test_get_revision_returns_content_rendered_and_raw(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'first body',
				'post_excerpt' => 'first excerpt',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'second body **bold**',
				'post_excerpt' => 'second excerpt',
			)
		);
		$revs = wp_get_post_revisions( $pid ); // newest first.
		$rev  = array_shift( $revs );

		// Default format is rendered: the_content wraps paragraphs.
		$out = aafm_exec_get_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $rev->ID,
			)
		);
		$this->assertArrayHasKey( 'content', $out['revision'] );
		$this->assertArrayHasKey( 'excerpt', $out['revision'] );
		$this->assertStringContainsString( 'second body', $out['revision']['content'] );
		$this->assertSame( 'second excerpt', $out['revision']['excerpt'] );
		// Rendered output is wrapped in a paragraph tag by the_content.
		$this->assertStringContainsString( '<p>', $out['revision']['content'] );

		// Raw format returns the stored markup, unwrapped.
		$raw = aafm_exec_get_revision(
			array(
				'post_id'        => $pid,
				'revision_id'    => (int) $rev->ID,
				'content_format' => 'raw',
			)
		);
		$this->assertSame( 'second body **bold**', $raw['revision']['content'] );

		// Diff is null when not requested.
		$this->assertNull( $out['revision']['diff'] );
	}

	public function test_get_revision_diff_against_current_content(): void {
		require_once ABSPATH . 'wp-admin/includes/revision.php'; // wp_text_diff lives here.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'alpha line',
			)
		);
		// WordPress snapshots the NEW content on each update, so the oldest revision holds
		// the content from the first update. Two more updates leave the oldest revision
		// ('beta line') genuinely different from the post's current content ('gamma line').
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'beta line',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'gamma line', // current content differs from the oldest revision.
			)
		);
		$revs = wp_get_post_revisions( $pid );
		$rev  = end( $revs ); // oldest snapshot holds 'beta line'.

		// Without with_diff: diff is null.
		$out = aafm_exec_get_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $rev->ID,
			)
		);
		$this->assertNull( $out['revision']['diff'] );

		// With with_diff: a non-empty HTML diff table is returned.
		$with = aafm_exec_get_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $rev->ID,
				'with_diff'   => true,
			)
		);
		$this->assertIsString( $with['revision']['diff'] );
		$this->assertStringContainsString( '<table', $with['revision']['diff'] );
	}

	public function test_delete_revision_removes_one_revision(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'd1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'd2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'd3',
			)
		);
		$revs   = wp_get_post_revisions( $pid );
		$before = count( $revs );
		$target = array_shift( $revs ); // newest revision.

		$this->assertTrue(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $pid,
					'revision_id' => (int) $target->ID,
				)
			)
		);
		$out = aafm_exec_delete_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $target->ID,
			)
		);
		$this->assertSame(
			array(
				'deleted'     => true,
				'post_id'     => $pid,
				'revision_id' => (int) $target->ID,
			),
			$out
		);
		// One fewer revision, and the live post is untouched.
		$this->assertCount( $before - 1, wp_get_post_revisions( $pid ) );
		$this->assertSame( 'd3', get_post( $pid )->post_content );
	}

	public function test_delete_revision_registered_and_discoverable(): void {
		$reg = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/delete-revision', $reg );
		$this->assertNotNull( aafm_ability_list_permission( 'aafm/delete-revision' ) );
	}
}
