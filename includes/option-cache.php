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
 * The return value reports whether every `alloptions`/`notoptions` rewrite this call actually
 * attempted was accepted by `wp_cache_set()`. A drop-in that reports failure here (a remote write
 * that could not complete) means the blob rewrite this function exists to make did not really
 * happen, so a caller certifying a write must not treat the cache as repaired (Codex hotfix
 * re-check, finding 6 remainder) - see aafm_option_write_certified().
 *
 * @param string $option Option name.
 * @return bool True when every cache rewrite this call attempted was accepted; false if any
 *              `wp_cache_set()` call for `alloptions` or `notoptions` reported failure. True when
 *              no rewrite was needed (the option was not present in either blob to begin with).
 */
function aafm_forget_option_caches( string $option ): bool {
	wp_cache_delete( $option, 'options' );

	$rewrites_ok = true;

	// Force the read past the runtime copy so a stale persistent entry is what gets rewritten.
	$all = wp_cache_get( 'alloptions', 'options', true );
	if ( is_array( $all ) && array_key_exists( $option, $all ) ) {
		unset( $all[ $option ] );
		if ( ! wp_cache_set( 'alloptions', $all, 'options' ) ) {
			$rewrites_ok = false;
		}
	}

	$not = wp_cache_get( 'notoptions', 'options', true );
	if ( is_array( $not ) && isset( $not[ $option ] ) ) {
		unset( $not[ $option ] );
		if ( ! wp_cache_set( 'notoptions', $not, 'options' ) ) {
			$rewrites_ok = false;
		}
	}

	return $rewrites_ok;
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
 * @return bool Whatever aafm_forget_option_caches() reports for this option (see its docblock);
 *              true unless a cache rewrite it attempted was rejected.
 */
function aafm_delete_option_cache_safe( string $option ): bool {
	delete_option( $option );
	return aafm_forget_option_caches( $option );
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
 * Read $option as two independent, uninterpreted views - the object cache's and the database's -
 * instead of folding them into `get_option()`'s single, cache-trusting answer.
 *
 * The cache view is whichever of the per-option key (`wp_cache_get( $option, 'options', true,
 * $found )`, the same forced non-autoload cache `get_option()` itself consults) or the forced
 * `alloptions` blob actually holds the option; the per-option key is checked first because that is
 * the one `update_option()` writes to for a non-autoloaded option. Both stores hold
 * `maybe_serialize()`'s output, not the caller's native value - `add_option()` and
 * `update_option()` (wp-includes/option.php) write `$serialized_value` into whichever of the two
 * they use - so whichever view answers is passed through `maybe_unserialize()` before it is
 * returned, the same conversion `get_option()` itself applies on its own read.
 *
 * The database view is a direct, uncached `$wpdb` read of the row for the current blog - the one
 * source `aafm_uninstall_should_delete_data()` already trusts over any cache for its own,
 * higher-stakes decision, for the same reason: a cache is not the ground truth. This is the only
 * function outside that one allowed to cost that extra query, because it exists specifically to
 * certify a write the plugin just made.
 *
 * @param string $option Option name.
 * @return array{db_found:bool,db_value:mixed,cache_found:bool,cache_value:mixed} db_found/db_value
 *              describe the row for the current blog (db_value is false when the row is absent);
 *              cache_found/cache_value describe whichever of the per-option key or the alloptions
 *              blob answered for the option (cache_value is null when neither did).
 */
function aafm_read_option_views( string $option ): array {
	global $wpdb;

	// Both the per-option cache and the alloptions blob store `maybe_serialize()`'s output, not the
	// caller's native value - `add_option()` and `update_option()` (wp-includes/option.php) both
	// write `$serialized_value` into whichever of the two they use. `maybe_unserialize()` is a
	// no-op for a scalar that was never actually serialized (e.g. a bool or int), so applying it
	// unconditionally to whichever view answers is correct for both.
	$cache_found = false;
	$cache_value = wp_cache_get( $option, 'options', true, $cache_found );
	$cache_found = (bool) $cache_found; // The by-ref $found param is typed nullable upstream; a cache miss is always false, never null.

	if ( $cache_found ) {
		$cache_value = maybe_unserialize( $cache_value );
	} else {
		$all = wp_cache_get( 'alloptions', 'options', true );
		if ( is_array( $all ) && array_key_exists( $option, $all ) ) {
			$cache_found = true;
			$cache_value = maybe_unserialize( $all[ $option ] );
		} else {
			$cache_value = null;
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately bypassing the object cache; certification must be checked against the row itself, mirroring aafm_uninstall_should_delete_data()'s reasoning.
	$raw      = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $option ) );
	$db_found = null !== $raw;

	return array(
		'db_found'    => $db_found,
		'db_value'    => $db_found ? maybe_unserialize( $raw ) : false,
		'cache_found' => $cache_found,
		'cache_value' => $cache_value,
	);
}

/**
 * Type-aware equality between a value read back from storage and the value a caller intended to
 * store, mirroring how WordPress itself round-trips each type.
 *
 * An array or object round-trips through `serialize()`/`unserialize()` with its element types
 * intact, so a strict comparison there is exact. A scalar is not: WordPress never restores it to
 * the caller's original PHP type, only to whatever it actually stored - a passed-in int `0` reads
 * back as the string `'0'`, `true` as `'1'`, `false` as `''`. Comparing a scalar as WordPress
 * itself renders it, rather than against the caller's native type, is what keeps this from
 * reporting a perfectly persisted `0` or `false` as a mismatch.
 *
 * @param mixed $stored   Value read back from a cache view or the database.
 * @param mixed $expected Value the caller intended to store.
 * @return bool
 */
function aafm_option_value_matches( $stored, $expected ): bool {
	if ( is_array( $expected ) || is_object( $expected ) ) {
		return $stored === $expected;
	}
	return (string) $stored === (string) $expected;
}

/**
 * Certify whether an option write actually took, checking the database row and the object cache's
 * own forced views directly rather than trusting a subsequent, unforced `get_option()` read.
 *
 * `get_option()` is convenient but not sufficient here: even after
 * `aafm_force_refresh_option_caches()`, a drop-in that primes its own runtime cache with a new
 * value before its remote `set()` call's result is known can make a later `get_option()` in the
 * same request look repaired even when the remote write itself failed (see
 * aafm_forget_option_caches()'s docblock and the module-level note at the top of this file) - the
 * outstanding gap in the Codex hotfix re-check (finding 6). Certifying instead against
 * aafm_read_option_views()'s two independent reads closes that: the database view cannot be primed
 * by a runtime cache at all, and the cache view here is the same forced read `get_option()` itself
 * would use, kept separate rather than folded into one answer.
 *
 * Success requires the database row to match the intent - the row is authoritative, exactly as
 * aafm_uninstall_should_delete_data() already treats it - AND, when the object cache holds any
 * view of the option at all, that view must not contradict the intent. A cache view that holds
 * nothing for the option is not a contradiction either way: it just means nothing is cached yet,
 * and the database is what a fresh read would land on next. A cache view that DOES hold something
 * is checked against the intent for both directions - a present intent whose cached value differs,
 * and an absent intent that the cache still claims exists at all.
 *
 * One case with a non-absent intent has no row to check against a value at all: `update_option()`
 * itself (wp-includes/option.php) skips writing anything - no INSERT, no UPDATE - when the value
 * passed in already equals what `get_option()` would return for the option today, and for an
 * option that has never been written that "today" value is its registered default (the
 * `default_option_{$option}` filter, false when nothing has registered one - the exact check
 * `update_option()` runs before deciding there is nothing to do). A caller storing an ordinary
 * setting's own off state - `aafm_force_draft`, say - for the very first time is exactly this: no
 * write was ever attempted, so there is no row and never was going to be one, and that is the
 * correct end state, not a failure to certify against. So a row-absent, non-absent-intent
 * certification falls back to comparing the intent against that same registered default rather
 * than automatically failing.
 *
 * A caller also passes in what aafm_forget_option_caches() reported for every rewrite it attempted
 * during this write (see that function's docblock); a reported cache-rewrite failure means the
 * blob this function reads may still be the stale one that failure left behind, so the caller
 * should treat the whole write as uncertified rather than call this function at all in that case.
 *
 * @param string $option        Option name.
 * @param mixed  $expected      Value the write intended to store. Ignored when $expect_absent.
 * @param bool   $expect_absent True when the intended state is "no row for this option" (the off
 *                               branch of aafm_persist_operator_switch()) rather than a stored value.
 * @return bool
 */
function aafm_option_write_certified( string $option, $expected, bool $expect_absent = false ): bool {
	$views = aafm_read_option_views( $option );

	if ( $expect_absent ) {
		if ( $views['db_found'] ) {
			return false;
		}
	} elseif ( ! $views['db_found'] ) {
		// This mirrors the exact "nothing to do" check update_option() itself runs before an
		// INSERT/UPDATE - see the docblock above.
		$default = apply_filters( "default_option_{$option}", false, $option, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mirroring core's own dynamic hook name (wp-includes/option.php), not a hook this plugin defines.
		if ( ! aafm_option_value_matches( $default, $expected ) ) {
			return false;
		}
	} elseif ( ! aafm_option_value_matches( $views['db_value'], $expected ) ) {
		return false;
	}

	if ( ! $views['cache_found'] ) {
		return true;
	}

	if ( $expect_absent ) {
		// Something is still cached for an option whose row is gone - exactly the stale entry this
		// module exists to catch (see the module docblock).
		return false;
	}

	return aafm_option_value_matches( $views['cache_value'], $expected );
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
 * runtime copy (aafm_force_refresh_option_caches()) before certification, so the return value says
 * whether the switch is now really in the requested state. A caller must not log or report success
 * when it is not: the live bug was five "turned off" rows in the activity log for a switch that
 * never moved.
 *
 * The return value comes from aafm_option_write_certified(), not from a subsequent get_option()
 * (see that function's docblock for why an unforced read is not enough on its own). get_option()
 * is still called afterward so this request's own runtime cache is left holding the same value the
 * rest of the plugin will read for the remainder of it - callers elsewhere rely on that read
 * reflecting the write this function just made - it is simply no longer where the boolean this
 * function returns comes from.
 *
 * @param string $option Option name.
 * @param bool   $on     Whether the switch should be on.
 * @return bool True when the option now certifies as $on.
 */
function aafm_persist_operator_switch( string $option, bool $on ): bool {
	if ( $on ) {
		$forgot_before = aafm_forget_option_caches( $option );
		update_option( $option, true );
		$forgot_after = aafm_forget_option_caches( $option );
		$caches_ok    = $forgot_before && $forgot_after;
	} else {
		$caches_ok = aafm_delete_option_cache_safe( $option );
	}

	aafm_force_refresh_option_caches( $option );
	get_option( $option, false );

	if ( ! $caches_ok ) {
		return false;
	}

	return $on
		? aafm_option_write_certified( $option, true )
		: aafm_option_write_certified( $option, false, true );
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
 * The certification itself is aafm_option_write_certified(), which checks the database row and the
 * object cache's own forced views directly, not a bare `===` of the caller's value against a
 * subsequent `get_option()` read - see that function's docblock for why an unforced read is not
 * enough on its own. get_option() is still called afterward so this request's own runtime cache is
 * left holding the same value the rest of the plugin will read for the remainder of it - callers
 * elsewhere rely on that read reflecting the write this function just made - it is simply no
 * longer where the boolean this function returns comes from.
 *
 * @param string $option Option name.
 * @param mixed  $value  New value to store.
 * @return bool True when the option now certifies as $value.
 */
function aafm_update_option_verified( string $option, $value ): bool {
	$caches_ok = aafm_forget_option_caches( $option );
	update_option( $option, $value );
	$caches_ok = aafm_forget_option_caches( $option ) && $caches_ok;
	aafm_force_refresh_option_caches( $option );

	get_option( $option );

	if ( ! $caches_ok ) {
		return false;
	}

	return aafm_option_write_certified( $option, $value );
}
