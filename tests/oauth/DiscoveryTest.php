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
	 * The registration_endpoint appears only when OAuth is on AND dynamic client
	 * registration is on - the same pair the register route gates on. DCR is a real toggle
	 * (its own option, on by default), and the aafm_oauth_dcr_enabled filter can still force
	 * it closed while OAuth stays on.
	 */
	public function test_registration_endpoint_requires_oauth_and_dcr(): void {
		// OAuth on, DCR on by default (no stored row): advertised.
		update_option( 'aafm_oauth_enabled', '1' );
		delete_option( 'aafm_oauth_dcr_enabled' );
		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/register', $meta['registration_endpoint'] );

		// OAuth off: never advertised, whatever DCR says.
		update_option( 'aafm_oauth_enabled', '0' );
		update_option( 'aafm_oauth_dcr_enabled', '1' );
		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertArrayNotHasKey( 'registration_endpoint', $meta );

		// OAuth on, DCR toggle off: not advertised (the route would 404).
		update_option( 'aafm_oauth_enabled', '1' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );
		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertArrayNotHasKey( 'registration_endpoint', $meta, 'A stored DCR 0 must hide the endpoint.' );

		// Filter escape hatch: OAuth on, DCR toggle on, but forced closed in code.
		update_option( 'aafm_oauth_dcr_enabled', '1' );
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
	 * OAuth defaults OFF (the public surface is opt-in). Dynamic client registration has
	 * its own toggle and defaults ON, read from the stored aafm_oauth_dcr_enabled option
	 * independently of the OAuth state: on when the row is absent or '1', off when '0'.
	 * The aafm_oauth_dcr_enabled filter wins over the stored value.
	 */
	public function test_oauth_defaults_off_and_dcr_defaults_on(): void {
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		$this->assertFalse( aafm_oauth_enabled(), 'OAuth must be off by default on a fresh install.' );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'DCR must be on by default.' );

		// A stored 0 turns the DCR toggle off, independently of OAuth.
		update_option( 'aafm_oauth_dcr_enabled', '0' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'A stored 0 turns the DCR toggle off.' );

		// Back on, and still unaffected by OAuth being off.
		update_option( 'aafm_oauth_dcr_enabled', '1' );
		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'DCR reads its own toggle, not the OAuth state.' );

		// The filter overrides the stored value.
		add_filter( 'aafm_oauth_dcr_enabled', '__return_false' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'The filter wins over the stored toggle.' );
		remove_filter( 'aafm_oauth_dcr_enabled', '__return_false' );
	}
}
