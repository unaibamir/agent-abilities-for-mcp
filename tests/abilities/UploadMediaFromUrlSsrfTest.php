<?php
/**
 * Dedicated SSRF-regression tests for aafm/upload-media-from-url, one per control decided in
 * 228-url-upload-ssrf-design.md: https-only, no bare IP literal, resolve-once-then-pin (proven
 * by a call-count assertion, not just a refusal - Codex-review amendment 20), no redirects, a
 * size cap enforced even against a mocked HTTP layer, and the existing byte-sniff allow-list.
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
	 * Codex final round 9 MEDIUM: this function short-circuits WP's HTTP transport via
	 * 'pre_http_request', which runs BEFORE 'reject_unsafe_urls' is ever acted on inside
	 * WP_Http::request() - so passing that request arg gave no actual port protection, and an
	 * otherwise-valid public host on an arbitrary port (a probe of any open TLS service on the
	 * public internet, not just image hosts) sailed through. Fixed by re-deriving the same
	 * 80/443/8080 default allowlist wp_http_validate_url() itself uses.
	 */
	public function test_refuses_a_port_outside_the_safe_allowlist(): void {
		$out = aafm_ssrf_safe_fetch_url( 'https://example.test:8443/pixel.png' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_unsafe_port', $out->get_error_code() );
	}

	/**
	 * The 'http_allowed_safe_ports' filter is WP core's own mechanism for a site to widen that
	 * default allowlist; a site that already uses it for its other HTTP calls should not need a
	 * second, plugin-specific setting for this ability to respect the same policy.
	 */
	public function test_a_port_added_via_the_core_safe_ports_filter_is_allowed(): void {
		add_filter(
			'http_allowed_safe_ports',
			static fn( array $ports ): array => array_merge( $ports, array( 8443 ) )
		);
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => base64_decode( self::PNG_B64 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a fixture constant, not obfuscating anything.
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			)
		);

		$out = aafm_ssrf_safe_fetch_url( 'https://example.test:8443/pixel.png' );

		remove_all_filters( 'http_allowed_safe_ports' );
		remove_all_filters( 'pre_http_request' );

		$this->assertIsString( $out );
	}

	/**
	 * The call-count proof Codex-review amendment 20 requires: a resolver double that fails the
	 * test if invoked more than once for a single fetch. A naive re-resolution bug passes a plain
	 * "is refused" test (the first, validated resolution is what a refusal test checks) - only a
	 * call-count assertion catches a second, un-pinned lookup between validation and connection.
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
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 500 ),
				'cookies'  => array(),
			)
		);

		aafm_ssrf_safe_fetch_url( 'https://example.test/image.jpg' );

		remove_all_filters( 'aafm_resolve_hostname_to_ip' );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $calls, 'The hostname must be resolved exactly once per fetch, never re-resolved between validation and connection.' );
	}

	public function test_does_not_follow_a_redirect(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'location' => 'https://169.254.169.254/latest/meta-data/' ),
				'body'     => '',
				'response' => array( 'code' => 302 ),
				'cookies'  => array(),
			)
		);

		$out = aafm_ssrf_safe_fetch_url( 'https://example.test/image.jpg' );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_fetch_failed', $out->get_error_code() );
	}

	public function test_refuses_a_payload_over_the_size_cap(): void {
		$oversized = str_repeat( 'x', (int) wp_max_upload_size() + 1 );
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => $oversized,
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			)
		);

		$out = aafm_ssrf_safe_fetch_url( 'https://example.test/image.jpg' );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_too_large', $out->get_error_code() );
	}

	/**
	 * Codex final round 7/8 MEDIUM (fix): the byte cap is no longer enforced via WP's own
	 * 'limit_response_size' request arg (that mechanism could only reject AFTER a bounded
	 * download completed, never before). This test's own mock deliberately runs at a LOWER
	 * priority than aafm_ssrf_safe_fetch_url()'s own pre_http_request intercept (which registers
	 * at PHP_INT_MAX as of round 9, so any earlier-registered callback, at any priority, still
	 * runs first) - proving the production intercept correctly treats an earlier filter's
	 * non-false return as already-decided and passes it straight through untouched, exactly the
	 * convention every pre_http_request filter (including a test's own mock) depends on. The
	 * request args that DO still reach wp_safe_remote_get() (as a fallback default, should the
	 * intercept ever not fire) still carry the neutral User-Agent and the
	 * no-redirect/reject-unsafe-urls floor.
	 */
	public function test_an_earlier_pre_http_request_filter_is_never_overridden(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 500 ),
					'cookies'  => array(),
				);
			},
			10,
			2
		);

		$out = aafm_ssrf_safe_fetch_url( 'https://example.test/image.jpg' );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( WP_Error::class, $out, "The test's own mock (a 500 response) must be the result used, not silently replaced by the production intercept." );
		$this->assertIsArray( $captured_args );
		$this->assertNull( $captured_args['limit_response_size'], 'The size cap is enforced by the owned-handle fetch now (WP core defaults this arg to null when unset), not this now-obsolete request arg.' );

		// Codex final round 2 HIGH: WP core's default User-Agent discloses the site's own URL to
		// the caller-supplied host. The fallback request must still carry a neutral one instead.
		$this->assertSame( 'Agent Abilities for MCP (media fetch)', $captured_args['user-agent'] );
		$this->assertStringNotContainsString( home_url(), (string) $captured_args['user-agent'] );
	}

	/**
	 * Codex final round 9 MEDIUM (fix): before this round, the intercept registered at the
	 * default priority 10, so a LATER, permanently-registered 'pre_http_request' callback (e.g.
	 * from another plugin) could discard the pinned response this function already produced and
	 * force WordPress to fall through to its own unpinned transport - reopening exactly the
	 * DNS/proxy path this whole design exists to close. Registering at PHP_INT_MAX instead means
	 * nothing can ever run after this intercept (it is the highest priority PHP can represent),
	 * closing the override by construction rather than by hoping every third-party callback
	 * happens to use a lower number. Proven here by inspecting WP's own filter registry from
	 * inside an even-lower-priority spy - which also short-circuits with a non-false mock so
	 * this test never lets the real owned-handle fetch touch the network.
	 */
	public function test_the_intercept_registers_at_the_maximum_priority_so_nothing_can_run_after_it(): void {
		$observed_priority = null;
		add_filter(
			'pre_http_request',
			static function () use ( &$observed_priority ) {
				global $wp_filter;
				if ( isset( $wp_filter['pre_http_request'] ) ) {
					$priorities        = array_keys( $wp_filter['pre_http_request']->callbacks );
					$observed_priority = empty( $priorities ) ? null : max( $priorities );
				}
				// Non-false: short-circuits before the production intercept's own body ever runs,
				// so this test never performs a real network fetch.
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 500 ),
					'cookies'  => array(),
				);
			},
			1,
			1
		);

		aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame(
			PHP_INT_MAX,
			$observed_priority,
			"aafm_ssrf_safe_fetch_url()'s own pre_http_request intercept must register at PHP_INT_MAX so no later-registered callback can discard its pinned response."
		);
	}

	public function test_a_non_image_response_is_refused_by_the_existing_byte_sniff(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'content-type' => 'image/jpeg' ),
				'body'     => '<html><body>not an image</body></html>',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			)
		);

		$fetched = aafm_ssrf_safe_fetch_url( 'https://example.test/image.jpg' );
		remove_all_filters( 'pre_http_request' );

		$this->assertIsString( $fetched );

		$this->acting_as( 'author' );
		$out = aafm_finish_media_upload( $fetched, 'x.jpg', null );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_disallowed_type', $out->get_error_code() );
	}

	public function test_a_legitimate_public_https_image_is_fetched_and_uploaded(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png = base64_decode( self::PNG_B64, true );
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => $png,
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			)
		);

		$fetched = aafm_ssrf_safe_fetch_url( 'https://example.test/pixel.png' );
		remove_all_filters( 'pre_http_request' );

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
