<?php
/**
 * OAuth access and refresh tokens.
 *
 * Mints access/refresh token pairs and stores only their SHA-256 hashes - the
 * raw values are returned once and never persisted in clear. Refresh tokens
 * rotate on every use: redeeming a refresh token deactivates it and issues a
 * fresh pair whose refresh_parent_id links back to the consumed row.
 *
 * Replaying a consumed (inactive) refresh token triggers reuse detection: the
 * entire lineage - every row reachable up the parent links and down the child
 * links - is revoked, so a stolen-then-replayed token kills the live session.
 *
 * Every secret is matched by a DB lookup on an indexed SHA-256 hex column
 * (WHERE token_hash = %s), never by an in-PHP comparison of raw values.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Default access-token lifetime, in seconds. The live value is the
 * aafm_oauth_access_ttl option; this is only its fallback.
 */
if ( ! defined( 'AAFM_OAUTH_ACCESS_TTL' ) ) {
	define( 'AAFM_OAUTH_ACCESS_TTL', 3600 );
}

/**
 * Default refresh-token lifetime, in seconds (30 days). The live value is the
 * aafm_oauth_refresh_ttl option; this is only its fallback.
 */
if ( ! defined( 'AAFM_OAUTH_REFRESH_TTL' ) ) {
	define( 'AAFM_OAUTH_REFRESH_TTL', 2592000 );
}

/**
 * Hard cap on chain-revocation traversal hops, guarding against any pathological
 * loop in the parent/child links. It also bounds the maximum lineage length that
 * a single reuse-detection pass will revoke, not just the anti-infinite-loop guard.
 */
if ( ! defined( 'AAFM_OAUTH_CHAIN_MAX_HOPS' ) ) {
	define( 'AAFM_OAUTH_CHAIN_MAX_HOPS', 1000 );
}

/**
 * Mint an access/refresh token pair and store only their hashes.
 *
 * Access token is prefixed `aafm_oat_`; the refresh token has no prefix. Both
 * raw values are returned to the caller once and never stored in clear - only
 * their SHA-256 hashes are persisted, alongside the binding context.
 *
 * @param array<string,mixed> $ctx {
 *     Token binding context.
 *
 *     @type string $client_id         The public client identifier.
 *     @type int    $wp_user_id        The authenticated WordPress user.
 *     @type string $resource          The resource indicator the token is scoped to.
 *     @type string $scope             The requested OAuth scope, persisted for audit/narrowing (may be '').
 *     @type int    $refresh_parent_id The id of the refresh row this pair rotated from (0 for a fresh mint).
 * }
 * @return array{access_token:string,refresh_token:string,expires_in:int}|\WP_Error The token pair,
 *         or a WP_Error when the row could not be persisted (so callers never hand out phantom tokens).
 */
function aafm_oauth_mint_tokens( array $ctx ) {
	// keep in sync with aafm_oauth_resolve_current_user()'s prefix (AAFM_OAUTH_ACCESS_TOKEN_PREFIX in validator.php, which loads after this file).
	$access_raw  = 'aafm_oat_' . bin2hex( random_bytes( 32 ) );
	$refresh_raw = bin2hex( random_bytes( 32 ) );

	$access_ttl  = (int) get_option( 'aafm_oauth_access_ttl', AAFM_OAUTH_ACCESS_TTL );
	$refresh_ttl = (int) get_option( 'aafm_oauth_refresh_ttl', AAFM_OAUTH_REFRESH_TTL );

	$now = time();

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'aafm_oauth_access_tokens',
		array(
			'token_hash'         => hash( 'sha256', $access_raw ),
			'refresh_hash'       => hash( 'sha256', $refresh_raw ),
			'refresh_parent_id'  => isset( $ctx['refresh_parent_id'] ) ? (int) $ctx['refresh_parent_id'] : 0,
			'client_id'          => isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '',
			'wp_user_id'         => isset( $ctx['wp_user_id'] ) ? (int) $ctx['wp_user_id'] : 0,
			'resource'           => isset( $ctx['resource'] ) ? (string) $ctx['resource'] : '',
			'scope'              => isset( $ctx['scope'] ) ? (string) $ctx['scope'] : '',
			'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + $access_ttl ),
			'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + $refresh_ttl ),
			'is_active'          => 1,
		),
		array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
	);

	// A failed insert means there is no persisted token row - never return a token pair for it,
	// or the client gets a successful token response it can never use.
	if ( false === $inserted ) {
		return new WP_Error( 'server_error', __( 'The access token could not be issued.', 'agent-abilities-for-mcp' ) );
	}

	return array(
		'access_token'  => $access_raw,
		'refresh_token' => $refresh_raw,
		'expires_in'    => $access_ttl,
	);
}

