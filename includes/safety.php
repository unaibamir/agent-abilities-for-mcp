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
	// An array or an object is not a stored limit, and limits to 1.
	$limit = static function ( $stored ): int {
		return is_array( $stored ) || is_object( $stored ) ? 1 : max( 0, (int) $stored );
	};

	$raw    = get_option( 'aafm_rate_limit_per_min', 0 );
	$stored = $limit( $raw );
	if ( 0 === $raw ) {
		// No limit may be a failed read's default: the row decides, and an unreadable row limits to 1.
		$row    = aafm_policy_row( 'aafm_rate_limit_per_min' );
		$stored = ! $row['ok'] ? 1 : ( $row['found'] ? $limit( $row['value'] ) : 0 );
	}

	// A cache copy that disagrees with the row takes the lower positive limit (off only when both
	// are); an unreadable row limits to 1.
	$row = aafm_policy_row_if_stale( 'aafm_rate_limit_per_min' );
	if ( null !== $row ) {
		$other  = ! $row['ok'] ? 1 : ( $row['found'] ? $limit( $row['value'] ) : 0 );
		$stored = 0 === $stored || 0 === $other ? max( $stored, $other ) : min( $stored, $other );
	}

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
 * Keyed by the authenticated principal's user id and the current GMT minute, so each user gets an
 * independent window that naturally rolls over every minute. An OAuth call whose connection carries
 * a per-app override also counts against a per-app bucket, which can only lower the limit: the
 * per-user bucket stays the ceiling, and a call refused by either bucket consumes neither. The
 * transient TTL is 120s - longer than a single minute so the bucket survives its own window, while
 * the GMT minute embedded in the key is what actually rolls the counter to a fresh bucket.
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
 * permission check rather than consuming a token. A site limit of 0 means off; a per-app
 * override still caps that app.
 *
 * @param int $user_id Authenticated principal user id. <= 0 is never limited.
 * @return bool True if the request is allowed; false once a limit is exceeded.
 */
function aafm_rate_limit_consume( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return true; // No authenticated principal to limit.
	}
	$grant  = aafm_oauth_current_grant();
	$site   = aafm_rate_limit_per_min();
	$app    = '' !== $grant['client_id'] ? (int) $grant['rate'] : 0; // An Application Password has no client, so no override.
	$minute = gmdate( 'YmdHi' );
	$limits = array();
	if ( $site > 0 ) {
		$limits[ 'aafm_rl_' . $user_id . '_' . $minute ] = $site; // The per-user ceiling; this key is unchanged from 1.9.0.
	}
	if ( $app > 0 ) {
		$limits[ 'aafm_rla_' . $user_id . '_' . substr( md5( (string) $grant['client_id'] ), 0, 12 ) . '_' . $minute ] = $app;
	}
	$counts = array();
	foreach ( $limits as $key => $limit ) {
		$count = aafm_transient_count( $key );
		if ( null === $count || $count >= $limit ) {
			return false; // Refused by one bucket: neither is consumed.
		}
		$counts[ $key ] = $count;
	}
	foreach ( $counts as $key => $count ) {
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
	}
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

	// One entry that is not an address matches nothing, so every IP is refused. It stands for an
	// unreadable row, a stored object, and a cache copy that disagrees with the row.
	$refuse_all = array( 'aafm-allowlist-read-failed' );

	$raw    = get_option( 'aafm_ip_allowlist', array() );
	$stored = is_object( $raw ) ? $refuse_all : $normalize( $raw );
	if ( array() === $raw ) {
		// An empty list may be a failed read's default: the row decides.
		$row    = aafm_policy_row( 'aafm_ip_allowlist' );
		$stored = ! $row['ok'] ? $refuse_all : ( $row['found'] ? ( is_object( $row['value'] ) ? $refuse_all : $normalize( $row['value'] ) ) : array() );
	}
	if ( null !== aafm_policy_row_if_stale( 'aafm_ip_allowlist' ) ) {
		$stored = $refuse_all;
	}

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

	$parsed = aafm_split_and_validate_cidr( $cidr );
	if ( null === $parsed ) {
		return false;
	}
	list(
		'subnet' => $subnet,
		'prefix' => $prefix,
		'max'    => $max,
	) = $parsed;

	// Both ends must belong to the same address family.
	$ip_is_v4 = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	if ( ( 32 === $max ) !== $ip_is_v4 ) {
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

	return null !== aafm_split_and_validate_cidr( $line );
}

