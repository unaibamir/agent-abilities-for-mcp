<?php
/**
 * The 403 body for a blocked address names the rule; an unauthenticated 401 and other routes keep
 * core's rest_forbidden.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class TransportRefusalBodyTest extends TestCase {

	/**
	 * Core's answer to a false permission callback.
	 *
	 * @param int $status 401 or 403.
	 * @return \WP_REST_Response
	 */
	private function forbidden( int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'rest_forbidden',
				'message' => 'Sorry, you are not allowed to do that.',
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	public function test_ip_blocked_body_names_the_address_rule(): void {
		$request = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		aafm_transport_refusal( $request, 'ip_blocked' );
		$response = aafm_mcp_explain_transport_refusal( $this->forbidden( 403 ), null, $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aafm_ip_blocked', $response->get_data()['code'] );
		$this->assertSame( array( 'status' => 403 ), $response->get_data()['data'] );
	}

	public function test_unauthenticated_body_is_unchanged(): void {
		$request = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		aafm_transport_refusal( $request, 'unauthenticated' );
		$response = aafm_mcp_explain_transport_refusal( $this->forbidden( 401 ), null, $request );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	public function test_other_routes_are_untouched(): void {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		aafm_transport_refusal( $request, 'connection_paused' );
		$response = aafm_mcp_explain_transport_refusal( $this->forbidden( 403 ), null, $request );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	public function test_a_403_without_a_recorded_reason_is_untouched(): void {
		$request  = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$response = aafm_mcp_explain_transport_refusal( $this->forbidden( 403 ), null, $request );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}
}
