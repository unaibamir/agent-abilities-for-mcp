<?php
/**
 * OAuth discovery: the two .well-known metadata documents and their routing.
 *
 * MCP clients locate the authorization server before any REST authentication
 * runs, so these documents are served directly off `parse_request` (priority 0).
 * The metadata builders are pure array factories and the path matcher is a pure
 * predicate; the request wrapper layers headers, output, and exit on top.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether a stored toggle option counts as "off".
 *
 * `get_option( $key, $default )` returns $default whenever the stored value is
 * strictly === false, so a truthy default would read a literal boolean-false
 * "off" switch as on — fail-open on a public token surface. Defaulting to '1'
 * and treating every falsy stored form as off closes that gap: the option is on
 * only when it was never set or holds a genuinely truthy value.
 *
 * @param string $key The toggle option name.
 * @return bool True when the toggle is enabled, false when explicitly disabled.
 */
function aafm_oauth_option_is_on( string $key ): bool {
	$value = get_option( $key, '1' );

	$off = array( false, 0, '0', '', 'false', 'no', 'off' );

	return ! in_array( $value, $off, true ) && (bool) $value;
}

/**
 * Whether the OAuth surface is enabled.
 *
 * @return bool True unless the operator has explicitly disabled OAuth.
 */
function aafm_oauth_enabled(): bool {
	return aafm_oauth_option_is_on( 'aafm_oauth_enabled' );
}

/**
 * Whether Dynamic Client Registration is enabled.
 *
 * @return bool True unless the operator has explicitly disabled DCR.
 */
function aafm_oauth_dcr_enabled(): bool {
	return aafm_oauth_option_is_on( 'aafm_oauth_dcr_enabled' );
}

/**
 * Protected-resource metadata (RFC 9728).
 *
 * Advertises the MCP endpoint as the protected resource, this site as its
 * authorization server, and that bearer tokens travel in the Authorization
 * header.
 *
 * @return array<string, mixed>
 */
function aafm_oauth_protected_resource_metadata(): array {
	return array(
		'resource'                 => aafm_endpoint_url(),
		'authorization_servers'    => array( home_url() ),
		'bearer_methods_supported' => array( 'header' ),
	);
}

/**
 * Authorization-server metadata (RFC 8414).
 *
 * Describes the endpoints and capabilities of this site as an OAuth 2.1
 * authorization server: authorization code with PKCE S256, refresh tokens, and
 * public clients (no client secret) registered via DCR.
 *
 * @return array<string, mixed>
 */
function aafm_oauth_authorization_server_metadata(): array {
	return array(
		'issuer'                                => home_url(),
		'authorization_endpoint'                => add_query_arg( 'aafm_oauth', 'authorize', home_url( '/' ) ),
		'token_endpoint'                        => rest_url( 'agent-abilities-for-mcp/oauth/token' ),
		'registration_endpoint'                 => rest_url( 'agent-abilities-for-mcp/oauth/register' ),
		'revocation_endpoint'                   => rest_url( 'agent-abilities-for-mcp/oauth/revoke' ),
		'response_types_supported'              => array( 'code' ),
		'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
		'code_challenge_methods_supported'      => array( 'S256' ),
		'token_endpoint_auth_methods_supported' => array( 'none' ),
	);
}

/**
 * The WWW-Authenticate challenge advertising the protected-resource metadata.
 *
 * Attached to the transport's 401 so a client that arrives unauthenticated learns
 * where to discover the authorization server (RFC 9728 resource_metadata). Points
 * at the same .well-known document aafm_oauth_maybe_serve_well_known() emits.
 *
 * @return string The Bearer challenge value for the WWW-Authenticate header.
 */
function aafm_oauth_challenge_header(): string {
	return 'Bearer resource_metadata="' . home_url( '/.well-known/oauth-protected-resource' ) . '"';
}

/**
 * Promote the www_authenticate error data to a real WWW-Authenticate header.
 *
 * A WP_Error returned from a permission_callback is serialized into the response
 * body's `data` array, so the challenge attached by the transport never reaches
 * the wire as a header on its own. This rest_post_dispatch filter copies it up
 * onto the 401 response. Defensive by design: it acts only on a 401 carrying a
 * non-empty www_authenticate key and otherwise returns the response untouched.
 *
 * @param \WP_HTTP_Response $response The dispatch result.
 * @param \WP_REST_Server   $server   The REST server (unused).
 * @param \WP_REST_Request  $request  The request (unused).
 * @return \WP_HTTP_Response The response, with the header set when applicable.
 */
function aafm_oauth_filter_rest_challenge( $response, $server, $request ) {
	unset( $server, $request );

	if ( ! $response instanceof WP_REST_Response || 401 !== $response->get_status() ) {
		return $response;
	}

	$data = $response->get_data();

	if ( is_array( $data ) && ! empty( $data['data']['www_authenticate'] ) ) {
		$response->header( 'WWW-Authenticate', (string) $data['data']['www_authenticate'] );
	}

	return $response;
}

/**
 * Match a request path against the two supported .well-known documents.
 *
 * The leading slash is optional and any query string is ignored by the caller
 * before this runs. Returns a stable key the request wrapper maps to a builder.
 *
 * @param string $path Request path (no query string).
 * @return string 'protected-resource', 'authorization-server', or '' for no match.
 */
function aafm_oauth_match_well_known( string $path ): string {
	$path = ltrim( $path, '/' );

	if ( '.well-known/oauth-protected-resource' === $path ) {
		return 'protected-resource';
	}

	if ( '.well-known/oauth-authorization-server' === $path ) {
		return 'authorization-server';
	}

	return '';
}

/**
 * Serve a .well-known OAuth document when the request targets one.
 *
 * Hooked on `parse_request` at priority 0 so the document is emitted before
 * WordPress REST authentication runs. Does nothing unless OAuth is enabled and
 * the path matches one of the two documents. Plaintext requests are refused
 * with a 403 when HTTPS is required, so metadata never leaks over http://.
 *
 * @return void
 */
function aafm_oauth_maybe_serve_well_known(): void {
	if ( ! aafm_oauth_enabled() ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';

	$path  = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	$which = aafm_oauth_match_well_known( $path );

	if ( '' === $which ) {
		return;
	}

	if ( aafm_oauth_https_required() && ! is_ssl() ) {
		status_header( 403 );
		exit;
	}

	$metadata = 'protected-resource' === $which
		? aafm_oauth_protected_resource_metadata()
		: aafm_oauth_authorization_server_metadata();

	header( 'Cache-Control: no-store' );
	header( 'Content-Type: application/json' );
	status_header( 200 );
	echo wp_json_encode( $metadata );
	exit;
}
