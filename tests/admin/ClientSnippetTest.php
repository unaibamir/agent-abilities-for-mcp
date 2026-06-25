<?php
/**
 * Per-client quickstart snippets: the client roster and the per-client config shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class ClientSnippetTest extends TestCase {

	public function test_each_client_shapes_a_secret_free_config(): void {
		foreach ( oversio_quickstart_clients() as $slug => $label ) {
			$snippet = oversio_client_snippet( $slug, 'mcp-agent', 'unix' );
			$this->assertStringContainsString( 'mcp-agent', $snippet, "client {$slug} missing username" );
			// The paste placeholder must be present so the operator drops in the real secret.
			$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet, "client {$slug} missing placeholder" );
			// No real password material ever leaks into a snippet.
			$this->assertStringNotContainsString( 'wp_', $snippet, "client {$slug} leaked secret-like material" );
			// The label is a translated, non-empty display string.
			$this->assertNotSame( '', (string) $label, "client {$slug} has an empty label" );
		}
	}

	public function test_unsupported_clients_absent(): void {
		$slugs = array_keys( oversio_quickstart_clients() );
		$this->assertNotContains( 'chatgpt', $slugs );
		$this->assertNotContains( 'gemini-hosted', $slugs );
		$this->assertContains( 'claude-desktop', $slugs );
		$this->assertContains( 'gemini-cli', $slugs );
	}

	public function test_every_client_snippet_is_valid_json(): void {
		foreach ( array_keys( oversio_quickstart_clients() ) as $slug ) {
			$decoded = json_decode( oversio_client_snippet( $slug, 'mcp-agent', 'unix' ), true );
			$this->assertNotNull( $decoded, "client {$slug} did not emit valid JSON" );
			$this->assertIsArray( $decoded );
		}
	}

	public function test_vscode_uses_a_different_top_level_shape_than_claude_desktop(): void {
		$claude = json_decode( oversio_client_snippet( 'claude-desktop', 'mcp-agent', 'unix' ), true );
		$vscode = json_decode( oversio_client_snippet( 'vscode', 'mcp-agent', 'unix' ), true );

		// Claude Desktop (and most clients) use the mcpServers key.
		$this->assertArrayHasKey( 'mcpServers', $claude );
		$this->assertArrayNotHasKey( 'servers', $claude );

		// VS Code reads .vscode/mcp.json under a "servers" key — proves $client is used.
		$this->assertArrayHasKey( 'servers', $vscode );
		$this->assertArrayNotHasKey( 'mcpServers', $vscode );

		$this->assertNotSame( array_keys( $claude ), array_keys( $vscode ) );
	}

	public function test_windows_variant_still_wraps_cmd_for_a_client(): void {
		$cfg    = json_decode( oversio_client_snippet( 'cursor', 'mcp-agent', 'windows' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}

	public function test_vscode_windows_variant_wraps_cmd_under_servers_key(): void {
		$cfg    = json_decode( oversio_client_snippet( 'vscode', 'mcp-agent', 'windows' ), true );
		$server = $cfg['servers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}
}
