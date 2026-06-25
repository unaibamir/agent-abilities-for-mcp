<?php
/**
 * Plugins-screen row links: the plugin_action_links filter prepends our quick links.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class PluginActionLinksTest extends TestCase {

	/**
	 * Our four quick links are prepended, in order, before WordPress's own actions, and
	 * the existing actions (e.g. Deactivate) are preserved after them.
	 */
	public function test_quick_links_are_prepended_in_order(): void {
		$actions = oversio_plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame(
			array( 'oversio-getting-started', 'oversio-abilities', 'oversio-integrations', 'oversio-settings', 'deactivate' ),
			array_keys( $actions ),
			'Our links come first, in the agreed order, with Deactivate kept last.'
		);
	}

	/**
	 * Each link points at the correct admin tab and carries the expected label.
	 */
	public function test_links_target_the_right_tabs(): void {
		$actions = oversio_plugin_action_links( array() );

		$this->assertStringContainsString( 'page=oversio-agent-abilities', $actions['oversio-getting-started'] );
		$this->assertStringNotContainsString( 'tab=', $actions['oversio-getting-started'] );
		$this->assertStringContainsString( 'Getting Started', $actions['oversio-getting-started'] );

		$this->assertStringContainsString( 'tab=abilities', $actions['oversio-abilities'] );
		$this->assertStringContainsString( 'tab=integrations', $actions['oversio-integrations'] );
		$this->assertStringContainsString( 'tab=settings', $actions['oversio-settings'] );
	}

	/**
	 * The link labels are escaped output and the markup is a well-formed anchor.
	 */
	public function test_links_are_anchor_markup(): void {
		$actions = oversio_plugin_action_links( array() );

		foreach ( array( 'oversio-getting-started', 'oversio-abilities', 'oversio-integrations', 'oversio-settings' ) as $key ) {
			$this->assertStringStartsWith( '<a href="', $actions[ $key ] );
			$this->assertStringEndsWith( '</a>', $actions[ $key ] );
		}
	}
}
