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
}
