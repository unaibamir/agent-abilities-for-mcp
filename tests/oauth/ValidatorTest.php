<?php
/**
 * Tests for the OAuth bearer-token validator: the determine_current_user
 * resolver, the access-token row resolver, and the rest_authentication_errors
 * pass-through.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

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
	 * The WP test suite rewrites plugin CREATE TABLE to its TEMPORARY form, so the
	 * token table must be installed per test before any mint/validate runs.
	 */
	public function set_up(): void {
		parent::set_up();

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
		// environment, so HTTPS is genuinely required here.
		$this->on_mcp_route();
		$_SERVER['HTTPS'] = 'on';

		aafm_install_oauth_tables();

		// OAuth is OFF by default now; the resolver's happy path requires it on. The
		// disabled-bearer test sets it back to '0' explicitly.
		update_option( 'aafm_oauth_enabled', '1' );

		// The failed-bearer audit tests below read the activity log.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Restore the Authorization / request keys to exactly their pre-test state.
	 */
	public function tear_down(): void {
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
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/wp/v2/posts';
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
			$_SERVER['REQUEST_URI'] = '/blog/' . $rest_prefix . '/wp/v2/posts';
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
	 * L3: an unknown/inactive/expired bearer (the token row lookup itself fails) writes a
	 * denied `oauth:bearer` audit row, so a stolen or guessed credential leaves a trace even
	 * though it never resolves an owning client.
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
	 * The rest_authentication_errors hook is a pure pass-through: it never turns a
	 * non-error into an error, and never mutates an existing WP_Error.
	 */
	public function test_rest_authentication_errors_passthrough(): void {
		$this->assertNull( aafm_oauth_rest_authentication_errors( null ) );

		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, aafm_oauth_rest_authentication_errors( $error ) );
	}

	/**
	 * The route guard runs on determine_current_user, which can fire before WordPress
	 * instantiates the global $wp_rewrite (Query Monitor calls current_user_can() that
	 * early). rest_url() -> get_rest_url() dereferences $wp_rewrite and would fatal on
	 * null. With $wp_rewrite nulled, the guard must still (a) not fatal and (b) classify
	 * the MCP route as true and a non-MCP route as false, falling back to the
	 * home_url() + rest_get_url_prefix() reconstruction (neither touches $wp_rewrite).
	 */
	public function test_route_guard_survives_null_wp_rewrite(): void {
		$saved_rewrite = $GLOBALS['wp_rewrite'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulate the early-bootstrap state where $wp_rewrite is not yet set.
		$GLOBALS['wp_rewrite'] = null;

		try {
			// Pretty-permalink MCP request path, reconstructed without $wp_rewrite.
			$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/agent-abilities-for-mcp/mcp';
			unset( $_GET['rest_route'] );
			$this->assertTrue(
				aafm_oauth_request_targets_mcp_route(),
				'The MCP route must be recognised even when $wp_rewrite is null.'
			);

			// A non-MCP REST route must not match.
			$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/wp/v2/posts';
			$this->assertFalse(
				aafm_oauth_request_targets_mcp_route(),
				'A non-MCP route must not match when $wp_rewrite is null.'
			);
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the exact prior global so the null state never bleeds into another test.
			$GLOBALS['wp_rewrite'] = $saved_rewrite;
		}
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
	 * The rest_route query var is AUTHORITATIVE over the request path. WordPress dispatches on
	 * $_GET['rest_route'] when present, so a request whose path IS the MCP route but whose
	 * rest_route points at an unrelated core route (e.g. /wp/v2/users/me) must NOT be classified as
	 * MCP-targeted - otherwise an audience-bound aafm_oat_ token would resolve a user for a route
	 * WordPress actually dispatches elsewhere, turning it into a general credential.
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

		// set_up() already pointed REQUEST_URI at the MCP path. Overlay a non-MCP rest_route.
		$_GET['rest_route'] = '/wp/v2/users/me';
		$this->assertFalse(
			aafm_oauth_request_targets_mcp_route(),
			'A non-MCP rest_route must win over an MCP request path.'
		);
		$this->assertFalse(
			aafm_oauth_resolve_current_user( false ),
			'A valid bearer must not resolve when rest_route dispatches to a non-MCP route.'
		);

		// Positive control: rest_route pointing at the MCP route resolves normally.
		$_GET['rest_route'] = '/agent-abilities-for-mcp/mcp';
		$this->assertTrue( aafm_oauth_request_targets_mcp_route() );
		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
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
}
