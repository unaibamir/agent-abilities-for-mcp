<?php
/**
 * Per-client quickstart snippets: the client roster and the per-client config shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ClientSnippetTest extends TestCase {

	public function test_each_client_shapes_a_secret_free_config(): void {
		// Only the config-snippet clients get an mcp-remote config; hosted web apps do not.
		foreach ( aafm_config_snippet_clients() as $slug => $label ) {
			$snippet = aafm_client_snippet( $slug, 'mcp-agent', 'unix' );
			$this->assertStringContainsString( 'mcp-agent', $snippet, "client {$slug} missing username" );
			// The paste placeholder must be present so the operator drops in the real secret.
			$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet, "client {$slug} missing placeholder" );
			// No real password material ever leaks into a snippet.
			$this->assertStringNotContainsString( 'wp_', $snippet, "client {$slug} leaked secret-like material" );
			// The label is a translated, non-empty display string.
			$this->assertNotSame( '', (string) $label, "client {$slug} has an empty label" );
		}
	}

	public function test_hosted_web_apps_are_absent_from_the_config_grid(): void {
		$all    = array_keys( aafm_quickstart_clients() );
		$config = array_keys( aafm_config_snippet_clients() );

		// Hosted web apps appear in the overall client list (the OAuth-by-URL path)...
		$this->assertContains( 'chatgpt', $all );
		$this->assertContains( 'claude', $all );
		$this->assertContains( 'manus', $all );
		// ...but never in the mcp-remote config-snippet grid, since they cannot run stdio.
		$this->assertNotContains( 'chatgpt', $config );
		$this->assertNotContains( 'claude', $config );
		$this->assertNotContains( 'manus', $config );

		// A proxy client stays in both, and a made-up hosted slug is in neither.
		$this->assertContains( 'gemini-cli', $config );
		$this->assertNotContains( 'gemini-hosted', $all );
	}

	public function test_every_client_snippet_is_valid_json(): void {
		foreach ( array_keys( aafm_config_snippet_clients() ) as $slug ) {
			$decoded = json_decode( aafm_client_snippet( $slug, 'mcp-agent', 'unix' ), true );
			$this->assertNotNull( $decoded, "client {$slug} did not emit valid JSON" );
			$this->assertIsArray( $decoded );
		}
	}

	public function test_vscode_uses_a_different_top_level_shape_than_claude_code(): void {
		$claude = json_decode( aafm_client_snippet( 'claude-code', 'mcp-agent', 'unix' ), true );
		$vscode = json_decode( aafm_client_snippet( 'vscode', 'mcp-agent', 'unix' ), true );

		// Claude Code (and most clients) use the mcpServers key.
		$this->assertArrayHasKey( 'mcpServers', $claude );
		$this->assertArrayNotHasKey( 'servers', $claude );

		// VS Code reads .vscode/mcp.json under a "servers" key - proves $client is used.
		$this->assertArrayHasKey( 'servers', $vscode );
		$this->assertArrayNotHasKey( 'mcpServers', $vscode );

		$this->assertNotSame( array_keys( $claude ), array_keys( $vscode ) );
	}

	public function test_windows_variant_still_wraps_cmd_for_a_client(): void {
		$cfg    = json_decode( aafm_client_snippet( 'cursor', 'mcp-agent', 'windows' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}

	public function test_vscode_windows_variant_wraps_cmd_under_servers_key(): void {
		$cfg    = json_decode( aafm_client_snippet( 'vscode', 'mcp-agent', 'windows' ), true );
		$server = $cfg['servers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}
}
