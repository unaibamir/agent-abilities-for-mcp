<?php
/**
 * OAuth dynamic client registry.
 *
 * Registers public OAuth clients (token_endpoint_auth_method "none", no secret) and
 * validates their redirect URIs against the strict allowlist the spec requires.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register a public OAuth client.
 *
 * Validates every redirect URI, generates a client_id (a 32-character hex string,
 * 16 random bytes), and persists the client row. This is a public-client model:
 * there is no client secret to store.
 *
 * @param array<string,mixed> $req {
 *     Registration request.
 *
 *     @type string[] $redirect_uris              Required. 1-10 absolute redirect URIs.
 *     @type string   $client_name                Optional display name.
 *     @type string[] $grant_types                Optional. Defaults to authorization_code + refresh_token.
 *     @type string[] $response_types             Optional. Defaults to code.
 * }
 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|\WP_Error
 */
function aafm_oauth_register_client( array $req ) {
	$redirect_uris = isset( $req['redirect_uris'] ) && is_array( $req['redirect_uris'] )
		? array_values( $req['redirect_uris'] )
		: array();

	if ( empty( $redirect_uris ) ) {
		return new WP_Error(
			'invalid_redirect_uri',
			__( 'At least one redirect URI is required.', 'agent-abilities-for-mcp' )
		);
	}

	if ( count( $redirect_uris ) > 10 ) {
		return new WP_Error(
			'invalid_redirect_uri',
			__( 'A client may register at most 10 redirect URIs.', 'agent-abilities-for-mcp' )
		);
	}

	foreach ( $redirect_uris as $uri ) {
		if ( ! is_string( $uri ) || ! aafm_oauth_validate_redirect_uri( $uri ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				__( 'A redirect URI is not allowed.', 'agent-abilities-for-mcp' )
			);
		}
	}

	$client_name    = isset( $req['client_name'] ) ? sanitize_text_field( (string) $req['client_name'] ) : '';
	$grant_types    = isset( $req['grant_types'] ) && is_array( $req['grant_types'] )
		? array_values( array_map( 'sanitize_text_field', $req['grant_types'] ) )
		: array( 'authorization_code', 'refresh_token' );
	$response_types = isset( $req['response_types'] ) && is_array( $req['response_types'] )
		? array_values( array_map( 'sanitize_text_field', $req['response_types'] ) )
		: array( 'code' );

	$client_id = bin2hex( random_bytes( 16 ) );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'aafm_oauth_clients',
		array(
			'client_id'      => $client_id,
			'client_name'    => $client_name,
			'redirect_uris'  => wp_json_encode( $redirect_uris ),
			'grant_types'    => wp_json_encode( $grant_types ),
			'response_types' => wp_json_encode( $response_types ),
			'created_by_ip'  => aafm_source_ip(),
			'is_active'      => 1,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
	);

	if ( false === $inserted ) {
		return new WP_Error(
			'registration_failed',
			__( 'Could not store the client registration.', 'agent-abilities-for-mcp' )
		);
	}

	return array(
		'client_id'     => $client_id,
		'client_name'   => $client_name,
		'redirect_uris' => $redirect_uris,
	);
}

/**
 * Whether a client row exists for this id but has been deactivated (is_active = 0).
 *
 * Used to re-enforce a client deactivation AFTER authorize-time, at code redemption, refresh
 * rotation, and bearer validation — so disabling a compromised client stops its already-issued
 * tokens, its refresh rotation, and the redemption of a code minted before deactivation. Returns
 * false when no row exists at all, so synthetic client ids (never registered) are not blocked —
 * only a known-and-disabled client is.
 *
 * @param string $client_id The client identifier carried by a code/token row.
 * @return bool True only when a client row exists AND is inactive.
 */
function aafm_oauth_client_is_deactivated( string $client_id ): bool {
	if ( '' === $client_id ) {
		return false;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_active = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT is_active FROM {$wpdb->prefix}aafm_oauth_clients WHERE client_id = %s",
			$client_id
		)
	);

	// null => no row (synthetic / never-registered id): not deactivated. A row with is_active 0
	// is the only "deactivated" case.
	return null !== $is_active && 0 === (int) $is_active;
}

