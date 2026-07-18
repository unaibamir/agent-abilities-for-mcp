<?php
/**
 * OAuth bearer-token validation at the WordPress auth layer.
 *
 * Resolves a presented OAuth access token to its approving WordPress user on the
 * `determine_current_user` filter - the same layer Application Passwords resolve
 * on. Once a user is resolved here, the adapter's transport gate (which only
 * checks is_user_logged_in()) lets the request through under that identity.
 *
 * The resolver is deliberately narrow: it only ever acts on a bearer credential
 * that carries the `aafm_oat_` access-token prefix, and only when no earlier
 * filter has already resolved a user. Every other auth path - App Passwords,
 * cookies, foreign bearer schemes, no auth at all - is returned untouched. A
 * present-but-invalid OAuth token never hard-fails the request; it simply fails
 * to resolve a user, and the transport gate issues its own 401 downstream.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The literal prefix every minted OAuth access token carries.
 */
if ( ! defined( 'AAFM_OAUTH_ACCESS_TOKEN_PREFIX' ) ) {
	define( 'AAFM_OAUTH_ACCESS_TOKEN_PREFIX', 'aafm_oat_' );
}

/**
 * Remember (or read) the OAuth client_id a bearer token resolved for the current request.
 *
 * Read-only observability for M16: this store has no bearing on authentication or capability
 * decisions - aafm_oauth_resolve_current_user() writes to it only AFTER a token has already fully
 * resolved a user, purely so the activity-log wrapper in register.php can attribute the resulting
 * ability call to the OAuth client that made it. Mirrors the aafm_remember_raw_permission() static
 * store in register.php. Never populated for a non-OAuth (Application Password/cookie) request, so
 * it correctly stays '' for those.
 *
 * @param string|null $client_id Client id to record, or null to read the currently stored value.
 * @return string The stored client id, or '' when no OAuth bearer has resolved this request.
 */
function aafm_oauth_current_client_id( ?string $client_id = null ): string {
	static $stored = '';

	if ( null !== $client_id ) {
		$stored = $client_id;
	}

	return $stored;
}

/**
 * Resolve an OAuth bearer token to the WordPress user that approved it.
 *
 * Hooked on `determine_current_user`. Returns the incoming `$user_id` unchanged
 * in every case except a valid, audience-bound OAuth access token - see the
 * file header for why each guard exists.
 *
 * @param int|false $user_id The user id resolved so far (false if none yet). Left
 *                           untyped deliberately: WordPress passes int|false through
 *                           the determine_current_user filter, and a scalar type hint
 *                           would coerce that false to 0.
 * @return int|false The approving wp_user_id, or the incoming value unchanged.
 */
