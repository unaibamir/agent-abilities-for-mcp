<?php
/**
 * Page read abilities: redaction, status guard, per-object gating.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class PagesReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter - the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-pages', 'aafm/get-page' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_pages_is_in_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-pages', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-pages']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-pages']['risk'] );
	}

	public function test_get_pages_lists_published_pages(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About',
			)
		);

		$out = wp_get_ability( 'aafm/get-pages' )->execute( array( 'status' => 'publish' ) );

		$this->assertArrayHasKey( 'posts', $out );
		$this->assertContains( 'About', wp_list_pluck( $out['posts'], 'title' ) );
	}

	public function test_get_pages_excludes_drafts_from_low_priv(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Live Page',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Hidden Page',
			)
		);

		$out    = wp_get_ability( 'aafm/get-pages' )->execute( array( 'status' => 'publish' ) );
		$titles = wp_list_pluck( $out['posts'], 'title' );

		$this->assertContains( 'Live Page', $titles );
		$this->assertNotContains( 'Hidden Page', $titles );
	}

	public function test_get_pages_rejects_status_any(): void {
		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/get-pages' )->execute( array( 'status' => 'any' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_pages_status_guard_uses_the_page_read_private_cap(): void {
		// read_private_pages and read_private_posts are distinct primitives on stock WP.
		// get-pages pins post_type to page, so its private-status guard must consult
		// read_private_pages, not the post default. An actor holding only the page cap
		// may request status=private; one holding only the post cap may not.
		$may_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $may_id )->add_cap( 'read_private_pages' );
		wp_set_current_user( $may_id );
		$allowed = wp_get_ability( 'aafm/get-pages' )->execute( array( 'status' => 'private' ) );
		$this->assertArrayHasKey( 'posts', $allowed );

		$may_not_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $may_not_id )->add_cap( 'read_private_posts' );
		wp_set_current_user( $may_not_id );
		$denied = wp_get_ability( 'aafm/get-pages' )->execute( array( 'status' => 'private' ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
	}

	public function test_get_page_denies_private_to_low_priv(): void {
		$this->acting_as( 'subscriber' );
		$priv = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
			)
		);
		$out  = wp_get_ability( 'aafm/get-page' )->check_permissions( array( 'page_id' => $priv ) );
		$this->assertFalse( $out );
	}

	public function test_get_page_output_has_no_pii_fields(): void {
		$this->acting_as( 'editor' );
		$id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$out = wp_get_ability( 'aafm/get-page' )->execute( array( 'page_id' => $id ) );
		$this->assertSame( $id, $out['post']['id'] );
		$this->assertArrayNotHasKey( 'post_password', $out['post'] );
	}

	public function test_get_page_accepts_lang_arg(): void {
		$schema = wp_get_ability( 'aafm/get-page' )->get_input_schema();
		$this->assertArrayHasKey( 'lang', $schema['properties'] );
	}

	public function test_get_pages_accepts_lang_arg(): void {
		$schema = wp_get_ability( 'aafm/get-pages' )->get_input_schema();
		$this->assertArrayHasKey( 'lang', $schema['properties'] );
	}

	public function test_get_pages_reports_resolved_language_null_without_wpml(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$out = wp_get_ability( 'aafm/get-pages' )->execute( array() );
		$this->assertArrayHasKey( 'language', $out );
		$this->assertNull( $out['language'] ); // WPML off in the unit suite.
	}
}
