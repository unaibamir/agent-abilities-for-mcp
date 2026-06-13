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
	/**
	 * Filters the requests-per-minute rate limit. 0 means no limit.
	 *
	 * @param int $limit Stored limit, clamped to >= 0.
	 */
	return (int) apply_filters( 'aafm_rate_limit_per_min', max( 0, (int) get_option( 'aafm_rate_limit_per_min', 0 ) ) );
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
	return $normalize( apply_filters( 'aafm_ip_allowlist', $stored ) );
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
 * Whether an IP is permitted by the allowlist.
 *
 * An empty allowlist is the neutral default and permits everyone. A non-empty
 * allowlist restricts access to matching entries only — and because every entry
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
