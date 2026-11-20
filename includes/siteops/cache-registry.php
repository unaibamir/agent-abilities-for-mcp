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
			'confirm'       => 'file',
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
