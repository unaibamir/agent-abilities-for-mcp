<?php
/**
 * Tests for the OAuth WWW-Authenticate challenge attached to the MCP 401.
 *
 * The transport's 401 stays a plain WP_Error - the bundled adapter discards any
 * WP_Error data before WordPress dispatches the response, so the challenge cannot
 * ride on a data key. Instead the rest_post_dispatch filter re-derives the
 * condition (OAuth on, 401, MCP route) and sets the header on the live response.
 * The 403 IP-block branch and the authenticated path stay byte-for-byte as before.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies the challenge header builder, the plain 401 the transport returns, and
 * the rest_post_dispatch filter that sets the header by re-deriving the condition
 * from the request/response context (route + status), not from a surviving data key.
 */
final class ChallengeTest extends TestCase {

	/**
	 * The full REST route of the MCP server, mirroring create_server() in
	 * includes/server.php: namespace 'agent-abilities-for-mcp' + route 'mcp'.
	 *
	 * @var string
	 */
	private const MCP_ROUTE = '/agent-abilities-for-mcp/mcp';

	/**
	 * Saved REMOTE_ADDR so the 403 test restores the fixture's request environment.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	/**
	 * $_SERVER as set_up() found it, for the bearer tests that set headers and HTTPS.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

		// OAuth is OFF by default now; the challenge filter early-returns when disabled, so
		// enable it for the header tests. The disabled-path tests set it back to '0'.
		update_option( 'aafm_oauth_enabled', '1' );
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		aafm_oauth_invalid_bearer( false );
		$this->set_permalink_structure( '' );
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}
		parent::tear_down();
	}

	/**
	 * Build a WP_REST_Request carrying a given route, for driving the filter the
	 * way WordPress does at rest_post_dispatch.
	 *
	 * @param string $route Full REST route, e.g. '/agent-abilities-for-mcp/mcp'.
	 * @return \WP_REST_Request<array<string,mixed>>
	 */
	private function request_for_route( string $route ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_route( $route );
		return $request;
	}

	/**
	 * The challenge header is a Bearer scheme pointing at the protected-resource
	 * metadata document under .well-known on this site.
	 */
	public function test_challenge_header_value(): void {
		$header = aafm_oauth_challenge_header();

		$this->assertStringStartsWith( 'Bearer ', $header );
		$this->assertStringContainsString( 'resource_metadata=', $header );
		$this->assertStringEndsWith( '/.well-known/oauth-protected-resource"', $header );
		$this->assertSame(
			'Bearer resource_metadata="' . home_url( '/.well-known/oauth-protected-resource' ) . '"',
			$header
		);
	}

	/**
	 * The transport's unauthenticated refusal is a plain false: core turns it into a 401 and no
	 * www_authenticate data key exists. The adapter would discard such data anyway, so the
	 * header is delivered by the dispatch filter instead. The absence holds whether
	 * or not OAuth is enabled.
	 */
	public function test_unauthenticated_401_is_a_plain_error_when_oauth_enabled(): void {
		wp_set_current_user( 0 );

		$request = new \WP_REST_Request();
		$this->assertFalse( aafm_transport_permission_callback( $request ), 'A plain false: core answers 401 and the header comes from the dispatch filter.' );
		$this->assertSame( 'unauthenticated', aafm_transport_refusal( $request ) );
	}

	/**
	 * With OAuth explicitly disabled the 401 is likewise plain: still no data key.
	 */
	public function test_unauthenticated_401_is_a_plain_error_when_oauth_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		wp_set_current_user( 0 );

