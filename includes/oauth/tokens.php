<?php
/**
 * OAuth access and refresh tokens.
 *
 * Mints access/refresh token pairs and stores only their SHA-256 hashes — the
 * raw values are returned once and never persisted in clear. Refresh tokens
 * rotate on every use: redeeming a refresh token deactivates it and issues a
 * fresh pair whose refresh_parent_id links back to the consumed row.
 *
 * Replaying a consumed (inactive) refresh token triggers reuse detection: the
 * entire lineage — every row reachable up the parent links and down the child
 * links — is revoked, so a stolen-then-replayed token kills the live session.
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
 * raw values are returned to the caller once and never stored in clear — only
 * their SHA-256 hashes are persisted, alongside the binding context.
 *
 * @param array<string,mixed> $ctx {
 *     Token binding context.
 *
 *     @type string $client_id         The public client identifier.
 *     @type int    $wp_user_id        The authenticated WordPress user.
 *     @type string $resource          The resource indicator the token is scoped to.
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
			'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + $access_ttl ),
			'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + $refresh_ttl ),
			'is_active'          => 1,
		),
		array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d' )
	);

	// A failed insert means there is no persisted token row — never return a token pair for it,
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
 * The raw token is hashed and looked up by token_hash. A token validates only
 * when it is active and its expires_at is still in the future.
 *
 * @param string $raw The raw access token presented by the client.
 * @return int|false The wp_user_id on success, or false when expired, inactive, or unknown.
 */
function aafm_oauth_validate_access_token( string $raw ) {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';
	$now   = gmdate( 'Y-m-d H:i:s', time() );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$user_id = $wpdb->get_var(
		$wpdb->prepare(
			// Keep this WHERE clause in sync with aafm_oauth_get_access_token_row() in validator.php — the two must never disagree on the active/unexpired predicate.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant; all values are bound.
			"SELECT wp_user_id FROM {$table}
			 WHERE token_hash = %s
			   AND is_active = 1
			   AND expires_at > %s",
			hash( 'sha256', $raw ),
			$now
		)
	);

	if ( null === $user_id ) {
		return false;
	}

	return (int) $user_id;
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT * FROM {$table} WHERE refresh_hash = %s",
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
	// out). The InnoDB engine is implied by the table's get_charset_collate().
	// The WP test harness already wraps each test in its own transaction, so this
	// nested START/COMMIT is effectively a no-op there — it does not break test
	// isolation.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'START TRANSACTION' );

	// Single-winner gate: deactivate the row only while it is still active. Under
	// a concurrent race two presentations of the same refresh token both reach
	// here, but exactly one UPDATE flips is_active 1 -> 0 and affects a row; the
	// loser affects none. $wpdb->update() returns the affected-row count (or false
	// on error). Anything other than exactly one consumed row means we did not win
	// the race (or the query failed) — roll back and reject without minting.
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant; all values are bound.
			"UPDATE {$table}
			 SET is_active = 0
			 WHERE ( token_hash = %s OR refresh_hash = %s )
			   AND is_active = 1",
			$hash,
			$hash
		)
	);

	return (int) $wpdb->rows_affected > 0;
}

/**
 * Revoke an entire refresh-token lineage, given any one row id in it.
 *
 * Each rotation links child.refresh_parent_id = parent.id, so the lineage is a
 * simple chain. From the seed row we walk UP the parent links to the root and
 * DOWN the child links to every descendant, deactivating each row we touch.
 * Both walks are bounded by AAFM_OAUTH_CHAIN_MAX_HOPS so a corrupt link can
 * never spin forever. After this runs, no token anywhere in the lineage
 * validates — which is the whole point of reuse detection.
 *
 * @param int $seed_id Any row id belonging to the lineage to revoke.
 * @return void
 */
function aafm_oauth_revoke_chain( int $seed_id ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

	// Collect every id in the lineage first, then deactivate in one pass.
	$ids = array( $seed_id );

	// Walk UP: follow refresh_parent_id toward the root.
	$cursor = $seed_id;
	for ( $hop = 0; $hop < AAFM_OAUTH_CHAIN_MAX_HOPS; $hop++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parent_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT refresh_parent_id FROM {$table} WHERE id = %d",
				$cursor
			)
		);

		$parent_id = null === $parent_id ? 0 : (int) $parent_id;
		if ( $parent_id <= 0 || in_array( $parent_id, $ids, true ) ) {
			break;
		}

		$ids[]  = $parent_id;
		$cursor = $parent_id;
	}

	// Walk DOWN: each id may have one child whose refresh_parent_id points to it.
	// Use a queue so the cap counts total descendants discovered, not just depth.
	$queue = $ids;
	$hops  = 0;
	while ( ! empty( $queue ) && $hops < AAFM_OAUTH_CHAIN_MAX_HOPS ) {
		++$hops;
		$current = array_shift( $queue );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$child_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT id FROM {$table} WHERE refresh_parent_id = %d",
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

	// Deactivate the whole lineage in a single bounded UPDATE.
	$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

	// $table is an internal constant; $placeholders is a list of %d built from the
	// id count and every id is bound via $ids, so the query is fully prepared.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET is_active = 0 WHERE id IN ( {$placeholders} )",
			$ids
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}
