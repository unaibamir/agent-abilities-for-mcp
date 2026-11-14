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
	 * A restore rewrites title/content/excerpt exactly like aafm/update-post, so a builder-owned
	 * post must refuse it the same way: restoring a pre-builder
	 * revision over a post the builder now owns would report success while the builder's own
	 * meta (which actually drives the rendered output) stays untouched.
	 */
	public function test_restore_revision_refuses_a_builder_owned_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
		$this->assertSame( 'v2', $oldest->post_content, 'the restore target differs from the current content' );
		update_post_meta( $pid, '_elementor_data', '[]' );

		$out = aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $oldest->ID,
			)
		);
		$this->assertSame( 'v3', get_post( $pid )->post_content, 'The builder-owned post must be left untouched.' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_page_builder_owned', $out->get_error_code() );
	}

	/**
	 * A restore whose underlying write fails must surface the generic error, never a false
	 * {restored:true}. wp_restore_post_revision() returns the wp_update_post() result, which is
	 * falsy (0/false/null) on failure and - per its documented int|false|null contract being
	 * incomplete - may be a WP_Error. A WP_Error is a truthy object, so the old falsy-only guard
	 * would have reported success for a failed write and the audit layer would have logged it as
	 * one. We force the restore write to fail (via wp_insert_post_empty_content, which makes
	 * wp_update_post bail), then assert the guard returns a WP_Error.
	 */
	/**
	 * The description claims "the current state is first saved as a fresh revision, so
	 * the restore is reversible". When revisions are disabled for the post (WP_POST_REVISIONS
	 * false, or the wp_revisions_to_keep filter returning 0), core takes NO fresh snapshot
	 * and the pre-restore state is lost forever. The ability must refuse rather than perform
	 * an irreversible restore under a reversibility promise.
	 */
	public function test_restore_refuses_when_revisions_are_disabled_for_the_post(): void {
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
		$this->assertInstanceOf( \WP_Post::class, $oldest, 'a genuine revision must exist before revisions are disabled.' );
		$this->assertSame( 'v2', $oldest->post_content, 'the restore target differs from the current content' );

		// Disable revisions AFTER the history exists - the WP_POST_REVISIONS=false site shape.
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$out = aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $oldest->ID,
			)
		);
		$this->assertSame( 'v3', get_post( $pid )->post_content, 'the post must be left untouched by the refusal.' );

		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_restore_irreversible', $out->get_error_code(), 'an irreversible restore must be refused with the named error.' );
	}

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
		// wp_text_diff( $raw_revision, $current_content ) renders the revision as the
		// removed (left) side and the current content as the added (right) side. Assert
		// both tokens land in the diff so an argument swap or wrong-direction regression
		// would fail here, not slip past the bare '<table' check.
		$this->assertStringContainsString( 'beta', $with['revision']['diff'], 'Removed token (old revision content) should appear in the diff.' );
		$this->assertStringContainsString( 'gamma', $with['revision']['diff'], 'Added token (current content) should appear in the diff.' );
	}

	/**
	 * A revision of a password-protected parent post must withhold the body and excerpt -
	 * rendered AND content_format=raw - and must not leak the body through a diff. A normal
	 * post still returns its body (the Wave-1 enrichment).
	 */
	public function test_get_revision_withholds_body_for_password_protected_parent(): void {
		require_once ABSPATH . 'wp-admin/includes/revision.php';
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'   => $author,
				'post_content'  => 'secret v1 body',
				'post_password' => 'hunter2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'secret v2 body',
				'post_excerpt' => 'secret excerpt',
			)
		);
		$revs = wp_get_post_revisions( $pid ); // newest first.
		$rev  = array_shift( $revs );

		// Rendered: body and excerpt must be withheld (empty), no secret markup.
		$rendered = aafm_exec_get_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $rev->ID,
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $rendered );
		$this->assertSame( '', $rendered['revision']['content'], 'Rendered body must be withheld for a password-protected parent.' );
		$this->assertSame( '', $rendered['revision']['excerpt'], 'Excerpt must be withheld for a password-protected parent.' );

		// Raw: the stored markup must also be withheld.
		$raw = aafm_exec_get_revision(
			array(
				'post_id'        => $pid,
				'revision_id'    => (int) $rev->ID,
				'content_format' => 'raw',
			)
		);
		$this->assertSame( '', $raw['revision']['content'], 'Raw stored markup must be withheld for a password-protected parent.' );

		// Diff must not leak the protected body either.
		$with = aafm_exec_get_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => (int) $rev->ID,
				'with_diff'   => true,
			)
		);
		$this->assertNull( $with['revision']['diff'], 'Diff must be withheld for a password-protected parent.' );
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

	public function test_delete_revision_denied_for_non_parent_editor(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $owner,
				'post_content' => 'p1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'p2',
			)
		);
		$revs = wp_get_post_revisions( $pid );
		$rev  = array_shift( $revs );

		// A different author cannot edit the parent, so cannot delete its revisions.
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$this->assertFalse(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $pid,
					'revision_id' => (int) $rev->ID,
				)
			)
		);
	}

	/**
	 * 1.7.2 bug #1: aafm_perm_delete_revision() only calls aafm_revision_parent_editable() (an
	 * edit_post gate), never delete_post - so a role that can edit but genuinely cannot delete the
	 * parent post can still permanently wipe its revision history. WP core's own REST revisions
	 * delete controller requires delete_post here; this ability must match that gate.
	 *
	 * No stock WP role carries edit_posts without delete_posts (contributor gets delete_posts too,
	 * added by populate_roles_210()), so a purpose-built role pins the exact gap this bug is about:
	 * edit_posts (so map_meta_cap grants edit_post on the author's own unpublished post) but no
	 * delete_posts/delete_published_posts/delete_others_posts at all, so delete_post can never
	 * resolve true for any post under this role.
	 *
	 * RED against the current code: aafm_perm_delete_revision() returns true here because it never
	 * checks delete_post.
	 */
	public function test_delete_revision_denied_for_a_role_that_can_edit_but_not_delete_the_parent(): void {
		add_role(
			'aafm_test_edit_not_delete',
			'AAFM Test Edit Not Delete',
			array(
				'read'       => true,
				'edit_posts' => true,
			)
		);
		$user = self::factory()->user->create( array( 'role' => 'aafm_test_edit_not_delete' ) );
		wp_set_current_user( $user );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $user,
				'post_status'  => 'draft',
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'v2',
			)
		);
		$revs = wp_get_post_revisions( $pid );
		$rev  = array_shift( $revs );

		// Preconditions establish the exact gap this test targets.
		$this->assertTrue(
			current_user_can( 'edit_post', $pid ),
			'precondition: this role can edit_post its own draft.'
		);
		$this->assertFalse(
			current_user_can( 'delete_post', $pid ),
			'precondition: this role carries no delete_posts capability at all.'
		);

		$this->assertFalse(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $pid,
					'revision_id' => (int) $rev->ID,
				)
			),
			'delete-revision must require delete_post on the parent, not just edit_post - an edit-but-not-delete role must not be able to wipe revision history.'
		);
	}

	public function test_delete_revision_rejects_cross_parent_revision_id(): void {
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
		$revs  = wp_get_post_revisions( $a );
		$rev_a = array_shift( $revs );
		$b     = self::factory()->post->create( array( 'post_author' => $author ) );

		// $b is editable, but $rev_a is a revision of $a, not $b - must be rejected by perm AND exec.
		$this->assertFalse(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $b,
					'revision_id' => (int) $rev_a->ID,
				)
			)
		);
		$out = aafm_exec_delete_revision(
			array(
				'post_id'     => $b,
				'revision_id' => (int) $rev_a->ID,
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
		// The revision of $a was NOT deleted by the cross-parent attempt.
		$this->assertNotEmpty( wp_get_post_revisions( $a ) );
	}

	public function test_list_revisions_stays_metadata_only(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'L1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $pid,
				'post_content' => 'L2 body content',
			)
		);
		$out = aafm_exec_list_revisions( array( 'post_id' => $pid ) );
		$this->assertNotEmpty( $out['revisions'] );
		foreach ( $out['revisions'] as $row ) {
			$this->assertArrayHasKey( 'id', $row );
			$this->assertArrayNotHasKey( 'content', $row );
			$this->assertArrayNotHasKey( 'excerpt', $row );
			$this->assertArrayNotHasKey( 'diff', $row );
		}
	}

	/**
	 * Seed a post at v3 with an earlier v2 revision, as the author.
	 *
	 * @return array{0:int,1:\WP_Post}
	 */
	private function seed_restorable_post(): array {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$pid = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'v1',
			)
		);
		foreach ( array( 'v2', 'v3' ) as $v ) {
			wp_update_post(
				array(
					'ID'           => $pid,
					'post_content' => $v,
				)
			);
		}
		$revs = wp_get_post_revisions( $pid );
		return array( $pid, end( $revs ) );
	}

	public function test_restore_reports_unconfirmed_when_a_filter_changes_the_content(): void {
		list( $pid, $oldest ) = $this->seed_restorable_post();
		$rewrite              = static function ( array $data ): array {
			$data['post_content'] = 'rewritten by a filter';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $rewrite );
		$out = aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => $oldest->ID,
			)
		);
		remove_filter( 'wp_insert_post_data', $rewrite );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_restore_unconfirmed', $out->get_error_code() );
	}

	public function test_a_clean_restore_still_reports_restored(): void {
		list( $pid, $oldest ) = $this->seed_restorable_post();
		$out                  = aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => $oldest->ID,
			)
		);
		$this->assertIsArray( $out );
		$this->assertTrue( $out['restored'] );
		$this->assertSame( $oldest->post_content, get_post( $pid )->post_content );
	}

	public function test_revision_tools_reach_a_layout_section(): void {
		register_post_type(
			'fusion_tb_section',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'revisions' ),
			)
		);
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create( array( 'post_type' => 'fusion_tb_section' ) );
		$this->assertTrue( aafm_perm_list_revisions( array( 'post_id' => $id ) ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_list_revisions( array( 'post_id' => $id ) ) );
	}

	private const AV_A  = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]Alpha.[/fusion_text][fusion_text]Extra.[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
	private const AV_B  = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]Bravo.[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
	private const WPB_A = '[vc_row][vc_column][vc_column_text]<p>Alpha.</p>[/vc_column_text][/vc_column][/vc_row]';
	private const WPB_B = '[vc_row][vc_column][vc_column_text]<p>Bravo.</p>[/vc_column_text][/vc_column][/vc_row]';

	/**
	 * Rebuilds the seam recorded, as (post id, kind) pairs.
	 *
	 * @var list<array{0:int,1:string}>
	 */
	private $rebuilds = array();

	/**
	 * A post whose oldest revision holds $old while the post holds $current, written as an administrator.
	 *
	 * @param string $old     Content the oldest revision holds.
	 * @param string $current Content the post holds.
	 * @param string $type    Post type.
	 * @param int    $author  Post author, or 0 for the acting administrator.
	 * @return array{0:int,1:int} Post id and the id of the revision holding $old.
	 */
	private function builder_post( string $old, string $current, string $type = 'page', int $author = 0 ): array {
		$this->acting_as( 'administrator' );
		$args = array(
			'post_type'    => $type,
			'post_content' => 'seed',
		);
		if ( $author ) {
			$args['post_author'] = $author;
		}
		$pid = self::factory()->post->create( $args );
		foreach ( array( $old, $current ) as $content ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $pid,
						'post_content' => $content,
					)
				)
			);
		}
		$revs = wp_get_post_revisions( $pid );
		$rev  = end( $revs );
		$this->assertSame( $old, $rev->post_content );
		$this->assertSame( $current, get_post_field( 'post_content', $pid, 'raw' ) );
		return array( $pid, (int) $rev->ID );
	}

	private function restore( int $pid, int $rid ) {
		return aafm_exec_restore_revision(
			array(
				'post_id'     => $pid,
				'revision_id' => $rid,
			)
		);
	}

	private function record_rebuilds(): void {
		$this->rebuilds = array();
		add_filter( 'aafm_builder_vendor', array( $this, 'rebuild_seam' ), 10, 2 );
	}

	/**
	 * Seam filter recording each CSS rebuild.
	 *
	 * @param callable|null $resolved Resolved entry.
	 * @param string        $entry    Entry.
	 * @return callable|null
	 */
	public function rebuild_seam( $resolved, string $entry ) {
		if ( 'wpbakery_css_rebuild' !== $entry ) {
			return $resolved;
		}
		return function ( int $id, string $type ): void {
			$this->rebuilds[] = array( $id, $type );
		};
	}

	private function assert_restore_refused( $out, string $code, int $pid, string $current ): void {
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( $code, $out->get_error_code() );
		clean_post_cache( $pid );
		$this->assertSame( $current, get_post_field( 'post_content', $pid, 'raw' ) );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_builder_vendor', array( $this, 'rebuild_seam' ), 10 );
		remove_all_filters( 'aafm_integration_active_avada' );
		remove_all_filters( 'aafm_integration_active_wpbakery' );
		parent::tear_down();
	}

	public function test_restore_is_allowed_on_an_avada_page_while_avada_is_active(): void {
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		list( $pid, $rid ) = $this->builder_post( self::AV_A, self::AV_B );
		update_post_meta( $pid, 'fusion_builder_status', 'active' );
		$this->assertSame( 'avada', aafm_post_has_foreign_builder_ownership( $pid ) );

		$out = $this->restore( $pid, $rid );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['restored'] );
		$this->assertSame( self::AV_A, get_post_field( 'post_content', $pid, 'raw' ) );
	}

	public function test_restore_is_allowed_on_a_wpbakery_page_while_wpbakery_is_active(): void {
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		$this->record_rebuilds();
		list( $pid, $rid ) = $this->builder_post( self::WPB_A, self::WPB_B );
		$this->assertSame( 'wpbakery', aafm_post_has_foreign_builder_ownership( $pid ) );

		$out = $this->restore( $pid, $rid );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['restored'] );
		$this->assertSame( self::WPB_A, get_post_field( 'post_content', $pid, 'raw' ) );
	}

	public function test_restore_stays_refused_on_those_pages_while_the_integration_is_inactive(): void {
		add_filter( 'aafm_integration_active_avada', '__return_false' );
		add_filter( 'aafm_integration_active_wpbakery', '__return_false' );
		list( $pid, $rid ) = $this->builder_post( self::AV_A, self::AV_B );
		update_post_meta( $pid, 'fusion_builder_status', 'active' );
		$this->assert_restore_refused( $this->restore( $pid, $rid ), 'aafm_page_builder_owned', $pid, self::AV_B );

		list( $pid, $rid ) = $this->builder_post( self::WPB_A, self::WPB_B );
		$this->assert_restore_refused( $this->restore( $pid, $rid ), 'aafm_page_builder_owned', $pid, self::WPB_B );
	}

	public function test_restore_stays_refused_on_an_elementor_page_and_on_unknown_ownership(): void {
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		list( $pid, $rid ) = $this->builder_post( self::AV_A, self::AV_B );
		update_post_meta( $pid, '_elementor_data', '[]' );
		$this->assertSame( 'elementor', aafm_post_has_foreign_builder_ownership( $pid ) );
		$this->assert_restore_refused( $this->restore( $pid, $rid ), 'aafm_page_builder_owned', $pid, self::AV_B );

		update_post_meta( $pid, 'fusion_builder_status', 'active' );
		$this->assertSame( 'unknown', aafm_post_has_foreign_builder_ownership( $pid ) );
		$this->assert_restore_refused( $this->restore( $pid, $rid ), 'aafm_page_builder_owned', $pid, self::AV_B );
	}

	public function test_a_section_restore_is_refused_when_the_replay_would_strip_an_element(): void {
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		register_post_type(
			'fusion_tb_section',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'revisions' ),
			)
		);
		list( $pid, $rid ) = $this->builder_post( self::AV_A, self::AV_B, 'fusion_tb_section' );
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $pid ) );
		$strip = static fn( $value ) => str_replace( '[fusion_text]Extra.[/fusion_text]', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$out = $this->restore( $pid, $rid );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}

		$this->assert_restore_refused( $out, 'aafm_avada_save_would_change_layout', $pid, self::AV_B );
	}

	public function test_a_wpbakery_page_without_a_root_row_gets_the_checks_and_the_rebuild_when_its_revision_has_one(): void {
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		$this->record_rebuilds();
		list( $pid, $rid ) = $this->builder_post( self::WPB_A, '<p>Plain.</p>' );
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $pid ) );

		$out = $this->restore( $pid, $rid );

		$this->assertIsArray( $out );
		$this->assertSame( self::WPB_A, get_post_field( 'post_content', $pid, 'raw' ) );
		$this->assertSame( array( array( $pid, 'default' ), array( $pid, 'custom' ) ), $this->rebuilds );
	}

	public function test_restore_refuses_a_page_its_own_save_would_change(): void {
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		$this->record_rebuilds();
		$current           = self::WPB_B . '<iframe></iframe>';
		list( $pid, $rid ) = $this->builder_post( self::WPB_A, $current );
		$strip             = static fn( $value ) => str_replace( '<iframe></iframe>', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$out = $this->restore( $pid, $rid );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}

		$this->assert_restore_refused( $out, 'aafm_wpbakery_save_would_change_page', $pid, $current );
		$this->assertSame( array(), $this->rebuilds );
	}

	public function test_restore_refuses_a_revision_whose_embed_the_users_save_would_strip(): void {
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		$author            = self::factory()->user->create( array( 'role' => 'author' ) );
		$old               = str_replace( 'Alpha.', 'Alpha.<iframe src="https://example.com/embed"></iframe>', self::AV_A );
		list( $pid, $rid ) = $this->builder_post( $old, self::AV_B, 'post', $author );
		update_post_meta( $pid, 'fusion_builder_status', 'active' );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertTrue(
			aafm_perm_restore_revision(
				array(
					'post_id'     => $pid,
					'revision_id' => $rid,
				)
			)
		);

		$this->assert_restore_refused( $this->restore( $pid, $rid ), 'aafm_avada_save_would_change_page', $pid, self::AV_B );
	}

	public function test_restore_rebuilds_wpbakery_css_once(): void {
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		$this->record_rebuilds();
		list( $pid, $rid ) = $this->builder_post( self::WPB_A, self::WPB_B );

		$this->assertIsArray( $this->restore( $pid, $rid ) );
		$this->assertSame( array( array( $pid, 'default' ), array( $pid, 'custom' ) ), $this->rebuilds );
	}

	public function test_restore_reports_stale_css_when_the_rebuild_fails(): void {
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		$absent            = static fn( $resolved, string $entry ) => 'wpbakery_css_rebuild' === $entry ? null : $resolved;
		add_filter( 'aafm_builder_vendor', $absent, 10, 2 );
		list( $pid, $rid ) = $this->builder_post( self::WPB_A, self::WPB_B );
		try {
			$out = $this->restore( $pid, $rid );
		} finally {
			remove_filter( 'aafm_builder_vendor', $absent, 10 );
		}

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_wpbakery_css_stale', $out->get_error_code() );
		$this->assertSame( self::WPB_A, get_post_field( 'post_content', $pid, 'raw' ) );
	}

	public function test_restore_description_lists_the_refused_builders_and_names_the_two_exceptions(): void {
		$text = aafm_get_abilities_registry()['aafm/restore-revision']['description'];

		$this->assertStringContainsString( aafm_page_builder_names(), $text );
		$this->assertStringContainsString( 'except an Avada or WPBakery page while that builder is active', $text );
		$this->assertStringNotContainsString( ' - ', $text );
	}
}
