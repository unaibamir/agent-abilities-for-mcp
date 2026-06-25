<?php
/**
 * Page shell: header lede, status pill, and inline-SVG-prefixed nav tabs.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class ShellTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Rendering the default (dashboard) tab queries the activity-log table, so install it
		// to keep the suite output clean.
		oversio_install_activity_log();
	}

	public function test_shell_has_lede_and_svg_tabs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		oversio_render_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'oversio-page-lede', $html );
		// Tabs are now prefixed with inline SVGs, not Dashicons.
		$this->assertStringContainsString( 'oversio-icon', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
		// The Dashboard nav tab still renders and is the active one.
		$this->assertStringContainsString( 'tab=dashboard', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );          // Markup kept.
		$this->assertStringContainsString( 'oversio-status-pill', $html );
	}
}
