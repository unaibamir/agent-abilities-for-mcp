<?php
/**
 * Fake cache adapters for the Site Ops dispatcher and tool tests.
 *
 * No vendor symbol is defined here. A fake adapter is a registry entry handed to the
 * aafm_site_cache_adapters filter plus the adapter functions below, which record every call and
 * return or throw what a test set. Slugs: fake_a, fake_b, fake_c (all four functions) and
 * fake_partial (serving only, to test an adapter whose functions are missing).
 *
 * The per-vendor stubs that the real adapters need are added to this file in batch 3.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

final class SiteopsCacheStubStore {

	/**
	 * What each fake's serving() returns: a value, or the string 'throw'. Default true.
	 *
	 * @var array<string,mixed>
	 */
	public static $serving = array();

	/**
	 * What each fake's purge functions return: a value, the string 'throw', or a Closure called
	 * with the function name and its arguments. Default: purged, no reason, was cached.
	 *
	 * @var array<string,mixed>
	 */
	public static $purge = array();

	/**
	 * Every adapter call, in order: array( slug, function, arguments ).
	 *
	 * @var array<int,array{0:string,1:string,2:array<int,mixed>}>
	 */
	public static $calls = array();

	/**
	 * The registry the filter returns.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $entries = array();

	/**
	 * Forget everything and remove the registry filter.
	 */
	public static function reset(): void {
		self::$serving = array();
		self::$purge   = array();
		self::$calls   = array();
		self::$entries = array();
		remove_filter( 'aafm_site_cache_adapters', array( self::class, 'filter' ), 999 );
	}

	/**
	 * A registry entry. With no overrides it is always detected and supports everything.
	 *
	 * @param string              $label     Label.
	 * @param array<string,mixed> $overrides Keys to change.
	 * @return array<string,mixed>
	 */
	public static function entry( string $label, array $overrides = array() ): array {
		return array_merge(
			array(
				'label'         => $label,
				'plugins'       => array( strtolower( str_replace( ' ', '-', $label ) ) . '/plugin.php' ),
				'detect'        => array(),
				'ops'           => array( 'post', 'url', 'all' ),
				'confirm'       => 'file',
				'vendor_http'   => false,
				'recursive_url' => false,
				'rewarms'       => false,
				'cli'           => true,
			),
			$overrides
		);
	}

	/**
	 * Replace the whole adapter registry with these entries, keyed by slug.
	 *
	 * @param array<string,array<string,mixed>> $entries Entries.
	 */
	public static function register( array $entries ): void {
		self::$entries = $entries;
		add_filter( 'aafm_site_cache_adapters', array( self::class, 'filter' ), 999 );
	}

	/**
	 * The filter callback.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter(): array {
		return self::$entries;
	}

	/**
	 * Called by a fake's serving().
	 *
	 * @param string $slug Slug.
	 * @return mixed
	 * @throws \Error When the test asked for it.
	 */
	public static function serving( string $slug ) {
		self::$calls[] = array( $slug, 'serving', array() );
		$value         = array_key_exists( $slug, self::$serving ) ? self::$serving[ $slug ] : true;
		if ( 'throw' === $value ) {
			throw new \Error( 'stub serving failure' );
		}
		return $value;
	}

	/**
	 * Called by a fake's purge functions.
	 *
	 * @param string           $slug Slug.
	 * @param string           $fn   purge_post, purge_url or purge_all.
	 * @param array<int,mixed> $args Arguments the dispatcher passed.
	 * @return mixed
	 * @throws \Error When the test asked for it.
	 */
	public static function purge( string $slug, string $fn, array $args ) {
		self::$calls[] = array( $slug, $fn, $args );
		$value         = array_key_exists( $slug, self::$purge ) ? self::$purge[ $slug ] : aafm_site_cache_result( 'purged', '', true );
		if ( 'throw' === $value ) {
			throw new \Error( 'stub purge failure' );
		}
		if ( $value instanceof \Closure ) {
			return $value( $fn, $args );
		}
		return $value;
	}

	/**
	 * The purge calls one fake received: array( function, arguments ) each.
	 *
	 * @param string $slug Slug.
	 * @return array<int,array{0:string,1:array<int,mixed>}>
	 */
	public static function purge_calls( string $slug ): array {
		$out = array();
		foreach ( self::$calls as $call ) {
			if ( $slug === $call[0] && 'serving' !== $call[1] ) {
				$out[] = array( $call[1], $call[2] );
			}
		}
		return $out;
	}
}

function aafm_cache_fake_a_serving() {
	return SiteopsCacheStubStore::serving( 'fake_a' );
}

function aafm_cache_fake_a_purge_post( int $post_id, array $url ) {
	return SiteopsCacheStubStore::purge( 'fake_a', 'purge_post', array( $post_id, $url ) );
}

function aafm_cache_fake_a_purge_url( array $url ) {
	return SiteopsCacheStubStore::purge( 'fake_a', 'purge_url', array( $url ) );
}

function aafm_cache_fake_a_purge_all() {
	return SiteopsCacheStubStore::purge( 'fake_a', 'purge_all', array() );
}

