<?php
/**
 * Origin validation on the MCP route. The MCP spec (2025-11-25, Streamable HTTP) requires a server
 * to validate the Origin header and answer 403 when it is present and not allowed. A request with
 * no Origin header (every server-side client) passes untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Normalize an incoming Origin header to scheme://host[:port]: lowercase scheme and host, default
 * port dropped, at most one trailing slash allowed.
 *
 * @param string $origin Header value.
 * @return string '' for anything else, including "null", a path, userinfo, a query or a fragment.
 */
function aafm_normalize_origin( string $origin ): string {
	$origin = trim( $origin );
	if ( ! preg_match( '#^(https?)://([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?/?$#', $origin, $m ) ) {
		return '';
	}
	return aafm_origin_from_parts( $m[1], $m[2], isset( $m[3] ) ? (int) $m[3] : null );
}

/**
 * The origin of a configured URL: scheme, host and port only, so a subdirectory install still
 * matches its own origin.
 *
 * @param string $url URL.
 * @return string '' when the URL has no scheme or host.
 */
function aafm_site_origin( string $url ): string {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return '';
	}
	return aafm_origin_from_parts( $parts['scheme'], $parts['host'], isset( $parts['port'] ) ? (int) $parts['port'] : null );
}

/**
 * Build a normalized origin.
 *
 * @param string   $scheme http or https.
 * @param string   $host   Host.
 * @param int|null $port   Port, or null for the default.
 * @return string
 */
function aafm_origin_from_parts( string $scheme, string $host, ?int $port ): string {
	$scheme  = strtolower( $scheme );
	$default = 'https' === $scheme ? 443 : 80;
	$origin  = $scheme . '://' . strtolower( $host );
	return null !== $port && $port !== $default ? $origin . ':' . $port : $origin;
}

/**
 * The origins allowed on the MCP route: this site's own, plus the hosted clients this plugin
 * supports.
 *
 * @return list<string>
 */
function aafm_mcp_allowed_origins(): array {
	$list = array(
		aafm_site_origin( home_url() ),
		aafm_site_origin( site_url() ),
		'https://claude.ai',
		'https://claude.com',
		'https://chatgpt.com',
		'https://chat.openai.com',
	);
	/**
	 * Filter the browser origins allowed to call the MCP endpoint.
	 *
	 * Add the origin of a browser-based MCP client you trust, as scheme://host[:port]. A request with
	 * no Origin header is never affected.
	 *
	 * @param list<string> $list Allowed origins.
	 */
	$list = apply_filters( 'aafm_mcp_allowed_origins', $list );
	$out  = array();
	foreach ( is_array( $list ) ? $list : array() as $entry ) {
		$origin = is_string( $entry ) ? aafm_normalize_origin( $entry ) : '';
		if ( '' !== $origin && ! in_array( $origin, $out, true ) ) {
			$out[] = $origin;
		}
	}
	return $out;
}

/**
 * Refuse an MCP request whose Origin header is present and not allowed.
 *
 * @param mixed            $result  Earlier pre-dispatch result.
 * @param mixed            $server  REST server.
 * @param \WP_REST_Request $request Request.
 * @return mixed $result, or a 403 WP_Error.
 */
function aafm_mcp_origin_guard( $result, $server, $request ) {
	unset( $server );
	// The route helper loads with bootstrap.php inside aafm_bootstrap(); before that, leave the request alone.
	if ( ! function_exists( 'aafm_is_mcp_route' ) || null !== $result || ! $request instanceof WP_REST_Request ) {
		return $result;
	}
	if ( ! aafm_is_mcp_route( (string) $request->get_route() ) ) {
		return $result;
	}
	$header = $request->get_header( 'origin' );
	if ( null === $header ) {
		return $result;
	}
	if ( in_array( aafm_normalize_origin( (string) $header ), aafm_mcp_allowed_origins(), true ) ) {
		return $result;
	}
	$host = wp_parse_url( (string) $header, PHP_URL_HOST );
	$host = substr( aafm_sanitize_plain_text( is_string( $host ) ? $host : (string) $header ), 0, 100 );
	aafm_log_transport_denial( 'org', 'origin_not_allowed', '', $request, 'origin:' . $host );
	return new WP_Error( 'aafm_origin_not_allowed', __( 'This site does not accept MCP requests from that web page.', 'agent-abilities-for-mcp' ), array( 'status' => 403 ) );
}
add_filter( 'rest_pre_dispatch', 'aafm_mcp_origin_guard', -1, 3 );