/**
 * Count active registered OAuth clients.
 *
 * Used by the DCR endpoint to enforce a soft cap so the public registration route cannot grow
 * the clients table without bound. Counts only is_active = 1 rows (a revoked client no longer
 * counts against the cap). Tolerates a not-yet-installed table by returning 0.
 *
 * @return int Non-negative count of active clients.
 */
function aafm_oauth_count_active_clients(): int {
	global $wpdb;
	$table = esc_sql( $wpdb->prefix . 'aafm_oauth_clients' );

	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" );
	$wpdb->suppress_errors( $suppressed );

	return max( 0, (int) $count );
}

/**
 * Validate a single redirect URI against the registration allowlist.
 *
 * Enforces: non-empty and at most 2048 bytes; no wildcard anywhere; no fragment and
 * no userinfo component; a present host; and a scheme of exactly https, or http only
 * when the host is a loopback address (localhost, 127.0.0.1, ::1). The scheme is
 * matched against an explicit allowlist so odd-parsing values such as
 * "javascript:alert(1)" cannot slip through.
 *
 * @param string $uri Candidate redirect URI.
 * @return bool True when the URI is allowed.
 */
function aafm_oauth_validate_redirect_uri( string $uri ): bool {
	if ( '' === $uri || strlen( $uri ) > 2048 ) {
		return false;
	}

	// Reject C0 control characters and DEL (CR, LF, TAB, NUL, …) anywhere in the URI.
	// wp_parse_url() strips these before parsing, so the host would validate clean while
	// the raw string we persist still carries the control chars — a header-splitting /
	// open-redirect seed. Validate the exact bytes we store.
	if ( preg_match( '/[\x00-\x1F\x7F]/', $uri ) ) {
		return false;
	}

	// Wildcards are never permitted in a registered redirect URI.
	if ( false !== strpos( $uri, '*' ) ) {
		return false;
	}

	$parts = wp_parse_url( $uri );
	if ( ! is_array( $parts ) ) {
		return false;
	}

	// No fragment and no userinfo (user:pass@) components.
	if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

	if ( '' === $host ) {
		return false;
	}

	if ( 'https' === $scheme ) {
		return true;
	}

	// Plain http is allowed only for loopback hosts (native-app / local-dev clients).
	if ( 'http' === $scheme ) {
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	return false;
}

/**
 * List every registered OAuth client for the admin management table.
 *
 * Each row carries the stored client fields plus a live count of its active,
 * unexpired access tokens (a correlated COUNT against the access-tokens table).
 * redirect_uris is decoded from its stored JSON to a string array; a malformed or
 * non-array value decodes to an empty array so the caller never has to guard it.
 * Ordered newest first. Read-only, prepared queries against the plugin's own tables.
 *
 * @return array<int,array{client_id:string,client_name:string,redirect_uris:string[],created_at:string,is_active:bool,active_tokens:int}>
 */
function aafm_oauth_list_clients(): array {
	global $wpdb;
	$clients_table = esc_sql( $wpdb->prefix . 'aafm_oauth_clients' );
	$tokens_table  = $wpdb->prefix . 'aafm_oauth_access_tokens';
	$now           = gmdate( 'Y-m-d H:i:s', time() );

	// Read-only listing for the admin table: tolerate a not-yet-installed table
	// (a brand-new install before activation finishes) by returning an empty list
	// instead of surfacing a DB error.
	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( "SELECT client_id, client_name, redirect_uris, created_at, is_active FROM {$clients_table} ORDER BY created_at DESC, id DESC", ARRAY_A );
	$wpdb->suppress_errors( $suppressed );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	// One grouped pass over the tokens table builds a client_id => active-token-count map, so
	// the listing never runs a COUNT per client (an N+1 that scanned the token table once per row).
	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count_rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant; the value is bound.
			"SELECT client_id, COUNT(*) AS active_tokens FROM {$tokens_table} WHERE is_active = 1 AND ( expires_at IS NULL OR expires_at > %s ) GROUP BY client_id",
			$now
		),
		ARRAY_A
	);
	$wpdb->suppress_errors( $suppressed );

	$counts = array();
	if ( is_array( $count_rows ) ) {
		foreach ( $count_rows as $count_row ) {
			$counts[ (string) $count_row['client_id'] ] = (int) $count_row['active_tokens'];
		}
	}

	$out = array();
	foreach ( $rows as $row ) {
		$decoded = json_decode( (string) $row['redirect_uris'], true );
		$uris    = is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : array();

		$out[] = array(
			'client_id'     => (string) $row['client_id'],
			'client_name'   => (string) $row['client_name'],
			'redirect_uris' => $uris,
			'created_at'    => (string) $row['created_at'],
			'is_active'     => 1 === (int) $row['is_active'],
			'active_tokens' => $counts[ (string) $row['client_id'] ] ?? 0,
		);
	}

	return $out;
}

