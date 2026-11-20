<?php
/**
 * Site Ops cache adapter: Proxy Cache Purge.
 *
 * Every purge is one VarnishPurger::purge_url() call; the whole site is the plugin's own regex
 * form of the home URL. purge_post() is never called: it queues 16 requests for shutdown. The
 * plugin sends a PURGE request to each configured proxy and reports each answer through its
 * after_purge_url action. A 2xx answer proves only that something answered, so the best result is
 * requested with reason proxy_answered, never purged.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether Proxy Cache Purge can send a purge. The plugin cannot tell whether a proxy exists.
 *
 * @return bool
 */
function aafm_cache_proxy_cache_purge_serving(): bool {
	return is_callable( array( 'VarnishPurger', 'purge_url' ) );
}

/**
 * Purge one published post. The same vendor call as a URL purge.
 *
 * @param int                 $post_id Post id, already checked. Not passed to the plugin.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_proxy_cache_purge_purge_post( int $post_id, array $url ): array {
	unset( $post_id );
	return aafm_cache_proxy_cache_purge_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. Only its url key is read.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_proxy_cache_purge_purge_url( array $url ): array {
	$purge = array( 'VarnishPurger', 'purge_url' );
	if ( ! is_callable( $purge ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	// The plugin fires this once per configured proxy address, with the response third.
	$codes  = array();
	$listen = static function ( $parsed_url, $purgeme, $response, $headers ) use ( &$codes ): void {
		unset( $parsed_url, $purgeme, $headers );
		// The status code is read from the response array directly: this file may not name an HTTP API function.
		$codes[] = is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	};
	add_action( 'after_purge_url', $listen, 10, 4 );
	try {
		call_user_func( $purge, (string) $url['url'] );
	} finally {
		remove_action( 'after_purge_url', $listen, 10 );
	}

	if ( array() === $codes ) {
		return aafm_site_cache_result( 'failed', 'nothing_sent', null );
	}
	foreach ( $codes as $code ) {
		if ( $code < 200 || $code > 299 ) {
			return aafm_site_cache_result( 'failed', 'proxy_error', null );
		}
	}

	return aafm_site_cache_result( 'requested', 'proxy_answered', null );
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_proxy_cache_purge_purge_all(): array {
	$the_home = array( 'VarnishPurger', 'the_home_url' );
	$home     = is_callable( $the_home ) ? (string) call_user_func( $the_home ) : home_url();

	// The plugin turns this form into a PURGE of every path.
	return aafm_cache_proxy_cache_purge_purge_url( array( 'url' => untrailingslashit( $home ) . '/?vhp-regex' ) );
}
