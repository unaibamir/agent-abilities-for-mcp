<?php
declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class MenuStructureTest extends TestCase {

	public function test_admin_tabs_map_has_expected_slugs(): void {
		$tabs = aafm_admin_tabs();
		$this->assertSame(
			array( 'dashboard', 'connection', 'abilities', 'settings', 'activity', 'help' ),
			array_keys( $tabs )
		);
		$this->assertSame( 'Dashboard', $tabs['dashboard'] );
	}

	public function test_registers_top_level_menu_with_tab_submenus(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages;
		$menu = $submenu = $admin_page_hooks = $_registered_pages = $_parent_pages = array();

		aafm_register_admin_menu();

		$this->assertArrayHasKey( 'agent-abilities-for-mcp', $admin_page_hooks );
		$this->assertArrayHasKey( 'agent-abilities-for-mcp', $submenu );
		$slugs = wp_list_pluck( $submenu['agent-abilities-for-mcp'], 2 );
		$this->assertContains( 'agent-abilities-for-mcp', $slugs );
		$this->assertContains( 'agent-abilities-for-mcp&tab=connection', $slugs );
		$this->assertContains( 'agent-abilities-for-mcp&tab=help', $slugs );
	}

	public function test_assets_enqueue_on_the_top_level_hook(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		aafm_enqueue_admin_assets( 'toplevel_page_agent-abilities-for-mcp' );
		$this->assertTrue( wp_style_is( 'aafm-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'aafm-admin', 'enqueued' ) );
	}

	public function test_tab_links_use_admin_php_not_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		aafm_install_activity_log();
		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'admin.php?page=agent-abilities-for-mcp', $html );
		$this->assertStringNotContainsString( 'options-general.php?page=agent-abilities-for-mcp', $html );
	}
}