/**
 * Validate an access token and return the user it belongs to.
 *
 * Delegates the token lookup to aafm_oauth_get_access_token_row() so the active/unexpired predicate
 * lives in exactly one place, then re-enforces client deactivation - matching the live request path
 * in validator.php. A token validates only when it is active, unexpired, AND its owning client has
 * not been deactivated; so disabling a compromised client invalidates its live access tokens here
 * too, not just on the REST path.
 *
 * WARNING: this does NOT perform the RFC 8707 audience binding that the live request path enforces
 * (aafm_oauth_resolve_current_user compares the token's resource against aafm_endpoint_url() before
 * resolving a user). It answers only "is this token active, unexpired, and its client still enabled",
 * so it MUST NEVER be used as a standalone authorization decision on the request path - a token
 * minted for a different audience would validate here. Use aafm_oauth_resolve_current_user() for any
 * real auth decision. This helper exists for token-lifecycle/introspection use (and tests) only.
 *
 * @param string $raw The raw access token presented by the client.
 * @return int|false The wp_user_id on success, or false when expired, inactive, deactivated, or unknown.
 */
function aafm_oauth_validate_access_token( string $raw ) {
	$row = aafm_oauth_get_access_token_row( $raw );
	if ( null === $row ) {
		return false;
	}

	// Re-enforce client deactivation: a token already in a client's hands keeps working unless its
	// owning client is re-checked, so a deactivated client's live tokens must stop validating.
	if ( aafm_oauth_client_is_deactivated( (string) ( $row['client_id'] ?? '' ) ) ) {
		return false;
	}

	return (int) $row['wp_user_id'];
}

/**
 * Redeem a refresh token, rotating it for a fresh access/refresh pair.
 *
 * On a valid, active refresh token whose refresh_expires_at is in the future and
 * whose client_id matches: the old row is marked inactive and a new pair is
 * minted carrying the same wp_user_id/resource, with the new row's
 * refresh_parent_id chained to the old row's id.
 *
 * On replay of an already-consumed (inactive) refresh token, reuse detection
 * fires: the whole lineage is revoked (see aafm_oauth_revoke_chain()) and a
 * WP_Error is returned. Expired, unknown, or wrong-client tokens also return a
 * WP_Error without touching other rows.
 *
 * @param string $raw       The raw refresh token presented at the token endpoint.
 * @param string $client_id The client_id presented at the token endpoint.
 * @return array{access_token:string,refresh_token:string,expires_in:int}|\WP_Error
 */
