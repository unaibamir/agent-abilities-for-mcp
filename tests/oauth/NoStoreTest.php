<?php
/**
 * Every MCP route response carries Cache-Control: no-store; other routes are untouched; the
 * discovery 403 for a plain-HTTP request carries it too.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class NoStoreTest extends TestCase {

	/**
	 * Run the no-store filter over a response for a route.
	 *
	 * @param int    $status Status.
	 * @param string $route  Route.
	 * @return array<string,string>
	 */
	private function headers_for( int $status, string $route ): array {
		$response = aafm_mcp_no_store( new \WP_REST_Response( array( 'code' => 'x' ), $status ), null, new \WP_REST_Request( 'POST', $route ) );
		return $response->get_headers();
	}

	public function test_success_401_403_and_errors_carry_no_store(): void {
		foreach ( array( 200, 401, 403, 500 ) as $status ) {
			$this->assertSame( 'no-store', $this->headers_for( $status, aafm_mcp_rest_route() )['Cache-Control'] ?? null, (string) $status );
		}
		$this->assertSame( 20, has_filter( 'rest_post_dispatch', 'aafm_mcp_no_store' ) );
	}

	public function test_served_unauthenticated_401_carries_no_store(): void {
		wp_set_current_user( 0 );
		$this->route_as_rest_request();
		$server                        = $this->mcp_spy_server();
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$GLOBALS['HTTP_RAW_POST_DATA'] = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the body core's get_raw_data() reads.
		$server->serve_request( aafm_mcp_rest_route() );
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( 401, $server->status );
		$this->assertSame( 'no-store', $server->sent_headers['Cache-Control'] ?? null );
	}

	public function tear_down(): void {
		unset( $_GET['_envelope'], $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Serve an initialize with the envelope flag on and return the headers the server sent.
	 *
	 * @param bool $logged_in Whether an administrator makes the call.
	 * @return \Spy_REST_Server
	 */
	private function serve_enveloped( bool $logged_in ): \Spy_REST_Server {
		if ( $logged_in ) {
			$this->acting_as( 'administrator' );
		} else {
			wp_set_current_user( 0 );
		}
		$_GET['_envelope'] = '1';
		$this->route_as_rest_request();
		$server                        = $this->mcp_spy_server();
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$GLOBALS['HTTP_RAW_POST_DATA'] = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"o","version":"1"}}}'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the body core's get_raw_data() reads.
		$server->serve_request( aafm_mcp_rest_route() );
		return $server;
	}

	public function test_enveloped_responses_carry_no_store(): void {
		$denied = $this->serve_enveloped( false );
		$this->assertSame( 401, json_decode( $denied->sent_body, true )['status'] ?? null, 'The envelope carries the real status in its body.' );
		$this->assertSame( 'no-store', $denied->sent_headers['Cache-Control'] ?? null, 'enveloped 401' );
		$ok = $this->serve_enveloped( true );
		$this->assertSame( 200, json_decode( $ok->sent_body, true )['status'] ?? null );
		$this->assertSame( 'no-store', $ok->sent_headers['Cache-Control'] ?? null, 'enveloped 200' );
	}

	public function test_envelope_flag_without_a_value_carries_no_store(): void {
		$_GET['_envelope'] = '';
		$server            = $this->mcp_spy_server();
		$request           = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		aafm_mcp_no_store_enveloped( false, new \WP_REST_Response( array( 'a' => 1 ), 200 ), $request, $server );
		$this->assertSame( 'no-store', $server->sent_headers['Cache-Control'] ?? null );
	}

	public function test_envelope_filter_ignores_other_routes(): void {
		$server  = $this->mcp_spy_server();
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertFalse( aafm_mcp_no_store_enveloped( false, new \WP_REST_Response( array(), 200 ), $request, $server ) );
		$this->assertArrayNotHasKey( 'Cache-Control', $server->sent_headers );
		$this->assertSame( 10, has_filter( 'rest_pre_serve_request', 'aafm_mcp_no_store_enveloped' ) );
	}

	public function test_other_routes_are_untouched(): void {
		$this->assertArrayNotHasKey( 'Cache-Control', $this->headers_for( 200, '/wp/v2/posts' ) );
	}

	public function test_discovery_403_carries_no_store(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/oauth/discovery.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertMatchesRegularExpression( "/header\\( 'Cache-Control: no-store' \\);\\s*status_header\\( 403 \\);/", $source, 'The root well-known 403 sends no-store before it exits.' );
		$this->assertMatchesRegularExpression( '/new WP_REST_Response\\( null, 403 \\);\\s*\\$refused->header\\( \'Cache-Control\', \'no-store\' \\);/', $source, 'The /wp-json copy sends no-store on its 403.' );
	}
}
