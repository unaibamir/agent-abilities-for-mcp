<?php
/**
 * Comment CRUD abilities: single read, create (author-pinned, content-sanitized),
 * content-only update, and permanent (force) delete. Proves no email/IP ever leaks
 * and that author identity can never be spoofed through input.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Comment;
use WP_Error;

final class CommentsCrudTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/get-comment',
				'aafm/create-comment',
				'aafm/update-comment',
				'aafm/delete-comment',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
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

		$out = wp_get_ability( 'aafm/get-comment' )->execute( array( 'comment_id' => $comment ) );

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

	/**
	 * Calling wp_trash_post_comments() (via wp_trash_post()) sets EVERY comment on the trashed
	 * post's comment_approved to the literal string 'post-trashed', regardless of what the
	 * comment's own status was. Core's wp_get_comment_status() has no branch for that value and
	 * falls through to `return false`, so without Task 11's fix aafm_redact_comment() would emit
	 * `status: false` here - a bool where every other status is a string. Assert on the
	 * JSON-encoded form per SHARED-CONTEXT: only the encoded output distinguishes a JSON boolean
	 * from a JSON string.
	 */
	public function test_get_comment_on_a_trashed_post_reports_a_string_status_not_false(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_content'  => 'Still here after the post is trashed',
			)
		);

		wp_trash_post( $post );

		$out = wp_get_ability( 'aafm/get-comment' )->execute( array( 'comment_id' => $comment ) );

		$this->assertIsArray( $out );
		$shape = $out['comment'];
		$this->assertIsString(
			$shape['status'],
			'A trashed post\'s comment must report a string status, not the boolean false wp_get_comment_status() falls through to for the unrecognized "post-trashed" value.'
		);
		$this->assertSame( 'post-trashed', $shape['status'] );
		$this->assertSame(
			'"post-trashed"',
			wp_json_encode( $shape['status'] ),
			'The wire value must be the JSON string "post-trashed", not the JSON boolean false.'
		);
	}

	public function test_get_comment_missing_id_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/get-comment' )->execute( array( 'comment_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_comment_missing_id_is_denied_at_the_permission_layer(): void {
		// A comment id that resolves to nothing must default-deny at check_permissions(),
		// not merely error inside execute - so the ability can't probe for valid ids.
		$this->acting_as( 'editor' );
		$this->assertFalse(
			wp_get_ability( 'aafm/get-comment' )->check_permissions( array( 'comment_id' => 999999 ) )
		);
	}

	public function test_create_comment_requires_moderate_comments_and_audits_denial(): void {
		$post = self::factory()->post->create();

		$this->acting_as( 'author' ); // No moderate_comments.
		$this->assertFalse(
			wp_get_ability( 'aafm/create-comment' )->check_permissions(
				array(
					'post_id' => $post,
					'content' => 'Nice post',
				)
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/create-comment', $abilities );
	}

	public function test_create_comment_ties_author_to_the_agent_user_not_input(): void {
		$editor_id = $this->acting_as( 'editor' );
		$editor    = get_user_by( 'id', $editor_id );
		$post      = self::factory()->post->create();

		$out = wp_get_ability( 'aafm/create-comment' )->execute(
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

		$out = wp_get_ability( 'aafm/create-comment' )->execute(
			array(
				'post_id' => $post,
				'content' => 'Hold me for moderation',
			)
		);

		$this->assertSame( 'unapproved', $out['comment']['status'] );
		$this->assertSame( 'unapproved', wp_get_comment_status( $out['comment']['id'] ) );
	}

	/**
	 * The post-insert wp_set_comment_status( $id, 'hold' ) pin's return value must be checked, not
	 * just the comment's existence afterward: a 'wp_set_comment_status' hook (fired synchronously,
	 * AFTER that pin's own DB update succeeds but BEFORE it returns) can move the comment away
	 * from pending again, and checking existence alone would still report success, contradicting
	 * the pending-queue guarantee this ability exists to enforce.
	 */
	public function test_create_comment_errors_when_a_hook_undoes_the_pending_pin(): void {
		global $wpdb;

		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();

		// wp_insert_comment() applies no filter to comment_approved on this path (the ability
		// bypasses wp_allow_comment()/wp_new_comment() by design - see the docblock above); the
		// only way a real install ends up with an approved-on-insert comment here is a plugin
		// hook on the post-insert action forcing it directly, which this simulates with a raw
		// update - the shape of an insert filter approving the new comment out from under the
		// pending pin.
		$comment_id        = null;
		$approve_on_insert = static function ( $id ) use ( &$comment_id, $wpdb ) {
			$comment_id = $id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->comments, array( 'comment_approved' => '1' ), array( 'comment_ID' => $id ) );
			clean_comment_cache( $id );
		};
		add_action( 'wp_insert_comment', $approve_on_insert );

		// Now the pending-status pin's own UPDATE (approved '1' -> '0') is a real, non-no-op
		// write - fail that one query so its return value is false, using the same
		// fault-injection shape as elsewhere in this suite: suppress a single targeted query via
		// the 'query' filter, never the whole request.
		$fail_pin_update = static function ( $query ) use ( &$comment_id, $wpdb ) {
			if ( null !== $comment_id
				&& false !== strpos( $query, "UPDATE `{$wpdb->comments}` SET `comment_approved` = '0'" )
				&& false !== strpos( $query, "`comment_ID` = {$comment_id}" )
			) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $fail_pin_update );

		try {
			$out = wp_get_ability( 'aafm/create-comment' )->execute(
				array(
					'post_id' => $post,
					'content' => 'Sneak me past moderation',
				)
			);
		} finally {
			remove_action( 'wp_insert_comment', $approve_on_insert );
			remove_filter( 'query', $fail_pin_update );
		}

		$this->assertNotNull( $comment_id, 'the insert hook must have run for this test to prove anything.' );
		$this->assertSame( 'approved', wp_get_comment_status( $comment_id ), 'the pin update must have actually failed and left the comment approved for this test to prove anything.' );
		$this->assertInstanceOf( WP_Error::class, $out, 'a pending-pin write whose own confirming read never lands must not be reported as a confirmed pending create.' );
	}

	/**
	 * Codex round 9, R9-2: same gap as the moderate-comment path, same shape here - the
	 * pending-pin confirming read at line ~655 never busts the comment object cache first, so it
	 * can trust a stale cached object instead of the row it just tried (and failed) to pin. This
	 * plants a stale cached "pending" object over a database row that a hook already flipped to
	 * "approved", then fails the pin's own UPDATE the same no-flush way as the sibling test above
	 * so core's own cache-clearing never runs. A confirming read that trusts the stale cache sees
	 * pending == pending and hands back an "approved" comment as a confirmed pending create.
	 */
	public function test_create_comment_confirms_against_the_database_not_a_stale_cache_entry(): void {
		global $wpdb;

		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();

		$comment_id        = null;
		$approve_on_insert = static function ( $id ) use ( &$comment_id, $wpdb ) {
			$comment_id = $id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->comments, array( 'comment_approved' => '1' ), array( 'comment_ID' => $id ) );

			// A stale cached copy claiming "pending" over a row the hook just flipped to
			// "approved" - the shape a prior failed, non-flushing write leaves behind.
			$stale                   = clone get_comment( $id );
			$stale->comment_approved = '0';
			wp_cache_set( $id, $stale, 'comment' );
		};
		add_action( 'wp_insert_comment', $approve_on_insert );

		$fail_pin_update = static function ( $query ) use ( &$comment_id, $wpdb ) {
			if ( null !== $comment_id
				&& false !== strpos( $query, "UPDATE `{$wpdb->comments}` SET `comment_approved` = '0'" )
				&& false !== strpos( $query, "`comment_ID` = {$comment_id}" )
			) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $fail_pin_update );

		try {
			$out = wp_get_ability( 'aafm/create-comment' )->execute(
				array(
					'post_id' => $post,
					'content' => 'Sneak me past moderation via a stale cache',
				)
			);
		} finally {
			remove_action( 'wp_insert_comment', $approve_on_insert );
			remove_filter( 'query', $fail_pin_update );
		}

		$this->assertNotNull( $comment_id, 'the insert hook must have run for this test to prove anything.' );
		// Bypass the cache entirely for this check - a raw read is the only way to see the row's
		// real state independent of whatever the code under test trusted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$db_status = $wpdb->get_var( $wpdb->prepare( "SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ) );
		$this->assertSame( '1', $db_status, 'the pin update must have actually failed and left the comment approved for this test to prove anything.' );
		$this->assertInstanceOf( WP_Error::class, $out, 'a stale cached "pending" object must not let the confirming read report a create that is actually approved as a confirmed pending one.' );
	}

	public function test_create_comment_sanitizes_script_content(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();

		$out = wp_get_ability( 'aafm/create-comment' )->execute(
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
		$out = wp_get_ability( 'aafm/create-comment' )->execute(
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

		$out = wp_get_ability( 'aafm/create-comment' )->execute(
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

		$out = wp_get_ability( 'aafm/update-comment' )->execute(
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

		$out = wp_get_ability( 'aafm/update-comment' )->execute(
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

		$out = wp_get_ability( 'aafm/update-comment' )->execute(
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
			wp_get_ability( 'aafm/update-comment' )->check_permissions(
				array(
					'comment_id' => $comment,
					'content'    => 'I should not be able to edit this',
				)
			)
		);
	}

	public function test_update_comment_missing_id_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/update-comment' )->execute(
			array(
				'comment_id' => 999999,
				'content'    => 'nope',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	/**
	 * wp_update_comment() returns 0 (not false, not WP_Error) both when a filter vetoes the
	 * change AND when the request is a genuine no-op, so the executor cannot tell the two apart
	 * from that return value alone. A wp_update_comment_data filter that rewrites the content
	 * back to its original value is the veto case: the requested content never actually landed,
	 * so this must report an error, not success carrying the old content.
	 */
	public function test_update_comment_errors_when_a_filter_vetoes_the_content_change(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post,
				'comment_content' => 'original body',
			)
		);

		$preserve_original = static function ( array $data ): array {
			$data['comment_content'] = 'original body';
			return $data;
		};
		add_filter( 'wp_update_comment_data', $preserve_original );

		$out = wp_get_ability( 'aafm/update-comment' )->execute(
			array(
				'comment_id' => $comment,
				'content'    => 'requested body',
			)
		);

		remove_filter( 'wp_update_comment_data', $preserve_original );

		$this->assertInstanceOf( WP_Error::class, $out, 'A vetoed content change must not report success.' );
		$this->assertSame( 'original body', get_comment( $comment )->comment_content, 'The stored content must be untouched by the vetoed request.' );
	}

	/**
	 * A request whose content already matches the stored value is a genuine no-op:
	 * wp_update_comment() returns 0 for it the same way it does for a filter veto, but this must
	 * still report success, since the requested state and the stored state agree.
	 */
	public function test_update_comment_reports_success_for_a_genuine_no_op(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post,
				'comment_content' => 'same body',
			)
		);

		$out = wp_get_ability( 'aafm/update-comment' )->execute(
			array(
				'comment_id' => $comment,
				'content'    => 'same body',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'same body', $out['comment']['content'] );
	}

	public function test_delete_comment_permanently_removes_the_comment(): void {
		$this->acting_as( 'editor' );
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$out = wp_get_ability( 'aafm/delete-comment' )->execute( array( 'comment_id' => $comment ) );

		$this->assertSame(
			array(
				'deleted'    => true,
				'comment_id' => $comment,
			),
			$out
		);
		// Force delete bypasses trash - the row is gone, not recoverable.
		$this->assertNull( get_comment( $comment ) );
	}

	public function test_delete_comment_denied_for_non_editor(): void {
		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'aafm/delete-comment' )->check_permissions( array( 'comment_id' => $comment ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/delete-comment', $abilities );
		// And the comment still exists - the denied call never touched it.
		$this->assertInstanceOf( WP_Comment::class, get_comment( $comment ) );
	}

	public function test_delete_comment_missing_id_returns_error(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/delete-comment' )->execute( array( 'comment_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_writes_are_discoverable_by_a_moderator_and_hidden_from_a_low_cap_caller(): void {
		$this->acting_as( 'editor' ); // Has moderate_comments + edit_comment.
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/create-comment' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-comment' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-comment' ) );
		// get-comment falls through to its own object-independent read gate.
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-comment' ) );

		$this->acting_as( 'author' ); // No moderate_comments.
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/create-comment' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-comment' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/delete-comment' ) );
	}
}
