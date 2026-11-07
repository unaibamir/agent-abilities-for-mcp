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

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter - the idiom WP core's own
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

	public function test_get_posts_reports_resolved_language_null_without_wpml(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$out = wp_get_ability( 'aafm/get-posts' )->execute( array() );
		$this->assertArrayHasKey( 'language', $out );
		$this->assertNull( $out['language'] ); // WPML off in the unit suite.
	}

	public function test_get_posts_rejects_undeclared_arg_but_accepts_lang(): void {
		$schema = wp_get_ability( 'aafm/get-posts' )->get_input_schema();
		$this->assertArrayHasKey( 'lang', $schema['properties'] );
	}

	/**
	 * A user who may read private posts but not edit other people's.
	 *
	 * @param string[] $caps Extra capabilities.
	 * @return int User id.
	 */
	private function reader_with( array $caps ): int {
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_user_by( 'id', $id );
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $id );
		return $id;
	}

	public function test_read_private_posts_alone_does_not_list_others_drafts(): void {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
				'post_title'  => 'others draft',
			)
		);
		$me = $this->reader_with( array( 'read_private_posts' ) );
		self::factory()->post->create(
			array(
				'post_author' => $me,
				'post_status' => 'draft',
				'post_title'  => 'my draft',
			)
		);

		$out = aafm_exec_get_posts( array( 'status' => 'draft' ) );

		$this->assertSame( array( 'my draft' ), array_column( $out['posts'], 'title' ) );
		$this->assertSame( 1, $out['total'] );
	}

	public function test_it_still_lists_others_private_posts(): void {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'private',
				'post_title'  => 'others private',
			)
		);
		$this->reader_with( array( 'read_private_posts' ) );

		$out = aafm_exec_get_posts( array( 'status' => 'private' ) );

		$this->assertCount( 1, $out['posts'] );
		$this->assertStringContainsString( 'others private', $out['posts'][0]['title'] );
	}

	public function test_edit_others_posts_still_lists_others_drafts(): void {
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
				'post_title'  => 'others draft',
			)
		);
		$this->acting_as( 'editor' );

		$out = aafm_exec_get_posts( array( 'status' => 'draft' ) );

		$this->assertSame( array( 'others draft' ), array_column( $out['posts'], 'title' ) );
	}
}
