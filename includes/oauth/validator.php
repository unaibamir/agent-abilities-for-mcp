<?php
/**
 * OAuth bearer-token validation at the WordPress auth layer.
 *
 * Resolves a presented OAuth access token to its approving WordPress user on the
 * `determine_current_user` filter — the same layer Application Passwords resolve
 * on. Once a user is resolved here, the adapter's transport gate (which only
 * checks is_user_logged_in()) lets the request through under that identity.
 *
 * The resolver is deliberately narrow: it only ever acts on a bearer credential
 * that carries the `aafm_oat_` access-token prefix, and only when no earlier
 * filter has already resolved a user. Every other auth path — App Passwords,
 * cookies, foreign bearer schemes, no auth at all — is returned untouched. A
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
 * Resolve an OAuth bearer token to the WordPress user that approved it.
 *
 * Hooked on `determine_current_user`. Returns the incoming `$user_id` unchanged
 * in every case except a valid, audience-bound OAuth access token — see the
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

	// 2. Respect the operator's OAuth kill switch.
	if ( ! aafm_oauth_enabled() ) {
		return $user_id;
	}

	// 3. Read the bearer credential from the Authorization header. Some FastCGI
	// setups only expose it under REDIRECT_HTTP_AUTHORIZATION.
	$credential = aafm_oauth_read_bearer_token();
	if ( null === $credential ) {
		return $user_id;
	}

	// 4. Scope strictly to our own access tokens. A bearer that is not ours
	// (App Password, any other scheme) is left for its own resolver.
	if ( 0 !== strncmp( $credential, AAFM_OAUTH_ACCESS_TOKEN_PREFIX, strlen( AAFM_OAUTH_ACCESS_TOKEN_PREFIX ) ) ) {
		return $user_id;
	}

	// 5. Resolve the token to its row in a single indexed lookup. The row
	// resolver already gates on (active + unexpired), so a null row covers
	// every present-but-invalid case — unknown, inactive, expired — and a
	// present-but-invalid OAuth token simply fails to resolve a user rather
	// than hard-failing the request.
	$row = aafm_oauth_get_access_token_row( $credential );
	if ( null === $row ) {
		return $user_id;
	}

	// 6. Audience binding (RFC 8707): the token must have been minted for THIS
	// endpoint. A token scoped to a different audience resolves no user here.
	if ( ! hash_equals( aafm_endpoint_url(), (string) $row['resource'] ) ) {
		return $user_id;
	}

	return (int) $row['wp_user_id'];
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
 * Returns the same row that {@see aafm_oauth_validate_access_token()} matches —
 * active and unexpired — so the two functions always agree: a token that
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
			// Keep this WHERE clause in sync with aafm_oauth_validate_access_token() in tokens.php — the two must never disagree on the active/unexpired predicate.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant; all values are bound.
			"SELECT * FROM {$table}
			 WHERE token_hash = %s
			   AND is_active = 1
			   AND expires_at > %s",
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
 * routes. It returns the incoming value verbatim — null stays null, a WP_Error
 * comes back untouched.
 *
 * @param WP_Error|true|null $errors The current authentication error state.
 * @return WP_Error|true|null The same value, unchanged.
 */
function aafm_oauth_rest_authentication_errors( $errors ) {
	return $errors;
}
