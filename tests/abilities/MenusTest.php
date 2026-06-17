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

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class MenusTest extends TestCase {

	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	private function register_menus(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/list-menus',
				'aafm/get-menu',
				'aafm/list-menu-items',
				'aafm/create-menu',
				'aafm/update-menu',
				'aafm/delete-menu',
				'aafm/create-menu-item',
				'aafm/update-menu-item',
				'aafm/delete-menu-item',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	private function make_menu( string $name = 'Primary' ): int {
		$id = wp_create_nav_menu( $name );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public function test_list_menus_requires_edit_theme_options(): void {
		$this->register_menus();
		$this->make_menu();
		$this->acting_as( 'editor' ); // Editor lacks edit_theme_options.
		$this->assertNotTrue( wp_get_ability( 'aafm/list-menus' )->check_permissions( array() ) );
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/list-menus' )->execute( array() );
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

		$res = wp_get_ability( 'aafm/get-menu' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame( 'Footer', $res['name'] );

		$items = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame( 'Home', $items['items'][0]['title'] );
		$this->assertArrayNotHasKey( 'email', $items['items'][0] );
	}

	public function test_get_menu_rejects_unknown_id(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$this->assertInstanceOf( WP_Error::class, wp_get_ability( 'aafm/get-menu' )->execute( array( 'menu_id' => 999999 ) ) );
	}

	public function test_create_then_update_then_delete_menu(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );

		$created = wp_get_ability( 'aafm/create-menu' )->execute( array( 'name' => 'New Menu' ) );
		$this->assertArrayHasKey( 'id', $created );
		$menu_id = (int) $created['id'];

		$renamed = wp_get_ability( 'aafm/update-menu' )->execute(
			array(
				'menu_id' => $menu_id,
				'name'    => 'Renamed',
			)
		);
		$this->assertSame( 'Renamed', $renamed['name'] );

		$deleted = wp_get_ability( 'aafm/delete-menu' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $deleted );
		$this->assertFalse( wp_get_nav_menu_object( $menu_id ), 'menu permanently removed.' );
	}

	public function test_menu_writes_deny_an_editor(): void {
		$this->register_menus();
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/create-menu' )->check_permissions( array() ) );
		$this->assertNotTrue( wp_get_ability( 'aafm/delete-menu' )->check_permissions( array() ) );
	}

	public function test_create_menu_rejects_a_smuggled_field(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$this->assertInstanceOf(
			WP_Error::class,
			wp_get_ability( 'aafm/create-menu' )->execute(
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

		$item = wp_get_ability( 'aafm/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'title'   => 'About',
				'url'     => home_url( '/about' ),
			)
		);
		$this->assertArrayHasKey( 'id', $item );
		$item_id = (int) $item['id'];

		$up = wp_get_ability( 'aafm/update-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'item_id' => $item_id,
				'title'   => 'About Us',
			)
		);
		$this->assertSame( 'About Us', $up['title'] );

		$del = wp_get_ability( 'aafm/delete-menu-item' )->execute( array( 'item_id' => $item_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $del );
		// Empirical force-delete check: wp_delete_post( $id ) with NO ,true literal must remove
		// the trash-less nav_menu_item outright in WP 7.0. If this assertion holds, the no-true
		// call is sufficient and no force-delete primitive (or invariant extension) is needed.
		$this->assertNull( get_post( $item_id ), 'menu item removed.' );
	}

	public function test_menu_write_is_discoverable_by_an_admin_and_hidden_from_an_editor(): void {
		// Exercises the server.php fall-through for a menu WRITE (not just by comment): an admin
		// holds edit_theme_options so create-menu is discoverable; an editor lacks it, so it is
		// hidden. This proves the object-independent edit_theme_options gate scopes writes too.
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/create-menu' ) );

		$this->acting_as( 'editor' ); // Editor lacks edit_theme_options.
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/create-menu' ) );
	}
}
