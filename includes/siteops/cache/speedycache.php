<?php
/**
 * Site Ops cache adapter: SpeedyCache.
 *
 * Post and URL purges both call \SpeedyCache\Delete::url() with the decoded URL, because the
 * plugin stores non-ASCII pages under decoded directory names and its own post purge passes the
 * percent-encoded permalink, which never matches them. Delete::cache() and Delete::run() are never
 * called. The desktop and the mobile tree are both checked on disk.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether SpeedyCache is serving cached pages in this request.
 *
 * @return bool
 */
function aafm_cache_speedycache_serving(): bool {
	$plugin = $GLOBALS['speedycache'] ?? null;

	return class_exists( 'SpeedyCache\Delete' )
		&& class_exists( 'SpeedyCache\Util' )
		&& is_callable( array( 'SpeedyCache\Util', 'cache_path' ) )
		&& is_object( $plugin )
		&& isset( $plugin->options )
		&& is_array( $plugin->options )
		&& ! empty( $plugin->options['status'] )
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
function aafm_cache_speedycache_purge_post( int $post_id, array $url ): array {
	unset( $post_id );
	return aafm_cache_speedycache_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_speedycache_purge_url( array $url ): array {
	$delete = array( 'SpeedyCache\Delete', 'url' );
	$path   = array( 'SpeedyCache\Util', 'cache_path' );
	if ( ! is_callable( $delete ) || ! is_callable( $path ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s = aafm_siteops_path_spellings( $url );
	// The canonical origin followed by the decoded path.
	$dec = substr( (string) $url['url'], 0, strlen( (string) $url['url'] ) - strlen( (string) $url['path_encoded'] ) ) . (string) $url['path'];

	return aafm_site_cache_file_confirmed(
		array(
			array( untrailingslashit( (string) call_user_func( $path, 'all' ) ), $s['dec'] ),
			array( untrailingslashit( (string) call_user_func( $path, 'mobile-cache' ) ), $s['dec'] ),
		),
		'/^index.*\.html(\.gz)?$/',
		static function () use ( $delete, $dec ): void {
			call_user_func( $delete, $dec );
		}
	);
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_speedycache_purge_all(): array {
	$delete = array( 'SpeedyCache\Delete', 'all_cache' );
	$path   = array( 'SpeedyCache\Util', 'cache_path' );
	if ( ! is_callable( $delete ) || ! is_callable( $path ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	return aafm_site_cache_tree_confirmed(
		array(
			untrailingslashit( (string) call_user_func( $path, 'all' ) ),
			untrailingslashit( (string) call_user_func( $path, 'mobile-cache' ) ),
		),
		'/^index.*\.html/',
		static function () use ( $delete ): void {
			call_user_func( $delete );
		}
	);
}
