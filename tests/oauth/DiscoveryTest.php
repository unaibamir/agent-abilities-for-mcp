<?php
/**
 * Tests for the OAuth discovery metadata builders and well-known routing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies the protected-resource and authorization-server metadata documents
 * and the .well-known path matcher used to route discovery requests.
 */
class DiscoveryTest extends TestCase {

	/**
	 * Protected-resource metadata advertises the MCP endpoint and this site as the
	 * authorization server, with bearer tokens carried in the Authorization header.
	 */
	public function test_protected_resource_metadata_shape(): void {
		$meta = aafm_oauth_protected_resource_metadata();

		$this->assertSame( aafm_endpoint_url(), $meta['resource'] );
		$this->assertSame( array( home_url() ), $meta['authorization_servers'] );
		$this->assertSame( array( 'header' ), $meta['bearer_methods_supported'] );
	}

	/**
	 * Authorization-server metadata advertises PKCE S256, the supported grant and
	 * response types, public-client auth, and the three OAuth REST endpoints.
	 */
	public function test_authorization_server_metadata_shape(): void {
		$meta = aafm_oauth_authorization_server_metadata();

		$this->assertSame( array( 'S256' ), $meta['code_challenge_methods_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $meta['grant_types_supported'] );
		$this->assertSame( array( 'code' ), $meta['response_types_supported'] );
		$this->assertSame( array( 'none' ), $meta['token_endpoint_auth_methods_supported'] );

		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/token', $meta['token_endpoint'] );
		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/register', $meta['registration_endpoint'] );
		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/revoke', $meta['revocation_endpoint'] );
	}

	/**
	 * The path matcher maps both well-known documents, with or without a leading
	 * slash, and returns the empty string for anything else.
	 */
	public function test_match_well_known_routes(): void {
		$this->assertSame( 'protected-resource', aafm_oauth_match_well_known( '/.well-known/oauth-protected-resource' ) );
		$this->assertSame( 'protected-resource', aafm_oauth_match_well_known( '.well-known/oauth-protected-resource' ) );
		$this->assertSame( 'authorization-server', aafm_oauth_match_well_known( '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( 'authorization-server', aafm_oauth_match_well_known( '.well-known/oauth-authorization-server' ) );

		$this->assertSame( '', aafm_oauth_match_well_known( '/wp-json/foo' ) );
		$this->assertSame( '', aafm_oauth_match_well_known( '' ) );

		// Exact-anchoring guard: adversarial paths that merely contain a well-known
		// document name must never match. Locks the matcher against path confusion.
		$this->assertSame( '', aafm_oauth_match_well_known( '.well-known/oauth-authorization-server/evil' ) );
		$this->assertSame( '', aafm_oauth_match_well_known( '/foo/.well-known/oauth-authorization-server' ) );
		$this->assertSame( '', aafm_oauth_match_well_known( '/.well-known/oauth-authorization-server/' ) );
		$this->assertSame( '', aafm_oauth_match_well_known( '/.well-known/oauth-authorization-serverXYZ' ) );
	}

	/**
	 * Both feature toggles default to enabled when their options are unset.
	 */
	public function test_toggles_default_to_enabled(): void {
		$this->assertTrue( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled() );
	}
}
