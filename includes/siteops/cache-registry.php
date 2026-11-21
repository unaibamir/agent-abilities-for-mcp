<?php
/**
 * Site Ops: the cache adapter registry, detection, the two file confirmation sequences and the
 * fan-out dispatcher.
 *
 * This file is loaded on every request and stays cheap: detection is symbol tests only, and an
 * adapter file is loaded only for a detected plugin, on first use. An adapter file is the only
 * place its vendor is called, and every adapter function is called through
 * aafm_siteops_vendor_call().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Load the three helper files the cache tools need. The first line of each cache ability's
 * execute callback.
 *
 * @return void
 */
function aafm_siteops_load(): void {
	require_once __DIR__ . '/url.php';
	require_once __DIR__ . '/vendor-call.php';
	require_once __DIR__ . '/budget.php';
}

/**
 * The cache adapters, in fan-out order. Adapters that can confirm a purge come first, so a
 * wall-time cut-off drops the ones that cannot.
 *
 * Keys of an entry: label (a product name, not translated); plugins (basenames, for the
 * integration card and the unsupported_version rule); detect (symbol tests that must all pass);
 * ops (operations the adapter supports); confirm ('file' when the adapter can at best confirm a
 * purge on disk, 'none' when it never can); vendor_http (the vendor can send a request inside our
 * call); recursive_url (its one-URL purge also removes pages under that URL); rewarms (its own
 * purge can cache the page again before it returns); cli (it works in a WP-CLI process).
 *
 * @return array<string,array<string,mixed>> Slug to entry. Every built-in entry has exactly the nine keys above.
 */