		$request = new \WP_REST_Request();
		$this->assertFalse( aafm_transport_permission_callback( $request ), 'A plain false: core answers 401 and the header comes from the dispatch filter.' );
		$this->assertSame( 'unauthenticated', aafm_transport_refusal( $request ) );
	}

	/**
	 * The 403 IP-block branch is untouched: it carries no challenge regardless of
	 * the OAuth toggle. Locks the frozen invariant.
	 */
	public function test_ip_blocked_403_carries_no_challenge(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $uid );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );

		$request = new \WP_REST_Request();
		$this->assertFalse( aafm_transport_permission_callback( $request ), 'A plain false carries no challenge data at all.' );
		$this->assertSame( 'ip_blocked', aafm_transport_refusal( $request ) );
	}

	/**
	 * Real path: a 401 on the MCP route, OAuth enabled, gets the WWW-Authenticate
	 * header set by the dispatch filter - derived from route + status, not a data key.
	 */
	public function test_filter_sets_header_on_mcp_401_when_oauth_enabled(): void {
		wp_set_current_user( 0 );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( self::MCP_ROUTE );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertSame( aafm_oauth_challenge_header(), $out->get_headers()['WWW-Authenticate'] ?? '' );
	}

	/**
	 * Core dispatches a route with one trailing newline to the MCP handler, so its 401 carries the
	 * challenge as the plain route's does.
	 */
	public function test_filter_sets_header_on_a_401_for_the_newline_route(): void {
		wp_set_current_user( 0 );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( self::MCP_ROUTE . "\n" );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertSame( aafm_oauth_challenge_header(), $out->get_headers()['WWW-Authenticate'] ?? '' );
	}

	/**
	 * Negative: a 401 on an unrelated route never gets the header - the filter must
	 * not slap the beacon on every 401 across the site.
	 */
	public function test_filter_ignores_401_on_non_mcp_route(): void {
		wp_set_current_user( 0 );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( '/wp/v2/posts' );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $out->get_headers() );
	}

	/**
	 * Negative: a logged-in-but-unauthorized request on the MCP route yields 403, and
	 * a 403 must not get the challenge.
	 */
	public function test_filter_ignores_403_on_mcp_route(): void {
		$response = new \WP_REST_Response( null, 403 );
		$request  = $this->request_for_route( self::MCP_ROUTE );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $out->get_headers() );
	}

	/**
	 * Negative: MCP route, 401, but OAuth disabled - no header.
	 */
	public function test_filter_ignores_mcp_401_when_oauth_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( self::MCP_ROUTE );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $out->get_headers() );
	}

	/**
	 * The filter leaves a non-WP_REST_Response alone - no header is invented and the
	 * value is returned untouched.
	 */
	public function test_filter_ignores_non_rest_response(): void {
		$passthrough = new \WP_HTTP_Response( array( 'ok' => true ), 401 );

		$out = aafm_oauth_filter_rest_challenge( $passthrough, rest_get_server(), $this->request_for_route( self::MCP_ROUTE ) );

		$this->assertSame( $passthrough, $out );
	}

	/**
	 * The route match must be case-insensitive, mirroring how core itself matches REST
	 * routes (class-wp-rest-server.php builds its route regex with the `i` modifier) and
	 * how the sibling aafm_oauth_filter_malformed_json() already matches its own route
	 * family. A differently-cased request must still get the discovery pointer.
	 */
	public function test_filter_sets_header_on_a_differently_cased_mcp_route(): void {
		wp_set_current_user( 0 );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( strtoupper( self::MCP_ROUTE ) );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertSame(
			aafm_oauth_challenge_header(),
			$out->get_headers()['WWW-Authenticate'] ?? '',
			'A differently-cased MCP route must still get the discovery pointer.'
		);
	}

	/**
	 * The negative case that proves the case-insensitive match did not become an
	 * over-broad one: a non-MCP route, differently cased or not, still gets nothing.
	 */
	public function test_filter_still_ignores_a_non_mcp_route_regardless_of_case(): void {
		wp_set_current_user( 0 );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( strtoupper( '/wp/v2/posts' ) );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertArrayNotHasKey(
			'WWW-Authenticate',
			$out->get_headers(),
			'A non-MCP route must never get the challenge. Loosening the match must not over-reach.'
		);
	}

	/**
	 * Integration through the registered hook: an unauthenticated dispatch to the
	 * MCP route yields a 401, and running that 401 through the rest_post_dispatch
	 * filter chain - the exact apply_filters() core fires in WP_REST_Server::serve_request()
	 * - sets the WWW-Authenticate header. This proves the filter is wired on the hook
	 * in the plugin bootstrap (not just callable in isolation) and that it matches the
	 * live route. (rest_do_request() itself does not apply rest_post_dispatch, so the
	 * filter chain is invoked the way the real HTTP entry point does.)
	 *
	 * Skipped if the MCP route is not registered in this environment; the direct-filter
	 * route-matching tests above remain the deterministic coverage.
	 */
	public function test_dispatch_then_post_dispatch_filter_sets_header_on_mcp_401(): void {
		$routes = rest_get_server()->get_routes();
		if ( ! isset( $routes[ self::MCP_ROUTE ] ) ) {
			$this->markTestSkipped( 'MCP route not registered in the unit env; covered by direct-filter tests.' );
		}

		wp_set_current_user( 0 );
		$request  = $this->request_for_route( self::MCP_ROUTE );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook; we are firing the exact filter WP_REST_Server::serve_request() applies.
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );

		$this->assertSame( aafm_oauth_challenge_header(), $response->get_headers()['WWW-Authenticate'] ?? '' );
	}

	/**
	 * Put a request on the MCP route over HTTPS with OAuth tables, one active client and an approved
	 * grant, and mint a token for it.
	 *
	 * @return string The raw access token.
	 */
	private function mint_mcp_token(): string {
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . self::MCP_ROUTE;
		$this->route_as_rest_request();
		$_SERVER['HTTPS'] = 'on';
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_challenge',
				'client_name' => 'Challenge',
				'is_active'   => 1,
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $user,
				'client_id'  => 'client_challenge',
			)
		);
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $user,
				'client_id'  => 'client_challenge',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		return $tokens['access_token'];
	}

	/**
	 * Resolve the request's credentials the way determine_current_user does, then run an MCP 401
	 * through the challenge filter.
	 *
	 * @param string|null $authorization The Authorization header, or null for none.
	 * @param string      $route         The REST route of the 401.
	 * @return array<string,string> The response headers.
	 */
	private function challenge_after( ?string $authorization, string $route = self::MCP_ROUTE ): array {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		if ( null !== $authorization ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
		}
		$this->assertFalse( aafm_oauth_resolve_current_user( false ), 'The credential must not resolve a user.' );
		$out = aafm_oauth_filter_rest_challenge( new \WP_REST_Response( null, 401 ), rest_get_server(), $this->request_for_route( $route ) );
		return $out->get_headers();
	}

	public function test_expired_token_challenge_has_invalid_token_error(): void {
		$token = $this->mint_mcp_token();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_access_tokens', array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		$headers = $this->challenge_after( 'Bearer ' . $token );

		$this->assertSame( aafm_oauth_challenge_header() . ', error="invalid_token"', $headers['WWW-Authenticate'] ?? '' );
		$this->assertStringNotContainsString( $token, $headers['WWW-Authenticate'] ?? '', 'The challenge never echoes the credential.' );
	}

	public function test_revoked_token_challenge_has_invalid_token_error(): void {
		$token = $this->mint_mcp_token();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_access_tokens', array( 'is_active' => 0 ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		$this->assertSame( aafm_oauth_challenge_header( true ), $this->challenge_after( 'Bearer ' . $token )['WWW-Authenticate'] ?? '' );
	}

	public function test_a_token_of_a_deactivated_client_gets_invalid_token_error(): void {
		$token = $this->mint_mcp_token();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_clients', array( 'is_active' => 0 ), array( 'client_id' => 'client_challenge' ) );

		$this->assertSame( aafm_oauth_challenge_header( true ), $this->challenge_after( 'Bearer ' . $token )['WWW-Authenticate'] ?? '' );
	}

	public function test_no_credentials_keeps_plain_challenge(): void {
		$token = $this->mint_mcp_token();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_access_tokens', array( 'is_active' => 0 ), array( 'token_hash' => hash( 'sha256', $token ) ) );
		// A failed bearer earlier in the same process must not leak into the next resolution.
		$this->challenge_after( 'Bearer ' . $token );

		$headers = $this->challenge_after( null );

		$this->assertSame( aafm_oauth_challenge_header(), $headers['WWW-Authenticate'] ?? '' );
		$this->assertStringNotContainsString( 'error=', $headers['WWW-Authenticate'] ?? '' );
	}

	public function test_application_password_credentials_keep_plain_challenge(): void {
		$this->mint_mcp_token();

		$headers = $this->challenge_after( 'Basic ' . base64_encode( 'nobody:wrong pass word' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the Basic scheme is base64 by definition.

		$this->assertSame( aafm_oauth_challenge_header(), $headers['WWW-Authenticate'] ?? '' );
	}

	public function test_non_mcp_route_challenge_unchanged(): void {
		$token = $this->mint_mcp_token();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_access_tokens', array( 'is_active' => 0 ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $this->challenge_after( 'Bearer ' . $token, '/wp/v2/posts' ) );
	}

	public function test_the_failed_bearer_flag_is_cleared_at_shutdown(): void {
		$this->assertNotFalse( has_action( 'shutdown', 'aafm_oauth_reset_current_client_id' ) );
		aafm_oauth_invalid_bearer( true );
		aafm_oauth_reset_current_client_id();
		$this->assertFalse( aafm_oauth_invalid_bearer() );
	}
}
