<?php
/**
 * End-to-end OAuth 2.1 handshake integration test.
 *
 * Walks the whole chain the way a real MCP client does — register a public client,
 * mint an authorization code the way the authorize POST does, exchange it at the
 * token endpoint over REST, then prove the minted bearer resolves to the approving
 * WordPress user AND that that resolved identity drives per-user MCP tool
 * visibility, exactly like an Application Password connection. The negative paths
 * (missing PKCE, replay, expiry, redirect mismatch, disabled surface) confirm the
 * grant's one-time / bound / kill-switched guarantees hold through the REST layer,
 * and the frozen-invariant assertions confirm a present-but-invalid token never
 * hard-fails an unrelated route.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\OAuth;

use Oversio\Tests\TestCase;
use WP_REST_Request;

/**
 * Drives the full authorization_code handshake end to end and asserts the
 * OAuth-resolved identity yields the same bounded tool set App Passwords do.
 */
class HandshakeTest extends TestCase {

	/**
	 * The redirect URI registered for the handshake client. https so it passes the
	 * strict redirect-URI allowlist without the loopback-http exception.
	 */
	private const REDIRECT = 'https://app.example/cb';

	/**
	 * A second, DIFFERENT registered redirect URI, used to prove a code is bound to
	 * the exact redirect URI it was minted against.
	 */
	private const REDIRECT_ALT = 'https://app.example/other';

	/**
	 * A fixed PKCE verifier (RFC 7636 §4.1 sample). Its S256 challenge is derived
	 * in-test via challenge_for(), mirroring what a real client computes.
	 */
	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	/**
	 * Stand up the OAuth tables, relax HTTPS for the plain-HTTP test request, register
	 * the REST routes, and register + enable the two ability fixtures so tools/list has
	 * something to filter. Mirrors the ServerToolsTest harness exactly so the per-user
	 * visibility check behaves identically to the rest of the suite.
	 */
	public function set_up(): void {
		parent::set_up();

		// The REST dispatch path reports a production environment; relax the HTTPS
		// requirement the documented agent-dev way so the token handler runs over the
		// test's plain-HTTP request instead of short-circuiting with a 400 — the same
		// documented override RestEndpointsTest uses.
		if ( ! defined( 'OVERSIO_OAUTH_ALLOW_HTTP' ) ) {
			define( 'OVERSIO_OAUTH_ALLOW_HTTP', true );
		}

		// OAuth storage + audit log the registration wrapper writes to.
		oversio_install_oauth_tables();
		oversio_install_activity_log();
		oversio_clear_activity_log();

		// Register the OAuth (and MCP) routes against the REST server for this run.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook fired to populate the REST server in the test.
		do_action( 'rest_api_init' );

		// Contribute the two fixtures to the static registry so oversio_get_enabled_abilities()
		// and the tools/list filter can map tool names back to abilities. The registry
		// catalog is memoized, and parent::set_up() flushed it BEFORE this filter was
		// attached, so flush once more now that the filter is in place — otherwise the
		// next read returns the production catalog without our fixtures and the
		// enabled-abilities intersection drops them.
		add_filter( 'oversio_abilities_registry', array( $this, 'register_fixture_registry' ) );
		oversio_flush_registry_cache();

		// Categories first, inside their gated init action (idempotent).
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );

