<?php
/**
 * Admin menu structure: shared tabs map, top-level menu, asset hook, and tab links.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class MenuStructureTest extends TestCase {

	public function test_admin_tabs_map_has_expected_slugs(): void {
		$tabs = oversio_admin_tabs();
		$this->assertSame(
			array( 'dashboard', 'connection', 'abilities', 'integrations', 'settings', 'activity', 'help' ),
			array_keys( $tabs )
		);
		$this->assertSame( 'Dashboard', $tabs['dashboard'] );
	}

	public function test_registers_top_level_menu_with_tab_submenus(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages;
		$menu              = array();
		$submenu           = array();
		$admin_page_hooks  = array();
		$_registered_pages = array();
		$_parent_pages     = array();

		oversio_register_admin_menu();

		$this->assertArrayHasKey( 'oversio-agent-abilities', $admin_page_hooks );
		$this->assertArrayHasKey( 'oversio-agent-abilities', $submenu );
		$slugs = wp_list_pluck( $submenu['oversio-agent-abilities'], 2 );
		$this->assertContains( 'oversio-agent-abilities', $slugs );
		$this->assertContains( 'oversio-agent-abilities&tab=connection', $slugs );
		$this->assertContains( 'oversio-agent-abilities&tab=integrations', $slugs );
		$this->assertContains( 'oversio-agent-abilities&tab=help', $slugs );
	}

	public function test_assets_enqueue_on_the_top_level_hook(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		oversio_enqueue_admin_assets( 'toplevel_page_oversio-agent-abilities' );
		$this->assertTrue( wp_style_is( 'oversio-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'oversio-admin', 'enqueued' ) );
	}

	public function test_tab_links_use_admin_php_not_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		oversio_install_activity_log();
		ob_start();
		oversio_render_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'admin.php?page=oversio-agent-abilities', $html );
		$this->assertStringNotContainsString( 'options-general.php?page=oversio-agent-abilities', $html );
	}
}
