<?php
/**
 * Connection Doctor v2: MCP transport headers, JSON bodies, named redirects, stale and blocked
 * discovery documents, and the Plain-permalinks endpoint address. Loopback requests are stubbed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class DoctorV2Test extends TestCase {

	/**
	 * Requests the stub saw.
	 *
	 * @var array<int,string>
	 */
	private $urls = array();

	public function set_up(): void {
		parent::set_up();
		$this->urls = array();
		update_option( 'permalink_structure', '/%postname%/' );
		delete_option( 'aafm_oauth_enabled' );
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'aafm_doctor_wellknown_file_exists' );
		delete_option( 'aafm_oauth_enabled' );
		parent::tear_down();
	}

	/**
	 * Stub every loopback request.
	 *
	 * @param callable $responder fn( string $url ): array.
	 * @return void
	 */
	private function respond( callable $responder ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $responder ) {
				$this->urls[] = (string) $url;
				return $responder( (string) $url );
			},
			10,
			3
		);
	}

	/**
	 * A canned response.
	 *
	 * @param int                  $code    Status.
	 * @param string               $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @return array<string,mixed>
	 */
	private function http( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
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
	 * The probe handler's answer.
	 *
	 * @param bool $headers Transport headers seen.
	 * @param bool $json    JSON body seen.
	 * @return string
	 */
	private function answer( bool $headers = true, bool $json = true ): string {
		return (string) wp_json_encode(
			array(
				'aafm_doctor' => array(
					'authorization_seen'     => true,
					'route_registered'       => true,
					'transport_headers_seen' => $headers,
					'json_body_seen'         => $json,
				),
			)
		);
	}

	/**
	 * One row by id, or null.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $id   Id.
	 * @return array<string,mixed>|null
	 */
	private function row( array $rows, string $id ): ?array {
		foreach ( $rows as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Run the probe handler for a live token with the given headers and body.
	 *
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    Request body.
	 * @return array<string,mixed>
	 */
	private function handle( array $headers, string $body ): array {
		$user  = get_current_user_id();
		$token = $user . '.abcdefghijklmnopqrstuvwxyz012345';
		set_transient( aafm_doctor_probe_key( $user, $token ), hash( 'sha256', $token ), 60 );
		$request = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'x-aafm-doctor', $token );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $body );
		$response = aafm_doctor_handle_probe( null, rest_get_server(), $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response->get_data()['aafm_doctor'];
	}

	public function test_the_probe_sends_the_mcp_headers_and_a_json_body_and_the_handler_reports_both(): void {
		$sent = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$sent ) {
				$sent = $args;
				return $this->http( 200, $this->answer() );
			},
			10,
			2
		);
		$probe = aafm_doctor_probe();
		$this->assertSame( '2025-06-18', $sent['headers']['MCP-Protocol-Version'] );
		$this->assertSame( 'aafm-doctor', $sent['headers']['Mcp-Session-Id'] );
		$this->assertSame( '{"aafm_doctor":"json"}', $sent['body'] );
		$this->assertTrue( $probe['headers'] );
		$this->assertTrue( $probe['json'] );

		$mcp = array(
			'mcp-protocol-version' => '2025-06-18',
			'mcp-session-id'       => 'aafm-doctor',
		);
		$this->assertTrue( $this->handle( $mcp, '{"aafm_doctor":"json"}' )['transport_headers_seen'] );
		$this->assertTrue( $this->handle( $mcp, '{"aafm_doctor":"json"}' )['json_body_seen'] );
		$this->assertFalse( $this->handle( array(), '{"aafm_doctor":"json"}' )['transport_headers_seen'] );
		$this->assertFalse( $this->handle( $mcp, '' )['json_body_seen'] );
	}

	public function test_stripped_mcp_headers_give_a_warning_row_owned_by_the_host(): void {
		$this->respond( fn() => $this->http( 200, $this->answer( false, true ) ) );
		$row = $this->row( aafm_doctor_run_checks(), 'transport_headers' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'host', $row['who'] );
	}

	public function test_a_dropped_json_body_gives_a_warning_row(): void {
		$this->respond( fn() => $this->http( 200, $this->answer( true, false ) ) );
		$this->assertSame( 'warn', $this->row( aafm_doctor_run_checks(), 'json_post' )['status'] );
	}

	public function test_both_pass_when_seen(): void {
		$this->respond( fn() => $this->http( 200, $this->answer() ) );
		$rows = aafm_doctor_run_checks();
		$this->assertSame( 'pass', $this->row( $rows, 'transport_headers' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'json_post' )['status'] );
	}

	/**
	 * Redirects and their names.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function redirects(): array {
		return array(
			'http to https' => array( 'http://x.test/mcp', 'https://x.test/mcp', 'http_to_https' ),
			'https to http' => array( 'https://x.test/mcp', 'http://x.test/mcp', 'https_to_http' ),
			'www added'     => array( 'https://x.test/mcp', 'https://www.x.test/mcp', 'www_added' ),
			'www removed'   => array( 'https://www.x.test/mcp', 'https://x.test/mcp', 'www_removed' ),
			'slash'         => array( 'https://x.test/mcp', 'https://x.test/mcp/', 'trailing_slash' ),
			'other host'    => array( 'https://x.test/mcp', 'https://y.test/mcp', 'other_host' ),
			'other path'    => array( 'https://x.test/mcp', 'https://x.test/elsewhere', 'other_path' ),
			'protocol rel'  => array( 'https://x.test/mcp', '//x.test/mcp', 'unknown' ),
			'malformed'     => array( 'https://x.test/mcp', 'not a url', 'unknown' ),
		);
	}

	/**
	 * Each redirect gets its name.
	 *
	 * @dataProvider redirects
	 *
	 * @param string $from Endpoint.
	 * @param string $to   Location.
	 * @param string $kind Expected name.
	 */
	public function test_redirects_are_named( string $from, string $to, string $kind ): void {
		$this->assertSame( $kind, aafm_doctor_describe_redirect( $from, $to ) );
	}

	public function test_a_redirect_row_names_the_kind_and_keeps_the_address_out_of_the_report(): void {
		$final = str_replace( 'http://', 'https://', aafm_endpoint_url() );
		$this->respond( fn() => $this->http( 301, '', array( 'location' => $final ) ) );
		$rows = aafm_doctor_run_checks();
		$row  = $this->row( $rows, 'endpoint' );
		$this->assertStringContainsString( 'redirects from http to https', $row['detail'] );
		$this->assertSame( $final, $row['fix'] );
		$this->assertStringNotContainsString( '://', aafm_doctor_build_report( $rows ) );
	}

	public function test_a_stale_discovery_document_is_flagged(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$this->respond(
			function ( string $url ) {
				if ( false !== strpos( $url, 'protected-resource' ) ) {
					return $this->http( 200, '{"resource":"https://old-site.test/wp-json/agent-abilities-for-mcp/mcp"}' );
				}
				if ( false !== strpos( $url, 'authorization-server' ) ) {
					return $this->http( 200, (string) wp_json_encode( array( 'issuer' => aafm_oauth_issuer() ) ) );
				}
				return $this->http( 200, $this->answer() );
			}
		);
		$row = $this->row( aafm_doctor_run_checks(), 'oauth_discovery_stale' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'you', $row['who'] );
		$this->assertStringContainsString( 'names a different site address than this endpoint', $row['detail'] );
		$this->assertStringNotContainsString( 'old-site.test', $row['detail'] );
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $row['detail'] );

		remove_all_filters( 'pre_http_request' );
		$this->respond(
			function ( string $url ) {
				if ( false !== strpos( $url, 'protected-resource' ) ) {
					return $this->http( 200, (string) wp_json_encode( array( 'resource' => aafm_endpoint_url() ) ) );
				}
				if ( false !== strpos( $url, 'authorization-server' ) ) {
					return $this->http( 200, (string) wp_json_encode( array( 'issuer' => aafm_oauth_issuer() ) ) );
				}
				return $this->http( 200, $this->answer() );
			}
		);
		$this->assertNull( $this->row( aafm_doctor_run_checks(), 'oauth_discovery_stale' ) );
		add_filter( 'aafm_doctor_wellknown_file_exists', '__return_true' );
		$this->assertNotNull( $this->row( aafm_doctor_run_checks(), 'oauth_discovery_stale' ), 'A static file shadows WordPress.' );
	}

	public function test_a_blocked_well_known_with_a_working_rest_copy_says_so(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$rest = 200;
		$this->respond(
			function ( string $url ) use ( &$rest ) {
				if ( false !== strpos( $url, '.well-known' ) ) {
					return $this->http( 403 );
				}
				if ( false !== strpos( $url, '/protected-resource' ) ) {
					return $this->http( $rest, 200 === $rest ? '{"resource":"x"}' : '' );
				}
				return $this->http( 200, $this->answer() );
			}
		);
		$rows = aafm_doctor_run_checks();
		$this->assertSame( 'fail', $this->row( $rows, 'oauth_discovery' )['status'] );
		$row = $this->row( $rows, 'oauth_discovery_rest' );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( '/.well-known/', $row['detail'] );
		$this->assertStringContainsString( rest_url( aafm_oauth_rest_namespace() . '/protected-resource' ), $row['fix'] );

		$rest = 404;
		$this->assertSame( 'fail', $this->row( aafm_doctor_run_checks(), 'oauth_discovery_rest' )['status'] );
	}

	public function test_the_rest_fallback_is_only_asked_when_the_well_known_check_failed(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$this->respond(
			function ( string $url ) {
				if ( false !== strpos( $url, 'protected-resource' ) && false !== strpos( $url, '.well-known' ) ) {
					return $this->http( 200, (string) wp_json_encode( array( 'resource' => aafm_endpoint_url() ) ) );
				}
				if ( false !== strpos( $url, 'authorization-server' ) ) {
					return $this->http( 200, (string) wp_json_encode( array( 'issuer' => aafm_oauth_issuer() ) ) );
				}
				return $this->http( 200, $this->answer() );
			}
		);
		$rows = aafm_doctor_run_checks();
		$this->assertNull( $this->row( $rows, 'oauth_discovery_rest' ) );
		$this->assertCount( 3, $this->urls, 'The probe and the two documents only.' );
	}

	public function test_plain_permalinks_row_carries_the_rest_route_address(): void {
		update_option( 'permalink_structure', '' );
		$this->respond( fn() => $this->http( 200, $this->answer() ) );
		$row = $this->row( aafm_doctor_run_checks(), 'permalinks' );
		$this->assertSame( aafm_endpoint_url(), $row['fix'] );
		$this->assertStringContainsString( '?rest_route=/', $row['fix'] );
		$this->assertStringContainsString( 'Use this exact endpoint address in your agent', $row['detail'] );
	}

	public function test_the_outbound_requests_stay_on_this_site(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$this->respond( fn() => $this->http( 403 ) );
		aafm_doctor_run_checks();
		$this->assertNotEmpty( $this->urls );
		foreach ( $this->urls as $url ) {
			$this->assertTrue( 0 === strpos( $url, home_url() ) || 0 === strpos( $url, rest_url() ), $url );
		}
	}

	public function test_no_row_and_no_report_line_carries_a_host_name(): void {
		update_option( 'home', 'https://private-site.test' );
		update_option( 'siteurl', 'https://private-site.test' );
		update_option( 'aafm_oauth_enabled', '1' );
		$cases = array(
			fn( string $url ) => false !== strpos( $url, '.well-known' ) ? $this->http( 403 ) : $this->http( 301, '', array( 'location' => 'https://www.private-site.test/redirected' ) ),
			fn( string $url ) => false !== strpos( $url, '.well-known' ) ? $this->http( 200, '{"resource":"https://private-site.test/old","issuer":"https://private-site.test/old"}' ) : $this->http( 200, $this->answer() ),
		);
		foreach ( $cases as $case ) {
			remove_all_filters( 'pre_http_request' );
			$this->respond( $case );
			$rows = aafm_doctor_run_checks();
			foreach ( $rows as $row ) {
				$this->assertStringNotContainsString( 'private-site.test', $row['detail'], $row['id'] );
				$this->assertStringNotContainsString( 'private-site.test', $row['who'], $row['id'] );
			}
			$this->assertStringNotContainsString( 'private-site.test', aafm_doctor_build_report( $rows ) );
		}
	}

	public function test_run_checks_returns_the_new_rows_in_a_stable_order_and_only_asks_for_discovery_when_oauth_is_on(): void {
		$this->respond( fn() => $this->http( 200, $this->answer() ) );
		$this->assertSame(
			array( 'endpoint', 'auth_header', 'transport_headers', 'json_post', 'app_passwords', 'https', 'permalinks', 'security_plugins' ),
			wp_list_pluck( aafm_doctor_run_checks(), 'id' )
		);
		$this->assertCount( 1, $this->urls );
		update_option( 'aafm_oauth_enabled', '1' );
		$this->assertContains( 'oauth_discovery', wp_list_pluck( aafm_doctor_run_checks(), 'id' ) );
	}

	/**
	 * A row that passed has nothing to fix, so it carries no "who can fix this" badge. There is no JS
	 * runner here: this pins the renderer's guard in admin.js.
	 */
	public function test_a_passing_row_shows_no_owner_badge(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$this->assertStringContainsString( "if ( 'pass' !== state && whoLabel[ row.who ] ) {", $js );
	}
}
