<?php
/**
 * Per-connection (per-grant) state on the consents table: pause, end date, last use, access
 * preset, rate-limit override. NULL in a column is today's behaviour.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The state of a grant with nothing set: what every pre-1.10.0 consent row reads as.
 *
 * @return array{paused:bool,expired:bool,expires_at:?string,preset:string,rate:int,last_used_at:?string,last_used_ip:string}
 */
function aafm_oauth_grant_defaults(): array {
	return array(
		'paused'       => false,
		'expired'      => false,
		'expires_at'   => null,
		'preset'       => '',
		'rate'         => 0,
		'last_used_at' => null,
		'last_used_ip' => '',
	);
}

/**
 * True only when the consents table can be read and holds NONE of the six v9 columns: the state between
 * the plugin files changing and the first request that runs the installer. Any other state, including a
 * failed read, a missing table, a half-installed column set or a current column set whose version stamp
 * failed, is not "absent". The caller denies in those cases, because a stored pause, end date or preset
 * may exist that this request cannot see.
 *
 * @return bool
 */
function aafm_oauth_grant_columns_absent(): bool {
	global $wpdb;
	$suppressed = $wpdb->suppress_errors( true );
	$view       = aafm_wpdb_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wpdb->prefix . 'aafm_oauth_consents' ) );
	$wpdb->suppress_errors( $suppressed );
	if ( ! $view['ok'] || ! is_array( $view['value'] ) || array() === $view['value'] ) {
		return false;
	}
	return array() === array_intersect( aafm_oauth_grant_columns(), array_column( $view['value'], 'Field' ) );
}

/**
 * The state of one user's grant to one client.
 *
 * @param int    $user_id   Approving user.
 * @param string $client_id OAuth client id.
 * @return array{paused:bool,expired:bool,expires_at:?string,preset:string,rate:int,last_used_at:?string,last_used_ip:string}|null
 *         Null when the state could not be certified; the caller must deny.
 */
function aafm_oauth_grant_state( int $user_id, string $client_id ): ?array {
	global $wpdb;
	$state = aafm_oauth_grant_defaults();
	if ( $user_id <= 0 || '' === $client_id ) {
		return $state;
	}
	// Errors are muted for this one read: before the installer has added the v9 columns the SELECT fails, and
	// that must neither print under WP_DEBUG nor lose the caller's request.
	$suppressed = $wpdb->suppress_errors( true );
	$view       = aafm_wpdb_row(
		$wpdb->prepare(
			'SELECT paused_at, expires_at, last_used_at, last_used_ip, preset, rate_per_min FROM %i WHERE wp_user_id = %d AND client_id = %s',
			$wpdb->prefix . 'aafm_oauth_consents',
			$user_id,
			$client_id
		)
	);
	$wpdb->suppress_errors( $suppressed );

	if ( ! $view['ok'] ) {
		// Defaults are exact only when a read POSITIVELY shows the columns do not exist yet: nothing could have been
		// paused or expired before they existed. The version stamp is deliberately not consulted, because the
		// installer allows a stamp to fail after the columns landed. Any other failed read cannot be certified: null,
		// and the caller denies.
		return aafm_oauth_grant_columns_absent() ? $state : null;
	}
	// No consent row: a token without one works as it always did.
	return is_array( $view['value'] ) ? aafm_oauth_grant_from_row( $view['value'] ) : $state;
}

/**
 * Turn a consents row (any subset of the six v9 columns) into the state shape. The one mapping: the
 * resolver reads it through aafm_oauth_grant_state() and the Connection tab through aafm_oauth_list_grants().
 * An absent key and a NULL both mean the default.
 *
 * @param array<string,mixed> $row Row from the consents table.
 * @return array{paused:bool,expired:bool,expires_at:?string,preset:string,rate:int,last_used_at:?string,last_used_ip:string}
 */
function aafm_oauth_grant_from_row( array $row ): array {
	$expires = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null;
	return array(
		'paused'       => isset( $row['paused_at'] ),
		// 'Y-m-d H:i:s' strings compare correctly as text.
		'expired'      => null !== $expires && $expires <= gmdate( 'Y-m-d H:i:s' ),
		'expires_at'   => $expires,
		'preset'       => isset( $row['preset'] ) ? (string) $row['preset'] : '',
		'rate'         => isset( $row['rate_per_min'] ) ? (int) $row['rate_per_min'] : 0,
		'last_used_at' => isset( $row['last_used_at'] ) ? (string) $row['last_used_at'] : null,
		'last_used_ip' => isset( $row['last_used_ip'] ) ? (string) $row['last_used_ip'] : '',
	);
}

if ( ! defined( 'AAFM_OAUTH_LAST_USED_WINDOW' ) ) {
	// Seconds a last-used stamp stays fresh: inside the window the hot path writes nothing.
	define( 'AAFM_OAUTH_LAST_USED_WINDOW', 300 );
}

/**
 * Change an owner-controlled field on one grant and certify it against a fresh read.
 *
 * Moving an EXPIRED grant to a cleared or future end date first revokes the user's tokens for that
 * app and certifies none is still active, so an expired grant never revives its old credentials.
 *
 * @param int                 $user_id   Approving user.
 * @param string              $client_id OAuth client id.
 * @param array<string,mixed> $changes   Any of paused (bool), expires_at (?string UTC Y-m-d H:i:s, null clears),
 *                                       preset (string, '' is Custom), rate (int, 0 inherits).
 * @return bool True only when a fresh read shows every requested value.
 */
