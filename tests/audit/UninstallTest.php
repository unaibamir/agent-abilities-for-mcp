<?php
/**
 * Per-site uninstall cleanup removes the option and the log table.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class UninstallTest extends TestCase {

	public function test_cleanup_drops_table_and_option(): void {
		aafm_install_activity_log();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$this->assertTrue( $this->activity_log_table_exists() );

		aafm_uninstall_site();

		$this->assertFalse( get_option( 'aafm_enabled_abilities' ) );
		$this->assertFalse( $this->activity_log_table_exists() );
	}
}