function aafm_oauth_resolve_current_user( $user_id ) {
	// 1. Never preempt a user some earlier filter already resolved (App Password,
	// cookie, another plugin). This early return is what makes the filter
	// priority non-load-bearing for the frozen invariant.
	if ( $user_id ) {
		return $user_id;
	}

	// 2. Read the bearer and scope to our own access tokens FIRST, before touching anything else.
	// A request that carries no `aafm_oat_` bearer - i.e. every ordinary page view - can never
	// resolve an OAuth user, so we return immediately. Doing this first keeps the filter a true
	// no-op for all non-OAuth traffic: it never builds a URL (rest_url()/home_url()) or reads an
	// option during user resolution. That matters because this runs on determine_current_user for
	// every logged-out request - resolving a URL here would fire the site-wide home_url/rest_url
	// filter chain during user resolution, and a third-party filter on it that reads the current
	// user would re-enter determine_current_user and recurse into a memory-exhaustion white-screen.
	$credential = aafm_oauth_read_bearer_token();
	if ( null === $credential
		|| 0 !== strncmp( $credential, AAFM_OAUTH_ACCESS_TOKEN_PREFIX, strlen( AAFM_OAUTH_ACCESS_TOKEN_PREFIX ) )
	) {
		return $user_id;
	}

	// 3. Re-entrancy guard. Everything below can build site URLs (aafm_oauth_request_targets_mcp_route()
	// at step 5, aafm_endpoint_url() at step 8), which fire the site-wide home_url/rest_url filter
	// chains DURING user resolution. WordPress's _wp_get_current_user() has no re-entrancy lock, so a
	// third-party filter on those URLs that calls a current-user function would re-enter this callback
	// and recurse until memory is exhausted (a white-screen). Once we are already resolving, a nested
	// call resolves no OAuth user. The bearer read above stays outside the guard so bearer-less
	// traffic is unaffected. This CANNOT deadlock a legitimate token: only a nested (re-entrant) call
	// sees the flag set; the outer call always resets it in the finally below.
	static $resolving = false;
	if ( $resolving ) {
		return $user_id;
	}
	$resolving = true;

	try {
		// 4. Respect the operator's OAuth kill switch.
		if ( ! aafm_oauth_enabled() ) {
			return $user_id;
		}

		// 5. Bail until the plugin is fully loaded. The MCP-route match (step 6) and the audience
		// binding (step 9) call functions defined only when aafm_bootstrap() runs on `plugins_loaded`
		// - aafm_mcp_rest_route() (includes/bootstrap.php) and aafm_endpoint_url() (the connection
		// module). This filter is registered at plugin-include time, so it can fire BEFORE our
		// bootstrap when another active plugin resolves the current user during `plugins_loaded` (e.g.
		// The Events Calendar calls wp_create_nonce() there). A fatal in a determine_current_user
		// callback white-screens the request, so we fail closed and resolve no OAuth user until the
		// helpers exist; the genuine MCP auth check runs later, during REST dispatch.
		if ( ! function_exists( 'aafm_mcp_rest_route' ) || ! function_exists( 'aafm_endpoint_url' ) ) {
			return $user_id;
		}

		// 6. Scope strictly to the MCP REST route. An aafm_oat_ token is a credential
		// for the MCP endpoint, not a site-wide WP REST bearer. Resolving it on any
		// other route would turn an MCP token into a general credential for every route
		// that trusts is_user_logged_in()/current_user_can(). Off the MCP route we leave
		// current_user untouched, exactly as Application Passwords are. determine_current_user
		// fires before REST routing, so the target is read from the request URI.
		if ( ! aafm_oauth_request_targets_mcp_route() ) {
			return $user_id;
		}

		// 7. Enforce the HTTPS policy. Where HTTPS is required, a bearer presented over
		// plain http never resolves a user (the other OAuth paths already refuse http).
		if ( aafm_oauth_https_required() && ! is_ssl() ) {
			return $user_id;
		}

		// 8. Resolve the token to its row in a single indexed lookup. The row
		// resolver already gates on (active + unexpired), so a null row covers
		// every present-but-invalid case - unknown, inactive, expired - and a
		// present-but-invalid OAuth token simply fails to resolve a user rather
		// than hard-failing the request. Audited as a denied `bearer` event: the
		// token carries our prefix, so this is a genuine failed authentication
		// attempt, not ordinary non-OAuth traffic. No client_id is known yet -
		// the lookup that would resolve one is exactly what just failed.
		$row = aafm_oauth_get_access_token_row( $credential );
		if ( null === $row ) {
			if ( function_exists( 'aafm_oauth_log_event' ) ) {
				aafm_oauth_log_event( 'bearer', 'denied' );
			}
			return $user_id;
		}

		// 9. Audience binding (RFC 8707): the token must have been minted for THIS
		// endpoint. A token scoped to a different audience resolves no user here.
		if ( ! hash_equals( aafm_endpoint_url(), (string) $row['resource'] ) ) {
			if ( function_exists( 'aafm_oauth_log_event' ) ) {
				aafm_oauth_log_event( 'bearer', 'denied', array( 'client_id' => (string) $row['client_id'] ) );
			}
			return $user_id;
		}

		// 10. Re-enforce client deactivation. is_active is checked at authorize-time, but a token
		// already in a client's hands keeps working unless its owning client is re-checked here -
		// so disabling a compromised client invalidates its live access tokens immediately.
		if ( aafm_oauth_client_is_deactivated( (string) $row['client_id'] ) ) {
			if ( function_exists( 'aafm_oauth_log_event' ) ) {
				aafm_oauth_log_event( 'bearer', 'denied', array( 'client_id' => (string) $row['client_id'] ) );
			}
			return $user_id;
		}

		// 11. Capability-narrowing seam. The token resolves to the approver's FULL account by
		// default (unchanged behaviour); this offers operators/future code a hook to cap what a
		// token may do based on the requested scope, without altering the resolved identity.
		aafm_oauth_apply_token_capability_scope(
			(int) $row['wp_user_id'],
			isset( $row['scope'] ) ? (string) $row['scope'] : '',
			(string) $row['client_id']
		);

		// 12. M16: record the resolved client_id purely for activity-log attribution. Read-only -
		// this happens only after the token has fully resolved a user through every guard above, so
		// it can never influence the auth decision itself, only observability of its outcome.
		aafm_oauth_current_client_id( (string) $row['client_id'] );

		return (int) $row['wp_user_id'];
	} finally {
		$resolving = false;
	}
}

