<?php
/**
 * Tests for the "Token lifetimes and client registration" card.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * Covers aafm_connection_lifetimes_data() and aafm_render_lifetimes_card().
 */
class LifetimesCardTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_oauth_access_ttl' );
		delete_option( 'aafm_oauth_refresh_ttl' );
		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		aafm_render_lifetimes_card( aafm_connection_lifetimes_data() );
		return (string) ob_get_clean();
	}

	public function test_rendering_the_card_writes_nothing(): void {
		global $wpdb;
		$snapshot = static fn() => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'aafm\\_%' ORDER BY option_name", ARRAY_A );
		$before   = $snapshot();
		$this->render();
		$this->assertSame( $before, $snapshot() );
	}

	public function test_the_card_shows_the_effective_values_and_the_defaults(): void {
		$data = aafm_connection_lifetimes_data();
		$this->assertSame( 3600, $data['access'] );
		$this->assertSame( 2592000, $data['refresh'] );

		$html = $this->render();
		$this->assertMatchesRegularExpression( '/data-ttl="access" data-saved="3600" min="1" step="1" value="1">/', $html );
		$this->assertMatchesRegularExpression( '/data-ttl="refresh" data-saved="2592000" min="1" step="1" value="30">/', $html );
		$this->assertStringContainsString( 'Default: ' . human_time_diff( 0, HOUR_IN_SECONDS ), $html );
		$this->assertStringContainsString( 'Default: ' . human_time_diff( 0, 30 * DAY_IN_SECONDS ), $html );

		update_option( 'aafm_oauth_access_ttl', 1800 );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/data-ttl="access" data-saved="1800" min="1" step="1" value="30">/', $html );
		$this->assertMatchesRegularExpression( '/<option value="60" selected/', $html );
	}

	public function test_the_card_states_the_new_tokens_only_rule_and_the_dcr_consequence(): void {
		$html = $this->render();
		foreach ( array( 'applies to tokens issued from now on', 'does not shorten tokens already issued', 'ends a connection at once', 'Turning this off closes a public endpoint that anyone can reach without logging in' ) as $text ) {
			$this->assertStringContainsStringIgnoringCase( $text, $html );
		}
	}

	public function test_it_renders_collapsed_and_the_save_button_starts_disabled(): void {
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/<details class="aafm-card aafm-section aafm-section--collapsible" id="aafm-oauth-lifetimes">/', $html );
		$this->assertStringContainsString( 'id="aafm-lifetimes-save" disabled', $html );
	}
}
