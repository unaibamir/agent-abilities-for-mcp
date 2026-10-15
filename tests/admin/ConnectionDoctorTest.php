<?php
/**
 * Connection Doctor: the probe handler and its token rules, every check, the "could not test"
 * state, the support report's redaction, and the AJAX entry point. The loopback requests are
 * stubbed through pre_http_request, so nothing here talks to a network.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class ConnectionDoctorTest extends TestCase {

	use AjaxHarness;

	/**
	 * Loopback requests the stub saw.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	private $requests = array();

	/**
	 * The SERVER_SOFTWARE value the test found, restored in tear_down().
	 *
	 * @var string|null
	 */
	private $server_software;

	/**
	 * The HTTPS server variable the test found, restored in tear_down().
	 *
	 * @var mixed
	 */
	private $https;

	/**
	 * Every probe slot a test armed or the doctor created, by transient name.
	 *
	 * @var list<string>
	 */
	private $slots_seen = array();

	/**
	 * The administrator the doctor runs as.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->requests        = array();
		$this->server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null;
		$this->https           = isset( $_SERVER['HTTPS'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) : null;
		update_option( 'permalink_structure', '/%postname%/' );
		// OAuth is off unless a test turns it on; a row leaked by another suite must not decide this one.
		delete_option( 'aafm_oauth_enabled' );
		$this->admin_id   = $this->acting_as( 'administrator' );
		$this->slots_seen = array();
		add_action(
			'set_transient',
			function ( $transient ): void {
				if ( 0 === strpos( (string) $transient, 'aafm_doctor_probe_' ) ) {
					$this->slots_seen[] = (string) $transient;
				}
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_is_application_passwords_available' );
		remove_all_filters( 'aafm_doctor_known_blockers' );
		foreach ( $this->slots_seen as $slot ) {
			delete_transient( $slot );
		}
		delete_transient( 'aafm_doctor_header_seen' );
		delete_option( 'aafm_oauth_enabled' );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_COOKIE[ LOGGED_IN_COOKIE ] );
		if ( null === $this->server_software ) {
			unset( $_SERVER['SERVER_SOFTWARE'] );
		} else {
			$_SERVER['SERVER_SOFTWARE'] = $this->server_software;
		}
		if ( null === $this->https ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https;
		}
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	/**
	 * Stub every loopback request.
	 *
	 * @param callable $responder fn( string $url, array $args ): array|\WP_Error.
	 * @return void
	 */
	private function respond( callable $responder ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $responder ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				return $responder( (string) $url, (array) $args );
			},
			10,
			3
		);
	}

	/**
	 * A canned HTTP response in the shape pre_http_request wants.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return array<string,mixed>
	 */
	private function http( int $code, string $body = '' ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The JSON body the probe handler sends.
	 *
	 * @param bool $seen  Whether the Authorization header arrived.
	 * @param bool $route Whether the MCP route is registered.
	 * @return string
	 */
	private function doctor_answer( bool $seen, bool $route = true ): string {
		return (string) wp_json_encode(
			array(
				'aafm_doctor' => array(
					'authorization_seen' => $seen,
					'route_registered'   => $route,
				),
			)
		);
	}

	/**
	 * Pick one doctor row by id.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $id   Row id.
	 * @return array<string,mixed>
	 */
	private function row( array $rows, string $id ): array {
		foreach ( $rows as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		$this->fail( "No doctor row with id $id" );
	}

	/**
	 * A responder for a healthy site: the MCP route answers the probe, everything else is a 404.
	 *
	 * @return callable
	 */
	private function endpoint_ok(): callable {
		return function ( string $url ) {
			return false !== strpos( $url, 'agent-abilities-for-mcp' ) ? $this->http( 200, $this->doctor_answer( true ) ) : $this->http( 404 );
		};
	}

	/**
	 * A REST server stand-in that reports the MCP route as registered or not.
	 *
	 * @param bool $has_route Whether the MCP route is registered.
	 * @return \WP_REST_Server
	 */
	private function rest_server( bool $has_route ): \WP_REST_Server {
		$routes = $has_route ? array( aafm_mcp_rest_route() => array() ) : array( '/wp/v2/posts' => array() );
		return new class( $routes ) extends \WP_REST_Server {
			/**
			 * The routes this stand-in reports.
			 *
			 * @var array<string,mixed>
			 */
			private $fixed;

			/**
			 * Build the stand-in.
			 *
			 * @param array<string,mixed> $fixed Routes to report.
			 */
			public function __construct( array $fixed ) {
				parent::__construct();
				$this->fixed = $fixed;
			}

			/**
			 * The fixed routes.
			 *
			 * @param string $route_namespace Unused.
			 * @return array<string,mixed>
			 */
			public function get_routes( $route_namespace = '' ) {
				unset( $route_namespace );
				return $this->fixed;
			}
		};
	}

	/**
	 * A well-formed probe token for a user.
	 *
	 * @param int    $user_id The administrator's id.
	 * @param string $random  The 32-character random part.
	 * @return string
	 */
	private function token_for( int $user_id, string $random = 'abcdefghijklmnopqrstuvwxyz012345' ): string {
		return $user_id . '.' . $random;
	}

	/**
	 * Arm the one-shot probe the way aafm_doctor_probe() does.
	 *
	 * @param int    $user_id The administrator's id.
	 * @param string $token   The plain token.
	 * @return void
	 */
	private function arm( int $user_id, string $token ): void {
		set_transient( $this->slot( $user_id, $token ), hash( 'sha256', $token ), 60 );
	}

	/**
	 * The transient name of one token's slot.
	 *
	 * @param int    $user_id The administrator's id.
	 * @param string $token   The plain token.
	 * @return string
	 */
	private function slot( int $user_id, string $token ): string {
		return aafm_doctor_probe_key( $user_id, $token );
	}

	/**
	 * Assert that no probe slot the test saw is still stored.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assertNoSlotLeft( string $message ): void {
		$this->assertNotSame( array(), $this->slots_seen, 'The test saw no slot at all, so this check proves nothing.' );
		foreach ( $this->slots_seen as $slot ) {
			$this->assertFalse( get_transient( $slot ), $message );
		}
	}

	/**
	 * Every status the doctor explains.
	 *
	 * @return array<string,array{0:int,1:bool,2:string,3:string}>
	 */
	public function statuses(): array {
		return array(
			'401 oauth off'  => array( 401, false, 'warn', 'plugin' ),
			'401 oauth on'   => array( 401, true, 'fail', 'plugin' ),
			'403'            => array( 403, false, 'fail', 'host' ),
			'404'            => array( 404, false, 'fail', 'you' ),
			'406'            => array( 406, false, 'fail', 'host' ),
			'429'            => array( 429, false, 'warn', 'host' ),
			'500'            => array( 500, false, 'fail', 'host' ),
			'503'            => array( 503, false, 'fail', 'host' ),
			'302'            => array( 302, false, 'warn', 'you' ),
			'unexpected 418' => array( 418, false, 'warn', 'host' ),
		);
	}

	/**
	 * Each status maps to a verdict and a person who can fix it.
	 *
	 * @dataProvider statuses
	 *
	 * @param int    $code   HTTP status.
	 * @param bool   $oauth  Whether OAuth is on.
	 * @param string $status Wanted verdict.
	 * @param string $who    Wanted owner.
	 * @return void
	 */
	public function test_http_status_is_explained_with_who_fixes_it( int $code, bool $oauth, string $status, string $who ): void {
		$read = aafm_doctor_interpret_http_status( $code, $oauth );
		$this->assertSame( $status, $read['status'] );
		$this->assertSame( $who, $read['who'] );
		$this->assertNotSame( '', $read['detail'] );
	}

	/**
	 * A POST to the MCP route carrying the probe header.
	 *
	 * @param string $token The header value.
	 * @return \WP_REST_Request
	 */
	private function probe_request( string $token ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'x-aafm-doctor', $token );
		return $request;
	}

	public function test_probe_handler_answers_a_live_token_exactly_once(): void {
		$token = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );
		$_SERVER['HTTP_AUTHORIZATION'] = 'AAFM-Doctor ' . $token;
		$request                       = $this->probe_request( $token );
		$server                        = $this->rest_server( true );

		$response = aafm_doctor_handle_probe( null, $server, $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'aafm_doctor' => array(
					'authorization_seen' => true,
					'route_registered'   => true,
				),
			),
			$response->get_data()
		);
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $request ), 'A token is good for one answer.' );
		$this->assertFalse( get_transient( $this->slot( $this->admin_id, $token ) ), 'The stored hash is deleted on use.' );
	}

	public function test_probe_answer_never_authenticates_anyone_and_never_echoes_the_token(): void {
		$token = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );
		$_SERVER['HTTP_AUTHORIZATION'] = 'AAFM-Doctor ' . $token;
		wp_set_current_user( 0 );

		$response = aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( $token ) );

		$this->assertSame( 0, get_current_user_id(), 'Answering the probe does not log anyone in.' );
		$this->assertSame( array( 'authorization_seen', 'route_registered' ), array_keys( $response->get_data()['aafm_doctor'] ) );
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $response->get_headers(), 'No cookie or token header in the answer.' );
	}

	public function test_probe_handler_reports_a_stripped_authorization_header(): void {
		$token = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$response = aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( $token ) );

		$this->assertFalse( $response->get_data()['aafm_doctor']['authorization_seen'] );
	}

	public function test_probe_handler_accepts_the_redirected_server_variable(): void {
		$token = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'AAFM-Doctor ' . $token;

		$response = aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( $token ) );

		$this->assertTrue( $response->get_data()['aafm_doctor']['authorization_seen'] );
	}

	public function test_probe_handler_reports_a_route_that_is_not_registered(): void {
		$token = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );

		$response = aafm_doctor_handle_probe( null, $this->rest_server( false ), $this->probe_request( $token ) );

		$this->assertFalse( $response->get_data()['aafm_doctor']['route_registered'] );
	}

	public function test_probe_handler_ignores_everything_that_is_not_a_live_probe(): void {
		$token  = $this->token_for( $this->admin_id );
		$server = $this->rest_server( true );
		$this->arm( $this->admin_id, $token );

		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( $this->token_for( $this->admin_id, 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ' ) ) ), 'Wrong token.' );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( '' ) ), 'Empty token.' );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( 'tok123' ) ), 'Not shaped like a probe token.' );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( $token . 'x' ) ), 'Too long.' );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( $this->admin_id . '.' . str_repeat( '!', 32 ) ) ), 'Bad characters.' );

		$other = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_header( 'x-aafm-doctor', $token );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $other ), 'Another route.' );

		$get = new \WP_REST_Request( 'GET', aafm_mcp_rest_route() );
		$get->set_header( 'x-aafm-doctor', $token );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $get ), 'Not a POST.' );

		$taken = new \WP_REST_Response( array( 'x' => 1 ), 200 );
		$this->assertSame( $taken, aafm_doctor_handle_probe( $taken, $server, $this->probe_request( $token ) ), 'Never overrides an earlier answer.' );

		$this->assertNotFalse( get_transient( $this->slot( $this->admin_id, $token ) ), 'None of the refused calls consumed the live token.' );

		delete_transient( $this->slot( $this->admin_id, $token ) );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( $token ) ), 'No live token.' );
	}

	public function test_a_token_is_bound_to_the_admin_who_started_the_run(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );
		$server = $this->rest_server( true );

		// The same random part presented under another user's id looks up that user's slot, which is empty.
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( $this->token_for( $other ) ) ) );
		$this->assertNotFalse( get_transient( $this->slot( $this->admin_id, $token ) ), 'The admin\'s own slot is untouched.' );

		// A slot armed for another user does not answer a token carrying a different user id.
		$this->arm( $other, $this->token_for( $other ) );
		$this->assertNull( aafm_doctor_handle_probe( null, $server, $this->probe_request( $this->token_for( $this->admin_id, 'abcdefghijklmnopqrstuvwxyz012346' ) ) ) );
	}

	public function test_a_token_of_a_user_who_lost_manage_options_is_refused_and_burned(): void {
		$demoted = self::factory()->user->create( array( 'role' => 'editor' ) );
		$token   = $this->token_for( $demoted );
		$this->arm( $demoted, $token );

		$this->assertNull( aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( $token ) ) );
		$this->assertFalse( get_transient( $this->slot( $demoted, $token ) ), 'A refused token is still spent.' );
	}

	public function test_the_token_is_compared_in_constant_time_and_never_written_to_a_log(): void {
		// A timing-safe compare cannot be told from === by behaviour, so pin it in the source.
		$reflection = new \ReflectionFunction( 'aafm_doctor_handle_probe' );
		$source     = implode( '', array_slice( (array) file( (string) $reflection->getFileName() ), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
		$this->assertStringContainsString( 'hash_equals(', $source );
		$this->assertStringNotContainsString( 'aafm_log_activity', $source );
		$this->assertStringNotContainsString( 'error_log', $source );

		$whole = implode( '', (array) file( (string) $reflection->getFileName() ) );
		$this->assertStringNotContainsString( 'aafm_log_activity', $whole, 'The Doctor never writes an activity row.' );
		$this->assertStringNotContainsString( 'error_log', $whole );
	}

	public function test_two_runs_by_the_same_admin_keep_separate_slots(): void {
		$first  = $this->token_for( $this->admin_id, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
		$second = $this->token_for( $this->admin_id, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' );
		$this->assertNotSame( $this->slot( $this->admin_id, $first ), $this->slot( $this->admin_id, $second ) );
		$server = $this->rest_server( true );

		// Run one is in flight (its slot is armed) while run two starts, finishes and cleans up.
		$this->arm( $this->admin_id, $first );
		$this->arm( $this->admin_id, $second );
		$this->assertInstanceOf( \WP_REST_Response::class, aafm_doctor_handle_probe( null, $server, $this->probe_request( $second ) ) );
		$this->assertNotFalse( get_transient( $this->slot( $this->admin_id, $first ) ), 'Run two spending its token leaves run one\'s slot alone.' );
		$this->assertInstanceOf( \WP_REST_Response::class, aafm_doctor_handle_probe( null, $server, $this->probe_request( $first ) ), 'Run one is still answered.' );

		// A whole second doctor run (arm, request, delete) in the middle of a pending first run.
		$this->arm( $this->admin_id, $first );
		$this->respond( $this->endpoint_ok() );
		aafm_doctor_run_checks();
		$this->assertNotFalse( get_transient( $this->slot( $this->admin_id, $first ) ), 'A finished run deletes only its own slot.' );
		$this->assertInstanceOf( \WP_REST_Response::class, aafm_doctor_handle_probe( null, $server, $this->probe_request( $first ) ) );
	}

	public function test_the_doctor_row_is_not_labelled_like_the_rail_row(): void {
		$this->respond( $this->endpoint_ok() );
		$rail = aafm_diagnostic_auth_header_check();

		$this->assertNotSame( $rail['label'], $this->row( aafm_doctor_run_checks(), 'auth_header' )['label'] );
	}

	public function test_the_ajax_answer_carries_the_rail_row_as_it_now_reads(): void {
		$this->respond( $this->endpoint_ok() );
		$this->post_as( 'administrator' );

		$json = $this->run_handler( 'aafm_ajax_run_doctor' );

		$this->assertSame( 'pass', $json['data']['rail']['status'] );
		$this->assertSame( aafm_diagnostic_auth_header_check()['detail'], $json['data']['rail']['detail'] );
	}

	public function test_the_rail_row_can_be_found_by_the_script(): void {
		ob_start();
		aafm_render_connection_tab();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'data-check="auth_header"' ) );
	}

	public function test_the_probe_handler_is_hooked_first(): void {
		$this->assertSame( 1, has_filter( 'rest_pre_dispatch', 'aafm_doctor_handle_probe' ) );
	}

	public function test_a_healthy_site_passes_with_one_request_and_no_credentials_sent(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$stored = array();
		$this->respond(
			function ( string $url, array $args ) use ( &$stored ) {
				$stored[] = get_transient( $this->slot( $this->admin_id, (string) $args['headers']['X-AAFM-Doctor'] ) );
				return $this->endpoint_ok()( $url );
			}
		);

		$rows = aafm_doctor_run_checks();

		$this->assertSame( 'pass', $this->row( $rows, 'endpoint' )['status'] );
		$this->assertSame( 200, $this->row( $rows, 'endpoint' )['http'] );
		$this->assertSame( 'pass', $this->row( $rows, 'auth_header' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'app_passwords' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'permalinks' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'security_plugins' )['status'] );
		$this->assertCount( 1, $this->requests, 'One probe, no discovery requests while OAuth is off.' );

		$args  = $this->requests[0]['args'];
		$token = (string) ( $args['headers']['X-AAFM-Doctor'] ?? '' );
		$this->assertMatchesRegularExpression( '/^' . $this->admin_id . '\.[A-Za-z0-9]{32}$/', $token, 'Token is the admin id plus 32 random characters.' );
		$this->assertSame( 'AAFM-Doctor ' . $token, (string) ( $args['headers']['Authorization'] ?? '' ), 'An auth scheme core and the OAuth validator ignore.' );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertEmpty( $args['cookies'] ?? array(), 'No browser cookies ride along.' );
		$this->assertCount( 1, $stored );
		$this->assertSame( hash( 'sha256', $token ), $stored[0], 'Only a hash of the token is stored.' );
		$this->assertNotSame( $token, $stored[0] );
		$this->assertNoSlotLeft( 'The token does not outlive the run.' );
	}

	public function test_the_stored_token_has_a_short_lifetime(): void {
		$ttl = null;
		add_action(
			'set_transient',
			static function ( $transient, $value, $expiration ) use ( &$ttl ): void {
				unset( $value );
				if ( 0 === strpos( (string) $transient, 'aafm_doctor_probe_' ) ) {
					$ttl = $expiration;
				}
			},
			10,
			3
		);
		$this->respond( $this->endpoint_ok() );

		aafm_doctor_run_checks();

		$this->assertIsInt( $ttl );
		$this->assertGreaterThan( 0, $ttl );
		$this->assertLessThanOrEqual( 60, $ttl );
	}

	public function test_the_doctor_refuses_to_probe_without_an_administrator(): void {
		$this->respond( $this->endpoint_ok() );

		wp_set_current_user( 0 );
		$rows = aafm_doctor_run_checks();
		$this->assertSame( 'skip', $this->row( $rows, 'endpoint' )['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$rows = aafm_doctor_run_checks();
		$this->assertSame( 'skip', $this->row( $rows, 'endpoint' )['status'] );

		$this->assertSame( array(), $this->requests, 'No request, and so no token, for a user who may not run it.' );
	}

	public function test_a_stripped_header_is_a_host_failure_with_the_matching_snippet(): void {
		$this->respond(
			function () {
				return $this->http( 200, $this->doctor_answer( false ) );
			}
		);
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';

		$row = $this->row( aafm_doctor_run_checks(), 'auth_header' );

		$this->assertSame( 'fail', $row['status'] );
		$this->assertSame( 'host', $row['who'] );
		$this->assertStringContainsString( 'fastcgi_param HTTP_AUTHORIZATION', $row['fix'] );
		$this->assertStringNotContainsString( 'RewriteRule', $row['fix'] );

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Unix)';
		$row                        = $this->row( aafm_doctor_run_checks(), 'auth_header' );
		$this->assertStringContainsString( 'RewriteRule', $row['fix'] );
		$this->assertStringNotContainsString( 'fastcgi_param', $row['fix'] );

		$_SERVER['SERVER_SOFTWARE'] = 'SomethingElse/1';
		$row                        = $this->row( aafm_doctor_run_checks(), 'auth_header' );
		$this->assertStringContainsString( 'RewriteRule', $row['fix'] );
		$this->assertStringContainsString( 'fastcgi_param', $row['fix'] );
	}

	public function test_the_doctor_and_the_help_tab_share_one_header_snippet(): void {
		$snippets                   = aafm_authorization_header_snippets();
		$_SERVER['SERVER_SOFTWARE'] = 'SomethingElse/1';
		$fix                        = aafm_doctor_header_snippet();
		foreach ( array_merge( $snippets['apache'], $snippets['nginx'] ) as $line ) {
			$this->assertStringContainsString( $line, $fix, 'The Doctor shows the shared line.' );
		}

		ob_start();
		aafm_render_help_tab();
		$help = (string) ob_get_clean();
		foreach ( array_merge( $snippets['apache'], $snippets['nginx'] ) as $line ) {
			$this->assertStringContainsString( 'data-copy="' . esc_attr( $line ) . '"', $help, 'The Help tab shows the shared line.' );
		}
	}

	public function test_a_site_that_cannot_call_itself_says_could_not_test_and_never_fail(): void {
		$this->respond(
			static function () {
				return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to secret-host.internal port 443' );
			}
		);

		$rows = aafm_doctor_run_checks();

		$this->assertSame( 'skip', $this->row( $rows, 'endpoint' )['status'] );
		$this->assertSame( 'skip', $this->row( $rows, 'auth_header' )['status'] );
		foreach ( $rows as $row ) {
			if ( in_array( $row['id'], array( 'endpoint', 'auth_header' ), true ) ) {
				$this->assertNotSame( 'fail', $row['status'] );
			}
		}
		$report = aafm_doctor_build_report( $rows );
		$this->assertStringContainsString( 'http_request_failed', $report );
		$this->assertStringNotContainsString( 'secret-host.internal', $report );
		$this->assertNoSlotLeft( 'A failed request still spends the token.' );
	}

	public function test_a_blocked_request_is_explained_and_the_header_row_is_not_blamed(): void {
		$this->respond(
			function () {
				return $this->http( 403 );
			}
		);

		$rows = aafm_doctor_run_checks();

		$this->assertSame( 'fail', $this->row( $rows, 'endpoint' )['status'] );
		$this->assertSame( 'host', $this->row( $rows, 'endpoint' )['who'] );
		$this->assertSame( 403, $this->row( $rows, 'endpoint' )['http'] );
		$this->assertSame( 'skip', $this->row( $rows, 'auth_header' )['status'] );
	}

	public function test_an_unregistered_mcp_route_fails_the_endpoint_row_even_though_the_probe_was_answered(): void {
		$this->respond(
			function () {
				return $this->http( 200, $this->doctor_answer( true, false ) );
			}
		);

		$rows = aafm_doctor_run_checks();

		$endpoint = $this->row( $rows, 'endpoint' );
		$this->assertSame( 'fail', $endpoint['status'] );
		$this->assertSame( 'plugin', $endpoint['who'] );
		$this->assertSame( 200, $endpoint['http'] );
		$this->assertSame( 'pass', $this->row( $rows, 'auth_header' )['status'], 'The header check is still valid.' );
	}

	public function test_application_passwords_switched_off_by_a_filter_names_the_filter(): void {
		// Core's "supported" test is is_ssl() or a local environment; make it true so the row is the filtered one.
		$_SERVER['HTTPS'] = 'on';
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->respond( $this->endpoint_ok() );

		$row = $this->row( aafm_doctor_run_checks(), 'app_passwords' );

		$this->assertSame( 'fail', $row['status'] );
		$this->assertSame( 'plugin', $row['who'] );
		$this->assertStringContainsString( 'filter', $row['detail'] );
	}

	public function test_application_passwords_on_a_site_that_is_not_https_say_so(): void {
		if ( 'local' === wp_get_environment_type() || 'development' === wp_get_environment_type() ) {
			$this->markTestSkipped( 'Core treats a local environment as supported, so the plain-HTTP branch cannot be reached.' );
		}
		unset( $_SERVER['HTTPS'] );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->respond( $this->endpoint_ok() );

		$row = $this->row( aafm_doctor_run_checks(), 'app_passwords' );

		$this->assertSame( 'fail', $row['status'] );
		$this->assertSame( 'you', $row['who'] );
	}

	/**
	 * Final review M4: behind a TLS-terminating proxy the home URL is https while the request is
	 * not, so "this site is on plain HTTP" would be wrong. The wording must name the proxy header.
	 * (The row itself is unreachable in a local environment, which core treats as supported.)
	 */
	public function test_unsupported_application_passwords_behind_a_tls_proxy_blame_the_forwarded_header(): void {
		$proxy = aafm_doctor_unsupported_detail( 'https' );
		$plain = aafm_doctor_unsupported_detail( 'http' );

		$this->assertStringContainsString( 'X-Forwarded-Proto', $proxy );
		$this->assertStringNotContainsString( 'plain HTTP', $proxy );
		$this->assertStringContainsString( 'plain HTTP', $plain );
	}

	public function test_http_and_https_site_urls(): void {
		$this->respond( $this->endpoint_ok() );
		update_option( 'home', 'http://example.org' );
		$this->assertSame( 'warn', $this->row( aafm_doctor_run_checks(), 'https' )['status'] );
		update_option( 'home', 'https://example.org' );
		$this->assertSame( 'pass', $this->row( aafm_doctor_run_checks(), 'https' )['status'] );
	}

	public function test_plain_permalinks_warn_and_pretty_pass(): void {
		$this->respond( $this->endpoint_ok() );
		update_option( 'permalink_structure', '' );
		$row = $this->row( aafm_doctor_run_checks(), 'permalinks' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'you', $row['who'] );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( 'pass', $this->row( aafm_doctor_run_checks(), 'permalinks' )['status'] );
	}

	public function test_a_known_security_plugin_that_is_active_is_named(): void {
		add_filter(
			'aafm_doctor_known_blockers',
			static function ( array $known ): array {
				$known['fake-firewall/fake-firewall.php'] = 'Fake Firewall';
				return $known;
			}
		);
		update_option( 'active_plugins', array( 'fake-firewall/fake-firewall.php' ) );
		$this->respond( $this->endpoint_ok() );

		$row = $this->row( aafm_doctor_run_checks(), 'security_plugins' );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'plugin', $row['who'] );
		$this->assertStringContainsString( 'Fake Firewall', $row['detail'] );
	}

	public function test_oauth_discovery_is_checked_only_when_oauth_is_on(): void {
		$this->respond( $this->endpoint_ok() );
		$ids = wp_list_pluck( aafm_doctor_run_checks(), 'id' );
		$this->assertNotContains( 'oauth_discovery', $ids );

		update_option( 'aafm_oauth_enabled', '1' );
		$this->requests = array();
		remove_all_filters( 'pre_http_request' );
		$this->respond(
			function ( string $url ) {
				if ( false !== strpos( $url, '.well-known' ) ) {
					return $this->http( 404 );
				}
				return $this->http( 200, $this->doctor_answer( true ) );
			}
		);
		$row = $this->row( aafm_doctor_run_checks(), 'oauth_discovery' );

		$this->assertSame( 'fail', $row['status'] );
		$this->assertSame( 'host', $row['who'] );
		$this->assertStringContainsString( 'location ^~ /.well-known/', $row['fix'] );
		$this->assertSame( 404, $row['http'] );
		$this->assertCount( 3, $this->requests, 'One probe plus the two discovery documents.' );
	}

	public function test_oauth_discovery_passes_when_both_documents_answer_json(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$this->respond(
			function ( string $url ) {
				if ( false !== strpos( $url, '.well-known' ) ) {
					return $this->http( 200, '{"resource":"x"}' );
				}
				return $this->http( 200, $this->doctor_answer( true ) );
			}
		);

		$this->assertSame( 'pass', $this->row( aafm_doctor_run_checks(), 'oauth_discovery' )['status'] );
	}

	public function test_a_doctor_run_writes_no_activity_rows(): void {
		$this->respond( $this->endpoint_ok() );
		$before = aafm_activity_count();

		aafm_doctor_run_checks();

		$this->assertSame( $before, aafm_activity_count() );
	}

	public function test_a_probe_that_fails_or_is_refused_writes_no_activity_rows(): void {
		$before = aafm_activity_count();
		$token  = $this->token_for( $this->admin_id );
		$this->arm( $this->admin_id, $token );

		aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( 'bad' ) );
		aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( $token ) );
		aafm_doctor_handle_probe( null, $this->rest_server( true ), $this->probe_request( $token ) );

		$this->assertSame( $before, aafm_activity_count() );
	}

	public function test_the_rail_row_shows_the_last_probe_result_instead_of_a_permanent_warning(): void {
		$rail = static function (): array {
			return array_column( aafm_diagnostic_checks(), null, 'id' )['auth_header'];
		};

		$this->assertSame( 'warn', $rail()['status'], 'Not tested yet.' );

		$this->respond( $this->endpoint_ok() );
		aafm_doctor_run_checks();
		$this->assertSame( 'pass', $rail()['status'] );

		remove_all_filters( 'pre_http_request' );
		$this->respond(
			function () {
				return $this->http( 200, $this->doctor_answer( false ) );
			}
		);
		aafm_doctor_run_checks();
		$this->assertSame( 'fail', $rail()['status'] );

		remove_all_filters( 'pre_http_request' );
		$this->respond(
			static function () {
				return new \WP_Error( 'http_request_failed', 'x' );
			}
		);
		aafm_doctor_run_checks();
		$this->assertSame( 'warn', $rail()['status'], 'A run that could not test forgets the old answer.' );
	}

	public function test_the_connection_tab_has_one_authorization_header_row_in_the_rail(): void {
		ob_start();
		aafm_render_connection_tab();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'Authorization header reaches WordPress' ) );
	}

	public function test_the_report_holds_no_secret_and_only_the_site_host(): void {
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'secretlogin-xyz',
			)
		);
		wp_set_current_user( $admin_id );
		$created = \WP_Application_Passwords::create_new_application_password( $admin_id, array( 'name' => 'doctor test' ) );
		$this->assertIsArray( $created );
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'cookie-secret-value';
		$this->respond( $this->endpoint_ok() );

		$rows   = aafm_doctor_run_checks();
		$report = aafm_doctor_build_report( $rows );

		$token = (string) ( $this->requests[0]['args']['headers']['X-AAFM-Doctor'] ?? '' );
		$this->assertNotSame( '', $token );
		$this->assertStringNotContainsString( $token, $report, 'The probe token.' );
		$this->assertStringNotContainsString( substr( $token, strpos( $token, '.' ) + 1 ), $report, 'The random part of the probe token.' );
		$this->assertStringNotContainsString( (string) $created[0], $report, 'Application password.' );
		$this->assertStringNotContainsString( 'secretlogin-xyz', $report, 'User name.' );
		$this->assertStringNotContainsString( 'cookie-secret-value', $report, 'Cookie value.' );
		$this->assertStringNotContainsString( LOGGED_IN_COOKIE, $report, 'Cookie name.' );
		$this->assertStringNotContainsString( 'http://', $report, 'No URLs.' );
		$this->assertStringNotContainsString( 'https://', $report, 'No URLs.' );
		$this->assertStringNotContainsString( 'wp-json', $report, 'No endpoint path.' );
		$this->assertStringContainsString( 'Site host: example.org', $report );
		$this->assertStringContainsString( 'Plugin: ' . AAFM_VERSION, $report );
		$this->assertStringContainsString( '- endpoint: pass HTTP 200', $report );
		$this->assertStringContainsString( 'Active plugins (', $report );
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $rows ), 'The rows the browser gets carry no token either.' );
	}

	public function test_the_ajax_entry_point_returns_rows_and_a_report_to_an_admin(): void {
		$this->respond( $this->endpoint_ok() );
		$this->post_as( 'administrator' );

		$json = $this->run_handler( 'aafm_ajax_run_doctor' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertContains( 'endpoint', wp_list_pluck( $json['data']['rows'], 'id' ) );
		$this->assertStringContainsString( 'Agent Abilities for MCP connection report', $json['data']['report'] );
	}

	public function test_the_ajax_entry_point_refuses_a_non_admin_and_a_bad_nonce_without_a_request(): void {
		$this->respond( $this->endpoint_ok() );

		$this->post_as( 'editor' );
		$json = $this->run_handler( 'aafm_ajax_run_doctor' );
		$this->assertFalse( (bool) ( $json['success'] ?? true ) );

		$this->post_as( 'administrator' );
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$json              = $this->run_handler( 'aafm_ajax_run_doctor' );
		$this->assertSame( array(), $json );

		$this->assertSame( array(), $this->requests, 'No loopback request was made for either refused call.' );
	}

	public function test_the_connection_tab_renders_the_card_labelled_as_run_from_this_server(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_connection_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aafm-doctor"', $html );
		$this->assertStringContainsString( 'id="aafm-doctor-run"', $html );
		$this->assertStringContainsString( 'id="aafm-doctor-show-all"', $html );
		$this->assertMatchesRegularExpression( '/id="aafm-doctor-copy"[^>]*hidden/', $html );
		$this->assertStringContainsString( 'run from this site&#039;s own server', str_replace( '&apos;', '&#039;', $html ) );
	}
}
