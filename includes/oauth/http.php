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
 * Counters live in the object cache for speed and are mirrored to a transient so the
 * limit still holds without a persistent object cache. The request is allowed only
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
 * Seeds the counter at 1 with wp_cache_add() (which only writes when the key is
 * absent, so it naturally starts a fresh window), then increments on subsequent
 * hits. The value is mirrored into a transient with the same TTL so the limit
 * survives on sites without a persistent object cache.
 *
 * @param string $key    Cache/transient key (already namespaced to a bucket).
 * @param int    $window Window length in seconds.
 * @return int The counter value after this hit.
 */
function aafm_oauth_bump_counter( string $key, int $window ): int {
	$group         = 'aafm_oauth';
	$transient_key = 'aafm_oauth_' . $key;

	// First hit in a window: seed both stores at 1 and return.
	if ( wp_cache_add( $key, 1, $group, $window ) ) {
		set_transient( $transient_key, 1, $window );
		return 1;
	}

	// Subsequent hits: bump the cache counter.
	$count = wp_cache_incr( $key, 1, $group );

	// Without a persistent object cache, wp_cache_incr() can miss the seeded value;
	// fall back to the transient mirror as the source of truth.
	if ( false === $count ) {
		$count = (int) get_transient( $transient_key ) + 1;
		wp_cache_set( $key, $count, $group, $window );
	}

	set_transient( $transient_key, $count, $window );

	return (int) $count;
}
