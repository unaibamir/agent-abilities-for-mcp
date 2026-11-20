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
