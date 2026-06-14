<?php
/**
 * Connection tab logic: diagnostics checks, agent-user creation, snippet building.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;
use WP_Error;

final class ConnectionTest extends TestCase {

	public function test_diagnostics_report_adapter_and_endpoint(): void {
		// Note: we do NOT fire rest_api_init here. The adapter creates its server (a
		// process-global, once-per-ID resource) on that action, so re-firing it across the
		// suite trips an "ID already exists" incorrect-usage notice. The diagnostic shape is
		// what matters; the endpoint check legitimately reports pass or fail either way.
		$checks = aafm_diagnostic_checks();
		$ids    = wp_list_pluck( $checks, 'id' );
		$this->assertContains( 'adapter', $ids );
		$this->assertContains( 'endpoint', $ids );
		$this->assertContains( 'auth_header', $ids );

		$by_id = array_column( $checks, null, 'id' );
		// The adapter is bundled and loaded in the test process, so this check always passes.
		$this->assertSame( 'pass', $by_id['adapter']['status'] );
		// Endpoint and auth-header are environment-dependent; assert they yield a known state.
		$this->assertContains( $by_id['endpoint']['status'], array( 'pass', 'fail' ) );
		$this->assertContains( $by_id['auth_header']['status'], array( 'pass', 'warn' ) );
	}

	public function test_create_agent_user_makes_a_low_priv_user(): void {
		$this->acting_as( 'administrator' );
		$result = aafm_create_agent_user( 'mcp-agent' );
		$this->assertIsArray( $result );
		$this->assertIsInt( $result['user_id'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertNotContains( 'administrator', $user->roles );
		$this->assertNotContains( 'editor', $user->roles );
	}

	public function test_create_agent_user_rejects_duplicate_login(): void {
		$this->acting_as( 'administrator' );
		aafm_create_agent_user( 'dupe-agent' );
		$again = aafm_create_agent_user( 'dupe-agent' );
		$this->assertInstanceOf( WP_Error::class, $again );
	}

	public function test_client_snippet_points_at_endpoint_and_username(): void {
		$snippet = aafm_client_snippet( 'claude', 'mcp-agent' );
		$this->assertStringContainsString( rest_url( 'agent-abilities-for-mcp/mcp' ), $snippet );
		$this->assertStringContainsString( 'mcp-agent', $snippet );
		// The wizard never embeds a real secret — only the paste placeholder.
		$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet );
	}

	public function test_unix_snippet_launches_npx_directly(): void {
		$cfg    = json_decode( aafm_client_snippet( 'claude', 'mcp-agent', 'unix' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame( array( '-y', '@automattic/mcp-wordpress-remote@latest' ), $server['args'] );
	}

	public function test_windows_snippet_wraps_launcher_in_cmd(): void {
		$cfg    = json_decode( aafm_client_snippet( 'claude', 'mcp-agent', 'windows' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}

	public function test_local_site_snippet_carries_tls_bypass(): void {
		add_filter( 'aafm_site_is_local', '__return_true' );
		$cfg = json_decode( aafm_client_snippet( 'claude', 'mcp-agent' ), true );
		remove_filter( 'aafm_site_is_local', '__return_true' );
		$env = $cfg['mcpServers']['agent-abilities']['env'];
		$this->assertSame( '0', $env['NODE_TLS_REJECT_UNAUTHORIZED'] );
	}

	public function test_production_site_snippet_omits_tls_bypass(): void {
		add_filter( 'aafm_site_is_local', '__return_false' );
		$cfg = json_decode( aafm_client_snippet( 'claude', 'mcp-agent' ), true );
		remove_filter( 'aafm_site_is_local', '__return_false' );
		$env = $cfg['mcpServers']['agent-abilities']['env'];
		$this->assertArrayNotHasKey( 'NODE_TLS_REJECT_UNAUTHORIZED', $env );
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

	public function test_connection_tab_renders_guided_three_step_layout(): void {
		$html = $this->render_connection_tab();

		// New Direction A structure: stepper, client picker, diagnostics rail.
		$this->assertStringContainsString( 'aafm-step-head', $html );
		$this->assertStringContainsString( 'aafm-client-grid', $html );
		$this->assertStringContainsString( 'aafm-diag', $html );

		// The endpoint URL is shown verbatim.
		$this->assertStringContainsString( esc_html( rest_url( 'agent-abilities-for-mcp/mcp' ) ), $html );

		// The proxy package name survives in the primary config block.
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote', $html );
	}

	public function test_connection_tab_preserves_js_contract_ids(): void {
		$html = $this->render_connection_tab();

		// The create-agent-user controls keep their exact ids/classes for admin.js.
		$this->assertStringContainsString( 'id="aafm-agent-login"', $html );
		$this->assertStringContainsString( 'id="aafm-create-user"', $html );
		$this->assertStringContainsString( 'aafm-user-status', $html );

		// The test-connection controls keep their exact ids/classes.
		$this->assertStringContainsString( 'id="aafm-test-connection"', $html );
		$this->assertStringContainsString( 'aafm-test-status', $html );

		// OS toggle + OS-keyed snippet boxes the OS-tab handler binds to.
		$this->assertStringContainsString( 'aafm-os-tab', $html );
		$this->assertStringContainsString( 'data-os="unix"', $html );
		$this->assertStringContainsString( 'data-os="windows"', $html );

		// Per-client quickstart toggle + grid the quickstart handler binds to.
		$this->assertStringContainsString( 'aafm-quickstart-toggle', $html );
		$this->assertStringContainsString( 'id="aafm-quickstart-grid"', $html );

		// Copy buttons keep their hook class.
		$this->assertStringContainsString( 'aafm-copy', $html );
	}

	public function test_connection_tab_keeps_diagnostic_labels_and_platform_notes(): void {
		$html = $this->render_connection_tab();

		// Diagnostic labels survive the restyle.
		$this->assertStringContainsString( 'MCP adapter active and compatible', $html );
		$this->assertStringContainsString( 'MCP REST endpoint registered', $html );
		$this->assertStringContainsString( 'Authorization header reaches WordPress', $html );

		// Diagnostic rows map state to the status-dot classes.
		$this->assertStringContainsString( 'aafm-diag-row', $html );
		$this->assertStringContainsString( 'dot-lg', $html );

		// Platform-specific notices are preserved.
		$this->assertStringContainsString( 'Windows', $html );
		$this->assertStringContainsString( 'Certificate', $html );
		$this->assertStringContainsString( 'NODE_TLS_REJECT_UNAUTHORIZED', $html );

		// Security framing: the snippet never embeds a real secret.
		$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $html );
	}

	public function test_connection_tab_emits_every_client_snippet(): void {
		$html = $this->render_connection_tab();

		// Every quickstart client keeps a presence in the rendered markup so the
		// per-client config is reachable from the picker.
		foreach ( aafm_quickstart_clients() as $slug => $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html, "client {$slug} label missing from render" );
		}

		// VS Code's distinct "servers" key proves per-client shaping reaches the markup.
		$this->assertStringContainsString( 'servers', $html );
	}
}
