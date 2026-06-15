<?php
/**
 * OAuth REST endpoints: dynamic client registration, token, and revocation.
 *
 * Exposes the three public OAuth HTTP routes under the
 * `agent-abilities-for-mcp/oauth` namespace. Each route's permission_callback is
 * `__return_true` — the OAuth grant itself is the authentication, not a WordPress
 * capability. The handlers gate on the feature toggles, enforce the HTTPS policy,
 * and rate-limit before doing any work, then delegate the cryptographic and
 * storage logic to the clients/codes/tokens modules. Every success response
 * carries `Cache-Control: no-store` so tokens are never cached.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the three OAuth REST routes.
 *
 * Hooked on `rest_api_init`. All routes accept POST only and authenticate via the
 * grant, so each uses `__return_true` as its permission_callback.
 *
 * @return void
 */
function aafm_oauth_register_rest_routes(): void {
	$namespace = 'agent-abilities-for-mcp/oauth';

	register_rest_route(
		$namespace,
		'/register',
		array(
			'methods'             => 'POST',
			'callback'            => 'aafm_oauth_rest_register',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$namespace,
		'/token',
		array(
			'methods'             => 'POST',
			'callback'            => 'aafm_oauth_rest_token',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$namespace,
		'/revoke',
		array(
			'methods'             => 'POST',
			'callback'            => 'aafm_oauth_rest_revoke',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Attach `Cache-Control: no-store` to a REST response and return it.
 *
 * @param \WP_REST_Response $response The response to decorate.
 * @return \WP_REST_Response The same response, with the no-store header set.
 */
function aafm_oauth_rest_no_store( WP_REST_Response $response ): WP_REST_Response {
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Build an OAuth-style error.
 *
 * The error code becomes the OAuth `error` value and the HTTP status is carried
 * in the data array so WordPress renders the matching status code.
 *
 * @param string $code    The OAuth error code (e.g. 'invalid_grant').
 * @param string $message A non-leaky human-readable description.
 * @param int    $status  The HTTP status code to return.
 * @return \WP_Error
 */
function aafm_oauth_rest_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/**
 * Enforce the shared transport-security policy for an OAuth endpoint.
 *
 * @return \WP_Error|null A 400 error when HTTPS is required but the request is plain HTTP, otherwise null.
 */
function aafm_oauth_rest_require_https() {
	if ( aafm_oauth_https_required() && ! is_ssl() ) {
		return aafm_oauth_rest_error(
			'invalid_request',
			__( 'This endpoint requires HTTPS.', 'agent-abilities-for-mcp' ),
			400
		);
	}

	return null;
}

/**
 * POST /register — dynamic client registration (RFC 7591).
 *
 * Gated on both the OAuth and DCR toggles (404 when either is off), HTTPS-only in
 * production, and rate-limited. Delegates validation and storage to
 * aafm_oauth_register_client() and echoes back the public-client metadata.
 *
 * @param \WP_REST_Request $request The incoming request.
 * @return \WP_REST_Response|\WP_Error
 */
function aafm_oauth_rest_register( WP_REST_Request $request ) {
	if ( ! aafm_oauth_enabled() || ! aafm_oauth_dcr_enabled() ) {
		return aafm_oauth_rest_error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.', 'agent-abilities-for-mcp' ),
			404
		);
	}

	$https = aafm_oauth_rest_require_https();
	if ( null !== $https ) {
		return $https;
	}

	if ( ! aafm_oauth_rate_ok( 'register', 10, 100 ) ) {
		return aafm_oauth_rest_error(
			'rate_limited',
			__( 'Too many registration requests. Try again shortly.', 'agent-abilities-for-mcp' ),
			429
		);
	}

	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = array();
	}

	$redirect_uris  = isset( $params['redirect_uris'] ) && is_array( $params['redirect_uris'] ) ? $params['redirect_uris'] : array();
	$client_name    = isset( $params['client_name'] ) ? (string) $params['client_name'] : '';
	$grant_types    = isset( $params['grant_types'] ) && is_array( $params['grant_types'] ) ? $params['grant_types'] : array( 'authorization_code', 'refresh_token' );
	$response_types = isset( $params['response_types'] ) && is_array( $params['response_types'] ) ? $params['response_types'] : array( 'code' );

	$result = aafm_oauth_register_client(
		array(
			'redirect_uris'  => $redirect_uris,
			'client_name'    => $client_name,
			'grant_types'    => $grant_types,
			'response_types' => $response_types,
		)
	);

	if ( is_wp_error( $result ) ) {
		return aafm_oauth_rest_error(
			'invalid_redirect_uri' === $result->get_error_code() ? 'invalid_redirect_uri' : 'invalid_client_metadata',
			$result->get_error_message(),
			400
		);
	}

	$response = new WP_REST_Response(
		array(
			'client_id'                  => $result['client_id'],
			'client_name'                => $result['client_name'],
			'redirect_uris'              => $result['redirect_uris'],
			'grant_types'                => $grant_types,
			'response_types'             => $response_types,
			'token_endpoint_auth_method' => 'none',
		),
		201
	);

	return aafm_oauth_rest_no_store( $response );
}

/**
 * POST /token — the authorization_code and refresh_token grants.
 *
 * Gated on the OAuth toggle (404 when off), HTTPS-only in production, and
 * rate-limited. Dispatches on grant_type: authorization_code redeems the code and
 * verifies PKCE before minting tokens; refresh_token rotates via the token
 * manager (which runs its own transaction). Unknown grants are 400.
 *
 * @param \WP_REST_Request $request The incoming request.
 * @return \WP_REST_Response|\WP_Error
 */
function aafm_oauth_rest_token( WP_REST_Request $request ) {
	if ( ! aafm_oauth_enabled() ) {
		return aafm_oauth_rest_error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.', 'agent-abilities-for-mcp' ),
			404
		);
	}

	$https = aafm_oauth_rest_require_https();
	if ( null !== $https ) {
		return $https;
	}

	if ( ! aafm_oauth_rate_ok( 'token', 30, 300 ) ) {
		return aafm_oauth_rest_error(
			'rate_limited',
			__( 'Too many token requests. Try again shortly.', 'agent-abilities-for-mcp' ),
			429
		);
	}

	$grant_type = (string) $request->get_param( 'grant_type' );

	if ( 'authorization_code' === $grant_type ) {
		return aafm_oauth_rest_token_authorization_code( $request );
	}

	if ( 'refresh_token' === $grant_type ) {
		return aafm_oauth_rest_token_refresh( $request );
	}

	return aafm_oauth_rest_error(
		'unsupported_grant_type',
		__( 'The grant type is missing or not supported.', 'agent-abilities-for-mcp' ),
		400
	);
}

/**
 * Handle the authorization_code grant on the token endpoint.
 *
 * @param \WP_REST_Request $request The incoming request.
 * @return \WP_REST_Response|\WP_Error
 */
function aafm_oauth_rest_token_authorization_code( WP_REST_Request $request ) {
	$code          = (string) $request->get_param( 'code' );
	$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
	$client_id     = (string) $request->get_param( 'client_id' );
	$code_verifier = (string) $request->get_param( 'code_verifier' );

	$invalid_grant = aafm_oauth_rest_error(
		'invalid_grant',
		__( 'The authorization grant is invalid.', 'agent-abilities-for-mcp' ),
		400
	);

	if ( '' === $code || '' === $redirect_uri || '' === $client_id || '' === $code_verifier ) {
		return $invalid_grant;
	}

	// Atomic one-time redemption, with the client_id + redirect_uri binding
	// enforced inside aafm_oauth_redeem_code().
	$row = aafm_oauth_redeem_code( $code, $client_id, $redirect_uri );
	if ( is_wp_error( $row ) ) {
		return $invalid_grant;
	}

	// PKCE: a failed verifier burns the (already-consumed) code, which is safe.
	if ( ! aafm_pkce_verify( $code_verifier, (string) $row['code_challenge'] ) ) {
		return $invalid_grant;
	}

	$tokens = aafm_oauth_mint_tokens(
		array(
			'client_id'  => (string) $row['client_id'],
			'wp_user_id' => (int) $row['wp_user_id'],
			'resource'   => (string) $row['resource'],
		)
	);

	return aafm_oauth_rest_token_response( $tokens );
}

/**
 * Handle the refresh_token grant on the token endpoint.
 *
 * @param \WP_REST_Request $request The incoming request.
 * @return \WP_REST_Response|\WP_Error
 */
function aafm_oauth_rest_token_refresh( WP_REST_Request $request ) {
	$refresh_token = (string) $request->get_param( 'refresh_token' );
	$client_id     = (string) $request->get_param( 'client_id' );

	if ( '' === $refresh_token || '' === $client_id ) {
		return aafm_oauth_rest_error(
			'invalid_grant',
			__( 'The refresh token grant is invalid.', 'agent-abilities-for-mcp' ),
			400
		);
	}

	// aafm_oauth_rotate_refresh() manages its own transaction — never wrap it.
	$tokens = aafm_oauth_rotate_refresh( $refresh_token, $client_id );
	if ( is_wp_error( $tokens ) ) {
		return aafm_oauth_rest_error(
			'invalid_grant',
			__( 'The refresh token grant is invalid.', 'agent-abilities-for-mcp' ),
			400
		);
	}

	return aafm_oauth_rest_token_response( $tokens );
}

/**
 * Build the 200 token response from a minted/rotated token set.
 *
 * @param array{access_token:string,refresh_token:string,expires_in:int} $tokens The token set.
 * @return \WP_REST_Response
 */
function aafm_oauth_rest_token_response( array $tokens ): WP_REST_Response {
	$response = new WP_REST_Response(
		array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
		),
		200
	);

	return aafm_oauth_rest_no_store( $response );
}

/**
 * POST /revoke — token revocation (RFC 7009).
 *
 * Gated on the OAuth toggle (404 when off), HTTPS-only in production, and
 * rate-limited. Always returns 200 with an empty body regardless of whether the
 * token existed, so token validity is never leaked.
 *
 * @param \WP_REST_Request $request The incoming request.
 * @return \WP_REST_Response|\WP_Error
 */
function aafm_oauth_rest_revoke( WP_REST_Request $request ) {
	if ( ! aafm_oauth_enabled() ) {
		return aafm_oauth_rest_error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.', 'agent-abilities-for-mcp' ),
			404
		);
	}

	$https = aafm_oauth_rest_require_https();
	if ( null !== $https ) {
		return $https;
	}

	if ( ! aafm_oauth_rate_ok( 'revoke', 30, 300 ) ) {
		return aafm_oauth_rest_error(
			'rate_limited',
			__( 'Too many revocation requests. Try again shortly.', 'agent-abilities-for-mcp' ),
			429
		);
	}

	$token = (string) $request->get_param( 'token' );
	if ( '' !== $token ) {
		aafm_oauth_revoke_token( $token );
	}

	// RFC 7009: a successful revocation (or a no-op for an unknown token) is 200
	// with an empty body. Never signal whether the token existed.
	$response = new WP_REST_Response( null, 200 );

	return aafm_oauth_rest_no_store( $response );
}