function aafm_oauth_rotate_refresh( string $raw, string $client_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE refresh_hash = %s',
			$table,
			hash( 'sha256', $raw )
		),
		ARRAY_A
	);

	// Unknown refresh token: nothing to rotate, nothing to revoke.
	if ( ! is_array( $row ) ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The refresh token is invalid.', 'agent-abilities-for-mcp' )
		);
	}

	// Reuse detection: a known refresh token that is already inactive means it
	// was consumed by an earlier rotation and is now being replayed. Treat the
	// replay as a compromise signal and revoke the entire lineage.
	if ( 0 === (int) $row['is_active'] ) {
		aafm_oauth_revoke_chain( (int) $row['id'] );

		return new WP_Error(
			'invalid_grant',
			__( 'The refresh token has already been used; the token chain has been revoked.', 'agent-abilities-for-mcp' )
		);
	}

	// Wrong client for an otherwise-valid token: reject without rotating.
	if ( (string) $row['client_id'] !== $client_id ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The refresh token was issued to a different client.', 'agent-abilities-for-mcp' )
		);
	}

	// Deactivated client: refuse rotation so disabling a compromised client stops it from
	// rolling its tokens forward. is_active is otherwise only checked at authorize-time.
	if ( aafm_oauth_client_is_deactivated( $client_id ) ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The client is no longer active.', 'agent-abilities-for-mcp' )
		);
	}

	// Expired refresh token: reject without rotating. The PHP string compare is
	// safe because 'Y-m-d H:i:s' is a fixed-width, zero-padded, lexicographically
	// ordered datetime format.
	if ( gmdate( 'Y-m-d H:i:s', time() ) >= (string) $row['refresh_expires_at'] ) {
		return new WP_Error(
			'invalid_grant',
			__( 'The refresh token has expired.', 'agent-abilities-for-mcp' )
		);
	}

	// Consume the old refresh row and mint the successor as one atomic unit.
	//
	// Wrap both in a transaction so a crash between consume and mint can't leave
	// the row consumed without a persisted successor (which would lock the user
	// out). This relies on the access-tokens table being InnoDB: get_charset_collate()
	// sets only charset/collation, so the engine is pinned separately - the CREATE
	// declares ENGINE=InnoDB and aafm_oauth_enforce_lifecycle_engine() (schema.php)
	// converts a pre-existing MyISAM table and warns if it cannot. On a non-transactional
	// engine START/ROLLBACK is a no-op and the atomicity below would be lost.
	// KNOWN BOUND, and the sentence that used to sit here claimed the opposite. It said the WP
	// test harness wraps each test in its own transaction so this nested START/COMMIT is
	// "effectively a no-op there". It is not. MySQL and MariaDB have no nested transactions:
	// issuing START TRANSACTION while one is open IMPLICITLY COMMITS the open one
	// (dev.mysql.com/doc/refman/8.4/en/commit.html, "Statements That Cause an Implicit Commit").
	// So under the harness this commits the harness's wrapper, and in production it would commit
	// any transaction another component happens to be holding on the shared $wpdb connection,
	// leaving that component's later ROLLBACK with nothing to undo.
	//
	// Not fixed here on purpose. A correct fix needs to know whether a transaction is already
	// open, and neither MySQL nor $wpdb exposes that portably, so it would mean this plugin
	// growing its own transaction-nesting manager on top of a platform that deliberately has
	// none. That is the reimplementing-platform-mechanics habit the delegation audit exists to
	// stop, and the trigger needs another component to hold an open transaction across a REST
	// dispatch. Stating the bound is the honest half; building the manager is not this
	// release's change to make.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'START TRANSACTION' );

	// Single-winner gate: deactivate the row only while it is still active. Under
	// a concurrent race two presentations of the same refresh token both reach
	// here, but exactly one UPDATE flips is_active 1 -> 0 and affects a row; the
	// loser affects none. $wpdb->update() returns the affected-row count (or false
	// on error). Anything other than exactly one consumed row means we did not win
	// the race (or the query failed) - roll back and reject without minting.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$consumed = $wpdb->update(
		$table,
		array( 'is_active' => 0 ),
		array(
			'id'        => (int) $row['id'],
			'is_active' => 1,
		),
		array( '%d' ),
		array( '%d', '%d' )
	);

	if ( 1 !== $consumed ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );

		return new WP_Error(
			'invalid_grant',
			__( 'The refresh token is invalid.', 'agent-abilities-for-mcp' )
		);
	}

	$new = aafm_oauth_mint_tokens(
		array(
			'client_id'         => (string) $row['client_id'],
			'wp_user_id'        => (int) $row['wp_user_id'],
			'resource'          => (string) $row['resource'],
			// Carry the original grant's scope forward so a rotated token never silently widens.
			'scope'             => isset( $row['scope'] ) ? (string) $row['scope'] : '',
			'refresh_parent_id' => (int) $row['id'],
		)
	);

	// If the new pair did not persist, roll back the rotation so the old refresh row stays
	// usable rather than committing a consumed parent with no child.
	if ( is_wp_error( $new ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
		return $new;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'COMMIT' );

	return $new;
}

