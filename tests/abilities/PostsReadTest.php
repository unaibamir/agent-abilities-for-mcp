<?php
/**
 * Post read abilities: redaction, status guard, per-object gating.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class PostsReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter — the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/get-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_posts_is_in_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-posts', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-posts']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-posts']['risk'] );
	}

	public function test_get_posts_returns_published_only_for_low_priv(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Live',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Hidden',
			)
		);

		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'post_type' => 'post',
				'status'    => 'publish',
			)
		);

		$this->assertArrayHasKey( 'posts', $out );
		$titles = wp_list_pluck( $out['posts'], 'title' );
		$this->assertContains( 'Live', $titles );
		$this->assertNotContains( 'Hidden', $titles );
	}

	public function test_get_posts_rejects_status_any(): void {
		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'post_type' => 'post',
				'status'    => 'any',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_posts_low_priv_cannot_request_drafts(): void {
		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'post_type' => 'post',
				'status'    => 'draft',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_post_denies_non_public_to_low_priv(): void {
		$this->acting_as( 'subscriber' );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$out   = wp_get_ability( 'aafm/get-post' )->check_permissions( array( 'post_id' => $draft ) );
		$this->assertFalse( $out );
	}

	public function test_get_post_output_has_no_pii_fields(): void {
		$this->acting_as( 'editor' );
		$id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$out = wp_get_ability( 'aafm/get-post' )->execute( array( 'post_id' => $id ) );
		$this->assertSame( $id, $out['post']['id'] );
		$this->assertArrayNotHasKey( 'post_password', $out['post'] );
	}
}
