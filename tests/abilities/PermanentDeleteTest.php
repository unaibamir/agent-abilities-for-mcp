<?php
/**
 * Wave 2 Slice 6: the permanent-delete abilities (delete-post / delete-page).
 *
 * Both force-delete through the single sanctioned aafm_force_delete_post() executor in
 * posts.php (delete-page delegates with the page type pinned). These tests prove the
 * post/page is permanently gone after the call, the destructive annotation is honest,
 * a contributor is denied on another author's post, and delete-page refuses a non-page id.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class PermanentDeleteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		aafm_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	public function test_delete_post_permanently_removes_and_is_destructive(): void {
		$this->acting_as( 'administrator' );
		$id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res = wp_get_ability( 'aafm/delete-post' )->execute( array( 'post_id' => $id ) );
		$this->assertIsArray( $res );
		$this->assertNull( get_post( $id ), 'delete-post must permanently remove the post.' );
		$ann = wp_get_ability( 'aafm/delete-post' )->get_meta_item( 'annotations' );
		$this->assertTrue( $ann['destructive'] );
	}

	public function test_delete_post_denies_a_contributor_on_anothers_post(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$id        = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		$this->acting_as( 'contributor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/delete-post' )->check_permissions( array( 'post_id' => $id ) ) );
	}

	public function test_delete_page_permanently_removes_a_page_only(): void {
		$this->acting_as( 'administrator' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$res  = wp_get_ability( 'aafm/delete-page' )->execute( array( 'page_id' => $page ) );
		$this->assertIsArray( $res );
		$this->assertNull( get_post( $page ) );
	}

	public function test_delete_page_rejects_a_non_page_id(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res  = wp_get_ability( 'aafm/delete-page' )->execute( array( 'page_id' => $post ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'delete-page must reject a non-page id.' );
		$this->assertNotNull( get_post( $post ), 'the post must be untouched.' );
	}
}
