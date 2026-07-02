<?php
/**
 * Safety option getters: filterable, bounded, default off/neutral.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Requests-per-minute rate limit. 0 (the default) means no limit.
 *
 * @return int Clamped to >= 0.
 */
function aafm_rate_limit_per_min(): int {
	$stored = max( 0, (int) get_option( 'aafm_rate_limit_per_min', 0 ) );

	/**
	 * Filters the requests-per-minute rate limit. 0 means no limit.
	 *
	 * @param int $limit Stored limit, clamped to >= 0.
	 */
	$filtered = (int) apply_filters( 'aafm_rate_limit_per_min', $stored );

	// Re-clamp the post-filter value so a buggy filter returning a negative number can't
	// disable the limiter. A filter returning <= 0 when a positive limit is stored keeps the
	// stored limit (it cannot silently switch off this fail-closed control); a filter may still
	// set any other positive value.
	if ( $filtered < 0 ) {
		return $stored;
	}
	if ( 0 === $filtered && $stored > 0 ) {
		return $stored;
	}
	return $filtered;
}

/**
 * Consume one token from a principal's per-minute rate-limit bucket.
 *
 * Keyed by the authenticated principal's user id and the current GMT-minute, so
 * each user gets an independent window that naturally rolls over every minute.
 * The transient TTL is 120s - longer than a single minute so the bucket survives
 * its own window, while the GMT-minute embedded in the key is what actually rolls
 * the counter to a fresh bucket on the next minute.
 *
 * This is a fixed-window counter, so a burst straddling a GMT-minute boundary can
 * briefly allow up to 2x the limit. That is acceptable for a coarse defensive cap.
 *
 * The get_transient/set_transient read-modify-write is non-atomic, so under high
 * concurrency it is best-effort and may slightly undercount. Acceptable: this is a
 * safety throttle layered behind the real permission check, not a hard quota.
 *
 * Discovery / anonymous callers ($user_id <= 0) are intentionally exempt: there
 * is no real principal to limit, and listing (tools/list) relies on the raw
 * permission check rather than consuming a token. A limit of 0 means off.
 *
 * @param int $user_id Authenticated principal user id. <= 0 is never limited.
 * @return bool True if the request is allowed; false once the limit is exceeded.
 */
function aafm_rate_limit_consume( int $user_id ): bool {
	$limit = aafm_rate_limit_per_min();
	if ( $limit <= 0 || $user_id <= 0 ) {
		return true; // Off, or no authenticated principal to limit.
	}
	$key   = 'aafm_rl_' . $user_id . '_' . gmdate( 'YmdHi' );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return false;
	}
	set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
	return true;
}

/**
 * IP allowlist. Empty (the default) means no IP restriction.
 *
 * @return array<int, string> Trimmed, non-empty entries.
 */
function aafm_ip_allowlist(): array {
	$normalize = static fn( $entries ): array => array_values(
		array_filter(
			array_map( 'trim', array_filter( (array) $entries, 'is_string' ) )
		)
	);

	$stored = $normalize( get_option( 'aafm_ip_allowlist', array() ) );

	/**
	 * Filters the IP/CIDR allowlist for the MCP endpoint.
	 *
	 * @param array<int, string> $stored Trimmed, non-empty entries.
	 */
	$filtered = $normalize( apply_filters( 'aafm_ip_allowlist', $stored ) );

	// Fail closed at the filter seam: a filter must not EMPTY an operator-configured non-empty
	// allowlist, which would widen the endpoint to allow-all. When the filter returns nothing
	// but the operator stored entries, keep the stored list. An operator-set empty allowlist
	// stays empty/off by design, which is the no-filter case where $stored is itself empty.
	if ( array() === $filtered && array() !== $stored ) {
		return $stored;
	}

	return $filtered;
}

/**
 * Whether a single IP falls inside a CIDR range (or matches a bare host).
 *
 * Fail-closed: any malformed input, family mismatch, or out-of-range prefix
 * returns false. Supports both IPv4 and IPv6. A bare IP (no `/`) is treated as
 * an exact host match (an implicit /32 or /128).
 *
 * @param string $ip   Candidate IP address.
 * @param string $cidr Subnet in `network/prefix` form, or a bare IP.
 * @return bool True only on a confirmed match.
 */
