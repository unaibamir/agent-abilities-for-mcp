<?php
/**
 * Page shell: header lede, status pill, and Dashicon-prefixed nav tabs.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ShellTest extends TestCase {

	public function test_shell_has_lede_and_dashicon_tabs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-page-lede', $html );
		$this->assertStringContainsString( 'dashicons-dashboard', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );          // Markup kept.
		$this->assertStringContainsString( 'aafm-status-pill', $html );
	}
}
