<?php
/**
 * The initialize params a client sends are stored verbatim in user meta, so they have to
 * be bounded before the adapter ever sees them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

final class InitializeClientInfoBoundTest extends TestCase {

	/**
	 * Build an initialize request against the MCP route with the given params.
	 *
	 * @param array<string,mixed> $params The initialize params.
	 * @return WP_REST_Request
	 */
	private function initialize_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => $params,
				)
			)
		);
		return $request;
	}

	/**
	 * Core dispatches a route with one trailing newline to the MCP handler, so the initialize bound
	 * applies to that spelling as well.
	 */
	public function test_the_bound_applies_on_the_newline_route_core_dispatches_to_mcp(): void {
		$request = $this->initialize_request(
			array(
				'protocolVersion' => '2025-06-18',
				'clientInfo'      => array( 'name' => 'probe' ),
				'padding'         => str_repeat( 'B', 2000000 ),
			)
		);
		$request->set_route( aafm_mcp_rest_route() . "\n" );

		$result = aafm_bound_mcp_initialize_params( null, null, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * The reproduction. A subscriber POSTed a 2,000,000-character clientInfo.name and grew their
	 * own usermeta row to 64 MB, because create_session() stores the whole params array.
	 */
	public function test_an_oversized_client_name_is_cut_down_before_the_adapter_sees_it(): void {
		$request = $this->initialize_request(
			array(
				'protocolVersion' => '2025-06-18',
				'clientInfo'      => array(
					'name'    => str_repeat( 'A', 2000000 ),
					'version' => '1.0',
				),
			)
		);

		$result = aafm_bound_mcp_initialize_params( null, null, $request );
		$this->assertNull( $result, 'Bounding is a rewrite, not a refusal - the handshake still proceeds.' );

		$body = $request->get_json_params();
		$name = (string) $body['params']['clientInfo']['name'];

		$this->assertLessThanOrEqual( 128, strlen( $name ) );
		$this->assertNotSame( '', $name, 'A truncated name is still a name; it is not dropped.' );
	}

	/**
	 * The sibling nobody asked about: create_session() stores the WHOLE params array, so bounding
	 * only clientInfo would leave params.anything_else just as unbounded.
	 */
	public function test_an_oversized_params_payload_outside_client_info_is_refused(): void {
		$request = $this->initialize_request(
			array(
				'protocolVersion' => '2025-06-18',
				'clientInfo'      => array( 'name' => 'probe' ),
				'padding'         => str_repeat( 'B', 2000000 ),
			)
		);

		$result = aafm_bound_mcp_initialize_params( null, null, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_initialize_params_too_large', $result->get_error_code() );
	}

	public function test_an_ordinary_handshake_is_left_exactly_as_sent(): void {
		$params  = array(
			'protocolVersion' => '2025-06-18',
			'capabilities'    => array( 'roots' => array( 'listChanged' => true ) ),
			'clientInfo'      => array(
				'name'    => 'claude-code',
				'version' => '1.2.3',
			),
		);
		$request = $this->initialize_request( $params );

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );
		$this->assertSame( $params, $request->get_json_params()['params'] );
	}

	/**
	 * The protocol gives clientInfo a name and a version and nothing else. Anything extra is a
	 * client's own invention and there is no reason to persist it.
	 */
	public function test_unknown_client_info_fields_are_dropped(): void {
		$request = $this->initialize_request(
			array(
				'clientInfo' => array(
					'name'      => 'probe',
					'version'   => '1.0',
					'telemetry' => str_repeat( 'C', 5000 ),
				),
			)
		);

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );
		$this->assertSame(
			array(
				'name'    => 'probe',
				'version' => '1.0',
			),
			$request->get_json_params()['params']['clientInfo']
		);
	}

	/**
	 * Invisible characters in a client name reach the same admin screens every other stored name
	 * does, so the name gets the plain-text helper too.
	 */
	public function test_a_client_name_is_sanitized_like_other_stored_text(): void {
		$request = $this->initialize_request(
			array( 'clientInfo' => array( 'name' => "pro\x00be" ) )
		);

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );
		$this->assertSame( 'probe', $request->get_json_params()['params']['clientInfo']['name'] );
	}

	/**
	 * Only the MCP route, and only initialize. Everything else passes through untouched.
	 */
	public function test_other_routes_and_methods_are_untouched(): void {
		$other = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_header( 'Content-Type', 'application/json' );
		$other->set_body( (string) wp_json_encode( array( 'params' => array( 'clientInfo' => array( 'name' => str_repeat( 'A', 5000 ) ) ) ) ) );
		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $other ) );
		$this->assertSame( 5000, strlen( $other->get_json_params()['params']['clientInfo']['name'] ) );

		$tools          = $this->initialize_request( array( 'clientInfo' => array( 'name' => str_repeat( 'A', 5000 ) ) ) );
		$body           = $tools->get_json_params();
		$body['method'] = 'tools/list';
		$tools->set_body( (string) wp_json_encode( $body ) );
		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $tools ) );
		$this->assertSame( 5000, strlen( $tools->get_json_params()['params']['clientInfo']['name'] ) );
	}

	/**
	 * The same payload inside a one-element JSON-RPC batch.
	 *
	 * The bound looked at the body as a single object, so wrapping the identical oversized
	 * handshake in `[...]` walked straight past it and reached the session store unchanged. Same
	 * shape as the params-versus-clientInfo sibling: the fix was right for the shape it examined
	 * and blind to the one beside it.
	 */
	public function test_an_oversized_client_name_in_a_batch_is_cut_down_too(): void {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => array(
							'clientInfo' => array( 'name' => str_repeat( 'A', 2000000 ) ),
						),
					),
				)
			)
		);

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );

		$body = $request->get_json_params();
		$this->assertLessThanOrEqual( 128, strlen( (string) $body[0]['params']['clientInfo']['name'] ) );
	}

	/**
	 * And the refusal half of the same bypass, on a batch element that is oversized outside
	 * clientInfo.
	 */
	public function test_an_oversized_batch_element_is_refused(): void {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/list',
					),
					array(
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'initialize',
						'params'  => array( 'padding' => str_repeat( 'B', 2000000 ) ),
					),
				)
			)
		);

		$result = aafm_bound_mcp_initialize_params( null, null, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_initialize_params_too_large', $result->get_error_code() );
	}

	/**
	 * The handshake from issue 123: a client that sends clientInfo.title alongside empty capability
	 * objects. Dropping the title forces a rewrite, and the rewrite must keep every `{}` an object,
	 * because the adapter refuses `[]` where the schema says object (-32602).
	 */
	private const CODEX_HANDSHAKE = '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{"elicitation":{"form":{},"url":{}},"experimental":{"codex/auth-change":{}}},"clientInfo":{"name":"codex-mcp-client","title":"Codex","version":"0.171.0-alpha.13"}}}';

	/**
	 * Build a request on the MCP route from a raw JSON body, so empty objects survive into it.
	 *
	 * @param string $json The raw body.
	 * @return WP_REST_Request
	 */
	private function raw_request( string $json ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $json );
		return $request;
	}

	/**
	 * Assert the rewritten params kept every empty object as an object and lost the title.
	 *
	 * @param mixed $params The params node, decoded as objects.
	 */
	private function assert_handshake_kept_its_objects( $params ): void {
		$this->assertInstanceOf( \stdClass::class, $params->capabilities->elicitation->form );
		$this->assertInstanceOf( \stdClass::class, $params->capabilities->elicitation->url );
		$this->assertInstanceOf( \stdClass::class, $params->capabilities->experimental->{'codex/auth-change'} );
		$this->assertEquals(
			json_decode( '{"elicitation":{"form":{},"url":{}},"experimental":{"codex/auth-change":{}}}' ),
			$params->capabilities
		);
		$this->assertEquals(
			(object) array(
				'name'    => 'codex-mcp-client',
				'version' => '0.171.0-alpha.13',
			),
			$params->clientInfo, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP field name.
			'The title is still stripped.'
		);
	}

	public function test_a_rewrite_keeps_empty_capability_objects_as_objects(): void {
		$request = $this->raw_request( self::CODEX_HANDSHAKE );

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );

		$body = json_decode( $request->get_body() );
		$this->assertInstanceOf( \stdClass::class, $body );
		$this->assert_handshake_kept_its_objects( $body->params );
	}

	public function test_a_batch_rewrite_keeps_empty_capability_objects_as_objects(): void {
		$request = $this->raw_request( '[{"jsonrpc":"2.0","id":5,"method":"tools/list","params":{}},' . self::CODEX_HANDSHAKE . ']' );

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );

		$body = json_decode( $request->get_body() );
		$this->assertIsArray( $body );
		$this->assertCount( 2, $body );
		$this->assertInstanceOf( \stdClass::class, $body[0]->params, 'The untouched sibling keeps its {} too.' );
		$this->assert_handshake_kept_its_objects( $body[1]->params );
	}

	public function test_an_emptied_client_info_stays_an_object(): void {
		$request = $this->raw_request( '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"capabilities":{},"clientInfo":{"title":"Only a title"}}}' );

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );

		$body = json_decode( $request->get_body() );
		$this->assertInstanceOf( \stdClass::class, $body->params->clientInfo );
		$this->assertSame( array(), get_object_vars( $body->params->clientInfo ) );
		$this->assertInstanceOf( \stdClass::class, $body->params->capabilities );
	}

	public function test_an_oversized_handshake_with_empty_objects_is_still_refused(): void {
		$request = $this->raw_request(
			'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"capabilities":{"experimental":{"x":{}}},"clientInfo":{"name":"probe","title":"t"},"padding":"' . str_repeat( 'B', 2000000 ) . '"}}'
		);

		$result = aafm_bound_mcp_initialize_params( null, null, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_initialize_params_too_large', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
	}

	public function test_a_handshake_needing_no_rewrite_is_left_byte_identical(): void {
		$json    = '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{"elicitation":{"form":{},"url":{}},"experimental":{"codex/auth-change":{}}},"clientInfo":{"name":"codex-mcp-client","version":"0.171.0-alpha.13"}}}';
		$request = $this->raw_request( $json );

		$this->assertNull( aafm_bound_mcp_initialize_params( null, null, $request ) );
		$this->assertSame( $json, $request->get_body() );
	}
}