/**
 * Revoke an access or refresh token (RFC 7009 style).
 *
 * Accepts either an access token (prefixed `aafm_oat_`) or a refresh token (no
 * prefix). The value is hashed and matched against token_hash OR refresh_hash;
 * the matching row is marked inactive.
 *
 * @param string $raw The raw token presented for revocation.
 * @return bool True when a row was found and revoked, false otherwise.
 */
function aafm_oauth_revoke_token( string $raw ): bool {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';
	$hash  = hash( 'sha256', $raw );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i
			 SET is_active = 0
			 WHERE ( token_hash = %s OR refresh_hash = %s )
			   AND is_active = 1',
			$table,
			$hash,
			$hash
		)
	);

	return (int) $wpdb->rows_affected > 0;
}

/**
 * Revoke every active token issued to a client (admin "Revoke client" action).
 *
 * Deactivates all of the client's still-active access/refresh rows in one prepared
 * UPDATE, so a deactivated client's already-issued sessions stop validating at once.
 *
 * @param string $client_id The public client identifier.
 * @return int Number of token rows deactivated.
 */
function aafm_oauth_revoke_client_tokens( string $client_id ): int {
	if ( '' === $client_id ) {
		return 0;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET is_active = 0 WHERE client_id = %s AND is_active = 1',
			$table,
			$client_id
		)
	);

	return (int) $wpdb->rows_affected;
}

/**
 * Revoke every active token a single user holds for one client (admin "Revoke grant").
 *
 * Scoped to that user+client pair, so other users' sessions with the same client and
 * the user's sessions with other clients are untouched.
 *
 * @param int    $user_id   The WordPress user id whose tokens are revoked.
 * @param string $client_id The client the tokens belong to.
 * @return int Number of token rows deactivated.
 */
function aafm_oauth_revoke_user_client_tokens( int $user_id, string $client_id ): int {
	if ( $user_id <= 0 || '' === $client_id ) {
		return 0;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET is_active = 0 WHERE wp_user_id = %d AND client_id = %s AND is_active = 1',
			$table,
			$user_id,
			$client_id
		)
	);

	return (int) $wpdb->rows_affected;
}

/**
 * Whether a client still has any active token row, regardless of expiry.
 *
 * Used by the admin "Revoke client" handler to certify a full revocation against the tokens
 * table directly, rather than trusting aafm_oauth_revoke_client_tokens()'s own affected-row
 * count: a real SQL failure and "nothing left to revoke" both leave that count at zero (Codex
 * round 9, R9-2), so the count alone cannot tell the handler whether the client is actually
 * clear. Deliberately ignores expires_at - a still-flagged-active row is what a caller
 * elsewhere would treat as live, so it is what this check treats as live too.
 *
 * The count read goes through aafm_wpdb_scalar() rather than a bare get_var() (Codex round 10,
 * R10-2): a get_var() read that itself failed used to cast straight to `(int) null > 0 === false`
 * - a database this function cannot read reported the exact same "no active tokens" answer as a
 * database it genuinely found none in. This function has exactly one caller shape (a revoke
 * handler certifying full revocation), so the correct bias for that failure is the opposite one:
 * a read that could not run must count as "still has active tokens," or a revoke whose UPDATE and
 * confirming COUNT both fail the same way still reports success while a live bearer token survives.
 *
 * @param string $client_id The public client identifier.
 * @return bool True when the client is confirmed to have at least one active token, OR when the
 *              confirming read itself failed and cannot rule that out.
 */