function aafm_site_cache_adapters(): array {
	$all = array( 'post', 'url', 'all' );

	$adapters = array(
		'wp_super_cache'    => array(
			'label'         => 'WP Super Cache',
			'plugins'       => array( 'wp-super-cache/wp-cache.php' ),
			'detect'        => array(
				array( 'function', 'wp_cache_clear_cache' ),
				array( 'function', 'wpsc_delete_post_cache' ),
				array( 'function', 'wpsc_delete_url_cache' ),
				array( 'function', 'get_supercache_dir' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => false,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
		'wp_fastest_cache'  => array(
			'label'         => 'WP Fastest Cache',
			'plugins'       => array( 'wp-fastest-cache/wpFastestCache.php' ),
			'detect'        => array(
				array( 'class', 'WpFastestCache' ),
				array( 'function', 'wpfc_clear_post_cache_by_id' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
		'w3_total_cache'    => array(
			'label'         => 'W3 Total Cache',
			'plugins'       => array( 'w3-total-cache/w3-total-cache.php' ),
			'detect'        => array(
				array( 'constant', 'W3TC' ),
				array( 'function', 'w3tc_config' ),
				array( 'function', 'w3tc_flush_post' ),
			),
			'ops'           => $all,
			'confirm'       => 'none',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
		'wp_optimize'       => array(
			'label'         => 'WP-Optimize',
			'plugins'       => array( 'wp-optimize/wp-optimize.php' ),
			'detect'        => array(
				array( 'function', 'WP_Optimize' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => false,
		),
		'cache_enabler'     => array(
			'label'         => 'Cache Enabler',
			'plugins'       => array( 'cache-enabler/cache-enabler.php' ),
			'detect'        => array(
				array( 'constant', 'CACHE_ENABLER_VERSION' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => false,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
		'hummingbird'       => array(
			'label'         => 'Hummingbird',
			'plugins'       => array( 'hummingbird-performance/wp-hummingbird.php' ),
			'detect'        => array(
				array( 'constant', 'WPHB_VERSION' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => false,
		),
		'jetpack_boost'     => array(
			'label'         => 'Jetpack Boost',
			'plugins'       => array( 'jetpack-boost/jetpack-boost.php' ),
			'detect'        => array(
				array( 'constant', 'JETPACK_BOOST_VERSION' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => false,
			'recursive_url' => true,
			'rewarms'       => false,
			'cli'           => false,
		),
		'speedycache'       => array(
			'label'         => 'SpeedyCache',
			'plugins'       => array( 'speedycache/speedycache.php' ),
			'detect'        => array(
				array( 'constant', 'SPEEDYCACHE_VERSION' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => false,
		),
		'breeze'            => array(
			'label'         => 'Breeze (file cache)',
			'plugins'       => array( 'breeze/breeze.php' ),
			'detect'        => array(
				array( 'constant', 'BREEZE_VERSION' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
		'siteground'        => array(
			'label'         => 'Speed Optimizer',
			'plugins'       => array( 'sg-cachepress/sg-cachepress.php' ),
			'detect'        => array(
				array( 'function', 'sg_cachepress_purge_cache' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => true,
			'cli'           => true,
		),
		'litespeed'         => array(
			'label'         => 'LiteSpeed Cache',
			'plugins'       => array( 'litespeed-cache/litespeed-cache.php' ),
			'detect'        => array(
				array( 'constant', 'LSCWP_V' ),
			),
			'ops'           => $all,
			'confirm'       => 'none',
			'vendor_http'   => false,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => false,
		),
		'nginx_helper'      => array(
			'label'         => 'Nginx Helper',
			'plugins'       => array( 'nginx-helper/nginx-helper.php' ),
			'detect'        => array(
				array( 'constant', 'NGINX_HELPER_BASENAME' ),
			),
			'ops'           => $all,
			'confirm'       => 'file',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
		'proxy_cache_purge' => array(
			'label'         => 'Proxy Cache Purge',
			'plugins'       => array( 'varnish-http-purge/varnish-http-purge.php' ),
			'detect'        => array(
				array( 'class', 'VarnishPurger' ),
			),
			'ops'           => $all,
			'confirm'       => 'none',
			'vendor_http'   => true,
			'recursive_url' => false,
			'rewarms'       => false,
			'cli'           => true,
		),
	);

	/**
	 * Filters the cache adapter registry. A seam for tests, which register fake adapters here.
	 *
	 * @param array<string,array<string,mixed>> $adapters Slug to registry entry.
	 */
	$filtered = apply_filters( 'aafm_site_cache_adapters', $adapters );
	return is_array( $filtered ) ? $filtered : $adapters;
}

/**
 * Whether every detect test of one registry entry passes. No file load, no vendor call.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @return bool
 */
function aafm_site_cache_detected( array $entry ): bool {
	$tests = isset( $entry['detect'] ) && is_array( $entry['detect'] ) ? $entry['detect'] : array();
	foreach ( $tests as $test ) {
		if ( ! is_array( $test ) || ! isset( $test[0], $test[1] ) || ! is_string( $test[1] ) ) {
			return false;
		}
		switch ( $test[0] ) {
			case 'function':
				$passes = function_exists( $test[1] );
				break;
			case 'class':
				$passes = class_exists( $test[1], false );
				break;
			case 'constant':
				$passes = defined( $test[1] );
				break;
			default:
				$passes = false;
		}
		if ( ! $passes ) {
			return false;
		}
	}
	return true;
}

/**
 * Whether any of these plugin basenames is an active plugin on this site or, on multisite,
 * across the network.
 *
 * @param array<int,mixed> $basenames Plugin basenames.
 * @return bool
 */
function aafm_siteops_plugin_active( array $basenames ): bool {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	return array() !== array_intersect( array_map( 'strval', $basenames ), array_map( 'strval', $active ) );
}

/**
 * The result of one adapter's purge. The only shape an adapter returns.
 *
 * The state purged means: a cached copy was found before the call and no copy from before the
 * call is left; reason recached adds that the plugin has already written a new one.
 *
 * @param string    $state      purged | requested | failed | skipped.
 * @param string    $reason     '' or 'recached' for purged; a reason code otherwise.
 * @param bool|null $was_cached True or false when a file check ran before the call, null when unknowable.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_site_cache_result( string $state, string $reason, ?bool $was_cached ): array {
	return array(
		'state'      => $state,
		'reason'     => $reason,
		'was_cached' => $was_cached,
	);
}

/**
 * Whether the four functions of one adapter exist, loading its file first when that is needed.
 *
 * The file of slug wp_super_cache is includes/siteops/cache/wp-super-cache.php.
 *
 * @param string $slug Registry slug.
 * @return bool
 */
function aafm_site_cache_adapter_ready( string $slug ): bool {
	if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $slug ) ) {
		return false;
	}
	$functions = array(
		'aafm_cache_' . $slug . '_serving',
		'aafm_cache_' . $slug . '_purge_post',
		'aafm_cache_' . $slug . '_purge_url',
		'aafm_cache_' . $slug . '_purge_all',
	);

	$missing = array_filter(
		$functions,
		static function ( string $name ): bool {
			return ! function_exists( $name );
		}
	);
	if ( array() === $missing ) {
		return true;
	}

	$file = __DIR__ . '/cache/' . str_replace( '_', '-', $slug ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
	foreach ( $functions as $name ) {
		if ( ! function_exists( $name ) ) {
			return false;
		}
	}
	return true;
}

/**
 * One answer from several yes, no or unknown answers: true when any is true, otherwise null when
 * any is unknown, otherwise false.
 *
 * @param array<int|string,bool|null> $answers Answers.
 * @return bool|null
 */
function aafm_site_cache_any( array $answers ): ?bool {
	if ( in_array( true, $answers, true ) ) {
		return true;
	}
	return in_array( null, $answers, true ) ? null : false;
}

/**
 * The one-page sequence: the only way an adapter confirms a post or URL purge by file.
 *
 * It looks at the vendor's cache before and after the vendor's own purge and never deletes,
 * renames or touches another plugin's cache file itself. With $rewarms true a file the vendor
 * wrote during its own purge, proven by a path that did not exist before or by a different
 * inode, is a new copy and does not count as left; a file with the old inode and a changed
 * mtime proves nothing and is never called new.
 *
 * @param array<int,mixed> $dirs      Every place the vendor may keep this page: array( base, relative ) pairs.
 * @param string           $regex     Matches the page's cache file names. Never input.
 * @param callable         $vendor_fn The vendor purge call. No arguments.
 * @param bool             $rewarms   The vendor can re-create the page's cache file inside its own purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_site_cache_file_confirmed( array $dirs, string $regex, callable $vendor_fn, bool $rewarms = false ): array {
	// 1. Drop duplicate pairs. 2. Resolve each pair whose base exists and prove it is inside its base.
	$pairs = array();
	foreach ( $dirs as $pair ) {
		if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && is_string( $pair[0] ) && is_string( $pair[1] ) ) {
			$pairs[ $pair[0] . "\n" . $pair[1] ] = array( $pair[0], $pair[1] );
		}
	}
	$paths = array();
	foreach ( $pairs as $pair ) {
		if ( ! is_dir( $pair[0] ) ) {
			continue;
		}
		$path = aafm_siteops_confined_path( $pair[0], $pair[1] );
		if ( null === $path ) {
			return aafm_site_cache_result( 'failed', 'vendor_error', null );
		}
		$paths[ $path ] = $path;
	}
	if ( array() === $paths ) {
		// We cannot see the vendor's cache, so nothing can be confirmed.
		$vendor_fn();
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}

	// 3. Before. A failed identity scan is kept: nothing found later can be compared with it.
	$before    = array();
	$seen      = array();
	$stat_lost = false;
	foreach ( $paths as $path ) {
		$before[ $path ] = aafm_siteops_cache_files_present( $path, $regex );
		if ( $rewarms ) {
			$stat = aafm_siteops_cache_files_stat( $path, $regex );
			if ( null === $stat ) {
				$stat_lost = true;
				continue;
			}
			foreach ( $stat as $name => $identity ) {
				$seen[ $path . '/' . $name ] = $identity;
			}
		}
	}
	$had = aafm_site_cache_any( $before );

	// 4. The vendor's own purge.
	$vendor_fn();

	// Without the identities from before the call, no file found now is known to be new or old.
	if ( $stat_lost ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}

	// 5. After. With the re-cache rule only an old copy counts as left.
	$after       = array();
	$new_copies  = 0;
	$unconfirmed = 0;
	foreach ( $paths as $path ) {
		if ( ! $rewarms ) {
			$after[ $path ] = aafm_siteops_cache_files_present( $path, $regex );
			continue;
		}
		$stat = aafm_siteops_cache_files_stat( $path, $regex );
		if ( null === $stat ) {
			$after[ $path ] = null;
			continue;
		}
		$old_copy = false;
		foreach ( $stat as $name => $identity ) {
			$key = $path . '/' . $name;
			if ( ! isset( $seen[ $key ] ) || $seen[ $key ][1] !== $identity[1] ) {
				++$new_copies;
			} elseif ( $seen[ $key ] === $identity ) {
				$old_copy = true;
			} else {
				++$unconfirmed;
			}
		}
		$after[ $path ] = $old_copy;
	}
	$left = aafm_site_cache_any( $after );

	// 6. Something could not be read.
	if ( null === $had || null === $left ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}

	if ( true === $left ) {
		// 7. One stored spelling was removed and another was left.
		foreach ( $before as $path => $was_there ) {
			if ( true === $was_there && false === $after[ $path ] ) {
				return aafm_site_cache_result( 'failed', 'variant_still_cached', true );
			}
		}
		// 8. A copy from before the call is still there.
		return aafm_site_cache_result( 'failed', 'still_cached', $had );
	}

	// A file that may be the old copy, touched in place: removal is not proven.
	if ( $unconfirmed > 0 ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', true );
	}

	// 9. Found before, gone now.
	if ( true === $had ) {
		return aafm_site_cache_result( 'purged', $new_copies > 0 ? 'recached' : '', true );
	}

	// 10. Nothing was cached where this adapter looks, before or after.
	return aafm_site_cache_result( 'requested', 'not_found_in_cache', false );
}

/**
 * The whole-site sequence: the only way an adapter confirms a full purge by file.
 *
 * A base that is missing before the call means we cannot see the cache. A base that is missing
 * after a call that began with files in it means the vendor removed it, which is the positive
 * result.
 *
 * @param array<int,mixed> $bases     Every directory that holds this site's cached pages.
 * @param string           $regex     Matches the file names of cached pages. Never input.
 * @param callable         $vendor_fn The vendor purge call. No arguments.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_site_cache_tree_confirmed( array $bases, string $regex, callable $vendor_fn ): array {
	$bases = array_values( array_unique( array_map( 'strval', $bases ) ) );

	// 1. Before.
	clearstatcache();
	$answers = array();
	foreach ( $bases as $base ) {
		if ( is_dir( $base ) ) {
			$answers[] = aafm_siteops_cache_tree_has_files( $base, $regex );
		}
	}
	$had = array() === $answers ? null : aafm_site_cache_any( $answers );

	// 2. The vendor's own purge.
	$vendor_fn();

	// 3. Nothing to confirm against.
	if ( null === $had ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}
	if ( false === $had ) {
		return aafm_site_cache_result( 'requested', 'not_found_in_cache', false );
	}

	// 4. After.
	clearstatcache();
	$answers = array();
	foreach ( $bases as $base ) {
		if ( is_dir( $base ) ) {
			$answers[] = aafm_siteops_cache_tree_has_files( $base, $regex );
		}
	}
	$left = array() === $answers ? false : aafm_site_cache_any( $answers );
	if ( true === $left ) {
		return aafm_site_cache_result( 'failed', 'still_cached', true );
	}
	if ( null === $left ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', true );
	}
	return aafm_site_cache_result( 'purged', '', true );
}

/**
 * The variant rule: a purged result becomes requested variants_unchecked when the plugin keeps
 * other copies in a directory this adapter does not check and that directory holds a file.
 *
 * An adapter whose section names variant directories passes its sequence result through this.
 *
 * @param array<string,mixed> $result       A result of one of the two sequences.
 * @param array<int,mixed>    $variant_dirs Directories the adapter does not check.
 * @return array<string,mixed>
 */
function aafm_site_cache_variants_downgrade( array $result, array $variant_dirs ): array {
	if ( ! isset( $result['state'] ) || 'purged' !== $result['state'] ) {
		return $result;
	}
	foreach ( $variant_dirs as $dir ) {
		if ( is_string( $dir ) && is_dir( $dir ) && false !== aafm_siteops_cache_tree_has_files( $dir, '/./' ) ) {
			return aafm_site_cache_result( 'requested', 'variants_unchecked', true );
		}
	}
	return $result;
}

/**
 * Whether a value an adapter returned is exactly the result shape with a state and reason pair
 * the contract allows.
 *
 * @param mixed $value Value an adapter purge function returned.
 * @return bool
 */
function aafm_site_cache_valid_result( $value ): bool {
	if ( ! is_array( $value ) || array( 'state', 'reason', 'was_cached' ) !== array_keys( $value ) ) {
		return false;
	}
	if ( ! is_string( $value['state'] ) || ! is_string( $value['reason'] ) || ( null !== $value['was_cached'] && ! is_bool( $value['was_cached'] ) ) ) {
		return false;
	}
	$pairs = array(
		'purged'    => array( '', 'recached' ),
		'requested' => array( 'no_acknowledgement', 'not_found_in_cache', 'proxy_answered', 'deferred', 'stale_window', 'variants_unchecked' ),
		'failed'    => array( 'still_cached', 'variant_still_cached', 'vendor_error', 'proxy_error', 'nothing_sent' ),
		'skipped'   => array( 'not_caching', 'unsupported_operation', 'unsupported_version', 'over_budget' ),
	);
	return isset( $pairs[ $value['state'] ] ) && in_array( $value['reason'], $pairs[ $value['state'] ], true );
}

/**
 * Whether each detected adapter is serving cached pages right now: serving() of every detected
 * entry whose adapter file loads, each called through the wrapper.
 *
 * Not memoised: the calling tool calls this once and passes the result on, so a cache plugin
 * switched on or off between two tool calls is noticed.
 *
 * @return array<string,bool|null> True, false, or null when serving() threw.
 */
function aafm_site_cache_serving_map(): array {
	aafm_siteops_load();

	$map = array();
	foreach ( aafm_site_cache_adapters() as $slug => $entry ) {
		$slug = (string) $slug;
		if ( ! aafm_site_cache_detected( $entry ) || ! aafm_site_cache_adapter_ready( $slug ) ) {
			continue;
		}
		$serving      = 'aafm_cache_' . $slug . '_serving';
		$call         = aafm_siteops_vendor_call(
			static function () use ( $serving ) {
				return is_callable( $serving ) ? $serving() : null;
			}
		);
		$map[ $slug ] = $call['ok'] ? true === $call['value'] : null;
	}
	return $map;
}

/**
 * Purge through every adapter, in registry order. The only caller of adapter purge functions.
 *
 * One row per registry entry whose plugin is present. A plugin that is not detected and not
 * active has no row.
 *
 * @param string              $op   'post' | 'url' | 'all'.
 * @param array<string,mixed> $args post_id and url for 'post', url for 'url', nothing for 'all'.
 * @param array<string,mixed> $map  The value of aafm_site_cache_serving_map(), computed once by the calling tool.
 * @return list<array{provider:string,label:string,state:string,reason:string,was_cached:bool|null}>
 */
function aafm_site_cache_fan_out( string $op, array $args, array $map ): array {
	aafm_siteops_load();

	$settings = aafm_site_purge_budget_settings();
	$started  = microtime( true );
	$url      = isset( $args['url'] ) && is_array( $args['url'] ) ? $args['url'] : array();
	$post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

	// A post whose URL is the home URL is purged by URL, so the home rules of each adapter apply.
	$run_op = ( 'post' === $op && ! empty( $url['is_home'] ) ) ? 'url' : $op;

	$rows = array();
	foreach ( aafm_site_cache_adapters() as $slug => $entry ) {
		$slug  = (string) $slug;
		$label = isset( $entry['label'] ) ? (string) $entry['label'] : $slug;
		$row   = static function ( array $result ) use ( $slug, $label ): array {
			return array(
				'provider'   => $slug,
				'label'      => $label,
				'state'      => (string) $result['state'],
				'reason'     => (string) $result['reason'],
				'was_cached' => $result['was_cached'],
			);
		};

		// 1. Not detected: a row only when the plugin is active.
		if ( ! aafm_site_cache_detected( $entry ) ) {
			if ( aafm_siteops_plugin_active( isset( $entry['plugins'] ) && is_array( $entry['plugins'] ) ? $entry['plugins'] : array() ) ) {
				$rows[] = $row( aafm_site_cache_result( 'skipped', 'unsupported_version', null ) );
			}
			continue;
		}
		// 2. The adapter file and its four functions.
		if ( ! aafm_site_cache_adapter_ready( $slug ) ) {
			$rows[] = $row( aafm_site_cache_result( 'skipped', 'unsupported_version', null ) );
			continue;
		}
		// 3. An adapter that does not work in a WP-CLI process.
		if ( aafm_siteops_is_cli() && empty( $entry['cli'] ) ) {
			$rows[] = $row( aafm_site_cache_result( 'skipped', 'unsupported_operation', null ) );
			continue;
		}
		// 4. The operation.
		if ( ! in_array( $run_op, isset( $entry['ops'] ) && is_array( $entry['ops'] ) ? $entry['ops'] : array(), true ) ) {
			$rows[] = $row( aafm_site_cache_result( 'skipped', 'unsupported_operation', null ) );
			continue;
		}
		// 6. Whether it is serving. (Step 5 of the design was removed; the numbers are kept.)
		if ( array_key_exists( $slug, $map ) && null === $map[ $slug ] ) {
			$rows[] = $row( aafm_site_cache_result( 'failed', 'vendor_error', null ) );
			continue;
		}
		if ( ! isset( $map[ $slug ] ) || true !== $map[ $slug ] ) {
			$rows[] = $row( aafm_site_cache_result( 'skipped', 'not_caching', null ) );
			continue;
		}
		// 7. The wall-time cut-off, between adapters. It cannot interrupt one that is running.
		if ( microtime( true ) - $started > $settings['wall_seconds'] ) {
			$rows[] = $row( aafm_site_cache_result( 'skipped', 'over_budget', null ) );
			continue;
		}
		// 8. The purge, through the wrapper.
		$purge = 'aafm_cache_' . $slug . '_purge_' . $run_op;
		$call  = aafm_siteops_vendor_call(
			static function () use ( $purge, $run_op, $post_id, $url ) {
				if ( ! is_callable( $purge ) ) {
					return null;
				}
				if ( 'post' === $run_op ) {
					return $purge( $post_id, $url );
				}
				if ( 'url' === $run_op ) {
					return $purge( $url );
				}
				return $purge();
			}
		);
		if ( ! $call['ok'] || ! aafm_site_cache_valid_result( $call['value'] ) ) {
			$rows[] = $row( aafm_site_cache_result( 'failed', 'vendor_error', null ) );
			continue;
		}
		$rows[] = $row( $call['value'] );
	}

	return $rows;
}