/**
 * Split and validate a `network/prefix` string shared by aafm_cidr_match() and
 * aafm_is_valid_ip_or_cidr(): the subnet must be a well-formed IPv4 or IPv6 address, the
 * prefix must be a plain run of digits (no sign, whitespace, or decimals), and the prefix
 * must not exceed the family's bit length (32 for IPv4, 128 for IPv6). Callers that already
 * handled a bare host (no `/`) before reaching here never pass one.
 *
 * @param string $value A `network/prefix` string (never a bare host).
 * @return array{subnet:string,prefix:int,max:int}|null Null on any validation failure.
 */
function aafm_split_and_validate_cidr( string $value ): ?array {
	list( $subnet, $prefix_raw ) = explode( '/', $value, 2 );

	if ( false === filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
		return null;
	}

	// Prefix must be a plain run of digits (no sign, whitespace, or decimals).
	if ( '' === $prefix_raw || 1 !== preg_match( '/^\d+$/', $prefix_raw ) ) {
		return null;
	}
	$prefix = (int) $prefix_raw;

	$max = ( false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) ? 32 : 128;
	if ( $prefix > $max ) {
		return null;
	}

	return array(
		'subnet' => $subnet,
		'prefix' => $prefix,
		'max'    => $max,
	);
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

	return aafm_ip_in_ranges( $ip, $list );
}

/**
 * Whether an address falls in any of a list of addresses or CIDR ranges.
 *
 * @param string       $ip     Candidate address.
 * @param array<mixed> $ranges Bare addresses or CIDR ranges.
 * @return bool
 */
