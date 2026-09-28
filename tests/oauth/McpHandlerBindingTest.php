<?php
/**
 * Tests that a user resolved from an OAuth bearer only ever reaches the MCP adapter's own handler.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * End to end through WP_REST_Server::serve_request() with a real bearer on a request WordPress
 * routed to the MCP endpoint.
 */
final class McpHandlerBindingTest extends TestCase {

	/**
	 * $_SERVER, $_GET and the current user as set_up() found them.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved = array();

	/**
	 * What the foreign route's callbacks saw, by callback.
	 *
	 * @var array<string,int>
	 */
	private array $calls = array();

	public function set_up(): void {
		parent::set_up();
		$this->saved = array(
			'server'       => $_SERVER,
			'get'          => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- snapshot, restored verbatim.
			'current_user' => $GLOBALS['current_user'] ?? null,
		);
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['HTTPS'] = 'on';
	}

	public function tear_down(): void {
		unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
		$this->set_permalink_structure( '' );
		$_SERVER                 = $this->saved['server'];
		$_GET                    = $this->saved['get'];
		$GLOBALS['current_user'] = $this->saved['current_user']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored as set_up() found it.
		parent::tear_down();
	}

	/**
	 * A client row, a token bound to this endpoint for a new user, and the bearer on the request.
	 *
	 * @return int The approving user's id.
	 */
	private function present_valid_bearer(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'binding-client',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
		$uid                           = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$tokens                        = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'binding-client',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		return $uid;
	}

	/**
	 * Register a foreign route on rest_api_init before the adapter's (priority 16), recording calls.
	 *
	 * @param string $route  The route inside our namespace.
	 * @param array  $extra  Extra route args.
	 * @return void
	 */
	private function register_foreign_route( string $route, array $extra = array() ): void {
		$calls = &$this->calls;
		add_action(
			'rest_api_init',
			static function () use ( &$calls, $route, $extra ): void {
				register_rest_route(
					'agent-abilities-for-mcp',
					$route,
					array_merge(
						array(
							'methods'             => 'POST',
							'callback'            => static function () use ( &$calls ) {
								$calls['callback'] = get_current_user_id();
								return array( 'foreign' => true );
							},
							'permission_callback' => static function () use ( &$calls ) {
								$calls['permission'] = get_current_user_id();
								return true;
							},
						),
						$extra
					)
				);
			},
			15
		);
	}

	/**
	 * Serve a JSON initialize call to the MCP route and return the server.
	 *
	 * @param string $query Optional query string for the request.
	 * @return \Spy_REST_Server
	 */
	private function serve_initialize( string $query = '' ): \Spy_REST_Server {
		$this->route_as_rest_request();
		$server                    = $this->mcp_spy_server();
		$GLOBALS['current_user']   = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- serve_request() resolves afresh, restored in tear_down().
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_TYPE']   = 'application/json';
		$_SERVER['HTTP_ACCEPT']    = 'application/json, text/event-stream';
		if ( '' !== $query ) {
			parse_str( $query, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the request fixture, restored in tear_down().
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
						'name'    => 'binding-test',
						'version' => '1.0',
					),
				),
			)
		);
		$server->serve_request( aafm_mcp_rest_route() );
		return $server;
	}

	/**
	 * Route shapes another plugin can register ahead of the adapter's handler on the MCP path.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function foreign_route_provider(): array {
		return array(
			'same route key, registered first' => array( '/mcp' ),
			'earlier regex route'              => array( '/(?P<seg>mcp)' ),
		);
	}

	/**
	 * A foreign handler matched on the MCP path with a bearer user gets the unauthenticated 401, and
	 * its callback never runs.
	 *
	 * @dataProvider foreign_route_provider
	 *
	 * @param string $route The foreign route.
	 */
	public function test_a_foreign_handler_on_the_mcp_path_is_refused( string $route ): void {
		$this->present_valid_bearer();
		$this->register_foreign_route( $route );

		$server = $this->serve_initialize();

		$body = json_decode( $server->sent_body, true );
		$this->assertSame( 401, $server->status );
		$this->assertSame( 'aafm_unauthenticated', $body['code'] ?? null );
		$this->assertArrayNotHasKey( 'callback', $this->calls );
	}

	/**
	 * A later rest_request_before_callbacks filter at a normal priority that returns null cannot
	 * undo the refusal.
	 */
	public function test_a_normal_priority_filter_cannot_undo_the_refusal(): void {
		$this->present_valid_bearer();
		$this->register_foreign_route( '/mcp' );
		add_filter( 'rest_request_before_callbacks', '__return_null', 11 );

		$server = $this->serve_initialize();

		$this->assertSame( 401, $server->status );
		$this->assertArrayNotHasKey( 'callback', $this->calls );
	}

	/**
	 * Named residual: core runs a foreign route's argument validation before any
	 * rest_request_before_callbacks filter, so it sees the bearer's user.
	 */
	public function test_a_foreign_validate_callback_still_sees_the_approver(): void {
		$uid   = $this->present_valid_bearer();
		$calls = &$this->calls;
		$this->register_foreign_route(
			'/mcp',
			array(
				'args' => array(
					'x' => array(
						'validate_callback' => static function () use ( &$calls ) {
							$calls['validate'] = get_current_user_id();
							return true;
						},
					),
				),
			)
		);

		$this->serve_initialize( 'x=1' );

		$this->assertSame( $uid, $this->calls['validate'] ?? null );
		$this->assertArrayNotHasKey( 'callback', $this->calls );
	}

	/**
	 * The healthy call: initialize with the bearer is answered by the adapter.
	 */
	public function test_the_adapter_answers_an_mcp_call_with_a_bearer(): void {
		$uid = $this->present_valid_bearer();

		$server = $this->serve_initialize();

		$this->assertSame( 200, $server->status );
		$this->assertSame( $uid, get_current_user_id() );
		$body = json_decode( $server->sent_body, true );
		$this->assertArrayHasKey( 'result', (array) $body );
	}

	/**
	 * A REST sub-request to another route made while the bearer is resolved is left alone.
	 */
	public function test_a_sub_request_to_another_route_is_untouched(): void {
		$uid = $this->present_valid_bearer();
		$this->route_as_rest_request();
		$this->mcp_spy_server();
		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh lookup, restored in tear_down().
		$this->assertSame( $uid, get_current_user_id() );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/types' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Without our bearer (a cookie or Application Password user), a foreign handler on the MCP path
	 * runs as core would run it.
	 */
	public function test_a_foreign_handler_runs_for_a_user_not_from_our_bearer(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->register_foreign_route( '/mcp' );
		$this->route_as_rest_request();
		$server = $this->mcp_spy_server();
		wp_set_current_user( $admin );

		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_SERVER['CONTENT_TYPE']       = 'application/json';
		$GLOBALS['HTTP_RAW_POST_DATA'] = '{}'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the body core's get_raw_data() reads.
		$server->serve_request( aafm_mcp_rest_route() );

		$this->assertSame( $admin, $this->calls['callback'] ?? null );
	}
}
