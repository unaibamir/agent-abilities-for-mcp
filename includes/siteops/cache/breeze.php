<?php
/**
 * Site Ops cache adapter: Breeze (local file cache only).
 *
 * A post purge fires the plugin's own action purge_post_cache; a URL purge calls
 * breeze_purge_local_cache_for_urls(); a whole-site purge calls
 * Breeze_PurgeCache::breeze_cache_flush( false, false, true ). The plugin keeps one directory per
 * spelling and per scheme of an address and leaves the other scheme's copy behind, so every purge
 * is followed by a second call with the other-scheme addresses. breeze_clear_all_cache and
 * is_varnish_cache_started() are never used. The Varnish layer is never reported as purged.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether Breeze's file cache is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_breeze_serving(): bool {
	$reader = array( 'Breeze_Options_Reader', 'get_option_value' );

	return class_exists( 'Breeze_Options_Reader' )
		&& is_callable( $reader )
		&& function_exists( 'breeze_get_cache_base_path' )
		&& '1' === (string) call_user_func( $reader, 'breeze-active' )
		&& false !== has_action( 'purge_post_cache' )
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
function aafm_cache_breeze_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_breeze_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_breeze_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	if (
		! function_exists( 'breeze_get_cache_base_path' )
		|| ! function_exists( 'breeze_purge_local_cache_for_urls' )
		|| ( $post_id > 0 && false === has_action( 'purge_post_cache' ) )
	) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	// One directory per spelling and per scheme: sha256 of the full URL with its final slash.
	$lower       = trailingslashit( (string) $url['url'] );
	$upper       = trailingslashit( aafm_siteops_upper_url( $url ) );
	$switch      = static function ( string $address ): string {
		return 0 === strpos( $address, 'https://' ) ? 'http://' . substr( $address, 8 ) : 'https://' . substr( $address, 7 );
	};
	$other_lower = $switch( $lower );
	$other_upper = $switch( $upper );
	$base_path   = (string) breeze_get_cache_base_path();
	$base        = untrailingslashit( $base_path );

	return aafm_site_cache_file_confirmed(
		array(
			array( $base, hash( 'sha256', $lower ) ),
			array( $base, hash( 'sha256', $upper ) ),
			array( $base, hash( 'sha256', $other_lower ) ),
			array( $base, hash( 'sha256', $other_upper ) ),
		),
		'/^(mobile_)?[0-9a-f]{64}\.html$/',
		static function () use ( $post_id, $url, $base_path, $other_lower, $other_upper ): void {
			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own public purge action.
				do_action( 'purge_post_cache', $post_id );
			} else {
				breeze_purge_local_cache_for_urls( array( (string) $url['url'] ), $base_path );
			}
			// The plugin leaves the other scheme's directory behind.
			breeze_purge_local_cache_for_urls( array( $other_lower, $other_upper ), $base_path );
		}
	);
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_breeze_purge_all(): array {
	$flush = array( 'Breeze_PurgeCache', 'breeze_cache_flush' );
	if ( ! function_exists( 'breeze_get_cache_base_path' ) || ! is_callable( $flush ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	return aafm_site_cache_tree_confirmed(
		array( untrailingslashit( (string) breeze_get_cache_base_path() ) ),
		'/^(mobile_)?[0-9a-f]{64}\.html$/',
		static function () use ( $flush ): void {
			call_user_func( $flush, false, false, true );
		}
	);
}
