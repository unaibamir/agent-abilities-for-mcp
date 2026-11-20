<?php
/**
 * Site Ops cache adapter: Jetpack Boost (Page Cache module).
 *
 * Fires only the plugin's own actions jetpack_boost_clear_page_cache_post, _url, _home and _all.
 * The _url action with the home URL empties the whole cache, so the home page goes through _home.
 * The plugin keeps one copy per spelling of an address, so a path with percent escapes gets a
 * second call with upper-case escapes. Its success hook fires when nothing was removed and is not
 * used. A URL purge also removes pages under that URL.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether Jetpack Boost's page cache is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_jetpack_boost_serving(): bool {
	return function_exists( 'jetpack_boost_delete_cache_for_url' )
		&& (bool) get_option( 'jetpack_boost_status_page-cache' )
		&& defined( 'WP_CACHE' )
		&& (bool) constant( 'WP_CACHE' );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_jetpack_boost_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_jetpack_boost_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_jetpack_boost_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	$is_home = ! empty( $url['is_home'] );
	$second  = ! $is_home && false !== strpos( (string) $url['path_encoded'], '%' );
	$first   = 'jetpack_boost_clear_page_cache_url';
	if ( $post_id > 0 ) {
		$first = 'jetpack_boost_clear_page_cache_post';
	} elseif ( $is_home ) {
		$first = 'jetpack_boost_clear_page_cache_home';
	}
	if ( false === has_action( $first ) || ( $second && false === has_action( 'jetpack_boost_clear_page_cache_url' ) ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s    = aafm_siteops_path_spellings( $url );
	$base = WP_CONTENT_DIR . '/boost-cache/cache/' . $s['hostport'];

	return aafm_site_cache_file_confirmed(
		array(
			array( $base, $s['lo'] ),
			array( $base, $s['up'] ),
		),
		'/^[0-9a-f]{32}\.html$/',
		static function () use ( $post_id, $is_home, $url, $second ): void {
			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Jetpack Boost's own public purge action.
				do_action( 'jetpack_boost_clear_page_cache_post', $post_id );
			} elseif ( $is_home ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Jetpack Boost's own public purge action.
				do_action( 'jetpack_boost_clear_page_cache_home' );
			} else {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Jetpack Boost's own public purge action.
				do_action( 'jetpack_boost_clear_page_cache_url', (string) $url['url'] );
			}
			if ( $second ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Jetpack Boost's own public purge action.
				do_action( 'jetpack_boost_clear_page_cache_url', aafm_siteops_upper_url( $url ) );
			}
		}
	);
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_jetpack_boost_purge_all(): array {
	if ( false === has_action( 'jetpack_boost_clear_page_cache_all' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s = aafm_siteops_home_spellings();

	return aafm_site_cache_tree_confirmed(
		array( WP_CONTENT_DIR . '/boost-cache/cache/' . $s['hostport'] ),
		'/^[0-9a-f]{32}\.html$/',
		static function (): void {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Jetpack Boost's own public purge action.
			do_action( 'jetpack_boost_clear_page_cache_all' );
		}
	);
}
