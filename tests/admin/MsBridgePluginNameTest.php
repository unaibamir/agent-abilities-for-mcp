<?php
/**
 * A network-activated plugin names its bridged tools the same way a site-activated one does.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * Pins the active_sitewide_plugins branch of aafm_bridge_active_plugin_name().
 *
 * @group ms-required
 */
final class MsBridgePluginNameTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	public function test_a_network_activated_plugin_gives_the_provider_label(): void {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'network-only/network-only.php' => array(
						'Name'       => 'Network Only Plugin',
						'TextDomain' => 'network-only',
					),
				),
			),
			'plugins'
		);
		update_option( 'active_plugins', array() );
		update_site_option( 'active_sitewide_plugins', array( 'network-only/network-only.php' => time() ) );

		$this->assertSame( 'Network Only Plugin', aafm_bridge_provider_label( 'network-only' ) );
		$this->assertStringContainsString( 'Provided by Network Only Plugin.', aafm_bridge_source_line( 'network-only' ) );
	}
}
