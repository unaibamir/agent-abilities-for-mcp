<?php
/**
 * Tests for transport-visibility logging: the rest_post_dispatch handler that writes one (transport)
 * activity row for an MCP-route JSON-RPC error response.
 *
 * The gap this closes: activity logging fires per ability invocation (tools/call), so the initialize
 * handshake, tools/list, and session/protocol validation never wrote a row. A failed /mcp request was
 * therefore indistinguishable in the log from one that never reached WordPress at all. After this
 * ships, a still-empty log means "never reached WordPress"; a (transport) row means "reached WP, here
 * is the exact error". These tests pin the predicate (single JSON-RPC error on the MCP route only), the
 * code->name map, the denied/error status split, the flood cap, and that the handler leaves the
 * response untouched.
 *
 * The handler is called directly rather than through rest_do_request(): dispatch() never applies the
 * rest_post_dispatch filter, exactly as McpErrorStatusTest.php documents for its sibling filter.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Verifies aafm_log_mcp_transport_outcome() and aafm_mcp_transport_error_name().
 */
final class McpTransportOutcomeLogTest extends TestCase {

	/**
	 * Saved REQUEST_URI / rest_route / REMOTE_ADDR, restored in tear_down.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_request = array();

	/**
	 * Install and clear the activity log, and point the request at the MCP route by default.
	 */
	public function set_up(): void {
		parent::set_up();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended
		$this->original_request = array(
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			'rest_route'  => $_GET['rest_route'] ?? null,
			'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended

		unset( $_GET['rest_route'] );
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/agent-abilities-for-mcp/mcp';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Restore the request keys to exactly their pre-test state.
	 */
	public function tear_down(): void {
		foreach ( array( 'REQUEST_URI', 'REMOTE_ADDR' ) as $key ) {
			if ( null === $this->original_request[ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->original_request[ $key ];
			}
		}
		if ( null === $this->original_request['rest_route'] ) {
			unset( $_GET['rest_route'] );
		} else {
			$_GET['rest_route'] = $this->original_request['rest_route'];
		}
		parent::tear_down();
	}

	/**
	 * A JSON-RPC error body in the exact shape JSONRPCErrorResponse::toArray() produces.
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
	 * A request against the MCP route, optionally carrying a JSON-RPC method body.
	 *
	 * @param string $method Optional JSON-RPC method to place in the request body.
	 * @return WP_REST_Request
	 */
	private function mcp_request( string $method = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		if ( '' !== $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => $method,
					)
				)
			);
		}
		return $request;
	}

	/**
	 * Run the handler with a real WP_REST_Server instance, matching the signature
	 * rest_post_dispatch actually calls it with.
	 *
	 * @param mixed           $response The response to filter.
	 * @param WP_REST_Request $request  The originating request.
	 * @return mixed
	 */
	private function handle( $response, WP_REST_Request $request ) {
		return aafm_log_mcp_transport_outcome( $response, rest_get_server(), $request );
	}

	/**
	 * The single most recent activity row, or null when the log is empty.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latest_row(): ?array {
		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		return isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * SESSION_NOT_FOUND (-32005) - the field-report failure - writes exactly one (transport) row whose
	 * detail carries the mapped name, the raw code, and the final HTTP status.
	 */
	public function test_session_not_found_writes_one_transport_row(): void {
		$response = new WP_REST_Response( $this->jsonrpc_error_body( -32005 ), 404 );

		$this->handle( $response, $this->mcp_request() );

		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		$this->assertCount( 1, $rows, 'One MCP-route JSON-RPC error must write exactly one transport row.' );
		$this->assertSame( '(transport)', $rows[0]['ability'] );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertStringContainsString( 'session_not_found', $rows[0]['detail'] );
		$this->assertStringContainsString( 'code:-32005', $rows[0]['detail'] );
		$this->assertStringContainsString( 'http:404', $rows[0]['detail'] );
	}

	/**
	 * The handler is pure observability: it returns the response untouched, changing neither the data
	 * nor the HTTP status.
	 */
	public function test_response_is_returned_untouched(): void {
		$body     = $this->jsonrpc_error_body( -32005 );
		$response = new WP_REST_Response( $body, 404 );

		$out = $this->handle( $response, $this->mcp_request() );

		$this->assertSame( $response, $out );
		$this->assertSame( 404, $out->get_status() );
		$this->assertSame( $body, $out->get_data() );
	}

