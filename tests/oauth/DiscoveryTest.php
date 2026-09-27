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


	/**
	 * The $_SERVER['HTTPS'] value the discovery fallback tests found, restored in tear_down().
	 *
	 * @var array{set: bool, value: mixed}
	 */
	private array $saved_https = array(
		'set'   => false,
		'value' => null,
	);

	/**
	 * Snapshot $_SERVER['HTTPS'], which the discovery fallback tests change.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->saved_https = array(
			'set'   => array_key_exists( 'HTTPS', $_SERVER ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- snapshot restored verbatim in tear_down().
			'value' => $_SERVER['HTTPS'] ?? null,
		);
	}

	/**
	 * Put $_SERVER['HTTPS'] back and drop the REST server these tests built, so no route set
	 * registered under one OAuth state or permalink structure outlives the test.
	 */
	public function tear_down(): void {
		if ( $this->saved_https['set'] ) {
			$_SERVER['HTTPS'] = $this->saved_https['value'];
		} else {
			unset( $_SERVER['HTTPS'] );
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core REST server, rebuilt on next use.
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * GET a discovery fallback route on a freshly built REST server, so the routes are registered
	 * under the OAuth state and permalink structure the test has just set.
	 *
	 * @param string $suffix Route below the OAuth namespace, with a leading slash.
	 * @return \WP_REST_Response
	 */
	private function get_discovery_route( string $suffix ): \WP_REST_Response {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core REST server, rebuilt so rest_api_init fires again.
		$GLOBALS['wp_rest_server'] = null;
		return rest_do_request( new \WP_REST_Request( 'GET', '/' . aafm_oauth_rest_namespace() . $suffix ) );
	}

	/**
	 * The three fallback routes, each mapped to the builder of the root document it must serve.
	 * Pretty permalinks are set first so the resource URL has a path and the RFC 9728 3.1
	 * path-suffixed form exists.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fallback_routes_with_root_documents(): array {
		$this->set_permalink_structure( '/%postname%/' );
		$resource_path = ltrim( (string) wp_parse_url( aafm_endpoint_url(), PHP_URL_PATH ), '/' );
		$this->assertNotSame( '', $resource_path, 'Pretty permalinks must give the MCP endpoint a path.' );

		return array(
			'/protected-resource'                   => aafm_oauth_protected_resource_metadata(),
			'/protected-resource/' . $resource_path => aafm_oauth_protected_resource_metadata(),
			'/authorization-server'                 => aafm_oauth_authorization_server_metadata(),
		);
	}

	/**
	 * Step 13 (doc 261 A5): with OAuth on, each /wp-json fallback serves exactly the document the
	 * root .well-known handler serves, the same array and the same wp_json_encode() string, with
	 * Cache-Control: no-store as the root's 200 sends. The route paths carry no dot segment, since
	 * the stock nginx dotfile rule refuses /wp-json/.../.well-known/... with a 403.
	 */
	public function test_discovery_fallback_routes_serve_the_root_documents(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$_SERVER['HTTPS'] = 'on';

		foreach ( $this->fallback_routes_with_root_documents() as $suffix => $root_document ) {
			$response = $this->get_discovery_route( $suffix );

			$this->assertSame( 200, $response->get_status(), $suffix );
			$this->assertSame( $root_document, $response->get_data(), $suffix );
			$this->assertSame( wp_json_encode( $root_document ), wp_json_encode( $response->get_data() ), $suffix );
			$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null, $suffix );
		}

		$routes = array_keys( rest_get_server()->get_routes( aafm_oauth_rest_namespace() ) );
		$this->assertContains( '/' . aafm_oauth_rest_namespace() . '/protected-resource', $routes );
		$this->assertContains( '/' . aafm_oauth_rest_namespace() . '/authorization-server', $routes );
		foreach ( $routes as $route ) {
			$this->assertDoesNotMatchRegularExpression( '#/\.#', $route, 'No OAuth route path may carry a dot segment.' );
		}
	}

	/**
	 * OAuth off: the fallback routes are not registered, so REST answers rest_no_route with 404.
	 */
	public function test_discovery_fallback_routes_are_not_registered_while_oauth_is_off(): void {
		delete_option( 'aafm_oauth_enabled' );
		$_SERVER['HTTPS'] = 'on';

		foreach ( array_keys( $this->fallback_routes_with_root_documents() ) as $suffix ) {
			$response = $this->get_discovery_route( $suffix );

			$this->assertSame( 404, $response->get_status(), $suffix );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'] ?? null, $suffix );
		}
	}

	/**
	 * HTTPS required and the request not over SSL: 403 with response data null and no
	 * Cache-Control header, as the root's 403 sends none. Isolated so no AAFM_OAUTH_ALLOW_HTTP
	 * defined by an earlier suite in the same process relaxes the requirement.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_discovery_fallback_refuses_plain_http_when_https_is_required(): void {
		if ( ! aafm_oauth_https_required() ) {
			$this->markTestSkipped( 'HTTPS is not required in this environment; the plain-http gate cannot be exercised.' );
		}
		update_option( 'aafm_oauth_enabled', '1' );
		unset( $_SERVER['HTTPS'] );

		foreach ( array_keys( $this->fallback_routes_with_root_documents() ) as $suffix ) {
			$response = $this->get_discovery_route( $suffix );

			$this->assertSame( 403, $response->get_status(), $suffix );
			$this->assertNull( $response->get_data(), $suffix );
			$this->assertArrayNotHasKey( 'Cache-Control', $response->get_headers(), $suffix );
		}
	}

	/**
	 * The development setting (AAFM_OAUTH_ALLOW_HTTP) relaxes the HTTPS requirement, and the
	 * fallback then serves each document over plain HTTP, as the root does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_discovery_fallback_serves_plain_http_under_the_development_setting(): void {
		if ( ! defined( 'AAFM_OAUTH_ALLOW_HTTP' ) ) {
			define( 'AAFM_OAUTH_ALLOW_HTTP', true );
		}
		$this->assertFalse( aafm_oauth_https_required() );
		update_option( 'aafm_oauth_enabled', '1' );
		unset( $_SERVER['HTTPS'] );

		foreach ( $this->fallback_routes_with_root_documents() as $suffix => $root_document ) {
			$response = $this->get_discovery_route( $suffix );

			$this->assertSame( 200, $response->get_status(), $suffix );
			$this->assertSame( $root_document, $response->get_data(), $suffix );
			$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null, $suffix );
		}
	}

	/**
	 * The REST index advertises the path-suffixed route by its own key, as a self link. The key is
	 * the literal path (preg_quote() alone would turn each '-' into '\-'), so the advertised link
	 * answers with the document instead of a 404 (ledger b5c2r1-security-2, b5c2r1-code-2).
	 */
	public function test_discovery_fallback_index_advertises_a_self_link_that_answers(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$_SERVER['HTTPS'] = 'on';
		$this->set_permalink_structure( '/%postname%/' );
		$resource_path = ltrim( (string) wp_parse_url( aafm_endpoint_url(), PHP_URL_PATH ), '/' );
		$this->assertStringContainsString( '-', $resource_path, 'Guard: the resource path carries a hyphen.' );

		$route = '/' . aafm_oauth_rest_namespace() . '/protected-resource/' . $resource_path;
		$index = $this->get_discovery_route( '' )->get_data();
		$this->assertArrayHasKey( $route, $index['routes'], 'The index must list the route by its literal path.' );

		$href = $index['routes'][ $route ]['_links']['self'][0]['href'] ?? '';
		$this->assertStringStartsWith( rest_url(), $href );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/' . ltrim( substr( $href, strlen( rest_url() ) ), '/' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( aafm_oauth_protected_resource_metadata(), $response->get_data() );
	}
}
