<?php
/**
 * OAuth HTTP helpers: transport-security policy and request rate limiting.
 *
 * Pure-ish helpers shared by the OAuth endpoints. They depend only on WordPress
 * primitives (environment type, the object cache, transients) plus aafm_source_ip()
 * from the audit log module.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_OAUTH_RATE_WINDOW' ) ) {
	define( 'AAFM_OAUTH_RATE_WINDOW', 60 );
}

/**
 * Whether OAuth endpoints must be served over HTTPS.
 *
 * HTTPS is mandatory in production. It is relaxed only on a local or development
 * environment, or when the AAFM_OAUTH_ALLOW_HTTP override constant is set true -
 * both intended for local agent development against http://localhost.
 *
 * @return bool True when HTTPS is required, false when plain HTTP is tolerated.
 */
function aafm_oauth_https_required(): bool {
	if ( defined( 'AAFM_OAUTH_ALLOW_HTTP' ) && AAFM_OAUTH_ALLOW_HTTP ) {
		return false;
	}

	if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
		return false;
	}

	return true;
}

/**
 * Fixed-window rate limiter keyed on a bucket name, enforced per-IP and globally.
 *
 * Two counters share a 60-second window: one scoped to the caller's source IP and
 * the bucket, one scoped to the bucket alone (a global ceiling across all callers).
 * Each counter lives in a transient, so the limit holds on the default in-memory
 * object cache where every request is a fresh process. The request is allowed only
 * while both counters remain at or below their limits.
 *
 * @param string $bucket  Logical action name, e.g. 'register' or 'token'.
 * @param int    $per_ip  Maximum requests per IP per window.
 * @param int    $global  Maximum requests across all IPs per window.
 * @return bool True when the request is within both limits, false once either is exceeded.
 *
 * phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.globalFound -- $global is the contracted parameter name later OAuth PRs call against.
 */
function aafm_oauth_rate_ok( string $bucket, int $per_ip, int $global ): bool {
	// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.globalFound
	$window = AAFM_OAUTH_RATE_WINDOW;
	$ip     = aafm_source_ip();

	$ip_key     = 'rl_ip_' . md5( $bucket . '|' . $ip );
	$global_key = 'rl_all_' . md5( $bucket );

	$ip_count     = aafm_oauth_bump_counter( $ip_key, $window );
	$global_count = aafm_oauth_bump_counter( $global_key, $window );

	return $ip_count <= $per_ip && $global_count <= $global;
}

/**
 * Increment a fixed-window counter and return its new value.
 *
 * The transient is the single source of truth: read it, add one, write it back. On the default
 * in-memory object cache (most installs, all shared hosting) every request is a fresh process, so
 * the transient in the options table is the only store that survives between requests. An earlier
 * version seeded the counter with wp_cache_add() first and only read the transient on a
 * wp_cache_incr() miss that never fires on a fresh process, so the counter reset to 1 every request
 * and the limit never tripped. Sibling counters aafm_rate_limit_consume() (includes/safety.php) and
 * the failed-auth logger (includes/audit/log.php) use this same transient-authoritative read.
 *
 * The read-modify-write is non-atomic, so under heavy concurrency the window may slightly
 * undercount. That is acceptable: this is a coarse defensive throttle on public endpoints, not a
 * hard quota, and it is the same tradeoff the sibling counters accept.
 *
 * @param string $key    Transient key (already namespaced to a bucket).
 * @param int    $window Window length in seconds.
 * @return int The counter value after this hit.
 */
function aafm_oauth_bump_counter( string $key, int $window ): int {
	$transient_key = 'aafm_oauth_' . $key;

	$count = (int) get_transient( $transient_key ) + 1;
	set_transient( $transient_key, $count, $window );

	return $count;
}
