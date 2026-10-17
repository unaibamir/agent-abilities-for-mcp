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
	 * @param bool $as_served Also run core's Allow header filter, which calls every handler's
	 *                        permission callback again once per HTTP method, as serving a request does.
	 * @return \WP_REST_Response
	 */
	private function dispatch( bool $as_served = false ): \WP_REST_Response {
		if ( ! isset( rest_get_server()->get_routes()[ aafm_mcp_rest_route() ] ) ) {
			$server = \WP\MCP\Core\McpAdapter::instance()->get_server( 'aafm-server' );
			$this->assertNotNull( $server );
			( new \WP\MCP\Transport\HttpTransport( $server->create_transport_context() ) )->register_routes();
		}
		$request = new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' );
		$response = rest_do_request( $request );
		return $as_served ? rest_send_allow_header( $response, rest_get_server(), $request ) : $response;
	}

	public function test_a_paused_grant_still_resolves_a_user_but_the_transport_gate_refuses_it(): void {
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->assertSame( $this->user, $this->resolve() );
		$this->assertFalse( aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) ) );
		$this->assertSame( 1, $this->paused_rows() );
		$this->assertSame( self::CLIENT, aafm_query_activity( array( 'ability' => '(transport)' ) )[0]['client_id'] );

		for ( $i = 0; $i < 10; $i++ ) {
			aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) );
		}
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, $this->paused_rows() );
	}

	public function test_a_paused_grant_is_a_plain_403_forbidden_on_the_wire(): void {
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$log      = wp_tempnam( 'aafm-pause-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture the adapter's log line, if any.

		$response = $this->dispatch();

		ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restore.
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		wp_delete_file( $log );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( '', $logged, 'A plain false writes no adapter error_log line.' );
	}

	public function test_one_refused_request_writes_one_denial_row_although_core_reruns_the_permission_callback(): void {
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$this->assertSame( 403, $this->dispatch( true )->get_status() );
		$this->assertSame( 1, $this->paused_rows(), 'The Allow header re-run must not spend the denial cap.' );

		update_option( 'aafm_ip_allowlist', array( '198.51.100.1' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		aafm_policy_reset_request_state();
		$this->set_paused( null );
		$this->resolve();
		$this->assertSame( 403, $this->dispatch( true )->get_status() );
		$rows = aafm_query_activity(
			array(
				'ability' => '(transport)',
				'status'  => 'denied',
			)
		);
		$this->assertCount( 2, $rows, 'One more row for the blocked address, not four.' );
	}

	public function test_resuming_restores_access_without_a_new_token(): void {
		$this->set_paused( '2026-10-01 00:00:00' );
		$this->resolve();
		$this->assertFalse( aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) ) );

		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => false ) ) );
		$this->resolve();
		$this->assertTrue( aafm_transport_permission_callback( new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/mcp' ) ) );
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
		$this->assertWPError( $result );
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
