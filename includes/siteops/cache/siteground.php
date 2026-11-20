<?php
/**
 * Site Ops cache adapter: SiteGround Speed Optimizer (file cache).
 *
 * A post or URL purge calls sg_cachepress_purge_cache() with the URL; a whole-site purge calls
 * purge_everything() on the plugin's file cacher. The home URL is refused for the one-URL purge,
 * because the plugin treats it as the whole site. The plugin keeps one copy per spelling of an
 * address and one cache root per logged-in session, and with its preheat option on it fetches the
 * page again inside its own purge, so the re-cache rule of the file check is switched on.
 * Supercacher_Posts::purge_all_post_cache() is never called. A URL purge also removes pages under
 * that URL. The plugin's own full purge rewrites its cache secret and config file.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether SiteGround Speed Optimizer's file cache is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_siteground_serving(): bool {
	$is_enabled = array( 'SiteGround_Optimizer\Options\Options', 'is_enabled' );

	return class_exists( 'SiteGround_Optimizer\Options\Options' )
		&& is_callable( $is_enabled )
		&& (bool) call_user_func( $is_enabled, 'siteground_optimizer_file_caching' )
		&& defined( 'WP_CACHE' )
		&& (bool) constant( 'WP_CACHE' );
}

/**
 * Purge one published post. The same vendor call as a URL purge.
 *
 * @param int                 $post_id Post id, already checked. Not passed to the plugin.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_siteground_purge_post( int $post_id, array $url ): array {
	unset( $post_id );
	return aafm_cache_siteground_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_siteground_purge_url( array $url ): array {
	if ( ! empty( $url['is_home'] ) ) {
		// The plugin empties the whole cache for the home URL.
		return aafm_site_cache_result( 'skipped', 'unsupported_operation', null );
	}
	if ( ! function_exists( 'sg_cachepress_purge_cache' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s     = aafm_siteops_path_spellings( $url );
	$cache = WP_CONTENT_DIR . '/cache/sgo-cache';
	$pairs = array();
	$names = is_dir( $cache ) ? scandir( $cache ) : array();
	foreach ( is_array( $names ) ? $names : array() as $name ) {
		// The anonymous root, and one root per logged-in session (a 64 character key in the middle).
		if ( 1 === preg_match( '/^' . preg_quote( $s['host'], '/' ) . '-([0-9a-f]{64}-)?[0-9a-f]{32}$/', $name ) && is_dir( $cache . '/' . $name ) ) {
			$pairs[] = array( $cache . '/' . $name, $s['lo'] );
			$pairs[] = array( $cache . '/' . $name, $s['up'] );
		}
	}
	$second = false !== strpos( (string) $url['path_encoded'], '%' );

	$result = aafm_site_cache_file_confirmed(
		$pairs,
		'/^index.*\.html$/',
		static function () use ( $url, $second ): void {
			sg_cachepress_purge_cache( (string) $url['url'] );
			if ( $second ) {
				sg_cachepress_purge_cache( aafm_siteops_upper_url( $url ) );
			}
		},
		true
	);

	// With the server cache also on, removal there cannot be confirmed.
	$is_enabled = array( 'SiteGround_Optimizer\Options\Options', 'is_enabled' );
	if ( 'purged' === $result['state'] && is_callable( $is_enabled ) && call_user_func( $is_enabled, 'siteground_optimizer_enable_cache' ) ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', $result['was_cached'] );
	}

	return $result;
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_siteground_purge_all(): array {
	$get_instance = array( 'SiteGround_Optimizer\File_Cacher\File_Cacher', 'get_instance' );
	$cacher       = is_callable( $get_instance ) ? call_user_func( $get_instance ) : null;
	if ( ! is_object( $cacher ) || ! method_exists( $cacher, 'purge_everything' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}
	$vendor = static function () use ( $cacher ): void {
		$cacher->purge_everything();
	};

	// The roots are listed before the call: the plugin gives its next cache root a new name.
	$s     = aafm_siteops_home_spellings();
	$cache = WP_CONTENT_DIR . '/cache/sgo-cache';
	$roots = array();
	$names = is_dir( $cache ) ? scandir( $cache ) : array();
	foreach ( is_array( $names ) ? $names : array() as $name ) {
		if ( 1 === preg_match( '/^' . preg_quote( $s['host'], '/' ) . '-([0-9a-f]{64}-)?[0-9a-f]{32}$/', $name ) && is_dir( $cache . '/' . $name ) ) {
			$roots[] = $cache . '/' . $name;
		}
	}
	if ( array() === $roots ) {
		$vendor();
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}

	$result = aafm_site_cache_tree_confirmed( $roots, '/^index.*\.html$/', $vendor );

	$is_enabled = array( 'SiteGround_Optimizer\Options\Options', 'is_enabled' );
	if ( 'purged' === $result['state'] && is_callable( $is_enabled ) && call_user_func( $is_enabled, 'siteground_optimizer_enable_cache' ) ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', $result['was_cached'] );
	}

	return $result;
}
