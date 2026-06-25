<?php
/**
 * Wave 2 Slice 6: the permanent-delete abilities (delete-post / delete-page).
 *
 * Both force-delete through the single sanctioned oversio_force_delete_post() executor in
 * posts.php (delete-page delegates with the page type pinned). These tests prove the
 * post/page is permanently gone after the call, the destructive annotation is honest,
 * a contributor is denied on another author's post, and delete-page refuses a non-page id.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class PermanentDeleteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		oversio_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'oversio_enabled_abilities', array_keys( oversio_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		oversio_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	public function test_delete_post_permanently_removes_and_is_destructive(): void {
		$this->acting_as( 'administrator' );
		$id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res = wp_get_ability( 'oversio/delete-post' )->execute( array( 'post_id' => $id ) );
		$this->assertIsArray( $res );
		$this->assertNull( get_post( $id ), 'delete-post must permanently remove the post.' );
		$ann = wp_get_ability( 'oversio/delete-post' )->get_meta_item( 'annotations' );
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
		$this->assertNotTrue( wp_get_ability( 'oversio/delete-post' )->check_permissions( array( 'post_id' => $id ) ) );
	}

	public function test_delete_page_permanently_removes_a_page_only(): void {
		$this->acting_as( 'administrator' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$res  = wp_get_ability( 'oversio/delete-page' )->execute( array( 'page_id' => $page ) );
		$this->assertIsArray( $res );
		$this->assertNull( get_post( $page ) );
	}

	public function test_delete_page_rejects_a_non_page_id(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res  = wp_get_ability( 'oversio/delete-page' )->execute( array( 'page_id' => $post ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'delete-page must reject a non-page id.' );
		$this->assertNotNull( get_post( $post ), 'the post must be untouched.' );
	}

	public function test_discovery_floors_match_the_trash_abilities(): void {
		// An admin holds delete_posts and the page delete_posts cap, so both tools show up.
		$this->acting_as( 'administrator' );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/delete-post' ) );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/delete-page' ) );

		// A contributor holds the coarse delete_posts floor (exactly like trash-post), so it
		// discovers delete-post — per-object delete_post still denies it on others' content at
		// execute time (proven above). It lacks the page delete cap, so delete-page stays hidden.
		$this->acting_as( 'contributor' );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/delete-post' ) );
		$this->assertSame(
			oversio_user_can_discover_ability( 'oversio/trash-post' ),
			oversio_user_can_discover_ability( 'oversio/delete-post' ),
			'delete-post must share the trash-post discovery floor.'
		);
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/delete-page' ) );

		// A subscriber holds neither floor cap, so neither tool is discoverable.
		$this->acting_as( 'subscriber' );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/delete-post' ) );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/delete-page' ) );
	}
}
