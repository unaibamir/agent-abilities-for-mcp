<?php
/**
 * Router for a real `php -S` server used by the aafm_ssrf_owned_curl_fetch() abort tests
 * (tests/abilities/SsrfOwnedCurlFetchTest.php). Two routes, selected by ?mode=:
 *
 * - honest-oversized-header: declares a Content-Length far larger than the test's $max_bytes,
 *   then sleeps before ever writing a body byte. aafm_ssrf_owned_curl_fetch()'s
 *   CURLOPT_HEADERFUNCTION must abort the instant it reads that header - if it did not, the test
 *   would hang for the sleep instead of returning almost immediately.
 * - lying-stream: sends NO Content-Length and streams chunks indefinitely, far past any
 *   reasonable $max_bytes. aafm_ssrf_owned_curl_fetch()'s CURLOPT_WRITEFUNCTION must abort once
 *   the accumulated byte count exceeds $max_bytes - if it did not, the request would run until
 *   the 10-second CURLOPT_TIMEOUT instead of returning almost immediately.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable -- test fixture served by a bare `php -S`, outside WordPress and this plugin's own coding standard entirely.

$mode = $_GET['mode'] ?? '';

if ( 'honest-oversized-header' === $mode ) {
	header( 'Content-Length: 999999999' );
	flush();
	usleep( 5000000 ); // 5s stand-in for network latency. If the caller ever reaches here, the header-based abort did not fire.
	echo 'should never be sent';
	exit;
}

if ( 'lying-stream' === $mode ) {
	header( 'Content-Type: application/octet-stream' );
	while ( true ) {
		echo str_repeat( 'x', 4096 );
		flush();
	}
}

if ( 'small-ok' === $mode ) {
	$body = 'ok';
	header( 'Content-Length: ' . strlen( $body ) );
	echo $body;
	exit;
}

http_response_code( 404 );
