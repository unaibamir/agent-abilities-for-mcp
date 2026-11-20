<?php
/**
 * Where each cache plugin keeps a page on disk, written out from the "On disk" lines of the design
 * (sections 6.2 to 6.13). Plain PHP, no WordPress, and no code shared with the adapters: if an
 * adapter looks in the wrong place, this file must not agree with it by construction.
 *
 * Usage: php cache-paths.php <mode> <slug> <wp-content dir> <host[:port]> <url path>
 *   live   print every live cache file of the page, one per line
 *   stale  print every renamed (stale) copy of the page, one per line
 *   base   print the adapter's cache base directory
 *   plant  print the path of a file that would count as a live copy of the page
 * The URL path has lower-case percent escapes and both slashes, for example /acc-a/ or /.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable -- A throwaway harness file for the acceptance run on a disposable site. Never loaded by the plugin and never shipped.

list( , $mode, $slug, $content, $hostport, $path ) = array_pad( $argv, 6, '' );
$host = preg_replace( '/:.*$/', '', $hostport );
$lo   = trim( $path, '/' );
$up   = preg_replace_callback(
	'/%[0-9a-f]{2}/i',
	function ( $m ) {
		return strtoupper( $m[0] );
	},
	$lo
);
$dec  = rawurldecode( $lo );
$join = function ( $base, $relative ) {
	return rtrim( $base, '/' ) . ( '' === $relative ? '' : '/' . $relative );
};

$dirs  = array();
$files = array();
$live  = '/^$/';
$stale = '/^$/';
$plant = 'index.html';
$base  = '';

switch ( $slug ) {
	case 'wp_super_cache':
		$base  = $content . '/cache/supercache/' . $host;
		$dirs  = array( $join( $base, $up ) );
		$live  = '/^(index.*\.html(\.gz)?|(meta-)?wp-cache-[0-9a-f]{32}\.php)$/';
		$stale = '/^index.*\.html(\.gz)?\.needs-rebuild$/';
		break;
	case 'wp_fastest_cache':
		$base = $content . '/cache/all';
		$dirs = array( $join( $base, $dec ) );
		$live = '/^index\.html$/';
		break;
	case 'w3_total_cache':
		$base  = $content . '/cache/page_enhanced/' . $hostport;
		$dirs  = array( $join( $base, $lo ) );
		$live  = '/^_index.*\.html(_gzip|_br)?$/';
		$stale = '/^_index.*\.html(_gzip|_br)?_old$/';
		$plant = '_index_slash.html';
		break;
	case 'wp_optimize':
		$base = $content . '/cache/wpo-cache/' . $host;
		$dirs = array( $join( $base, strtolower( $dec ) ) );
		$live = '/^(mobile\.)?index\.html(\.gz)?$/';
		break;
	case 'cache_enabler':
		$base  = $content . '/cache/cache-enabler/' . $host;
		$dirs  = array( $join( $base, $lo ), $join( $base, $up ) );
		$live  = '/^https?-index.*\.html(\.gz|\.br)?$/';
		$plant = 'http-index.html';
		break;
	case 'hummingbird':
		$base  = $content . '/wphb-cache/cache/' . $hostport;
		$other = $content . '/wphb-cache/cache/mobile/' . $hostport;
		$dirs  = array( $join( $base, $lo ), $join( $base, $up ), $join( $other, $lo ), $join( $other, $up ) );
		$live  = '/^[0-9a-f]{32}\.(html|php)$/';
		$plant = str_repeat( 'a', 32 ) . '.html';
		break;
	case 'jetpack_boost':
		$base  = $content . '/boost-cache/cache/' . $hostport;
		$dirs  = array( $join( $base, $lo ), $join( $base, $up ) );
		$live  = '/^[0-9a-f]{32}\.html$/';
		$plant = str_repeat( 'a', 32 ) . '.html';
		break;
	case 'speedycache':
		$base = $content . '/cache/speedycache/' . $hostport . '/all';
		$dirs = array( $join( $base, $dec ), $join( $content . '/cache/speedycache/' . $hostport . '/mobile-cache', $dec ) );
		$live = '/^index.*\.html(\.gz)?$/';
		break;
	case 'breeze':
		$base = $content . '/cache/breeze';
		foreach ( array( 'http', 'https' ) as $scheme ) {
			foreach ( array( $lo, $up ) as $spelling ) {
				$dirs[] = $base . '/' . hash( 'sha256', $scheme . '://' . $hostport . '/' . ( '' === $spelling ? '' : $spelling . '/' ) );
			}
		}
		$live  = '/^(mobile_)?[0-9a-f]{64}\.html$/';
		$plant = str_repeat( 'a', 64 ) . '.html';
		break;
	case 'siteground':
		$base  = $content . '/cache/sgo-cache';
		$roots = array();
		foreach ( is_dir( $base ) ? scandir( $base ) : array() as $name ) {
			if ( preg_match( '/^' . preg_quote( $host, '/' ) . '-([0-9a-f]{64}-)?[0-9a-f]{32}$/', $name ) ) {
				$roots[] = $base . '/' . $name;
			}
		}
		if ( 'plant' === $mode && array() === $roots ) {
			$roots[] = $base . '/' . $host . '-' . str_repeat( '0', 32 );
		}
		foreach ( $roots as $root ) {
			$dirs[] = $join( $root, $lo );
			$dirs[] = $join( $root, $up );
		}
		$live  = '/^index.*\.html$/';
		$plant = 'index-v2.html';
		break;
	case 'nginx_helper':
		// nginx names the file md5( scheme . method . host . uri ), levels 1:2, host without port.
		$base = '/tmp/aafm_accept_ngx/cache';
		foreach ( array_unique( array( $lo, $up ) ) as $spelling ) {
			$hash    = md5( 'httpGET' . $host . '/' . ( '' === $spelling ? '' : $spelling . '/' ) );
			$files[] = $base . '/' . substr( $hash, -1 ) . '/' . substr( $hash, -3, 2 ) . '/' . $hash;
		}
		break;
}

$dirs = array_values( array_unique( $dirs ) );

if ( 'base' === $mode ) {
	echo $base, "\n";
	exit( 0 );
}
if ( 'plant' === $mode ) {
	echo array() !== $files ? $files[0] : ( array() !== $dirs ? $dirs[0] . '/' . $plant : '' ), "\n";
	exit( 0 );
}

clearstatcache();
if ( 'live' === $mode ) {
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			echo $file, "\n";
		}
	}
}
$regex = 'stale' === $mode ? $stale : $live;
foreach ( $dirs as $dir ) {
	foreach ( is_dir( $dir ) ? scandir( $dir ) : array() as $name ) {
		if ( is_file( $dir . '/' . $name ) && preg_match( $regex, $name ) ) {
			echo $dir . '/' . $name, "\n";
		}
	}
}
// phpcs:enable
