<?php
/**
 * Tests for the OAuth authorization endpoint: param validation, consent storage,
 * and the standalone consent-screen render.
 *
 * The init handler reads superglobals and calls auth_redirect()/status_header()/exit,
 * which cannot run in-process, so the testable logic lives in pure helpers exercised
 * here: aafm_oauth_validate_authorize_params(), the consent-row reader/writer, and the
 * consent-page renderer.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies authorize-request validation, consent persistence, and the consent render.
 */
class AuthorizeTest extends TestCase {

	/**
	 * Install the OAuth tables before each test (they are TEMPORARY under the suite).
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
	}

	/**
	 * Register a public client and return its client_id.
	 *
	 * @param string $redirect The single redirect URI to register.
	 * @return string
	 */
	private function register_client( string $redirect = 'https://app.example/cb' ): string {
		$result = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( $redirect ),
				'client_name'   => 'Test Client',
			)
		);
		$this->assertIsArray( $result );
		return (string) $result['client_id'];
	}

	/**
	 * A valid set of authorize params for the given client/redirect.
	 *
	 * @param string $client_id Registered client_id.
	 * @param string $redirect  Registered redirect URI.
	 * @return array<string,string>
	 */
	private function valid_params( string $client_id, string $redirect = 'https://app.example/cb' ): array {
		return array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect,
			'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			'code_challenge_method' => 'S256',
			'state'                 => 'xyz',
			'scope'                 => '',
			'resource'              => '',
		);
	}

	/**
	 * Missing code_challenge is rejected: PKCE is mandatory.
	 */
	public function test_missing_code_challenge_is_error(): void {
		$client = $this->register_client();
		$params = $this->valid_params( $client );
		unset( $params['code_challenge'] );

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * A code_challenge_method other than S256 is rejected (no plain).
	 */
	public function test_non_s256_method_is_error(): void {
		$client                          = $this->register_client();
		$params                          = $this->valid_params( $client );
		$params['code_challenge_method'] = 'plain';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * A response_type other than "code" is a redirectable OAuth error.
	 */
	public function test_bad_response_type_is_redirectable_error(): void {
		$client                  = $this->register_client();
		$params                  = $this->valid_params( $client );
		$params['response_type'] = 'token';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * An unknown client_id is a NON-redirectable (local) error.
	 */
	public function test_unknown_client_is_local_error(): void {
		$params              = $this->valid_params( 'does_not_exist' );
		$params['client_id'] = 'does_not_exist';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * A redirect_uri not in the client's registered set is a NON-redirectable error.
	 */
	public function test_unregistered_redirect_is_local_error(): void {
		$client                 = $this->register_client();
		$params                 = $this->valid_params( $client );
		$params['redirect_uri'] = 'https://evil.example/cb';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * With no resource param, the normalized resource defaults to the MCP endpoint.
	 */
	public function test_absent_resource_defaults_to_endpoint(): void {
		$client = $this->register_client();
		$params = $this->valid_params( $client );

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertIsArray( $result );
		$this->assertSame( aafm_endpoint_url(), $result['resource'] );
	}

	/**
	 * A resource that equals the MCP endpoint exactly is accepted.
	 */
	public function test_matching_resource_is_accepted(): void {
		$client             = $this->register_client();
		$params             = $this->valid_params( $client );
		$params['resource'] = aafm_endpoint_url();

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertIsArray( $result );
		$this->assertSame( aafm_endpoint_url(), $result['resource'] );
	}

	/**
	 * A resource that differs from the MCP endpoint is rejected with invalid_target.
	 */
	public function test_mismatched_resource_is_invalid_target(): void {
		$client             = $this->register_client();
		$params             = $this->valid_params( $client );
		$params['resource'] = 'https://other.example/mcp';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_target', $result->get_error_code() );
		$this->assertTrue( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * Recording then reading consent returns true; a different client returns false.
	 */
	public function test_record_and_read_consent(): void {
		$client = $this->register_client();
		$user   = self::factory()->user->create();

		$this->assertFalse( aafm_oauth_has_consent( $user, $client ) );

		aafm_oauth_record_consent( $user, $client );

		$this->assertTrue( aafm_oauth_has_consent( $user, $client ) );
		$this->assertFalse( aafm_oauth_has_consent( $user, 'some_other_client' ) );
	}

	/**
	 * Recording consent twice for the same pair stays a single row (idempotent upsert).
	 */
	public function test_record_consent_is_idempotent(): void {
		$client = $this->register_client();
		$user   = self::factory()->user->create();

		aafm_oauth_record_consent( $user, $client );
		aafm_oauth_record_consent( $user, $client );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
				$user,
				$client
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * A code minted with the forced endpoint resource persists that exact string.
	 *
	 * The authorize flow forces resource => aafm_endpoint_url() at the mint call, and
	 * the D1 token validator later audience-checks that exact string. This locks the
	 * audience carry-forward contract at the storage boundary: read the stored row
	 * back by the code's SHA-256 hash and assert the persisted resource is the
	 * endpoint URL byte-for-byte.
	 */
	public function test_minted_code_persists_endpoint_resource(): void {
		$client   = $this->register_client();
		$user     = self::factory()->user->create();
		$endpoint = aafm_endpoint_url();

		$raw = aafm_oauth_mint_code(
			array(
				'client_id'      => $client,
				'wp_user_id'     => $user,
				'redirect_uri'   => 'https://app.example/cb',
				'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
				'resource'       => $endpoint,
			)
		);
		$this->assertIsString( $raw );

		$hash = hash( 'sha256', $raw );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_resource = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT resource FROM {$wpdb->prefix}aafm_oauth_codes WHERE code_hash = %s",
				$hash
			)
		);

		$this->assertSame( $endpoint, $stored_resource );
	}

	/**
	 * Drive the full init handler for one authorize GET, capturing the first redirect
	 * or any rendered output without letting it exit the process.
	 *
	 * The handler calls wp_redirect()/wp_safe_redirect() and then exit on every
	 * terminal branch. wp_safe_redirect() routes through the `wp_redirect` filter, so
	 * we short-circuit there: record the target and throw to unwind before exit. Output
	 * branches (the consent screen) never redirect, so they are captured via the buffer.
	 *
	 * Every terminal branch ends in exit, which would kill the test process, so both
	 * are short-circuited with a throw that unwinds the stack:
	 *   - redirects route through the `wp_redirect` filter (records target, throws);
	 *   - the consent screen and every local error page call status_header(), so that
	 *     filter records the HTTP code and throws.
	 * The consent path sets 200; failure pages set 400/403/404/429, so the captured
	 * status cleanly distinguishes "reached consent" from any error branch without
	 * depending on output that exit() would otherwise discard.
	 *
	 * @param array<string,string> $params Authorize query params to place in $_GET.
	 * @return array{redirect:?string,status:?int} Captured redirect target and HTTP status.
	 */
	private function run_authorize_get( array $params ): array {
		$captured = array(
			'redirect' => null,
			'status'   => null,
		);

		$catch_redirect = static function ( $location ) use ( &$captured ) {
			$captured['redirect'] = (string) $location;
			throw new \RuntimeException( 'aafm_test_redirect' );
		};
		$catch_status   = static function ( $header, $code ) use ( &$captured ) {
			$captured['status'] = (int) $code;
			throw new \RuntimeException( 'aafm_test_status' );
		};
		add_filter( 'wp_redirect', $catch_redirect, 1 );
		add_filter( 'status_header', $catch_status, 1, 2 );

		// The route marker selects this handler; valid_params() omits it by design.
		$params['aafm_oauth'] = 'authorize';

		// Snapshot the request superglobals so unrelated tests that run after this one
		// keep the harness-seeded REQUEST_URI etc. (restored byte-for-byte below).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- snapshot only, restored verbatim in the finally block.
		$prev_get    = $_GET;
		$prev_server = $_SERVER;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness seeds the request.
		$_GET                      = $params;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/?' . http_build_query( $params );
		// The handler requires HTTPS in a production environment (the test env type).
		// Present the request as TLS so the realistic FORCE_SSL_ADMIN scenario is
		// exercised: is_ssl() true is exactly the condition under which the old
		// auth_redirect() gate looped.
		$_SERVER['HTTPS'] = 'on';

		// The consent and error branches emit raw header() calls before status_header().
		// Under the CLI test SAPI headers are already "sent" (bootstrap printed), so
		// header() would raise a warning the suite escalates to an exception before our
		// status_header capture runs. Demote that one warning so execution reaches the
		// status_header filter; real requests send headers cleanly.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test harness only: demotes the CLI "headers already sent" warning so the status_header capture runs.
		set_error_handler(
			static function ( $errno, $errstr ) {
				return str_contains( $errstr, 'Cannot modify header information' );
			},
			E_WARNING
		);

		ob_start();
		try {
			aafm_oauth_handle_authorize();
		} catch ( \RuntimeException $e ) {
			// Expected: a capture filter threw to unwind before the handler's exit.
			unset( $e );
		} finally {
			ob_end_clean();
			restore_error_handler();
			remove_filter( 'wp_redirect', $catch_redirect, 1 );
			remove_filter( 'status_header', $catch_status, 1 );
			$_GET    = $prev_get;
			$_SERVER = $prev_server;
		}

		return array(
			'redirect' => $captured['redirect'],
			'status'   => $captured['status'],
		);
	}

	/**
	 * A LOGGED-OUT request to the authorize endpoint is sent to wp-login, with the
	 * authorize URL carried as redirect_to so the user returns after signing in.
	 *
	 * This is the regression test for the FORCE_SSL_ADMIN loop. The handler must NOT
	 * call core auth_redirect() (which validates the secure_auth cookie scoped to
	 * /wp-admin and never sent on "/"); it must gate on is_user_logged_in() and send a
	 * genuinely logged-out user to wp_login_url(). Full real-HTTPS cookie-scheme
	 * behavior is an integration concern; this unit test proves the branch logic that
	 * the bug broke: logged-out goes to login.
	 */
	public function test_logged_out_request_redirects_to_login(): void {
		wp_set_current_user( 0 );
		$client = $this->register_client();

		$result = $this->run_authorize_get( $this->valid_params( $client ) );

		$this->assertNotNull( $result['redirect'], 'A logged-out user must be redirected.' );
		$this->assertStringContainsString( 'wp-login.php', (string) $result['redirect'] );
		$this->assertStringContainsString( 'redirect_to=', (string) $result['redirect'] );
		// The return target is the authorize URL, so sign-in lands back on this flow.
		$this->assertStringContainsString( rawurlencode( 'aafm_oauth=authorize' ), (string) $result['redirect'] );
	}

	/**
	 * A LOGGED-IN user with the required capability PROCEEDS to the consent screen
	 * instead of being bounced to login.
	 *
	 * This is the guard that proves the loop is fixed: under the old auth_redirect()
	 * gate a fully logged-in user was still redirected to wp-login on a FORCE_SSL_ADMIN
	 * site because the secure_auth cookie is absent on "/". With the is_user_logged_in()
	 * gate the same user reaches the consent screen and never redirects. The consent
	 * render sets a 200 status; any failure branch (HTTPS/cap/nonce/disabled/rate) sets
	 * a 4xx, so 200 with no redirect proves the gate passed.
	 */
	public function test_logged_in_user_reaches_consent_screen(): void {
		$this->acting_as( 'administrator' );
		$client = $this->register_client();

		$result = $this->run_authorize_get( $this->valid_params( $client ) );

		$this->assertNull( $result['redirect'], 'A logged-in user must not be redirected to login.' );
		$this->assertSame( 200, $result['status'], 'A logged-in user must reach the 200 consent screen, not an error page.' );
	}

	/**
	 * The consent page renders a self-contained, escaped, script-free document.
	 */
	public function test_consent_render_is_escaped_and_self_contained(): void {
		$view = array(
			'client_name'   => '<script>alert(1)</script>',
			'user_login'    => 'mcp-agent',
			'site_name'     => 'Example Site',
			'action_url'    => 'https://site.example/?aafm_oauth=authorize',
			'nonce_field'   => '<input type="hidden" name="_wpnonce" value="abc123" />',
			'hidden_inputs' => array(
				'<input type="hidden" name="response_type" value="code" />',
				'<input type="hidden" name="client_id" value="abc" />',
			),
		);

		ob_start();
		aafm_oauth_render_consent_page( $view );
		$html = (string) ob_get_clean();

		// Document shell and form.
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'method="post"', $html );

		// Design tokens present and both decision buttons emitted.
		$this->assertStringContainsString( '--accent', $html );
		$this->assertStringContainsString( 'value="approve"', $html );
		$this->assertStringContainsString( 'value="deny"', $html );

		// Keyboard focus indicator (WCAG 2.4.7): the buttons paint a visible ring on
		// :focus-visible. The admin ring token is not enqueued on this standalone page,
		// so the inline styles must carry their own focus rule.
		$this->assertStringContainsString( '.aafm-btn:focus-visible', $html );

		// The agent user login is shown in the "acting as" note.
		$this->assertStringContainsString( 'mcp-agent', $html );

		// The honest risk is stated plainly: the agent acts as the user's WordPress
		// account. The redesign reframes the old red scare line as this calm note, but
		// the truthful "acts as your account" message must remain.
		$this->assertStringContainsString( 'as your WordPress account', $html );

		// The governance guarantees (the plugin's differentiator) are surfaced as trust
		// signals, so the consent screen argues the safety case rather than only warning.
		$this->assertStringContainsString( 'Off by default.', $html );
		$this->assertStringContainsString( 'Deletes go to Trash.', $html );

		// No script tag anywhere: the page is CSP-clean and self-contained.
		$this->assertStringNotContainsString( '<script', $html );

		// The malicious client_name comes back HTML-escaped, never as a raw tag.
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	/**
	 * The redirect-origin helper reduces a redirect_uri to scheme://host[:port] and
	 * strips the path and query, so only a bare origin can ever reach the CSP.
	 */
	public function test_redirect_uri_origin_strips_path_and_query(): void {
		$this->assertSame(
			'https://app.example',
			aafm_oauth_redirect_uri_origin( 'https://app.example/cb?foo=bar#frag' )
		);
		// An explicit non-default port is preserved; the path is still dropped.
		$this->assertSame(
			'https://app.example:8443',
			aafm_oauth_redirect_uri_origin( 'https://app.example:8443/callback' )
		);
		// A URI with no scheme/host yields '', which keeps form-action at 'self' only.
		$this->assertSame( '', aafm_oauth_redirect_uri_origin( '/relative/only' ) );
	}

	/**
	 * The consent CSP allows the resulting cross-origin redirect: when the validated
	 * client origin is supplied, form-action carries BOTH 'self' and that origin, never
	 * the path. This is the fix for the form-action redirect block — without the origin
	 * the browser aborts the 302 to the client and the code never arrives.
	 */
	public function test_consent_csp_form_action_includes_validated_client_origin(): void {
		$origin = aafm_oauth_redirect_uri_origin( 'https://app.example/cb' );
		$csp    = aafm_oauth_consent_csp( $origin );

		$this->assertStringContainsString( "form-action 'self' https://app.example;", $csp );
		// The path of the redirect_uri must never leak into the policy.
		$this->assertStringNotContainsString( '/cb', $csp );
		// Every other hardened directive is preserved exactly.
		$this->assertStringContainsString( "default-src 'none';", $csp );
		$this->assertStringContainsString( "style-src 'unsafe-inline';", $csp );
		$this->assertStringContainsString( 'img-src data:;', $csp );
		$this->assertStringContainsString( "base-uri 'none';", $csp );
		$this->assertStringContainsString( "frame-ancestors 'none'", $csp );
		// The policy must not be widened to a wildcard form-action source.
		$this->assertStringNotContainsString( '*', $csp );
	}

	/**
	 * The local error page never redirects to the client, so its form-action stays at
	 * 'self' alone with no client origin appended.
	 */
	public function test_local_error_csp_keeps_form_action_self_only(): void {
		$csp = aafm_oauth_consent_csp();

		$this->assertStringContainsString( "form-action 'self';", $csp );
		$this->assertStringNotContainsString( 'app.example', $csp );
		$this->assertStringNotContainsString( 'https://', $csp );
	}
}
