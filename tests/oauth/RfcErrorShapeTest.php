<?php
/**
 * Regression tests locking the OAuth RFC 6749 error wire contract (issue #68).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Every OAuth error test before this file asserted only get_status(), which is exactly how a
 * WP_Error-shaped body shipped undetected: a status code can be right while the body is
 * unreadable to any real OAuth client. These tests dispatch through rest_do_request() and
 * assert the actual response body: the RFC 6749 section 5.2 {error, error_description} shape
 * on protocol errors, the section 5.1 no-cache headers, and the deliberate WordPress-shaped
 * 404 exception carved out for a switched-off endpoint (see D1 in RestEndpointsTest.php).
 */
class RfcErrorShapeTest extends TestCase {

	/**
	 * Ensure the OAuth tables exist and the REST routes are registered.
	 */
	public function set_up(): void {
		parent::set_up();

		// The REST dispatch path reports a production environment, so relax the HTTPS
		// requirement the way a local agent-dev operator would, the same override
		// RestEndpointsTest uses, so the handlers run over the test's plain-HTTP request.
		if ( ! defined( 'AAFM_OAUTH_ALLOW_HTTP' ) ) {
			define( 'AAFM_OAUTH_ALLOW_HTTP', true );
		}

		aafm_install_oauth_tables();

		update_option( 'aafm_oauth_enabled', '1' );
		update_option( 'aafm_oauth_dcr_enabled', '1' );

		aafm_install_activity_log();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook fired to populate the REST server in the test.
		do_action( 'rest_api_init' );
	}

	/**
	 * Assert a protocol-error response body is RFC 6749 section 5.2 shaped: an `error` key
	 * matching the expected code, a non-empty `error_description`, none of WordPress's own
	 * WP_Error keys, and both RFC 6749 section 5.1 no-cache headers (every protocol error
	 * routes through aafm_oauth_rest_no_store()).
	 *
	 * @param \WP_REST_Response   $response      The dispatched response.
	 * @param array<string,mixed> $data          The response's decoded data.
	 * @param string              $expected_code The expected `error` value.
	 * @return void
	 */
	private function assert_rfc6749_error_shape( WP_REST_Response $response, array $data, string $expected_code ): void {
		$this->assertArrayHasKey(
			'error',
			$data,
			'Response body keys were: ' . wp_json_encode( array_keys( $data ) ) . ' (has the RFC 6749 shape reverted to a WP_Error?)'
		);
		$this->assertSame( $expected_code, $data['error'] );

		$this->assertArrayHasKey(
			'error_description',
			$data,
			'Response body keys were: ' . wp_json_encode( array_keys( $data ) )
		);
		$this->assertIsString( $data['error_description'] );
		$this->assertNotSame( '', $data['error_description'] );

		// The exact assertion that would have caught issue #68: a WP_Error-shaped body
		// would carry these keys instead of error/error_description.
		$this->assertArrayNotHasKey( 'code', $data );
		$this->assertArrayNotHasKey( 'message', $data );
		$this->assertArrayNotHasKey( 'data', $data );

		$headers = $response->get_headers();
		$this->assertArrayHasKey(
			'Cache-Control',
			$headers,
			'Response headers were: ' . wp_json_encode( array_keys( $headers ) )
		);
		$this->assertSame( 'no-store', $headers['Cache-Control'] );

		$this->assertArrayHasKey(
			'Pragma',
			$headers,
			'Response headers were: ' . wp_json_encode( array_keys( $headers ) )
		);
		$this->assertSame( 'no-cache', $headers['Pragma'] );
	}

	/**
	 * The exact reproduction from issue #68: a refresh_token grant with a known-invalid
	 * placeholder token returns HTTP 400 and the RFC 6749 {error, error_description} body,
	 * not WordPress's {code, message, data} WP_Error shape.
	 */
	public function test_invalid_refresh_grant_returns_rfc6749_error_shape(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'not-a-real-refresh-token',
				'client_id'     => 'not-a-real-client',
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assert_rfc6749_error_shape( $response, $response->get_data(), 'invalid_grant' );
	}

	/**
	 * An invalid authorization_code grant returns the same RFC 6749 shape.
	 */
	public function test_invalid_authorization_code_grant_returns_rfc6749_error_shape(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => 'not-a-real-code',
				'redirect_uri'  => 'https://app.example/cb',
				'client_id'     => 'not-a-real-client',
				'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assert_rfc6749_error_shape( $response, $response->get_data(), 'invalid_grant' );
	}

