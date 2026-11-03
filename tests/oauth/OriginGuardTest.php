<?php
/**
 * Origin validation on the MCP route: no Origin passes, this site's own and the hosted clients'
 * origins pass, anything else is a 403 with one denial row whose host only the owner can read.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class OriginGuardTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'aafm_mcp_allowed_origins' );
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ORIGIN'] );
		parent::tear_down();
	}

	/**
	 * A request to the MCP route with an optional Origin header.
	 *
	 * @param string|null $origin Origin header, or null for none.
	 * @param string      $route  Route.
	 * @return \WP_REST_Request
	 */
	private function request( ?string $origin, string $route = '' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '' !== $route ? $route : aafm_mcp_rest_route() );
		if ( null !== $origin ) {
			$request->set_header( 'Origin', $origin );
		}
		return $request;
	}

	/**
	 * Serve a real initialize, optionally with an Origin header.
	 *
	 * @param string|null $origin Origin header.
	 * @param string      $method HTTP method.
	 * @return \Spy_REST_Server
	 */
	private function serve( ?string $origin, string $method = 'POST' ): \Spy_REST_Server {
		$this->route_as_rest_request();
		$server                    = $this->mcp_spy_server();
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['CONTENT_TYPE']   = 'application/json';
		$_SERVER['HTTP_ACCEPT']    = 'application/json, text/event-stream';
		if ( null !== $origin ) {
			$_SERVER['HTTP_ORIGIN'] = $origin;
		}
		$GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the body core's get_raw_data() reads.
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'origin-test',
						'version' => '1.0',
					),
				),
			)
		);
		$server->serve_request( aafm_mcp_rest_route() );
		return $server;
	}

	/**
	 * The guard's verdict for an Origin header on the MCP route.
	 *
	 * @param string|null $origin Origin header.
	 * @return bool True when it passes.
	 */
	private function passes( ?string $origin ): bool {
		return null === aafm_mcp_origin_guard( null, null, $this->request( $origin ) );
	}

	public function test_no_origin_passes(): void {
		$this->acting_as( 'administrator' );
		$server = $this->serve( null );
		$this->assertSame( 200, $server->status );
		$this->assertCount( 0, aafm_query_activity( array( 'ability' => '(transport)' ) ) );
	}

	public function test_own_origin_passes(): void {
		$this->assertTrue( $this->passes( aafm_site_origin( home_url() ) ) );
	}

	public function test_trusted_client_origins_pass(): void {
		$this->assertTrue( $this->passes( 'https://claude.ai' ) );
		$this->assertTrue( $this->passes( 'https://chatgpt.com' ) );
	}

	public function test_foreign_origin_is_refused(): void {
		$this->acting_as( 'administrator' );
		$this->route_as_rest_request();
		$server  = $this->mcp_spy_server();
		$request = $this->request( 'https://evil.example' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"o","version":"1"}}}' );
		$response = rest_ensure_response( $server->dispatch( $request ) );
		$body     = $server->response_to_data( $response, false );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array( 'code', 'message', 'data' ), array_keys( $body ) );
		$this->assertSame( 'aafm_origin_not_allowed', $body['code'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response->get_headers(), 'The adapter never ran, so no session was created.' );
		$rows = aafm_query_activity( array( 'ability' => '(transport)' ) );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'evil.example', (string) $rows[0]['detail'] );
		$this->assertStringContainsString( 'origin_not_allowed', (string) $rows[0]['detail'] );
	}

	/**
	 * Origin header spellings and their verdicts.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function origin_cases(): array {
		return array(
			'upper case host' => array( 'https://EXAMPLE.ORG', true ),
			'default port'    => array( 'https://example.org:443', true ),
			'trailing slash'  => array( 'https://example.org/', true ),
			'path'            => array( 'https://example.org/x', false ),
			'userinfo'        => array( 'https://user@example.org', false ),
			'wrong scheme'    => array( 'http://example.org', false ),
			'null'            => array( 'null', false ),
			'suffix host'     => array( 'https://example.org.evil.example', false ),
		);
	}

	/**
	 * Normalization against a site at https://example.org.
	 *
	 * @dataProvider origin_cases
	 *
	 * @param string $origin Origin header.
	 * @param bool   $passes Expected verdict.
	 */
	public function test_origin_normalization_cases( string $origin, bool $passes ): void {
		add_filter(
			'aafm_mcp_allowed_origins',
			static function (): array {
				return array( 'https://example.org' );
			}
		);
		$this->assertSame( $passes, $this->passes( $origin ) );
	}

	public function test_subdirectory_install_allows_its_own_origin(): void {
		$this->assertSame( 'https://example.com', aafm_site_origin( 'https://example.com/blog' ) );
		update_option( 'home', 'https://example.com/blog' );
		update_option( 'siteurl', 'https://example.com/blog' );
		$this->assertTrue( $this->passes( 'https://example.com' ) );
	}

	public function test_filter_can_add_an_origin(): void {
		$this->assertFalse( $this->passes( 'https://inspector.example' ) );
		add_filter(
			'aafm_mcp_allowed_origins',
			static function ( array $list ): array {
				$list[] = 'https://inspector.example';
				return $list;
			}
		);
		$this->assertTrue( $this->passes( 'https://inspector.example' ) );
	}

	public function test_blank_or_malformed_filter_entries_never_match(): void {
		add_filter(
			'aafm_mcp_allowed_origins',
			static function ( array $list ): array {
				return array_merge( $list, array( '', 'not a url', 'https://x/y' ) );
			}
		);
		$this->assertNotContains( '', aafm_mcp_allowed_origins() );
		$this->assertFalse( $this->passes( 'null' ) );
		$this->assertFalse( $this->passes( 'garbage' ) );
	}

	public function test_options_preflight_from_a_foreign_origin_is_refused(): void {
		$request = new \WP_REST_Request( 'OPTIONS', aafm_mcp_rest_route() );
		$request->set_header( 'Origin', 'https://evil.example' );
		$result = aafm_mcp_origin_guard( null, null, $request );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_agent_activity_log_never_shows_a_refused_origin(): void {
		$this->acting_as( 'administrator' );
		aafm_mcp_origin_guard( null, null, $this->request( 'https://evil.example' ) );
		$row = aafm_query_activity( array( 'ability' => '(transport)' ) )[0];
		$this->assertStringContainsString( 'evil.example', (string) $row['detail'] );
		$this->assertNull( aafm_redact_denial_reason( (string) $row['detail'] ) );
	}

	public function test_guard_ignores_other_routes(): void {
		$this->assertNull( aafm_mcp_origin_guard( null, null, $this->request( 'https://evil.example', '/wp/v2/posts' ) ) );
	}

	public function test_guard_before_bootstrap_is_a_no_op(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/origin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertMatchesRegularExpression( "/function aafm_mcp_origin_guard\\([^)]*\\) \\{\\s*unset\\( \\\$server \\);\\s*(\\/\\/[^\\n]*\\n\\s*)?if \\( ! function_exists\\( 'aafm_is_mcp_route' \\)/", $source );
	}
}
