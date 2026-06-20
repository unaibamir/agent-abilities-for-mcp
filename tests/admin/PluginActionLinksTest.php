<?php
/**
 * Plugins-screen row links: the plugin_action_links filter prepends our quick links.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class PluginActionLinksTest extends TestCase {

	/**
	 * Our four quick links are prepended, in order, before WordPress's own actions, and
	 * the existing actions (e.g. Deactivate) are preserved after them.
	 */
	public function test_quick_links_are_prepended_in_order(): void {
		$actions = aafm_plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame(
			array( 'aafm-getting-started', 'aafm-abilities', 'aafm-integrations', 'aafm-settings', 'deactivate' ),
			array_keys( $actions ),
			'Our links come first, in the agreed order, with Deactivate kept last.'
		);
	}

	/**
	 * Each link points at the correct admin tab and carries the expected label.
	 */
	public function test_links_target_the_right_tabs(): void {
		$actions = aafm_plugin_action_links( array() );

		$this->assertStringContainsString( 'page=agent-abilities-for-mcp', $actions['aafm-getting-started'] );
		$this->assertStringNotContainsString( 'tab=', $actions['aafm-getting-started'] );
		$this->assertStringContainsString( 'Getting Started', $actions['aafm-getting-started'] );

		$this->assertStringContainsString( 'tab=abilities', $actions['aafm-abilities'] );
		$this->assertStringContainsString( 'tab=integrations', $actions['aafm-integrations'] );
		$this->assertStringContainsString( 'tab=settings', $actions['aafm-settings'] );
	}

	/**
	 * The link labels are escaped output and the markup is a well-formed anchor.
	 */
	public function test_links_are_anchor_markup(): void {
		$actions = aafm_plugin_action_links( array() );

		foreach ( array( 'aafm-getting-started', 'aafm-abilities', 'aafm-integrations', 'aafm-settings' ) as $key ) {
			$this->assertStringStartsWith( '<a href="', $actions[ $key ] );
			$this->assertStringEndsWith( '</a>', $actions[ $key ] );
		}
	}
}
