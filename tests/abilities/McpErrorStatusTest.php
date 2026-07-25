<?php
/**
 * The MCP route's rest_post_dispatch filter that stops four specific JSON-RPC "not
 * found" errors from being reported as a dead session.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The adapter's McpErrorFactory::mcp_error_to_http_status() maps five JSON-RPC error
 * codes to HTTP 404 in one bucket: -32601, -32002, -32003, -32004, and -32005. Only the
 * last, session-not-found, is genuinely a "start over" signal per the MCP spec; the other
 * four are ordinary application errors an agent can read and correct within the same
 * session. aafm_mcp_filter_governed_error_status() rewrites exactly those four to 200 and
 * leaves -32005 (and everything else) alone.
 *
 * Every test here calls the filter function directly rather than dispatching through
 * rest_do_request(): rest_post_dispatch is never applied on that code path (only
 * WP_REST_Server::serve_request(), the real HTTP entry point, applies it), the same fact
 * RfcErrorShapeTest.php and ChallengeTest.php already work around for their own
 * rest_post_dispatch filters.
 */
final class McpErrorStatusTest extends TestCase {

	/**
	 * Build a JSON-RPC error response body in the exact shape
	 * JSONRPCErrorResponse::toArray() produces: {jsonrpc, id, error: {code, message}}.
	 *
	 * @param int        $code The JSON-RPC error code.
	 * @param string|int $id   The echoed request id.
	 * @return array<string,mixed>
	 */
	private function jsonrpc_error_body( int $code, $id = 1 ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => 'Some error message.',
			),
		);
	}

	/**
	 * Build a request against the MCP route.
	 *
	 * @return WP_REST_Request
	 */
	private function mcp_request(): WP_REST_Request {
		return new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
	}

	/**
	 * Run the filter with a real WP_REST_Server instance, matching the signature
	 * rest_post_dispatch actually calls it with.
	 *
	 * @param mixed           $response The response to filter.
	 * @param WP_REST_Request $request  The originating request.
	 * @return mixed
	 */
	private function filter( $response, WP_REST_Request $request ) {
		return aafm_mcp_filter_governed_error_status( $response, rest_get_server(), $request );
	}

	/**
	 * A tool that does not exist (or a real tool the operator has not enabled - never
	 * registered, so the lookup fails identically) returns TOOL_NOT_FOUND. This is
	 * rewritten to 200, and the error code plus echoed id survive untouched.
	 */
	public function test_tool_not_found_is_rewritten_to_200(): void {
		$body     = $this->jsonrpc_error_body( -32003, 'req-1' );
		$response = new WP_REST_Response( $body, 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( -32003, $out->get_data()['error']['code'] );
		$this->assertSame( 'req-1', $out->get_data()['id'] );
	}

	/**
	 * An unknown JSON-RPC method (METHOD_NOT_FOUND, -32601) is rewritten the same way.
	 */
	public function test_method_not_found_is_rewritten_to_200(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32601 ), 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( -32601, $out->get_data()['error']['code'] );
	}

	/**
	 * RESOURCE_NOT_FOUND (-32002) is rewritten the same way.
	 */
	public function test_resource_not_found_is_rewritten_to_200(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32002 ), 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( -32002, $out->get_data()['error']['code'] );
	}

	/**
	 * PROMPT_NOT_FOUND (-32004) is rewritten the same way.
	 */
	public function test_prompt_not_found_is_rewritten_to_200(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32004 ), 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( -32004, $out->get_data()['error']['code'] );
	}

	/**
	 * The single most important test in this file: SESSION_NOT_FOUND (-32005) MUST keep
	 * its 404. This is the one code for which the session-terminated signal is genuinely
	 * correct - rewriting it would make a client cling to a dead session forever. If the
	 * allowlist in aafm_mcp_filter_governed_error_status() is ever widened to catch this
	 * code, this test must fail.
	 */
	public function test_session_not_found_keeps_404(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32005 ), 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( 404, $out->get_status(), 'SESSION_NOT_FOUND (-32005) must keep its 404.' );
		$this->assertSame( -32005, $out->get_data()['error']['code'] );
	}

	/**
	 * A code outside the allowlist (e.g. INTERNAL_ERROR, -32000, which the adapter itself
	 * maps to 500, not 404) is left untouched. Proves this is an allowlist, not a denylist
	 * of "everything except -32005."
	 */
	public function test_unlisted_code_at_404_is_untouched(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32000 ), 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( 404, $out->get_status() );
	}

	/**
	 * A successful tools/call response (no error key) is untouched.
	 */
	public function test_successful_response_is_untouched(): void {
		$body     = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array( 'content' => array() ),
		);
		$response = new WP_REST_Response( $body, 200 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( $response, $out );
		$this->assertArrayNotHasKey( 'error', $out->get_data() );
	}

	/**
	 * A 404 on an unrelated route is untouched: rest_post_dispatch fires on every REST
	 * request the site serves, not only the MCP route, so a real tool-not-found-shaped
	 * body on some other endpoint must never be rewritten.
	 */
	public function test_unrelated_route_404_is_untouched(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32003 ), 404 );
		$request  = new WP_REST_Request( 'POST', '/wp/v2/posts' );

		$out = $this->filter( $response, $request );

		$this->assertSame( $response, $out );
		$this->assertSame( 404, $out->get_status() );
	}

	/**
	 * A batch response is always a plain list of per-message results, never a single
	 * {jsonrpc, error, id} object, and must never be rewritten even if one somehow carried
	 * a 404 status (the adapter itself never produces this combination - batches always
	 * return 200 - but the filter guards against relying on that indirectly).
	 */
	public function test_batch_shaped_response_is_untouched(): void {
		$batch    = array(
			$this->jsonrpc_error_body( -32003, 1 ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'result'  => array(),
			),
		);
		$response = new WP_REST_Response( $batch, 404 );

		$out = $this->filter( $response, $this->mcp_request() );

		$this->assertSame( $response, $out );
		$this->assertSame( 404, $out->get_status() );
	}
}
