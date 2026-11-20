<?php
/**
 * Site Ops cache adapter: Cache Enabler.
 *
 * Fires only the plugin's own actions cache_enabler_clear_page_cache_by_post,
 * cache_enabler_clear_page_cache_by_url and cache_enabler_clear_site_cache. The plugin keeps one
 * copy of a page per spelling of its address and clears only the spelling it is given, so a path
 * with percent escapes gets a second call with upper-case escapes. Its "cleared" hook is not used
 * as evidence. Cache_Enabler::clear_post_cache() and cache_enabler_clear_complete_cache are never
 * used: the first also clears the front page, the second every site of a network.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether Cache Enabler is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_cache_enabler_serving(): bool {
	return defined( 'CACHE_ENABLER_CACHE_DIR' )
		&& defined( 'WP_CACHE' )
		&& (bool) constant( 'WP_CACHE' )
		&& is_file( WP_CONTENT_DIR . '/advanced-cache.php' );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_cache_enabler_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_cache_enabler_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_cache_enabler_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	$second  = false !== strpos( (string) $url['path_encoded'], '%' );
	if (
		! defined( 'CACHE_ENABLER_CACHE_DIR' )
		|| ( $post_id > 0 && false === has_action( 'cache_enabler_clear_page_cache_by_post' ) )
		|| ( ( 0 === $post_id || $second ) && false === has_action( 'cache_enabler_clear_page_cache_by_url' ) )
	) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s    = aafm_siteops_path_spellings( $url );
	$base = (string) constant( 'CACHE_ENABLER_CACHE_DIR' ) . '/' . $s['host'];

	return aafm_site_cache_file_confirmed(
		array(
			array( $base, $s['lo'] ),
			array( $base, $s['up'] ),
		),
		'/^https?-index.*\.html(\.gz|\.br)?$/',
		static function () use ( $post_id, $url, $second ): void {
			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own public purge action.
				do_action( 'cache_enabler_clear_page_cache_by_post', $post_id );
			} else {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own public purge action.
				do_action( 'cache_enabler_clear_page_cache_by_url', (string) $url['url'] );
			}
			if ( $second ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own public purge action.
				do_action( 'cache_enabler_clear_page_cache_by_url', aafm_siteops_upper_url( $url ) );
			}
		}
	);
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_cache_enabler_purge_all(): array {
	if ( ! defined( 'CACHE_ENABLER_CACHE_DIR' ) || false === has_action( 'cache_enabler_clear_site_cache' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s = aafm_siteops_home_spellings();

	return aafm_site_cache_tree_confirmed(
		array( (string) constant( 'CACHE_ENABLER_CACHE_DIR' ) . '/' . $s['host'] ),
		'/^https?-index/',
		static function (): void {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own public purge action.
			do_action( 'cache_enabler_clear_site_cache' );
		}
	);
}
