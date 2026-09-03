<?php
/**
 * Tests for the OAuth REST endpoints: register, token, and revoke.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_REST_Request;

/**
 * Drives the three OAuth REST routes end-to-end through the REST server:
 * dynamic client registration, the authorization_code and refresh_token grants,
 * and token revocation.
 */
class RestEndpointsTest extends TestCase {

	/**
	 * The WordPress user id the most recent mint_code() call bound its code to.
	 *
	 * @var int
	 */
	private int $last_code_user_id = 0;

	/**
	 * Ensure the OAuth tables exist and the REST routes are registered.
	 */
	public function set_up(): void {
		parent::set_up();

		// The REST dispatch path reports a production environment, so relax the
		// HTTPS requirement the way a local agent-dev operator would - this is the
		// documented override, exercised here so the handlers run over the test's
		// plain-HTTP request instead of short-circuiting with a 400.
		if ( ! defined( 'AAFM_OAUTH_ALLOW_HTTP' ) ) {
			define( 'AAFM_OAUTH_ALLOW_HTTP', true );
		}

		// The endpoints read and write the OAuth storage tables.
		aafm_install_oauth_tables();

		// OAuth is OFF by default now; these endpoint tests exercise the enabled surface, so turn
		// it on explicitly. DCR is on by default, so enabling OAuth also enables registration. The
		// disabled-path tests override per case.
		update_option( 'aafm_oauth_enabled', '1' );

		// register/token success now writes an OAuth lifecycle audit row to the activity log.
		aafm_install_activity_log();

		// Register the routes against the REST server for this test run.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook fired to populate the REST server in the test.
		do_action( 'rest_api_init' );
	}

