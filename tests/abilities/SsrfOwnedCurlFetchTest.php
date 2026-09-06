<?php
/**
 * Real-network proof that aafm_ssrf_owned_curl_fetch() (includes/abilities/media.php) actually
 * aborts a transfer, on a handle this plugin owns outright, rather than merely rejecting a
 * fully-downloaded oversized result after the fact.
 *
 * Codex final round 7/8 MEDIUM: the interim fix bounded the download via WP's own
 * 'limit_response_size' request arg, which correctly rejected an oversized result but only after
 * the (bounded) transfer completed - a hostile or slow server could still occupy a PHP worker and
 * consume bandwidth for up to the 10-second timeout. These tests run a REAL local `php -S`
 * server (no pre_http_request mock - that would prove nothing about the actual curl callbacks)
 * and assert the fetch returns almost immediately, not after a multi-second sleep or the full
 * timeout, which only a genuine mid-transfer abort can produce.
 *
 * Calls aafm_ssrf_owned_curl_fetch() DIRECTLY, bypassing aafm_ssrf_safe_fetch_url()'s https-only/
 * IP-literal/private-range gates entirely: those are already covered by
 * tests/abilities/UploadMediaFromUrlSsrfTest.php, and a bare `php -S` server cannot speak TLS at
 * all, so a local end-to-end test necessarily has to exercise the fetch mechanism on its own,
 * scheme-and-validation-agnostic terms.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class SsrfOwnedCurlFetchTest extends TestCase {

	/**
	 * The running local `php -S` fixture server, or null before start_local_server() is called.
	 *
	 * @var resource|null
	 */
	private $server_process;

	/**
	 * Base URL of the running local fixture server (e.g. "http://127.0.0.1:54321").
	 *
	 * @var string
	 */
	private string $base_url = '';

	public function tear_down(): void {
		if ( is_resource( $this->server_process ) ) {
			proc_terminate( $this->server_process );
			proc_close( $this->server_process );
			$this->server_process = null;
		}
		parent::tear_down();
	}

	/**
	 * Starts a real `php -S` server on a free local port serving router.php, and waits (bounded)
	 * until it actually accepts a connection before returning - a fixed short sleep would be
	 * flaky under load.
	 */
	private function start_local_server(): void {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		if ( false === $socket ) {
			$this->markTestSkipped( "Could not reserve a local port: $errstr" );
		}
		$name = stream_socket_get_name( $socket, false );
		$port = (int) substr( (string) $name, strrpos( (string) $name, ':' ) + 1 );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-only: a plain TCP probe socket to find a free local port, not a WP file operation.

		$router = __DIR__ . '/../Fixtures/SsrfLocalServer/router.php';
		$cmd    = array( PHP_BINARY, '-S', "127.0.0.1:{$port}", $router );
		$proc   = proc_open( $cmd, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- test-only: spawns the local `php -S` fixture server these tests fetch against; never runs on a live site.
		if ( ! is_resource( $proc ) ) {
			$this->markTestSkipped( 'Could not start the local test server.' );
		}
		$this->server_process = $proc;
		$this->base_url       = "http://127.0.0.1:{$port}";

		$deadline = microtime( true ) + 3.0;
		while ( microtime( true ) < $deadline ) {
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- test-only: polling for the server socket to accept; a connection refusal here is expected and retried.
			if ( false !== $conn ) {
				fclose( $conn ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-only: closes the probe connection, not a WP file operation.
				return;
			}
			usleep( 50000 );
		}
		$this->markTestSkipped( 'The local test server never started accepting connections.' );
	}

	public function test_aborts_before_any_body_byte_on_an_honest_oversized_content_length(): void {
		$this->start_local_server();
		$max_bytes = 1000;

		$started = microtime( true );
		$out     = aafm_ssrf_owned_curl_fetch( "{$this->base_url}/?mode=honest-oversized-header", '127.0.0.1', 0, '127.0.0.1', $max_bytes );
		$elapsed = microtime( true ) - $started;

		// The fixture sleeps 5s before writing any body byte. A genuine header-triggered abort
		// returns in well under 1s; anything close to 5s means the abort did not fire and the
		// call instead waited through (some of) the sleep.
		$this->assertLessThan( 2.0, $elapsed, 'The fetch must abort on the oversized header, not wait for the server to ever write a body byte.' );

		$this->assertIsArray( $out );
		$this->assertSame( '', $out['body'], 'Zero body bytes must have been consumed.' );
		$this->assertArrayHasKey( 'content-length', $out['headers'] );
		$this->assertSame( '999999999', $out['headers']['content-length'] );
	}

	public function test_aborts_mid_transfer_once_the_real_byte_count_exceeds_the_cap_with_no_content_length_header(): void {
		$this->start_local_server();
		$max_bytes = 1000;

		$started = microtime( true );
		$out     = aafm_ssrf_owned_curl_fetch( "{$this->base_url}/?mode=lying-stream", '127.0.0.1', 0, '127.0.0.1', $max_bytes );
		$elapsed = microtime( true ) - $started;

		// The fixture streams forever. A genuine mid-transfer abort returns in well under a
		// second (a handful of 4096-byte chunks past the 1000-byte cap); the 10-second
		// CURLOPT_TIMEOUT this function sets is the only other way the call could ever return.
		$this->assertLessThan( 5.0, $elapsed, 'The fetch must abort once the byte cap is exceeded, not run until the timeout.' );

		$this->assertIsArray( $out );
		$this->assertGreaterThan( $max_bytes, strlen( $out['body'] ), 'The accumulated body must be the proof the cap was exceeded.' );
	}

	public function test_a_response_at_or_under_the_cap_is_returned_untouched(): void {
		$this->start_local_server();

		$out = aafm_ssrf_owned_curl_fetch( "{$this->base_url}/?mode=small-ok", '127.0.0.1', 0, '127.0.0.1', 1000 );

		$this->assertIsArray( $out );
		$this->assertArrayNotHasKey( 'content-length', $out['headers'], 'A declared length at or under the cap must not trip the header abort.' );
		$this->assertSame( 'ok', $out['body'] );
		$this->assertSame( 200, $out['response']['code'] );
	}

	public function test_a_connection_failure_is_a_generic_wp_error(): void {
		// Nothing is listening on this port - no local server started.
		$out = aafm_ssrf_owned_curl_fetch( 'http://127.0.0.1:1/', '127.0.0.1', 1, '127.0.0.1', 1000 );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_fetch_failed', $out->get_error_code() );
	}

	/**
	 * Codex hunt H2: aafm_ssrf_safe_fetch_url() now composes aafm_ssrf_owned_curl_fetch() and
	 * aafm_ssrf_process_fetch_response() directly, with no pre_http_request mock possible or
	 * needed in between. This proves the SECOND half of that pipe - a REAL fetch result feeding
	 * cleanly into aafm_ssrf_process_fetch_response() - against a real fetch result rather than a
	 * synthetic array. It does NOT call aafm_ssrf_safe_fetch_url() itself, so it cannot prove the
	 * wrapper actually calls either function; see the composition test below for that.
	 */
	public function test_process_fetch_response_accepts_a_real_local_fetch_result(): void {
		$this->start_local_server();

		$raw = aafm_ssrf_owned_curl_fetch( "{$this->base_url}/?mode=small-ok", '127.0.0.1', 0, '127.0.0.1', 1000 );
		$out = aafm_ssrf_process_fetch_response( $raw, 1000 );

		$this->assertSame( 'ok', $out );
	}

	/**
	 * Codex round 5, R5-6: the test above calls aafm_ssrf_owned_curl_fetch() and
	 * aafm_ssrf_process_fetch_response() by hand - it never calls aafm_ssrf_safe_fetch_url()
	 * itself, so a wrapper regression that reaches the owned fetch but skips response processing
	 * (or the reverse) would leave this file green despite the misleading claim above.
	 *
	 * The aafm_ssrf_validate_fetch_target() function refuses 127.0.0.1 outright as a
	 * private/reserved address (by design), so the wrapper cannot be pointed at this test's own
	 * local server directly.
	 * Instead: fetch the REAL local-server response first, over the same real socket the tests
	 * above use, then feed that exact raw array back through the test-only
	 * 'aafm_media_fetch_pre_fetch_result' seam while calling the real wrapper with a URL that
	 * passes validation (https, non-IP-literal host, pinned to a resolvable non-private IP the
	 * same way UploadMediaFromUrlSsrfTest.php does). A pass here proves the wrapper's own
	 * validation half ran AND that it handed the seam's real payload to
	 * aafm_ssrf_process_fetch_response(), not just that a test can call the two by hand.
	 */
	public function test_safe_fetch_url_composes_validation_and_response_processing_around_a_real_fetch_result(): void {
		$this->start_local_server();
		$raw = aafm_ssrf_owned_curl_fetch( "{$this->base_url}/?mode=small-ok", '127.0.0.1', 0, '127.0.0.1', 1000 );

		add_filter( 'aafm_resolve_hostname_to_ip', static fn(): string => '203.0.113.10' );
		add_filter( 'aafm_media_fetch_pre_fetch_result', static fn() => $raw );
		$out = aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );
		remove_all_filters( 'aafm_media_fetch_pre_fetch_result' );
		remove_all_filters( 'aafm_resolve_hostname_to_ip' );

		$this->assertSame( 'ok', $out );
	}
}