/**
 * List every active OAuth grant (consent) for the admin management table.
 *
 * Joins the consents table to the clients table for the client display name and
 * resolves each consent's WordPress user for its display name and login. A consent
 * whose user no longer exists is skipped (there is nothing meaningful to show or
 * revoke for a deleted account). Ordered newest first. Read-only, prepared query.
 *
 * @return array<int,array{user_id:int,user_display:string,user_login:string,client_id:string,client_name:string,granted_at:string}>
 */
function aafm_oauth_list_grants(): array {
	global $wpdb;
	// Internal constant table names; esc_sql() makes the safety explicit for analyzers.
	$consents_table = esc_sql( $wpdb->prefix . 'aafm_oauth_consents' );
	$clients_table  = esc_sql( $wpdb->prefix . 'aafm_oauth_clients' );

	// Read-only listing for the admin table: tolerate a not-yet-installed table
	// by returning an empty list instead of surfacing a DB error. Table names are
	// internal constants; the LEFT JOIN keeps a consent whose client row was removed.
	$suppressed = $wpdb->suppress_errors();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		"SELECT c.wp_user_id, c.client_id, c.granted_at, cl.client_name
		 FROM {$consents_table} c
		 LEFT JOIN {$clients_table} cl ON cl.client_id = c.client_id
		 ORDER BY c.granted_at DESC, c.id DESC",
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->suppress_errors( $suppressed );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$out = array();
	foreach ( $rows as $row ) {
		$user_id = (int) $row['wp_user_id'];
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			continue; // The account is gone; nothing to display or revoke.
		}

		$out[] = array(
			'user_id'      => $user_id,
			'user_display' => (string) $user->display_name,
			'user_login'   => (string) $user->user_login,
			'client_id'    => (string) $row['client_id'],
			'client_name'  => (string) $row['client_name'],
			'granted_at'   => (string) $row['granted_at'],
		);
	}

	return $out;
}

/**
 * Deactivate an OAuth client by its public client_id (sets is_active = 0).
 *
 * Locks the client out immediately: deactivation is re-enforced at code redemption,
 * refresh rotation, and bearer validation. Revoking the client's live tokens is the
 * caller's separate step (aafm_oauth_revoke_client_tokens()).
 *
 * @param string $client_id The public client identifier.
 * @return bool True when a client row was updated.
 */
function aafm_oauth_deactivate_client( string $client_id ): bool {
	if ( '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_clients';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"UPDATE {$table} SET is_active = 0 WHERE client_id = %s AND is_active = 1",
			$client_id
		)
	);

	return (int) $updated > 0;
}

/**
 * Delete a single user's consent (grant) for one client.
 *
 * After this, the user must re-approve the client to reconnect. Revoking the
 * matching tokens is the caller's separate step (aafm_oauth_revoke_user_client_tokens()).
 *
 * @param int    $user_id   The WordPress user id whose grant is removed.
 * @param string $client_id The client the grant is for.
 * @return bool True when a consent row was deleted.
 */
function aafm_oauth_delete_consent( int $user_id, string $client_id ): bool {
	if ( $user_id <= 0 || '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_consents';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->delete(
		$table,
		array(
			'wp_user_id' => $user_id,
			'client_id'  => $client_id,
		),
		array( '%d', '%s' )
	);

	return (int) $deleted > 0;
}