	/**
	 * Build the S256 challenge for a verifier the way an RFC 7636 client would.
	 *
	 * @param string $verifier The code verifier.
	 * @return string The base64url-encoded SHA-256 challenge.
	 */
	private function challenge_for( string $verifier ): string {
		// base64url encode of the raw SHA-256 digest, mirroring an RFC 7636 client (not obfuscation).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Register a client and return its client_id.
	 *
	 * @param string $redirect_uri The redirect URI to register.
	 * @return string The minted client_id.
	 */
	private function register_client( string $redirect_uri ): string {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( $redirect_uri ),
					'client_name'   => 'Test',
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		return (string) $data['client_id'];
	}

	/**
	 * Mint an authorization code bound to a client, redirect URI, user, and PKCE challenge.
	 *
	 * @param string $client_id  The client the code is bound to.
	 * @param string $redirect   The redirect URI the code is bound to.
	 * @param string $challenge  The PKCE S256 challenge.
	 * @return string The raw authorization code.
	 */
	private function mint_code( string $client_id, string $redirect, string $challenge ): string {
		$user_id                 = self::factory()->user->create();
		$this->last_code_user_id = $user_id;

		// The real authorize flow always records consent before it issues a code (approve path)
		// or only issues when a prior consent exists, so every minted code has a consent row.
		// Model that here, since the token endpoint now re-checks consent at redemption.
		aafm_oauth_record_consent( $user_id, $client_id );

		return aafm_oauth_mint_code(
			array(
				'client_id'      => $client_id,
				'wp_user_id'     => $user_id,
				'redirect_uri'   => $redirect,
				'code_challenge' => $challenge,
				'resource'       => aafm_endpoint_url(),
			)
		);
	}

	/**
	 * Dispatch an authorization_code token request.
	 *
	 * @param string $client_id The client_id.
	 * @param string $code      The raw authorization code.
	 * @param string $redirect  The redirect URI.
	 * @param string $verifier  The PKCE code verifier.
	 * @return \WP_REST_Response
	 */
	private function token_code_request( string $client_id, string $code, string $redirect, string $verifier ) {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect,
				'client_id'     => $client_id,
				'code_verifier' => $verifier,
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Registration returns 201 with a 32-hex client_id and both RFC 6749 section 5.1
	 * no-cache headers, since the response carries a live client_id.
	 */
	public function test_register_returns_client_id(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => 'Test',
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertArrayHasKey(
			'Pragma',
			$headers,
			'Response headers were: ' . wp_json_encode( array_keys( $headers ) )
		);
		$this->assertSame( 'no-cache', $headers['Pragma'] );

		$data = $response->get_data();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $data['client_id'] );
	}

	/**
	 * The authorization_code grant returns a Bearer access token and a refresh token.
	 */
	public function test_authorization_code_grant_happy_path(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		$response = $this->token_code_request( $client_id, $code, $redirect, $verifier );

		$this->assertSame( 200, $response->get_status() );

		// The token response must never be cached (RFC 6749 §5.1): it carries the
		// bearer credential, so a shared cache holding it would leak the token.
		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertArrayHasKey(
			'Pragma',
			$headers,
			'Response headers were: ' . wp_json_encode( array_keys( $headers ) )
		);
		$this->assertSame( 'no-cache', $headers['Pragma'] );

		$data = $response->get_data();
		$this->assertStringStartsWith( 'aafm_oat_', (string) $data['access_token'] );
		$this->assertSame( 'Bearer', $data['token_type'] );
		$this->assertNotEmpty( $data['refresh_token'] );
	}

	/**
	 * Replaying the same authorization code a second time fails with 400.
	 */
	public function test_authorization_code_replay_is_rejected(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		$first = $this->token_code_request( $client_id, $code, $redirect, $verifier );
		$this->assertSame( 200, $first->get_status() );

		$replay = $this->token_code_request( $client_id, $code, $redirect, $verifier );
		$this->assertSame( 400, $replay->get_status() );
	}

	/**
	 * A code whose grant was revoked between mint and redemption fails with 400. This closes the
	 * TOCTOU where an admin revokes a user+client grant while an authorize is in flight: the
	 * authorize GET already minted a code, but the token endpoint now re-checks consent, so the
	 * orphaned code cannot redeem into a live token after the revoke landed.
	 */
	public function test_authorization_code_rejected_after_consent_revoked(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		// The admin revokes this user+client grant after the code was minted.
		aafm_oauth_delete_consent( $this->last_code_user_id, $client_id );

		$response = $this->token_code_request( $client_id, $code, $redirect, $verifier );
		$this->assertSame( 400, $response->get_status(), 'A code whose consent was revoked must not redeem.' );
	}

	/**
	 * A wrong PKCE verifier is rejected with 400.
	 */
	public function test_authorization_code_bad_verifier_is_rejected(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		$response = $this->token_code_request( $client_id, $code, $redirect, 'the-wrong-verifier' );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The refresh_token grant rotates the refresh token and issues a new access token.
	 */
	public function test_refresh_token_grant_issues_new_access_token(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		$first         = $this->token_code_request( $client_id, $code, $redirect, $verifier );
		$first_data    = $first->get_data();
		$refresh_token = (string) $first_data['refresh_token'];

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $client_id,
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertStringStartsWith( 'aafm_oat_', (string) $data['access_token'] );
		$this->assertNotSame( (string) $first_data['access_token'], (string) $data['access_token'] );
	}

	/**
	 * Revocation always returns 200 with an empty body (RFC 7009).
	 */
	public function test_revoke_returns_ok(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		$token_response = $this->token_code_request( $client_id, $code, $redirect, $verifier );
		$access_token   = (string) $token_response->get_data()['access_token'];

		// The token is live before revocation.
		$this->assertNotFalse( aafm_oauth_validate_access_token( $access_token ) );

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/revoke' );
		$request->set_body_params( array( 'token' => $access_token ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		// The revocation response is never cached either (RFC 6749 §5.1): it acknowledges
		// a credential operation even though its own body carries no secret.
		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertArrayHasKey(
			'Pragma',
			$headers,
			'Response headers were: ' . wp_json_encode( array_keys( $headers ) )
		);
		$this->assertSame( 'no-cache', $headers['Pragma'] );

		// Revocation must genuinely kill the token, not just return 200: validating
		// the same token afterwards now reports it as dead.
		$this->assertFalse( aafm_oauth_validate_access_token( $access_token ) );
	}

	/**
	 * Registration accepts an application/x-www-form-urlencoded body (RFC 7591 parity).
	 *
	 * Real agents post DCR as form-encoded as readily as JSON. The handler must read
	 * the redirect_uris[] and client_name from the form body, not only get_json_params().
	 */
	public function test_register_accepts_form_encoded_body(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body(
			http_build_query(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => 'Form Client',
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $data['client_id'] );
		$this->assertSame( 'Form Client', $data['client_name'] );
	}

	/**
	 * The authorization_code grant accepts a form-encoded body (RFC 6749 §4.1.3).
	 *
	 * Regression guard: the token endpoint must redeem a real minted code from an
	 * application/x-www-form-urlencoded body, the wire format the spec prescribes.
	 */
	public function test_token_accepts_form_encoded_body(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body(
			http_build_query(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $redirect,
					'client_id'     => $client_id,
					'code_verifier' => $verifier,
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertStringStartsWith( 'aafm_oat_', (string) $data['access_token'] );
		$this->assertSame( 'Bearer', $data['token_type'] );
	}

	/**
	 * An over-long PKCE code_verifier is rejected with 400 before any redemption.
	 *
	 * RFC 7636 bounds the verifier to 43-128 characters. A 5000-character verifier
	 * is refused up front with a generic invalid_grant, so an attacker cannot use
	 * the token endpoint to push unbounded strings through hashing/DB work.
	 */
	public function test_token_rejects_overlong_code_verifier(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		// A 5000-character verifier - far outside the RFC 7636 43-128 window.
		$overlong = str_repeat( 'a', 5000 );

		$response = $this->token_code_request( $client_id, $code, $redirect, $overlong );

		$this->assertSame( 400, $response->get_status() );

		// The code must NOT have been consumed: an over-long verifier is rejected
		// before redemption, so the legitimate verifier still works afterwards.
		$retry = $this->token_code_request( $client_id, $code, $redirect, $verifier );
		$this->assertSame( 200, $retry->get_status() );
	}

	/**
	 * A too-short PKCE code_verifier is rejected with 400 (RFC 7636 lower bound).
	 */
	public function test_token_rejects_too_short_code_verifier(): void {
		$redirect  = 'https://app.example/cb';
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$client_id = $this->register_client( $redirect );
		$code      = $this->mint_code( $client_id, $redirect, $challenge );

		// 10 characters - below the RFC 7636 minimum of 43.
		$response = $this->token_code_request( $client_id, $code, $redirect, 'short12345' );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An over-long client_name on registration is rejected with 400.
	 */
	public function test_register_rejects_overlong_client_name(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => str_repeat( 'n', 5000 ),
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An over-long refresh_token is rejected with 400 before any DB lookup.
	 */
	public function test_token_rejects_overlong_refresh_token(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => str_repeat( 'r', 5000 ),
				'client_id'     => 'abc123',
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Registration returns 404 when OAuth is disabled.
	 *
	 * The register route gates on OAuth as well as DCR, so turning OAuth off closes it -
	 * a DCR toggle left on (its default) cannot reopen the route while OAuth is off.
	 */
	public function test_register_is_404_when_oauth_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		// A stale legacy DCR row must not resurrect the route while OAuth is off.
		update_option( 'aafm_oauth_dcr_enabled', '1' );

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Every falsy stored form of the OAuth toggle disables registration (404).
	 *
	 * Registration follows OAuth now, so the register route inherits OAuth's fail-closed
	 * reader: stored strings like 'false'/'no'/'off' and a persisted literal boolean false
	 * all read as off and shut the endpoint.
	 */
	public function test_register_is_404_when_oauth_disabled_falsy_values(): void {
		$falsy = array( 'false', 'no', 'off', '0', '' );

		foreach ( $falsy as $value ) {
			update_option( 'aafm_oauth_enabled', $value );

			$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				wp_json_encode(
					array(
						'redirect_uris' => array( 'https://app.example/cb' ),
					)
				)
			);

			$response = rest_do_request( $request );

			$this->assertSame(
				404,
				$response->get_status(),
				'A falsy OAuth toggle (' . wp_json_encode( $value ) . ') should disable registration.'
			);
		}

		// Persisted literal boolean false: seed the row on, then toggle it off -
		// WordPress only stores a literal false when the option already exists.
		add_option( 'aafm_oauth_enabled', '1' );
		update_option( 'aafm_oauth_enabled', false );

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) ) );

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * The aafm_oauth_dcr_enabled filter can force registration closed while OAuth stays on.
	 *
	 * This is the one escape hatch for an advanced operator who runs OAuth but registers
	 * clients by hand: OAuth is enabled (from setUp), yet the filter returning false makes
	 * aafm_oauth_dcr_enabled() false, so POST /register 404s.
	 */
	public function test_register_is_404_when_filter_forces_dcr_off(): void {
		add_filter( 'aafm_oauth_dcr_enabled', '__return_false' );

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) ) );

		$status = rest_do_request( $request )->get_status();
		remove_filter( 'aafm_oauth_dcr_enabled', '__return_false' );

		$this->assertSame( 404, $status );
	}

	/**
	 * With OAuth enabled and no filter override, registration succeeds (201).
	 *
	 * The positive half of DCR-follows-OAuth: OAuth on (setUp) is sufficient for a client
	 * to self-register, with no separate DCR option to set.
	 */
	public function test_register_succeeds_when_oauth_enabled(): void {
		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => 'Test',
				)
			)
		);

		$this->assertSame( 201, rest_do_request( $request )->get_status() );
	}

	/**
	 * Every falsy stored form of the OAuth toggle 404s the token endpoint.
	 *
	 * Same fail-open quirk as DCR, on the unauthenticated token surface: a falsy
	 * toggle must shut the endpoint rather than leave it serving.
	 */
	public function test_token_is_404_when_oauth_disabled_falsy_values(): void {
		$falsy = array( 'false', 'no', 'off', '0', '' );

		foreach ( $falsy as $value ) {
			update_option( 'aafm_oauth_enabled', $value );

			$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
			$request->set_body_params(
				array(
					'grant_type' => 'authorization_code',
					'code'       => 'irrelevant',
				)
			);

			$response = rest_do_request( $request );

			$this->assertSame(
				404,
				$response->get_status(),
				'A falsy OAuth toggle (' . wp_json_encode( $value ) . ') should disable the token endpoint.'
			);
		}

		// Persisted literal boolean false (see the DCR sibling above).
		add_option( 'aafm_oauth_enabled', '1' );
		update_option( 'aafm_oauth_enabled', false );

		$request = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type' => 'authorization_code',
				'code'       => 'irrelevant',
			)
		);

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * With OAuth disabled, /register still returns WordPress's own error shape rather than
	 * the RFC 6749 shape used for real protocol errors.
	 *
	 * D1: a disabled endpoint answers as "this route does not exist" (WordPress's own
	 * rest_no_route body), not as an OAuth error, because there is no grant to describe -
	 * the RFC 6749 §5.2 shape would not apply to a switched-off surface. This is a
	 * deliberate exception carved out during the issue #68 fix, not a gap in it, and this
	 * test locks the exception so a future change cannot quietly merge the two shapes.
	 */
	public function test_register_disabled_returns_wordpress_error_shape(): void {
		update_option( 'aafm_oauth_enabled', '0' );

		$request  = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey(
			'code',
			$data,
			'Response body keys were: ' . wp_json_encode( array_keys( $data ) )
		);
		$this->assertSame( 'rest_no_route', $data['code'] );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	/**
	 * With OAuth disabled, /token still returns WordPress's own error shape. See D1 on the
	 * register test above for why this is deliberate.
	 */
	public function test_token_disabled_returns_wordpress_error_shape(): void {
		update_option( 'aafm_oauth_enabled', '0' );

		$request  = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/token' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey(
			'code',
			$data,
			'Response body keys were: ' . wp_json_encode( array_keys( $data ) )
		);
		$this->assertSame( 'rest_no_route', $data['code'] );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	/**
	 * With OAuth disabled, /revoke still returns WordPress's own error shape. See D1 on the
	 * register test above for why this is deliberate.
	 */
	public function test_revoke_disabled_returns_wordpress_error_shape(): void {
		update_option( 'aafm_oauth_enabled', '0' );

		$request  = new WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/revoke' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey(
			'code',
			$data,
			'Response body keys were: ' . wp_json_encode( array_keys( $data ) )
		);
		$this->assertSame( 'rest_no_route', $data['code'] );
		$this->assertArrayNotHasKey( 'error', $data );
	}
}