function aafm_oauth_grant_update( int $user_id, string $client_id, array $changes ): bool {
	global $wpdb;
	$data = array();
	$fmt  = array();
	if ( array_key_exists( 'paused', $changes ) ) {
		$data['paused_at'] = $changes['paused'] ? gmdate( 'Y-m-d H:i:s' ) : null;
		$fmt[]             = '%s';
	}
	if ( array_key_exists( 'expires_at', $changes ) ) {
		$data['expires_at'] = null === $changes['expires_at'] ? null : (string) $changes['expires_at'];
		$fmt[]              = '%s';
	}
	if ( array_key_exists( 'preset', $changes ) ) {
		$data['preset'] = '' === (string) $changes['preset'] ? null : (string) $changes['preset'];
		$fmt[]          = '%s';
	}
	if ( array_key_exists( 'rate', $changes ) ) {
		$data['rate_per_min'] = (int) $changes['rate'] > 0 ? (int) $changes['rate'] : null;
		$fmt[]                = '%d';
	}
	if ( $user_id <= 0 || '' === $client_id || array() === $data ) {
		return false;
	}
	aafm_maybe_upgrade_oauth_tables(); // A write needs the columns; reads tolerate their absence.

	// An expired grant is over. Moving its end date (or clearing it) must not bring its old credentials back to
	// life, so the tokens go first and the write stops if they cannot be certified gone.
	$before = aafm_oauth_grant_state( $user_id, $client_id );
	if ( null === $before ) {
		return false;
	}
	$renews = $before['expired'] && array_key_exists( 'expires_at', $changes )
		&& ( null === $changes['expires_at'] || (string) $changes['expires_at'] > gmdate( 'Y-m-d H:i:s' ) );
	if ( $renews && ( -1 === aafm_oauth_revoke_user_client_tokens( $user_id, $client_id ) || aafm_oauth_user_client_has_active_tokens( $user_id, $client_id ) ) ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update(
		$wpdb->prefix . 'aafm_oauth_consents',
		$data,
		array(
			'wp_user_id' => $user_id,
			'client_id'  => $client_id,
		),
		$fmt,
		array( '%d', '%s' )
	);

	// Certify against a fresh read, not the UPDATE's affected-row count (same rule as aafm_oauth_deactivate_client()).
	// No matching row reads as the defaults, so the row itself must still be there: otherwise a
	// resume (or a cleared field) asked of a grant that is gone would certify against those defaults.
	$state = aafm_oauth_grant_state( $user_id, $client_id );
	if ( null === $state || ! aafm_oauth_has_consent( $user_id, $client_id ) ) {
		return false;
	}
	return ( ! array_key_exists( 'paused', $changes ) || (bool) $changes['paused'] === $state['paused'] )
		&& ( ! array_key_exists( 'expires_at', $changes ) || ( $data['expires_at'] ?? null ) === $state['expires_at'] )
		&& ( ! array_key_exists( 'preset', $changes ) || (string) $changes['preset'] === $state['preset'] )
		&& ( ! array_key_exists( 'rate', $changes ) || max( 0, (int) $changes['rate'] ) === $state['rate'] );
}

/**
 * Stamp the grant's last use, at most once per AAFM_OAUTH_LAST_USED_WINDOW. Never fails the caller.
 *
 * @param int         $user_id      Approving user.
 * @param string      $client_id    OAuth client id.
 * @param string|null $last_used_at The stamp the caller already read, or null.
 * @return void
 */
function aafm_oauth_grant_touch( int $user_id, string $client_id, ?string $last_used_at ): void {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - AAFM_OAUTH_LAST_USED_WINDOW );
	if ( null !== $last_used_at && $last_used_at > $cutoff ) {
		return; // Fresh enough: no write on the hot path.
	}
	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET last_used_at = %s, last_used_ip = %s WHERE wp_user_id = %d AND client_id = %s AND ( last_used_at IS NULL OR last_used_at <= %s )',
			$wpdb->prefix . 'aafm_oauth_consents',
			gmdate( 'Y-m-d H:i:s' ),
			substr( aafm_source_ip(), 0, 45 ),
			$user_id,
			$client_id,
			$cutoff
		)
	);
	$wpdb->suppress_errors( $suppressed );
}

/**
 * Whether a grant may mint or use a credential: 'ok', 'expired', or 'unknown' (the state could not
 * be read; the caller denies).
 *
 * @param int    $user_id   Approving user.
 * @param string $client_id OAuth client id.
 * @return string
 */
function aafm_oauth_grant_gate( int $user_id, string $client_id ): string {
	$state = aafm_oauth_grant_state( $user_id, $client_id );
	if ( null === $state ) {
		return 'unknown';
	}
	return $state['expired'] ? 'expired' : 'ok';
}

/**
 * The grant the current request's bearer resolved to: a per-request store, cleared at shutdown.
 *
 * @param array<string,mixed>|null $grant  The grant to remember, or null to read.
 * @param bool                     $forget Drop the stored grant.
 * @return array<string,mixed> The defaults plus client_id and user_id.
 */
function aafm_oauth_current_grant( ?array $grant = null, bool $forget = false ): array {
	static $stored = null;
	if ( $forget ) {
		$stored = null;
	} elseif ( null !== $grant ) {
		$stored = $grant;
	}
	return $stored ?? array_merge(
		aafm_oauth_grant_defaults(),
		array(
			'client_id' => '',
			'user_id'   => 0,
		)
	);
}
