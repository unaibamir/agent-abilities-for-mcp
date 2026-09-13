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
 * one and only copy handed to the caller - it is never stored or logged in clear.
 *
 * @param array<string,mixed> $ctx {
 *     Code binding context.
 *
 *     @type string $client_id      The public client identifier.
 *     @type int    $wp_user_id     The authenticated WordPress user.
 *     @type string $redirect_uri   The redirect URI this code is bound to.
 *     @type string $code_challenge The PKCE S256 challenge.
 *     @type string $resource       The resource indicator the code is scoped to.
 *     @type string $scope          The requested OAuth scope, persisted for audit/narrowing (may be '').
 * }
 * @return string|\WP_Error The raw authorization code (64 hex characters), or a WP_Error when the
 *                          row could not be persisted (so callers never hand out a phantom code).
 */
function aafm_oauth_mint_code( array $ctx ) {
	$raw  = bin2hex( random_bytes( 32 ) );
	$hash = hash( 'sha256', $raw );

	$now        = time();
	$expires_at = gmdate( 'Y-m-d H:i:s', $now + AAFM_OAUTH_CODE_TTL );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'aafm_oauth_codes',
		array(
			'code_hash'      => $hash,
			'client_id'      => isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '',
			'wp_user_id'     => isset( $ctx['wp_user_id'] ) ? (int) $ctx['wp_user_id'] : 0,
			'redirect_uri'   => isset( $ctx['redirect_uri'] ) ? (string) $ctx['redirect_uri'] : '',
			'code_challenge' => isset( $ctx['code_challenge'] ) ? (string) $ctx['code_challenge'] : '',
			'resource'       => isset( $ctx['resource'] ) ? (string) $ctx['resource'] : '',
			'scope'          => isset( $ctx['scope'] ) ? (string) $ctx['scope'] : '',
			'expires_at'     => $expires_at,
		),
		array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	// A failed insert means there is no persisted grant - never return a code for it, or the
	// client redirects with a code that can never be redeemed.
	if ( false === $inserted ) {
		return new \WP_Error( 'server_error', __( 'The authorization code could not be issued.', 'agent-abilities-for-mcp' ) );
	}

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
 * concurrent requests - a SELECT-then-UPDATE would race.
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
	$updated = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i
			 SET used_at = %s
			 WHERE code_hash = %s
			   AND used_at IS NULL
			   AND expires_at > %s
			   AND client_id = %s
			   AND redirect_uri = %s',
			$table,
			$used_at,
			$hash,
			$now,
			$client_id,
			$redirect_uri
		)
	);

	// R6-2: $wpdb->query()'s own return value used to be discarded outright, so a genuine query
	// failure (false) and a code that is simply invalid/expired/already-used (0 rows affected)
	// were indistinguishable through $wpdb->rows_affected alone - both reported as invalid_grant.
	// Only the latter is a real grant-validity answer.
	if ( false === $updated ) {
		return new WP_Error(
			'server_error',
			__( 'The authorization code could not be redeemed.', 'agent-abilities-for-mcp' )
		);
	}

	if ( 0 === (int) $wpdb->rows_affected ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The authorization code is invalid, expired, or already used.', 'agent-abilities-for-mcp' )
		);
	}

	$lookup = aafm_wpdb_row(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE code_hash = %s',
			$table,
			$hash
		)
	);

	// R6-2: the UPDATE above just stamped exactly one row by this exact hash, so a failed or
	// empty readback here is never a genuine grant-validity answer - it is this function's own
	// read that could not be trusted, not evidence the code itself is bad.
	if ( ! $lookup['ok'] || ! is_array( $lookup['value'] ) ) {
		return new WP_Error(
			'server_error',
			__( 'The authorization code could not be read back after redemption.', 'agent-abilities-for-mcp' )
		);
	}

	return $lookup['value'];
}

/**
 * Delete every authorization code issued to a client, redeemed or not.
 *
 * Used when an admin revokes the client. A code is valid for ~60s, so a pending (not-yet-redeemed)
 * code is deleted here as the first layer of defence. The token endpoint also re-checks consent at
 * redemption (aafm_oauth_has_consent() in rest.php), so a code minted in the narrow window between
 * the revoke and this delete cannot mint tokens either: it is refused as invalid_grant when it is
 * presented.
 *
 * @param string $client_id The public client identifier.
 * @return int Rows deleted, or -1 when the query itself failed and the count cannot be
 *              trusted - a caller must not read -1 as "nothing to delete".
 */
function aafm_oauth_revoke_client_codes( string $client_id ): int {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE client_id = %s',
			$table,
			$client_id
		)
	);
	$wpdb->suppress_errors( $suppressed );

	return false === $result ? -1 : (int) $result;
}

