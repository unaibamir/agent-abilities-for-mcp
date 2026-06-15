<?php
/**
 * OAuth dynamic client registry.
 *
 * Registers public OAuth clients (token_endpoint_auth_method "none", no secret) and
 * validates their redirect URIs against the strict allowlist the spec requires.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register a public OAuth client.
 *
 * Validates every redirect URI, generates a 32-hex client_id, and persists the
 * client row. This is a public-client model: there is no client secret to store.
 *
 * @param array<string,mixed> $req {
 *     Registration request.
 *
 *     @type string[] $redirect_uris              Required. 1-10 absolute redirect URIs.
 *     @type string   $client_name                Optional display name.
 *     @type string[] $grant_types                Optional. Defaults to authorization_code + refresh_token.
 *     @type string[] $response_types             Optional. Defaults to code.
 * }
 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|\WP_Error
 */
function aafm_oauth_register_client( array $req ) {
	$redirect_uris = isset( $req['redirect_uris'] ) && is_array( $req['redirect_uris'] )
		? array_values( $req['redirect_uris'] )
		: array();

	if ( empty( $redirect_uris ) ) {
		return new WP_Error(
			'invalid_redirect_uri',
			__( 'At least one redirect URI is required.', 'agent-abilities-for-mcp' )
		);
	}

	if ( count( $redirect_uris ) > 10 ) {
		return new WP_Error(
			'invalid_redirect_uri',
			__( 'A client may register at most 10 redirect URIs.', 'agent-abilities-for-mcp' )
		);
	}

	foreach ( $redirect_uris as $uri ) {
		if ( ! is_string( $uri ) || ! aafm_oauth_validate_redirect_uri( $uri ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				__( 'A redirect URI is not allowed.', 'agent-abilities-for-mcp' )
			);
		}
	}

	$client_name    = isset( $req['client_name'] ) ? sanitize_text_field( (string) $req['client_name'] ) : '';
	$grant_types    = isset( $req['grant_types'] ) && is_array( $req['grant_types'] )
		? array_values( array_map( 'sanitize_text_field', $req['grant_types'] ) )
		: array( 'authorization_code', 'refresh_token' );
	$response_types = isset( $req['response_types'] ) && is_array( $req['response_types'] )
		? array_values( array_map( 'sanitize_text_field', $req['response_types'] ) )
		: array( 'code' );

	$client_id = bin2hex( random_bytes( 16 ) );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'aafm_oauth_clients',
		array(
			'client_id'      => $client_id,
			'client_name'    => $client_name,
			'redirect_uris'  => wp_json_encode( $redirect_uris ),
			'grant_types'    => wp_json_encode( $grant_types ),
			'response_types' => wp_json_encode( $response_types ),
			'created_by_ip'  => aafm_source_ip(),
			'is_active'      => 1,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
	);

	if ( false === $inserted ) {
		return new WP_Error(
			'registration_failed',
			__( 'Could not store the client registration.', 'agent-abilities-for-mcp' )
		);
	}

	return array(
		'client_id'     => $client_id,
		'client_name'   => $client_name,
		'redirect_uris' => $redirect_uris,
	);
}

/**
 * Validate a single redirect URI against the registration allowlist.
 *
 * Enforces: non-empty and at most 2048 bytes; no wildcard anywhere; no fragment and
 * no userinfo component; a present host; and a scheme of exactly https, or http only
 * when the host is a loopback address (localhost, 127.0.0.1, ::1). The scheme is
 * matched against an explicit allowlist so odd-parsing values such as
 * "javascript:alert(1)" cannot slip through.
 *
 * @param string $uri Candidate redirect URI.
 * @return bool True when the URI is allowed.
 */
function aafm_oauth_validate_redirect_uri( string $uri ): bool {
	if ( '' === $uri || strlen( $uri ) > 2048 ) {
		return false;
	}

	// Wildcards are never permitted in a registered redirect URI.
	if ( false !== strpos( $uri, '*' ) ) {
		return false;
	}

	$parts = wp_parse_url( $uri );
	if ( ! is_array( $parts ) ) {
		return false;
	}

	// No fragment and no userinfo (user:pass@) components.
	if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

	if ( '' === $host ) {
		return false;
	}

	if ( 'https' === $scheme ) {
		return true;
	}

	// Plain http is allowed only for loopback hosts (native-app / local-dev clients).
	if ( 'http' === $scheme ) {
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	return false;
}
