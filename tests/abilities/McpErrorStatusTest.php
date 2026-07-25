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

	/**
	 * Every test above calls the filter function directly, which proves its logic but not
	 * that it is actually wired up. This filter is the only thing standing between a
	 * disabled-tool call and a client being told its session died, so a direct check that
	 * it is genuinely hooked on rest_post_dispatch closes that gap: an accidental
	 * deregistration would leave every test above passing while the real fix silently
	 * stopped running.
	 */
	public function test_filter_is_registered_on_rest_post_dispatch(): void {
		$priority = has_filter( 'rest_post_dispatch', 'aafm_mcp_filter_governed_error_status' );

		$this->assertNotFalse( $priority, 'aafm_mcp_filter_governed_error_status must be hooked on rest_post_dispatch.' );
		$this->assertSame( 10, $priority );
	}

	/**
	 * Every test above hand-builds a JSON-RPC error body and feeds it to the filter, which
	 * proves the filter's own logic but assumes the adapter actually returns -32003 for an
	 * unknown tool name. This test drives the real vendored code instead: it registers the
	 * plugin's real MCP server (the same aafm_register_mcp_server() the live site calls,
	 * simulating mcp_adapter_init the way ServerToolsTest.php already does) and calls the
	 * adapter's own ToolsHandler::call_tool() - the exact method a live tools/call request
	 * reaches - with a name that cannot be registered.
	 *
	 * This single real-path call also covers the governance case ("a tool that exists but
	 * is not enabled by the operator"), because ToolsHandler::call_tool()
	 * (vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:139-146) has
	 * exactly one branch for a missing tool: `$mcp_tool = $this->mcp->get_mcp_tool( $tool_name );
	 * if ( ! $mcp_tool ) { ... return McpErrorFactory::tool_not_found(...); }`. A
	 * governance-disabled ability is never added to the server's $tools list in the first
	 * place (aafm_build_server_tools() only includes aafm_get_enabled_abilities()), so to
	 * this method it is indistinguishable from a name that was never registered at all -
	 * there is no separate "disabled" branch to exercise. I did not build a second, isolated
	 * MCP server fixture to prove that case independently: the adapter keeps one server per
	 * ID for the life of the PHPUnit process with no reset method, so an earlier test's real
	 * abilities may already occupy 'aafm-server' by the time this one runs, making a
	 * from-scratch "this specific ability is absent" fixture unreliable to control here
	 * without a lot of scaffolding the team lead asked me not to spend on this.
	 */
	public function test_unknown_tool_call_produces_tool_not_found_via_real_adapter(): void {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter ): void {
				aafm_register_mcp_server( $adapter );
			}
		);

		$server = $adapter->get_server( 'aafm-server' );
		$this->assertNotNull( $server, 'The plugin must have a registered MCP server to call tools/call against.' );

		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'name' => 'definitely-not-a-real-tool-9f3c1a' ), 'req-premise-1' );

		$this->assertInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
		$this->assertSame( -32003, $result->getError()->getCode() );
	}
}