/**
 * Delete every authorization code issued to one user for one client.
 *
 * Used when an admin revokes a single grant: removes any pending code so it cannot mint tokens
 * after the consent and existing tokens are gone.
 *
 * @param int    $user_id   The WordPress user.
 * @param string $client_id The public client identifier.
 * @return int Rows deleted, or -1 when the query itself failed and the count cannot be
 *              trusted - a caller must not read -1 as "nothing to delete".
 */
function aafm_oauth_revoke_user_client_codes( int $user_id, string $client_id ): int {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE wp_user_id = %d AND client_id = %s',
			$table,
			$user_id,
			$client_id
		)
	);
	$wpdb->suppress_errors( $suppressed );

	return false === $result ? -1 : (int) $result;
}

/**
 * Whether a client still has any authorization-code row, redeemed or not.
 *
 * Used by the admin "Revoke client" handler to certify aafm_oauth_revoke_client_codes() actually
 * cleared the table, rather than trusting that delete's own affected-row count (Codex round 10,
 * R10-2): the revoke handlers called the delete and threw its result away entirely, so the codes
 * table was never certified at all - only the client and its tokens were.
 *
 * Codex round 11 R11-5 corrected an earlier version of this comment that overstated the risk: a
 * code left behind by a failed delete is NOT actually redeemable after the fact. The token
 * endpoint independently re-checks client deactivation before redemption
 * (aafm_oauth_rest_token_authorization_code(), includes/oauth/rest.php) and re-checks consent at
 * redemption for the revoked-grant case, so a leftover row cannot mint a token either way.
 * Clearing the row here is still worth certifying: defence in depth against a future redemption
 * path that might not repeat both re-checks, and data hygiene - a stale, unusable code should
 * not linger in the table pretending to be live.
 *
 * Same fail-closed bias as aafm_oauth_client_has_active_tokens(): this has exactly one caller
 * shape, a revoke handler certifying a full clear, so a read that could not run must count as
 * "still has a pending code," never as "confirmed clear."
 *
 * @param string $client_id The public client identifier.
 * @return bool True when the client is confirmed to have at least one code row, OR when the
 *              confirming read itself failed and cannot rule that out.
 */
function aafm_oauth_client_has_pending_codes( string $client_id ): bool {
	if ( '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_id = %s', $table, $client_id ) );

	return ! $count['ok'] || (int) $count['value'] > 0;
}

/**
 * Whether a single user still has any authorization-code row for one client, redeemed or not.
 *
 * Same purpose and fail-closed bias as aafm_oauth_client_has_pending_codes(), scoped to the admin
 * "Revoke grant" action.
 *
 * @param int    $user_id   The WordPress user id.
 * @param string $client_id The public client identifier.
 * @return bool True when the pair is confirmed to have at least one code row, OR when the
 *              confirming read itself failed and cannot rule that out.
 */
function aafm_oauth_user_client_has_pending_codes( int $user_id, string $client_id ): bool {
	if ( $user_id <= 0 || '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = aafm_wpdb_scalar(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE wp_user_id = %d AND client_id = %s',
			$table,
			$user_id,
			$client_id
		)
	);

	return ! $count['ok'] || (int) $count['value'] > 0;
}

/**
 * Delete every authorization code issued to one user, across every client.
 *
 * Used when a WordPress user is deleted (see aafm_oauth_cleanup_deleted_user() in
 * tokens.php). Same purpose as aafm_oauth_revoke_user_client_codes(), scoped to the
 * whole user rather than one client pair.
 *
 * Errors are suppressed around the query (restored immediately after) so a not-yet-installed
 * or otherwise unreadable table never prints a raw wpdb error block - the same discipline the
 * read-only helpers in this file already follow. $wpdb->query() returns false, not an int, on
 * a failed DELETE - casting that straight to (int) collapsed a real SQL failure into the same
 * 0 a genuine "nothing to delete" produces, which is exactly the R9-2 shape this codebase
 * otherwise guards against with a certifying re-read.
 *
 * @param int $user_id The WordPress user.
 * @return int Rows deleted, or -1 when the query itself failed and the count cannot be
 *              trusted - a caller must not read -1 as "nothing to delete".
 */
function aafm_oauth_revoke_user_codes( int $user_id ): int {
	if ( $user_id <= 0 ) {
		return 0;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE wp_user_id = %d',
			$table,
			$user_id
		)
	);
	$wpdb->suppress_errors( $suppressed );

	return false === $result ? -1 : (int) $result;
}

/**
 * Whether a single user still has any authorization-code row for any client, redeemed or not.
 *
 * Same fail-closed certification bias as aafm_oauth_user_client_has_pending_codes(), scoped to
 * the whole user rather than one client pair. Used to certify aafm_oauth_revoke_user_codes().
 *
 * @param int $user_id The WordPress user id.
 * @return bool True when the user is confirmed to have at least one code row, OR when the
 *              confirming read itself failed and cannot rule that out.
 */
function aafm_oauth_user_has_pending_codes( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_codes';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE wp_user_id = %d', $table, $user_id ) );

	return ! $count['ok'] || (int) $count['value'] > 0;
}
