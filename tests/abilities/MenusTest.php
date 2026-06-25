<?php
/**
 * Slice C1: the navigation-menu read abilities (list-menus, get-menu, list-menu-items).
 *
 * Covers the edit_theme_options gate, the id/name/slug/count menu shape, reading one menu
 * and its items by id, the redacted item shape (no email or other post fields), and that an
 * unknown menu id returns a generic error rather than leaking which ids exist.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class MenusTest extends TestCase {

	private function register_menus(): void {
		oversio_install_activity_log();
		oversio_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/list-menus',
				'oversio/get-menu',
				'oversio/list-menu-items',
				'oversio/create-menu',
				'oversio/update-menu',
				'oversio/delete-menu',
				'oversio/create-menu-item',
				'oversio/update-menu-item',
				'oversio/delete-menu-item',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	private function make_menu( string $name = 'Primary' ): int {
		$id = wp_create_nav_menu( $name );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public function test_list_menus_requires_edit_theme_options(): void {
		$this->register_menus();
		$this->make_menu();
		$this->acting_as( 'editor' ); // Editor lacks edit_theme_options.
		$this->assertNotTrue( wp_get_ability( 'oversio/list-menus' )->check_permissions( array() ) );
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/list-menus' )->execute( array() );
		$this->assertArrayHasKey( 'menus', $res );
		$this->assertSame( array( 'id', 'name', 'slug', 'count' ), array_keys( $res['menus'][0] ) );
	}

	public function test_get_menu_and_list_items(): void {
		$this->register_menus();
		$menu_id = $this->make_menu( 'Footer' );
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'oversio/get-menu' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame( 'Footer', $res['name'] );

		$items = wp_get_ability( 'oversio/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame( 'Home', $items['items'][0]['title'] );
		$this->assertArrayNotHasKey( 'email', $items['items'][0] );
	}

	public function test_get_menu_rejects_unknown_id(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$this->assertInstanceOf( WP_Error::class, wp_get_ability( 'oversio/get-menu' )->execute( array( 'menu_id' => 999999 ) ) );
	}

	public function test_create_then_update_then_delete_menu(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );

		$created = wp_get_ability( 'oversio/create-menu' )->execute( array( 'name' => 'New Menu' ) );
		$this->assertArrayHasKey( 'id', $created );
		$menu_id = (int) $created['id'];

		$renamed = wp_get_ability( 'oversio/update-menu' )->execute(
			array(
				'menu_id' => $menu_id,
				'name'    => 'Renamed',
			)
		);
		$this->assertSame( 'Renamed', $renamed['name'] );

		$deleted = wp_get_ability( 'oversio/delete-menu' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $deleted );
		$this->assertFalse( wp_get_nav_menu_object( $menu_id ), 'menu permanently removed.' );
	}

	public function test_menu_writes_deny_an_editor(): void {
		$this->register_menus();
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'oversio/create-menu' )->check_permissions( array() ) );
		$this->assertNotTrue( wp_get_ability( 'oversio/delete-menu' )->check_permissions( array() ) );
	}

	public function test_create_menu_rejects_a_smuggled_field(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$this->assertInstanceOf(
			WP_Error::class,
			wp_get_ability( 'oversio/create-menu' )->execute(
				array(
					'name'     => 'x',
					'taxonomy' => 'category',
				)
			)
		);
	}

	public function test_create_update_delete_menu_item(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = $this->make_menu( 'Nav' );

		$item = wp_get_ability( 'oversio/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'title'   => 'About',
				'url'     => home_url( '/about' ),
			)
		);
		$this->assertArrayHasKey( 'id', $item );
		$item_id = (int) $item['id'];

		$up = wp_get_ability( 'oversio/update-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'item_id' => $item_id,
				'title'   => 'About Us',
			)
		);
		$this->assertSame( 'About Us', $up['title'] );

		$del = wp_get_ability( 'oversio/delete-menu-item' )->execute( array( 'item_id' => $item_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $del );
		// Empirical force-delete check: wp_delete_post( $id ) with NO ,true literal must remove
		// the trash-less nav_menu_item outright in WP 7.0. If this assertion holds, the no-true
		// call is sufficient and no force-delete primitive (or invariant extension) is needed.
		$this->assertNull( get_post( $item_id ), 'menu item removed.' );
	}

	public function test_delete_menu_item_rejects_arbitrary_post_id(): void {
		// Regression pin: delete-menu-item resolves the post first and rejects any id whose
		// post_type is not nav_menu_item. Without that guard, an edit_theme_options user could
		// steer this destructive write into an arbitrary-post-delete primitive. Pass a normal
		// page id and prove (a) the call errors and (b) the page is untouched.
		$this->register_menus();
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Untouchable',
			)
		);
		$this->acting_as( 'administrator' ); // Holds edit_theme_options.

		$res = wp_get_ability( 'oversio/delete-menu-item' )->execute( array( 'item_id' => $page_id ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'arbitrary post id is rejected.' );

		$still = get_post( $page_id );
		$this->assertNotNull( $still, 'the arbitrary page was not deleted.' );
		$this->assertSame( 'publish', $still->post_status, 'the page status is unchanged.' );
	}

	public function test_create_menu_item_neutralizes_dangerous_url_scheme(): void {
		// Regression pin: create-menu-item routes the url through esc_url_raw, which strips
		// unsafe schemes. On this WP 7.0, esc_url_raw() returns an empty string for both
		// javascript: and data: (verified in-container), so the stored url is empty rather than
		// a javascript:/data: link. Either way it must not begin with the dangerous scheme.
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = $this->make_menu( 'Danger' );

		$item    = wp_get_ability( 'oversio/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'title'   => 'XSS',
				'url'     => 'javascript:alert(1)',
			)
		);
		$item_id = (int) $item['id'];

		$stored = get_post_meta( $item_id, '_menu_item_url', true );
		$this->assertSame( '', $stored, 'esc_url_raw neutralizes the javascript: scheme to an empty url.' );
		$this->assertStringStartsNotWith( 'javascript:', (string) $stored, 'no javascript: scheme is stored.' );

		// data: is likewise neutralized to empty by esc_url_raw on this WP.
		$item2   = wp_get_ability( 'oversio/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'title'   => 'XSS2',
				'url'     => 'data:text/html,<script>alert(1)</script>',
			)
		);
		$stored2 = get_post_meta( (int) $item2['id'], '_menu_item_url', true );
		$this->assertSame( '', $stored2, 'esc_url_raw neutralizes the data: scheme to an empty url.' );
	}

	public function test_menu_write_is_discoverable_by_an_admin_and_hidden_from_an_editor(): void {
		// Exercises the server.php fall-through for a menu WRITE (not just by comment): an admin
		// holds edit_theme_options so create-menu is discoverable; an editor lacks it, so it is
		// hidden. This proves the object-independent edit_theme_options gate scopes writes too.
		$this->acting_as( 'administrator' );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/create-menu' ) );

		$this->acting_as( 'editor' ); // Editor lacks edit_theme_options.
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/create-menu' ) );
	}
}
