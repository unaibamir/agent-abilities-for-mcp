<?php
/**
 * Dedicated SSRF-regression tests for aafm/upload-media-from-url, one per control decided in
 * 228-url-upload-ssrf-design.md: https-only, no bare IP literal, resolve-once-then-pin (proven
 * by a call-count assertion, not just a refusal - Codex-review amendment 20), no redirects, a
 * size cap enforced even against a synthetic fetch result, and the existing byte-sniff allow-list.
 *
 * Codex hunt H2 (2026-09-06): aafm_ssrf_safe_fetch_url() no longer routes through
 * `wp_safe_remote_get()`/`pre_http_request` at all - it calls aafm_ssrf_owned_curl_fetch()
 * directly (includes/abilities/media.php). Mocking a fetch result via `pre_http_request` would
 * therefore no longer intercept anything, so every test below that used to fake a response that
 * way now either calls aafm_ssrf_validate_fetch_target() directly (the validation half, before
 * any fetch is attempted) or aafm_ssrf_process_fetch_response() directly (the response-handling
 * half, with a synthetic array in the same shape aafm_ssrf_owned_curl_fetch() returns) - both
 * split out of aafm_ssrf_safe_fetch_url() specifically so they stay unit-testable without a
 * network mock. Two tests whose entire subject was the removed `pre_http_request` short-circuit
 * itself (that it registered at PHP_INT_MAX, and that it passed an earlier filter's result
 * through untouched) are deleted outright: there is nothing left to prove once no such
 * registration happens at all. Real-bytes-over-a-real-socket coverage of
 * aafm_ssrf_owned_curl_fetch() itself lives in tests/abilities/SsrfOwnedCurlFetchTest.php.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class UploadMediaFromUrlSsrfTest extends TestCase {

	// 1x1 transparent PNG.
	private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Absolute paths written by a fixture that slips past every guard, cleaned up in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $written_files = array();

	public function set_up(): void {
		parent::set_up();
		// example.test never resolves in this container (RFC 2606), and some DNS setups sinkhole
		// an unresolvable name to a private/loopback address rather than failing outright - pin a
		// public IP by default so a test exercises the control under test, not the resolver's own
		// environmental behavior. Tests that specifically exercise resolution/denylist behavior
		// override this locally.
		add_filter( 'aafm_resolve_hostname_to_ip', static fn(): string => '203.0.113.10' );
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		remove_all_filters( 'aafm_resolve_hostname_to_ip' );
		parent::tear_down();
	}

	/**
	 * Codex round C finding 1: without cURL, WordPress's HTTP API falls back to the Fsockopen
	 * transport, which performs its OWN unpinned DNS resolution - CURLOPT_RESOLVE pinning inside
	 * http_api_curl never fires for that path at all, silently reopening the TOCTOU gap this
	 * whole design exists to close. Refusing outright when cURL is unavailable removes the
	 * fallback path rather than trying to detect after the fact whether the pin actually applied.
	 */
	public function test_refuses_outright_when_curl_is_unavailable(): void {
		add_filter( 'aafm_curl_available', '__return_false' );

		$out = aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );

		remove_all_filters( 'aafm_curl_available' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_curl_unavailable', $out->get_error_code() );
	}

	/**
	 * Codex final round HIGH: function_exists('curl_init') is not the test Requests itself runs
	 * before selecting the Curl transport over Fsockopen for an https:// request - Requests also
	 * requires curl_exec() to exist and the installed libcurl to have SSL support. This CI/dev
	 * container has a real, SSL-capable cURL build, so the true default (with no filter override)
	 * must be true - guards the common path against the stricter check regressing to false.
	 */
	public function test_curl_available_is_true_on_a_real_ssl_capable_curl_build(): void {
		$this->assertTrue( aafm_curl_available() );
	}

	/**
	 * Codex final round 2 HIGH: CURLOPT_RESOLVE pinning never applies once WordPress routes the
	 * request through an outbound HTTP proxy - the proxy, not this server, resolves the hostname.
	 * No real WP_PROXY_HOST/WP_PROXY_PORT constants are defined here (defining them would leak
	 * into every later test in this process); the filter mirrors what WP_HTTP_Proxy would decide.
	 */
	public function test_refuses_a_url_that_would_go_through_an_outbound_proxy(): void {
		add_filter( 'aafm_url_would_use_proxy', '__return_true' );

		$out = aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );

		remove_all_filters( 'aafm_url_would_use_proxy' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_proxy_unsupported', $out->get_error_code() );
	}

	public function test_aafm_url_would_use_proxy_is_false_with_no_proxy_configured(): void {
		// No WP_PROXY_HOST/WP_PROXY_PORT defined in this environment - confirms the real
		// WP_HTTP_Proxy integration does not block an ordinary fetch by default.
		$this->assertFalse( aafm_url_would_use_proxy( 'https://example.test/pixel.png' ) );
	}

	/**
	 * Codex round 8 LOW: aafm_url_would_use_proxy() used to pass the full URL as a second filter
	 * argument, so any 'all' hook observer could read a signed query string before any control had
	 * run. This is a plugin-defined filter (unlike the core-mirrored http_allowed_safe_ports below,
	 * which legitimately still carries the URL), so a call-count assertion is what proves the URL
	 * was dropped rather than merely unread by this particular observer.
	 */
	public function test_proxy_filter_does_not_receive_the_url(): void {
		$received_args = null;
		add_filter(
			'aafm_url_would_use_proxy',
			static function () use ( &$received_args ) {
				$received_args = func_get_args();
				return false;
			},
			10,
			20
		);

		aafm_url_would_use_proxy( 'https://example.test/pixel.png?token=super-secret' );

		remove_all_filters( 'aafm_url_would_use_proxy' );

		$this->assertCount( 1, $received_args, 'the proxy filter must receive only the boolean value, never the URL.' );
	}

	public function test_refuses_a_non_https_scheme(): void {
		$out = aafm_ssrf_safe_fetch_url( 'http://example.com/x.jpg' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_bad_scheme', $out->get_error_code() );
	}

	public function test_refuses_a_bare_ip_literal_host(): void {
		$out = aafm_ssrf_safe_fetch_url( 'https://169.254.169.254/latest/meta-data/' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_ip_literal_refused', $out->get_error_code() );
	}

	public function test_refuses_a_hostname_that_resolves_to_a_private_range(): void {
		add_filter( 'aafm_resolve_hostname_to_ip', static fn(): string => '10.0.0.5' );

		$out = aafm_ssrf_safe_fetch_url( 'https://internal.example.test/image.jpg' );

		remove_all_filters( 'aafm_resolve_hostname_to_ip' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_private_range_refused', $out->get_error_code() );
	}

	/**
	 * Codex final round 9 MEDIUM: this function used to short-circuit WP's HTTP transport via
	 * 'pre_http_request', which ran BEFORE 'reject_unsafe_urls' was ever acted on inside
	 * WP_Http::request() - so passing that request arg gave no actual port protection, and an
	 * otherwise-valid public host on an arbitrary port (a probe of any open TLS service on the
	 * public internet, not just image hosts) sailed through. Fixed by re-deriving the same
	 * 80/443/8080 default allowlist wp_http_validate_url() itself uses, now inside
	 * aafm_ssrf_validate_fetch_target() (Codex hunt H2 dropped the pre_http_request/
	 * reject_unsafe_urls plumbing this check originally had to work around entirely).
	 */
	public function test_refuses_a_port_outside_the_safe_allowlist(): void {
		$out = aafm_ssrf_safe_fetch_url( 'https://example.test:8443/pixel.png' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_unsafe_port', $out->get_error_code() );
	}

	/**
	 * The 'http_allowed_safe_ports' filter is WP core's own mechanism for a site to widen that
	 * default allowlist; a site that already uses it for its other HTTP calls should not need a
	 * second, plugin-specific setting for this ability to respect the same policy. Asserted at
	 * the validation step directly (Codex hunt H2) rather than through a full fetch: whether the
	 * widened port is accepted is entirely decided there, before any network attempt.
	 */
	public function test_a_port_added_via_the_core_safe_ports_filter_is_allowed(): void {
		add_filter(
			'http_allowed_safe_ports',
			static fn( array $ports ): array => array_merge( $ports, array( 8443 ) )
		);

		$target = aafm_ssrf_validate_fetch_target( 'https://example.test:8443/pixel.png' );

		remove_all_filters( 'http_allowed_safe_ports' );

		$this->assertIsArray( $target, 'A port added via the core safe-ports filter must pass validation.' );
		$this->assertSame( 8443, $target['port'] );
	}

	/**
	 * The call-count proof Codex-review amendment 20 requires: a resolver double that fails the
	 * test if invoked more than once for a single fetch. A naive re-resolution bug passes a plain
	 * "is refused" test (the first, validated resolution is what a refusal test checks) - only a
	 * call-count assertion catches a second, un-pinned lookup between validation and connection.
	 *
	 * Codex hunt H2: asserted against aafm_ssrf_validate_fetch_target() alone now, since
	 * resolution only ever happens there - aafm_ssrf_owned_curl_fetch() takes the already-resolved
	 * IP as a plain string argument and has no way to call the resolver again, so "never
	 * re-resolved between validation and connection" is now a structural property of the function
	 * signatures, not just an observed one. This also drops the prior need to mock a fetch result
	 * just to get past validation to the point being measured.
	 */
	public function test_resolves_the_hostname_exactly_once_per_fetch(): void {
		$calls = 0;
		add_filter(
			'aafm_resolve_hostname_to_ip',
			static function () use ( &$calls ) {
				++$calls;
				return '203.0.113.10'; // TEST-NET-3, public per filter_var()'s own flags.
			}
		);

		aafm_ssrf_validate_fetch_target( 'https://example.test/image.jpg' );

		remove_all_filters( 'aafm_resolve_hostname_to_ip' );

		$this->assertSame( 1, $calls, 'The hostname must be resolved exactly once during validation.' );
	}

	/**
	 * Codex hunt F12: the validation test above proves the hostname is resolved once, but
	 * nothing there shows the pin it produced is ever applied to a cURL handle. This test runs the
	 * real owned-fetch path and uses the aafm_media_fetch_curl_options seam to capture the final
	 * option array right before curl_setopt_array(), aborting there so no network call happens.
	 */
	public function test_owned_fetch_pins_resolution_and_disables_proxy_and_redirects(): void {
		$captured = null;
		add_filter(
			'aafm_media_fetch_curl_options',
			static function ( array $options ) use ( &$captured ) {
				$captured = $options;
				throw new \RuntimeException( 'aafm-test-abort-before-curl-exec' );
			}
		);

		try {
			aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );
			$this->fail( 'Expected the test seam to abort the fetch before curl_exec() ran.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'aafm-test-abort-before-curl-exec', $e->getMessage() );
		} finally {
			remove_all_filters( 'aafm_media_fetch_curl_options' );
		}

		$this->assertIsArray( $captured );
		$this->assertSame( array( 'example.test:443:203.0.113.10' ), $captured[ CURLOPT_RESOLVE ] );
		$this->assertSame( '', $captured[ CURLOPT_PROXY ] );
		$this->assertFalse( $captured[ CURLOPT_FOLLOWLOCATION ] );
	}

	/**
	 * Codex final round 4 HIGH: curl_setopt_array()'s return value was ignored, so a single option
	 * it could not apply (CURLOPT_PROXYTYPE with an out-of-range value, verified separately to make
	 * curl_setopt_array() return false without throwing, per the cURL manual's documented behavior)
	 * still let curl_exec() run. This injects exactly that failure via the aafm_media_fetch_curl_options
	 * seam and proves two things: a WP_Error comes back, and curl_exec() itself was never reached.
	 * The second point is observed directly via the aafm_media_fetch_before_exec seam
	 * (fires right before curl_exec(), a test-only observation point) rather than inferred from
	 * elapsed wall time, which is circumstantial: a host that rejects the pinned TEST-NET-3 address
	 * instantly would pass that check even with the fail-closed guard reverted.
	 */
	public function test_a_curl_option_the_handle_cannot_apply_aborts_before_any_connection(): void {
		$reached_exec = false;
		add_filter(
			'aafm_media_fetch_curl_options',
			static function ( array $options ): array {
				// CURLOPT_PROXYTYPE only accepts a small set of CURLPROXY_* constants; an
				// out-of-range value makes curl_setopt_array() return false without throwing,
				// which is exactly the silent-failure shape this test guards against.
				$options[ CURLOPT_PROXYTYPE ] = 999999;
				return $options;
			}
		);
		add_action(
			'aafm_media_fetch_before_exec',
			static function () use ( &$reached_exec ): void {
				$reached_exec = true;
			}
		);

		try {
			$out = aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );
		} finally {
			remove_all_filters( 'aafm_media_fetch_curl_options' );
			remove_all_actions( 'aafm_media_fetch_before_exec' );
		}

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_fetch_failed', $out->get_error_code() );
		$this->assertFalse( $reached_exec, 'curl_exec() must never run when an option the handle cannot apply is rejected.' );
	}

	/**
	 * F8 (1.7.5 deferred): the missing-option guard's required list omitted CURLOPT_TIMEOUT and
	 * CURLOPT_CONNECTTIMEOUT, so a hooked callback removing either one passed straight through and
	 * a public HTTPS server could then stall the transfer past the intended ten-second bound. Same
	 * shape and seam as test_a_curl_option_the_handle_cannot_apply_aborts_before_any_connection()
	 * above, but removes a required key outright instead of setting an option the handle rejects.
	 */
	public function test_removing_a_transfer_timeout_option_aborts_before_any_connection(): void {
		$reached_exec = false;
		add_filter(
			'aafm_media_fetch_curl_options',
			static function ( array $options ): array {
				unset( $options[ CURLOPT_TIMEOUT ] );
				return $options;
			}
		);
		add_action(
			'aafm_media_fetch_before_exec',
			static function () use ( &$reached_exec ): void {
				$reached_exec = true;
			}
		);

		try {
			$out = aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );
		} finally {
			remove_all_filters( 'aafm_media_fetch_curl_options' );
			remove_all_actions( 'aafm_media_fetch_before_exec' );
		}

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_fetch_failed', $out->get_error_code() );
		$this->assertFalse( $reached_exec, 'curl_exec() must never run once a required transfer-timeout option is missing.' );
	}

	/**
	 * Proves the aafm_media_fetch_before_exec seam used above is actually live: with a benign
	 * option set (no bad CURLOPT_PROXYTYPE), the fetch must reach the point right before
	 * curl_exec() and fire the action, aborting there via a thrown exception so no real network
	 * call happens. Without this test, a seam that silently stopped firing would make the test
	 * above pass for the wrong reason (nothing ever sets $reached_exec, bad option or not).
	 */
	public function test_the_before_exec_seam_fires_with_a_benign_option_set(): void {
		add_action(
			'aafm_media_fetch_before_exec',
			static function (): void {
				throw new \RuntimeException( 'aafm-test-abort-before-curl-exec' );
			}
		);

		try {
			aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );
			$this->fail( 'Expected the before-exec seam to abort the fetch.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'aafm-test-abort-before-curl-exec', $e->getMessage() );
		} finally {
			remove_all_actions( 'aafm_media_fetch_before_exec' );
		}
	}

	/**
	 * Codex hunt H2: asserted against aafm_ssrf_process_fetch_response() directly with a synthetic
	 * response in the same shape aafm_ssrf_owned_curl_fetch() returns for a redirect (a 302 status,
	 * no captured headers - that function never records a Location header, since
	 * CURLOPT_FOLLOWLOCATION is off and nothing downstream needs one).
	 */
	public function test_does_not_follow_a_redirect(): void {
		$out = aafm_ssrf_process_fetch_response(
			array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 302,
					'message' => '',
				),
			),
			(int) wp_max_upload_size()
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_fetch_failed', $out->get_error_code() );
	}

	/**
	 * Codex hunt H2: asserted against aafm_ssrf_process_fetch_response() directly. The mid-transfer
	 * abort itself (the mechanism that actually stops an oversized body being downloaded) is
	 * proven against a real local server in SsrfOwnedCurlFetchTest.php; this is the
	 * defense-in-depth re-check one layer up, for a body that reaches this function already over
	 * the cap by whatever means.
	 */
	public function test_refuses_a_payload_over_the_size_cap(): void {
		$max_bytes = (int) wp_max_upload_size();
		$out       = aafm_ssrf_process_fetch_response(
			array(
				'headers'  => array(),
				'body'     => str_repeat( 'x', $max_bytes + 1 ),
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
			),
			$max_bytes
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_too_large', $out->get_error_code() );
	}

	/**
	 * Codex hunt H2: asserted against aafm_ssrf_process_fetch_response() with a synthetic
	 * response, then fed through the real byte-sniff/upload path. Real bytes fetched over a real
	 * socket are covered separately in SsrfOwnedCurlFetchTest.php; this test's job is only the
	 * response-shape-to-upload composition, which never needed a network call to exercise.
	 */
	public function test_a_non_image_response_is_refused_by_the_existing_byte_sniff(): void {
		$fetched = aafm_ssrf_process_fetch_response(
			array(
				'headers'  => array( 'content-type' => 'image/jpeg' ),
				'body'     => '<html><body>not an image</body></html>',
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
			),
			(int) wp_max_upload_size()
		);

		$this->assertIsString( $fetched );

		$this->acting_as( 'author' );
		$out = aafm_finish_media_upload( $fetched, 'x.jpg', null );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_disallowed_type', $out->get_error_code() );
	}

	public function test_a_legitimate_public_https_image_is_fetched_and_uploaded(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png = base64_decode( self::PNG_B64, true );

		$fetched = aafm_ssrf_process_fetch_response(
			array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => $png,
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
			),
			(int) wp_max_upload_size()
		);

		$this->assertIsString( $fetched );

		$this->acting_as( 'author' );
		$out = aafm_finish_media_upload( $fetched, 'pixel.png', 'a single pixel' );

		$this->assertIsArray( $out );
		$attachment_id = (int) $out['attachment_id'];
		$file          = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
	}
}
