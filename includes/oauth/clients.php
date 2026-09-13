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
 * @return array{client_id:string,client_name:string,redirect_uris:string[],grant_types:string[],response_types:string[]}|\WP_Error
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

	$client_name = isset( $req['client_name'] ) ? aafm_sanitize_plain_text( (string) $req['client_name'] ) : '';

	// Only authorization_code + refresh_token (and the `code` response type) are implemented, so
	// filter any client-supplied grant_types/response_types down to that supported set rather than
	// storing and echoing back arbitrary values. An empty result (the client asked for nothing we
	// support) falls back to the default set.
	$supported_grants    = array( 'authorization_code', 'refresh_token' );
	$supported_responses = array( 'code' );

	$grant_types = isset( $req['grant_types'] ) && is_array( $req['grant_types'] )
		? array_values( array_intersect( array_map( 'sanitize_text_field', $req['grant_types'] ), $supported_grants ) )
		: $supported_grants;
	if ( empty( $grant_types ) ) {
		$grant_types = $supported_grants;
	}

	$response_types = isset( $req['response_types'] ) && is_array( $req['response_types'] )
		? array_values( array_intersect( array_map( 'sanitize_text_field', $req['response_types'] ), $supported_responses ) )
		: $supported_responses;
	if ( empty( $response_types ) ) {
		$response_types = $supported_responses;
	}

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
		'client_id'      => $client_id,
		'client_name'    => $client_name,
		'redirect_uris'  => $redirect_uris,
		'grant_types'    => $grant_types,
		'response_types' => $response_types,
	);
}

/**
 * Fetch a single OAuth client row by its public client_id.
 *
 * @param string $client_id The public client identifier.
 * @return array{client_id:string,client_name:string,is_active:bool,is_agent_identity:bool}|null
 *               Null when no row exists.
 */
function aafm_oauth_get_client( string $client_id ): ?array {
	if ( '' === $client_id ) {
		return null;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT client_id, client_name, is_active, is_agent_identity FROM %i WHERE client_id = %s',
			$wpdb->prefix . 'aafm_oauth_clients',
			$client_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'client_id'         => (string) $row['client_id'],
		'client_name'       => (string) $row['client_name'],
		'is_active'         => 1 === (int) $row['is_active'],
		'is_agent_identity' => 1 === (int) $row['is_agent_identity'],
	);
}

/**
 * Flag (or unflag) an OAuth client as an agent-identity connection.
 *
 * Distinct from the existing user-level {@see aafm_agent_user_marker_meta_key()} marker: this is
 * an operator-settable flag on the OAuth client row itself, surfaced on the activity log via
 * {@see aafm_principal_is_agent_identity()}.
 *
 * @param string $client_id The public client identifier.
 * @param bool   $flag      True to flag the client as an agent identity, false to clear it.
 * @return bool True when the client row exists and now carries this flag value (whether or not
 *              the row's value actually changed). False only when no such client exists, or a
 *              real database error occurred.
 */
