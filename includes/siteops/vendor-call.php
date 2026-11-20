<?php
/**
 * Site Ops: the wrapper around every call into another plugin, the HTTP guard, and the helpers
 * that look at another plugin's cache files without ever changing them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether this process is WP-CLI.
 *
 * @return bool
 */
function aafm_siteops_is_cli(): bool {
	/**
	 * Filters whether the Site Ops tools treat this process as WP-CLI. A seam for tests: the
	 * WP_CLI constant cannot be undefined again once a test has defined it.
	 *
	 * @param bool $is_cli Whether WP_CLI is defined and true.
	 */
	return (bool) apply_filters( 'aafm_siteops_is_cli', defined( 'WP_CLI' ) && constant( 'WP_CLI' ) );
}

/**
 * This site's host as a visitor's browser sends it: the lower-case host of home_url(), plus the
 * port when the home URL has one.
 *
 * @return string
 */
function aafm_siteops_canonical_host(): string {
	$home = wp_parse_url( home_url( '/' ) );
	if ( ! is_array( $home ) || empty( $home['host'] ) ) {
		return '';
	}
	$host = strtolower( (string) $home['host'] );
	return isset( $home['port'] ) ? $host . ':' . (int) $home['port'] : $host;
}

/**
 * Run one function that calls into another plugin, with the request superglobals emptied, the
 * Host header set to this site's own, output discarded and wp_die() contained.
 *
 * A bare exit or die() in the other plugin cannot be caught here; nothing can.
 *
 * @param callable $callback No arguments.
 * @return array{ok:bool,value:mixed} ok is false when the call threw or called wp_die(). The
 *                                     Throwable's class goes to the error log, never to the caller.
 */
