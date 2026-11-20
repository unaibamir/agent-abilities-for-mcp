<?php
/**
 * Site Ops cache adapter: Hummingbird (page cache).
 *
 * A post purge fires the plugin's own action wphb_clear_page_cache; a URL purge and a whole-site
 * purge call clear_cache() on its page cache module. The plugin keeps one copy per spelling of an
 * address, a second tree for mobile copies, and .php files for logged-in visitors, so a path with
 * percent escapes gets a second call with upper-case escapes and all four directories are checked.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether Hummingbird's page cache is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_hummingbird_serving(): bool {
	$get_module = array( 'Hummingbird\Core\Utils', 'get_module' );
	if ( ! class_exists( 'Hummingbird\Core\Utils' ) || ! is_callable( $get_module ) ) {
		return false;
	}
	$page_cache = call_user_func( $get_module, 'page_cache' );

	return is_object( $page_cache )
		&& method_exists( $page_cache, 'is_active' )
		&& method_exists( $page_cache, 'clear_cache' )
		&& (bool) $page_cache->is_active()
		&& false !== has_action( 'wphb_clear_page_cache' );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_hummingbird_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_hummingbird_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_hummingbird_purge_url( array $url ): array {
	$post_id    = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	$get_module = array( 'Hummingbird\Core\Utils', 'get_module' );
	$page_cache = is_callable( $get_module ) ? call_user_func( $get_module, 'page_cache' ) : null;
	if (
		! is_object( $page_cache )
		|| ! method_exists( $page_cache, 'clear_cache' )
		|| ( $post_id > 0 && false === has_action( 'wphb_clear_page_cache' ) )
	) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s      = aafm_siteops_path_spellings( $url );
	$root   = WP_CONTENT_DIR . '/wphb-cache/cache/';
	$second = false !== strpos( (string) $url['path_encoded'], '%' );

	return aafm_site_cache_file_confirmed(
		array(
			array( $root . $s['hostport'], $s['lo'] ),
			array( $root . $s['hostport'], $s['up'] ),
			array( $root . 'mobile/' . $s['hostport'], $s['lo'] ),
			array( $root . 'mobile/' . $s['hostport'], $s['up'] ),
		),
		'/^[0-9a-f]{32}\.(html|php)$/',
		static function () use ( $post_id, $page_cache, $s, $second ): void {
			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's own public purge action.
				do_action( 'wphb_clear_page_cache', $post_id );
			} else {
				// The plugin needs both slashes; the home page is a single slash.
				$page_cache->clear_cache( '' === $s['lo'] ? '/' : '/' . $s['lo'] . '/' );
			}
			if ( $second ) {
				$page_cache->clear_cache( '/' . $s['up'] . '/' );
			}
		}
	);
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_hummingbird_purge_all(): array {
	$get_module = array( 'Hummingbird\Core\Utils', 'get_module' );
	$page_cache = is_callable( $get_module ) ? call_user_func( $get_module, 'page_cache' ) : null;
	if ( ! is_object( $page_cache ) || ! method_exists( $page_cache, 'clear_cache' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$s    = aafm_siteops_home_spellings();
	$root = WP_CONTENT_DIR . '/wphb-cache/cache/';

	return aafm_site_cache_tree_confirmed(
		array( $root . $s['hostport'], $root . 'mobile/' . $s['hostport'] ),
		'/^[0-9a-f]{32}\.(html|php)$/',
		static function () use ( $page_cache ): void {
			$page_cache->clear_cache();
		}
	);
}
