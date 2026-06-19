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

	/**
	 * Uninstall must delete the FULL configuration option set, not just the hardcoded
	 * enabled-abilities literal — this proves the pre-existing leak fix (aafm_allowed_meta_keys
	 * plus the Slice C options all survived uninstall before). It must also drop the
	 * detected-keys transient, the only outside-config-list row in the same defect class.
	 *
	 * Asserts via get_option/get_transient === false, never a table probe (the temp-table CI
	 * lesson: the suite's DROP TABLE is rewritten to its TEMPORARY form).
	 */
	public function test_cleanup_removes_all_config_options_and_detected_keys_transient(): void {
		aafm_install_activity_log();
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		update_option( 'aafm_denied_meta_keys', array( 'secret_key' ) );
		update_option( 'aafm_exposed_user_meta_keys', array( 'profile_color' ) );
		update_option( 'aafm_denied_user_meta_keys', array( 'private_note' ) );
		set_transient( 'aafm_detected_meta_keys', array( 'x' ), HOUR_IN_SECONDS );

		aafm_uninstall_site();

		foreach ( aafm_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} should be deleted by uninstall." );
		}
		$this->assertFalse( get_transient( 'aafm_detected_meta_keys' ) );
	}
}
