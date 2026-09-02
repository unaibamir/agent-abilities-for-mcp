<?php
/**
 * Tests for the session-persistence guard: the rest_post_dispatch handler that fails an MCP
 * initialize response honestly when the adapter handed the client a session id its
 * update_user_meta() write never actually stored.
 *
 * The gap this closes: SessionManager::create_session() returns a session id without checking
 * its write succeeded, so a silent write failure (DB error, read-only object cache, storage
 * inconsistency) stamps a valid-looking Mcp-Session-Id on the response, the client "connects",
 * and its very next request 404s with session_not_found - the in-WordPress half of the "OAuth
 * ok, then can't connect" field report. The guard verifies the just-issued session is really in
 * the user's store; when it is not, it strips the phantom header and rewrites the body to a
 * JSON-RPC internal_error (-32603) so the client gets an honest, retryable error.
 *
 * The handler is called directly rather than through rest_do_request(): dispatch() never applies
 * the rest_post_dispatch filter, exactly as the sibling McpTransportOutcomeLogTest documents.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Verifies aafm_mcp_guard_unpersisted_session().
 */
final class McpSessionPersistenceGuardTest extends TestCase {

	/**
	 * The adapter's session-store user-meta key (mirrors SessionManager::SESSION_META_KEY).
	 *
	 * @var string
	 */
	private const SESSION_META_KEY = 'mcp_adapter_sessions';

	/**
	 * A fixed session id used across the cases.
	 *
	 * @var string
	 */
	private const SESSION_ID = '11111111-2222-4333-8444-555555555555';

	/**
	 * Saved REQUEST_URI / REMOTE_ADDR, restored in tear_down.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_request = array();

	/**
	 * Resolve a real user and point the request environment at the MCP route.
	 */
	public function set_up(): void {
		parent::set_up();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->original_request = array(
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/agent-abilities-for-mcp/mcp';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Restore the request keys and the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		foreach ( array( 'REQUEST_URI', 'REMOTE_ADDR' ) as $key ) {
			if ( null === $this->original_request[ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->original_request[ $key ];
			}
		}
		parent::tear_down();
	}

	/**
	 * Create and log in a subscriber, returning the id.
	 *
	 * @return int
	 */
	private function login_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		return (int) $user_id;
	}

