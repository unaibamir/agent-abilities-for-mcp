<?php
/**
 * Tests for the OAuth bearer-token validator: the determine_current_user
 * resolver and the access-token row resolver.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Verifies that a valid `aafm_oat_` bearer resolves to the approving user on the
 * determine_current_user filter, while every non-OAuth path - App Passwords,
 * already-resolved users, foreign bearers, expired/wrong-audience tokens - is
 * left byte-for-byte unchanged.
 */
class ValidatorTest extends TestCase {

	/**
	 * Saved Authorization header values, restored in tear_down so a header set in
	 * one test can never bleed into the next.
	 *
	 * @var array<string,string|null>
	 */
	private array $original_auth = array();

	/**
	 * Saved REQUEST_URI / HTTPS / rest_route, restored in tear_down.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_request = array();

	/**
	 * $_GET, $_POST, $_SERVER and the current user as set_up() found them, restored in tear_down().
	 *
	 * @var array<string,mixed>
	 */
	private array $saved_globals = array();

	/**
	 * Cleanups a test registered, run last-first in tear_down().
	 *
	 * @var array<int,callable>
	 */
	private array $cleanups = array();

	/**
	 * The WP test suite rewrites plugin CREATE TABLE to its TEMPORARY form, so the
	 * token table must be installed per test before any mint/validate runs.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->saved_globals = array(
			'get'          => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- snapshot, restored verbatim.
			'post'         => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- snapshot, restored verbatim.
			'server'       => $_SERVER,
			'current_user' => $GLOBALS['current_user'] ?? null,
		);

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended
		$this->original_auth    = array(
			'HTTP_AUTHORIZATION'          => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
			'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
		);
		$this->original_request = array(
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			'HTTPS'       => $_SERVER['HTTPS'] ?? null,
			'rest_route'  => $_GET['rest_route'] ?? null,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['rest_route'] );

		// Default the request to the MCP route over HTTPS so the route-scope and
		// HTTPS-policy gates pass; individual tests override these to exercise the
		// off-route and plain-HTTP branches. The harness reports a production
		// environment, so HTTPS is genuinely required here. The site uses pretty permalinks, so
		// WordPress routes the /wp-json/ path at all.
		$this->set_permalink_structure( '/%postname%/' );
		$this->on_mcp_route();
		$this->route_as_rest_request();
		$_SERVER['HTTPS'] = 'on';

		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();

		// R11-2: aafm_oauth_client_is_deactivated() now denies a client_id with no row at all
		// (not just a row confirmed inactive), so every synthetic client_id this file mints
		// tokens for needs a real, active client row to resolve.
		foreach ( array( 'c', 'wrong-audience-client', 'attribution_client' ) as $client_id ) {
			$this->register_client_row( $client_id );
		}

		// OAuth is OFF by default now; the resolver's happy path requires it on. The
		// disabled-bearer test sets it back to '0' explicitly.
		update_option( 'aafm_oauth_enabled', '1' );
	}

	/**
	 * Seed a minimal, active OAuth client row for a synthetic client_id used only to mint
	 * tokens in this file (never through aafm_oauth_register_client(), which generates its
	 * own random id).
	 *
	 * @param string $client_id Public client id to seed.
	 */
	private function register_client_row( string $client_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => $client_id,
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
	}

	/**
	 * Restore the Authorization / request keys to exactly their pre-test state.
	 */
	public function tear_down(): void {
		foreach ( array_reverse( $this->cleanups ) as $cleanup ) {
			$cleanup();
		}
		$this->cleanups = array();
		$this->set_permalink_structure( '' );
		foreach ( $this->original_auth as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		foreach ( array( 'REQUEST_URI', 'HTTPS' ) as $key ) {
			if ( null === $this->original_request[ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->original_request[ $key ];
			}
		}
		if ( null === $this->original_request['rest_route'] ) {
			unset( $_GET['rest_route'] );
		} else {
			$_GET['rest_route'] = $this->original_request['rest_route'];
		}
		$_GET                    = $this->saved_globals['get'];
		$_POST                   = $this->saved_globals['post'];
		$_SERVER                 = $this->saved_globals['server'];
		$GLOBALS['current_user'] = $this->saved_globals['current_user']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored as set_up() found it.
		parent::tear_down();
	}

	/**
	 * Point the request at the MCP REST route (pretty-permalink form).
	 */
	private function on_mcp_route(): void {
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/agent-abilities-for-mcp/mcp';
	}

	/**
	 * Set the incoming Authorization header for the request under test.
	 *
	 * @param string $value Full header value, e.g. "Bearer aafm_oat_...".
	 */
	private function set_bearer( string $value ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = $value;
	}

	/**
	 * Set the credential under the FastCGI-only REDIRECT_HTTP_AUTHORIZATION key,
	 * leaving HTTP_AUTHORIZATION unset (set_up already cleared both). tear_down
	 * restores REDIRECT_HTTP_AUTHORIZATION from $original_auth.
	 *
	 * @param string $value Full header value, e.g. "Bearer aafm_oat_...".
	 */
	private function set_redirect_bearer( string $value ): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = $value;
	}

	/**
	 * Read a token row directly so a test can mutate expires_at.
	 *
	 * @param string $access_raw Raw access token.
	 * @return array<string,mixed>|null
	 */
	private function row_by_access( string $access_raw ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT * FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE token_hash = %s",
				hash( 'sha256', $access_raw )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A valid access token bound to this endpoint resolves to its user.
	 */
	public function test_resolves_valid_bearer_to_user(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * A valid aafm_oat_ token authenticates on the MCP route but NOT on an unrelated core
	 * REST route - the MCP token must never become a site-wide WP bearer credential.
	 */
	public function test_token_does_not_resolve_off_mcp_route(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		// On the MCP route it resolves.
		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );

		// On an unrelated core REST route the same token resolves no user.
		$this->route_off_mcp();
		$this->assertFalse( aafm_oauth_resolve_current_user( false ), 'An MCP token must not authenticate on a non-MCP REST route.' );
	}

	/**
	 * The plain-permalink rest_route form (?rest_route=/agent-abilities-for-mcp/mcp) is also
	 * recognised as the MCP route.
	 */
	public function test_token_resolves_on_plain_permalink_rest_route(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		// Plain-permalink request: index.php with the rest_route query var, no pretty path.
		$_SERVER['REQUEST_URI'] = '/index.php';
		$_GET['rest_route']     = '/agent-abilities-for-mcp/mcp';

		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * TF-2: on a subdirectory install (site under a path prefix like /blog), the pretty-permalink
	 * MCP path is /blog/wp-json/agent-abilities-for-mcp/mcp. The route guard must derive the
	 * expected path from rest_url(), which carries that prefix, so a valid token still resolves -
	 * a hardcoded /wp-json/... literal would never match.
	 */
	public function test_token_resolves_on_subdirectory_install(): void {
		// Model a site installed under /blog with pretty permalinks: rest_url() returns the
		// prefixed pretty endpoint (https://host/blog/wp-json/agent-abilities-for-mcp/mcp).
		$rest_prefix = trim( rest_get_url_prefix(), '/' );
		$pretty_url  = static function ( string $url ) use ( $rest_prefix ): string {
			// Only rewrite the MCP endpoint URL; the route may sit in the path (pretty) or the
			// rest_route query var (plain), so match against the whole URL. Leave everything else
			// (and any already-prefixed call) alone so the audience and the route derivation agree.
			if ( false === strpos( $url, 'agent-abilities-for-mcp/mcp' ) || false !== strpos( $url, '/blog/' ) ) {
				return $url;
			}
			$host = (string) wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( $url, PHP_URL_HOST );
			return $host . '/blog/' . $rest_prefix . '/agent-abilities-for-mcp/mcp';
		};
		add_filter( 'rest_url', $pretty_url );

		try {
			$uid    = self::factory()->user->create();
			$tokens = aafm_oauth_mint_tokens(
				array(
					'wp_user_id' => $uid,
					'client_id'  => 'c',
					// Audience is the prefixed endpoint, exactly as a subdir install would mint it.
					'resource'   => aafm_endpoint_url(),
				)
			);
			$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

			// Pretty-permalink request carrying the /blog path prefix.
			$mcp_path               = (string) wp_parse_url( aafm_endpoint_url(), PHP_URL_PATH );
			$_SERVER['REQUEST_URI'] = $mcp_path;
			unset( $_GET['rest_route'] );
			$this->assertStringStartsWith( '/blog/', $mcp_path, 'The simulated request path must carry the /blog prefix.' );

			$this->assertSame(
				$uid,
				aafm_oauth_resolve_current_user( false ),
				'A valid MCP token must resolve on a subdirectory install whose path carries a prefix.'
			);

			// A non-MCP route under the same prefix must still be denied.
			$this->route_off_mcp();
			$this->assertFalse(
				aafm_oauth_resolve_current_user( false ),
				'An MCP token must not authenticate on a non-MCP route even under the same path prefix.'
			);
		} finally {
			remove_filter( 'rest_url', $pretty_url );
		}
	}

	/**
	 * Where HTTPS is required (production) and the request is plain http, a valid token does
	 * not resolve - the validator enforces the same HTTPS policy as the other OAuth paths.
	 */
	public function test_token_does_not_resolve_over_plain_http_when_https_required(): void {
		if ( ! aafm_oauth_https_required() ) {
			$this->markTestSkipped( 'HTTPS is not required in this environment; the plain-http gate cannot be exercised.' );
		}

		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		// Drop TLS: is_ssl() now returns false while HTTPS is still required.
		unset( $_SERVER['HTTPS'] );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ), 'A bearer over plain http must not resolve when HTTPS is required.' );
	}

	/**
	 * Transport visibility: the HTTPS-required plain-http bail used to be silent. A real aafm_oat_
	 * bearer presented over http now leaves one bounded (transport) denied row so the failure is
	 * traceable, while the auth decision itself (no user resolved) is unchanged.
	 */
	public function test_plain_http_bail_writes_a_transport_row(): void {
		if ( ! aafm_oauth_https_required() ) {
			$this->markTestSkipped( 'HTTPS is not required in this environment; the plain-http gate cannot be exercised.' );
		}

		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		unset( $_SERVER['HTTPS'] );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );

		$row = $this->latest_activity_row();
		$this->assertNotNull( $row, 'The HTTPS-scheme bail must write a transport row instead of staying invisible.' );
		$this->assertSame( '(transport)', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
		$this->assertSame( 'Bearer token presented over insecure HTTP', $row['detail'] );
	}

	/**
	 * T1-8: deactivating a client invalidates its live access tokens - a bearer whose owning
	 * client is disabled no longer resolves a user, even on the MCP route.
	 */
	public function test_bearer_does_not_resolve_for_deactivated_client(): void {
		$client = aafm_oauth_register_client( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) );
		$this->assertIsArray( $client );
		$client_id = (string) $client['client_id'];

		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => $client_id,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		// While the client is active the bearer resolves.
		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );

