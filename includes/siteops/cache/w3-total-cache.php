<?php
/**
 * Site Ops cache adapter: W3 Total Cache (page cache, Disk: Enhanced).
 *
 * Calls only w3tc_flush_post(), w3tc_flush_url() and w3tc_flush_posts(), then asks the plugin to
 * run its delayed flush now, because it otherwise waits for shutdown. Every flush, also the
 * whole-site one, renames the cache files to *_old and can serve the old copy for up to 30 seconds,
 * so no W3 Total Cache purge is ever reported as purged. w3tc_flush_all() is never called.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether W3 Total Cache's page cache is serving cached pages.
 *
 * @return bool
 */
function aafm_cache_w3_total_cache_serving(): bool {
	if ( ! function_exists( 'w3tc_config' ) || ! defined( 'WP_CACHE' ) || ! constant( 'WP_CACHE' ) ) {
		return false;
	}
	$config = w3tc_config();

	return is_object( $config ) && method_exists( $config, 'get_boolean' ) && (bool) $config->get_boolean( 'pgcache.enabled' );
}

/**
 * Purge one published post.
 *
 * @param int                 $post_id Post id, already checked.
 * @param array<string,mixed> $url     Canonical URL of the post.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_w3_total_cache_purge_post( int $post_id, array $url ): array {
	// The post id travels in a private key, one aafm_siteops_canonical_url() can never emit, so the
	// directory and file rules exist once.
	$url["\0aafm_post_id"] = $post_id;
	return aafm_cache_w3_total_cache_purge_url( $url );
}

/**
 * Purge one canonical URL.
 *
 * @param array<string,mixed> $url Canonical URL array. A private post id key selects the post purge.
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_w3_total_cache_purge_url( array $url ): array {
	$post_id = isset( $url["\0aafm_post_id"] ) ? (int) $url["\0aafm_post_id"] : 0;
	if ( ( $post_id > 0 && ! function_exists( 'w3tc_flush_post' ) ) || ( 0 === $post_id && ! function_exists( 'w3tc_flush_url' ) ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$flush = static function () use ( $post_id, $url ): void {
		if ( $post_id > 0 ) {
			w3tc_flush_post( $post_id, true );
		} else {
			w3tc_flush_url( (string) $url['url'] );
		}
	};

	// The delayed flush is an internal method. Without it the flush runs at shutdown.
	$component = array( 'W3TC\Dispatcher', 'component' );
	$runner    = is_callable( $component ) ? call_user_func( $component, 'CacheFlush' ) : null;
	if ( ! is_object( $runner ) || ! method_exists( $runner, 'execute_delayed_operations' ) ) {
		$flush();
		return aafm_site_cache_result( 'requested', 'deferred', null );
	}

	$list   = null;
	$vendor = static function () use ( $flush, $runner, &$list ): void {
		$flush();
		$list = $runner->execute_delayed_operations();
	};

	$s      = aafm_siteops_path_spellings( $url );
	$base   = WP_CONTENT_DIR . '/cache/page_enhanced/' . $s['hostport'];
	$result = null;
	if ( is_dir( $base ) ) {
		$result = aafm_site_cache_file_confirmed( array( array( $base, $s['lo'] ) ), '/^_index.*\.html(_gzip|_br)?$/', $vendor );
	} else {
		$vendor();
	}
	if ( is_array( $result ) && 'failed' === $result['state'] && 'vendor_error' === $result['reason'] ) {
		// The page path left the cache folder, so the plugin was never called: not nothing_sent.
		return $result;
	}

	$sent = false;
	if ( is_array( $list ) ) {
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && isset( $entry['module'] ) && 'pgcache' === $entry['module'] ) {
				$sent = true;
			}
		}
	}
	if ( ! $sent ) {
		return aafm_site_cache_result( 'failed', 'nothing_sent', null );
	}
	if ( null === $result ) {
		// Another engine than Disk: Enhanced. Nothing on disk to look at.
		return aafm_site_cache_result( 'requested', 'stale_window', null );
	}
	if ( 'purged' === $result['state'] ) {
		// The files were renamed to *_old, not deleted.
		return aafm_site_cache_result( 'requested', 'stale_window', true );
	}

	return $result;
}

/**
 * Purge every cached page of this site.
 *
 * @return array{state:string,reason:string,was_cached:bool|null}
 */
function aafm_cache_w3_total_cache_purge_all(): array {
	if ( ! function_exists( 'w3tc_flush_posts' ) ) {
		return aafm_site_cache_result( 'failed', 'vendor_error', null );
	}

	$component = array( 'W3TC\Dispatcher', 'component' );
	$runner    = is_callable( $component ) ? call_user_func( $component, 'CacheFlush' ) : null;
	$can_run   = is_object( $runner ) && method_exists( $runner, 'execute_delayed_operations' );
	$vendor    = static function () use ( $runner, $can_run ): void {
		w3tc_flush_posts();
		if ( $can_run && is_object( $runner ) ) {
			$runner->execute_delayed_operations();
		}
	};

	$s    = aafm_siteops_home_spellings();
	$base = WP_CONTENT_DIR . '/cache/page_enhanced/' . $s['hostport'];
	if ( ! $can_run || ! is_dir( $base ) ) {
		$vendor();
		return aafm_site_cache_result( 'requested', 'deferred', null );
	}

	$result = aafm_site_cache_tree_confirmed( array( $base ), '/^_index.*\.html(_gzip|_br)?$/', $vendor );
	if ( 'purged' === $result['state'] ) {
		// The whole-site flush renames to *_old too, it does not delete.
		return aafm_site_cache_result( 'requested', 'stale_window', true );
	}

	return $result;
}