function aafm_oauth_set_client_agent_identity( string $client_id, bool $flag ): bool {
	if ( '' === $client_id ) {
		return false;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->update(
		$wpdb->prefix . 'aafm_oauth_clients',
		array( 'is_agent_identity' => $flag ? 1 : 0 ),
		array( 'client_id' => $client_id ),
		array( '%d' ),
		array( '%s' )
	);

	if ( false === $updated ) {
		return false; // A real database error.
	}
	if ( (int) $updated > 0 ) {
		return true; // The row existed and its value changed.
	}

	// $wpdb->update() also returns 0 when the row exists but already holds this exact value - not
	// a failure, and must not be reported as "client not found" to a caller retrying a toggle or
	// re-submitting a stale tab.
	$existing = aafm_oauth_get_client( $client_id );
	return is_array( $existing );
}

/**
 * Whether a principal (an acting user, an OAuth client, or both) is marked as an agent identity.
 *
 * Two independent sources, either of which is enough: the app-password-user marker
 * {@see aafm_agent_user_marker_meta_key()} this plugin already stamps on a user it created via
 * the dedicated-agent-user flow, and the OAuth client's own {@see aafm_oauth_set_client_agent_identity()}
 * flag. A user id of 0 or an empty/null client id is simply not checked on that side.
 *
 * A request-local static cache keys the client-row lookup by client_id: a caller such as
 * aafm/get-activity-log resolves this per row for up to 200 rows a page, and most of those rows
 * share a handful of client_ids, so this avoids one uncached query per row for the same client.
 * get_user_meta() needs no equivalent cache - core's own object-cache layer already dedupes it.
 *
 * @param int         $user_id         Acting WordPress user id, or 0 when unresolved.
 * @param string|null $oauth_client_id OAuth client id the call is attributed to, or null/'' for none.
 * @return bool
 */
function aafm_principal_is_agent_identity( int $user_id, ?string $oauth_client_id ): bool {
	if ( $user_id > 0 && get_user_meta( $user_id, aafm_agent_user_marker_meta_key(), true ) ) {
		return true;
	}

	if ( null !== $oauth_client_id && '' !== $oauth_client_id ) {
		static $client_cache = array();
		if ( ! array_key_exists( $oauth_client_id, $client_cache ) ) {
			$client_cache[ $oauth_client_id ] = aafm_oauth_get_client( $oauth_client_id );
		}
		$client = $client_cache[ $oauth_client_id ];
		if ( is_array( $client ) && ! empty( $client['is_agent_identity'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a client is anything other than a confirmed, currently-active registration.
 *
 * Used to re-enforce a client's standing AFTER authorize-time, at three live authorization
 * gates - code redemption (rest.php), refresh rotation (tokens.php), and bearer validation
 * (validator.php) - so disabling a compromised client stops its already-issued tokens, its
 * refresh rotation, and the redemption of a code minted before deactivation. A fourth caller,
 * aafm_oauth_validate_access_token() (tokens.php), is NOT a live gate: its own docblock states
 * it lacks RFC 8707 audience binding and exists for token-lifecycle/introspection use only, never
 * as a standalone authorization decision (Codex round 12, R12-3 - an earlier docblock here
 * claimed every caller was a live gate, which was true of three but not that fourth).
 *
 * Fails closed in every direction regardless of which of those four callers is asking, because
 * certification reads go through a direct aafm_wpdb_scalar() read instead - see
 * aafm_oauth_deactivate_client() and aafm_oauth_delete_consent() - never through this function:
 * an unreadable clients table denies (Codex round 10, R10-10), and so does a row that is
 * missing entirely rather than confirmed inactive (Codex round 11, R11-2) - a client whose
 * row was removed by a partial table clear, a manual repair, or the abandoned-client reaper
 * must not keep authenticating just because there is nothing left to read as "deactivated".
 * Only a row read back with is_active = 1 counts as active; anything else - no row, a
 * non-1 value, or a failed read - denies. The events this denial feeds (aafm_oauth_log_event's
 * 'bearer'/'refresh' 'denied' rows, and the generic invalid_grant responses at code redemption
 * and refresh) are already worded as a plain denial rather than a claim that the client was
 * deactivated, so failing closed here does not misreport a missing row or a database error as
 * a revocation.
 *
 * @param string $client_id The client identifier carried by a code/token row.
 * @return bool True unless a client row is read back and confirmed active (is_active = 1).
 */
function aafm_oauth_client_is_deactivated( string $client_id ): bool {
	if ( '' === $client_id ) {
		return true; // No client id to authorize against: deny, this is a live auth gate.
	}

	$view = aafm_oauth_client_active_row_view( $client_id );

	if ( ! $view['ok'] ) {
		return true; // Unreadable table: fail closed, this is a live auth gate.
	}

	if ( null === $view['value'] ) {
		return true; // No row: nothing to positively authorize against, fail closed.
	}

	return 1 !== (int) $view['value'];
}

/**
 * The raw is_active read aafm_oauth_client_is_deactivated() itself runs, factored out so
 * aafm_oauth_client_lookup_failed() below can ask the same question - did the READ succeed - as a
 * companion, independent probe, without duplicating the query or reaching into that function's
 * internals.
 *
 * @param string $client_id The client identifier to look up. Caller-validated non-empty.
 * @return array{ok:bool,value:mixed} Same shape as aafm_wpdb_scalar().
 */
function aafm_oauth_client_active_row_view( string $client_id ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return aafm_wpdb_scalar(
		$wpdb->prepare(
			'SELECT is_active FROM %i WHERE client_id = %s',
			$wpdb->prefix . 'aafm_oauth_clients',
			$client_id
		)
	);
}

/**
 * Whether aafm_oauth_client_is_deactivated()'s own read could not be completed - a query failure
 * or an unreadable table - as opposed to genuinely finding the client missing or deactivated.
 *
 * Codex round 6, R6-2: aafm_oauth_client_is_deactivated() correctly fails closed (denies) either
 * way, and this does not change that - a caller must still deny the request when this is true.
 * What it fixes is the client-facing REASON: two live call sites (aafm_oauth_rotate_refresh() and
 * the authorization_code token grant) used to tell the client its grant or its client was invalid
 * even when the true cause was this read failing, not a genuine authorization decision. A caller
 * that needs to report the fault honestly - as a server_error, not invalid_grant - checks this
 * FIRST, only after aafm_oauth_client_is_deactivated() has already returned true; it must never be
 * used as a substitute for that function's own fail-closed denial.
 *
 * @param string $client_id The client identifier to look up.
 * @return bool True when the underlying read itself failed. An empty client id is NOT a read
 *              failure - it is a genuine missing-client case, matching
 *              aafm_oauth_client_is_deactivated()'s own empty-string branch.
 */
function aafm_oauth_client_lookup_failed( string $client_id ): bool {
	if ( '' === $client_id ) {
		return false;
	}
	return ! aafm_oauth_client_active_row_view( $client_id )['ok'];
}

/**
 * Count active registered OAuth clients, with a failure signal a live abuse-control
 * decision can act on.
 *
 * Backs the DCR soft cap (aafm_oauth_rest_register()): the count itself is not enough there,
 * because a query failure and a genuine count of 0 both leave `count` at 0, and casting a
 * failed read to 0 read an unreadable cap as spare capacity and let the public registration
 * route grow the clients table without bound during an outage (Codex round 11, R11-4, the
 * DCR sibling of R10-1's certification-read class). Counts only is_active = 1 rows (a revoked
 * client no longer counts against the cap).
 *
 * Codex round 12 R12-2: an earlier version of this docblock claimed a not-yet-installed table
 * reads the same as a real empty table - `ok` true, `count` 0. It does not, and never did: the
 * query below goes through aafm_wpdb_scalar(), which returns `ok` false on ANY failed query,
 * a missing table included, exactly like any other unreadable table. So the DCR soft cap
 * (aafm_oauth_rest_register()) correctly answers `temporarily_unavailable` (HTTP 503) before
 * the clients table has ever been created, the same fail-closed response as any other read
 * failure - it does not read a not-yet-installed table as "0 clients, plenty of room". Only
 * {@see aafm_oauth_count_active_clients()} below, the display-only wrapper, folds that failure
 * into a bare 0 - and it is explicitly not safe for a live gate for exactly that reason.
 *
 * @return array{ok:bool,count:int} `ok` false only on a genuine query failure; `count` is the
 *              confirmed active-client count when `ok` is true, and always 0 when it is not -
 *              a caller enforcing a cap must check `ok`, not just `count`.
 */
function aafm_oauth_count_active_clients_view(): array {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_clients';

	$suppressed = $wpdb->suppress_errors();
	$view       = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_active = 1', $table ) );
	$wpdb->suppress_errors( $suppressed );

	return array(
		'ok'    => $view['ok'],
		'count' => $view['ok'] ? max( 0, (int) $view['value'] ) : 0,
	);
}

/**
 * Count active registered OAuth clients.
 *
 * Display-only convenience wrapper around {@see aafm_oauth_count_active_clients_view()}:
 * tolerates any read failure, including a not-yet-installed table, by reporting 0. Not safe
 * for a live abuse-control decision - a caller enforcing a cap must use
 * aafm_oauth_count_active_clients_view() instead, so a failed read denies rather than reading
 * as "no active clients, plenty of room" (Codex round 11, R11-4).
 *
 * @return int Non-negative count of active clients, or 0 when the count could not be read.
 */
function aafm_oauth_count_active_clients(): int {
	return aafm_oauth_count_active_clients_view()['count'];
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
	// the raw string we persist still carries the control chars - a header-splitting /
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
 * @return array<int,array{client_id:string,client_name:string,redirect_uris:string[],created_at:string,is_active:bool,active_tokens:int,is_agent_identity:bool}>
 */
function aafm_oauth_list_clients(): array {
	global $wpdb;
	$clients_table = $wpdb->prefix . 'aafm_oauth_clients';
	$tokens_table  = $wpdb->prefix . 'aafm_oauth_access_tokens';
	$now           = gmdate( 'Y-m-d H:i:s', time() );

	// Read-only listing for the admin table: tolerate a not-yet-installed table
	// (a brand-new install before activation finishes) by returning an empty list
	// instead of surfacing a DB error.
	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT client_id, client_name, redirect_uris, created_at, is_active, is_agent_identity FROM %i ORDER BY created_at DESC, id DESC', $clients_table ), ARRAY_A );
	$wpdb->suppress_errors( $suppressed );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	// One grouped pass over the tokens table builds a client_id => active-token-count map, so
	// the listing never runs a COUNT per client (an N+1 that scanned the token table once per row).
	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT client_id, COUNT(*) AS active_tokens FROM %i WHERE is_active = 1 AND ( expires_at IS NULL OR expires_at > %s ) GROUP BY client_id',
			$tokens_table,
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
			'client_id'         => (string) $row['client_id'],
			'client_name'       => (string) $row['client_name'],
			'redirect_uris'     => $uris,
			'created_at'        => (string) $row['created_at'],
			'is_active'         => 1 === (int) $row['is_active'],
			'active_tokens'     => $counts[ (string) $row['client_id'] ] ?? 0,
			'is_agent_identity' => 1 === (int) $row['is_agent_identity'],
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
 * Also reads each user's CURRENT role and privilege level, not a value stored at
 * consent time - the token table keeps no such snapshot, and none is added here. A
 * token always acts with whatever capabilities the identity holds right now, so a
 * live read is the only honest thing to show: the 1.7.4 security assessment (S4)
 * noted that an operator has no visibility into what a grant currently means if the
 * connected identity's role changed after they approved it. is_high_privilege reuses
 * aafm_oauth_user_is_high_privilege() (authorize.php), the same check that drives the
 * consent screen's own administrator warning, so "high privilege" means the same
 * thing in both places.
 *
 * @return array<int,array{user_id:int,user_display:string,user_login:string,client_id:string,client_name:string,granted_at:string,user_roles:list<string>,is_high_privilege:bool}>
 */
function aafm_oauth_list_grants(): array {
	global $wpdb;
	$consents_table = $wpdb->prefix . 'aafm_oauth_consents';
	$clients_table  = $wpdb->prefix . 'aafm_oauth_clients';

	// Read-only listing for the admin table: tolerate a not-yet-installed table
	// by returning an empty list instead of surfacing a DB error. Both table names are
	// bound as %i identifiers; the LEFT JOIN keeps a consent whose client row was removed.
	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT c.wp_user_id, c.client_id, c.granted_at, cl.client_name
			 FROM %i c
			 LEFT JOIN %i cl ON cl.client_id = c.client_id
			 ORDER BY c.granted_at DESC, c.id DESC',
			$consents_table,
			$clients_table
		),
		ARRAY_A
	);
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
			'user_id'           => $user_id,
			'user_display'      => (string) $user->display_name,
			'user_login'        => (string) $user->user_login,
			'client_id'         => (string) $row['client_id'],
			'client_name'       => (string) $row['client_name'],
			'granted_at'        => (string) $row['granted_at'],
			'user_roles'        => array_values( array_map( 'strval', $user->roles ) ),
			'is_high_privilege' => function_exists( 'aafm_oauth_user_is_high_privilege' ) && aafm_oauth_user_is_high_privilege( $user ),
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
 * @return bool True when the client is confirmed deactivated after this call.
 */
function aafm_oauth_deactivate_client( string $client_id ): bool {
	if ( '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_clients';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET is_active = 0 WHERE client_id = %s AND is_active = 1',
			$table,
			$client_id
		)
	);

	// Certify against a fresh read rather than trusting the UPDATE's own affected-row count: a
	// real SQL failure and "already inactive, nothing to update" both leave that count at zero,
	// so casting it straight to a bool collapsed the two (Codex round 9, R9-2) and let a failed
	// revoke still report the client as deactivated.
	//
	// A direct aafm_wpdb_scalar() read here, not aafm_oauth_client_is_deactivated() (Codex round
	// 10, R10-2): that helper is also the live token-validation guard (tokens.php, validator.php),
	// where the safe direction on a read failure is to ASSUME deactivated and reject the token.
	// Certification needs the opposite bias - a read failure here must NOT count as "confirmed
	// deactivated," or a client whose deactivating UPDATE and confirming SELECT both fail the
	// same way (an unreadable database) still reports a clean revoke.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$view = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT is_active FROM %i WHERE client_id = %s', $table, $client_id ) );

	return $view['ok'] && null !== $view['value'] && 0 === (int) $view['value'];
}

/**
 * Delete a single user's consent (grant) for one client.
 *
 * After this, the user must re-approve the client to reconnect. Revoking the
 * matching tokens is the caller's separate step (aafm_oauth_revoke_user_client_tokens()).
 *
 * @param int    $user_id   The WordPress user id whose grant is removed.
 * @param string $client_id The client the grant is for.
 * @return bool True when the consent is confirmed gone after this call.
 */
function aafm_oauth_delete_consent( int $user_id, string $client_id ): bool {
	if ( $user_id <= 0 || '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_consents';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete(
		$table,
		array(
			'wp_user_id' => $user_id,
			'client_id'  => $client_id,
		),
		array( '%d', '%s' )
	);

	// Certify against a fresh read (see aafm_oauth_deactivate_client()) rather than trusting
	// $wpdb->delete()'s own affected-row count, for the same reason: a real SQL failure and "no
	// matching row" both leave that count at zero (Codex round 9, R9-2).
	//
	// A direct aafm_wpdb_scalar() read here, not aafm_oauth_has_consent() (Codex round 10, R10-2):
	// that helper also gates whether the authorize screen skips asking for consent again, where the
	// safe direction on a read failure is to ASSUME no consent and show the screen. Certification
	// needs the opposite bias - a read failure must not count as "confirmed gone," or a delete whose
	// DELETE and confirming SELECT both fail the same way still reports the grant revoked while the
	// row, and the bearer tokens it backs, survive.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$view = aafm_wpdb_scalar(
		$wpdb->prepare(
			'SELECT id FROM %i WHERE wp_user_id = %d AND client_id = %s',
			$table,
			$user_id,
			$client_id
		)
	);

	return $view['ok'] && null === $view['value'];
}

/**
 * Delete every consent (grant) a single user holds, across every client.
 *
 * Used when a WordPress user is deleted (see aafm_oauth_cleanup_deleted_user() in
 * tokens.php): the user is gone, so every client they ever approved becomes an
 * orphaned grant, not just one. Unlike aafm_oauth_delete_consent(), this is not
 * scoped to a single client_id - it clears the user's whole consent history in
 * one query, on the same certify-against-a-fresh-read discipline as the
 * single-client version.
 *
 * Errors are suppressed around both the delete and the certifying read (restored immediately
 * after), the same discipline the read-only listings above already follow - a not-yet-installed
 * or otherwise unreadable consents table must not print a raw wpdb error block, which is what
 * happens uncorrected: this runs unconditionally on 'deleted_user', including in the PHPUnit
 * fixtures where the OAuth tables are never installed.
 *
 * @param int $user_id The WordPress user id whose consents are removed.
 * @return bool True when the user is confirmed to hold no consent rows after this call.
 */
function aafm_oauth_delete_all_user_consents( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_consents';

	$suppressed = $wpdb->suppress_errors();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $table, array( 'wp_user_id' => $user_id ), array( '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$view = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT id FROM %i WHERE wp_user_id = %d', $table, $user_id ) );

	$wpdb->suppress_errors( $suppressed );

	return $view['ok'] && null === $view['value'];
}
