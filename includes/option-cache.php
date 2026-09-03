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
 * other plugin's cached options stay exactly as they were - as long as nothing else writes to
 * `alloptions` or `notoptions` between this function's read and its write. That gap is not new:
 * core's own `update_option()` and `delete_option()` read-modify-write the same two blobs with no
 * lock or compare-and-swap, so this helper carries the same non-atomicity core already has, not a
 * new one. On these rare operator/reset/uninstall actions the exposure is a stale value from a
 * request that lost a race, self-correcting on the next read of that option; a portable fix would
 * need backend-specific CAS or locking this plugin cannot add without depending on which drop-in
 * is in play, so it is accepted rather than "fixed" by dropping the whole blob on every call.
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
 * Force every read of $option, of the `alloptions` blob, and of the `notoptions` list, past
 * whatever this request's own runtime cache still remembers, so a certification below reflects
 * what the persistent cache backend itself is holding rather than a copy this process primed
 * earlier in the same request.
 *
 * `notoptions` is not a minor third cache entry here: `get_option()` consults it BEFORE ever
 * querying the database (wp-includes/option.php) - if the option's name is still listed there, the
 * function returns its default and never reaches the database at all, no matter what the row now
 * holds. A write that turns a previously-absent option into a real row (the exact shape of
 * aafm_persist_operator_switch()'s ON branch) is invisible to a read-back that only refreshes
 * `alloptions` and the per-option key: the stale `notoptions` entry alone is enough to make a
 * successful write certify as failed.
 *
 * `wp_cache_get( ..., true )`'s `$force` argument is what a drop-in is supposed to honor for this;
 * the bundled Redis drop-in and Automattic's Memcached drop-in both do (see the module docblock
 * for aafm_forget_option_caches()). A drop-in that puts a new value in its runtime cache before its
 * remote `set()` call's result is known can still make this read look repaired for the rest of
 * this request even when the remote write itself failed - see aafm_forget_option_caches()'s
 * docblock and the module-level note above it; there is nothing further this plugin can check from
 * inside a single PHP request to close that last gap.
 *
 * @param string $option Option name.
 * @return void
 */
function aafm_force_refresh_option_caches( string $option ): void {
	wp_cache_get( $option, 'options', true );
	wp_cache_get( 'alloptions', 'options', true );
	wp_cache_get( 'notoptions', 'options', true );
}

/**
 * Persist an operator switch and prove it took, whatever the object cache was holding.
 *
 * On stores an explicit true; off deletes the row, because off is the option's out-of-the-box
 * state (the rule the high-risk and read-only switches have followed since 1.5.0).
 *
 * The on branch forgets the option's caches BEFORE writing, not only after. `update_option()`
 * decides whether there is anything to do, and whether to INSERT or UPDATE, from `get_option()`'s
 * cached idea of the current value (wp-includes/option.php) - with the database row absent and a
 * stale cache still claiming the option is already on, that pre-write read made `update_option()`
 * run an UPDATE against a row that does not exist, get zero affected rows, return false, and
 * return before touching any cache at all, leaving the stale entry in place for this function's
 * own after-write forget to clear a moment later with nothing to show for the write. Forgetting
 * first means `update_option()` sees the database's real prior state and takes the INSERT branch
 * instead, so "cache says on, row absent, operator requests on" is repaired in the one call instead
 * of needing a second attempt after the first one's cleanup fixes the cache for next time.
 *
 * After either branch, the caches are forgotten again and force-refreshed past this request's own
 * runtime copy (aafm_force_refresh_option_caches()) before the value is read back, so the return
 * value says whether the switch is now really in the requested state. A caller must not log or
 * report success when it is not: the live bug was five "turned off" rows in the activity log for a
 * switch that never moved.
 *
 * @param string $option Option name.
 * @param bool   $on     Whether the switch should be on.
 * @return bool True when the option now reads back as $on.
 */
function aafm_persist_operator_switch( string $option, bool $on ): bool {
	if ( $on ) {
		aafm_forget_option_caches( $option );
		update_option( $option, true );
		aafm_forget_option_caches( $option );
	} else {
		aafm_delete_option_cache_safe( $option );
	}

	aafm_force_refresh_option_caches( $option );

	return ( (bool) get_option( $option, false ) ) === $on;
}

/**
 * Write an option through `update_option()` and prove the write actually took, whatever the
 * object cache was holding beforehand.
 *
 * The same class of bug as aafm_persist_operator_switch() (see that function's docblock), but for
 * an ordinary configuration option where "off" is ITS OWN stored value - an empty array, the
 * string '0', a zero - rather than the row's absence, so every value goes through `update_option()`
 * and none through `delete_option()`. Caches are forgotten before the write (so `update_option()`
 * reads the database's real prior state, not a stale cached one, when it decides whether anything
 * changed and whether to INSERT or UPDATE) and forgotten and force-refreshed again afterward,
 * before the read-back that this function's return value is based on.
 *
 * The read-back comparison is type-aware, not a bare `===` of the caller's value against
 * `get_option()`'s return. An array or object round-trips through `serialize()`/`unserialize()`
 * with its element types intact, so a strict comparison there is exact. A scalar is not: WordPress
 * never restores it to the caller's original PHP type, only to whatever it actually stored - a
 * passed-in int `0` reads back as the string `'0'`, `true` as `'1'`, `false` as `''` (its own
 * `(string)` cast, on the way into a query built for a string column). Comparing a scalar as
 * WordPress itself renders it, rather than against the caller's native type, is what keeps this
 * from reporting a perfectly persisted `0` or `false` as a failed write.
 *
 * @param string $option Option name.
 * @param mixed  $value  New value to store.
 * @return bool True when the option now reads back as $value.
 */
function aafm_update_option_verified( string $option, $value ): bool {
	aafm_forget_option_caches( $option );
	update_option( $option, $value );
	aafm_forget_option_caches( $option );
	aafm_force_refresh_option_caches( $option );

	$stored = get_option( $option );

	if ( is_array( $value ) || is_object( $value ) ) {
		return $stored === $value;
	}

	return (string) $stored === (string) $value;
}