/**
 * Optionally narrow the capabilities an OAuth token may exercise for this request.
 *
 * The identity a token resolves to is never changed here: the token always acts AS the
 * approving WordPress user. What this offers is a seam to cap what that identity may DO on
 * the current MCP request, keyed on the scope the grant was minted with.
 *
 * By default it does nothing - the `aafm_oauth_token_capabilities` filter returns null, so no
 * restriction is applied and existing "acts with the approver's full caps" behaviour is
 * preserved (non-breaking; live tokens are never silently reduced). A hook that returns a
 * capability => bool allow-map instead installs a request-scoped `user_has_cap` filter that
 * grants only the listed capabilities and denies the rest, so an operator (or future
 * scope-mapping code) can bind a token to least privilege. The map is applied only for the
 * remainder of THIS request, which only reaches here on the MCP route with a valid OAuth
 * bearer, so it can never leak into an unrelated context.
 *
 * @param int    $user_id   The resolved approver (unchanged; passed for hook context).
 * @param string $scope     The scope the token was minted with (may be '').
 * @param string $client_id The client the token belongs to.
 * @return void
 */
function aafm_oauth_apply_token_capability_scope( int $user_id, string $scope, string $client_id ): void {
	/**
	 * Filter the capabilities an OAuth-authenticated request may exercise.
	 *
	 * Return null (the default) to apply NO restriction - the token acts with the approving
	 * user's full capabilities, exactly as before. Return an array of capability => bool to cap the
	 * token to that allow-list for the current request; any capability not present in the map is
	 * denied.
	 *
	 * @param array<string,bool>|null $caps      Capability allow-map, or null for no restriction.
	 * @param string                  $scope     The scope the token was minted with (may be '').
	 * @param string                  $client_id The client the token belongs to.
	 * @param int                     $user_id   The approving WordPress user id.
	 */
	$caps = apply_filters( 'aafm_oauth_token_capabilities', null, $scope, $client_id, $user_id );

	if ( ! is_array( $caps ) ) {
		return; // Default (and any non-array return): acts as the approver, unchanged.
	}

	// Normalise to a capability => bool allow-map once, outside the per-check closure.
	$allow = array();
	foreach ( $caps as $cap => $granted ) {
		$allow[ (string) $cap ] = (bool) $granted;
	}

	add_filter(
		'user_has_cap',
		static function ( $allcaps ) use ( $allow ) {
			if ( ! is_array( $allcaps ) ) {
				return $allcaps;
			}
			// Deny everything the token was not explicitly granted, then apply the allow-map.
			foreach ( array_keys( $allcaps ) as $cap ) {
				$allcaps[ $cap ] = isset( $allow[ $cap ] ) ? $allow[ $cap ] : false;
			}
			foreach ( $allow as $cap => $granted ) {
				$allcaps[ $cap ] = $granted;
			}
			return $allcaps;
		},
		10,
		1
	);
}

/**
 * Whether the current request targets the MCP REST route.
 *
 * The determine_current_user filter runs before REST routing resolves $request->get_route(),
 * so the target is derived from the raw request: the URI path (pretty permalinks give
 * /wp-json/agent-abilities-for-mcp/mcp) and the rest_route query var (plain permalinks give
 * ?rest_route=/agent-abilities-for-mcp/mcp). The MCP rest path is taken from the registered
 * endpoint so it tracks any future rename.
 *
 * @return bool True only when the request is for the MCP endpoint.
 */
