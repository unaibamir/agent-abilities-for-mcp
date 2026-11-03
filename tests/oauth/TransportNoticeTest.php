<?php
/**
 * A refused MCP request writes no adapter error_log lines: the transport callback returns false,
 * not a WP_Error, and records why it refused. A callback that throws is still logged.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class TransportNoticeTest extends TestCase {

	/**
	 * The temporary error log file.
	 *
	 * @var string
	 */
	private $log_file = '';

	/**
	 * The error_log ini value before the test.
	 *
	 * @var string
	 */
	private $saved_log = '';

	public function set_up(): void {
		parent::set_up();
		$this->log_file  = (string) wp_tempnam( 'aafm-notice' );
		$this->saved_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored in tear_down().
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->saved_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the value set_up() replaced.
		wp_delete_file( $this->log_file );
		delete_option( 'aafm_ip_allowlist' );
		remove_all_filters( 'pre_option_aafm_ip_allowlist' );
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'] );
		parent::tear_down();
	}

	/**
	 * Serve one initialize POST to the MCP route.
	 *
	 * @return \Spy_REST_Server
	 */
	private function serve(): \Spy_REST_Server {
		$this->route_as_rest_request();
		$server                        = $this->mcp_spy_server();
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_SERVER['CONTENT_TYPE']       = 'application/json';
		$_SERVER['HTTP_ACCEPT']        = 'application/json, text/event-stream';
		$GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the body core's get_raw_data() reads.
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'notice-test',
						'version' => '1.0',
					),
				),
			)
		);
		$server->serve_request( aafm_mcp_rest_route() );
		return $server;
	}

	/**
	 * How many adapter permission lines the log holds.
	 *
	 * @return int
	 */
	private function adapter_lines(): int {
		$log = (string) file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file.
		return substr_count( $log, 'HttpTransport::check_permission' );
	}

	public function test_unauthenticated_request_logs_nothing(): void {
		wp_set_current_user( 0 );
		$server = $this->serve();
		$this->assertSame( 0, $this->adapter_lines() );
		$this->assertSame( 401, $server->status );
		$this->assertSame( 'rest_forbidden', json_decode( (string) $server->sent_body, true )['code'] ?? null );
	}

	public function test_ip_blocked_request_logs_nothing(): void {
		$this->acting_as( 'administrator' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );
		$server = $this->serve();
		$this->assertSame( 0, $this->adapter_lines() );
		$this->assertSame( 403, $server->status );
	}

	public function test_a_throwing_callback_still_logs(): void {
		$this->acting_as( 'administrator' );
		add_filter(
			'pre_option_aafm_ip_allowlist',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);
		$server = $this->serve();
		$this->assertGreaterThan( 0, $this->adapter_lines() );
		$this->assertContains( $server->status, array( 401, 403 ) );
	}

	public function test_refusal_reason_is_recorded_per_request(): void {
		wp_set_current_user( 0 );
		$first  = new \WP_REST_Request();
		$second = new \WP_REST_Request();
		$this->assertFalse( aafm_transport_permission_callback( $first ) );
		$this->assertSame( 'unauthenticated', aafm_transport_refusal( $first ) );
		$this->assertNull( aafm_transport_refusal( $second ) );
		$this->assertNull( aafm_transport_refusal( null ) );
	}
}
