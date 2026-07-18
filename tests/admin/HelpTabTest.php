<?php
/**
 * Help tab: renders the troubleshooting sections, and the tab router accepts ?tab=help.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class HelpTabTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_help_tab_renders_section_headings(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm-help', $html );
		$this->assertStringContainsString( 'Connecting', $html );
		$this->assertStringContainsString( 'Authentication', $html );
		$this->assertStringContainsString( 'Abilities &amp; permissions', $html );
		$this->assertStringContainsString( 'Clients, privacy &amp; limits', $html );
	}

	public function test_help_tab_documents_each_known_issue(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		// One <details> accordion per documented issue (19 total).
		$this->assertSame( 19, substr_count( $html, '<details class="aafm-help-entry">' ) );

		// Spot-check the load-bearing technical fixes are present and accurate.
		$this->assertStringContainsString( 'rest_route=/agent-abilities-for-mcp/mcp', $html );
		$this->assertStringContainsString( 'SetEnvIf Authorization', $html );
		$this->assertStringContainsString( 'fastcgi_param HTTP_AUTHORIZATION', $html );
		$this->assertStringContainsString( 'NODE_TLS_REJECT_UNAUTHORIZED', $html );
		$this->assertStringContainsString( 'NODE_EXTRA_CA_CERTS', $html );
		$this->assertStringContainsString( 'cmd /c', $html );
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote', $html );

		// The rate-limit answer points at the shipped Settings control, not the old "not yet" copy.
		$this->assertStringNotContainsString( 'Not in this release', $html );
		$this->assertStringContainsString( 'Rate limit (per minute)', $html );
		$this->assertStringContainsString( 'per agent user', $html );
	}

	/**
	 * L13: the term-meta help entry used to claim "Term meta has no field on the Abilities
	 * tab" and code-filter only, but aafm_render_term_meta_keys_selector() renders an
	 * "Exposed term meta keys" / "Denied term meta keys" field under the Taxonomies & Terms
	 * panel. The entry must point at that field, not deny it exists.
	 */
	public function test_help_tab_term_meta_entry_points_at_the_real_field(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Term meta has no field on the Abilities tab', $html );
		$this->assertStringContainsString( 'Exposed term meta keys', $html );
	}

	/**
	 * L14: aafm_rate_limit_consume() (includes/safety.php) keys its transient bucket by
	 * user_id alone, so two connections authenticated as the same agent user share one
	 * budget. The old copy claimed the opposite ("do not eat into each other's budget").
	 */
	public function test_help_tab_rate_limit_entry_says_connections_share_one_budget(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'do not eat into each other', $html );
		$this->assertStringContainsString( 'per agent user, not per connection', $html );
		$this->assertStringContainsString( 'share one budget', $html );
	}

	public function test_help_tab_documents_the_waf_cdn_unreachable_cluster(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		// The #1 support cluster: the request never reaches WordPress (WAF / CDN / cache / redirect).
		$this->assertStringContainsString( '403 / 406 / 429', $html );
		$this->assertStringContainsString( 'Block AI Bots', $html );
		$this->assertStringContainsString( 'ModSecurity', $html );
		$this->assertStringContainsString( 'Zero Trust', $html );
		// Page/edge cache exclusion and the wildcard allow path for the MCP route.
		$this->assertStringContainsString( 'edge cache', $html );
		$this->assertStringContainsString( '/wp-json/agent-abilities-for-mcp/*', $html );
		// Trailing-slash / http->https redirect entry.
		$this->assertStringContainsString( 'trailing slash', $html );
	}

	public function test_help_tab_documents_the_curl_probe(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		// The copyable curl initialize probe, with its load-bearing flags and body.
		$this->assertStringContainsString( 'curl -i -X POST', $html );
		$this->assertStringContainsString( '-u &quot;mcp-agent:', $html );
		$this->assertStringContainsString( 'Accept: application/json, text/event-stream', $html );
		$this->assertStringContainsString( '&quot;method&quot;:&quot;initialize&quot;', $html );
		// It is a copyable snippet (reuses the shared copy button).
		$this->assertStringContainsString( 'data-copy="curl -i -X POST', $html );
		// The how-to-read-the-result guidance covers each status it can return.
		$this->assertStringContainsString( 'flush permalinks', $html );
		$this->assertStringContainsString( 'server-side error', $html );
	}

	public function test_help_tab_documents_per_client_setup(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		// Per-client guidance covers the web apps and the proxy clients.
		$this->assertStringContainsString( 'ChatGPT:', $html );
		$this->assertStringContainsString( 'Claude:', $html );
		$this->assertStringContainsString( 'Cursor:', $html );
		$this->assertStringContainsString( 'Windsurf:', $html );
		// ChatGPT now connects via its developer-mode custom connector.
		$this->assertStringContainsString( 'Developer mode', $html );
		// Only the hosted Gemini app is still called out as unable to connect; Gemini CLI works
		// as a proxy client (accurate to this release).
		$this->assertStringContainsString( 'hosted Gemini app cannot connect', $html );
		$this->assertStringContainsString( 'Gemini CLI', $html );
	}

	public function test_help_tab_documents_plain_language_security_model(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		// The plain-language security model accordion (the differentiator).
		$this->assertStringContainsString( 'No external calls', $html );
		$this->assertStringContainsString( 'dedicated low-privilege user', $html );
		$this->assertStringContainsString( 'Two locks on every ability', $html );
		// The trash-vs-delete distinction: trash is recoverable, delete (and every media/user
		// removal) is permanent. The copy was reworded to draw this line explicitly.
		$this->assertStringContainsString( 'Trash and permanent delete are different abilities.', $html );
		$this->assertStringContainsString( 'where you can restore it', $html );
		$this->assertStringContainsString( 'every media or user deletion, is permanent', $html );
		$this->assertStringContainsString( 'argument KEYS only', $html );
	}

	public function test_help_copy_lines_reuse_the_copy_button_hook(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_help_tab();
		$html = (string) ob_get_clean();

		// Copyable snippets must carry the shared .aafm-copy class + data-copy payload.
		$this->assertStringContainsString( 'aafm-copy', $html );
		$this->assertStringContainsString( 'data-copy="SetEnvIf Authorization', $html );
	}

	public function test_help_entry_escapes_summary_and_filters_body(): void {
		ob_start();
		aafm_render_help_entry( '<script>x</script>', '<p>ok</p><iframe src="x"></iframe>' );
		$html = (string) ob_get_clean();

		// Summary is esc_html'd.
		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		// Body is echoed as-is by the helper (caller is responsible for wp_kses),
		// so this proves the helper does not double-escape valid markup.
		$this->assertStringContainsString( '<p>ok</p>', $html );
	}

	public function test_router_accepts_help_tab(): void {
		$this->acting_as( 'administrator' );
		$_GET['tab'] = 'help';

		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		// The Help nav tab is rendered active, and the help body renders.
		$this->assertStringContainsString( 'aafm-help', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'tab=help', $html );
	}

	public function test_router_falls_back_to_dashboard_for_unknown_tab(): void {
		$this->acting_as( 'administrator' );
		$_GET['tab'] = 'bogus';

		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		// Unknown tab routes to the Dashboard tab, not Help.
		$this->assertStringContainsString( 'aafm-dashboard', $html );
		$this->assertStringNotContainsString( 'aafm-help-entry', $html );
	}
}
