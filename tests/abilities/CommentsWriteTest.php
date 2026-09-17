<?php
/**
 * Comment moderation write: capability gate, closed action allowlist, and
 * trash-not-delete semantics.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Comment;
use WP_Error;

final class CommentsWriteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// Register categories + enabled abilities inside their gated init actions,
		// simulated by pushing the action name onto $wp_current_filter - the idiom WP
		// core's own ability test trait uses. do_action() on the core hook trips the
		// WPCS non-prefixed-hookname sniff (Phase 1 carried issue).
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/moderate-comment' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_moderate_comment_is_in_registry_as_a_destructive_write(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/moderate-comment', $registry );
		$this->assertSame( 'writes', $registry['aafm/moderate-comment']['group'] );
		$this->assertSame( 'write', $registry['aafm/moderate-comment']['risk'] );

		// The annotation must advertise the destructive nature honestly.
		$args = aafm_args_moderate_comment();
		$this->assertFalse( $args['meta']['annotations']['readonly'] );
		$this->assertTrue( $args['meta']['annotations']['destructive'] );
	}

	/**
	 * The cap gate: a caller without moderate_comments is denied, and the denial is
	 * audited by the registration wrapper like every other deny.
	 */
	public function test_requires_moderate_comments_and_audits_denial(): void {
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'aafm/moderate-comment' )->check_permissions(
				array(
					'comment_id' => $comment,
					'action'     => 'approve',
				)
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/moderate-comment', $abilities );
	}

	public function test_editor_with_moderate_comments_is_allowed(): void {
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$this->acting_as( 'editor' );
		$this->assertTrue(
			wp_get_ability( 'aafm/moderate-comment' )->check_permissions(
				array(
					'comment_id' => $comment,
					'action'     => 'approve',
				)
			)
		);
	}

	public function test_approve_flips_a_pending_comment_to_approved(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
			)
		);

		$this->assertSame( 'unapproved', wp_get_comment_status( $comment ) );

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'approve',
			)
		);

		$this->assertSame( 'approved', wp_get_comment_status( $comment ) );
		$this->assertSame( 'approved', $out['status'] );
	}

	public function test_unapprove_flips_an_approved_comment_to_hold(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'unapprove',
			)
		);

		$this->assertSame( 'unapproved', wp_get_comment_status( $comment ) );
	}

	/**
	 * An action outside the closed allowlist must be rejected with a WP_Error, and
	 * the comment must be left untouched. The closed input schema rejects it before
	 * execute; this also covers the in-callback default branch.
	 */
	public function test_rejects_unknown_action(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'delete_forever',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		// The comment was never deleted or altered.
		$this->assertInstanceOf( WP_Comment::class, get_comment( $comment ) );
		$this->assertSame( 'approved', wp_get_comment_status( $comment ) );
	}

	/**
	 * Trash must use recoverable trash semantics, never a permanent delete. After
	 * trashing, the comment still exists (status 'trash') and is recoverable.
	 */
	public function test_trash_uses_recoverable_trash_not_permanent_delete(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'trash',
			)
		);

		$this->assertSame( 'trash', $out['status'] );
		// The row still exists (trashed, not destroyed) and can be restored.
		$this->assertInstanceOf( WP_Comment::class, get_comment( $comment ) );
		$this->assertSame( 'trash', wp_get_comment_status( $comment ) );
		$this->assertTrue( wp_untrash_comment( $comment ) );
	}

	/**
	 * Spam is part of the destructive allowlist and flips the comment to the spam
	 * status (also recoverable, never a hard delete).
	 */
	public function test_spam_marks_the_comment_as_spam(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'spam',
			)
		);

		$this->assertSame( 'spam', $out['status'] );
		$this->assertInstanceOf( WP_Comment::class, get_comment( $comment ) );
	}

	/**
	 * Approving an already-approved comment must still report success. Core's
	 * wp_set_comment_status() returns false when its $wpdb->update() call reports 0 affected
	 * rows, which MySQL does for a same-value UPDATE - indistinguishable at that layer from a
	 * genuine DB failure. An agent asking for an idempotent end state must not be told the
	 * request failed while the comment already sits exactly where it asked.
	 */
	public function test_approve_on_an_already_approved_comment_still_reports_success(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'approve',
			)
		);

		$this->assertSame( 'approved', $out['status'] );
		$this->assertSame( 'approved', wp_get_comment_status( $comment ) );
	}

	/**
	 * Same no-op contract as approve, see
	 * test_approve_on_an_already_approved_comment_still_reports_success().
	 */
	public function test_unapprove_on_an_already_held_comment_still_reports_success(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'unapprove',
			)
		);

		$this->assertSame( 'unapproved', $out['status'] );
		$this->assertSame( 'unapproved', wp_get_comment_status( $comment ) );
	}

	/**
	 * Same no-op contract: wp_spam_comment() delegates to wp_set_comment_status() behind a
	 * truthy check, so it inherits the same false-on-no-op shape.
	 */
	public function test_spam_on_an_already_spam_comment_still_reports_success(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'spam',
			)
		);

		$this->assertSame( 'spam', $out['status'] );
		$this->assertSame( 'spam', wp_get_comment_status( $comment ) );
	}

	/**
	 * Same no-op contract: wp_trash_comment() delegates to wp_set_comment_status() behind the
	 * same truthy check as spam.
	 */
	public function test_trash_on_an_already_trashed_comment_still_reports_success(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => 'trash',
			)
		);

		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => $comment,
				'action'     => 'trash',
			)
		);

		$this->assertSame( 'trash', $out['status'] );
		$this->assertSame( 'trash', wp_get_comment_status( $comment ) );
	}

	/**
	 * The route: wp_set_comment_status() (wp-includes/comment.php) fires its
	 * 'wp_set_comment_status' action AFTER the DB update has already succeeded, and returns `true`
	 * unconditionally once that update ran - what any listener on that action does afterward,
	 * including deleting the row, cannot change the return value. A hook that hard-deletes the
	 * comment there (an anti-spam or moderation plugin reacting to the status change, say) leaves
	 * $ok === true in aafm_exec_moderate_comment() while the comment is already gone by the time
	 * the status is read back at the end.
	 *
	 * This test used to pin the opposite outcome, deliberately: a 1.6.1-era review fix kept the
	 * call a "success" and reported the string 'unknown' (aafm_comment_status_string()'s
	 * fallback), because the immediate defect then was wp_get_comment_status() returning boolean
	 * false against an output schema declaring type:string. 1.6.2 reverses that judgment: a
	 * status of 'unknown' satisfies the schema but is unusable - the caller cannot tell whether
	 * its moderation took effect - so the vanish-mid-write case now returns aafm_generic_error()
	 * like every other miss path in this file, and the schema question is moot because no shape
	 * is emitted at all.
	 */
	public function test_moderate_comment_errors_when_the_comment_vanishes_mid_write(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
			)
		);

		$force_delete = static function ( $comment_id ) {
			wp_delete_comment( $comment_id, true );
		};
		add_action( 'wp_set_comment_status', $force_delete );

		try {
			$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
				array(
					'comment_id' => $comment,
					'action'     => 'approve',
				)
			);
		} finally {
			remove_action( 'wp_set_comment_status', $force_delete );
		}

		$this->assertNull( get_comment( $comment ), 'The hook must have actually deleted the comment for this test to prove anything.' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
	}

	/**
	 * A missing comment id default-denies rather than acting on nothing.
	 */
	public function test_missing_comment_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => 999999,
				'action'     => 'approve',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	/**
	 * Wp_set_comment_status() fires its 'wp_set_comment_status' action AFTER its own DB update has  * already succeeded, and returns `true` unconditionally once that update ran. Branching on
	 * that return value alone would miss a hook on the action that moves the comment again (here,
	 * straight back to its pre-moderation status - the same shape as a second plugin's moderation
	 * rule overriding this one, or a spam filter reverting an unwarranted approval): the truthy
	 * return value would say success while the comment sat at a status the caller never asked for.
	 * Same route as the sibling vanish-mid-write test above, but here the row survives with the
	 * WRONG status instead of disappearing, a shape a bare success/mismatch check on the return
	 * value alone could never catch.
	 */
	public function test_moderate_comment_errors_when_a_hook_reverts_the_status_after_a_successful_update(): void {
		global $wpdb;

		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
			)
		);

		$revert = static function ( $comment_id ) use ( $wpdb ) {
			// A raw update, not wp_set_comment_status(), so the hook itself does not
			// recurse back into the action it is attached to.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->comments, array( 'comment_approved' => '0' ), array( 'comment_ID' => $comment_id ) );
			clean_comment_cache( $comment_id );
		};
		add_action( 'wp_set_comment_status', $revert );

		try {
			$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
				array(
					'comment_id' => $comment,
					'action'     => 'approve',
				)
			);
		} finally {
			remove_action( 'wp_set_comment_status', $revert );
		}

		$this->assertSame( 'unapproved', wp_get_comment_status( $comment ), 'The hook must have actually reverted the status for this test to prove anything.' );
		$this->assertInstanceOf( WP_Error::class, $out, 'A status the hook reverted away from what was requested must not be reported as a successful approval.' );
	}

	/**
	 * Codex round 9, R9-2: the confirming get_comment() call R8-4 added never busts the comment
	 * object cache first, so it can serve a stale cached object instead of the real row. This
	 * plants that exact divergence directly - a cached "pending" object sitting over a database
	 * row that genuinely still says "approved" - the state a prior failed, non-flushing write
	 * would plausibly leave behind, then fails the moderation call's own UPDATE the same no-flush
	 * way so it never reaches core's own cache-clearing. A read that trusts the stale cache
	 * confirms the requested unapprove against pending == pending and reports success while the
	 * database never moved off approved.
	 */
	public function test_moderate_comment_confirms_against_the_database_not_a_stale_cache_entry(): void {
		global $wpdb;

		$this->acting_as( 'editor' );
		$post       = self::factory()->post->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		// A stale cached copy claiming "pending" while the row underneath still says "approved" -
		// the shape a plugin's earlier failed, non-flushing write leaves behind on a persistent
		// object cache.
		$stale                   = clone get_comment( $comment_id );
		$stale->comment_approved = '0';
		wp_cache_set( $comment_id, $stale, 'comment' );

		// wp_set_comment_status()'s own UPDATE (approved '1' -> '0') fails via the no-flush
		// query-filter path, so it returns before ever reaching its own clean_comment_cache()
		// call - the stale entry above survives untouched into the confirming read.
		$fail_pin_update = static function ( $query ) use ( $comment_id, $wpdb ) {
			if ( false !== strpos( $query, "UPDATE `{$wpdb->comments}` SET `comment_approved` = '0'" )
				&& false !== strpos( $query, "`comment_ID` = {$comment_id}" )
			) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $fail_pin_update );

		try {
			$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
				array(
					'comment_id' => $comment_id,
					'action'     => 'unapprove',
				)
			);
		} finally {
			remove_filter( 'query', $fail_pin_update );
		}

		// Bypass the cache entirely for this check - a raw read is the only way to see the row's
		// real state independent of whatever the code under test trusted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$db_status = $wpdb->get_var( $wpdb->prepare( "SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ) );
		$this->assertSame( '1', $db_status, 'the pin update must have actually failed and left the database approved for this test to prove anything.' );
		$this->assertInstanceOf( WP_Error::class, $out, 'a stale cached "pending" object must not let the confirming read report success while the database still holds "approved".' );
	}
}
