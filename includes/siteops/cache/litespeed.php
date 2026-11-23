<?php
/**
 * Site Ops cache adapter: LiteSpeed Cache.
 *
 * Fires only the plugin's own actions litespeed_purge_post and litespeed_purge_url, and calls
 * \LiteSpeed\Purge::purge_all_lscache() for the whole site. The litespeed_purge_all action is never
 * fired: it also purges CSS and JS and can reach the object cache. A purge here is a response
 * header that the web server acts on, with no acknowledgement, so every result is requested.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether LiteSpeed Cache is caching pages on a LiteSpeed server in this request.
 *
 * LITESPEED_ON alone is not enough: any client that sends an X-LSCACHE header makes the plugin
 * define it on a server that has no LiteSpeed.
 *
 * @return bool
 */
function aafm_cache_litespeed_serving(): bool {
	if ( ! defined( 'LITESPEED_ON' ) || aafm_siteops_is_cli() ) {
		return false;
	}

	// The server software goes through sanitize_key(): it is only searched, never stored or shown.
	$type        = defined( 'LITESPEED_SERVER_TYPE' ) ? (string) constant( 'LITESPEED_SERVER_TYPE' ) : '';
	$software    = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_key( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	$real_server = in_array( $type, array( 'LITESPEED_SERVER_OLS', 'LITESPEED_SERVER_ENT' ), true ) || false !== strpos( $software, 'litespeed' );

	/**
	 * Filters whether this site sits behind a real LiteSpeed cache.
	 *
	 * Only a callback that returns exactly true changes the answer. A site behind a LiteSpeed
	 * ADC or QUIC.cloud front, which cannot be told from a forged request header, can opt in.
	 *
	 * @param bool $real_server Whether a LiteSpeed or OpenLiteSpeed server was detected.
	 */
	return true === apply_filters( 'aafm_site_litespeed_serving', $real_server );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post. Not used.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_litespeed_purge_post( int $post_id, array $url ): array {
	unset( $url );
	if ( false === has_action( 'litespeed_purge_post' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache's own constant; stops its purge notice.
	defined( 'LITESPEED_PURGE_SILENT' ) || define( 'LITESPEED_PURGE_SILENT', true );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own public purge action.
	do_action( 'litespeed_purge_post', $post_id );

	return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_litespeed_purge_url( array $url ): array {
	if ( false === has_action( 'litespeed_purge_url' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache's own constant; stops its purge notice.
	defined( 'LITESPEED_PURGE_SILENT' ) || define( 'LITESPEED_PURGE_SILENT', true );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own public purge action.
	do_action( 'litespeed_purge_url', (string) $url['url'] );

	return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
}

/**
 * Purge every cached page of this site. Page cache only.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_litespeed_purge_all(): array {
	$purge = array( 'LiteSpeed\Purge', 'purge_all_lscache' );
	if ( ! is_callable( $purge ) ) {
		return aafm_site_cache_result( 'skipped', 'unsupported_operation', null );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache's own constant; stops its purge notice.
	defined( 'LITESPEED_PURGE_SILENT' ) || define( 'LITESPEED_PURGE_SILENT', true );
	call_user_func( $purge );

	return aafm_site_cache_result( 'requested', 'no_acknowledgement', null );
}
