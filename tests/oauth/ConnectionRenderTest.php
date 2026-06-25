<?php
/**
 * Tests for the additive OAuth card at the top of the Connection tab.
 *
 * The card is gated on oversio_oauth_enabled(): when OAuth is on it renders first,
 * before the existing Application Password endpoint card and the three numbered
 * steps; when OAuth is off it is omitted entirely and the rest of the tab renders
 * exactly as before. These assertions prove the gate is additive and leaves the
 * existing render untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\OAuth;

use Oversio\Tests\TestCase;

/**
 * Verifies the OAuth connection card and the no-regression invariant.
 */
class ConnectionRenderTest extends TestCase {

	/**
	 * Restore the OAuth toggle after any test that changed it.
	 */
	public function tear_down(): void {
		delete_option( 'oversio_oauth_enabled' );
		parent::tear_down();
	}

	/**
	 * Capture the rendered Connection tab markup.
	 *
	 * @return string
	 */
	private function render_connection_tab(): string {
		ob_start();
		oversio_render_connection_tab();
		return (string) ob_get_clean();
	}

	/**
	 * With OAuth enabled (the default), the card renders the heading, the endpoint
	 * URL, and a copy button carrying the endpoint URL in data-copy.
	 */
	public function test_oauth_card_renders_when_enabled(): void {
		$html = $this->render_connection_tab();
		$url  = oversio_endpoint_url();

		$this->assertStringContainsString( 'Connect with OAuth', $html );
		$this->assertStringContainsString( esc_html( $url ), $html );
		$this->assertStringContainsString( 'oversio-copy', $html );
		$this->assertStringContainsString( 'data-copy="' . esc_attr( $url ) . '"', $html );

		// The card's copy button carries a disambiguating aria-label so screen-reader
		// users can tell it apart from the other "Copy" buttons on the tab.
		$this->assertStringContainsString( 'aria-label="Copy the MCP endpoint URL"', $html );
	}

	/**
	 * With OAuth disabled, the card shows a notice instead of the picker, and the
	 * App Password fallback renders open so it is immediately visible.
	 */
	public function test_oauth_card_shows_notice_when_disabled(): void {
		update_option( 'oversio_oauth_enabled', '0' );

		$html = $this->render_connection_tab();

		// Heading is always shown — the content switches to a short notice.
		$this->assertStringContainsString( 'Connect with OAuth', $html );
		$this->assertStringContainsString( 'OAuth is turned off', $html );

		// The App Password fallback opens automatically when OAuth is off.
		$this->assertMatchesRegularExpression( '/<details[^>]*\bopen\b/', $html );

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

		$this->assertStringContainsString( 'oversio-endpoint-card', $html );
		$this->assertStringContainsString( 'oversio-create-user', $html );
		$this->assertStringContainsString( 'oversio-test-connection', $html );
		$this->assertStringContainsString( 'Create a dedicated agent user', $html );
		$this->assertStringContainsString( 'Connect your client', $html );
		$this->assertStringContainsString( 'Check the endpoint is reachable', $html );
	}

	/**
	 * With OAuth on and no clients/grants, the management tables show empty states.
	 */
	public function test_oauth_management_empty_states_render(): void {
		oversio_install_oauth_tables();
		oversio_truncate_oauth_tables();

		$html = $this->render_connection_tab();

		$this->assertStringContainsString( 'Registered clients', $html );
		$this->assertStringContainsString( 'Active grants', $html );
		$this->assertStringContainsString( 'No clients have registered yet.', $html );
		$this->assertStringContainsString( 'No one has approved an OAuth connection yet.', $html );
		// The revoke AJAX nonce field is printed for the JS to read.
		$this->assertStringContainsString( 'oversio_oauth_admin_nonce', $html );
	}

	/**
	 * A seeded client and grant render in the tables, escaped, with revoke controls.
	 */
	public function test_oauth_management_renders_rows_escaped(): void {
		global $wpdb;
		oversio_install_oauth_tables();
		oversio_truncate_oauth_tables();

		$user_id = self::factory()->user->create(
			array(
				'user_login'   => 'grant_owner',
				'display_name' => 'Grant Owner',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'oversio_oauth_clients',
			array(
				'client_id'     => 'client_abc123def456',
				'client_name'   => 'Claude',
				'redirect_uris' => wp_json_encode( array( 'https://claude.ai/cb' ) ),
				'is_active'     => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'oversio_oauth_consents',
			array(
				'wp_user_id' => $user_id,
				'client_id'  => 'client_abc123def456',
			),
			array( '%d', '%s' )
		);

		$html = $this->render_connection_tab();

		$this->assertStringContainsString( 'widefat striped oversio-oauth-table', $html );
		$this->assertStringContainsString( 'data-client-id="client_abc123def456"', $html );
		$this->assertStringContainsString( 'oversio-revoke-client', $html );
		$this->assertStringContainsString( 'oversio-revoke-grant', $html );
		$this->assertStringContainsString( 'grant_owner', $html );
		$this->assertStringContainsString( 'Grant Owner', $html );
		// Redirect URI rendered through esc_url as a link.
		$this->assertStringContainsString( 'href="https://claude.ai/cb"', $html );
		// Active client shows the success pill.
		$this->assertStringContainsString( 'oversio-pill oversio-pill-success', $html );
	}
}