function aafm_oauth_request_targets_mcp_route(): bool {
	// Single-sourced in bootstrap.php (leading-slash form).
	$mcp_route = aafm_mcp_rest_route();

	// Plain-permalink form: ?rest_route=/agent-abilities-for-mcp/mcp. When the rest_route query var
	// is present it is AUTHORITATIVE and we must decide solely from it, never falling through to the
	// path check below. WordPress's WP::parse_request() gives the $_GET['rest_route'] value
	// precedence over the URL-path-derived route, so that is the route the request is actually
	// dispatched to. If we instead fell through and matched the URL path, a request whose path is the
	// MCP route but whose ?rest_route= points elsewhere (e.g. ?rest_route=/wp/v2/users/me) would be
	// misclassified as MCP-targeted while WordPress dispatches it to /wp/v2/users/me - turning an
	// audience-bound aafm_oat_ MCP token into a general credential for that unrelated REST route.
	if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state change.
		$rest_route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return rtrim( $rest_route, '/' ) === $mcp_route;
	}

	// Pretty-permalink form: compare the request path against the MCP endpoint's path. Derive the
	// expected path from rest_url() so a site installed under a path prefix (e.g.
	// https://example.com/blog) keeps that prefix (/blog/wp-json/...) in the comparison - a
	// hardcoded /wp-json/... literal never matches there.
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';
	$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( '' === $path ) {
		return false;
	}

	// rest_url() -> get_rest_url() dereferences the global $wp_rewrite. The determine_current_user
	// filter can fire before WordPress instantiates $wp_rewrite (e.g. Query Monitor calling
	// current_user_can() that early), so calling rest_url() then fatals on a null $wp_rewrite. Only
	// use rest_url() once $wp_rewrite exists; otherwise leave the path empty so the home_url() +
	// rest_get_url_prefix() reconstruction below (neither touches $wp_rewrite) produces the route.
	$rest_url_path = '';
	if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
		$rest_url_path = (string) wp_parse_url( rest_url( ltrim( $mcp_route, '/' ) ), PHP_URL_PATH );
	}

	// When pretty permalinks are off, rest_url() returns the plain ?rest_route= form, whose path
	// component collapses to .../index.php and carries no route - that case is the rest_route branch
	// above. Only treat the rest_url() path as the pretty target when it actually ends with the MCP
	// route. Otherwise reconstruct the expected pretty path from the install's home-path prefix so a
	// subdirectory install still matches even with plain permalinks pretty-routing through.
	if ( substr( rtrim( $rest_url_path, '/' ), -strlen( $mcp_route ) ) === $mcp_route ) {
		$mcp_rest_path = $rest_url_path;
	} else {
		$home_path     = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$segments      = array_filter(
			array( trim( $home_path, '/' ), trim( rest_get_url_prefix(), '/' ) ),
			static function ( string $segment ): bool {
				return '' !== $segment;
			}
		);
		$mcp_rest_path = '/' . implode( '/', $segments ) . $mcp_route;
	}

	return rtrim( $path, '/' ) === rtrim( $mcp_rest_path, '/' );
}

/**
 * Extract the bearer credential from the Authorization header, if present.
 *
 * Checks HTTP_AUTHORIZATION first, then the FastCGI-only
 * REDIRECT_HTTP_AUTHORIZATION fallback. The `Bearer ` scheme is matched
 * case-insensitively per RFC 6750.
 *
 * @return string|null The raw credential, or null when no bearer is present.
 */
function aafm_oauth_read_bearer_token(): ?string {
	$header = '';
	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$header = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) );
	} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$header = trim( sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) );
	}

	if ( '' === $header ) {
		return null;
	}

	// Case-insensitive "Bearer " scheme; the remainder is the credential.
	if ( 0 !== strncasecmp( $header, 'Bearer ', 7 ) ) {
		return null;
	}

	$credential = trim( substr( $header, 7 ) );

	return '' === $credential ? null : $credential;
}

/**
 * Fetch a full access-token row by the SHA-256 hash of the raw token.
 *
 * Returns the same row that {@see aafm_oauth_validate_access_token()} matches -
 * active and unexpired - so the two functions always agree: a token that
 * validates also returns a row here, and one that does not validate returns
 * null. The row carries at least `resource` (the audience) and `wp_user_id`.
 *
 * @param string $raw The raw access token presented by the client.
 * @return array<string,mixed>|null The row as ARRAY_A, or null when not found / inactive / expired.
 */
function aafm_oauth_get_access_token_row( string $raw ): ?array {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';
	$now   = gmdate( 'Y-m-d H:i:s', time() );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// Keep this WHERE clause in sync with aafm_oauth_validate_access_token() in tokens.php - the two must never disagree on the active/unexpired predicate.
			'SELECT * FROM %i
			 WHERE token_hash = %s
			   AND is_active = 1
			   AND expires_at > %s',
			$table,
			hash( 'sha256', $raw ),
			$now
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Defensive pass-through for the rest_authentication_errors filter.
 *
 * Registered so a present-but-invalid `aafm_oat_` token can never let some other
 * code path convert "we didn't resolve a user" into a hard failure on unrelated
 * routes. It returns the incoming value verbatim - null stays null, a WP_Error
 * comes back untouched.
 *
 * @param WP_Error|true|null $errors The current authentication error state.
 * @return WP_Error|true|null The same value, unchanged.
 */
function aafm_oauth_rest_authentication_errors( $errors ) {
	return $errors;
}