function aafm_cache_fake_b_serving() {
	return SiteopsCacheStubStore::serving( 'fake_b' );
}

function aafm_cache_fake_b_purge_post( int $post_id, array $url ) {
	return SiteopsCacheStubStore::purge( 'fake_b', 'purge_post', array( $post_id, $url ) );
}

function aafm_cache_fake_b_purge_url( array $url ) {
	return SiteopsCacheStubStore::purge( 'fake_b', 'purge_url', array( $url ) );
}

function aafm_cache_fake_b_purge_all() {
	return SiteopsCacheStubStore::purge( 'fake_b', 'purge_all', array() );
}

function aafm_cache_fake_c_serving() {
	return SiteopsCacheStubStore::serving( 'fake_c' );
}

function aafm_cache_fake_c_purge_post( int $post_id, array $url ) {
	return SiteopsCacheStubStore::purge( 'fake_c', 'purge_post', array( $post_id, $url ) );
}

function aafm_cache_fake_c_purge_url( array $url ) {
	return SiteopsCacheStubStore::purge( 'fake_c', 'purge_url', array( $url ) );
}

function aafm_cache_fake_c_purge_all() {
	return SiteopsCacheStubStore::purge( 'fake_c', 'purge_all', array() );
}

function aafm_cache_fake_partial_serving() {
	return true;
}

// ---------------------------------------------------------------------------------------------
// Vendor stubs for the cache adapter tests (tests/SiteCacheAdaptersTest.php).
// Nothing below defines a vendor symbol at load time. Each aafm_siteops_stub_<slug>_activate()
// defines only what its adapter calls, and only when a test asks. Activation is permanent for the
// process, so no test may depend on a vendor being absent.
// ---------------------------------------------------------------------------------------------

/**
 * Call recorder and switchboard shared by every vendor stub.
 */
final class Aafm_Siteops_Vendor_Stub {

	/** @var array<int,array{0:string,1:array<int,mixed>}> Every vendor call, in order. */
	public static $calls = array();

	/** @var array<string,callable> What a vendor call does, set by the test. Key is the call name. */
	public static $on = array();

	/** @var array<string,mixed> Per-vendor switches, set by the test. */
	public static $state = array();

	/** @var array<string,\Closure> One listener per vendor action, re-added after each test. */
	private static $listeners = array();

	/**
	 * Record one vendor call and run what the test attached to it.
	 *
	 * @param string           $name Call name.
	 * @param array<int,mixed> $args Arguments.
	 * @return mixed What the attached callable returned, or null.
	 */
	public static function hit( string $name, array $args = array() ) {
		self::$calls[] = array( $name, $args );
		return isset( self::$on[ $name ] ) ? call_user_func_array( self::$on[ $name ], $args ) : null;
	}

	/** Forget calls, behaviours and switches. Call from set_up(). */
	public static function reset(): void {
		self::$calls = array();
		self::$on    = array();
		self::$state = array();
	}

	/**
	 * The names of the recorded calls, in order.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		return array_column( self::$calls, 0 );
	}

	/**
	 * The argument lists of every recorded call with this name.
	 *
	 * @param string $name Call name.
	 * @return array<int,array<int,mixed>>
	 */
	public static function args( string $name ): array {
		$out = array();
		foreach ( self::$calls as $call ) {
			if ( $name === $call[0] ) {
				$out[] = $call[1];
			}
		}
		return $out;
	}

	/** Temp directory that stands in for vendor cache folders that a constant or a function names. */
	public static function root(): string {
		return untrailingslashit( get_temp_dir() ) . '/aafm-siteops-stub';
	}


	/**
	 * Listen on a vendor action and record each firing under the action's name. Hooks are reset
	 * between tests, so this re-adds the listener on every activate call.
	 *
	 * @param string $hook Action name.
	 * @param int    $args Accepted arguments.
	 */
	public static function listen( string $hook, int $args = 1 ): void {
		if ( ! isset( self::$listeners[ $hook ] ) ) {
			self::$listeners[ $hook ] = static function () use ( $hook ) {
				return self::hit( $hook, func_get_args() );
			};
		}
		if ( false === has_action( $hook, self::$listeners[ $hook ] ) ) {
			add_action( $hook, self::$listeners[ $hook ], 10, $args );
		}
	}

	/**
	 * Delete a directory tree. For test clean-up and for stubs that empty a cache.
	 *
	 * @param string $dir Directory.
	 */
	public static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}

/**
 * WP Super Cache: the four functions of its detect list plus wpsc_normalize_uri_case().
 */
