<?php
/**
 * Per-site uninstall cleanup removes the option and the log table.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Audit;

use Oversio\Tests\TestCase;

final class UninstallTest extends TestCase {

	/**
	 * When oversio_delete_data_on_uninstall is not set (default), oversio_uninstall_site_data()
	 * must be a no-op: config options, the activity-log table, the OAuth tables, and the
	 * OAuth schema-version option must all survive.
	 */
	public function test_uninstall_keeps_data_when_flag_not_set(): void {
		oversio_install_activity_log();
		oversio_install_oauth_tables();
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-posts' ) );
		update_option( 'oversio_force_draft', true );
		// Confirm the flag is absent (default keep-path).
		delete_option( 'oversio_delete_data_on_uninstall' );

		oversio_uninstall_site_data();

		// Config options survive.
		$this->assertNotFalse( get_option( 'oversio_enabled_abilities' ), 'oversio_enabled_abilities must survive when flag is off.' );
		$this->assertNotFalse( get_option( 'oversio_force_draft' ), 'oversio_force_draft must survive when flag is off.' );
		// Activity log table survives.
		$this->assertTrue( $this->activity_log_table_exists(), 'Activity log table must survive when flag is off.' );
		// OAuth schema version survives (proxy for OAuth tables still present).
		$this->assertNotFalse( get_option( 'oversio_oauth_schema_version' ), 'oversio_oauth_schema_version must survive when flag is off.' );
	}

	/**
	 * When oversio_delete_data_on_uninstall is explicitly set to true, oversio_uninstall_site_data()
	 * must run the full teardown: all config options gone, activity-log table dropped, OAuth
	 * schema-version option gone, and the flag option itself must also be gone.
	 */
	public function test_uninstall_wipes_everything_when_flag_is_set(): void {
		oversio_install_activity_log();
		oversio_install_oauth_tables();
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-posts' ) );
		update_option( 'oversio_force_draft', true );
		update_option( 'oversio_oauth_schema_version', '4' );
		update_option( 'oversio_delete_data_on_uninstall', true );

		oversio_uninstall_site_data();

		// Every config option is gone.
		foreach ( oversio_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} must be deleted when the uninstall flag is set." );
		}
		// Activity log table is gone.
		$this->assertFalse( $this->activity_log_table_exists(), 'Activity log table must be dropped when flag is set.' );
		// OAuth schema version is gone.
		$this->assertFalse( get_option( 'oversio_oauth_schema_version', false ), 'oversio_oauth_schema_version must be deleted when flag is set.' );
		// The flag itself must not leak.
		$this->assertFalse( get_option( 'oversio_delete_data_on_uninstall', false ), 'oversio_delete_data_on_uninstall must be deleted after the wipe.' );
	}

	public function test_cleanup_drops_table_and_option(): void {
		oversio_install_activity_log();
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-posts' ) );
		$this->assertTrue( $this->activity_log_table_exists() );

		oversio_uninstall_site();

		$this->assertFalse( get_option( 'oversio_enabled_abilities' ) );
		$this->assertFalse( $this->activity_log_table_exists() );
	}

	/**
	 * Uninstall must delete the FULL configuration option set, not just the hardcoded
	 * enabled-abilities literal — this proves the pre-existing leak fix (oversio_allowed_meta_keys
	 * plus the Slice C options all survived uninstall before). It must also drop the
	 * detected-keys transient, the only outside-config-list row in the same defect class.
	 *
	 * Asserts via get_option/get_transient === false, never a table probe (the temp-table CI
	 * lesson: the suite's DROP TABLE is rewritten to its TEMPORARY form).
	 */
	public function test_cleanup_removes_all_config_options_and_detected_keys_transient(): void {
		oversio_install_activity_log();
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
		update_option( 'oversio_denied_meta_keys', array( 'secret_key' ) );
		update_option( 'oversio_exposed_user_meta_keys', array( 'profile_color' ) );
		update_option( 'oversio_denied_user_meta_keys', array( 'private_note' ) );
		update_option( 'oversio_exposed_term_meta_keys', array( 'seo_title' ) );
		update_option( 'oversio_denied_term_meta_keys', array( 'term_secret' ) );
		set_transient( 'oversio_detected_meta_keys', array( 'x' ), HOUR_IN_SECONDS );

		oversio_uninstall_site();

		foreach ( oversio_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} should be deleted by uninstall." );
		}
		$this->assertFalse( get_transient( 'oversio_detected_meta_keys' ) );
	}
}
