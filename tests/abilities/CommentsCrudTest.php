<?php
/**
 * Comment CRUD abilities: single read, create (author-pinned, content-sanitized),
 * content-only update, and permanent (force) delete. Proves no email/IP ever leaks
 * and that author identity can never be spoofed through input.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Comment;
use WP_Error;

final class CommentsCrudTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/get-comment',
				'oversio/create-comment',
				'oversio/update-comment',
				'oversio/delete-comment',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	public function test_get_comment_returns_redacted_shape_without_email_or_ip(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post,
				'comment_approved'     => '1',
				'comment_author'       => 'Jane',
				'comment_author_email' => 'jane@example.com',
				'comment_author_IP'    => '203.0.113.7',
				'comment_content'      => 'Hello world',
			)
		);

		$out = wp_get_ability( 'oversio/get-comment' )->execute( array( 'comment_id' => $comment ) );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'comment', $out );
		$shape = $out['comment'];
		$this->assertSame( $comment, $shape['id'] );
		$this->assertSame( 'Jane', $shape['author_name'] );
		$this->assertSame( 'Hello world', $shape['content'] );
		// The redactor must never surface email or IP.
		$this->assertArrayNotHasKey( 'author_email', $shape );
		$this->assertArrayNotHasKey( 'comment_author_email', $shape );
		$this->assertArrayNotHasKey( 'author_ip', $shape );
		$this->assertArrayNotHasKey( 'comment_author_IP', $shape );
		$this->assertStringNotContainsString( 'jane@example.com', wp_json_encode( $out ) );
		$this->assertStringNotContainsString( '203.0.113.7', wp_json_encode( $out ) );
	}

	public function test_get_comment_missing_id_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'oversio/get-comment' )->execute( array( 'comment_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_comment_missing_id_is_denied_at_the_permission_layer(): void {
		// A comment id that resolves to nothing must default-deny at check_permissions(),
		// not merely error inside execute — so the ability can't probe for valid ids.
		$this->acting_as( 'editor' );
		$this->assertFalse(
			wp_get_ability( 'oversio/get-comment' )->check_permissions( array( 'comment_id' => 999999 ) )
		);
	}

	public function test_create_comment_requires_moderate_comments_and_audits_denial(): void {
		$post = self::factory()->post->create();

		$this->acting_as( 'author' ); // No moderate_comments.
		$this->assertFalse(
			wp_get_ability( 'oversio/create-comment' )->check_permissions(
				array(
					'post_id' => $post,
					'content' => 'Nice post',
				)
			)
		);

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/create-comment', $abilities );
	}

	public function test_create_comment_ties_author_to_the_agent_user_not_input(): void {
		$editor_id = $this->acting_as( 'editor' );
		$editor    = get_user_by( 'id', $editor_id );
		$post      = self::factory()->post->create();

		$out = wp_get_ability( 'oversio/create-comment' )->execute(
			array(
				'post_id' => $post,
				'content' => 'Authored by the agent user',
			)
		);

		$this->assertIsArray( $out );
		$comment = get_comment( $out['comment']['id'] );
		$this->assertInstanceOf( WP_Comment::class, $comment );
		// Author identity comes from the current user, never from input.
		$this->assertSame( $editor_id, (int) $comment->user_id );
		$this->assertSame( $editor->display_name, $comment->comment_author );
		$this->assertSame( $editor->user_email, $comment->comment_author_email );
	}

	public function test_create_comment_defaults_to_pending_not_published(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();

		$out = wp_get_ability( 'oversio/create-comment' )->execute(
			array(
				'post_id' => $post,
				'content' => 'Hold me for moderation',
			)
		);

		$this->assertSame( 'unapproved', $out['comment']['status'] );
		$this->assertSame( 'unapproved', wp_get_comment_status( $out['comment']['id'] ) );
	}

	public function test_create_comment_sanitizes_script_content(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();

		$out = wp_get_ability( 'oversio/create-comment' )->execute(
			array(
				'post_id' => $post,
				'content' => 'Hello <script>alert(1)</script> world',
			)
		);

		$stored = get_comment( $out['comment']['id'] )->comment_content;
		$this->assertStringNotContainsString( '<script>', $stored );
		$this->assertStringContainsString( 'Hello', $stored );
	}

	public function test_create_comment_rejects_missing_post(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'oversio/create-comment' )->execute(
			array(
				'post_id' => 999999,
				'content' => 'No such post',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_create_comment_rejects_parent_on_a_different_post(): void {
		$this->acting_as( 'editor' );
		$post_a   = self::factory()->post->create();
		$post_b   = self::factory()->post->create();
		$parent_b = self::factory()->comment->create( array( 'comment_post_ID' => $post_b ) );

		$out = wp_get_ability( 'oversio/create-comment' )->execute(
			array(
				'post_id' => $post_a,
				'content' => 'mismatched parent',
				'parent'  => $parent_b,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_update_comment_edits_content_for_an_editor(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post,
				'comment_content' => 'old body',
			)
		);

		$out = wp_get_ability( 'oversio/update-comment' )->execute(
			array(
				'comment_id' => $comment,
				'content'    => 'new body',
			)
		);

		$this->assertSame( 'new body', $out['comment']['content'] );
		$this->assertSame( 'new body', get_comment( $comment )->comment_content );
	}

	public function test_update_comment_changes_only_the_content(): void {
		// The schema already closes out post id, author, and email. Assert it
		// behaviorally too, so a future edit to the executor can't silently widen
		// the write surface past the comment body.
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post,
				'comment_author'       => 'Original Author',
				'comment_author_email' => 'original@example.com',
				'comment_content'      => 'before',
			)
		);

		$out = wp_get_ability( 'oversio/update-comment' )->execute(
			array(
				'comment_id' => $comment,
				'content'    => 'after',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'after', $out['comment']['content'] );

		$stored = get_comment( $comment );
		$this->assertInstanceOf( WP_Comment::class, $stored );
		// Content changed; the post, author, and email are untouched.
		$this->assertSame( 'after', $stored->comment_content );
		$this->assertSame( $post, (int) $stored->comment_post_ID );
		$this->assertSame( 'Original Author', $stored->comment_author );
		$this->assertSame( 'original@example.com', $stored->comment_author_email );
	}

	public function test_update_comment_sanitizes_script_content(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$out = wp_get_ability( 'oversio/update-comment' )->execute(
			array(
				'comment_id' => $comment,
				'content'    => 'keep <script>alert(1)</script> me',
			)
		);

		$this->assertStringNotContainsString( '<script>', get_comment( $comment )->comment_content );
	}

	public function test_update_comment_denied_for_non_editor(): void {
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'oversio/update-comment' )->check_permissions(
				array(
					'comment_id' => $comment,
					'content'    => 'I should not be able to edit this',
				)
			)
		);
	}

	public function test_update_comment_missing_id_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'oversio/update-comment' )->execute(
			array(
				'comment_id' => 999999,
				'content'    => 'nope',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_delete_comment_permanently_removes_the_comment(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$out = wp_get_ability( 'oversio/delete-comment' )->execute( array( 'comment_id' => $comment ) );

		$this->assertSame(
			array(
				'deleted'    => true,
				'comment_id' => $comment,
			),
			$out
		);
		// Force delete bypasses trash — the row is gone, not recoverable.
		$this->assertNull( get_comment( $comment ) );
	}

	public function test_delete_comment_denied_for_non_editor(): void {
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'oversio/delete-comment' )->check_permissions( array( 'comment_id' => $comment ) )
		);

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/delete-comment', $abilities );
		// And the comment still exists — the denied call never touched it.
		$this->assertInstanceOf( WP_Comment::class, get_comment( $comment ) );
	}

	public function test_delete_comment_missing_id_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'oversio/delete-comment' )->execute( array( 'comment_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_writes_are_discoverable_by_a_moderator_and_hidden_from_a_low_cap_caller(): void {
		$this->acting_as( 'editor' ); // Has moderate_comments + edit_comment.
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/create-comment' ) );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/update-comment' ) );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/delete-comment' ) );
		// get-comment falls through to its own object-independent read gate.
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/get-comment' ) );

		$this->acting_as( 'author' ); // No moderate_comments.
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/create-comment' ) );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/update-comment' ) );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/delete-comment' ) );
	}
}
