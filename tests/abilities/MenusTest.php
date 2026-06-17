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
}
