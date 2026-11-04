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
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ORIGIN'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );
		unset( $GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['current_user'] );
		remove_all_filters( 'wp_is_application_passwords_available' );
		remove_all_filters( 'application_password_is_api_request' );
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
		$server = $this->mcp_spy_server();
		// Core's CORS filter calls header() directly, which a CLI test run cannot do after output started.
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
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
			'upper case host'   => array( 'https://EXAMPLE.ORG', true ),
			'upper case scheme' => array( 'HTTPS://example.org', true ),
			'default port'      => array( 'https://example.org:443', true ),
			'trailing slash'    => array( 'https://example.org/', true ),
			'path'              => array( 'https://example.org/x', false ),
			'userinfo'          => array( 'https://user@example.org', false ),
			'wrong scheme'      => array( 'http://example.org', false ),
			'null'              => array( 'null', false ),
			'suffix host'       => array( 'https://example.org.evil.example', false ),
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

	/**
	 * A site on an internationalised host matches the punycode Origin a browser sends, and the same
	 * host written in Unicode, whichever way the site's own URL is spelled.
	 */
	public function test_internationalised_host_matches_its_punycode_origin(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'The intl extension is not loaded.' );
		}
		add_filter(
			'aafm_mcp_allowed_origins',
			static function (): array {
				return array( 'https://münchen.example' );
			}
		);
		$this->assertTrue( $this->passes( 'https://xn--mnchen-3ya.example' ) );
		$this->assertTrue( $this->passes( 'https://münchen.example' ) );
		$this->assertTrue( $this->passes( 'HTTPS://XN--MNCHEN-3YA.EXAMPLE' ) );
		$this->assertFalse( $this->passes( 'https://xn--mnchen-3ya.evil.example' ) );
		$this->assertSame( 'https://xn--mnchen-3ya.example', aafm_site_origin( 'https://münchen.example/blog' ) );
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
			static function ( array $origins ): array {
				$origins[] = 'https://inspector.example';
				return $origins;
			}
		);
		$this->assertTrue( $this->passes( 'https://inspector.example' ) );
	}

	public function test_blank_or_malformed_filter_entries_never_match(): void {
		add_filter(
			'aafm_mcp_allowed_origins',
			static function ( array $origins ): array {
				return array_merge( $origins, array( '', 'not a url', 'https://x/y' ) );
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

	/**
	 * Serve an initialize with credentials in play, the way production runs it: nobody is logged in
	 * yet, so core's authentication stage decides before dispatch.
	 *
	 * @param string|null $origin Origin header.
	 * @param array       $server Extra $_SERVER entries (credentials).
	 * @return \Spy_REST_Server
	 */
	private function serve_with_credentials( ?string $origin, array $server ): \Spy_REST_Server {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		// An unset global makes core resolve the user through determine_current_user, the way a real request does.
		unset( $GLOBALS['current_user'] );
		return $this->serve( $origin );
	}

	private function error_code( \Spy_REST_Server $server ): string {
		// Database notices from a table another test dropped can precede the JSON; the body starts at the first brace.
		$start = strpos( $server->sent_body, '{' );
		$body  = false === $start ? null : json_decode( substr( $server->sent_body, $start ), true );
		return is_array( $body ) ? (string) ( $body['code'] ?? '' ) : '';
	}

	public function test_foreign_origin_with_a_bad_application_password_is_403_not_401(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$login = get_userdata( $admin )->user_login;
		// Core only runs the Application Password check once any user owns one.
		\WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => 'origin-test' ) );
		$srv = $this->serve_with_credentials(
			'https://evil.example',
			array(
				'PHP_AUTH_USER' => $login,
				'PHP_AUTH_PW'   => 'wrong wrong wrong wrong',
			)
		);
		$this->assertSame( 403, $srv->status );
		$this->assertSame( 'aafm_origin_not_allowed', $this->error_code( $srv ) );
	}

	public function test_foreign_origin_with_no_credentials_is_403(): void {
		$srv = $this->serve_with_credentials( 'https://evil.example', array() );
		$this->assertSame( 403, $srv->status );
		$this->assertSame( 'aafm_origin_not_allowed', $this->error_code( $srv ) );
	}

	public function test_foreign_origin_with_a_bad_bearer_is_403(): void {
		global $wpdb;
		// A schema test earlier in the directory run can leave the token table dropped; the lookup notice is not what this test is about.
		$was_suppressed = $wpdb->suppress_errors( true );
		$srv            = $this->serve_with_credentials( 'https://evil.example', array( 'HTTP_AUTHORIZATION' => 'Bearer aafm_oat_garbagegarbagegarbage' ) );
		$wpdb->suppress_errors( $was_suppressed );
		$this->assertSame( 403, $srv->status );
		$this->assertSame( 'aafm_origin_not_allowed', $this->error_code( $srv ) );
	}

	public function test_allowed_origin_with_a_bad_application_password_is_still_401(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$login = get_userdata( $admin )->user_login;
		// Core only runs the Application Password check once any user owns one.
		\WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => 'origin-test' ) );
		$srv = $this->serve_with_credentials(
			'https://claude.ai',
			array(
				'PHP_AUTH_USER' => $login,
				'PHP_AUTH_PW'   => 'wrong wrong wrong wrong',
			)
		);
		$this->assertSame( 401, $srv->status );
		$this->assertNotSame( 'aafm_origin_not_allowed', $this->error_code( $srv ) );
	}

	public function test_foreign_origin_on_a_non_mcp_route_is_untouched(): void {
		$_SERVER['HTTP_ORIGIN']                  = 'https://evil.example';
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/posts';
		$this->assertNull( aafm_mcp_origin_auth_guard( null ) );
		$error = new \WP_Error( 'x', 'y' );
		$this->assertSame( $error, aafm_mcp_origin_auth_guard( $error ) );
	}

	public function test_oauth_and_discovery_routes_ignore_origin(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$server                 = $this->mcp_spy_server();
		$prefix                 = '/' . aafm_oauth_rest_namespace() . '/';
		$seen                   = 0;
		foreach ( array_keys( $server->get_routes() ) as $route ) {
			if ( 0 !== strpos( $route, $prefix ) ) {
				continue;
			}
			++$seen;
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
			$this->assertNull( aafm_mcp_origin_auth_guard( null ), $route );
		}
		$this->assertGreaterThan( 0, $seen, 'The OAuth namespace registers routes.' );
	}

	public function test_one_denial_row_for_a_refused_origin_at_the_auth_stage(): void {
		$this->serve_with_credentials( 'https://evil.example', array() );
		$rows = aafm_query_activity( array( 'ability' => '(transport)' ) );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'origin_not_allowed', (string) $rows[0]['detail'] );
		$this->assertStringContainsString( 'evil.example', (string) $rows[0]['detail'] );
	}

	public function test_guard_before_bootstrap_is_a_no_op(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/origin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertMatchesRegularExpression( "/function aafm_mcp_origin_guard\\([^)]*\\) \\{\\s*unset\\( \\\$server \\);\\s*(\\/\\/[^\\n]*\\n\\s*)?if \\( ! function_exists\\( 'aafm_is_mcp_route' \\)/", $source );
	}
}
