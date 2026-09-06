<?php
/**
 * Native Slim SEO integration: slim-seo-get-post, slim-seo-update-post.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SlimSeoTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_slim_seo', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		parent::tear_down();
	}

	public function test_get_post_reads_the_slim_seo_meta_array(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'          => 'Custom title',
				'description'    => 'Custom description',
				'canonical'      => 'https://example.com/canonical',
				'noindex'        => true,
				'facebook_image' => 'https://example.com/fb.jpg',
				'twitter_image'  => 'https://example.com/tw.jpg',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_get_post( array( 'post_id' => $post->ID ) );

		$this->assertSame( 'Custom title', $out['title'] );
		$this->assertSame( 'Custom description', $out['description'] );
		$this->assertSame( 'https://example.com/canonical', $out['canonical'] );
		$this->assertTrue( $out['noindex'] );
	}

	public function test_get_post_defaults_absent_fields_to_empty_and_false(): void {
		$post = self::factory()->post->create_and_get();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_get_post( array( 'post_id' => $post->ID ) );

		foreach ( aafm_slim_seo_fields() as $field ) {
			$this->assertSame( '', $out[ $field ], "{$field} should default to an empty string." );
		}
		$this->assertFalse( $out['noindex'] );
	}

	public function test_update_post_writes_the_slim_seo_meta_array_and_preserves_untouched_fields(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'       => 'Old title',
				'description' => 'Old description',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => 'New title',
			)
		);

		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertSame( 'New title', $stored['title'] );
		$this->assertSame( 'Old description', $stored['description'], 'A field not passed in this update must survive untouched, matching every sibling SEO integration\'s partial-update contract.' );
	}

	public function test_update_post_writes_noindex_and_url_fields(): void {
		$post = self::factory()->post->create_and_get();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_update_post(
			array(
				'post_id'        => $post->ID,
				'noindex'        => true,
				'facebook_image' => 'https://example.com/new-fb.jpg',
			)
		);

		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertTrue( $stored['noindex'] );
		$this->assertSame( 'https://example.com/new-fb.jpg', $stored['facebook_image'] );
		$this->assertTrue( $out['noindex'] );
	}

	/**
	 * Codex hunt F4: a site-installed update_post_metadata filter that vetoes the write must
	 * surface as a structured error, not a success response carrying the stale stored value.
	 */
	public function test_update_post_returns_an_error_when_the_write_is_vetoed(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'slim_seo', array( 'title' => 'Old title' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$veto = static fn() => false;
		add_filter( 'update_post_metadata', $veto, 10, 0 );
		$out  = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => 'New title',
			)
		);
		remove_filter( 'update_post_metadata', $veto, 10 );

		$this->assertInstanceOf(
			\WP_Error::class,
			$out,
			'A vetoed slim_seo meta write must return an error, not a success reporting the old value.'
		);
	}

	public function test_update_post_requires_edit_access(): void {
		$post = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( aafm_perm_seo_post_object( array( 'post_id' => $post ) ) );
	}

	public function test_get_post_returns_generic_error_for_an_unknown_id(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_get_post( array( 'post_id' => 999999 ) );

		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	/**
	 * Bootstrap-wiring proof (Codex-review amendment 13): enabling the integration through its
	 * own detection seam must make the ability appear in the registry WITHOUT this test itself
	 * requiring includes/abilities/slim-seo.php - proving the plugin's own bootstrap require list
	 * wires the file, not merely that the file's functions work when manually loaded.
	 */
	public function test_slim_seo_abilities_register_through_the_normal_bootstrap(): void {
		$this->register_enabled( array( 'aafm/slim-seo-get-post', 'aafm/slim-seo-update-post' ) );

		$this->assertContains( 'aafm/slim-seo-get-post', aafm_all_server_ability_names() );
		$this->assertContains( 'aafm/slim-seo-update-post', aafm_all_server_ability_names() );
	}
}
