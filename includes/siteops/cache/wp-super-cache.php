<?php
/**
 * Site Ops cache adapter: WP Super Cache.
 *
 * Calls only wpsc_delete_post_cache(), wpsc_delete_url_cache() and wp_cache_clear_cache( 0 ).
 * A result is decided by the cache files on disk before and after the call, never by what the
 * plugin returns. With the plugin's default rebuild setting a purge renames index.html to
 * index.html.needs-rebuild and can rename it back for the next visitor, so a purge that leaves a
 * renamed copy is reported as requested with reason stale_window, not as purged.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether WP Super Cache is serving cached pages in this request.
 *
 * @return bool
 */
function aafm_cache_wp_super_cache_serving(): bool {
	return defined( 'WP_CACHE' )
		&& (bool) constant( 'WP_CACHE' )
		&& ! empty( $GLOBALS['cache_enabled'] )
		&& is_file( WP_CONTENT_DIR . '/advanced-cache.php' );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_super_cache_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_wp_super_cache_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_super_cache_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	if ( ! function_exists( 'get_supercache_dir' ) || ! function_exists( 'wpsc_delete_post_cache' ) || ! function_exists( 'wpsc_delete_url_cache' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s     = aafm_siteops_path_spellings( $url );
	$pairs = array();

	// Pair 1: the directory the plugin's own purge acts on.
	$rel = str_replace( (string) get_option( 'home' ), '', (string) $url['url'] );
	if ( false === strpos( $rel, '://' ) ) {
		if ( function_exists( 'wpsc_normalize_uri_case' ) ) {
			$rel = (string) wpsc_normalize_uri_case( $rel );
		} else {
			$rel = (string) preg_replace_callback(
				'/%[0-9a-f]{2}/i',
				static function ( array $m ): string {
					return strtoupper( $m[0] );
				},
				$rel
			);
		}
		$pairs[] = array( untrailingslashit( (string) get_supercache_dir() ), trim( $rel, '/' ) );
	}

	// Pair 2: where the files were found on the bench, also on a subfolder site.
	$cache_path = $GLOBALS['cache_path'] ?? '';
	if ( is_string( $cache_path ) && '' !== $cache_path ) {
		$pairs[] = array( $cache_path . 'supercache/' . $s['host'], $s['up'] );
	}

	$result = aafm_site_cache_file_confirmed(
		$pairs,
		'/^(index.*\.html(\.gz)?|(meta-)?wp-cache-[0-9a-f]{32}\.php)$/',
		static function () use ( $post_id, $url ): void {
			if ( $post_id > 0 ) {
				wpsc_delete_post_cache( $post_id );
			} else {
				wpsc_delete_url_cache( (string) $url['url'] );
			}
		}
	);
	if ( 'purged' !== $result['state'] ) {
		return $result;
	}

	// A renamed copy can be put back under its served name for about ten seconds.
	foreach ( $pairs as $pair ) {
		$dir = aafm_siteops_confined_path( $pair[0], $pair[1] );
		if ( null !== $dir && true === aafm_siteops_cache_files_present( $dir, '/^index.*\.html(\.gz)?\.needs-rebuild$/' ) ) {
			return aafm_site_cache_result( 'requested', 'stale_window', true );
		}
	}

	return $result;
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_super_cache_purge_all(): array {
	if ( ! function_exists( 'get_supercache_dir' ) || ! function_exists( 'wp_cache_clear_cache' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	return aafm_site_cache_tree_confirmed(
		array( untrailingslashit( (string) get_supercache_dir() ) ),
		'/^(index.*\.html|(meta-)?wp-cache-[0-9a-f]{32}\.php)/',
		static function (): void {
			// Always the literal 0: a blog id is a fatal error on a single site.
			wp_cache_clear_cache( 0 );
		}
	);
}