function aafm_oauth_client_has_active_tokens( string $client_id ): bool {
	if ( '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = aafm_wpdb_scalar(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE client_id = %s AND is_active = 1',
			$table,
			$client_id
		)
	);

	return ! $count['ok'] || (int) $count['value'] > 0;
}

/**
 * Whether a single user still has any active token row for one client, regardless of expiry.
 *
 * Same purpose as aafm_oauth_client_has_active_tokens(), scoped to the admin "Revoke grant"
 * action, including the same fail-closed bias on a read failure (see that function's docblock).
 *
 * @param int    $user_id   The WordPress user id.
 * @param string $client_id The public client identifier.
 * @return bool True when the pair is confirmed to have at least one active token, OR when the
 *              confirming read itself failed and cannot rule that out.
 */
function aafm_oauth_user_client_has_active_tokens( int $user_id, string $client_id ): bool {
	if ( $user_id <= 0 || '' === $client_id ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = aafm_wpdb_scalar(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE wp_user_id = %d AND client_id = %s AND is_active = 1',
			$table,
			$user_id,
			$client_id
		)
	);

	return ! $count['ok'] || (int) $count['value'] > 0;
}

/**
 * Revoke an entire refresh-token lineage, given any one row id in it.
 *
 * Each rotation links child.refresh_parent_id = parent.id, so the lineage is a
 * simple chain. From the seed row we walk UP the parent links to the root and
 * DOWN the child links to every descendant, deactivating each row we touch.
 * Both walks are bounded by AAFM_OAUTH_CHAIN_MAX_HOPS so a corrupt link can
 * never spin forever. After this runs, no token anywhere in the lineage
 * validates - which is the whole point of reuse detection.
 *
 * @param int $seed_id Any row id belonging to the lineage to revoke.
 * @return void
 */
function aafm_oauth_revoke_chain( int $seed_id ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// Collect every id in the lineage first, then deactivate in one pass.
	$ids = array( $seed_id );

	// Track whether either walk hit the hop cap. A cap hit means the lineage was longer than
	// AAFM_OAUTH_CHAIN_MAX_HOPS and the tail was NOT revoked - surface that rather than silently
	// truncating the revocation, so an operator can investigate (and the cap can be raised).
	$cap_hit = false;

	// Walk UP: follow refresh_parent_id toward the root.
	$cursor = $seed_id;
	for ( $hop = 0; $hop < AAFM_OAUTH_CHAIN_MAX_HOPS; $hop++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parent_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT refresh_parent_id FROM %i WHERE id = %d',
				$table,
				$cursor
			)
		);

		$parent_id = null === $parent_id ? 0 : (int) $parent_id;
		if ( $parent_id <= 0 || in_array( $parent_id, $ids, true ) ) {
			break;
		}

		$ids[]  = $parent_id;
		$cursor = $parent_id;

		// Reached the last allowed hop with the chain still extending upward.
		if ( AAFM_OAUTH_CHAIN_MAX_HOPS - 1 === $hop ) {
			$cap_hit = true;
		}
	}

	// Walk DOWN: each id may have one child whose refresh_parent_id points to it.
	// aafm_oauth_rotate_refresh()'s single-winner gate rules out this plugin's own code ever
	// minting two children for one row, but the schema itself has no UNIQUE constraint on
	// refresh_parent_id (see includes/oauth/schema.php), so a manually edited or otherwise
	// corrupted row CAN branch. A queue keeps the cap counting total descendants discovered
	// (not just depth) and revokes every branch it finds, not just one arbitrary line of them -
	// the same defense-in-depth this function's docblock already claims for a corrupt chain.
	$queue = $ids;
	$hops  = 0;
	while ( ! empty( $queue ) && $hops < AAFM_OAUTH_CHAIN_MAX_HOPS ) {
		++$hops;
		$current = array_shift( $queue );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$child_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE refresh_parent_id = %d',
				$table,
				$current
			)
		);

		foreach ( $child_ids as $child_id ) {
			$child_id = (int) $child_id;
			if ( $child_id > 0 && ! in_array( $child_id, $ids, true ) ) {
				$ids[]   = $child_id;
				$queue[] = $child_id;
			}
		}
	}

	// The DOWN walk stopped with descendants still queued: the cap truncated the traversal.
	if ( ! empty( $queue ) ) {
		$cap_hit = true;
	}

	// A cap hit means we revoked only the first AAFM_OAUTH_CHAIN_MAX_HOPS rows of a longer lineage;
	// the remainder stays active. Fire an action so the truncation is never silent: an operator can
	// hook it to log, alert, or schedule a follow-up sweep, or raise the cap. The seed id and the
	// number of rows revoked are passed so the handler can investigate the pathological chain.
	if ( $cap_hit ) {
		do_action( 'aafm_oauth_chain_revocation_capped', $seed_id, count( $ids ) );
	}

	// Deactivate the whole lineage in a single bounded UPDATE.
	$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

	// The table name is bound via the leading %i placeholder; $placeholders is a list
	// of %d built from the id count and every id is bound via $ids, so the query is
	// fully prepared. The %d list is still interpolated, so the InterpolatedNotPrepared
	// and UnfinishedPrepare ignores stay.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE %i SET is_active = 0 WHERE id IN ( {$placeholders} )",
			array_merge( array( $table ), $ids )
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}

