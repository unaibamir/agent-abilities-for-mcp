<?php
/**
 * Site Ops cache adapter: Nginx Helper.
 *
 * A post or URL purge calls purge_url( $url, false ) on the plugin's purger; a whole-site purge
 * fires its action rt_nginx_helper_purge_all. purge_post() is never called: it sends up to 32
 * blocking requests. Only in the plugin's "delete local cache files" mode can a purge be
 * confirmed: the plugin names each cache file through a filter, and the result rests on those
 * files being gone from disk. In every other mode the purge is a request to nginx with no
 * acknowledgement.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether Nginx Helper has purging switched on. The plugin cannot tell whether nginx caches.
 *
 * @return bool
 */
function aafm_cache_nginx_helper_serving(): bool {
	$purger = $GLOBALS['nginx_purger'] ?? null;
	$admin  = $GLOBALS['nginx_helper_admin'] ?? null;

	return is_object( $purger )
		&& method_exists( $purger, 'purge_url' )
		&& is_object( $admin )
		&& isset( $admin->options )
		&& is_array( $admin->options )
		&& ! empty( $admin->options['enable_purge'] );
}

/**
 * Purge one published post. The same vendor call as a URL purge.
 *
 * @param int                 $post_id Post id, already checked. Not passed to the plugin.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_nginx_helper_purge_post( int $post_id, array $url ): array {
	unset( $post_id );
	return aafm_cache_nginx_helper_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_nginx_helper_purge_url( array $url ): array {
	$purger = $GLOBALS['nginx_purger'] ?? null;
	$admin  = $GLOBALS['nginx_helper_admin'] ?? null;
	if ( ! is_object( $purger ) || ! method_exists( $purger, 'purge_url' ) || ! is_object( $admin ) || ! isset( $admin->options ) || ! is_array( $admin->options ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$addresses = array( (string) $url['url'] );
	if ( false !== strpos( (string) $url['path_encoded'], '%' ) ) {
		$addresses[] = aafm_siteops_upper_url( $url );
	}

	if ( 'unlink_files' !== ( $admin->options['purge_method'] ?? '' ) ) {
		// A request to nginx, with no acknowledgement.
		foreach ( $addresses as $address ) {
			$purger->purge_url( $address, false );
		}
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}

	$paths  = array();
	$count  = 0;
	$record = static function ( $cached_file ) use ( &$paths ) {
		if ( is_string( $cached_file ) && '' !== $cached_file ) {
			$paths[] = $cached_file;
		}
		return $cached_file;
	};
	$tally  = static function () use ( &$count ): void {
		++$count;
	};
	// Last in the chain, so the recorded path is the one the plugin goes on to use.
	add_filter( 'rt_nginx_helper_purge_cached_file', $record, PHP_INT_MAX );
	add_action( 'rt_nginx_helper_purged_file', $tally );
	try {
		foreach ( $addresses as $address ) {
			$purger->purge_url( $address, false );
		}
	} finally {
		remove_filter( 'rt_nginx_helper_purge_cached_file', $record, PHP_INT_MAX );
		remove_action( 'rt_nginx_helper_purged_file', $tally );
	}

	if ( array() === $paths ) {
		return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
	}
	clearstatcache();
	foreach ( $paths as $path ) {
		if ( file_exists( $path ) ) {
			return aafm_site_cache_result( 'failed', 'still_cached', true );
		}
	}
	if ( $count >= 1 ) {
		// The plugin fires its action only after a successful unlink of one named file.
		return aafm_site_cache_result( 'purged', '', true );
	}

	return aafm_site_cache_result( 'requested', 'not_found_in_cache', false );
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_nginx_helper_purge_all(): array {
	$admin = $GLOBALS['nginx_helper_admin'] ?? null;
	if ( false === has_action( 'rt_nginx_helper_purge_all' ) || ! is_object( $admin ) || ! isset( $admin->options ) || ! is_array( $admin->options ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}
	$vendor = static function (): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own public purge action.
		do_action( 'rt_nginx_helper_purge_all' );
	};

	// Only a fastcgi cache is on disk. In Redis mode there is nothing to walk.
	if ( 'enable_fastcgi' === ( $admin->options['cache_method'] ?? '' ) && defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ) {
		return aafm_site_cache_tree_confirmed( array( untrailingslashit( (string) constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ) ), '/./', $vendor );
	}

	$vendor();
	return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
}
