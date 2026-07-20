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

	public function test_update_menu_item_title_only_preserves_the_rest(): void {
		// Regression pin for H6: wp_update_nav_menu_item() is not a partial API - core backfills
		// every omitted field from defaults (menu-item-type -> 'custom', blank url/object/parent/
		// classes/order) at wp/wp-includes/nav-menu.php and persists them. So a title-only update
		// that sends only menu-item-title flips a page item to a custom link, blanks its url and
		// object, flattens its parent, wipes its classes, and resets its order. update-menu-item
		// must read the existing item and re-send its full field set so only the title changes.
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = $this->make_menu( 'Nav' );

		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Target Page',
			)
		);

		// A parent item to nest under, and the page item itself with classes and a set order.
		$parent_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Parent',
				'menu-item-url'    => home_url( '/parent' ),
				'menu-item-status' => 'publish',
			)
		);
		$this->assertIsInt( $parent_id );

		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Original Label',
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page_id,
				'menu-item-parent-id' => $parent_id,
				'menu-item-classes'   => 'nav-highlight promo',
				'menu-item-position'  => 5,
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertIsInt( $item_id );

		// Snapshot the stored state before the update, straight from core meta and the post row.
		$before_type      = get_post_meta( $item_id, '_menu_item_type', true );
		$before_object    = get_post_meta( $item_id, '_menu_item_object', true );
		$before_object_id = (int) get_post_meta( $item_id, '_menu_item_object_id', true );
		$before_parent    = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );
		$before_classes   = (array) get_post_meta( $item_id, '_menu_item_classes', true );
		$before_order     = (int) get_post( $item_id )->menu_order;

		// The ability's read representation reindexes menu_order to a contiguous display index, so
		// capture that separately to assert the redacted return preserves it.
		$before_items    = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$before_redacted = 0;
		foreach ( $before_items['items'] as $listed ) {
			if ( (int) $listed['id'] === (int) $item_id ) {
				$before_redacted = (int) $listed['order'];
			}
		}

		$this->assertSame( 'post_type', $before_type );
		$this->assertSame( 'page', $before_object );
		$this->assertSame( $page_id, $before_object_id );
		$this->assertSame( (int) $parent_id, $before_parent );
		$this->assertSame( array( 'nav-highlight', 'promo' ), $before_classes );
		$this->assertSame( 5, $before_order, 'the stored menu order starts at the value we set.' );

		// Title-only update through the ability.
		$up = wp_get_ability( 'aafm/update-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'item_id' => $item_id,
				'title'   => 'New Label',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $up );

		// The title changed...
		$this->assertSame( 'New Label', $up['title'], 'the title was updated.' );

		// ...and nothing else did. Read the persisted meta and post row after the update.
		$this->assertSame( $before_type, get_post_meta( $item_id, '_menu_item_type', true ), 'type survives (not flipped to custom).' );
		$this->assertSame( $before_object, get_post_meta( $item_id, '_menu_item_object', true ), 'object survives.' );
		$this->assertSame( $before_object_id, (int) get_post_meta( $item_id, '_menu_item_object_id', true ), 'object_id survives.' );
		$this->assertSame( $before_parent, (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true ), 'parent survives (not flattened).' );
		$this->assertSame( $before_classes, (array) get_post_meta( $item_id, '_menu_item_classes', true ), 'classes survive (not wiped).' );
		$this->assertSame( $before_order, (int) get_post( $item_id )->menu_order, 'menu order survives (not reset).' );

		// The redacted return reflects the same preserved shape.
		$this->assertSame( 'post_type', $up['type'], 'returned type is the page item, not custom.' );
		$this->assertSame( 'page', $up['object'], 'returned object survives.' );
		$this->assertSame( $page_id, (int) $up['object_id'], 'returned object_id survives.' );
		$this->assertSame( (int) $parent_id, (int) $up['parent'], 'returned parent survives.' );
		$this->assertSame( $before_redacted, (int) $up['order'], 'returned order (display index) survives.' );
		// A post_type item derives its url from the object, so it must resolve to the page permalink,
		// never the empty url a custom-link flip would leave behind.
		$this->assertSame( get_permalink( $page_id ), $up['url'], 'url resolves to the page permalink.' );
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

		$res = wp_get_ability( 'aafm/delete-menu-item' )->execute( array( 'item_id' => $page_id ) );
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

		$item    = wp_get_ability( 'aafm/create-menu-item' )->execute(
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
		$item2   = wp_get_ability( 'aafm/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'title'   => 'XSS2',
				'url'     => 'data:text/html,<script>alert(1)</script>',
			)
		);
		$stored2 = get_post_meta( (int) $item2['id'], '_menu_item_url', true );
		$this->assertSame( '', $stored2, 'esc_url_raw neutralizes the data: scheme to an empty url.' );
	}

	public function test_create_menu_item_returns_the_created_item(): void {
		// The re-read after the write must resolve the just-created item by id. On a WPML site
		// wp_get_nav_menu_items() is language-filtered and would hide the fresh item, so the helper
		// resolves it language-agnostically (by post + nav_menu term). Without WPML present this pins
		// the plain contract: create hands back the item it just made, not a generic error.
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = $this->make_menu( 'Create' );

		$item = wp_get_ability( 'aafm/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_id,
				'title'   => 'Contact',
				'url'     => home_url( '/contact' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $item, 'the created item is re-read, not lost.' );
		$this->assertArrayHasKey( 'id', $item );
		$this->assertSame( 'Contact', $item['title'], 're-read by id returns the created item.' );
	}

	public function test_update_menu_item_rejects_item_from_another_menu(): void {
		// The cross-menu contract update relies on: an item that lives in menu A cannot be steered
		// through menu B. The re-read helper scopes by the nav_menu term relationship, so an item of
		// another menu resolves to null and the update returns a generic error rather than editing it.
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_a = $this->make_menu( 'Menu A' );
		$menu_b = $this->make_menu( 'Menu B' );

		$item    = wp_get_ability( 'aafm/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_a,
				'title'   => 'A item',
				'url'     => home_url( '/a' ),
			)
		);
		$item_id = (int) $item['id'];

		$res = wp_get_ability( 'aafm/update-menu-item' )->execute(
			array(
				'menu_id' => $menu_b,
				'item_id' => $item_id,
				'title'   => 'hijack',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'an item from another menu is rejected.' );

		// The item's title in menu A is untouched by the rejected cross-menu update.
		$this->assertSame( 'A item', get_post( $item_id )->post_title, 'the item in its own menu is unchanged.' );
	}

	public function test_menu_item_by_id_resolves_and_scopes(): void {
		// Direct contract for the re-read helper: it resolves an item for its owning menu, returns
		// null for a different menu (term-scoped), and null for a post that is not a nav_menu_item
		// (so it can never be steered onto an arbitrary post).
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_a = $this->make_menu( 'Alpha' );
		$menu_b = $this->make_menu( 'Beta' );

		$item    = wp_get_ability( 'aafm/create-menu-item' )->execute(
			array(
				'menu_id' => $menu_a,
				'title'   => 'Docs',
				'url'     => home_url( '/docs' ),
			)
		);
		$item_id = (int) $item['id'];

		$found = aafm_menu_item_by_id( $menu_a, $item_id );
		$this->assertIsObject( $found, 'the item resolves for its owning menu.' );
		$this->assertSame( $item_id, (int) $found->ID );

		$this->assertNull( aafm_menu_item_by_id( $menu_b, $item_id ), 'an item of another menu does not resolve.' );

		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->assertNull( aafm_menu_item_by_id( $menu_a, $page_id ), 'a non nav_menu_item id does not resolve.' );
	}

	public function test_list_menu_items_returns_items_ordered_by_menu_order(): void {
		// list-menu-items must return the menu's items sorted by menu_order, resolved from the
		// nav_menu term membership (language-agnostic) rather than the WPML-filtered core reader.
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = $this->make_menu( 'Ordered' );

		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'    => 'Second',
				'menu-item-url'      => home_url( '/second' ),
				'menu-item-position' => 2,
				'menu-item-status'   => 'publish',
			)
		);
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'    => 'First',
				'menu-item-url'      => home_url( '/first' ),
				'menu-item-position' => 1,
				'menu-item-status'   => 'publish',
			)
		);

		$res    = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$titles = array_column( $res['items'], 'title' );
		$this->assertSame( array( 'First', 'Second' ), $titles, 'items come back sorted by menu_order.' );
	}

	public function test_list_menu_items_empty_menu_returns_empty_list(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = $this->make_menu( 'Empty' );

		$res = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame( array(), $res['items'], 'an empty menu yields an empty item list.' );
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
