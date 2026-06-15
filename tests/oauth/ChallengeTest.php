<?php
/**
 * Tests for the OAuth WWW-Authenticate challenge attached to the MCP 401.
 *
 * The transport's 401 stays a plain WP_Error — the bundled adapter discards any
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

	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

		// The 403 IP-block path writes a 'denied' row to the custom activity log.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function tear_down(): void {
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
	 * The transport's unauthenticated 401 is a PLAIN WP_Error: status 401 and no
	 * www_authenticate data key. The adapter would discard such data anyway, so the
	 * header is delivered by the dispatch filter instead. The absence holds whether
	 * or not OAuth is enabled.
	 */
	public function test_unauthenticated_401_is_a_plain_error_when_oauth_enabled(): void {
		wp_set_current_user( 0 );

		$result = aafm_transport_permission_callback( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? 0 );
		$this->assertArrayNotHasKey( 'www_authenticate', $result->get_error_data() );
	}

	/**
	 * With OAuth explicitly disabled the 401 is likewise plain: still no data key.
	 */
	public function test_unauthenticated_401_is_a_plain_error_when_oauth_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		wp_set_current_user( 0 );

		$result = aafm_transport_permission_callback( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? 0 );
		$this->assertArrayNotHasKey( 'www_authenticate', $result->get_error_data() );
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

		$result = aafm_transport_permission_callback( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
		$this->assertArrayNotHasKey( 'www_authenticate', $result->get_error_data() );
	}

	/**
	 * Real path: a 401 on the MCP route, OAuth enabled, gets the WWW-Authenticate
	 * header set by the dispatch filter — derived from route + status, not a data key.
	 */
	public function test_filter_sets_header_on_mcp_401_when_oauth_enabled(): void {
		wp_set_current_user( 0 );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( self::MCP_ROUTE );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertSame( aafm_oauth_challenge_header(), $out->get_headers()['WWW-Authenticate'] ?? '' );
	}

	/**
	 * Negative: a 401 on an unrelated route never gets the header — the filter must
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
	 * Negative: MCP route, 401, but OAuth disabled — no header.
	 */
	public function test_filter_ignores_mcp_401_when_oauth_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		$response = new \WP_REST_Response( null, 401 );
		$request  = $this->request_for_route( self::MCP_ROUTE );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), $request );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $out->get_headers() );
	}

	/**
	 * The filter leaves a non-WP_REST_Response alone — no header is invented and the
	 * value is returned untouched.
	 */
	public function test_filter_ignores_non_rest_response(): void {
		$passthrough = new \WP_HTTP_Response( array( 'ok' => true ), 401 );

		$out = aafm_oauth_filter_rest_challenge( $passthrough, rest_get_server(), $this->request_for_route( self::MCP_ROUTE ) );

		$this->assertSame( $passthrough, $out );
	}

	/**
	 * Integration through the registered hook: an unauthenticated dispatch to the
	 * MCP route yields a 401, and running that 401 through the rest_post_dispatch
	 * filter chain — the exact apply_filters() core fires in WP_REST_Server::serve_request()
	 * — sets the WWW-Authenticate header. This proves the filter is wired on the hook
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
}
