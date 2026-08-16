<?php
/**
 * B2-12: the initialize params a client sends are stored verbatim in user meta, so they have to
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
}