	/**
	 * The one authentication code on the allowlist (-32010 unauthorized) reads as a denial; every other
	 * protocol/session/internal failure is an error.
	 */
	public function test_unauthorized_maps_to_denied_status(): void {
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( -32010 ), 401 ), $this->mcp_request() );
		$row = $this->latest_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'denied', $row['status'] );
		$this->assertStringContainsString( 'unauthorized', $row['detail'] );
	}

	/**
	 * A successful (non-error) MCP response writes no row - there is nothing to diagnose.
	 */
	public function test_success_response_writes_no_row(): void {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array( 'tools' => array() ),
			),
			200
		);

		$this->handle( $response, $this->mcp_request() );

		$this->assertCount( 0, aafm_query_activity( array( 'per_page' => 50 ) ) );
	}

	/**
	 * The same JSON-RPC error on an unrelated REST route must NOT be logged - rest_post_dispatch fires
	 * for every REST request the whole site serves.
	 */
	public function test_non_mcp_route_writes_no_row(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( -32005 ), 404 ), $request );

		$this->assertCount( 0, aafm_query_activity( array( 'per_page' => 50 ) ) );
	}

	/**
	 * A batch response is a list of per-message results with no top-level error key, so it writes
	 * nothing.
	 */
	public function test_batch_response_writes_no_row(): void {
		$batch = array(
			$this->jsonrpc_error_body( -32005, 'a' ),
			$this->jsonrpc_error_body( -32601, 'b' ),
		);
		$this->handle( new WP_REST_Response( $batch, 200 ), $this->mcp_request() );

		$this->assertCount( 0, aafm_query_activity( array( 'per_page' => 50 ) ) );
	}

	/**
	 * A core-shaped WP_Error body ({code:'string', message, data:{status}}) - the shape the anonymous
	 * transport 401/403 produces - has no numeric JSON-RPC error.code, so it is NOT logged. This is the
	 * disambiguator: a bare no-bearer 401 is routine noise, not a reached-WP-and-failed signal.
	 */
	public function test_core_shaped_wp_error_writes_no_row(): void {
		$body     = array(
			'code'    => 'aafm_unauthenticated',
			'message' => 'Authentication required.',
			'data'    => array( 'status' => 401 ),
		);
		$response = new WP_REST_Response( $body, 401 );

		$this->handle( $response, $this->mcp_request() );

		$this->assertCount( 0, aafm_query_activity( array( 'per_page' => 50 ) ) );
	}

	/**
	 * One source IP cannot grow the log past the per-window cap, so a client stuck in a reconnect loop
	 * cannot inflate the table without limit.
	 */
	public function test_cap_bounds_repeats_per_ip(): void {
		// A fresh address with its counter explicitly cleared, so the window starts cold regardless of
		// rows earlier tests in this class wrote (the transient counter is cached in the object cache,
		// which the per-test DB rollback does not reset).
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		delete_transient( 'aafm_tx_' . md5( '198.51.100.77' ) );

		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 3; $i++ ) {
			$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( -32005 ), 404 ), $this->mcp_request() );
		}

		$this->assertCount(
			AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW,
			aafm_query_activity( array( 'per_page' => 50 ) ),
			'A single IP must not write more than the capped number of transport rows per window.'
		);
	}

	/**
	 * The JSON-RPC method rides in the detail when the request body carries one and it is a clean
	 * protocol string - so a session error names the call that hit it (the field report: the follow-up
	 * tools/list 404s after initialize succeeds).
	 */
	public function test_method_is_recorded_when_present(): void {
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( -32005 ), 404 ), $this->mcp_request( 'tools/list' ) );

		$row = $this->latest_row();
		$this->assertNotNull( $row );
		$this->assertStringContainsString( 'method:tools/list', $row['detail'] );
	}

	/**
	 * A JSON-RPC method that fails the conservative protocol-name shape (disallowed characters, or
	 * over-length) is DROPPED from the detail rather than logged: aafm_mcp_transport_request_method()
	 * only accepts a short scalar string matching ^[a-zA-Z0-9._/-]+$. So a row is still written for the
	 * error, but its detail carries no `method:` fragment for the rejected value - the method is
	 * client-supplied and must never ride into the audit detail unvetted.
	 *
	 * @dataProvider provider_unsafe_methods
	 *
	 * @param string $method A method value that must be rejected from the detail.
	 */
	public function test_unsafe_method_is_not_recorded_in_detail( string $method ): void {
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( -32005 ), 404 ), $this->mcp_request( $method ) );

		$row = $this->latest_row();
		$this->assertNotNull( $row, 'The error itself is still logged.' );
		$this->assertStringContainsString( 'session_not_found', $row['detail'], 'The mapped error name is still present.' );
		$this->assertStringNotContainsString( 'method:', $row['detail'], 'A method failing the protocol-name shape must not reach the detail.' );
	}

	/**
	 * Method values that must be rejected by aafm_mcp_transport_request_method().
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_unsafe_methods(): array {
		return array(
			'markup'      => array( '<script>alert(1)</script>' ),
			'space'       => array( 'tools list' ),
			'over_length' => array( str_repeat( 'a', 65 ) ),
		);
	}

	/**
	 * An arbitrary code off the allowlist is ignored entirely - no row - so the log carries only known,
	 * diagnosable failures rather than junk numeric codes.
	 */
	public function test_unknown_code_writes_no_row(): void {
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( -32099 ), 400 ), $this->mcp_request() );

		$this->assertCount( 0, aafm_query_activity( array( 'per_page' => 50 ) ) );
	}

	/**
	 * Every code on the KEEP allowlist writes exactly one row carrying its mapped name - proving the
	 * narrowing did not drop a reachable code. -32005, -32003, and -32601 are called out because they
	 * are the load-bearing diagnostics (session lookup, tool-catalog mismatch, method routing).
	 *
	 * @dataProvider provider_allowlisted_codes
	 *
	 * @param int    $code The JSON-RPC error code on the allowlist.
	 * @param string $name The mapped name expected in the detail.
	 */
	public function test_allowlisted_code_writes_row( int $code, string $name ): void {
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( $code ), 400 ), $this->mcp_request() );

		$row = $this->latest_row();
		$this->assertNotNull( $row, "Allowlisted code {$code} must write a transport row." );
		$this->assertSame( '(transport)', $row['ability'] );
		$this->assertStringContainsString( $name, $row['detail'] );
		$this->assertStringContainsString( 'code:' . $code, $row['detail'] );
	}

	/**
	 * The KEEP allowlist: the ten codes that are known and reachable as a top-level transport error.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public function provider_allowlisted_codes(): array {
		return array(
			'parse_error'       => array( -32700, 'parse_error' ),
			'invalid_request'   => array( -32600, 'invalid_request' ),
			'method_not_found'  => array( -32601, 'method_not_found' ),
			'invalid_params'    => array( -32602, 'invalid_params' ),
			'internal_error'    => array( -32603, 'internal_error' ),
			'server_error'      => array( -32000, 'server_error' ),
			'timeout_error'     => array( -32001, 'timeout_error' ),
			'tool_not_found'    => array( -32003, 'tool_not_found' ),
			'session_not_found' => array( -32005, 'session_not_found' ),
			'unauthorized'      => array( -32010, 'unauthorized' ),
		);
	}

	/**
	 * The dropped codes write nothing: -32002 resource_not_found and -32004 prompt_not_found are
	 * unreachable in this tools-only server, and -32008 permission_denied never surfaces as a top-level
	 * transport error (a denied tools/call returns HTTP 200 isError=true, already logged per-call).
	 *
	 * @dataProvider provider_dropped_codes
	 *
	 * @param int $code The JSON-RPC error code that must not be logged.
	 */
	public function test_dropped_code_writes_no_row( int $code ): void {
		$this->handle( new WP_REST_Response( $this->jsonrpc_error_body( $code ), 400 ), $this->mcp_request() );

		$this->assertCount(
			0,
			aafm_query_activity( array( 'per_page' => 50 ) ),
			"Dropped code {$code} must not write a transport row."
		);
	}

	/**
	 * The DROP list: codes that are either unreachable here or already logged elsewhere.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function provider_dropped_codes(): array {
		return array(
			'resource_not_found' => array( -32002 ),
			'prompt_not_found'   => array( -32004 ),
			'permission_denied'  => array( -32008 ),
		);
	}

	/**
	 * The code->name map returns the documented names for the allowlisted codes, and null for both a
	 * dropped adapter code (-32002/-32004/-32008) and an arbitrary unknown one.
	 */
	public function test_error_name_map(): void {
		$this->assertSame( 'session_not_found', aafm_mcp_transport_error_name( -32005 ) );
		$this->assertSame( 'tool_not_found', aafm_mcp_transport_error_name( -32003 ) );
		$this->assertSame( 'method_not_found', aafm_mcp_transport_error_name( -32601 ) );
		$this->assertSame( 'internal_error', aafm_mcp_transport_error_name( -32603 ) );
		$this->assertSame( 'unauthorized', aafm_mcp_transport_error_name( -32010 ) );

		// Dropped adapter codes now map to null, so the logger ignores them.
		$this->assertNull( aafm_mcp_transport_error_name( -32002 ) );
		$this->assertNull( aafm_mcp_transport_error_name( -32004 ) );
		$this->assertNull( aafm_mcp_transport_error_name( -32008 ) );

		// An arbitrary code was never on the list.
		$this->assertNull( aafm_mcp_transport_error_name( -32099 ) );
	}
}