/**
 * Revoke every active token a single user holds, across every client.
 *
 * Used when a WordPress user is deleted (see aafm_oauth_cleanup_deleted_user() below).
 * Same purpose as aafm_oauth_revoke_user_client_tokens(), scoped to the whole user
 * rather than one client pair.
 *
 * Errors are suppressed around the query (restored immediately after) so a not-yet-installed
 * or otherwise unreadable table never prints a raw wpdb error block - the same discipline
 * aafm_oauth_delete_all_user_consents() and the read-only listings in clients.php already
 * follow. The return value distinguishes a failed query from a clean no-op: $wpdb->rows_affected
 * is not read when the query itself returned false, because a real SQL failure and "nothing to
 * revoke" both leave that count at zero (the same R9-2 shape aafm_oauth_deactivate_client() and
 * aafm_oauth_delete_consent() already guard against with a certifying re-read).
 *
 * @param int $user_id The WordPress user id whose tokens are revoked.
 * @return int Number of token rows deactivated, or -1 when the query itself failed and the
 *              count cannot be trusted - a caller must not read -1 as "nothing to revoke".
 */
function aafm_oauth_revoke_user_tokens( int $user_id ): int {
	if ( $user_id <= 0 ) {
		return 0;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	$suppressed = $wpdb->suppress_errors();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET is_active = 0 WHERE wp_user_id = %d AND is_active = 1',
			$table,
			$user_id
		)
	);
	$wpdb->suppress_errors( $suppressed );

	return false === $result ? -1 : (int) $wpdb->rows_affected;
}

/**
 * Whether a single user still has any active token row, for any client.
 *
 * Same fail-closed certification bias as aafm_oauth_user_client_has_active_tokens(),
 * scoped to the whole user. Used to certify aafm_oauth_revoke_user_tokens().
 *
 * @param int $user_id The WordPress user id.
 * @return bool True when the user is confirmed to have at least one active token, OR when
 *              the confirming read itself failed and cannot rule that out.
 */
function aafm_oauth_user_has_active_tokens( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = aafm_wpdb_scalar(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE wp_user_id = %d AND is_active = 1',
			$table,
			$user_id
		)
	);

	return ! $count['ok'] || (int) $count['value'] > 0;
}

