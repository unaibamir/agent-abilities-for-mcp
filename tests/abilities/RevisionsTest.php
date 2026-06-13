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
}
