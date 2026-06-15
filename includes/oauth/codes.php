<?php
/**
 * OAuth authorization codes.
 *
 * Mints single-use authorization codes (60-second TTL) and redeems them exactly
 * once. Only the SHA-256 hash of each code is persisted; the raw code is returned
 * to the caller once and never stored in clear. Redemption is an atomic UPDATE
 * that marks the row used, so replay is safe under concurrency.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The lifetime of an authorization code, in seconds.
 */
if ( ! defined( 'AAFM_OAUTH_CODE_TTL' ) ) {
	define( 'AAFM_OAUTH_CODE_TTL', 60 );
}

/**
 * Mint an authorization code and store only its hash.
 *
 * Generates a 64-character hex code (32 random bytes), persists the SHA-256 hash
 * along with the binding context, and returns the raw code. The raw value is the
 * one and only copy handed to the caller — it is never stored or logged in clear.
 *
 * @param array<string,mixed> $ctx {
 *     Code binding context.
 *
 *     @type string $client_id      The public client identifier.
 *     @type int    $wp_user_id     The authenticated WordPress user.
 *     @type string $redirect_uri   The redirect URI this code is bound to.
 *     @type string $code_challenge The PKCE S256 challenge.
 *     @type string $resource       The resource indicator the code is scoped to.
 * }
 * @return string The raw authorization code (64 hex characters).
 */
function aafm_oauth_mint_code( array $ctx ): string {
	$raw  = bin2hex( random_bytes( 32 ) );
	$hash = hash( 'sha256', $raw );

	$now        = time();
	$expires_at = gmdate( 'Y-m-d H:i:s', $now + AAFM_OAUTH_CODE_TTL );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->insert(
		$wpdb->prefix . 'aafm_oauth_codes',
		array(
			'code_hash'      => $hash,
			'client_id'      => isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '',
			'wp_user_id'     => isset( $ctx['wp_user_id'] ) ? (int) $ctx['wp_user_id'] : 0,
			'redirect_uri'   => isset( $ctx['redirect_uri'] ) ? (string) $ctx['redirect_uri'] : '',
			'code_challenge' => isset( $ctx['code_challenge'] ) ? (string) $ctx['code_challenge'] : '',
			'resource'       => isset( $ctx['resource'] ) ? (string) $ctx['resource'] : '',
			'expires_at'     => $expires_at,
		),
		array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
	);

	return $raw;
}

/**
 * Redeem an authorization code, exactly once.
 *
 * Atomic one-time use: a single UPDATE stamps used_at only when the row is
 * unredeemed, unexpired, and matches the presented client and redirect URI. When
 * that UPDATE affects no rows (already used, expired, or a client/redirect
 * mismatch) redemption fails. On a successful UPDATE the row is read back and
 * returned. The UPDATE-then-check ordering is what makes replay safe under
 * concurrent requests — a SELECT-then-UPDATE would race.
 *
 * @param string $raw          The raw authorization code presented by the client.
 * @param string $client_id    The client_id presented at the token endpoint.
 * @param string $redirect_uri The redirect_uri presented at the token endpoint.
 * @return array<string,mixed>|\WP_Error The redeemed row, or WP_Error on failure.
 */
function aafm_oauth_redeem_code( string $raw, string $client_id, string $redirect_uri ) {
	$hash    = hash( 'sha256', $raw );
	$now     = gmdate( 'Y-m-d H:i:s', time() );
	$used_at = $now;

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant; all values are bound.
			"UPDATE {$table}
			 SET used_at = %s
			 WHERE code_hash = %s
			   AND used_at IS NULL
			   AND expires_at > %s
			   AND client_id = %s
			   AND redirect_uri = %s",
			$used_at,
			$hash,
			$now,
			$client_id,
			$redirect_uri
		)
	);

	if ( 0 === (int) $wpdb->rows_affected ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The authorization code is invalid, expired, or already used.', 'agent-abilities-for-mcp' )
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT * FROM {$table} WHERE code_hash = %s",
			$hash
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The authorization code could not be read back after redemption.', 'agent-abilities-for-mcp' )
		);
	}

	return $row;
}
