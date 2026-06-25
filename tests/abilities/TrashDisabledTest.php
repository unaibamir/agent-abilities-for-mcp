<?php
/**
 * Trash-disabled safety: the plugin's "recoverable, never permanently deleted"
 * guarantee must hold on sites where EMPTY_TRASH_DAYS is 0/falsy.
 *
 * WP core's wp_trash_post()/wp_trash_comment() force a permanent delete when the
 * Trash is disabled. These abilities must refuse instead of silently destroying
 * content. The check lives behind oversio_trash_is_enabled(), which is filterable so
 * both branches are testable without redefining a PHP constant mid-suite.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class TrashDisabledTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/trash-post',
				'oversio/trash-page',
				'oversio/moderate-comment',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	/**
	 * Force the Trash-disabled condition without touching the PHP constant.
	 */
	private function disable_trash(): void {
		add_filter( 'oversio_trash_is_enabled', '__return_false' );
	}

	public function test_helper_reflects_filter_override(): void {
		// Default test env defines EMPTY_TRASH_DAYS truthy → enabled.
		$this->assertTrue( oversio_trash_is_enabled() );

		$this->disable_trash();
		$this->assertFalse( oversio_trash_is_enabled() );
	}

	public function test_trash_post_refuses_and_keeps_post_when_trash_disabled(): void {
		$this->disable_trash();
		$this->acting_as( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$out = wp_get_ability( 'oversio/trash-post' )->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'oversio_trash_disabled', $out->get_error_code() );

		// Critical: the post must still exist (NOT force-deleted).
		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'trash-post permanently deleted the post when Trash was disabled.' );
		$this->assertSame( 'publish', $post->post_status );
	}

	public function test_trash_page_refuses_and_keeps_page_when_trash_disabled(): void {
		$this->disable_trash();
		$this->acting_as( 'administrator' );
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$out = wp_get_ability( 'oversio/trash-page' )->execute( array( 'page_id' => $page_id ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'oversio_trash_disabled', $out->get_error_code() );

		$page = get_post( $page_id );
		$this->assertNotNull( $page, 'trash-page permanently deleted the page when Trash was disabled.' );
		$this->assertSame( 'publish', $page->post_status );
	}

	public function test_moderate_comment_trash_refuses_and_keeps_comment_when_trash_disabled(): void {
		$this->disable_trash();
		$this->acting_as( 'administrator' );
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);

		$out = wp_get_ability( 'oversio/moderate-comment' )->execute(
			array(
				'comment_id' => $comment_id,
				'action'     => 'trash',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'oversio_trash_disabled', $out->get_error_code() );

		// Comment must still exist and stay approved (NOT force-deleted).
		$comment = get_comment( $comment_id );
		$this->assertNotNull( $comment, 'moderate-comment permanently deleted the comment when Trash was disabled.' );
		$this->assertSame( '1', $comment->comment_approved );
	}

	public function test_moderate_comment_non_trash_action_still_works_when_trash_disabled(): void {
		// Disabling Trash must not block the non-destructive moderation actions.
		$this->disable_trash();
		$this->acting_as( 'administrator' );
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '0',
			)
		);

		$out = wp_get_ability( 'oversio/moderate-comment' )->execute(
			array(
				'comment_id' => $comment_id,
				'action'     => 'approve',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'approved', $out['status'] );
	}

	public function test_trash_post_recoverable_when_trash_enabled(): void {
		// Normal case (default test env: Trash enabled) → status=trash, recoverable.
		$this->acting_as( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$out = wp_get_ability( 'oversio/trash-post' )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['trashed'] );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertSame( 'trash', $post->post_status );
		$this->assertNotFalse( wp_untrash_post( $post_id ) );
	}
}
