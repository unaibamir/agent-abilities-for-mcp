<?php
/**
 * Site Ops cache adapter: WP-Optimize (page cache).
 *
 * Calls only WPO_Page_Cache::delete_single_post_cache(), WPO_Page_Cache::delete_cache_by_url() and
 * the page cache object's purge(). wpo_cache_flush() is never called: it also flushes the object
 * cache. The plugin fetches a purged URL again at shutdown; the file check here runs directly
 * after the vendor call, before that, and must stay there.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether WP-Optimize's page cache is serving cached pages.
 *
 * The two constants are tested first on purpose: the plugin's own is_enabled() writes to its log
 * file when either is missing.
 *
 * @return bool
 */
function aafm_cache_wp_optimize_serving(): bool {
	if (
		! defined( 'WP_CACHE' ) || ! constant( 'WP_CACHE' )
		|| ! defined( 'WPO_ADVANCED_CACHE' ) || ! constant( 'WPO_ADVANCED_CACHE' )
		|| ! class_exists( 'WPO_Page_Cache' )
		|| ! defined( 'WPO_CACHE_FILES_DIR' )
		|| ! function_exists( 'WP_Optimize' )
	) {
		return false;
	}
	$plugin = WP_Optimize();
	if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_page_cache' ) ) {
		return false;
	}
	$page_cache = $plugin->get_page_cache();

	return is_object( $page_cache ) && method_exists( $page_cache, 'is_enabled' ) && (bool) $page_cache->is_enabled();
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_optimize_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_wp_optimize_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_optimize_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	$by_post = array( 'WPO_Page_Cache', 'delete_single_post_cache' );
	$by_url  = array( 'WPO_Page_Cache', 'delete_cache_by_url' );
	if ( ! defined( 'WPO_CACHE_FILES_DIR' ) || ! is_callable( $by_post ) || ! is_callable( $by_url ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s = aafm_siteops_path_spellings( $url );

	return aafm_site_cache_file_confirmed(
		array( array( (string) constant( 'WPO_CACHE_FILES_DIR' ) . '/' . $s['host'], strtolower( $s['dec'] ) ) ),
		'/^(mobile\.)?index\.html(\.gz)?$/',
		static function () use ( $post_id, $url, $by_post, $by_url ): void {
			if ( $post_id > 0 ) {
				call_user_func( $by_post, $post_id );
			} else {
				call_user_func( $by_url, (string) $url['url'] );
			}
		}
	);
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_optimize_purge_all(): array {
	if ( ! defined( 'WPO_CACHE_FILES_DIR' ) || ! function_exists( 'WP_Optimize' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}
	$plugin     = WP_Optimize();
	$page_cache = is_object( $plugin ) && method_exists( $plugin, 'get_page_cache' ) ? $plugin->get_page_cache() : null;
	if ( ! is_object( $page_cache ) || ! method_exists( $page_cache, 'purge' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s = aafm_siteops_home_spellings();

	return aafm_site_cache_tree_confirmed(
		array( (string) constant( 'WPO_CACHE_FILES_DIR' ) . '/' . $s['host'] ),
		'/^(mobile\.)?index\.html/',
		static function () use ( $page_cache ): void {
			$page_cache->purge();
		}
	);
}
