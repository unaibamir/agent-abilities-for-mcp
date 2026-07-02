<?php
/**
 * Tests for exposing the OAuth challenge and MCP session headers to CORS clients.
 *
 * Browser-context MCP clients (the claude.ai web connector and any fetch()-based
 * agent) read responses through the Fetch/CORS spec, so a response header is only
 * readable when it appears in Access-Control-Expose-Headers, and a request header
 * is only sendable when it appears in Access-Control-Allow-Headers. The OAuth
 * surface adds WWW-Authenticate (the discovery pointer) to the exposed set and the
 * adapter's Mcp-Session-Id / MCP-Protocol-Version to both sets, so the handshake
 * and every follow-up request survive the CORS round trip.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies the rest_exposed_cors_headers and rest_allowed_cors_headers filters add
 * the OAuth + MCP session headers, dedupe, and stay gated on the OAuth toggle.
 */
final class CorsHeadersTest extends TestCase {

	/**
	 * A representative starting header set matching WordPress core's defaults.
	 *
	 * @return array<int, string>
	 */
	private function core_exposed_defaults(): array {
		return array( 'X-WP-Total', 'X-WP-TotalPages', 'Link' );
	}

	/**
	 * The exposed-headers filter adds the OAuth challenge and both MCP session
	 * headers so a browser client can read them off the response.
	 */
	public function test_exposed_headers_include_oauth_and_session_headers(): void {
		$headers = aafm_oauth_filter_exposed_cors_headers( $this->core_exposed_defaults() );

		$this->assertContains( 'WWW-Authenticate', $headers );
		$this->assertContains( 'Mcp-Session-Id', $headers );
		$this->assertContains( 'MCP-Protocol-Version', $headers );
	}

	/**
	 * The core defaults survive - the filter is additive, never a replacement.
	 */
	public function test_exposed_headers_preserve_core_defaults(): void {
		$headers = aafm_oauth_filter_exposed_cors_headers( $this->core_exposed_defaults() );

		$this->assertContains( 'X-WP-Total', $headers );
		$this->assertContains( 'X-WP-TotalPages', $headers );
		$this->assertContains( 'Link', $headers );
	}

	/**
	 * The allowed-headers filter adds the session + protocol headers so a browser
	 * client can send them back on follow-up requests.
	 */
	public function test_allowed_headers_include_session_headers(): void {
		$headers = aafm_oauth_filter_allowed_cors_headers( array( 'Authorization', 'Content-Type' ) );

		$this->assertContains( 'Mcp-Session-Id', $headers );
		$this->assertContains( 'MCP-Protocol-Version', $headers );
		$this->assertContains( 'Authorization', $headers );
		$this->assertContains( 'Content-Type', $headers );
	}

	/**
	 * Neither filter introduces duplicates, even when the header is already present.
	 */
	public function test_no_duplicate_headers(): void {
		$exposed = aafm_oauth_filter_exposed_cors_headers(
			array( 'Mcp-Session-Id', 'WWW-Authenticate', 'X-WP-Total' )
		);
		$this->assertSame( array_values( array_unique( $exposed ) ), $exposed );
		$this->assertSame( 1, count( array_keys( $exposed, 'Mcp-Session-Id', true ) ) );
		$this->assertSame( 1, count( array_keys( $exposed, 'WWW-Authenticate', true ) ) );

		$allowed = aafm_oauth_filter_allowed_cors_headers(
			array( 'Mcp-Session-Id', 'Authorization' )
		);
		$this->assertSame( array_values( array_unique( $allowed ) ), $allowed );
		$this->assertSame( 1, count( array_keys( $allowed, 'Mcp-Session-Id', true ) ) );
	}

	/**
	 * Both filters return a list keyed from zero - Access-Control header builders
	 * implode the values, but a clean integer-indexed list is the contract.
	 */
	public function test_filters_return_reindexed_lists(): void {
		$exposed = aafm_oauth_filter_exposed_cors_headers( $this->core_exposed_defaults() );
		$this->assertSame( array_values( $exposed ), $exposed );

		$allowed = aafm_oauth_filter_allowed_cors_headers( array( 'Authorization' ) );
		$this->assertSame( array_values( $allowed ), $allowed );
	}

	/**
	 * The filters are registered on the WordPress core hooks, gated on the OAuth
	 * toggle, so the live Access-Control headers carry the additions.
	 */
	public function test_filters_are_registered_when_oauth_enabled(): void {
		$this->assertNotFalse(
			has_filter( 'rest_exposed_cors_headers', 'aafm_oauth_filter_exposed_cors_headers' )
		);
		$this->assertNotFalse(
			has_filter( 'rest_allowed_cors_headers', 'aafm_oauth_filter_allowed_cors_headers' )
		);
	}
}
