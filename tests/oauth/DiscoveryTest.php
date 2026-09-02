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
	 * response types, public-client auth, and the token/revocation OAuth REST
	 * endpoints, regardless of the DCR toggle.
	 */
	public function test_authorization_server_metadata_shape(): void {
		$meta = aafm_oauth_authorization_server_metadata();

		$this->assertSame( array( 'S256' ), $meta['code_challenge_methods_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $meta['grant_types_supported'] );
		$this->assertSame( array( 'code' ), $meta['response_types_supported'] );
		$this->assertSame( array( 'none' ), $meta['token_endpoint_auth_methods_supported'] );

		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/token', $meta['token_endpoint'] );
		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/revoke', $meta['revocation_endpoint'] );
	}

	/**
	 * DCR follows OAuth: registration_endpoint appears whenever OAuth is enabled and
	 * disappears when OAuth is off (the route 404s then, so advertising a dead endpoint
	 * would mislead clients). The legacy aafm_oauth_dcr_enabled option is no longer read;
	 * the aafm_oauth_dcr_enabled filter is the one escape hatch that can force it closed
	 * while OAuth stays on.
	 */
	public function test_registration_endpoint_follows_oauth_and_the_filter(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/register', $meta['registration_endpoint'] );

		update_option( 'aafm_oauth_enabled', '0' );
		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertArrayNotHasKey( 'registration_endpoint', $meta );

		// A stale legacy DCR option does not resurrect the endpoint while OAuth is off.
		update_option( 'aafm_oauth_dcr_enabled', '1' );
		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertArrayNotHasKey( 'registration_endpoint', $meta, 'The legacy DCR option must not be read.' );

		// Filter escape hatch: OAuth on, but an operator forces registration closed.
		update_option( 'aafm_oauth_enabled', '1' );
		add_filter( 'aafm_oauth_dcr_enabled', '__return_false' );
		$meta = aafm_oauth_authorization_server_metadata();
		remove_filter( 'aafm_oauth_dcr_enabled', '__return_false' );
		$this->assertArrayNotHasKey( 'registration_endpoint', $meta, 'The filter must be able to force DCR off while OAuth is on.' );
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
	 * RFC 9728 3.1: when the protected resource identifier has a path, the metadata document is
	 * ALSO discoverable at a URL formed by inserting the well-known path segment between the
	 * authority and that path - not only at the bare root form. Both routes must serve the SAME
	 * document, so a strict client checking the identity match ("resource" equals the URL it
	 * fetched the document from) succeeds either way it looks.
	 */
	public function test_match_well_known_also_matches_the_rfc9728_path_suffixed_form(): void {
		$resource_path = ltrim( (string) wp_parse_url( aafm_endpoint_url(), PHP_URL_PATH ), '/' );

		$this->assertSame(
			'protected-resource',
			aafm_oauth_match_well_known( '/.well-known/oauth-protected-resource/' . $resource_path )
		);
		$this->assertSame(
			'protected-resource',
			aafm_oauth_match_well_known( '.well-known/oauth-protected-resource/' . $resource_path )
		);

		// A DIFFERENT path appended must not match - only the resource's own path earns the suffix.
		$this->assertSame( '', aafm_oauth_match_well_known( '.well-known/oauth-protected-resource/not-the-mcp-route' ) );
	}

	/**
	 * OAuth defaults to DISABLED when its option is unset: the public OAuth surface is
	 * opt-in, never a fresh-install default. DCR follows OAuth rather than carrying its
	 * own toggle, so it is off exactly when OAuth is off and on exactly when OAuth is on,
	 * regardless of any stored legacy aafm_oauth_dcr_enabled value.
	 */
	public function test_oauth_defaults_off_and_dcr_follows_it(): void {
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		$this->assertFalse( aafm_oauth_enabled(), 'OAuth must be off by default on a fresh install.' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'DCR must be off when OAuth is off.' );

		// Turning OAuth on turns DCR on in lockstep - no separate DCR option to set.
		update_option( 'aafm_oauth_enabled', '1' );
		$this->assertTrue( aafm_oauth_enabled(), 'A stored 1 must read on (existing installs preserved).' );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'DCR follows OAuth: on when OAuth is on.' );

		// A stored legacy DCR value cannot flip DCR against the OAuth state either way.
		update_option( 'aafm_oauth_enabled', '0' );
		update_option( 'aafm_oauth_dcr_enabled', '1' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'The legacy DCR option is ignored; DCR stays off while OAuth is off.' );
	}
}
