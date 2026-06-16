<?php
/**
 * Tests for the additive OAuth card at the top of the Connection tab.
 *
 * The card is gated on aafm_oauth_enabled(): when OAuth is on it renders first,
 * before the existing Application Password endpoint card and the three numbered
 * steps; when OAuth is off it is omitted entirely and the rest of the tab renders
 * exactly as before. These assertions prove the gate is additive and leaves the
 * existing render untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies the OAuth connection card and the no-regression invariant.
 */
class ConnectionRenderTest extends TestCase {

	/**
	 * Restore the OAuth toggle after any test that changed it.
	 */
	public function tear_down(): void {
		delete_option( 'aafm_oauth_enabled' );
		parent::tear_down();
	}

	/**
	 * Capture the rendered Connection tab markup.
	 *
	 * @return string
	 */
	private function render_connection_tab(): string {
		ob_start();
		aafm_render_connection_tab();
		return (string) ob_get_clean();
	}

	/**
	 * With OAuth enabled (the default), the card renders the heading, the endpoint
	 * URL, and a copy button carrying the endpoint URL in data-copy.
	 */
	public function test_oauth_card_renders_when_enabled(): void {
		$html = $this->render_connection_tab();
		$url  = aafm_endpoint_url();

		$this->assertStringContainsString( 'Connect with OAuth', $html );
		$this->assertStringContainsString( esc_html( $url ), $html );
		$this->assertStringContainsString( 'aafm-copy', $html );
		$this->assertStringContainsString( 'data-copy="' . esc_attr( $url ) . '"', $html );

		// The card's copy button carries a disambiguating aria-label so screen-reader
		// users can tell it apart from the other "Copy" buttons on the tab.
		$this->assertStringContainsString( 'aria-label="Copy the MCP endpoint URL"', $html );
	}

	/**
	 * With OAuth disabled, the card is omitted but the rest of the tab is intact.
	 */
	public function test_oauth_card_omitted_when_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );

		$html = $this->render_connection_tab();

		$this->assertStringNotContainsString( 'Connect with OAuth', $html );

		// The existing pieces still render, proving the gate is purely additive.
		$this->assertStringContainsString( 'MCP endpoint', $html );
		$this->assertStringContainsString( 'Create a dedicated agent user', $html );
		$this->assertStringContainsString( 'Connect your client', $html );
		$this->assertStringContainsString( 'Check the endpoint is reachable', $html );
	}

	/**
	 * With OAuth enabled, the existing Application Password section markers are all
	 * still present and unchanged.
	 */
	public function test_existing_app_password_section_is_untouched(): void {
		$html = $this->render_connection_tab();

		$this->assertStringContainsString( 'aafm-endpoint-card', $html );
		$this->assertStringContainsString( 'aafm-create-user', $html );
		$this->assertStringContainsString( 'aafm-test-connection', $html );
		$this->assertStringContainsString( 'Create a dedicated agent user', $html );
		$this->assertStringContainsString( 'Connect your client', $html );
		$this->assertStringContainsString( 'Check the endpoint is reachable', $html );
	}
}