function aafm_cidr_match( string $ip, string $cidr ): bool {
	if ( '' === $ip || '' === $cidr ) {
		return false;
	}

	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	// Bare host: exact match, same family, via packed-byte comparison.
	if ( false === strpos( $cidr, '/' ) ) {
		if ( false === filter_var( $cidr, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return inet_pton( $ip ) === inet_pton( $cidr );
	}

	list( $subnet, $prefix_raw ) = explode( '/', $cidr, 2 );

	if ( false === filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	// Prefix must be a plain run of digits (no sign, no whitespace, no decimals).
	if ( '' === $prefix_raw || 1 !== preg_match( '/^\d+$/', $prefix_raw ) ) {
		return false;
	}
	$prefix = (int) $prefix_raw;

	// Both ends must belong to the same address family.
	$ip_is_v4     = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	$subnet_is_v4 = false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	if ( $ip_is_v4 !== $subnet_is_v4 ) {
		return false;
	}

	$max = $ip_is_v4 ? 32 : 128;
	if ( $prefix < 0 || $prefix > $max ) {
		return false;
	}

	$packed_ip     = inet_pton( $ip );
	$packed_subnet = inet_pton( $subnet );
	if ( false === $packed_ip || false === $packed_subnet ) {
		return false;
	}

	// Build a binary mask of `$prefix` set bits: full 0xFF bytes, one partial
	// byte for the remainder, then 0x00 padding to the address byte length.
	$full_bytes  = intdiv( $prefix, 8 );
	$remainder   = $prefix % 8;
	$total_bytes = strlen( $packed_ip );
	$mask        = str_repeat( "\xff", $full_bytes );
	if ( $remainder > 0 ) {
		$mask .= chr( 0xFF << ( 8 - $remainder ) & 0xFF );
	}
	$mask = str_pad( $mask, $total_bytes, "\x00" );

	return ( $packed_ip & $mask ) === ( $packed_subnet & $mask );
}

/**
 * Whether a single allowlist line is a valid bare IP or a valid CIDR range.
 *
 * Fail-closed: anything that is not a well-formed IPv4/IPv6 address, or a
 * `network/prefix` where the network is a valid IP and the prefix is digits
 * within the family's range (0..32 for IPv4, 0..128 for IPv6), returns false.
 * This is the gate the settings sanitizer uses to drop invalid lines so a saved
 * allowlist is always made up entirely of usable entries.
 *
 * @param string $line One trimmed allowlist entry.
 * @return bool True only for a well-formed IP or CIDR.
 */
function aafm_is_valid_ip_or_cidr( string $line ): bool {
	if ( '' === $line ) {
		return false;
	}

	// Bare host: a plain IPv4 or IPv6 address.
	if ( false === strpos( $line, '/' ) ) {
		return false !== filter_var( $line, FILTER_VALIDATE_IP );
	}

	list( $subnet, $prefix_raw ) = explode( '/', $line, 2 );

	if ( false === filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	// Prefix must be a plain run of digits (no sign, whitespace, or decimals).
	if ( '' === $prefix_raw || 1 !== preg_match( '/^\d+$/', $prefix_raw ) ) {
		return false;
	}

	$max = ( false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) ? 32 : 128;

	return (int) $prefix_raw <= $max;
}

/**
 * Whether an IP is permitted by the allowlist.
 *
 * An empty allowlist is the neutral default and permits everyone. A non-empty
 * allowlist restricts access to matching entries only - and because every entry
 * is checked through {@see aafm_cidr_match()}, a list made up entirely of
 * invalid entries matches nothing and therefore blocks (fail-closed).
 *
 * @param string $ip Candidate IP address.
 * @return bool True if allowed.
 */
function aafm_ip_is_allowed( string $ip ): bool {
	$list = aafm_ip_allowlist();
	if ( empty( $list ) ) {
		return true;
	}

	foreach ( $list as $entry ) {
		if ( aafm_cidr_match( $ip, $entry ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether agent-created content is forced to draft. Off by default.
 *
 * @return bool
 */
function aafm_force_draft(): bool {
	/**
	 * Filters whether agent-created content is forced to draft.
	 *
	 * @param bool $force True to force draft status.
	 */
	return (bool) apply_filters( 'aafm_force_draft', (bool) get_option( 'aafm_force_draft', false ) );
}

/**
 * Maximum allowed title length. 0 (the default) means no cap.
 *
 * @return int Clamped to >= 0.
 */
function aafm_max_title_len(): int {
	/**
	 * Filters the maximum allowed title length. 0 means no cap.
	 *
	 * @param int $len Stored cap, clamped to >= 0.
	 */
	return (int) apply_filters( 'aafm_max_title_len', max( 0, (int) get_option( 'aafm_max_title_len', 0 ) ) );
}

/**
 * How many days of activity log to keep. 0 means keep every entry forever.
 *
 * The stored value is clamped to [0, 3650] (ten years) so a pasted-in absurd or
 * negative number can never be stored. The daily prune cron reads this getter to
 * decide the cutoff date; the dashboard and activity tab read it for their copy.
 *
 * @return int Retention window in days, clamped to [0, 3650]. Default 30.
 */
function aafm_log_retention_days(): int {
	$raw = (int) get_option( 'aafm_log_retention_days', 30 );
	return max( 0, min( 3650, $raw ) );
}

/**
 * Whether a title is within the configured maximum length.
 *
 * Off (cap <= 0) always passes. Counted with mb_strlen so multibyte titles
 * are measured in characters, not bytes.
 *
 * @param string $title Sanitized title to measure.
 * @return bool True when within the cap (or the cap is off).
 */
function aafm_title_within_limit( string $title ): bool {
	$max = aafm_max_title_len();
	return $max <= 0 || mb_strlen( $title ) <= $max;
}
