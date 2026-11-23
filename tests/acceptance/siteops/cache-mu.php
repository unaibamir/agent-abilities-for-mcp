<?php
/**
 * Must-use plugin for the cache acceptance run. Installed on the DISPOSABLE site by cache.sh and
 * removed by it. Never shipped.
 *
 * 1. Logs every outbound request, tagged with the kind of request that made it, and lets through
 *    only the three loopback addresses this run needs, by host AND port. Inside the DDEV web container localhost:80 is the main site, so a
 *    vendor request such as Nginx Helper's GET http://localhost/purge/... or a Varnish purge to
 *    127.0.0.1:80 must never leave this process.
 * 2. Logs each redirect a request is about to follow (case N10).
 * 3. Logs the code of every WP_Error this plugin's tools create, because a refused tool call
 *    carries only the message on the MCP wire.
 * 4. With the flag file aafm-accept-litespeed present, tells the LiteSpeed adapter that a real
 *    LiteSpeed front exists (design 6.12, 9.4).
 * 5. With the flag file aafm-accept-redirect present, answers 302 to another host for
 *    /acc-redirect/ (case N10).
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable -- A throwaway harness file for the acceptance run on a disposable site. Never loaded by the plugin and never shipped.

define( 'AAFM_ACCEPT_CACHE_LOG', ABSPATH . 'aafm-accept-cache-http.log' );
define( 'AAFM_ACCEPT_ERROR_LOG', ABSPATH . 'aafm-accept-cache-errors.log' );

// The first field of every log line names the request: "mcp-<time>" for a call to the MCP
// endpoint, which is where every tool of this plugin runs, "page-<time>" for any other request,
// "cli" for WP-CLI. Case N9 judges only the "mcp-" lines.
function aafm_accept_request_id() {
	if ( ! isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
		return 'cli';
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	return ( false !== strpos( $uri, 'agent-abilities-for-mcp/mcp' ) ? 'mcp-' : 'page-' ) . $_SERVER['REQUEST_TIME_FLOAT'];
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$parts   = wp_parse_url( $url );
		$host    = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : ( isset( $parts['scheme'] ) && 'https' === $parts['scheme'] ? 443 : 80 );
		$allowed = in_array( $host . ':' . $port, array( 'localhost:8072', '127.0.0.1:8072', '127.0.0.1:8099' ), true );
		$verdict = false !== $pre ? 'answered-earlier' : ( $allowed ? 'allowed' : 'blocked' );
		$request = aafm_accept_request_id();
		file_put_contents( AAFM_ACCEPT_CACHE_LOG, $request . ' ' . ( isset( $args['method'] ) ? $args['method'] : 'GET' ) . ' ' . $host . ':' . $port . ' ' . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ' ' . $verdict . "\n", FILE_APPEND );
		if ( false !== $pre || $allowed ) {
			return $pre;
		}
		return new WP_Error( 'aafm_accept_blocked', 'Blocked by the acceptance run: not a loopback address of this run.' );
	},
	2,
	3
);

add_action(
	'requests-requests.before_redirect',
	function ( $location ) {
		$request = aafm_accept_request_id();
		file_put_contents( AAFM_ACCEPT_CACHE_LOG, $request . ' REDIRECT ' . $location . "\n", FILE_APPEND );
	}
);

add_action(
	'wp_error_added',
	function ( $code ) {
		if ( is_string( $code ) && ( 0 === strpos( $code, 'aafm_site_' ) || 0 === strpos( $code, 'aafm_backup_' ) ) ) {
			file_put_contents( AAFM_ACCEPT_ERROR_LOG, $code . "\n", FILE_APPEND );
		}
	}
);

if ( is_file( ABSPATH . 'aafm-accept-litespeed' ) ) {
	add_filter( 'aafm_site_litespeed_serving', '__return_true' );
}

if ( is_file( ABSPATH . 'aafm-accept-redirect' ) ) {
	add_action(
		'template_redirect',
		function () {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
			if ( '/acc-redirect/' === $path ) {
				header( 'Location: http://example.invalid/', true, 302 );
				exit;
			}
		},
		0
	);
}
// phpcs:enable