		// Deactivate the client; the live access token must stop resolving.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_clients',
			array( 'is_active' => 0 ),
			array( 'client_id' => $client_id ),
			array( '%d' ),
			array( '%s' )
		);
		$this->assertFalse( aafm_oauth_resolve_current_user( false ), 'a deactivated client must invalidate its live access token' );

		$row = $this->latest_activity_row();
		$this->assertIsArray( $row, 'A bearer for a deactivated client must write a denied audit row.' );
		$this->assertSame( 'oauth:bearer', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
		$this->assertStringContainsString( 'client_' . $client_id, (string) $row['arg_keys'] );
	}

	/**
	 * The bearer is read from the FastCGI-only REDIRECT_HTTP_AUTHORIZATION key
	 * when HTTP_AUTHORIZATION is absent - a valid token there resolves its user.
	 */
	public function test_resolves_bearer_from_redirect_http_authorization(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$this->set_redirect_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * FROZEN INVARIANT: a non-aafm_oat_ bearer is left untouched - App Passwords
	 * and every other scheme resolve undisturbed.
	 */
	public function test_ignores_non_aafm_bearer(): void {
		$this->set_bearer( 'Bearer someoneelsestoken' );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * FROZEN INVARIANT: an already-resolved user is never preempted, even when a
	 * valid OAuth bearer for a different user is present.
	 */
	public function test_does_not_preempt_already_resolved_user(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $user_a,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $user_b, aafm_oauth_resolve_current_user( $user_b ) );
	}

	/**
	 * An expired access token does not resolve a user.
	 */
	public function test_expired_token_returns_incoming_value(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'token_hash' => hash( 'sha256', $tokens['access_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * A token minted for a different audience (resource) is ignored (RFC 8707).
	 */
	public function test_wrong_audience_token_is_ignored(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => 'https://evil.example/mcp',
			)
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * Fetch the single most recent activity row, or null when the log is empty.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latest_activity_row(): ?array {
		$rows = aafm_query_activity( array( 'per_page' => 5 ) );
		return isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * L3: an inactive or expired bearer - a real token row that was once issued but no longer
	 * validates - writes a denied `oauth:bearer` audit row, so a stolen or replayed credential leaves
	 * a trace even though it never resolves an owning client. A bearer that matches no stored token at
	 * all is NOT logged (see test_unknown_bearer_writes_no_audit_row); only a real credential does.
	 */
	public function test_expired_token_writes_a_denied_bearer_audit_row(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'token_hash' => hash( 'sha256', $tokens['access_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );

		$row = $this->latest_activity_row();
		$this->assertIsArray( $row, 'An expired bearer must write an audit row.' );
		$this->assertSame( 'oauth:bearer', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
	}

	/**
	 * L3: a wrong-audience bearer writes a denied `oauth:bearer` row carrying the client_id
	 * the token actually belongs to, so an operator can trace which client presented it.
	 */
	public function test_wrong_audience_token_writes_a_denied_bearer_audit_row(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'wrong-audience-client',
				'resource'   => 'https://evil.example/mcp',
			)
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );

		$row = $this->latest_activity_row();
		$this->assertIsArray( $row, 'A wrong-audience bearer must write an audit row.' );
		$this->assertSame( 'oauth:bearer', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
		$this->assertStringContainsString( 'client_wrong-audience-client', (string) $row['arg_keys'] );
	}

	/**
	 * A bearer that carries our aafm_oat_ prefix but matches NO stored token writes no audit row.
	 * Anyone can fabricate the prefix without any credential, so logging that denial would let an
	 * unauthenticated caller grow aafm_activity_log at will. The resolver still refuses the request
	 * (returns the incoming value); it just does not record the unauthenticated miss. Genuine credential
	 * misuse - an expired or wrong-audience real token - still writes a row (the tests above).
	 */
	public function test_unknown_bearer_writes_no_audit_row(): void {
		$this->set_bearer( 'Bearer ' . AAFM_OAUTH_ACCESS_TOKEN_PREFIX . 'this-token-was-never-issued' );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ), 'An unknown bearer must not resolve a user.' );
		$this->assertNull(
			$this->latest_activity_row(),
			'An unknown bearer that matches no stored token must not write an audit row.'
		);
	}

	/**
	 * The existence probe underpinning the audit gate: it reports true for a real token row regardless
	 * of active/expiry state, and false for a token that was never stored. This is what separates
	 * genuine credential misuse (worth auditing) from an unauthenticated fabricated prefix.
	 */
	public function test_access_token_row_exists_tracks_real_rows_only(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$this->assertTrue(
			aafm_oauth_access_token_row_exists( $tokens['access_token'] ),
			'A real, active token row must exist.'
		);

		// Expire it: the row still exists even though it no longer validates.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'token_hash' => hash( 'sha256', $tokens['access_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);
		$this->assertTrue(
			aafm_oauth_access_token_row_exists( $tokens['access_token'] ),
			'An expired token row still exists - existence ignores the active/unexpired predicate.'
		);
		$this->assertFalse(
			aafm_oauth_access_token_row_exists( AAFM_OAUTH_ACCESS_TOKEN_PREFIX . 'never-issued' ),
			'A token that was never stored must not exist.'
		);
	}

	/**
	 * With no Authorization header at all, the incoming value passes through
	 * unchanged - for both a falsey and a non-zero incoming user id.
	 */
	public function test_no_bearer_returns_incoming_value(): void {
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
		$this->assertSame( 7, aafm_oauth_resolve_current_user( 7 ) );
	}

	/**
	 * When OAuth is disabled, a valid aafm_oat_ bearer is ignored.
	 */
	public function test_disabled_oauth_ignores_bearer(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		update_option( 'aafm_oauth_enabled', '0' );
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$resolved = aafm_oauth_resolve_current_user( false );

		delete_option( 'aafm_oauth_enabled' );

		$this->assertFalse( $resolved );
	}

	/**
	 * The row resolver returns the audience and user for a known token, and null
	 * for an unknown one.
	 */
	public function test_get_access_token_row_returns_resource_and_user(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$row = aafm_oauth_get_access_token_row( $tokens['access_token'] );

		$this->assertIsArray( $row );
		$this->assertSame( aafm_endpoint_url(), $row['resource'] );
		$this->assertSame( $uid, (int) $row['wp_user_id'] );

		$this->assertNull( aafm_oauth_get_access_token_row( 'aafm_oat_unknown' ) );
	}

	/**
	 * Decisive-path coverage: aafm_oauth_get_access_token_row() is, by its own docblock, the
	 * highest-stakes instance of the stale-reader class in this plugin - a bare $wpdb->get_row()
	 * would hand back whatever OTHER token's row a preceding query left in $wpdb->last_result,
	 * authenticating the caller as a different principal entirely. Plants a real token lookup
	 * immediately before the one under test (the exact "positive prior query" shape every other
	 * instance of this defect class depends on), fails only the token's own row read, and proves
	 * the resolver denies - returns null - rather than resolving to either token's row.
	 */
	public function test_get_access_token_row_fails_closed_when_its_own_read_fails_after_a_positive_prior_query(): void {
		$owner_uid = self::factory()->user->create();
		$other_uid = self::factory()->user->create();
		$owner     = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $owner_uid,
				'client_id'  => 'owner-client',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$unrelated = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $other_uid,
				'client_id'  => 'other-client',
				'resource'   => aafm_endpoint_url(),
			)
		);

		// Plants a real, non-null row in $wpdb->last_result - a genuinely successful, unrelated
		// lookup, the exact precondition R7-2/R8-1's shared shape depends on.
		$this->assertIsArray( aafm_oauth_get_access_token_row( $unrelated['access_token'] ) );

		$row = QueryFaultInjector::fail_query(
			'token_hash = ',
			static function () use ( $owner ) {
				return aafm_oauth_get_access_token_row( $owner['access_token'] );
			}
		);

		$this->assertNull( $row, 'a failed row read must deny, never silently resolve to a stale prior lookup\'s token.' );
	}

	/**
	 * Verifies that aafm_endpoint_url() returns the same string whether or not $wp_rewrite
	 * is instantiated. When the OAuth bearer hits determine_current_user early (before
	 * $wp_rewrite exists), the validator's audience hash_equals() compares the token's
	 * stored resource (minted when $wp_rewrite WAS present) against the reconstructed
	 * URL (minted when $wp_rewrite is NULL). They must be byte-identical; if they
	 * diverge the check silently fails and every valid bearer resolves no user.
	 *
	 * This also verifies that aafm_endpoint_url() does not fatal when $wp_rewrite is
	 * null - the regression that caused HTTP 500 on the first claude.ai OAuth connect.
	 */
	public function test_endpoint_url_is_consistent_with_null_wp_rewrite(): void {
		// Capture the URL while $wp_rewrite IS available (mint-time path).
		$url_with_rewrite = aafm_endpoint_url();

		$saved_rewrite = $GLOBALS['wp_rewrite'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulate the early-bootstrap state where $wp_rewrite is not yet set.
		$GLOBALS['wp_rewrite'] = null;

		try {
			// Must not fatal, and must return the same URL (check-time path).
			$url_without_rewrite = aafm_endpoint_url();

			$this->assertSame(
				$url_with_rewrite,
				$url_without_rewrite,
				'aafm_endpoint_url() must return byte-identical output with and without $wp_rewrite ' .
				'so the RFC 8707 audience hash_equals() passes on the determine_current_user path.'
			);

			$this->assertStringContainsString(
				'agent-abilities-for-mcp/mcp',
				$url_without_rewrite,
				'aafm_endpoint_url() must include the MCP route even when $wp_rewrite is null.'
			);
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the exact prior global so the null state never bleeds into another test.
			$GLOBALS['wp_rewrite'] = $saved_rewrite;
		}
	}

	/**
	 * A full bearer-token resolve still works when $wp_rewrite is null at the time the
	 * determine_current_user filter fires. This is the exact scenario that caused HTTP
	 * 500 on the claude.ai OAuth connect flow: the audience check in the validator calls
	 * aafm_endpoint_url(), which previously called rest_url() unconditionally and fataled
	 * on a null $wp_rewrite. With the fix in place, a valid token must resolve its user.
	 */
	public function test_bearer_resolves_with_null_wp_rewrite(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				// Token minted while $wp_rewrite is present (the normal REST request path).
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$saved_rewrite = $GLOBALS['wp_rewrite'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulate early determine_current_user timing.
		$GLOBALS['wp_rewrite'] = null;

		try {
			$resolved = aafm_oauth_resolve_current_user( false );
			$this->assertSame(
				$uid,
				$resolved,
				'A valid bearer minted with $wp_rewrite present must resolve its user even when ' .
				'$wp_rewrite is null at check-time (determine_current_user early-bootstrap scenario).'
			);
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$GLOBALS['wp_rewrite'] = $saved_rewrite;
		}
	}

	/**
	 * The route WordPress parsed is AUTHORITATIVE over the request path. A request whose path IS the
	 * MCP route but whose parsed rest_route is an unrelated core route (e.g. /wp/v2/users/me) must NOT
	 * be classified as MCP-targeted - otherwise an audience-bound aafm_oat_ token would resolve a user
	 * for a route WordPress actually dispatches elsewhere, turning it into a general credential.
	 */
	public function test_rest_route_query_var_overrides_matching_path(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		// set_up() already pointed REQUEST_URI at the MCP path. WordPress parsed a non-MCP rest_route.
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users/me';
		$this->assertFalse(
			aafm_oauth_request_targets_mcp_route(),
			'A non-MCP rest_route must win over an MCP request path.'
		);
		$this->assertFalse(
			aafm_oauth_resolve_current_user( false ),
			'A valid bearer must not resolve when rest_route dispatches to a non-MCP route.'
		);

		// Positive control: rest_route pointing at the MCP route resolves normally.
		$this->route_as_rest_request();
		$this->assertTrue( aafm_oauth_request_targets_mcp_route() );
		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * Route strings and whether core's router matches them to the MCP route.
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public function mcp_route_match_provider(): array {
		$mcp = '/agent-abilities-for-mcp/mcp';
		return array(
			'the route'             => array( $mcp, true ),
			'upper case'            => array( strtoupper( $mcp ), true ),
			'one trailing newline'  => array( $mcp . "\n", true ),
			'trailing slash'        => array( $mcp . '/', false ),
			'two trailing newlines' => array( $mcp . "\n\n", false ),
			'a longer route'        => array( $mcp . 'x', false ),
			'empty'                 => array( '', false ),
			'null'                  => array( null, false ),
			'array'                 => array( array(), false ),
			'the OAuth token route' => array( '/agent-abilities-for-mcp/oauth/token', false ),
		);
	}

	/**
	 * The shared route predicate answers as core's route regex does (`@^route$@i`).
	 *
	 * @dataProvider mcp_route_match_provider
	 *
	 * @param mixed $route   Route string as get_route() returns it.
	 * @param bool  $matches Whether core dispatches it to the MCP route.
	 */
	public function test_the_route_predicate_matches_as_cores_router_does( $route, bool $matches ): void {
		$this->assertSame( $matches, aafm_is_mcp_route( $route ) );
	}

	/**
	 * The resolver's re-entrancy guard. Steps 5-9 build site URLs, firing the home_url/rest_url
	 * filter chain DURING user resolution. A third-party filter there that resolves the current user
	 * would re-enter this callback; without the guard that recurses until memory is exhausted. Prove
	 * the outer resolve still completes and the nested re-entrant call returns the incoming value
	 * (false) rather than recursing.
	 */
	public function test_resolver_does_not_recurse_under_reentrant_home_url_filter(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$nested_called = false;
		$nested_return = 'unset';
		$reentrant     = static function ( $url ) use ( &$nested_called, &$nested_return ) {
			// Mimic a third-party home_url filter that resolves the current user during the
			// resolver's own URL build. Fire exactly once; record what the nested call returns.
			if ( ! $nested_called ) {
				$nested_called = true;
				$nested_return = aafm_oauth_resolve_current_user( false );
			}
			return $url;
		};
		add_filter( 'home_url', $reentrant );

		try {
			$resolved = aafm_oauth_resolve_current_user( false );

			$this->assertTrue( $nested_called, 'The re-entrant home_url filter must fire during the URL build.' );
			$this->assertFalse(
				$nested_return,
				'The nested re-entrant resolve must return the incoming value (false), not recurse.'
			);
			$this->assertSame(
				$uid,
				$resolved,
				'The outer resolve must still complete under a re-entrant home_url filter.'
			);
		} finally {
			remove_filter( 'home_url', $reentrant );
		}
	}

	/**
	 * M16: a successful bearer resolve records the client_id via aafm_oauth_current_client_id(),
	 * purely for activity-log attribution - register.php reads it back when logging the ability
	 * call that follows on this same request.
	 */
	public function test_successful_resolve_records_client_id_for_audit_attribution(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'attribution_client',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
		$this->assertSame( 'attribution_client', aafm_oauth_current_client_id() );
	}

	/**
	 * M16: a bearer that never resolves a user (no header, wrong audience, expired, deactivated
	 * client) must never populate the client_id store - a failed or absent OAuth attempt is not
	 * an attributed call.
	 */
	public function test_failed_resolve_does_not_record_a_client_id(): void {
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
		$this->assertSame( '', aafm_oauth_current_client_id() );
	}

	/**
	 * 1.2.0 regression pin: the resolver bails before touching any helper that only exists after
	 * aafm_bootstrap() has run.
	 *
	 * The determine_current_user filter is registered at plugin-include time, so another active
	 * plugin resolving the current user during plugins_loaded (The Events Calendar calls
	 * wp_create_nonce() there) fires this callback BEFORE our bootstrap defines
	 * aafm_mcp_rest_route() and aafm_endpoint_url(). Pre-1.2.0 that was a fatal inside a
	 * determine_current_user callback, which white-screened every logged-out page view.
	 *
	 * The guard cannot be exercised in-process: both helpers are defined for the whole suite and
	 * PHP cannot undefine a function. So this pins the structure instead (the same layer
	 * ActivationHookLoadingTest uses for its load-order guarantee): both function_exists() guards
	 * must be present in the resolver, must fail closed by returning the incoming user, and must
	 * appear before the resolver's first real call to either helper.
	 */
	public function test_resolver_guards_pre_bootstrap_helpers_before_calling_them(): void {
		$fn   = new \ReflectionFunction( 'aafm_oauth_resolve_current_user' );
		$file = (string) $fn->getFileName();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- reading the plugin's own source from disk in a test.
		$lines = file( $file );
		$this->assertIsArray( $lines, 'The resolver source file must be readable.' );
		$body = array_slice( $lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1 );

		// Strip comment lines: the step comments name both helpers ahead of the guard, and a
		// comment must never be able to satisfy (or spoil) a positional check on real code.
		$code = implode(
			'',
			array_filter(
				$body,
				static function ( $line ) {
					$trimmed = ltrim( $line );
					return 0 !== strpos( $trimmed, '//' )
						&& 0 !== strpos( $trimmed, '*' )
						&& 0 !== strpos( $trimmed, '/*' );
				}
			)
		);

		// The guard exists and fails closed by passing the incoming user through unchanged.
		$this->assertMatchesRegularExpression(
			"/if \\( ! function_exists\\( 'aafm_mcp_rest_route' \\) \\|\\| ! function_exists\\( 'aafm_endpoint_url' \\) \\) \\{\\s*return \\\$user_id;/",
			$code,
			'The resolver must bail with the incoming user when the bootstrap-defined helpers are '
			. 'not loaded yet; without this, an early determine_current_user call fatals and '
			. 'white-screens every logged-out page view.'
		);

		// And it runs before the first real call to either helper. The open paren excludes the
		// quoted names inside the guard itself.
		$guard      = (int) strpos( $code, "function_exists( 'aafm_mcp_rest_route' )" );
		$route_call = strpos( $code, 'aafm_oauth_request_targets_mcp_route(' );
		$url_call   = strpos( $code, 'aafm_endpoint_url(' );

		$this->assertNotFalse( $route_call, 'The resolver is expected to match the MCP route.' );
		$this->assertNotFalse( $url_call, 'The resolver is expected to bind the token audience.' );
		$this->assertLessThan(
			(int) $route_call,
			$guard,
			'The pre-bootstrap guard must run before the route match that needs aafm_mcp_rest_route().'
		);
		$this->assertLessThan(
			(int) $url_call,
			$guard,
			'The pre-bootstrap guard must run before the audience binding that needs aafm_endpoint_url().'
		);
	}

	/**
	 * Mint a token for a new user bound to this endpoint, as the endpoint URL reads now, and present it.
	 *
	 * @param string $role Role of the approving user.
	 * @return int The approving user's id.
	 */
	private function present_valid_bearer( string $role = 'subscriber' ): int {
		$uid    = self::factory()->user->create( array( 'role' => $role ) );
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );
		return $uid;
	}

	/**
	 * Remove the REST rewrite rules core registers from the rewrite object's top rules, and put them
	 * back in tear_down().
	 *
	 * @return void
	 */
	private function drop_rest_top_rules(): void {
		global $wp_rewrite;
		$saved = $wp_rewrite->extra_rules_top;
		foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $regex ) {
			if ( false !== strpos( $regex, 'wp-json' ) ) {
				unset( $wp_rewrite->extra_rules_top[ $regex ] );
			}
		}
		$this->cleanups[] = static function () use ( $saved ): void {
			$GLOBALS['wp_rewrite']->extra_rules_top = $saved;
		};
	}

	/**
	 * One row per routing state WordPress can be in: the permalink structure, the request, any
	 * code-set setup, the rest_route core parses (the literal), and whether that is the MCP route.
	 *
	 * @return array<string,array{0:string,1:string,2:string|null,3:mixed,4:mixed,5:string,6:string,7:mixed,8:bool}>
	 */
	public function wordpress_routing_provider(): array {
		$m = '/agent-abilities-for-mcp/mcp';
		$p = '/%postname%/';
		$i = '/index.php/%postname%/';
		$n = '';
		// structure, REQUEST_URI, PATH_INFO, GET rest_route, POST rest_route, parse_request() extra, setup, parsed rest_route, targets MCP.
		return array(
			'R01 pretty path'                          => array( $p, '/wp-json' . $m, null, null, null, '', '', $m, true ),
			'R02 pretty path, trailing slash'          => array( $p, '/wp-json' . $m . '/', null, null, null, '', '', $m, true ),
			'R03 pretty path, mixed case'              => array( $p, '/wp-json/Agent-Abilities-For-MCP/MCP', null, null, null, '', '', '/Agent-Abilities-For-MCP/MCP', true ),
			'R04 upper-case prefix'                    => array( $p, '/WP-JSON' . $m, null, null, null, '', '', null, false ),
			'R05 NUL in the prefix'                    => array( $p, '/wp-%00json' . $m, null, null, null, '', '', null, false ),
			'R06 encoded letter in the prefix'         => array( $p, '/wp-%6Ason' . $m, null, null, null, '', '', $m, true ),
			'R07 index.php path'                       => array( $p, '/index.php/wp-json' . $m, null, null, null, '', '', $m, true ),
			'R08 PATH_INFO names the route'            => array( $p, '/index.php/wp-json' . $m, '/wp-json' . $m, null, null, '', '', $m, true ),
			'R09 PATH_INFO names a page'               => array( $p, '/wp-json' . $m, '/sample-page', null, null, '', '', null, false ),
			'R10 index structure, index.php path'      => array( $i, '/index.php/wp-json' . $m, null, null, null, '', '', $m, true ),
			'R11 index structure, bare path'           => array( $i, '/wp-json' . $m, null, null, null, '', '', $m, true ),
			'R12 site under /blog'                     => array( $p, '/blog/wp-json' . $m, null, null, null, '', 'home_blog', $m, true ),
			'R13 prefix api, /api path'                => array( $p, '/api' . $m, null, null, null, '', 'prefix_api', $m, true ),
			'R14 prefix api, /wp-json path'            => array( $p, '/wp-json' . $m, null, null, null, '', 'prefix_api', null, false ),
			'R15 rest_url filtered to /proxy, proxy'   => array( $p, '/proxy/wp-json' . $m, null, null, null, '', 'rest_url_proxy', null, false ),
			'R16 rest_url filtered to /proxy, direct'  => array( $p, '/wp-json' . $m, null, null, null, '', 'rest_url_proxy', $m, true ),
			'R17 plain permalinks, pretty path'        => array( $n, '/wp-json' . $m, null, null, null, '', '', null, false ),
			'R18 plain structure, stored REST rules'   => array( $n, '/wp-json' . $m, null, null, null, '', 'stored_rest_rules', $m, true ),
			'R19 pretty structure, no REST rules'      => array( $p, '/wp-json' . $m, null, null, null, '', 'drop_rest_rules', null, false ),
			'R20 plain permalinks, rest_route'         => array( $n, '/index.php?rest_route=' . $m, null, $m, null, '', '', $m, true ),
			'R21 pretty permalinks, rest_route'        => array( $p, '/?rest_route=' . $m, null, $m, null, '', '', $m, true ),
			'R22 POST rest_route'                      => array( $p, '/', null, null, $m, '', '', $m, true ),
			'R23 GET and POST agree'                   => array( $p, '/?rest_route=' . $m, null, $m, $m, '', '', $m, true ),
			'R24 GET and POST differ'                  => array( $p, '/?rest_route=' . $m, null, $m, '/wp/v2/users/me', '', 'expect_die', null, false ),
			'R25 array rest_route'                     => array( $p, '/', null, array( 'x' ), null, '', '', array( 'x' ), false ),
			'R26 empty rest_route on the pretty path'  => array( $p, '/wp-json' . $m . '?rest_route=', null, '', null, '', '', '', false ),
			'R27 markup after the route'               => array( $p, '/', null, $m . '<b>', null, '', '', $m . '<b>', false ),
			'R28 literal percent sequence, GET'        => array( $p, '/', null, $m . '%41', null, '', '', $m . '%41', false ),
			'R29 literal percent sequence, POST'       => array( $p, '/', null, null, $m . '%41', '', '', $m . '%41', false ),
			'R30 trailing backslash'                   => array( $p, '/', null, $m . '\\', null, '', '', $m . '\\', true ),
			'R31 trailing newline'                     => array( $p, '/', null, $m . "\n", null, '', '', $m . "\n", true ),
			'R32 pretty path, rest_route elsewhere'    => array( $p, '/wp-json' . $m, null, '/wp/v2/users/me', null, '', '', '/wp/v2/users/me', false ),
			'R33 page path, rest_route MCP'            => array( $p, '/sample-page/', null, $m, null, '', '', $m, true ),
			'R34 rest_route, mixed case'               => array( $p, '/', null, '/Agent-Abilities-For-MCP/MCP', null, '', '', '/Agent-Abilities-For-MCP/MCP', true ),
			'R35 parse_request() extra vars elsewhere' => array( $p, '/', null, $m, null, 'rest_route=/wp/v2/users/me', '', '/wp/v2/users/me', false ),
			'R36 request filter elsewhere'             => array( $p, '/', null, $m, null, '', 'request_users_me', '/wp/v2/users/me', false ),
			'R37 query_vars filter drops rest_route'   => array( $p, '/', null, $m, null, '', 'query_vars_drop', null, false ),
			'R38 do_parse_request false'               => array( $p, '/', null, $m, null, '', 'do_parse_false', null, false ),
			'R39 request filter sets MCP'              => array( $p, '/sample-page/', null, null, null, '', 'request_mcp', $m, true ),
		);
	}

	/**
	 * The bearer resolves exactly when WordPress parsed the request to the MCP route, whatever the
	 * request looked like and whatever code shaped the parse.
	 *
	 * @dataProvider wordpress_routing_provider
	 *
	 * @param string      $structure Permalink structure.
	 * @param string      $uri       REQUEST_URI.
	 * @param string|null $path_info PATH_INFO, or null for none.
	 * @param mixed       $get       GET rest_route, or null for none.
	 * @param mixed       $post      POST rest_route, or null for none.
	 * @param string      $extra     parse_request() extra query vars.
	 * @param string      $setup     Named code-set setup.
	 * @param mixed       $parsed    The rest_route core parses.
	 * @param bool        $targets   Whether that is the MCP route.
	 */
	public function test_the_audience_follows_the_route_wordpress_parsed( string $structure, string $uri, ?string $path_info, $get, $post, string $extra, string $setup, $parsed, bool $targets ): void {
		global $wp_rewrite;
		$this->route_off_mcp();
		$this->set_permalink_structure( $structure );
		// A WP object built by go_to() lacks the rest_route var rest_api_register_rewrites() adds on
		// init, and parse_request() keeps whatever the query_vars filter returns, so pin and restore it.
		$public_vars = $GLOBALS['wp']->public_query_vars;
		$GLOBALS['wp']->add_query_var( 'rest_route' );
		$this->cleanups[] = static function () use ( $public_vars ): void {
			$GLOBALS['wp']->public_query_vars = $public_vars;
		};

		switch ( $setup ) {
			case 'home_blog':
				$home = get_option( 'home' );
				update_option( 'home', 'http://example.org/blog' );
				$this->cleanups[] = static function () use ( $home ): void {
					update_option( 'home', $home );
				};
				break;
			case 'prefix_api':
				$this->drop_rest_top_rules();
				add_filter(
					'rest_url_prefix',
					static function (): string {
						return 'api';
					}
				);
				rest_api_register_rewrites();
				$wp_rewrite->flush_rules();
				break;
			case 'rest_url_proxy':
				add_filter(
					'rest_url',
					static function ( $url ) {
						return str_replace( '/wp-json/', '/proxy/wp-json/', (string) $url );
					}
				);
				break;
			case 'stored_rest_rules':
				$rules = array();
				foreach ( $wp_rewrite->extra_rules_top as $regex => $query ) {
					if ( false !== strpos( $regex, 'wp-json' ) ) {
						$rules[ $regex ] = $query;
					}
				}
				update_option( 'permalink_structure', '' );
				$wp_rewrite->init();
				update_option( 'rewrite_rules', $rules );
				break;
			case 'drop_rest_rules':
				add_filter(
					'rewrite_rules_array',
					static function ( $rules ) {
						foreach ( array_keys( $rules ) as $regex ) {
							if ( false !== strpos( $regex, 'wp-json' ) ) {
								unset( $rules[ $regex ] );
							}
						}
						return $rules;
					}
				);
				$wp_rewrite->flush_rules();
				break;
			case 'request_users_me':
			case 'request_mcp':
				$route = 'request_mcp' === $setup ? aafm_mcp_rest_route() : '/wp/v2/users/me';
				add_filter(
					'request',
					static function ( $query_vars ) use ( $route ) {
						$query_vars['rest_route'] = $route;
						return $query_vars;
					}
				);
				break;
			case 'query_vars_drop':
				add_filter(
					'query_vars',
					static function ( $vars ) {
						return array_values( array_diff( $vars, array( 'rest_route' ) ) );
					}
				);
				break;
			case 'do_parse_false':
				add_filter( 'do_parse_request', '__return_false' );
				break;
		}

		$uid = $this->present_valid_bearer();

		$_SERVER['REQUEST_URI'] = $uri;
		$_SERVER['PHP_SELF']    = '/index.php';
		unset( $_SERVER['PATH_INFO'], $_GET['rest_route'], $_POST['rest_route'] );
		if ( null !== $path_info ) {
			$_SERVER['PATH_INFO'] = $path_info;
		}
		if ( null !== $get ) {
			$_GET['rest_route'] = $get;
		}
		if ( null !== $post ) {
			$_POST['rest_route'] = $post;
		}
		remove_action( 'parse_request', 'rest_api_loaded' );

		if ( 'expect_die' === $setup ) {
			try {
				$GLOBALS['wp']->parse_request( $extra );
				$this->fail( 'WordPress refuses a GET and POST rest_route that differ.' );
			} catch ( \WPDieException $e ) {
				unset( $e );
			}
		} else {
			$GLOBALS['wp']->parse_request( $extra );
		}

		$this->assertSame( $parsed, $GLOBALS['wp']->query_vars['rest_route'] ?? null, 'The rest_route WordPress parsed.' );
		$this->assertSame( $targets, aafm_oauth_request_targets_mcp_route() );
		$this->assertSame( $targets ? $uid : false, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * Core's half of an array rest_route: rest_api_loaded() refuses it before REST_REQUEST is defined.
	 */
	public function test_core_refuses_a_rest_route_that_is_not_a_string(): void {
		$GLOBALS['wp']->query_vars['rest_route'] = array( 'x' );
		try {
			rest_api_loaded();
			$this->fail( 'rest_api_loaded() must refuse a non-string rest_route.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'The REST route parameter must be a string.', $e->getMessage() );
		}
	}

	/**
	 * Before WordPress parses the request nothing is routed, so a bearer aimed at the MCP route by
	 * its raw request resolves nobody.
	 */
	public function test_nothing_resolves_before_wordpress_parses_the_request(): void {
		$this->route_off_mcp();
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		$_GET['rest_route']     = aafm_mcp_rest_route();
		$this->present_valid_bearer();

		$this->assertFalse( aafm_oauth_request_targets_mcp_route() );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh lookup, restored in tear_down().
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Entry points WordPress serves without parsing a request.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unparsed_entry_point_provider(): array {
		return array(
			'admin-ajax.php'       => array( '/wp-admin/admin-ajax.php' ),
			'admin-post.php'       => array( '/wp-admin/admin-post.php' ),
			'wp-comments-post.php' => array( '/wp-comments-post.php' ),
		);
	}

	/**
	 * A bearer with ?rest_route=<MCP route> on an entry point that never parses the request resolves
	 * nobody there.
	 *
	 * @dataProvider unparsed_entry_point_provider
	 *
	 * @param string $script The entry point's path.
	 */
	public function test_an_entry_point_that_never_parses_resolves_nobody( string $script ): void {
		$this->route_off_mcp();
		$_SERVER['PHP_SELF']    = $script;
		$_SERVER['SCRIPT_NAME'] = $script;
		$_SERVER['REQUEST_URI'] = $script . '?rest_route=' . aafm_mcp_rest_route();
		$_GET['rest_route']     = aafm_mcp_rest_route();
		if ( '/wp-admin/admin-ajax.php' === $script ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		} elseif ( '/wp-admin/admin-post.php' === $script ) {
			$screen = $GLOBALS['current_screen'] ?? null;
			set_current_screen( 'dashboard' );
			$this->cleanups[] = static function () use ( $screen ): void {
				$GLOBALS['current_screen'] = $screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored as found.
			};
		}
		$this->present_valid_bearer();

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh lookup, restored in tear_down().
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Put a JSON initialize call in the request for serve_request() to read.
	 *
	 * @return void
	 */
	private function initialize_body_in_request(): void {
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
						'name'    => 'validator-test',
						'version' => '1.0',
					),
				),
			)
		);
		$this->cleanups[]              = static function (): void {
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
		};
	}

	/**
	 * WP::init() looks the user up before the parse and caches "nobody"; core's own clear in
	 * serve_request() is what lets the bearer resolve, with this plugin's rest_api_init clear removed.
	 */
	public function test_cores_serve_request_clear_resolves_the_bearer_after_an_early_lookup(): void {
		$this->route_off_mcp();
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		$uid                    = $this->present_valid_bearer();

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the lookup WP::init() makes, restored in tear_down().
		wp_get_current_user();
		$this->assertSame( 0, get_current_user_id(), 'The lookup before the parse resolves nobody.' );

		remove_action( 'rest_api_init', 'aafm_oauth_forget_anonymous_user_on_mcp_route', PHP_INT_MIN );
		$this->route_as_rest_request();
		$server = $this->mcp_spy_server();
		$this->assertSame( 0, get_current_user_id(), 'Without the rest_api_init clear, registration still sees nobody.' );

		$this->initialize_body_in_request();
		$server->serve_request( aafm_mcp_rest_route() );

		$this->assertSame( $uid, get_current_user_id(), 'serve_request() forgets the cached nobody and the bearer resolves.' );
	}

	/**
	 * Register two abilities an editor splits on (one anyone can discover, one needing manage_options),
	 * enabled and in the registry, the harness HandshakeTest uses.
	 *
	 * @return array<int,string> Their names.
	 */
	private function register_discovery_fixtures(): array {
		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ): array {
				$registry['aafm/pub-read']    = array(
					'label'        => 'Pub Read',
					'description'  => 'Anyone may read.',
					'group'        => 'reads',
					'risk'         => 'read',
					'args_builder' => '__return_empty_array',
				);
				$registry['aafm/admin-write'] = array(
					'label'        => 'Admin Write',
					'description'  => 'Admin only.',
					'group'        => 'writes',
					'risk'         => 'write',
					'args_builder' => '__return_empty_array',
				);
				return $registry;
			}
		);
		aafm_flush_registry_cache();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				$fixtures = array(
					'aafm/pub-read'    => array( 'aafm-reads', '__return_true' ),
					'aafm/admin-write' => array(
						'aafm-writes',
						static function () {
							return current_user_can( 'manage_options' );
						},
					),
				);
				foreach ( $fixtures as $name => $fixture ) {
					if ( wp_has_ability( $name ) ) {
						continue;
					}
					aafm_register_ability_with_log(
						$name,
						array(
							'label'               => $name,
							'description'         => $name,
							'category'            => $fixture[0],
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array(),
							'permission_callback' => $fixture[1],
						)
					);
				}
			}
		);
		update_option( 'aafm_enabled_abilities', array( 'aafm/pub-read', 'aafm/admin-write' ) );
		return array( 'aafm/pub-read', 'aafm/admin-write' );
	}

	/**
	 * On an MCP request with our bearer, code on rest_api_init (the adapter's tool registry) sees the
	 * approver, not the "nobody" WP::init() cached, so it builds the approver's tool set.
	 */
	public function test_registration_on_an_mcp_request_sees_the_approver(): void {
		$names = $this->register_discovery_fixtures();
		$this->route_off_mcp();
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		$uid                    = $this->present_valid_bearer( 'editor' );

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the lookup WP::init() makes, restored in tear_down().
		wp_get_current_user();
		$this->assertSame( 0, get_current_user_id(), 'The lookup before the parse resolves nobody.' );

		$seen = array();
		add_action(
			'rest_api_init',
			static function () use ( &$seen, $names ): void {
				$seen['user']  = get_current_user_id();
				$seen['tools'] = aafm_build_server_tools( $names );
			},
			1
		);
		$this->route_as_rest_request();
		$server = $this->mcp_spy_server();

		$this->assertSame( $uid, $seen['user'] ?? null, 'rest_api_init sees the approver.' );
		wp_set_current_user( $uid );
		$this->assertSame( aafm_build_server_tools( $names ), $seen['tools'] ?? null, 'The tool set built on rest_api_init is the approver\'s.' );
		$this->assertSame( array( 'aafm/pub-read' ), $seen['tools'] ?? null );

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- serve_request() re-resolves, restored in tear_down().
		wp_get_current_user();
		$this->initialize_body_in_request();
		$server->serve_request( aafm_mcp_rest_route() );
		$this->assertSame( $uid, get_current_user_id(), 'The request is served as the approver.' );
	}

	/**
	 * An Application Password request carries no aafm_oat_ bearer, so rest_api_init leaves its cached
	 * lookup alone, as before.
	 */
	public function test_registration_without_our_bearer_is_left_alone(): void {
		$this->route_off_mcp();
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		$this->set_bearer( 'Basic ' . base64_encode( 'someone:abcd efgh ijkl mnop qrst uvwx' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a Basic credential header, not obfuscation.

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the lookup WP::init() makes, restored in tear_down().
		wp_get_current_user();
		$seen = array();
		add_action(
			'rest_api_init',
			static function () use ( &$seen ): void {
				$seen['user'] = get_current_user_id();
			},
			1
		);
		$this->route_as_rest_request();
		$this->mcp_spy_server();

		$this->assertSame( 0, $seen['user'] ?? null );
	}

	/**
	 * Record whether a cached user object survives this plugin's rest_api_init clear, from a
	 * rest_api_init callback that runs straight after it.
	 *
	 * @return array<string,mixed>
	 */
	private function watch_rest_api_init_user(): array {
		$seen = array();
		add_action(
			'rest_api_init',
			static function () use ( &$seen ): void {
				$seen['cached'] = $GLOBALS['current_user'] ?? null;
			},
			PHP_INT_MIN + 1
		);
		$this->mcp_spy_server();
		return $seen;
	}

	/**
	 * A bearer on a REST route other than MCP: the cached "nobody" is not forgotten on rest_api_init.
	 */
	public function test_the_rest_api_init_clear_leaves_other_rest_routes_alone(): void {
		$this->route_as_rest_request();
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/posts';
		$this->present_valid_bearer();
		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh lookup, restored in tear_down().
		$cached                  = wp_get_current_user();
		$this->assertSame( 0, $cached->ID );

		$this->assertSame( $cached, $this->watch_rest_api_init_user()['cached'] ?? null );
	}

	/**
	 * Where WordPress never parsed the request (a plugin calling rest_get_server() inside admin-ajax
	 * fires rest_api_init), a rest_route query var left over forgets nothing.
	 */
	public function test_the_rest_api_init_clear_needs_the_parse(): void {
		$this->route_off_mcp();
		$GLOBALS['wp']->query_vars['rest_route'] = aafm_mcp_rest_route();
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->present_valid_bearer();
		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh lookup, restored in tear_down().
		$cached                  = wp_get_current_user();
		$this->assertSame( 0, $cached->ID );

		$this->assertSame( $cached, $this->watch_rest_api_init_user()['cached'] ?? null );
	}

	/**
	 * A cookie-authenticated user is never forgotten, even with our bearer on the MCP route.
	 */
	public function test_the_rest_api_init_clear_never_forgets_a_real_user(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->present_valid_bearer();
		wp_set_current_user( $admin );
		$cached = wp_get_current_user();

		$this->assertSame( $cached, $this->watch_rest_api_init_user()['cached'] ?? null );
		$this->assertSame( $admin, get_current_user_id() );
	}

	/**
	 * The route check reads core's parse and nothing from the raw request, options or URLs.
	 */
	public function test_the_route_check_reads_only_cores_parse(): void {
		$fn     = new \ReflectionFunction( 'aafm_oauth_request_targets_mcp_route' );
		$lines  = file( (string) $fn->getFileName() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- reading the plugin's own source from disk in a test.
		$source = implode( '', array_slice( (array) $lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1 ) );

		foreach ( array( '$_GET', '$_POST', '$_REQUEST', '$_SERVER', 'get_option', 'rest_url', 'home_url', 'sanitize_text_field' ) as $read ) {
			$this->assertStringNotContainsString( $read, $source );
		}
	}

	/**
	 * Before $wp_rewrite exists on a plain-permalink site, the audience URL is core's plain REST URL.
	 */
	public function test_the_plain_permalink_audience_matches_rest_url_before_wp_rewrite_exists(): void {
		$this->set_permalink_structure( '' );
		$expected              = trailingslashit( home_url() ) . 'index.php?rest_route=/agent-abilities-for-mcp/mcp';
		$saved                 = $GLOBALS['wp_rewrite'];
		$GLOBALS['wp_rewrite'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the pre-$wp_rewrite window, restored below.
		try {
			$early = aafm_endpoint_url();
		} finally {
			$GLOBALS['wp_rewrite'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored.
		}

		$this->assertSame( $expected, $early );
		$this->assertSame( rest_url( 'agent-abilities-for-mcp/mcp' ), $early );
	}

	/**
	 * A discovery-document URL with ?rest_route=<MCP route>: WordPress serves the document at
	 * parse_request and never dispatches REST, so init callbacks and early parse_request callbacks
	 * see nobody.
	 */
	public function test_a_discovery_url_naming_the_mcp_route_resolves_nobody(): void {
		$this->route_off_mcp();
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';
		$_SERVER['PHP_SELF']    = '/index.php';
		$_GET['rest_route']     = aafm_mcp_rest_route();
		$this->present_valid_bearer();

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the lookup WP::init() makes, restored in tear_down().
		wp_get_current_user();

		$seen = array();
		add_action(
			'init',
			static function () use ( &$seen ): void {
				$seen['init'] = get_current_user_id();
			},
			PHP_INT_MAX
		);
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired to reach init callbacks.

		remove_action( 'parse_request', 'aafm_oauth_maybe_serve_well_known', 0 );
		remove_action( 'parse_request', 'rest_api_loaded' );
		add_action(
			'parse_request',
			static function () use ( &$seen ): void {
				$seen['parse_request'] = get_current_user_id();
			},
			0
		);
		$GLOBALS['wp']->parse_request();

		$this->assertSame( 0, $seen['init'] ?? null );
		$this->assertSame( 0, $seen['parse_request'] ?? null );
	}

	/**
	 * Capture the target of a call that ends in wp_redirect() + exit (AuthorizeTest's idiom).
	 *
	 * @param callable $callback The redirecting call.
	 * @return string The captured Location target.
	 */
	private function capture_redirect( callable $callback ): string {
		$captured = '';
		$catch    = static function ( $location ) use ( &$captured ) {
			$captured = (string) $location;
			throw new \RuntimeException( 'aafm_test_redirect' );
		};
		add_filter( 'wp_redirect', $catch, 1 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test harness only: demotes the CLI "headers already sent" warning so the redirect capture runs.
		set_error_handler(
			static function ( $errno, $errstr ) {
				return str_contains( $errstr, 'Cannot modify header information' );
			},
			E_WARNING
		);
		try {
			$callback();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			restore_error_handler();
			remove_filter( 'wp_redirect', $catch, 1 );
		}
		return $captured;
	}

	/**
	 * The consent URL with ?rest_route=<MCP route> and a bearer: the consent handler on init sees a
	 * logged-out visitor and sends them to wp-login, rendering no consent form.
	 */
	public function test_the_consent_screen_does_not_accept_an_mcp_bearer(): void {
		$this->route_off_mcp();
		$client = aafm_oauth_register_client( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) );
		$this->assertIsArray( $client );
		$_GET                   = array(
			'aafm_oauth'            => 'authorize',
			'rest_route'            => aafm_mcp_rest_route(),
			'response_type'         => 'code',
			'client_id'             => (string) $client['client_id'],
			'redirect_uri'          => 'https://app.example/cb',
			'code_challenge'        => str_repeat( 'a', 43 ),
			'code_challenge_method' => 'S256',
			'state'                 => 'st',
		);
		$_SERVER['REQUEST_URI'] = '/?' . http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the request fixture this test just built.
		$this->present_valid_bearer( 'administrator' );

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the lookup WP::init() makes, restored in tear_down().
		wp_get_current_user();
		$this->assertSame( 0, get_current_user_id(), 'On init the MCP bearer is not a login.' );

		ob_start();
		$location = $this->capture_redirect( 'aafm_oauth_handle_authorize' );
		$output   = (string) ob_get_clean();

		$this->assertStringStartsWith( wp_login_url(), $location );
		$this->assertStringNotContainsString( 'aafm_oauth_consent_nonce', $output );
	}

	/**
	 * A rest_route query var set without WordPress parsing the request is ignored.
	 */
	public function test_a_rest_route_set_without_a_parse_is_ignored(): void {
		$this->route_off_mcp();
		$GLOBALS['wp']->query_vars['rest_route'] = aafm_mcp_rest_route();
		$_GET['rest_route']                      = aafm_mcp_rest_route();
		$this->present_valid_bearer();

		$this->assertFalse( aafm_oauth_request_targets_mcp_route() );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * When do_parse_request skips the parse, a stale rest_route query var survives it, and no parse
	 * is counted, so it is ignored.
	 */
	public function test_a_stale_rest_route_after_a_skipped_parse_is_ignored(): void {
		$this->route_off_mcp();
		$GLOBALS['wp']->query_vars['rest_route'] = aafm_mcp_rest_route();
		$_GET['rest_route']                      = aafm_mcp_rest_route();
		add_filter( 'do_parse_request', '__return_false' );
		remove_action( 'parse_request', 'rest_api_loaded' );
		$this->present_valid_bearer();

		$this->assertFalse( $GLOBALS['wp']->parse_request() );
		$this->assertSame( aafm_mcp_rest_route(), $GLOBALS['wp']->query_vars['rest_route'] );
		$this->assertSame( 0, did_action( 'parse_request' ) );
		$this->assertFalse( aafm_oauth_request_targets_mcp_route() );
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}
}