		// Two fixtures spanning a cap the editor HAS and a cap it LACKS. oversio/pub-read has
		// permission __return_true, so any logged-in user can discover it; oversio/admin-write
		// is gated on manage_options, which an editor lacks, so the editor cannot. The
		// Abilities registry is a process-singleton, so guard re-registration across tests.
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( ! wp_has_ability( 'oversio/pub-read' ) ) {
					oversio_register_ability_with_log(
						'oversio/pub-read',
						array(
							'label'               => 'Pub Read',
							'description'         => 'Anyone may read.',
							'category'            => 'oversio-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array(),
							'permission_callback' => '__return_true',
						)
					);
				}
				if ( ! wp_has_ability( 'oversio/admin-write' ) ) {
					oversio_register_ability_with_log(
						'oversio/admin-write',
						array(
							'label'               => 'Admin Write',
							'description'         => 'Admin only.',
							'category'            => 'oversio-writes',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array(),
							'permission_callback' => static fn() => current_user_can( 'manage_options' ),
						)
					);
				}
			}
		);

		update_option( 'oversio_enabled_abilities', array( 'oversio/pub-read', 'oversio/admin-write' ) );
	}

	/**
	 * Clean the auth header so a bearer set in one test never leaks into the next.
	 */
	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	/**
	 * Contribute the two fixtures to the static registry.
	 *
	 * @param array<string,array<string,mixed>> $registry Registry.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fixture_registry( array $registry ): array {
		$registry['oversio/pub-read']    = array(
			'label'        => 'Pub Read',
			'description'  => 'Anyone may read.',
			'group'        => 'reads',
			'risk'         => 'read',
			'args_builder' => '__return_empty_array',
		);
		$registry['oversio/admin-write'] = array(
			'label'        => 'Admin Write',
			'description'  => 'Admin only.',
			'group'        => 'writes',
			'risk'         => 'write',
			'args_builder' => '__return_empty_array',
		);
		return $registry;
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
	 * Register a public client and return its client_id.
	 *
	 * @param string[] $redirect_uris The redirect URIs to register.
	 * @return string The minted client_id.
	 */
	private function register_client( array $redirect_uris ): string {
		$request = new WP_REST_Request( 'POST', '/oversio-agent-abilities/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => $redirect_uris,
					'client_name'   => 'Handshake Client',
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status(), 'client registration should succeed' );

		$data = $response->get_data();
		return (string) $data['client_id'];
	}

	/**
	 * Mint an authorization code exactly the way oversio_oauth_issue_code_and_redirect()
	 * does on an approved authorize POST: bound to the client, redirect URI, PKCE
	 * challenge, and the MCP endpoint as the forced resource/audience.
	 *
	 * @param string $client_id The client the code is bound to.
	 * @param int    $user_id   The approving WordPress user.
	 * @param string $redirect  The redirect URI the code is bound to.
	 * @param string $challenge The PKCE S256 challenge.
	 * @return string The raw authorization code.
	 */
	private function mint_code( string $client_id, int $user_id, string $redirect, string $challenge ): string {
		return oversio_oauth_mint_code(
			array(
				'client_id'      => $client_id,
				'wp_user_id'     => $user_id,
				'redirect_uri'   => $redirect,
				'code_challenge' => $challenge,
				'resource'       => oversio_endpoint_url(),
			)
		);
	}

	/**
	 * Dispatch an authorization_code token request through the REST server.
	 *
	 * Pass null for $verifier to OMIT the code_verifier param entirely (the
	 * missing-PKCE negative path).
	 *
	 * @param string      $client_id The client_id presented at the token endpoint.
	 * @param string      $code      The raw authorization code.
	 * @param string      $redirect  The redirect URI presented at the token endpoint.
	 * @param string|null $verifier  The PKCE code verifier, or null to omit it.
	 * @return \WP_REST_Response
	 */
	private function exchange( string $client_id, string $code, string $redirect, ?string $verifier ) {
		$params = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirect,
			'client_id'    => $client_id,
		);
		if ( null !== $verifier ) {
			$params['code_verifier'] = $verifier;
		}

		$request = new WP_REST_Request( 'POST', '/oversio-agent-abilities/oauth/token' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * Resolve the MCP tools/list visibility for the CURRENT user.
	 *
	 * The live MCP route is a streamable-HTTP transport that requires a full session
	 * (Mcp-Session-Id, an initialize handshake) before it will answer tools/list, so
	 * driving it through rest_do_request() in-process is out of scope here. The
	 * per-user filtering itself, however, is a pure function of the current user:
	 * oversio_filter_mcp_tools_list() is the exact callback the adapter fires on the
	 * mcp_adapter_tools_list hook at request time, and it decides visibility solely
	 * from the resolved current user (via oversio_user_can_discover_ability). We invoke
	 * that same callback directly against Tool DTO stubs — the canonical harness used
	 * by ServerToolsTest — so the assertion exercises the real visibility logic the
	 * live route would, for whatever user wp_set_current_user() has established.
	 *
	 * @return array<int,string> Sanitized tool names the current user may discover.
	 */
	private function visible_tool_names(): array {
		$tools = array(
			$this->tool_dto( oversio_mcp_tool_name( 'oversio/pub-read' ) ),
			$this->tool_dto( oversio_mcp_tool_name( 'oversio/admin-write' ) ),
		);

		$names = array();
		foreach ( (array) oversio_filter_mcp_tools_list( $tools ) as $tool ) {
			$names[] = $tool->getName();
		}

		return $names;
	}

	/**
	 * Minimal Tool DTO stub exposing getName(), matching the adapter's DTO contract.
	 *
	 * @param string $name Sanitized MCP tool name.
	 * @return object
	 */
	private function tool_dto( string $name ): object {
		return new class( $name ) {
			/**
			 * Stub Tool DTO.
			 *
			 * @param string $name Tool name.
			 */
			public function __construct( private string $name ) {}

			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter DTO accessor.
				return $this->name;
			}
		};
	}

	/**
	 * Happy path: register → mint → exchange → resolve → list.
	 *
	 * Proves the OAuth-resolved identity drives per-user MCP tool visibility, the
	 * same as an Application Password. The editor is chosen deliberately: its
	 * discoverable set is a STRICT SUBSET of the enabled abilities — it can discover
	 * oversio/pub-read (permission __return_true) but NOT oversio/admin-write (manage_options,
	 * which an editor lacks) — so the subset assertion is meaningful.
	 *
	 * Note on the tools/list leg: the adapter's tools/list filter resolves visibility
	 * from the CURRENT user, not by re-reading the bearer (the bearer feeds the
	 * separate determine_current_user resolver), and the live MCP route needs a full
	 * streamable-HTTP session before it answers tools/list. So this asserts the chain
	 * as two linked facts: (5) the bearer resolves to $uid via
	 * oversio_oauth_resolve_current_user, and (6) running the real tools/list filter AS
	 * $uid (see visible_tool_names()) yields exactly the editor's bounded set.
	 * Together they prove the OAuth-resolved uid produces the correct per-user tool set
	 * — which is the integration guarantee under test.
	 */
	public function test_full_handshake_resolves_user_and_lists_their_tools(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Sanity-anchor the role boundary the subset assertion relies on.
		$this->assertFalse( user_can( $uid, 'manage_options' ), 'editor must lack manage_options' );
		$this->assertTrue( user_can( $uid, 'read' ), 'editor must have read' );

		$challenge = $this->challenge_for( self::VERIFIER );
		$client_id = $this->register_client( array( self::REDIRECT ) );
		$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );

		// Exchange the code at the token endpoint.
		$response = $this->exchange( $client_id, $code, self::REDIRECT, self::VERIFIER );
		$this->assertSame( 200, $response->get_status(), 'token exchange should succeed' );

		$data = $response->get_data();
		$this->assertStringStartsWith( 'oversio_oat_', (string) $data['access_token'], 'access token must carry the OAuth prefix' );
		$this->assertNotEmpty( $data['refresh_token'], 'a refresh token must be issued' );
		$this->assertSame( 'Bearer', $data['token_type'], 'the token_type a client reads must be exactly Bearer' );
		$this->assertIsInt( $data['expires_in'], 'expires_in must be an integer the client can schedule a refresh from' );
		$this->assertGreaterThan( 0, $data['expires_in'], 'expires_in must be a positive lifetime' );
		$access_token = (string) $data['access_token'];

		// (5) The minted bearer resolves to the approving user on determine_current_user —
		// but only on the MCP route (the resolver is scoped there, not site-wide).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- snapshot of a server superglobal, restored verbatim after the assertion.
		$prev_uri                      = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token;
		$_SERVER['REQUEST_URI']        = '/' . trim( rest_get_url_prefix(), '/' ) . '/oversio-agent-abilities/mcp';
		$this->assertSame( $uid, oversio_oauth_resolve_current_user( false ), 'the OAuth bearer must resolve to the approving user' );
		if ( null === $prev_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $prev_uri;
		}

		// (6) Acting AS that uid, tools/list reflects the editor's bounded, strict-subset view.
		wp_set_current_user( $uid );
		$names = $this->visible_tool_names();

		$this->assertContains(
			'oversio-pub-read',
			$names,
			'editor CAN discover an enabled ability gated on a cap it holds'
		);
		$this->assertNotContains(
			'oversio-admin-write',
			$names,
			'editor CANNOT discover an enabled ability gated on manage_options'
		);
	}

	/**
	 * Omitting the PKCE code_verifier at the exchange is rejected, no token issued.
	 *
	 * The token handler requires a non-empty code_verifier, so a missing one is an
	 * invalid_grant (400) before PKCE is even checked. The deeper "wrong verifier
	 * fails S256" path is unit-covered in PkceTest; this asserts the integration-level
	 * rejection through the REST layer.
	 */
	public function test_missing_pkce_verifier_is_rejected(): void {
		$uid       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$challenge = $this->challenge_for( self::VERIFIER );
		$client_id = $this->register_client( array( self::REDIRECT ) );
		$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );

		$response = $this->exchange( $client_id, $code, self::REDIRECT, null );

		$this->assertSame( 400, $response->get_status(), 'a missing PKCE verifier must not yield a token' );
		$this->assertArrayNotHasKey(
			'access_token',
			(array) $response->get_data(),
			'no token may be issued without PKCE'
		);
	}

	/**
	 * T1-8: deactivating a client blocks redemption of a code minted before deactivation —
	 * is_active is otherwise only checked at authorize-time.
	 */
	public function test_deactivated_client_cannot_redeem_code(): void {
		$uid       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$challenge = $this->challenge_for( self::VERIFIER );
		$client_id = $this->register_client( array( self::REDIRECT ) );
		$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );

		// Disable the client AFTER the code was minted.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'oversio_oauth_clients',
			array( 'is_active' => 0 ),
			array( 'client_id' => $client_id ),
			array( '%d' ),
			array( '%s' )
		);

		$response = $this->exchange( $client_id, $code, self::REDIRECT, self::VERIFIER );
		$this->assertSame( 400, $response->get_status(), 'a deactivated client must not redeem its code' );
		$this->assertArrayNotHasKey( 'access_token', (array) $response->get_data(), 'no token may be issued to a deactivated client' );
	}

	/**
	 * A code is single-use: a successful exchange burns it; replay fails with 400.
	 */
	public function test_replayed_code_is_rejected(): void {
		$uid       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$challenge = $this->challenge_for( self::VERIFIER );
		$client_id = $this->register_client( array( self::REDIRECT ) );
		$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );

		$first = $this->exchange( $client_id, $code, self::REDIRECT, self::VERIFIER );
		$this->assertSame( 200, $first->get_status(), 'first exchange should succeed' );

		$replay = $this->exchange( $client_id, $code, self::REDIRECT, self::VERIFIER );
		$this->assertSame( 400, $replay->get_status(), 'replaying a consumed code must fail' );
	}

	/**
	 * An expired code is rejected: push its expires_at into the past, then exchange.
	 */
	public function test_expired_code_is_rejected(): void {
		$uid       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$challenge = $this->challenge_for( self::VERIFIER );
		$client_id = $this->register_client( array( self::REDIRECT ) );
		$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );

		// Force the code stale by hashing it the same way the minter does and
		// rewriting its expires_at to an hour ago.
		global $wpdb;
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'oversio_oauth_codes',
			array( 'expires_at' => $past ),
			array( 'code_hash' => hash( 'sha256', $code ) ),
			array( '%s' ),
			array( '%s' )
		);

		$response = $this->exchange( $client_id, $code, self::REDIRECT, self::VERIFIER );
		$this->assertSame( 400, $response->get_status(), 'an expired code must fail' );
	}

	/**
	 * A code is bound to its redirect URI: presenting a DIFFERENT registered URI fails.
	 */
	public function test_wrong_redirect_uri_is_rejected(): void {
		$uid       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$challenge = $this->challenge_for( self::VERIFIER );

		// Register both URIs on the same client so the mismatch is a redirect-binding
		// failure, not an unknown-redirect failure.
		$client_id = $this->register_client( array( self::REDIRECT, self::REDIRECT_ALT ) );
		$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );

		// Exchange presenting the OTHER registered URI the code was not minted against.
		$response = $this->exchange( $client_id, $code, self::REDIRECT_ALT, self::VERIFIER );
		$this->assertSame( 400, $response->get_status(), 'a code is bound to the redirect URI it was minted with' );
	}

	/**
	 * With OAuth disabled the token route is gone (404) and the resolver no-ops.
	 *
	 * The kill switch must close the public surface AND stop the bearer validator from
	 * resolving a user, so a disabled install behaves as if OAuth were never wired.
	 */
	public function test_disabled_oauth_makes_token_endpoint_404(): void {
		$prior = get_option( 'oversio_oauth_enabled', null );
		update_option( 'oversio_oauth_enabled', '0' );

		try {
			$uid       = self::factory()->user->create( array( 'role' => 'editor' ) );
			$challenge = $this->challenge_for( self::VERIFIER );

			// Register + mint must run while the surface is still reachable, so flip the
			// toggle AFTER the code exists, then attempt the exchange with it off.
			update_option( 'oversio_oauth_enabled', '1' );
			$client_id = $this->register_client( array( self::REDIRECT ) );
			$code      = $this->mint_code( $client_id, $uid, self::REDIRECT, $challenge );
			update_option( 'oversio_oauth_enabled', '0' );

			$response = $this->exchange( $client_id, $code, self::REDIRECT, self::VERIFIER );
			$this->assertSame( 404, $response->get_status(), 'the token route is gone when OAuth is disabled' );

			// Even a syntactically valid bearer resolves nothing while OAuth is off: the
			// resolver returns the incoming value untouched.
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer oversio_oat_' . str_repeat( 'a', 64 );
			$this->assertFalse(
				oversio_oauth_resolve_current_user( false ),
				'a disabled OAuth surface resolves no user from a bearer'
			);
		} finally {
			// Be explicit even though TestCase isolation rolls options back.
			if ( null === $prior ) {
				delete_option( 'oversio_oauth_enabled' );
			} else {
				update_option( 'oversio_oauth_enabled', $prior );
			}
		}
	}

	/**
	 * Frozen invariant (a): a NON-oversio_oat_ bearer is returned untouched.
	 *
	 * Canonically covered in ValidatorTest; re-asserted here in the handshake context
	 * because the resolver's "leave foreign bearers for their own resolver" guarantee
	 * is what lets OAuth coexist with App Passwords on determine_current_user.
	 */
	public function test_non_oauth_bearer_passes_through_untouched(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-one-of-ours';
		$this->assertFalse(
			oversio_oauth_resolve_current_user( false ),
			'a foreign bearer leaves the incoming value untouched'
		);

		// And an already-resolved user (e.g. App Password resolved earlier) is never preempted.
		$this->assertSame(
			42,
			oversio_oauth_resolve_current_user( 42 ),
			'an identity resolved by an earlier filter is left intact'
		);
	}

	/**
	 * Frozen invariant (b): a garbage oversio_oat_ bearer must not break an unrelated route.
	 *
	 * This is the integration concern the unit tests can't fully cover: with a bogus but
	 * well-formed OAuth bearer on the request, an ordinary public REST route must still
	 * serve normally (no 401/500 forced by our determine_current_user or
	 * rest_authentication_errors hooks). A present-but-invalid token simply fails to
	 * resolve a user; it never hard-fails the request.
	 */
	public function test_invalid_oauth_token_does_not_break_unrelated_route(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer oversio_oat_deadbeef';

		// Our resolver must not invent a user from an unknown token.
		$this->assertFalse(
			oversio_oauth_resolve_current_user( false ),
			'an unknown OAuth token resolves no user'
		);

		// rest_authentication_errors must not become a WP_Error on our account. Core may
		// legitimately resolve the chain to true ("auth ok, no error") or null ("no
		// opinion"); what our oversio_oauth_rest_authentication_errors pass-through must
		// never do is convert "no user resolved" into a hard failure (a WP_Error).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core REST filter, applied to assert our pass-through hook never forces an error.
		$auth_errors = apply_filters( 'rest_authentication_errors', null );
		$this->assertNotInstanceOf(
			\WP_Error::class,
			$auth_errors,
			'a bogus OAuth bearer must not force a REST auth error'
		);

		// A simple public route still serves a 200 with the bogus bearer present.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/' ) );
		$this->assertSame(
			200,
			$response->get_status(),
			'an unrelated public route must still work with a bogus OAuth bearer set'
		);
	}
}
