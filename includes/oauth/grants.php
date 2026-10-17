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
