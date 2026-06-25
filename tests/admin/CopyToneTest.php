<?php
/**
 * Copy tone: the visible Connection and Dashboard body leans least-privilege.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class CopyToneTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The dashboard render queries the activity log table.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_copy_leans_least_privilege(): void {
		ob_start();
		aafm_render_connection_tab();
		$conn = ob_get_clean();
		ob_start();
		aafm_render_dashboard_tab();
		$dash = ob_get_clean();
		$all  = strtolower( $conn . $dash );
		$this->assertStringContainsString( 'least', $all );
		$this->assertStringNotContainsString( 'full access', $all );
		$this->assertStringNotContainsString( 'all tools', $all );
	}
}