function aafm_ip_in_ranges( string $ip, array $ranges ): bool {
	foreach ( $ranges as $range ) {
		if ( aafm_cidr_match( $ip, (string) $range ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The headers a trusted proxy may name the visitor in. A closed list: anything else is ignored.
 *
 * @return array<int,string>
 */
function aafm_proxy_headers(): array {
	return array( 'CF-Connecting-IP', 'True-Client-IP', 'X-Real-IP', 'X-Forwarded-For' );
}

/**
 * A valid address or CIDR that does not trust a huge part of the internet: at least /8 for IPv4
 * and /16 for IPv6.
 *
 * @param string $range Address or CIDR.
 * @return bool
 */
function aafm_proxy_range_ok( string $range ): bool {
	if ( ! aafm_is_valid_ip_or_cidr( $range ) ) {
		return false;
	}
	$pos = strpos( $range, '/' );
	if ( false === $pos ) {
		return true;
	}
	return (int) substr( $range, $pos + 1 ) >= ( false !== strpos( $range, ':' ) ? 16 : 8 );
}

/**
 * Unwrap an IPv4-mapped IPv6 address (::ffff:a.b.c.d) so range tests compare like with like.
 * ponytail: the hex form ::ffff:c000:201 is not unwrapped; a dual-stack socket reports the dotted form.
 *
 * @param string $ip Address.
 * @return string
 */
function aafm_normalize_ip( string $ip ): string {
	if ( 0 === stripos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		return substr( $ip, 7 );
	}
	return $ip;
}

/**
 * Cloudflare's published edge ranges, from https://www.cloudflare.com/ips-v4 and
 * https://www.cloudflare.com/ips-v6, fetched 2026-10-17. Refreshed by hand each release; the
 * plugin never fetches them (the readme promises no telemetry). A range missing from a stale list
 * is not trusted, so the proxy's own address is used: that can lock out an allowlist that holds
 * visitor addresses, and it can admit everyone behind a proxy when the allowlist holds the proxy's
 * address.
 *
 * @return list<string>
 */
function aafm_cloudflare_ranges(): array {
	return array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);
}

/**
 * The trusted-proxy setting. Absent, unreadable or malformed reads as off: today's behaviour.
 *
 * @return array{mode:string,header:string,ranges:list<string>}
 */
function aafm_trusted_proxy_config(): array {
	$row = aafm_policy_row( 'aafm_trusted_proxy' );
	return aafm_trusted_proxy_normalize( $row['ok'] && $row['found'] && is_array( $row['value'] ) ? $row['value'] : array() );
}

/**
 * A stored or submitted trusted-proxy value as the config shape. Anything malformed is off.
 *
 * @param array<mixed> $value Stored or submitted value.
 * @return array{mode:string,header:string,ranges:list<string>}
 */
function aafm_trusted_proxy_normalize( array $value ): array {
	$off  = array(
		'mode'   => 'off',
		'header' => '',
		'ranges' => array(),
	);
	$mode = isset( $value['mode'] ) && is_string( $value['mode'] ) ? $value['mode'] : 'off';
	if ( 'cloudflare' === $mode ) {
		return array(
			'mode'   => 'cloudflare',
			'header' => 'CF-Connecting-IP',
			'ranges' => aafm_cloudflare_ranges(),
		);
	}
	$header = isset( $value['header'] ) && is_string( $value['header'] ) ? $value['header'] : '';
	if ( 'custom' !== $mode || ! in_array( $header, aafm_proxy_headers(), true ) ) {
		return $off;
	}
	$ranges = array_values( array_filter( (array) ( $value['ranges'] ?? array() ), static fn( $r ): bool => is_string( $r ) && aafm_proxy_range_ok( $r ) ) );
	return array() === $ranges ? $off : array(
		'mode'   => 'custom',
		'header' => $header,
		'ranges' => $ranges,
	);
}

/**
 * The rightmost address in a header value that is not itself a trusted proxy ('' when there is
 * none). Bounded to the last 20 entries.
 *
 * @param string       $header_value Raw header value, comma separated.
 * @param array<mixed> $trusted      Trusted proxy ranges.
 * @return string
 */
function aafm_pick_visitor_ip( string $header_value, array $trusted ): string {
	$parts = array_reverse( array_slice( array_map( 'aafm_trim_unicode', explode( ',', $header_value ) ), -20 ) );
	foreach ( $parts as $part ) {
		$part = aafm_normalize_ip( $part );
		if ( false === filter_var( $part, FILTER_VALIDATE_IP ) ) {
			return ''; // Anything unparseable on the way in means we cannot say who the visitor is.
		}
		if ( ! aafm_ip_in_ranges( $part, $trusted ) ) {
			return $part;
		}
	}
	return '';
}

/**
 * The visitor address when $remote is a trusted proxy that named one; otherwise $remote itself.
 *
 * @param string                                                    $remote The connection address (REMOTE_ADDR).
 * @param array{mode:string,header:string,ranges:list<string>}|null $cfg    A config to test instead of the stored one.
 * @return string
 */
function aafm_trusted_proxy_visitor( string $remote, ?array $cfg = null ): string {
	$cfg = $cfg ?? aafm_trusted_proxy_config();
	if ( 'off' === $cfg['mode'] || ! aafm_ip_in_ranges( aafm_normalize_ip( $remote ), $cfg['ranges'] ) ) {
		return $remote; // Only a request that really arrives from a trusted proxy may name the visitor; anything else is a forged header.
	}
	$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $cfg['header'] ) );
	$value = isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	$ip    = aafm_pick_visitor_ip( $value, $cfg['ranges'] );
	return '' !== $ip ? $ip : $remote;
}

/**
 * Whether agent-created content is forced to draft. Off by default.
 *
 * @return bool
 */
function aafm_force_draft(): bool {
	// An array or an object is not a stored switch, and reads as on.
	$is_on = static function ( $stored ): bool {
		return is_array( $stored ) || is_object( $stored ) || (bool) $stored;
	};

	$raw = get_option( 'aafm_force_draft', false );
	$on  = $is_on( $raw );
	if ( false === $raw ) {
		// Off may be a failed read's default: the row decides, and an unreadable row means on.
		$row = aafm_policy_row( 'aafm_force_draft' );
		$on  = ! $row['ok'] || ( $row['found'] && $is_on( $row['value'] ) );
	}

	// A cache copy that disagrees with the row reads on when either does; an unreadable row means on.
	$row = aafm_policy_row_if_stale( 'aafm_force_draft' );
	if ( null !== $row ) {
		$on = $on || ! $row['ok'] || ( $row['found'] && $is_on( $row['value'] ) );
	}

	/**
	 * Filters whether agent-created content is forced to draft.
	 *
	 * @param bool $force True to force draft status.
	 */
	return (bool) apply_filters( 'aafm_force_draft', $on );
}

/**
 * Maximum allowed title length. 0 (the default) means no cap.
 *
 * @return int Clamped to >= 0.
 */
function aafm_max_title_len(): int {
	// An array or an object is not a stored cap, and caps at 1.
	$cap = static function ( $stored ): int {
		return is_array( $stored ) || is_object( $stored ) ? 1 : max( 0, (int) $stored );
	};

	$raw    = get_option( 'aafm_max_title_len', 0 );
	$stored = $cap( $raw );
	if ( 0 === $raw ) {
		// No cap may be a failed read's default: the row decides, and an unreadable row caps at 1.
		$row    = aafm_policy_row( 'aafm_max_title_len' );
		$stored = ! $row['ok'] ? 1 : ( $row['found'] ? $cap( $row['value'] ) : 0 );
	}

	// A cache copy that disagrees with the row takes the lower positive cap (off only when both
	// are); an unreadable row caps at 1.
	$row = aafm_policy_row_if_stale( 'aafm_max_title_len' );
	if ( null !== $row ) {
		$other  = ! $row['ok'] ? 1 : ( $row['found'] ? $cap( $row['value'] ) : 0 );
		$stored = 0 === $stored || 0 === $other ? max( $stored, $other ) : min( $stored, $other );
	}

	/**
	 * Filters the maximum allowed title length. 0 means no cap.
	 *
	 * @param int $len Stored cap, clamped to >= 0.
	 */
	$filtered = (int) apply_filters( 'aafm_max_title_len', $stored );

	// Re-clamp the post-filter value so a buggy filter returning a negative number can't
	// disable the cap. Mirrors aafm_rate_limit_per_min()'s guard exactly: a filter returning <= 0
	// when a positive cap is stored keeps the stored cap (it cannot silently switch the cap off);
	// a filter may still set any other positive value.
	if ( $filtered < 0 ) {
		return $stored;
	}
	if ( 0 === $filtered && $stored > 0 ) {
		return $stored;
	}
	return $filtered;
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
	// An array or an object is not a stored window, and keeps every entry (0).
	$window = static function ( $stored ): int {
		return is_array( $stored ) || is_object( $stored ) ? 0 : max( 0, min( 3650, (int) $stored ) );
	};
	// The prune deletes rows, so of two windows the one that keeps more wins: 0 (keep forever), or
	// the longer.
	$keeps_more = static function ( int $days, int $stored ): int {
		return 0 === $stored || 0 === $days ? 0 : max( $days, $stored );
	};

	$raw  = get_option( 'aafm_log_retention_days', 30 );
	$days = $window( $raw );
	if ( 30 === $raw ) {
		// 30 may be a failed read's default: the row decides when it keeps more, and an unreadable
		// row prunes nothing.
		$row = aafm_policy_row( 'aafm_log_retention_days' );
		if ( ! $row['ok'] ) {
			return 0;
		}
		$days = $keeps_more( $days, $row['found'] ? $window( $row['value'] ) : $days );
	}

	// A cache copy that disagrees with the row keeps what either keeps; an unreadable row prunes
	// nothing.
	$row = aafm_policy_row_if_stale( 'aafm_log_retention_days' );
	if ( null !== $row ) {
		if ( ! $row['ok'] ) {
			return 0;
		}
		$days = $keeps_more( $days, $row['found'] ? $window( $row['value'] ) : 30 );
	}
	return $days;
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

/**
 * Enforce the max-title-length setting, returning the standard WP_Error when it fails.
 *
 * The chokepoints aafm_insert_post()/aafm_exec_update_post() already run this check on their own
 * behalf. Codex final round 9 MEDIUM: TEC events/venues/organizers and GeoDirectory build
 * their own args arrays instead of routing through either of those, so the setting silently
 * never applied to them. This is the same check, reused rather than re-derived, for those
 * write paths.
 *
 * @param string $title Sanitized title about to be persisted.
 * @return true|WP_Error
 */
function aafm_enforce_title_limit( string $title ) {
	if ( ! aafm_title_within_limit( $title ) ) {
		return new WP_Error( 'aafm_title_too_long', __( 'The title exceeds the maximum allowed length.', 'agent-abilities-for-mcp' ) );
	}
	return true;
}