function aafm_siteops_stub_wp_super_cache_activate(): void {
	$GLOBALS['cache_path']    = Aafm_Siteops_Vendor_Stub::root() . '/wpsc/';
	$GLOBALS['cache_enabled'] = true;
	if ( function_exists( 'get_supercache_dir' ) ) {
		return;
	}
	// Mirrors wp-cache-phase2.php:911-919: the home option cut at its first colon.
	function get_supercache_dir( $blog_id = 0 ) {
		$home = strtolower( (string) preg_replace( '/:.*$/', '', str_replace( array( 'http://', 'https://' ), '', (string) get_option( 'home' ) ) ) );
		return trailingslashit( $GLOBALS['cache_path'] . 'supercache/' . trailingslashit( $home ) );
	}
	function wpsc_normalize_uri_case( $uri ) {
		return preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $m ) {
				return strtoupper( $m[0] );
			},
			strtolower( (string) $uri )
		);
	}
	function wpsc_delete_url_cache( $url ) {
		Aafm_Siteops_Vendor_Stub::hit( 'wpsc_delete_url_cache', array( $url ) );
		return true;
	}
	function wpsc_delete_post_cache( $id ) {
		Aafm_Siteops_Vendor_Stub::hit( 'wpsc_delete_post_cache', array( $id ) );
		return true;
	}
	function wp_cache_clear_cache( $blog_id = 0 ) {
		Aafm_Siteops_Vendor_Stub::hit( 'wp_cache_clear_cache', array( $blog_id ) );
	}
}

/**
 * WP Fastest Cache's main object: only the directory resolver the adapter reads.
 */
final class Aafm_Siteops_Stub_Wpfc {
	public function getWpContentDir( $path = false ) {
		return Aafm_Siteops_Vendor_Stub::root() . '/wpfc' . $path;
	}
}

/**
 * WP Fastest Cache: its three purge functions, its global object and its class name.
 */
function aafm_siteops_stub_wp_fastest_cache_activate(): void {
	$GLOBALS['wp_fastest_cache'] = new Aafm_Siteops_Stub_Wpfc();
	if ( ! class_exists( 'WpFastestCache', false ) ) {
		class_alias( 'Aafm_Siteops_Stub_Wpfc', 'WpFastestCache' );
	}
	if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
		return;
	}
	function wpfc_clear_post_cache_by_id( $post_id = false ) {
		return Aafm_Siteops_Vendor_Stub::hit( 'wpfc_clear_post_cache_by_id', array( $post_id ) );
	}
	function wpfc_clear_cache_by_url( $url = false ) {
		return Aafm_Siteops_Vendor_Stub::hit( 'wpfc_clear_cache_by_url', array( $url ) );
	}
	function wpfc_clear_all_cache( $minified = false ) {
		return Aafm_Siteops_Vendor_Stub::hit( 'wpfc_clear_all_cache', array( $minified ) );
	}
}

/**
 * W3 Total Cache's config object: get_boolean() only.
 */
final class Aafm_Siteops_Stub_W3tc_Config {
	public function get_boolean( $key, $default_value = false ) {
		return 'pgcache.enabled' === $key ? ! empty( Aafm_Siteops_Vendor_Stub::$state['w3tc_enabled'] ) : $default_value;
	}
}

/**
 * W3 Total Cache's CacheFlush component: the delayed run only.
 */
final class Aafm_Siteops_Stub_W3tc_Flush {
	public function execute_delayed_operations() {
		$answer = Aafm_Siteops_Vendor_Stub::hit( 'execute_delayed_operations' );
		// The real return for a page cache flush, bench.md B3.
		return null === $answer ? array( array( 'module' => 'pgcache' ) ) : $answer;
	}
}

/**
 * W3 Total Cache's dispatcher. Aliased to \W3TC\Dispatcher on activation.
 */
final class Aafm_Siteops_Stub_W3tc_Dispatcher {
	public static function component( $class_name ) {
		Aafm_Siteops_Vendor_Stub::hit( 'W3TC\Dispatcher::component', array( $class_name ) );
		// A test sets w3tc_no_runner to model a plugin version without the internal method.
		return empty( Aafm_Siteops_Vendor_Stub::$state['w3tc_no_runner'] ) ? new Aafm_Siteops_Stub_W3tc_Flush() : null;
	}
}

/**
 * W3 Total Cache: the constant and functions of its detect list, its flush functions and its dispatcher.
 */
function aafm_siteops_stub_w3_total_cache_activate(): void {
	Aafm_Siteops_Vendor_Stub::$state['w3tc_enabled'] = true;
	if ( ! defined( 'W3TC' ) ) {
		define( 'W3TC', true );
	}
	if ( ! class_exists( 'W3TC\Dispatcher', false ) ) {
		class_alias( 'Aafm_Siteops_Stub_W3tc_Dispatcher', 'W3TC\Dispatcher' );
	}
	if ( function_exists( 'w3tc_config' ) ) {
		return;
	}
	function w3tc_config() {
		return new Aafm_Siteops_Stub_W3tc_Config();
	}
	function w3tc_flush_post( $post_id, $force = false, $extras = null ) {
		Aafm_Siteops_Vendor_Stub::hit( 'w3tc_flush_post', array( $post_id, $force ) );
	}
	function w3tc_flush_url( $w3tc_url, $extras = null ) {
		Aafm_Siteops_Vendor_Stub::hit( 'w3tc_flush_url', array( $w3tc_url ) );
	}
	function w3tc_flush_posts( $extras = null ) {
		Aafm_Siteops_Vendor_Stub::hit( 'w3tc_flush_posts' );
	}
	function w3tc_flush_all( $extras = null ) {
		Aafm_Siteops_Vendor_Stub::hit( 'w3tc_flush_all' );
	}
}
