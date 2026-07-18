<?php
/**
 * OAuth lifecycle audit logging.
 *
 * The register/authorize/token/refresh/revoke lifecycle previously wrote no audit
 * rows, so an illicit-consent-grant intrusion (a phished admin approving an
 * attacker-registered client) left no trace. This records one row per lifecycle
 * event in the SAME activity-log table the ability calls use, so an operator sees
 * OAuth activity alongside ability activity in one place.
 *
 * Only non-secret context is ever recorded: the client_id (the join key back to the
 * client row and its registered redirect URIs), the redirect host, the acting user,
 * the source IP (captured by aafm_log_activity), and a status. Authorization codes,
 * access/refresh tokens, client secrets, PKCE values, and state are NEVER logged.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Record one OAuth lifecycle event in the activity log.
 *
 * Writes through aafm_log_activity() so the row lands in the same table, honours the
 * same retention/pruning, and fires the same SIEM seam as an ability call. The event
 * name is stored in the `ability` column as `oauth:<event>`; the non-secret context
 * (client_id, redirect host) rides in the argument-keys column, which the activity log
 * sanitises with sanitize_key(), so the host is normalised to a slug form there.
 *
 * No-op (returns silently) when the activity log is unavailable or the event name is
 * not one of the known lifecycle events, so a logging failure can never break an
 * OAuth response.
 *
 * @param string              $event  One of register|authorize|token|refresh|revoke|bearer.
 * @param string              $status One of success|error|denied.
 * @param array<string,mixed> $ctx    {
 *     Optional non-secret context.
 *
 *     @type string $client_id     The public client identifier (join key to the client row).
 *     @type string $redirect_host The redirect host the grant is sent to.
 *     @type int    $user_id       The acting WordPress user id (0 when none).
 *     @type string $user_login    The acting WordPress user login (may be '').
 * }
 * @return void
 */
function aafm_oauth_log_event( string $event, string $status, array $ctx = array() ): void {
	if ( ! function_exists( 'aafm_log_activity' ) ) {
		return;
	}

	// 'bearer' covers a presented-but-invalid aafm_oat_ credential at the determine_current_user
	// resolver: a real but expired token, a wrong audience, or a deactivated owning client. A bearer
	// that matches no stored token at all is NOT logged (see aafm_oauth_access_token_row_exists) so an
	// unauthenticated caller cannot grow the log by fabricating the prefix. Distinct from the five
	// grant-lifecycle events, which all concern a code or token WordPress itself just minted or accepted.
	if ( ! in_array( $event, array( 'register', 'authorize', 'token', 'refresh', 'revoke', 'bearer' ), true ) ) {
		return;
	}

	$status = in_array( $status, array( 'success', 'error', 'denied' ), true ) ? $status : 'error';

	// Build the non-secret context list. NEVER a token, code, refresh value, secret, PKCE value,
	// or state - only identifiers an operator needs to trace the event.
	$keys = array();
	if ( isset( $ctx['client_id'] ) && '' !== (string) $ctx['client_id'] ) {
		// client_id is a 32-char hex string, so sanitize_key() (applied by aafm_log_activity)
		// preserves it intact; it is the join key back to the client row.
		$keys[] = 'client_' . (string) $ctx['client_id'];
	}
	if ( isset( $ctx['redirect_host'] ) && '' !== (string) $ctx['redirect_host'] ) {
		// Normalise the host to a sanitize_key()-safe slug so it survives the activity log's
		// arg-key sanitisation readably (dots/colon -> hyphen). The exact host is also recoverable
		// from the client_id via the clients table, and is shown verbatim on the consent screen.
		$host = strtolower( (string) $ctx['redirect_host'] );
		$host = (string) preg_replace( '/[^a-z0-9]+/', '-', $host );
		$host = trim( $host, '-' );
		if ( '' !== $host ) {
			$keys[] = 'host_' . $host;
		}
	}

	aafm_log_activity(
		array(
			'ability'           => 'oauth:' . $event,
			'principal_user_id' => isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : 0,
			'principal_login'   => isset( $ctx['user_login'] ) ? (string) $ctx['user_login'] : '',
			'status'            => $status,
			'arg_keys'          => $keys,
		)
	);
}

/**
 * Derive the host (with any explicit port) from a redirect URI, for audit context.
 *
 * Returns '' when the URI has no parseable host. Used so the register/authorize log rows
 * can record WHERE a grant is sent without the caller re-parsing the URI inline.
 *
 * @param string $redirect_uri The redirect URI.
 * @return string The host, optionally ':port', or '' when unparseable.
 */
function aafm_oauth_audit_host_from_uri( string $redirect_uri ): string {
	$host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return '';
	}
	$port = wp_parse_url( $redirect_uri, PHP_URL_PORT );
	return is_int( $port ) ? $host . ':' . $port : $host;
}
