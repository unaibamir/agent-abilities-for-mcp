<?php
/**
 * Comment read abilities: PII redaction + moderation gating of unapproved bodies.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;

final class CommentsReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		oversio_install_activity_log();
		oversio_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter — the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array( 'oversio/get-comments', 'oversio/get-pending-comments' )
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	public function test_comment_reads_are_in_registry(): void {
		$registry = oversio_get_abilities_registry();
		foreach ( array( 'oversio/get-comments', 'oversio/get-pending-comments' ) as $name ) {
			$this->assertArrayHasKey( $name, $registry );
			$this->assertSame( 'reads', $registry[ $name ]['group'] );
			$this->assertSame( 'read', $registry[ $name ]['risk'] );
		}
	}

	public function test_get_comments_redacts_email_and_ip(): void {
		$this->acting_as( 'subscriber' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post,
				'comment_approved'     => '1',
				'comment_author'       => 'Jane',
				'comment_author_email' => 'jane@example.com',
				'comment_author_IP'    => '203.0.113.9',
				'comment_author_url'   => 'https://jane.example.com',
				'comment_agent'        => 'EvilBot/1.0',
			)
		);
		$out  = wp_get_ability( 'oversio/get-comments' )->execute( array( 'post_id' => $post ) );
		$json = wp_json_encode( $out );

		// The approved comment is returned...
		$this->assertCount( 1, $out['comments'] );
		$this->assertSame( 'Jane', $out['comments'][0]['author_name'] );

		// ...but never the email, IP, author URL, or user agent.
		$this->assertStringNotContainsString( 'jane@example.com', (string) $json );
		$this->assertStringNotContainsString( '203.0.113.9', (string) $json );
		$this->assertStringNotContainsString( 'jane.example.com', (string) $json );
		$this->assertStringNotContainsString( 'EvilBot/1.0', (string) $json );
		$this->assertArrayNotHasKey( 'author_email', $out['comments'][0] );
		$this->assertArrayNotHasKey( 'author_ip', $out['comments'][0] );
		$this->assertArrayNotHasKey( 'author_url', $out['comments'][0] );
		$this->assertArrayNotHasKey( 'agent', $out['comments'][0] );
	}

	/**
	 * The Phase 1 carried nit: a low-privilege caller must never receive an
	 * unapproved comment's body through get-comments.
	 */
	public function test_get_comments_never_returns_pending_or_spam_bodies(): void {
		$this->acting_as( 'subscriber' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_content'  => 'APPROVED_VISIBLE_BODY',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
				'comment_content'  => 'PENDING_SECRET_BODY',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_content'  => 'SPAM_SECRET_BODY',
			)
		);

		$out  = wp_get_ability( 'oversio/get-comments' )->execute( array( 'post_id' => $post ) );
		$json = wp_json_encode( $out );

		$this->assertCount( 1, $out['comments'] );
		$this->assertStringContainsString( 'APPROVED_VISIBLE_BODY', (string) $json );
		$this->assertStringNotContainsString( 'PENDING_SECRET_BODY', (string) $json );
		$this->assertStringNotContainsString( 'SPAM_SECRET_BODY', (string) $json );
	}

	/**
	 * The moderation queue is the only path to unapproved bodies, and it must be
	 * gated by moderate_comments. A subscriber is denied; a moderator is allowed
	 * AND actually sees the pending body.
	 */
	public function test_pending_comments_are_gated_by_moderate_comments(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
				'comment_content'  => 'PENDING_SECRET_BODY',
			)
		);

		// Subscriber lacks moderate_comments → denied, never reaches the body.
		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'oversio/get-pending-comments' )->check_permissions( array() )
		);

		// Editor has moderate_comments → allowed, and sees the pending body.
		$this->acting_as( 'editor' );
		$this->assertTrue(
			wp_get_ability( 'oversio/get-pending-comments' )->check_permissions( array() )
		);

		$out  = wp_get_ability( 'oversio/get-pending-comments' )->execute( array() );
		$json = wp_json_encode( $out );
		$this->assertGreaterThanOrEqual( 1, count( $out['comments'] ) );
		$this->assertStringContainsString( 'PENDING_SECRET_BODY', (string) $json );
	}

	/**
	 * Phase 3 review P1: get-comments leaked approved comment bodies on non-public
	 * posts. A read-only caller passing the id of a private/draft post must be
	 * DENIED before any of that post's approved comment bodies are returned —
	 * get-comments needs the same per-object visibility gate the post itself has.
	 */
	public function test_get_comments_denies_subscriber_on_non_public_post(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'private' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_content'  => 'SECRET_PRIVATE_COMMENT_BODY',
			)
		);

		// A subscriber cannot read the private post, so cannot read its comments.
		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'oversio/get-comments' )->check_permissions( array( 'post_id' => $post ) )
		);

		// The denial is audited by the registration wrapper, like every other deny.
		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/get-comments', $abilities );
	}

	/**
	 * A draft post is non-public too: the same gate must deny a low-privilege
	 * caller passing a draft id, and the secret body must never be returned even
	 * if execute were somehow reached.
	 */
	public function test_get_comments_denies_subscriber_on_draft_post(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_content'  => 'SECRET_DRAFT_COMMENT_BODY',
			)
		);

		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'oversio/get-comments' )->check_permissions( array( 'post_id' => $post ) )
		);
	}

	/**
	 * The gate must not over-correct: approved comments on a PUBLIC post stay
	 * readable for any logged-in caller, and an editor (who can read the private
	 * post) is allowed to read its comments.
	 */
	public function test_get_comments_still_allows_public_post_and_capable_caller(): void {
		$public = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $public,
				'comment_approved' => '1',
				'comment_content'  => 'PUBLIC_VISIBLE_BODY',
			)
		);
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $private,
				'comment_approved' => '1',
				'comment_content'  => 'PRIVATE_VISIBLE_TO_EDITOR',
			)
		);

		// Subscriber: allowed on the public post, and actually sees the body.
		$this->acting_as( 'subscriber' );
		$this->assertTrue(
			wp_get_ability( 'oversio/get-comments' )->check_permissions( array( 'post_id' => $public ) )
		);
		$out = wp_get_ability( 'oversio/get-comments' )->execute( array( 'post_id' => $public ) );
		$this->assertStringContainsString( 'PUBLIC_VISIBLE_BODY', (string) wp_json_encode( $out ) );

		// Editor can read the private post, so is allowed its comments too.
		$this->acting_as( 'editor' );
		$this->assertTrue(
			wp_get_ability( 'oversio/get-comments' )->check_permissions( array( 'post_id' => $private ) )
		);
	}
}
