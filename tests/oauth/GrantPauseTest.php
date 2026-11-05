<?php
/**
 * Tests for a paused connection at the transport gate.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * A paused grant keeps its login, is refused with a plain 403 at the transport gate, and works again on Resume.
 */
class GrantPauseTest extends TestCase {

	private const CLIENT = 'client_pause';

	/**
	 * Approving user.
	 *
	 * @var int
	 */
	private int $user = 0;

	/**
	 * $_SERVER as set_up() found it.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	/**
	 * The minted access token.
	 *
	 * @var string
	 */
	private string $access = '';

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		$this->route_as_rest_request();
		$_SERVER['HTTPS'] = 'on';
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Pause',
				'is_active'   => 1,
			)
		);
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
			)
		);
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		$this->access = $tokens['access_token'];
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * Resolve the bearer the way determine_current_user does and log the user in.
	 *
	 * @return int|false
	 */
	private function resolve() {
		aafm_oauth_current_grant( null, true );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->access;
		$user                          = aafm_oauth_resolve_current_user( false );
		wp_set_current_user( (int) $user );
		return $user;
	}

	/**
	 * Set the pause column directly.
	 *
	 * @param string|null $paused_at Pause stamp or null.
	 * @return void
	 */
	private function set_paused( ?string $paused_at ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'paused_at' => $paused_at ), array( 'client_id' => self::CLIENT ) );
	}

	/**
	 * Denied (transport) rows carrying the paused reason.
	 *
	 * @return int
	 */
	private function paused_rows(): int {
		$rows = aafm_query_activity(
			array(
				'ability' => '(transport)',
				'status'  => 'denied',
			)
		);
		return count( array_filter( $rows, static fn( $row ) => false !== strpos( (string) $row['detail'], 'Refused: connection_paused' ) ) );
	}

	/**
	 * The consent row's last_used_at.
	 *
	 * @return string|null
	 */
	private function last_used(): ?string {
		return aafm_oauth_grant_state( $this->user, self::CLIENT )['last_used_at'];
	}

	/**
	 * Send a tools/list request through the real REST dispatch, putting the transport's route back if an
	 * earlier test rebuilt the REST server after the adapter registered it.
	 *
	 * @param bool                 $as_served Also run core's Allow header filter, which calls every handler's
	 *                                        permission callback again once per HTTP method, as serving a request does.
	 * @param string               $body      The JSON-RPC body to send.
	 * @param string               $session   Mcp-Session-Id to send; the adapter wants one on everything after initialize.
	 * @param array<string,string> $headers Extra request headers.
	 * @return \WP_REST_Response
	 */
	private function dispatch( bool $as_served = false, string $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', string $session = '', array $headers = array() ): \WP_REST_Response {
		if ( ! isset( rest_get_server()->get_routes()[ aafm_mcp_rest_route() ] ) ) {
			$server = \WP\MCP\Core\McpAdapter::instance()->get_server( 'aafm-server' );
			$this->assertNotNull( $server );
			( new \WP\MCP\Transport\HttpTransport( $server->create_transport_context() ) )->register_routes();
		}
		$request = new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( '' !== $session ) {
			$request->set_header( 'Mcp-Session-Id', $session );
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $body );
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, applied as serve_request() does.
		return $as_served ? rest_send_allow_header( $response, rest_get_server(), $request ) : $response;
	}

	/**
	 * A response body as the client reads it: the adapter hands back schema records, not arrays.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return array<string,mixed>
	 */
	private function wire( \WP_REST_Response $response ): array {
		return (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}

	/**
	 * Run the initialize handshake for the current grant.
	 *
	 * @return array{0:string,1:\WP_REST_Response} Session id and the initialize response.
	 */
	private function handshake(): array {
		$response = $this->dispatch( false, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"pause-test","version":"1"}}}' );
		$this->assertSame( 200, $response->get_status() );
		return array( (string) ( $response->get_headers()['Mcp-Session-Id'] ?? '' ), $response );
	}

	public function test_a_paused_grant_still_resolves_a_user_and_the_transport_gate_admits_it(): void {
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->assertSame( $this->user, $this->resolve() );
		$this->assertTrue( aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) ) );
		$this->assertSame( 0, $this->paused_rows(), 'Admitting writes no transport denial row.' );
	}

	/**
	 * A throwaway server carrying one ability, so a tools/call can run through the adapter's real
	 * handler whatever the process-wide server was built with (see AllowlistWireTest).
	 *
	 * @return \AAFM\Tests\Support\McpToolsHandlerShim
	 */
	private function single_tool_handler(): \AAFM\Tests\Support\McpToolsHandlerShim {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		static $counter = 0;
		++$counter;
		$id      = 'aafm-server-pause-test-' . $counter;
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/get-posts' ) ) );
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $tools, $id ): void {
				$adapter->create_server(
					$id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (pause test)',
					'Test-only server.',
					AAFM_VERSION,
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					$tools,
					array(),
					array(),
					'aafm_transport_permission_callback'
				);
			}
		);
		$server = $adapter->get_server( $id );
		$this->assertInstanceOf( \WP\MCP\Core\McpServer::class, $server );
		return new \AAFM\Tests\Support\McpToolsHandlerShim( $server );
	}

	public function test_a_paused_connection_lists_tools_with_a_normal_200(): void {
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		list( $session ) = $this->handshake();
		$response        = $this->dispatch( true, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}', $session );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $this->wire( $response )['result']['tools'] );
	}

	public function test_a_paused_tools_call_is_a_tool_error_naming_the_pause(): void {
		$handler = $this->single_tool_handler();
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/get-posts' ),
				'arguments' => array(),
			)
		);

		$this->assertInstanceOf( \AAFM\Tests\Support\McpToolCallOutcome::class, $result );
		$this->assertTrue( $result->getIsError() );
		$this->assertStringContainsString( 'This connection is paused by the site owner', (string) wp_json_encode( $result->getContent() ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/get-posts' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertStringContainsString( 'Refused: connection_paused', (string) $rows[0]['detail'] );
		$this->assertSame( self::CLIENT, $rows[0]['client_id'] );
	}

	public function test_a_paused_call_gets_the_pause_error_even_when_the_rate_bucket_is_full(): void {
		update_option( 'aafm_rate_limit_per_min', 1 );
		$handler = $this->single_tool_handler();
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$this->assertTrue( aafm_rate_limit_consume( $this->user ), 'The bucket is now full.' );
		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/get-posts' ),
				'arguments' => array(),
			)
		);

		$this->assertTrue( $result->getIsError() );
		$text = (string) wp_json_encode( $result->getContent() );
		$this->assertStringContainsString( 'This connection is paused by the site owner', $text );
		$this->assertStringNotContainsString( 'rate', strtolower( $text ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/get-posts' ) );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'Refused: connection_paused', (string) $rows[0]['detail'] );
	}

	public function test_a_paused_call_does_not_use_up_the_users_rate_bucket(): void {
		update_option( 'aafm_rate_limit_per_min', 2 );
		$handler = $this->single_tool_handler();
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		for ( $i = 0; $i < 3; $i++ ) {
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/get-posts' ),
					'arguments' => array(),
				)
			);
		}
		// The same person's Application Password shares the bucket and still has both tokens.
		$this->assertTrue( aafm_rate_limit_consume( $this->user ) );
		$this->assertTrue( aafm_rate_limit_consume( $this->user ) );
		$this->assertFalse( aafm_rate_limit_consume( $this->user ) );
	}

	public function test_a_paused_connection_gets_no_pause_line_in_instructions(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-site-info' ) );
		// The server is built once per process and the test library drops hooks added during a test,
		// so a later test in the class needs the filter put back.
		if ( ! has_filter( 'mcp_adapter_initialize_response', 'aafm_filter_initialize_capabilities' ) ) {
			add_filter( 'mcp_adapter_initialize_response', 'aafm_filter_initialize_capabilities', 10, 3 );
		}
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		list( , $init ) = $this->handshake();
		$instructions   = $this->wire( $init )['result']['instructions'] ?? null;
		$this->assertIsString( $instructions, 'The initialize result carries instructions, so the check below is not vacuous.' );
		$this->assertNotSame( '', $instructions );
		$this->assertStringNotContainsString( 'paused', $instructions );
		$this->assertSame( '', aafm_server_instructions_addendum(), 'Pause is carried by the per-call refusal, never by instructions a client may cache.' );

		$request = new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"server/discover"}' );
		$fresh = static fn(): \WP_REST_Response => new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array( 'instructions' => 'Base.' ),
			),
			200
		);
		$out   = $this->wire( aafm_mcp_add_discover_instructions( $fresh(), null, $request ) );
		$this->assertSame( 'Base.', $out['result']['instructions'], 'Nothing is appended for a paused connection.' );

		$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' );
		$this->assertSame( 'Base.', $this->wire( aafm_mcp_add_discover_instructions( $fresh(), null, $request ) )['result']['instructions'], 'Only server/discover is rewritten.' );
		$other = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_body( '{"method":"server/discover"}' );
		$this->assertSame( 'Base.', $this->wire( aafm_mcp_add_discover_instructions( $fresh(), null, $other ) )['result']['instructions'], 'Other routes are untouched.' );

		// Resume: the instructions are exactly what an unpaused connection gets, because nothing was ever added.
		$this->set_paused( null );
		$this->resolve();
		$this->assertSame( aafm_playbook_index(), aafm_server_instructions_addendum() );
		$this->assertStringNotContainsString( 'paused', aafm_server_instructions_addendum() );
	}

	public function test_server_discover_on_the_wire_carries_no_pause_line(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-site-info' ) );
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$response = $this->dispatch(
			true,
			'{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}',
			'',
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'server/discover',
			)
		);
		$body     = $this->wire( $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsString( $body['result']['instructions'] ?? null, 'The discover result carries instructions, so the check below is not vacuous.' );
		$this->assertNotSame( '', $body['result']['instructions'] );
		$this->assertStringNotContainsString( 'paused', $body['result']['instructions'] );
	}

	public function test_one_blocked_request_writes_one_denial_row_although_core_reruns_the_permission_callback(): void {
		update_option( 'aafm_ip_allowlist', array( '198.51.100.1' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->resolve();
		$this->assertSame( 403, $this->dispatch( true )->get_status() );
		$rows = aafm_query_activity(
			array(
				'ability' => '(transport)',
				'status'  => 'denied',
			)
		);
		$this->assertCount( 1, $rows, 'One row for the blocked address, not four.' );
	}

	public function test_resuming_restores_tool_calls_without_a_new_token(): void {
		$handler = $this->single_tool_handler();
		$call    = array(
			'name'      => aafm_mcp_tool_name( 'aafm/get-posts' ),
			'arguments' => array(),
		);
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$this->assertTrue( $handler->call_tool( $call )->getIsError() );

		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => false ) ) );
		$this->resolve();
		$this->assertFalse( $handler->call_tool( $call )->getIsError() );
	}

	public function test_a_paused_grant_is_not_stamped_as_used(): void {
		global $wpdb;
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$this->admit();
		$this->assertNull( $this->last_used() );

		$this->set_paused( null );
		$this->resolve();
		$this->assertNull( $this->last_used(), 'Resolving a bearer is not a use: the transport gate admits it.' );
		$this->admit();
		$stamp = $this->last_used();
		$this->assertNotNull( $stamp );

		$writes = 0;
		$count  = static function ( $query ) use ( &$writes ) {
			if ( is_string( $query ) && false !== strpos( $query, 'SET last_used_at = ' ) ) {
				++$writes;
			}
			return $query;
		};
		add_filter( 'query', $count );
		$this->resolve();
		$this->admit();
		remove_filter( 'query', $count );
		$this->assertSame( 0, $writes, 'A second call inside five minutes writes nothing.' );

		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'last_used_at' => null,
				'expires_at'   => '2020-01-01 00:00:00',
			),
			array( 'client_id' => self::CLIENT )
		);
		$this->assertFalse( $this->resolve() );
		$this->assertNull( $this->last_used(), 'An expired grant is not stamped either.' );
	}

	public function test_a_call_from_a_blocked_address_is_not_stamped_as_used(): void {
		update_option( 'aafm_ip_allowlist', array( '198.51.100.1' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->resolve();
		$result = aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) );
		$this->assertFalse( $result );
		$this->assertNull( $this->last_used() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->resolve();
		$this->admit();
		$this->assertNotNull( $this->last_used(), 'The same grant from an allowed address is a use.' );
	}

	public function test_a_call_refused_by_pause_all_is_not_stamped_as_used(): void {
		$this->assertTrue( aafm_set_pause_all( true ) );
		$this->resolve();
		$this->assertSame( 403, $this->dispatch()->get_status() );
		$this->assertNull( $this->last_used() );
	}

	/**
	 * Run the transport gate for the grant the last resolve() loaded and expect it to admit or refuse quietly.
	 *
	 * @return void
	 */
	private function admit(): void {
		aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) );
	}
}
