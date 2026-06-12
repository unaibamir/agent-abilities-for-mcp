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

		// One <details> accordion per documented issue (15 total).
		$this->assertSame( 15, substr_count( $html, '<details class="aafm-help-entry">' ) );

		// Spot-check the load-bearing technical fixes are present and accurate.
		$this->assertStringContainsString( 'rest_route=/agent-abilities-for-mcp/mcp', $html );
		$this->assertStringContainsString( 'SetEnvIf Authorization', $html );
		$this->assertStringContainsString( 'fastcgi_param HTTP_AUTHORIZATION', $html );
		$this->assertStringContainsString( 'NODE_TLS_REJECT_UNAUTHORIZED', $html );
		$this->assertStringContainsString( 'NODE_EXTRA_CA_CERTS', $html );
		$this->assertStringContainsString( 'cmd /c', $html );
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote', $html );
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

		// Per-client guidance beyond Claude.
		$this->assertStringContainsString( 'Cursor:', $html );
		$this->assertStringContainsString( 'Windsurf:', $html );
		$this->assertStringContainsString( 'claude_desktop_config.json', $html );
		// ChatGPT and Gemini are explicitly called out as unsupported (accurate to this release).
		$this->assertStringContainsString( 'ChatGPT and Gemini are not supported', $html );
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
		$this->assertStringContainsString( 'trash, not destroy', $html );
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

	public function test_router_falls_back_to_connection_for_unknown_tab(): void {
		$this->acting_as( 'administrator' );
		$_GET['tab'] = 'bogus';

		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		// Unknown tab routes to the Connection tab, not Help.
		$this->assertStringContainsString( 'aafm-connection', $html );
		$this->assertStringNotContainsString( 'aafm-help-entry', $html );
	}
}
