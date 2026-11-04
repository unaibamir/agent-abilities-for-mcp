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
 * Normalize an incoming Origin header to scheme://host[:port]: lowercase scheme and host, an
 * internationalised host in its ASCII (punycode) form, default port dropped, at most one trailing
 * slash allowed.
 *
 * @param string $origin Header value.
 * @return string '' for anything else, including "null", a path, userinfo, a query or a fragment.
 */
function aafm_normalize_origin( string $origin ): string {
	$origin = trim( $origin );
	if ( ! preg_match( '#^(https?)://([\p{L}\p{N}.-]+)(?::([0-9]{1,5}))?/?$#iu', $origin, $m ) ) {
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
	$scheme = strtolower( $scheme );
	$host   = strtolower( $host );
	// Browsers send an internationalised host as punycode, so the site's own host is compared in that
	// form. Without the intl extension a Unicode host stays as written and fails closed.
	if ( function_exists( 'idn_to_ascii' ) && 1 === preg_match( '/[^\x00-\x7F]/', $host ) ) {
		$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
		$host  = false !== $ascii ? $ascii : $host;
	}
	$default = 'https' === $scheme ? 443 : 80;
	$origin  = $scheme . '://' . $host;
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
 * The 403 for an Origin header that is not allowed, or null when it is. A refusal writes its one
 * denial row here, so both guards below log the same way.
 *
 * @param string                $header  The Origin header value.
 * @param \WP_REST_Request|null $request The request, when there is one.
 * @return WP_Error|null
 */
function aafm_mcp_origin_error( string $header, $request = null ): ?WP_Error {
	if ( in_array( aafm_normalize_origin( $header ), aafm_mcp_allowed_origins(), true ) ) {
		return null;
	}
	$host = wp_parse_url( $header, PHP_URL_HOST );
	$host = substr( aafm_sanitize_plain_text( is_string( $host ) ? $host : $header ), 0, 100 );
	aafm_log_transport_denial( 'org', 'origin_not_allowed', '', $request, 'origin:' . $host );
	return new WP_Error( 'aafm_origin_not_allowed', __( 'This site does not accept MCP requests from that web page.', 'agent-abilities-for-mcp' ), array( 'status' => 403 ) );
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
	return aafm_mcp_origin_error( (string) $header, $request ) ?? $result;
}
add_filter( 'rest_pre_dispatch', 'aafm_mcp_origin_guard', -1, 3 );

/**
 * Refuse a foreign Origin on the MCP route before authentication decides.
 *
 * Core authenticates before it dispatches, so a bad Application Password is answered with its own 401
 * ahead of rest_pre_dispatch. This runs first on rest_authentication_errors so a disallowed Origin is a
 * 403 whatever credentials ride along. Only the MCP route is touched; OAuth and discovery are not.
 *
 * @param mixed $errors Earlier authentication result.
 * @return mixed $errors, or a 403 WP_Error.
 */
function aafm_mcp_origin_auth_guard( $errors ) {
	if ( ! function_exists( 'aafm_is_mcp_route' ) || ! isset( $GLOBALS['wp'] ) || ! is_object( $GLOBALS['wp'] ) ) {
		return $errors;
	}
	$route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
	if ( ! is_string( $route ) || ! aafm_is_mcp_route( untrailingslashit( $route ) ) ) {
		return $errors;
	}
	// Core's get_headers() builds the origin header from this same server variable.
	if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) || ! is_string( $_SERVER['HTTP_ORIGIN'] ) ) {
		return $errors;
	}
	$header = wp_unslash( $_SERVER['HTTP_ORIGIN'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against an allow-list, never stored raw.
	return aafm_mcp_origin_error( $header ) ?? $errors;
}
add_filter( 'rest_authentication_errors', 'aafm_mcp_origin_auth_guard', 1 );
