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
		// The audited registration wrapper logs every permission check and execute to
		// the custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

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
}