	/**
	 * An unsupported grant_type returns the same RFC 6749 shape.
	 */
	public function test_unsupported_grant_type_returns_rfc6749_error_shape(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params( array( 'grant_type' => 'password' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assert_rfc6749_error_shape( $response, $response->get_data(), 'unsupported_grant_type' );
	}

	/**
	 * Registration's over-long client_name rejection returns the same RFC 6749 shape.
	 */
	public function test_register_overlong_client_name_returns_rfc6749_error_shape(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => str_repeat( 'n', 5000 ),
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assert_rfc6749_error_shape( $response, $response->get_data(), 'invalid_client_metadata' );
	}

	/**
	 * Revocation's rate-limited path returns the same RFC 6749 shape. It is the only
	 * protocol error /revoke can reach in a test: an unknown or invalid token is a silent
	 * no-op by RFC 7009 design, so rate limiting is the one condition left that answers
	 * with an actual error. Pre-fills the same per-IP counter aafm_oauth_rest_revoke() reads
	 * so a single rest_do_request() call is the one that trips the cap, rather than
	 * dispatching 30 real requests through the REST server to get there.
	 */
	public function test_revoke_rate_limited_returns_rfc6749_error_shape(): void {
		// 30 matches the per-IP cap aafm_oauth_rest_revoke() passes to aafm_oauth_rate_ok().
		// If that cap ever changes, this loop must move with it.
		for ( $i = 0; $i < 30; $i++ ) {
			aafm_oauth_rate_ok( 'revoke', 30, 300 );
		}

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/revoke' );
		$request->set_body_params( array( 'token' => 'irrelevant' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assert_rfc6749_error_shape( $response, $response->get_data(), 'rate_limited' );

		// RFC 9110 §10.2.3: a 429 should tell the client how long to wait.
		$headers = $response->get_headers();
		$this->assertArrayHasKey(
			'Retry-After',
			$headers,
			'Response headers were: ' . wp_json_encode( array_keys( $headers ) )
		);
		$this->assertIsNumeric( $headers['Retry-After'] );
		$this->assertGreaterThan( 0, (int) $headers['Retry-After'] );
	}

	/**
	 * The protocol-error helper always returns a WP_REST_Response, never a WP_Error, with
	 * exactly {error, error_description} as its data and the status passed in.
	 */
	public function test_protocol_error_helper_returns_rest_response_with_exact_shape(): void {
		$response = aafm_oauth_rest_protocol_error( 'some_error_code', 'Some description.', 418 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();
		$this->assertSame( array( 'error', 'error_description' ), array_keys( $data ) );
		$this->assertSame( 'some_error_code', $data['error'] );
		$this->assertSame( 'Some description.', $data['error_description'] );

		$this->assertSame( 418, $response->get_status() );
	}

	/**
	 * Dispatch a POST carrying a syntactically broken JSON body and return the request
	 * alongside the response core's own has_valid_params() produces, before any route
	 * callback runs - this is the failure mode a route callback's own validation can
	 * never intercept.
	 *
	 * Dispatch() (which rest_do_request() calls) never applies the rest_post_dispatch
	 * filter itself - that only happens in WP_REST_Server::serve_request(), the real
	 * HTTP entry point, confirmed by the fact that this genuinely reproduces core's
	 * unfiltered rest_invalid_json shape here even with aafm_oauth_filter_malformed_json()
	 * registered. So every test below feeds this real, unfiltered response through the
	 * filter directly, the same convention ChallengeTest.php already uses for
	 * aafm_oauth_filter_rest_challenge(), rather than expecting rest_do_request() to have
	 * applied it.
	 *
	 * @param string $route The REST route to dispatch to.
	 * @return array{0: WP_REST_Request, 1: WP_REST_Response} The request and core's raw response.
	 */
	private function dispatch_malformed_json( string $route ): array {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"not": "valid json"' ); // Missing closing brace.

		return array( $request, rest_do_request( $request ) );
	}

	/**
	 * Malformed JSON to /token is caught by aafm_oauth_filter_malformed_json() and
	 * reshaped into the same RFC 6749 error contract as every other protocol error on
	 * this route, rather than leaking core's {code, message, data} shape.
	 */
	public function test_malformed_json_to_token_returns_rfc6749_error_shape(): void {
		list( $request, $raw ) = $this->dispatch_malformed_json( '/agent-abilities-for-mcp/oauth/token' );
		$filtered              = aafm_oauth_filter_malformed_json( $raw, rest_get_server(), $request );

		$this->assertSame( 400, $filtered->get_status() );
		$this->assert_rfc6749_error_shape( $filtered, $filtered->get_data(), 'invalid_request' );
	}

	/**
	 * Malformed JSON to /register gets the same treatment. This route matters most:
	 * RFC 7591 registration is JSON-only, so a syntax error here has no other path back
	 * to a body a DCR client can parse.
	 */
	public function test_malformed_json_to_register_returns_rfc6749_error_shape(): void {
		list( $request, $raw ) = $this->dispatch_malformed_json( '/agent-abilities-for-mcp/oauth/register' );
		$filtered              = aafm_oauth_filter_malformed_json( $raw, rest_get_server(), $request );

		$this->assertSame( 400, $filtered->get_status() );
		$this->assert_rfc6749_error_shape( $filtered, $filtered->get_data(), 'invalid_request' );
	}

	/**
	 * The critical negative case: the same malformed body sent to an unrelated core
	 * route must come back completely untouched, still carrying core's own
	 * rest_invalid_json WP_Error shape with no RFC 6749 fields and no cache headers.
	 * rest_post_dispatch fires for every REST request on the site, not only ours, so
	 * this is the test that proves the filter has not started rewriting core's or
	 * another plugin's JSON errors.
	 */
	public function test_malformed_json_to_unrelated_core_route_is_untouched(): void {
		list( $request, $raw ) = $this->dispatch_malformed_json( '/wp/v2/posts' );
		$filtered              = aafm_oauth_filter_malformed_json( $raw, rest_get_server(), $request );

		$this->assertSame( 400, $filtered->get_status() );

		$data = $filtered->get_data();
		$this->assertSame( 'rest_invalid_json', $data['code'] );
		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertArrayNotHasKey( 'error_description', $data );

		$headers = $filtered->get_headers();
		$this->assertArrayNotHasKey( 'Cache-Control', $headers );
		$this->assertArrayNotHasKey( 'Pragma', $headers );
	}

	/**
	 * A well-formed registration request's 201 success is unaffected by the filter: it
	 * never matches the 400-status, rest_invalid_json-code gate the filter checks, so
	 * feeding it through returns the exact same response.
	 */
	public function test_valid_register_response_is_unaffected_by_malformed_json_filter(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => 'Valid Client',
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status(), 'client registration should succeed' );

		$filtered = aafm_oauth_filter_malformed_json( $response, rest_get_server(), $request );
		$this->assertSame( $response, $filtered );
	}
}
