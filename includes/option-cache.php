<?php
/**
 * Option writes that stay correct under a persistent object cache.
 *
 * Loaded by the plugin bootstrap and by uninstall.php, so it must not depend on anything else in
 * the plugin.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove one option from every object-cache entry that could still answer for it.
 *
 * WordPress keeps options in three cache entries: the per-option key, the `alloptions` blob (one
 * array holding every autoloaded option), and the `notoptions` list of names known to be absent.
 * `get_option()` trusts the blob without consulting the database, and `delete_option()` queries
 * the database first and returns before touching any cache when it finds no row. So a persistent
 * cache (Redis, Memcached, a host's drop-in) that still lists an option after its row is gone
 * keeps serving the old value on every request, and nothing the plugin writes through the normal
 * option API can ever correct it. Seen live on 2026-09-03: read-only mode could not be turned off.
 *
 * This touches only the one name. The blob is rewritten without that key, the same way core's
 * own `delete_option()` rewrites it on a successful delete, rather than dropped wholesale; every
 * other plugin's cached options stay exactly as they were.
 *
 * @param string $option Option name.
 * @return void
 */
function aafm_forget_option_caches( string $option ): void {
	wp_cache_delete( $option, 'options' );

	// Force the read past the runtime copy so a stale persistent entry is what gets rewritten.
	$all = wp_cache_get( 'alloptions', 'options', true );
	if ( is_array( $all ) && array_key_exists( $option, $all ) ) {
		unset( $all[ $option ] );
		wp_cache_set( 'alloptions', $all, 'options' );
	}

	$not = wp_cache_get( 'notoptions', 'options', true );
	if ( is_array( $not ) && isset( $not[ $option ] ) ) {
		unset( $not[ $option ] );
		wp_cache_set( 'notoptions', $not, 'options' );
	}
}

/**
 * Delete an option and make sure no cache entry keeps answering for it.
 *
 * `delete_option()` alone is enough when the cache agrees with the database. When a persistent
 * cache still lists the option after the row is gone, core returns without touching the cache and
 * the delete silently does nothing (see aafm_forget_option_caches()). The follow-up forget makes
 * the outcome the same in both cases.
 *
 * @param string $option Option name.
 * @return void
 */
function aafm_delete_option_cache_safe( string $option ): void {
	delete_option( $option );
	aafm_forget_option_caches( $option );
}

/**
 * Persist an operator switch and prove it took, whatever the object cache was holding.
 *
 * On stores an explicit true; off deletes the row, because off is the option's out-of-the-box
 * state (the rule the high-risk and read-only switches have followed since 1.5.0). After the write
 * the option's own cache entries are cleared and the value is read back, so the return value says
 * whether the switch is now really in the requested state. A caller must not log or report success
 * when it is not: the live bug was five "turned off" rows in the activity log for a switch that
 * never moved.
 *
 * @param string $option Option name.
 * @param bool   $on     Whether the switch should be on.
 * @return bool True when the option now reads back as $on.
 */
function aafm_persist_operator_switch( string $option, bool $on ): bool {
	if ( $on ) {
		update_option( $option, true );
		aafm_forget_option_caches( $option );
	} else {
		aafm_delete_option_cache_safe( $option );
	}

	return ( (bool) get_option( $option, false ) ) === $on;
}