/**
 * Clean up a deleted WordPress user's OAuth grants: consents, tokens and codes.
 *
 * Hooked on 'deleted_user' rather than 'delete_user': it fires only once WordPress has
 * finished acting on the deletion, never pre-emptively for one a later step in the same
 * request might still abort. Both wp_delete_user() and the network-wide wpmu_delete_user()
 * fire it with the same ( $id, $reassign, $user ) signature at the very end (verified by
 * reading wp-admin/includes/user.php and wp-admin/includes/ms.php), so one hook covers
 * single-site deletion and a full network deletion alike.
 *
 * On a multisite subsite, wp_delete_user() does NOT delete the account - core's own
 * docblock says so - it calls remove_user_from_blog() for the current site instead, then
 * still fires 'deleted_user' regardless. So on that path this cleanup runs for a user whose
 * network account survives, wp_user_id still resolves to a real WP_User with ID > 0. That
 * is deliberately fine, not a bug: the action fired specifically because the user's
 * membership on THIS site just ended, so a grant recorded in THIS site's OAuth tables is
 * exactly as reasonable to clear as it is for the true-deletion case.
 *
 * Deliberately does NOT separately hook 'remove_user_from_blog': that fires on a plain
 * "Remove" from a subsite's Users list, which calls remove_user_from_blog() directly
 * without going through wp_delete_user(), so 'deleted_user' never fires for it and this
 * cleanup never runs for it either. Left alone on purpose: the account stays fully live,
 * capability checks already read live state per request and deny the moment the role on
 * this site is gone (verified in the 1.7.4 security assessment, S4), and clearing the
 * grant here would mean a later re-add to the site loses a connection that a plain
 * membership toggle should arguably preserve.
 *
 * Scoped to the current site's own tables (the $wpdb->prefix in effect when the hook fires),
 * the same scope every other function in this file uses. On wpmu_delete_user() this only
 * reaches the tables of whichever site is the current blog at the moment the network admin
 * request runs - not every site in the network the user may separately have connected on.
 * Sweeping every site would need a switch_to_blog() loop this plugin has no other precedent
 * for, and a grant left on another site is exactly as inert as the general case this finding
 * already established (a deleted user's id never resolves to a logged-in identity again), so
 * it is left as a known, named gap rather than an unannounced one.
 *
 * A failed cleanup is logged through the existing OAuth audit trail and never blocks, aborts
 * or reports an error back into the user-deletion request: deleting a user is a core operation
 * this plugin has no standing to veto, and the fail-safe reasoning above means a leftover row
 * is a hygiene issue, not a live credential.
 *
 * @param int $user_id The id of the WordPress user that was just deleted.
 * @return void
 */
function aafm_oauth_cleanup_deleted_user( int $user_id ): void {
	if ( $user_id <= 0 ) {
		return;
	}

	$consents_gone  = aafm_oauth_delete_all_user_consents( $user_id );
	$tokens_revoked = aafm_oauth_revoke_user_tokens( $user_id );
	$codes_revoked  = aafm_oauth_revoke_user_codes( $user_id );

	// The -1 sentinel from a failed revoke query is checked directly, rather than trusted to
	// surface only through the certifying reads below: those still catch it in the common case
	// where every OAuth table shares the same fate, but a table-by-table failure (the tokens
	// table gone while consents and codes are fine, say) would otherwise revoke nothing while
	// reporting the write as clean, the same silent-failure shape aafm_oauth_delete_all_user_consents()
	// closes with its own certifying read.
	$clean = $consents_gone
		&& $tokens_revoked >= 0
		&& $codes_revoked >= 0
		&& ! aafm_oauth_user_has_active_tokens( $user_id )
		&& ! aafm_oauth_user_has_pending_codes( $user_id );

	if ( ! $clean && function_exists( 'aafm_oauth_log_event' ) ) {
		aafm_oauth_log_event( 'revoke', 'error', array( 'user_id' => $user_id ) );
	}
}