function aafm_siteops_vendor_call( callable $callback ): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Saved and restored unchanged around a vendor call; no request value is read.
	$saved_get     = $_GET;
	$saved_post    = $_POST;
	$saved_request = $_REQUEST;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();

	$had_host = isset( $_SERVER['HTTP_HOST'] );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved and restored unchanged around a vendor call; the value is never used.
	$saved_host           = $had_host ? $_SERVER['HTTP_HOST'] : null;
	$_SERVER['HTTP_HOST'] = aafm_siteops_canonical_host();

	$level = ob_get_level();
	ob_start();

	$die_handler = static function () {
		return static function (): void {
			throw new \RuntimeException( 'aafm_siteops_wp_die' );
		};
	};
	$die_filters = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xml_handler', 'wp_die_xmlrpc_handler' );
	foreach ( $die_filters as $die_filter ) {
		add_filter( $die_filter, $die_handler, PHP_INT_MAX );
	}

	$ok    = true;
	$value = null;
	try {
		$value = $callback();
	} catch ( \Throwable $e ) {
		$ok    = false;
		$value = null;
		error_log( 'aafm siteops vendor call: ' . get_class( $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator diagnostic: the Throwable class of a failed vendor call, never its message.
	} finally {
		foreach ( $die_filters as $die_filter ) {
			remove_filter( $die_filter, $die_handler, PHP_INT_MAX );
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		if ( $had_host ) {
			$_SERVER['HTTP_HOST'] = $saved_host;
		} else {
			unset( $_SERVER['HTTP_HOST'] );
		}
		$_GET     = $saved_get;
		$_POST    = $saved_post;
		$_REQUEST = $saved_request;
	}

	return array(
		'ok'    => $ok,
		'value' => $value,
	);
}

/**
 * The same wrapper, with every outbound HTTP request refused for its duration.
 *
 * @param callable $callback No arguments.
 * @return array{ok:bool,value:mixed}
 */
function aafm_siteops_no_http_call( callable $callback ): array {
	$refuse = static function () {
		return new WP_Error( 'aafm_site_no_http', __( 'Outbound requests are blocked while this tool runs.', 'agent-abilities-for-mcp' ) );
	};
	add_filter( 'pre_http_request', $refuse, PHP_INT_MAX );
	try {
		return aafm_siteops_vendor_call( $callback );
	} finally {
		remove_filter( 'pre_http_request', $refuse, PHP_INT_MAX );
	}
}

/**
 * The HTTP guard's state for this process.
 *
 * @return array{active:bool,count:int}
 */
function &aafm_siteops_http_guard_state(): array {
	static $state = array(
		'active' => false,
		'count'  => 0,
	);
	return $state;
}

/**
 * Start counting and capping the HTTP requests other plugins send. Called by the fan-out
 * dispatcher before the first adapter.
 *
 * @return void
 */
function aafm_siteops_http_guard_begin(): void {
	$state           =& aafm_siteops_http_guard_state();
	$state['active'] = true;
	$state['count']  = 0;
	if ( false === has_filter( 'http_request_args', 'aafm_siteops_http_guard_args' ) ) {
		add_filter( 'http_request_args', 'aafm_siteops_http_guard_args', PHP_INT_MAX, 2 );
	}
	if ( false === has_filter( 'pre_http_request', 'aafm_siteops_http_guard_pre' ) ) {
		add_filter( 'pre_http_request', 'aafm_siteops_http_guard_pre', PHP_INT_MAX, 1 );
	}
}

/**
 * Stop counting. Called by the dispatcher in a finally block after the last adapter.
 *
 * In a web request the argument filter stays until the request ends, for requests to this site's
 * own host and port only, because a cache plugin can fetch the purged page after its purge
 * returned. In a WP-CLI process, which can serve many tool calls, it is removed here too.
 *
 * @return void
 */
function aafm_siteops_http_guard_end(): void {
	$state           =& aafm_siteops_http_guard_state();
	$state['active'] = false;
	remove_filter( 'pre_http_request', 'aafm_siteops_http_guard_pre', PHP_INT_MAX );
	if ( aafm_siteops_is_cli() ) {
		remove_filter( 'http_request_args', 'aafm_siteops_http_guard_args', PHP_INT_MAX );
	}
}

/**
 * Filter callback on http_request_args: no redirect is followed and the timeout is lowered, for
 * every request while the guard is active and for requests to this site afterwards.
 *
 * Stated limit: in a web request another plugin's loopback request made after a purge and before
 * the request ends also gets no redirects and the short timeout. That is accepted.
 *
 * @param mixed $args Request arguments.
 * @param mixed $url  Request URL.
 * @return mixed
 */
function aafm_siteops_http_guard_args( $args, $url ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}
	$state = aafm_siteops_http_guard_state();
	if ( ! $state['active'] ) {
		$parts = wp_parse_url( (string) $url );
		$host  = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' !== $host && is_array( $parts ) && isset( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}
		if ( '' === $host || aafm_siteops_canonical_host() !== $host ) {
			return $args;
		}
	}

	require_once __DIR__ . '/budget.php';
	$settings            = aafm_site_purge_budget_settings();
	$args['redirection'] = 0;
	if ( ! isset( $args['timeout'] ) || (float) $args['timeout'] > $settings['http_timeout'] ) {
		$args['timeout'] = $settings['http_timeout'];
	}
	return $args;
}

/**
 * Filter callback on pre_http_request: counts requests while the guard is active and refuses
 * those past the cap. A request somebody else already answered is not counted.
 *
 * @param mixed $pre False, or the answer another filter already gave.
 * @return mixed
 */
function aafm_siteops_http_guard_pre( $pre ) {
	$state =& aafm_siteops_http_guard_state();
	if ( ! $state['active'] || false !== $pre ) {
		return $pre;
	}

	require_once __DIR__ . '/budget.php';
	$settings = aafm_site_purge_budget_settings();
	++$state['count'];
	if ( $state['count'] > $settings['http_cap'] ) {
		return new WP_Error( 'aafm_site_vendor_http_cap', __( 'Too many requests were sent during one purge.', 'agent-abilities-for-mcp' ) );
	}
	return $pre;
}

/**
 * The spellings of a canonical URL that cache plugins use for directory names.
 *
 * @param array<string,mixed> $url The array aafm_siteops_canonical_url() returns.
 * @return array{host:string,hostport:string,lo:string,up:string,dec:string}
 */
function aafm_siteops_path_spellings( array $url ): array {
	$parts    = wp_parse_url( isset( $url['url'] ) ? (string) $url['url'] : '' );
	$host     = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
	$hostport = ( '' !== $host && is_array( $parts ) && isset( $parts['port'] ) ) ? $host . ':' . (int) $parts['port'] : $host;
	$lower    = trim( isset( $url['path_encoded'] ) ? (string) $url['path_encoded'] : '', '/' );
	$upper    = (string) preg_replace_callback(
		'/%[0-9a-f]{2}/',
		static function ( array $found ): string {
			return strtoupper( $found[0] );
		},
		$lower
	);

	return array(
		'host'     => $host,
		'hostport' => $hostport,
		'lo'       => $lower,
		'up'       => $upper,
		'dec'      => trim( isset( $url['path'] ) ? (string) $url['path'] : '', '/' ),
	);
}

/**
 * A canonical URL with every percent escape of its path in upper case: the same origin, the
 * spelling a browser sends for an address typed in raw Unicode.
 *
 * @param array<string,mixed> $url The array aafm_siteops_canonical_url() returns.
 * @return string
 */
function aafm_siteops_upper_url( array $url ): string {
	$full    = isset( $url['url'] ) ? (string) $url['url'] : '';
	$encoded = isset( $url['path_encoded'] ) ? (string) $url['path_encoded'] : '';
	if ( '' === $encoded || strlen( $full ) < strlen( $encoded ) ) {
		return $full;
	}
	$origin = substr( $full, 0, strlen( $full ) - strlen( $encoded ) );
	$upper  = (string) preg_replace_callback(
		'/%[0-9a-f]{2}/',
		static function ( array $found ): string {
			return strtoupper( $found[0] );
		},
		$encoded
	);
	return $origin . $upper;
}

/**
 * The spellings of this site's home URL, for whole-site purges, which take no URL.
 *
 * @return array{host:string,hostport:string,lo:string,up:string,dec:string}
 */
function aafm_siteops_home_spellings(): array {
	require_once __DIR__ . '/url.php';
	$url = aafm_siteops_canonical_url( home_url( '/' ) );
	return aafm_siteops_path_spellings( is_array( $url ) ? $url : array() );
}

/**
 * Resolve a cache path and prove it is inside the provider's cache directory.
 *
 * @param string $base     The provider's cache root. Must be an existing directory.
 * @param string $relative Path below it, built from an already canonical URL path. May be '' for the base itself.
 * @return string|null Absolute path without a trailing slash, or null when $base is not a directory or the path is not inside it.
 */
function aafm_siteops_confined_path( string $base, string $relative ): ?string {
	if ( ! is_dir( $base ) ) {
		return null;
	}
	$real_base = realpath( $base );
	if ( false === $real_base ) {
		return null;
	}

	$candidate = $real_base;
	if ( '' !== $relative ) {
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}
		$candidate .= '/' . $relative;
	}

	// Walk up to the deepest thing that exists and make sure it is still inside the base. This is
	// what catches a symbolic link inside the cache tree that points out of it.
	$probe = $candidate;
	while ( ! file_exists( $probe ) && ! is_link( $probe ) ) {
		$parent = dirname( $probe );
		if ( $parent === $probe ) {
			return null;
		}
		$probe = $parent;
	}
	$real_probe = realpath( $probe );
	if ( false === $real_probe ) {
		return null;
	}
	if ( $real_probe !== $real_base && 0 !== strpos( $real_probe, $real_base . '/' ) ) {
		return null;
	}

	return $candidate;
}

