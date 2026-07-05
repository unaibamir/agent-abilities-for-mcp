<?php
/**
 * The bridged-abilities option is cleared on reset and uninstall.
 *
 * aafm_config_option_names() is the canonical config list looped by both aafm_reset_plugin()
 * and aafm_uninstall_site() (uninstall.php), so listing the option there covers both paths.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BridgeUninstallTest extends TestCase {

	public function test_option_listed_for_cleanup(): void {
		$this->assertContains( 'aafm_enabled_bridged_abilities', aafm_config_option_names() );
	}

	public function test_reset_clears_the_option(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );

		aafm_reset_plugin();

		$this->assertFalse( get_option( 'aafm_enabled_bridged_abilities', false ) );
	}
}
