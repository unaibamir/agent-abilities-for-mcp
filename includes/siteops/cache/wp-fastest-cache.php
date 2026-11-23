<?php
/**
 * Site Ops cache adapter: WP Fastest Cache.
 *
 * Calls only wpfc_clear_post_cache_by_id(), wpfc_clear_cache_by_url() and wpfc_clear_all_cache().
 * The plugin stores pages under raw UTF-8 directory names with no host directory. Its post purge
 * is wider than the post: it also clears neighbouring posts, the home page and archives. The
 * mobile cache of the Premium build is not checked, so a purge on a site that has one is reported
 * as requested with reason variants_unchecked.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether WP Fastest Cache is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_wp_fastest_cache_serving(): bool {
	$options = json_decode( (string) get_option( 'WpFastestCache' ), true );

	return is_array( $options ) && isset( $options['wpFastestCacheStatus'] );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_fastest_cache_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_wp_fastest_cache_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_fastest_cache_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	if ( ( $post_id > 0 && ! function_exists( 'wpfc_clear_post_cache_by_id' ) ) || ( 0 === $post_id && ! function_exists( 'wpfc_clear_cache_by_url' ) ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s      = aafm_siteops_path_spellings( $url );
	$plugin = $GLOBALS['wp_fastest_cache'] ?? null;
	$base   = is_object( $plugin ) && method_exists( $plugin, 'getWpContentDir' )
		? untrailingslashit( (string) $plugin->getWpContentDir( '/cache/all' ) )
		: WP_CONTENT_DIR . '/cache/all';
	$answer = null;

	$result = aafm_site_cache_file_confirmed(
		array( array( $base, $s['dec'] ) ),
		'/^index\.html$/',
		static function () use ( $post_id, $url, &$answer ): void {
			if ( $post_id > 0 ) {
				$answer = wpfc_clear_post_cache_by_id( $post_id );
			} else {
				$answer = wpfc_clear_cache_by_url( (string) $url['url'] );
			}
		}
	);

	if ( is_array( $answer ) && isset( $answer['success'] ) && false === $answer['success'] ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	// The Premium mobile cache is a sibling tree this adapter does not check.
	$mobile = dirname( $base ) . '/wpfc-mobile-cache';
	if ( 'purged' === $result['state'] && is_dir( $mobile ) && false !== aafm_siteops_cache_tree_has_files( $mobile, '/./' ) ) {
		return aafm_site_cache_result( 'requested', 'variants_unchecked', true );
	}

	return $result;
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_wp_fastest_cache_purge_all(): array {
	if ( ! function_exists( 'wpfc_clear_all_cache' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$plugin = $GLOBALS['wp_fastest_cache'] ?? null;
	$base   = is_object( $plugin ) && method_exists( $plugin, 'getWpContentDir' )
		? untrailingslashit( (string) $plugin->getWpContentDir( '/cache/all' ) )
		: WP_CONTENT_DIR . '/cache/all';
	$mobile = dirname( $base ) . '/wpfc-mobile-cache';
	// Read before the call: the plugin moves its whole cache folder away during a full purge.
	$has_mobile = is_dir( $mobile ) && false !== aafm_siteops_cache_tree_has_files( $mobile, '/./' );
	$answer     = null;

	$result = aafm_site_cache_tree_confirmed(
		array( $base ),
		'/^index\.html$/',
		static function () use ( &$answer ): void {
			$answer = wpfc_clear_all_cache();
		}
	);

	if ( is_array( $answer ) && isset( $answer['success'] ) && false === $answer['success'] ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}
	if ( 'purged' === $result['state'] && $has_mobile ) {
		return aafm_site_cache_result( 'requested', 'variants_unchecked', true );
	}

	return $result;
}
