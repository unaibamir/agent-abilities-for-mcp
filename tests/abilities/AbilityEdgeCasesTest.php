<?php
/**
 * Edge-case and execute-level error-branch coverage for the ability catalog.
 *
 * The existing suite leans on check_permissions() for the deny paths; this file
 * drives the EXECUTE callbacks down their failure branches (missing object,
 * bad status, oversize/mismatched upload, whole-site comment listing, the role
 * and search filters, and pagination clamping at the query level) that the
 * permission gate does not exercise.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_Post;

final class AbilityEdgeCasesTest extends TestCase {

	// 1x1 transparent PNG.
	private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Absolute paths written by upload tests, cleaned up in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $written_files = array();

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/get-posts',
				'aafm/get-post',
				'aafm/get-page',
				'aafm/get-comments',
				'aafm/get-users',
				'aafm/get-media',
				'aafm/update-post',
				'aafm/set-featured-image',
				'aafm/upload-media',
				'aafm/moderate-comment',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		parent::tear_down();
	}

	/**
	 * Track an attachment's file for cleanup.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	private function track_attachment_files( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
	}

	public function test_get_posts_per_page_over_max_is_rejected_by_schema(): void {
		$this->acting_as( 'editor' );
		// The input schema pins per_page to [1,50]; an over-max value is rejected by
		// the Abilities API validator BEFORE execute runs (defence in depth on top of
		// the helper's own clamp, which HelpersTest covers at the unit level).
		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'post_type' => 'post',
				'status'    => 'publish',
				'per_page'  => 9999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_posts_per_page_at_max_boundary_is_accepted(): void {
		$this->acting_as( 'editor' );
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		// per_page = 50 is the inclusive upper bound and must be accepted.
		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'post_type' => 'post',
				'status'    => 'publish',
				'per_page'  => 50,
			)
		);
		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'posts', $out );
	}

	public function test_get_posts_page_beyond_last_returns_empty_not_error(): void {
		$this->acting_as( 'editor' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'post_type' => 'post',
				'status'    => 'publish',
				'page'      => 9999,
			)
		);
		$this->assertIsArray( $out );
		$this->assertSame( array(), $out['posts'] );
	}

	public function test_get_posts_rejects_unknown_post_type(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array( 'post_type' => 'definitely_not_a_type' )
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_post_type', $out->get_error_code() );
	}

	public function test_get_post_allows_owner_editor_to_read_own_draft(): void {
		// The deny branch is covered elsewhere; this exercises the per-object
		// edit_post ALLOW branch (private/draft status, but caller can edit it).
		$author = $this->acting_as( 'author' );
		$draft  = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'draft',
				'post_title'  => 'My private draft',
			)
		);

		$this->assertTrue(
			wp_get_ability( 'aafm/get-post' )->check_permissions( array( 'post_id' => $draft ) )
		);
		$out = wp_get_ability( 'aafm/get-post' )->execute( array( 'post_id' => $draft ) );
		$this->assertSame( 'My private draft', $out['post']['title'] );
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_get_post_public_status_is_readable_by_subscriber(): void {
		// Public-status short-circuit (no per-object edit needed).
		$post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Anyone can read me',
			)
		);
		$this->acting_as( 'subscriber' );
		$this->assertTrue(
			wp_get_ability( 'aafm/get-post' )->check_permissions( array( 'post_id' => $post ) )
		);
	}

	public function test_get_page_rejects_a_post_id_as_a_page(): void {
		// A non-page id must be rejected so get-page can't read a post by id confusion.
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->acting_as( 'editor' );
		// Permission denies a non-page id outright.
		$this->assertFalse(
			wp_get_ability( 'aafm/get-page' )->check_permissions( array( 'page_id' => $post ) )
		);
	}

	public function test_get_comments_whole_site_listing_allowed_for_any_logged_in(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_content'  => 'Approved everywhere',
			)
		);
		// No post_id supplied → whole-site approved listing, gated by 'read'.
		$this->acting_as( 'subscriber' );
		$this->assertTrue(
			wp_get_ability( 'aafm/get-comments' )->check_permissions( array() )
		);
		$out      = wp_get_ability( 'aafm/get-comments' )->execute( array() );
		$contents = wp_list_pluck( $out['comments'], 'content' );
		$this->assertContains( 'Approved everywhere', $contents );
	}

	public function test_get_comments_whole_site_listing_excludes_comments_on_hidden_posts(): void {
		// A subscriber paging the whole-site approved listing (no post_id) must see
		// comments on PUBLIC posts but never an approved comment whose parent post is
		// private/draft — "approved" is not "public" when the post itself is hidden.
		$public_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $public_post,
				'comment_approved' => '1',
				'comment_content'  => 'VISIBLE_ON_PUBLIC_POST',
			)
		);

		$private_post = self::factory()->post->create( array( 'post_status' => 'private' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $private_post,
				'comment_approved' => '1',
				'comment_content'  => 'LEAK_ON_PRIVATE_POST',
			)
		);

		$draft_post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $draft_post,
				'comment_approved' => '1',
				'comment_content'  => 'LEAK_ON_DRAFT_POST',
			)
		);

		$this->acting_as( 'subscriber' );
		$out      = wp_get_ability( 'aafm/get-comments' )->execute( array() );
		$contents = wp_list_pluck( $out['comments'], 'content' );

		$this->assertContains( 'VISIBLE_ON_PUBLIC_POST', $contents, 'Approved comment on a public post must be listed.' );
		$this->assertNotContains( 'LEAK_ON_PRIVATE_POST', $contents, 'Approved comment on a private post must not leak.' );
		$this->assertNotContains( 'LEAK_ON_DRAFT_POST', $contents, 'Approved comment on a draft post must not leak.' );
	}

	public function test_get_comments_denies_missing_post_id_to_prevent_probing(): void {
		$this->acting_as( 'subscriber' );
		// A non-existent post id is default-deny (can't probe for ids).
		$this->assertFalse(
			wp_get_ability( 'aafm/get-comments' )->check_permissions( array( 'post_id' => 99999 ) )
		);
	}

	public function test_get_users_search_filter_narrows_results(): void {
		$this->acting_as( 'administrator' );
		self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Zaphod Searchable',
				'user_login'   => 'zaphodsearchable',
			)
		);

		$out   = wp_get_ability( 'aafm/get-users' )->execute( array( 'search' => 'zaphodsearchable' ) );
		$names = wp_list_pluck( $out['users'], 'display_name' );
		$this->assertContains( 'Zaphod Searchable', $names );
	}

	public function test_update_post_execute_rejects_nonexistent_id(): void {
		// Admin passes the per-object permission for a phantom id (edit_post on a
		// missing post resolves true for an admin), so execute's get_post() guard
		// is the line that must error.
		$this->acting_as( 'administrator' );
		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => 987654,
				'title'   => 'ghost',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_update_post_execute_rejects_invalid_status(): void {
		$admin = $this->acting_as( 'administrator' );
		$post  = self::factory()->post->create(
			array(
				'post_author' => $admin,
				'post_status' => 'publish',
			)
		);
		// 'trash' is never an allow-listed status for the updater — execute must error
		// rather than silently route the post to trash via the status field.
		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post,
				'status'  => 'trash',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	public function test_update_post_execute_degrades_when_post_vanishes_post_update(): void {
		// The top-of-execute guard sees the post, wp_update_post succeeds, then a
		// destructive hook deletes it before the redacting re-fetch. Without an
		// instanceof guard the typed aafm_redact_post() throws an uncaught TypeError
		// (fatal); the contract is a clean generic WP_Error instead.
		$admin = $this->acting_as( 'administrator' );
		$post  = self::factory()->post->create(
			array(
				'post_author' => $admin,
				'post_status' => 'publish',
			)
		);

		$nuke = static function ( $post_id ) use ( $post ): void {
			if ( (int) $post_id === (int) $post ) {
				wp_delete_post( (int) $post, true );
			}
		};
		add_action( 'post_updated', $nuke, 10, 1 );

		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post,
				'title'   => 'Will vanish',
			)
		);

		remove_action( 'post_updated', $nuke, 10 );

		$this->assertInstanceOf( WP_Error::class, $out );
		// Must be the plugin's own clean generic error — not the Abilities API's
		// 'ability_callback_exception' wrapper, which would mean a raw TypeError
		// escaped the execute callback.
		$this->assertSame( 'aafm_error', $out->get_error_code() );
	}

	public function test_set_featured_image_execute_rejects_missing_post(): void {
		$this->acting_as( 'administrator' );
		// Real image attachment, phantom post id.
		$att = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		$this->track_attachment_files( $att );

		$out = wp_get_ability( 'aafm/set-featured-image' )->execute(
			array(
				'post_id'       => 876543,
				'attachment_id' => $att,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_upload_media_rejects_oversize_payload(): void {
		$this->acting_as( 'administrator' );

		// Force a tiny max-upload-size so a valid PNG exceeds it; assert no file lands.
		$cap = static fn(): int => 8;
		add_filter( 'upload_size_limit', $cap );
		add_filter( 'pre_option_max_upload_size', $cap );

		$before = count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);
		$out    = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'big.png',
				'data_base64' => self::PNG_B64,
			)
		);
		$after  = count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);

		remove_filter( 'upload_size_limit', $cap );
		remove_filter( 'pre_option_max_upload_size', $cap );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_too_large', $out->get_error_code() );
		$this->assertSame( $before, $after, 'No attachment should be created on an oversize reject.' );
	}

	public function test_upload_media_rejects_garbage_that_decodes_to_non_image(): void {
		$this->acting_as( 'administrator' );
		// A non-empty payload that base64-decodes to plain text (not an image). It
		// passes the schema's minLength and strict base64 decode, then fails the
		// byte-sniffed image allow-list — no file is written.
		$before = count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);
		$out    = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'notreally.png',
				// Encoding a benign test fixture, not obfuscating code.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'data_base64' => base64_encode( 'this is just text, definitely not an image' ),
			)
		);
		$after  = count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_disallowed_type', $out->get_error_code() );
		$this->assertSame( $before, $after, 'No attachment should be created when bytes are not an allowed image.' );
	}

	public function test_moderate_comment_missing_comment_execute_errors(): void {
		$this->acting_as( 'administrator' );
		$out = wp_get_ability( 'aafm/moderate-comment' )->execute(
			array(
				'comment_id' => 765432,
				'action'     => 'approve',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}
}
