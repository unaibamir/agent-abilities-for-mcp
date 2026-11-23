<?php
/**
 * A stand-in for a caching proxy, for the Proxy Cache Purge acceptance run. It accepts any HTTP
 * method (PHP's built-in web server answers PURGE with 501), logs each request head, and answers
 * with the status code in the file named by the second argument, 200 when that file is absent.
 *
 * Usage: php proxy-listener.php <log file> <code file>
 * Listens on 127.0.0.1:8099 only. Taken from scratchpad/1130-siteops/bench/cache2/listener2.php.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable -- A throwaway harness file for the acceptance run on a disposable site. Never loaded by the plugin and never shipped.

$aafm_log    = isset( $argv[1] ) ? $argv[1] : '/tmp/aafm_accept_proxy.log';
$aafm_code   = isset( $argv[2] ) ? $argv[2] : '/tmp/aafm_accept_proxy.code';
$aafm_socket = stream_socket_server( 'tcp://127.0.0.1:8099', $aafm_errno, $aafm_error );
if ( false === $aafm_socket ) {
	fwrite( STDERR, 'listen failed: ' . $aafm_error . "\n" );
	exit( 1 );
}
while ( true ) {
	$aafm_client = @stream_socket_accept( $aafm_socket, -1 );
	if ( false === $aafm_client ) {
		continue;
	}
	$aafm_head = '';
	while ( false !== ( $aafm_line = fgets( $aafm_client ) ) ) {
		if ( "\r\n" === $aafm_line || "\n" === $aafm_line ) {
			break;
		}
		$aafm_head .= $aafm_line;
	}
	file_put_contents( $aafm_log, str_replace( "\r\n", ' | ', $aafm_head ) . "\n", FILE_APPEND );
	$aafm_status = is_file( $aafm_code ) ? (int) file_get_contents( $aafm_code ) : 200;
	fwrite( $aafm_client, 'HTTP/1.1 ' . ( $aafm_status > 0 ? $aafm_status : 200 ) . " X\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok" );
	fclose( $aafm_client );
}
// phpcs:enable