/**
 * The names of the files in one directory that match a pattern.
 *
 * Uses scandir() and never glob(), because glob() reads a bracket in the directory path as a
 * pattern and percent-encoded directory names must be matched literally.
 *
 * @param string $dir   Directory.
 * @param string $regex A fixed pattern from an adapter, matched against file names. Never input.
 * @return list<string>|null Empty when the directory does not exist; null when it exists and cannot be read.
 */
function aafm_siteops_cache_file_names( string $dir, string $regex ): ?array {
	clearstatcache();
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	if ( ! is_readable( $dir ) ) {
		return null;
	}
	$entries = scandir( $dir );
	if ( false === $entries ) {
		return null;
	}

	$names = array();
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		if ( 1 === preg_match( $regex, $entry ) && is_file( $dir . '/' . $entry ) ) {
			$names[] = $entry;
		}
	}
	return $names;
}

/**
 * Whether one directory holds at least one file that matches a pattern.
 *
 * @param string $dir   A path returned by aafm_siteops_confined_path().
 * @param string $regex A fixed pattern from the adapter, matched against file names. Never input.
 * @return bool|null True when at least one file in $dir matches, false when none does or $dir does not exist, null when $dir exists and cannot be read.
 */
function aafm_siteops_cache_files_present( string $dir, string $regex ): ?bool {
	$names = aafm_siteops_cache_file_names( $dir, $regex );
	return null === $names ? null : array() !== $names;
}

/**
 * The matching files of one directory with what identifies each as the same file.
 *
 * @param string $dir   A path returned by aafm_siteops_confined_path().
 * @param string $regex A fixed pattern from the adapter, matched against file names. Never input.
 * @return array<string,array{0:int,1:int}>|null File name to array( filemtime, fileinode ); null when $dir exists and cannot be read; empty when it does not exist.
 */
function aafm_siteops_cache_files_stat( string $dir, string $regex ): ?array {
	$names = aafm_siteops_cache_file_names( $dir, $regex );
	if ( null === $names ) {
		return null;
	}
	$stat = array();
	foreach ( $names as $name ) {
		$stat[ $name ] = array( (int) filemtime( $dir . '/' . $name ), (int) fileinode( $dir . '/' . $name ) );
	}
	return $stat;
}

/**
 * Whether any file whose name matches a pattern exists anywhere under a directory. Walks at most
 * 5,000 entries and never follows a symbolic link.
 *
 * @param string $base  Directory to walk.
 * @param string $regex A fixed pattern from the adapter, matched against file names. Never input.
 * @return bool|null Null when $base is not a readable directory, a directory below it cannot be read, or the walk hit the cap.
 */
function aafm_siteops_cache_tree_has_files( string $base, string $regex ): ?bool {
	clearstatcache();
	if ( ! is_dir( $base ) || ! is_readable( $base ) ) {
		return null;
	}

	$stack = array( rtrim( $base, '/' ) );
	$seen  = 0;
	while ( array() !== $stack ) {
		$dir     = (string) array_pop( $stack );
		$entries = is_readable( $dir ) ? scandir( $dir ) : false;
		if ( false === $entries ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			++$seen;
			if ( $seen > 5000 ) {
				return null;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$stack[] = $path;
				continue;
			}
			if ( is_file( $path ) && 1 === preg_match( $regex, $entry ) ) {
				return true;
			}
		}
	}
	return false;
}
