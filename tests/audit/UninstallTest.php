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
		global $wpdb;
		aafm_install_activity_log();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		aafm_uninstall_site();

		$this->assertFalse( get_option( 'aafm_enabled_abilities' ) );
		$table = $wpdb->prefix . 'aafm_activity_log';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertNull( $found );
	}
}