	/**
	 * A successful initialize response body, in the shape JsonRpcResponseBuilder produces.
	 *
	 * @param mixed $id The echoed request id.
	 * @return array<string,mixed>
	 */
	private function initialize_success_body( $id = 1 ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array( 'protocolVersion' => '2025-06-18' ),
		);
	}

	/**
	 * A session-establishing response: a 200 initialize success carrying the Mcp-Session-Id header,
	 * exactly as the transport leaves it after dispatch.
	 *
	 * @param string $session_id The session id to stamp on the header.
	 * @param mixed  $id         The echoed JSON-RPC request id.
	 * @return WP_REST_Response
	 */
	private function session_response( string $session_id, $id = 1 ): WP_REST_Response {
		$response = new WP_REST_Response( $this->initialize_success_body( $id ), 200 );
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/**
	 * A request against the MCP route carrying an initialize method body.
	 *
	 * @return WP_REST_Request
	 */
	private function mcp_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);
		return $request;
	}

	/**
	 * Run the guard with the signature rest_post_dispatch actually calls it with.
	 *
	 * @param mixed           $response The response to filter.
	 * @param WP_REST_Request $request  The originating request.
	 * @return mixed
	 */
	private function guard( $response, WP_REST_Request $request ) {
		return aafm_mcp_guard_unpersisted_session( $response, rest_get_server(), $request );
	}

	/**
	 * Case-insensitively read the Mcp-Session-Id header off a response, or '' when absent.
	 *
	 * @param WP_REST_Response $response The response.
	 * @return string
	 */
	private function header_value( WP_REST_Response $response ): string {
		foreach ( $response->get_headers() as $key => $value ) {
			if ( 0 === strcasecmp( 'Mcp-Session-Id', (string) $key ) ) {
				return is_array( $value ) ? (string) reset( $value ) : (string) $value;
			}
		}
		return '';
	}

	/**
	 * (a) A persisted session passes through byte-identical: same object, same status, same body,
	 * header intact.
	 */
	public function test_persisted_session_passes_through_untouched(): void {
		$user_id = $this->login_user();
		update_user_meta(
			$user_id,
			self::SESSION_META_KEY,
			array(
				self::SESSION_ID => array(
					'created_at'    => time(),
					'last_activity' => time(),
					'client_params' => array(),
				),
			)
		);

		$response = $this->session_response( self::SESSION_ID );
		$body     = $response->get_data();

		$out = $this->guard( $response, $this->mcp_request() );

		$this->assertSame( $response, $out, 'A persisted session must be returned untouched.' );
		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( $body, $out->get_data() );
		$this->assertSame( self::SESSION_ID, $this->header_value( $out ), 'The session header must survive intact.' );
	}

	/**
	 * (b) A session id in the header but ABSENT from the user's store (the silent-write-failure state)
	 * is failed: header stripped, body rewritten to -32603, status 500. And when the transport logger
	 * runs after the guard (mirroring their real priority-11 order), it records one (transport)
	 * internal_error row for it.
	 */
	public function test_unpersisted_session_is_failed_and_logged(): void {
		$this->login_user();
		// No session meta written at all - update_user_meta() silently did nothing.

		$response = $this->session_response( self::SESSION_ID, 7 );
		$request  = $this->mcp_request();

		$out = $this->guard( $response, $request );

		// The phantom header is gone.
		$this->assertSame( '', $this->header_value( $out ), 'The unpersisted session header must be stripped.' );

		// The body is now a JSON-RPC -32603 error preserving the request id.
		$data = $out->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertSame( 7, $data['id'], 'The JSON-RPC request id must be preserved on the error.' );
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertArrayNotHasKey( 'result', $data, 'The success result must be replaced, not carried alongside the error.' );
		$this->assertSame( 500, $out->get_status(), '-32603 maps to HTTP 500.' );

		// The transport logger, running next, records the failure as a (transport) internal_error row.
		aafm_log_mcp_transport_outcome( $out, rest_get_server(), $request );

		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		$this->assertCount( 1, $rows, 'The converted -32603 must produce exactly one transport row.' );
		$this->assertSame( '(transport)', $rows[0]['ability'] );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertStringContainsString( 'internal_error', $rows[0]['detail'] );
		$this->assertStringContainsString( 'code:-32603', $rows[0]['detail'] );
		$this->assertStringContainsString( 'http:500', $rows[0]['detail'] );
		$this->assertStringContainsString( 'method:initialize', $rows[0]['detail'] );
	}

	/**
	 * (c) A response with no Mcp-Session-Id header (every non-initialize call, and errors) is left
	 * untouched - there is no session to verify.
	 */
	public function test_no_session_header_passes_through(): void {
		$this->login_user();

		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array( 'tools' => array() ),
			),
			200
		);
		$body     = $response->get_data();

		$out = $this->guard( $response, $this->mcp_request() );

		$this->assertSame( $response, $out );
		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( $body, $out->get_data() );
	}

	/**
	 * (d) The same header-carrying, unpersisted response on an unrelated REST route is NOT touched -
	 * rest_post_dispatch fires for every REST request the whole site serves.
	 */
	public function test_non_mcp_route_passes_through(): void {
		$this->login_user();

		$response = $this->session_response( self::SESSION_ID );
		$body     = $response->get_data();
		$request  = new WP_REST_Request( 'POST', '/wp/v2/posts' );

		$out = $this->guard( $response, $request );

		$this->assertSame( $response, $out );
		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( $body, $out->get_data() );
		$this->assertSame( self::SESSION_ID, $this->header_value( $out ) );
	}

	/**
	 * FIX C (1.7.2): a batch (list) response is NEVER clobbered, even when it carries an unpersisted
	 * session header. The rewrite replaces the whole body with a single -32603 error, which on a batch
	 * would destroy every sibling result (e.g. a batch that merely INCLUDED initialize). The guard must
	 * therefore detect a list body and return it untouched - status, data, and header all intact.
	 */
	public function test_batch_response_is_not_clobbered(): void {
		$this->login_user();
		// No session meta written: the session id is unpersisted, so a single response WOULD be failed.

		$batch    = array(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array( 'protocolVersion' => '2025-06-18' ),
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'result'  => array( 'tools' => array() ),
			),
		);
		$response = new WP_REST_Response( $batch, 200 );
		$response->header( 'Mcp-Session-Id', self::SESSION_ID );

		$out = $this->guard( $response, $this->mcp_request() );

		$this->assertSame( $response, $out, 'A batch response must be returned untouched.' );
		$this->assertSame( 200, $out->get_status(), 'The batch status must not be rewritten to 500.' );
		$this->assertSame( $batch, $out->get_data(), 'The batch body must not be clobbered into a single error.' );
		$this->assertSame( self::SESSION_ID, $this->header_value( $out ), 'The batch header is left intact.' );
	}

	/**
	 * An anonymous request (no resolved user) carrying the header passes through: there is no session
	 * store to verify against, so the guard does not fail it on a guess.
	 */
	public function test_anonymous_request_passes_through(): void {
		wp_set_current_user( 0 );

		$response = $this->session_response( self::SESSION_ID );
		$body     = $response->get_data();

		$out = $this->guard( $response, $this->mcp_request() );

		$this->assertSame( $response, $out );
		$this->assertSame( 200, $out->get_status() );
		$this->assertSame( $body, $out->get_data() );
		$this->assertSame( self::SESSION_ID, $this->header_value( $out ) );
	}
}
