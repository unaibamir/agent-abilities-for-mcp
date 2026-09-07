<?php
/**
 * Comment read abilities: PII redaction + moderation gating of unapproved bodies.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class CommentsReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter - the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/get-comments', 'aafm/get-pending-comments' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_comment_reads_are_in_registry(): void {
		$registry = aafm_get_abilities_registry();
		foreach ( array( 'aafm/get-comments', 'aafm/get-pending-comments' ) as $name ) {
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
		$out  = wp_get_ability( 'aafm/get-comments' )->execute( array( 'post_id' => $post ) );
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

		$out  = wp_get_ability( 'aafm/get-comments' )->execute( array( 'post_id' => $post ) );
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
			wp_get_ability( 'aafm/get-pending-comments' )->check_permissions( array() )
		);

		// Editor has moderate_comments → allowed, and sees the pending body.
		$this->acting_as( 'editor' );
		$this->assertTrue(
			wp_get_ability( 'aafm/get-pending-comments' )->check_permissions( array() )
		);

		$out  = wp_get_ability( 'aafm/get-pending-comments' )->execute( array() );
		$json = wp_json_encode( $out );
		$this->assertGreaterThanOrEqual( 1, count( $out['comments'] ) );
		$this->assertStringContainsString( 'PENDING_SECRET_BODY', (string) $json );
	}

	/**
	 * Phase 3 review P1: get-comments leaked approved comment bodies on non-public
	 * posts. A read-only caller passing the id of a private/draft post must be
	 * DENIED before any of that post's approved comment bodies are returned -
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
			wp_get_ability( 'aafm/get-comments' )->check_permissions( array( 'post_id' => $post ) )
		);

		// The denial is audited by the registration wrapper, like every other deny.
		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/get-comments', $abilities );
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
			wp_get_ability( 'aafm/get-comments' )->check_permissions( array( 'post_id' => $post ) )
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
			wp_get_ability( 'aafm/get-comments' )->check_permissions( array( 'post_id' => $public ) )
		);
		$out = wp_get_ability( 'aafm/get-comments' )->execute( array( 'post_id' => $public ) );
		$this->assertStringContainsString( 'PUBLIC_VISIBLE_BODY', (string) wp_json_encode( $out ) );

		// Editor can read the private post, so is allowed its comments too.
		$this->acting_as( 'editor' );
		$this->assertTrue(
			wp_get_ability( 'aafm/get-comments' )->check_permissions( array( 'post_id' => $private ) )
		);
	}

	/**
	 * Codex round 9, R9-4: the whole-site listing used to ask the database for one page,
	 * THEN drop comments on posts the caller can't read - so a readable comment could be
	 * pushed off page 1 entirely by unreadable ones consuming its slot. An older readable
	 * comment plus ten newer unreadable ones on a private post must still surface the
	 * readable one on page 1, and `total` must now be reported (it used to be omitted for
	 * this branch entirely).
	 */
	public function test_get_comments_sitewide_does_not_hide_a_readable_comment_behind_unreadable_ones(): void {
		$public_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $public_post,
				'comment_approved' => '1',
				'comment_content'  => 'READABLE_COMMENT',
				'comment_date'     => '2020-01-01 00:00:00',
				'comment_date_gmt' => '2020-01-01 00:00:00',
			)
		);

		$private_post = self::factory()->post->create( array( 'post_status' => 'private' ) );
		for ( $i = 1; $i <= 10; $i++ ) {
			self::factory()->comment->create(
				array(
					'comment_post_ID'  => $private_post,
					'comment_approved' => '1',
					'comment_content'  => 'HIDDEN_' . $i,
					'comment_date'     => sprintf( '2020-02-%02d 00:00:00', $i ),
					'comment_date_gmt' => sprintf( '2020-02-%02d 00:00:00', $i ),
				)
			);
		}

		$this->acting_as( 'subscriber' );
		$out      = wp_get_ability( 'aafm/get-comments' )->execute(
			array(
				'per_page' => 10,
				'page'     => 1,
			)
		);
		$contents = wp_list_pluck( $out['comments'], 'content' );

		$this->assertContains( 'READABLE_COMMENT', $contents );
		$this->assertSame( 1, $out['total'] );
		$this->assertFalse( $out['truncated'] );
	}

	/**
	 * Below the scan cap, `total` must be the exact count of comments the caller can
	 * actually see, not the raw approved count (which would include the private post's
	 * comments the subscriber cannot read).
	 */
	public function test_get_comments_sitewide_total_excludes_unreadable_comments(): void {
		$public_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create_many(
			2,
			array(
				'comment_post_ID'  => $public_post,
				'comment_approved' => '1',
			)
		);

		$private_post = self::factory()->post->create( array( 'post_status' => 'private' ) );
		self::factory()->comment->create_many(
			3,
			array(
				'comment_post_ID'  => $private_post,
				'comment_approved' => '1',
			)
		);

		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/get-comments' )->execute( array( 'per_page' => 50 ) );

		$this->assertSame( 2, $out['total'] );
		$this->assertCount( 2, $out['comments'] );
		$this->assertFalse( $out['truncated'] );
	}

	/**
	 * Lowering the (filterable) scan cap, rather than creating thousands of comments, is
	 * the practical way to exercise the bound in a test. Once the cap is hit, `truncated`
	 * must be true and `total` must be a floor (the scanned-and-visible count), never the
	 * unbounded real total.
	 */
	public function test_get_comments_sitewide_reports_truncated_once_the_scan_cap_is_hit(): void {
		add_filter( 'aafm_comments_sitewide_scan_cap', static fn() => 3 );

		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create_many(
			5,
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		$this->acting_as( 'subscriber' );
		// per_page is capped by the ability's own input schema at AAFM_LIST_PER_PAGE_MAX (50);
		// 100 would fail schema validation before aafm_exec_get_comments() ever runs.
		$out = wp_get_ability( 'aafm/get-comments' )->execute( array( 'per_page' => 50 ) );

		remove_all_filters( 'aafm_comments_sitewide_scan_cap' );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 3, $out['total'] );
	}
}
