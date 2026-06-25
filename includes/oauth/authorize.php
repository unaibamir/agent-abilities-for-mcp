<?php
/**
 * OAuth 2.1 authorization endpoint and consent flow.
 *
 * Served off `init` at ?aafm_oauth=authorize. The handler enforces HTTPS, rate
 * limiting, login, and a capability gate, then validates the request. On GET it
 * either mints a code immediately (prior consent) or renders the consent screen; on
 * POST it verifies the nonce, re-validates everything, and records consent before
 * minting. PKCE S256 is mandatory and the code's `resource` is forced to the MCP
 * endpoint so it audience-matches the bearer validator byte-for-byte.
 *
 * Open-redirect guard: the request is never bounced to a client redirect_uri until
 * that URI has been matched against the client's registered allowlist. A bad
 * client_id/redirect_uri (or a local authorization failure such as a capability or
 * nonce error) renders a local error page instead of redirecting anywhere.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether a validation WP_Error may be handed back to the client's redirect_uri.
 *
 * "Local" errors (unknown client, unregistered redirect_uri) must never redirect —
 * the redirect target is itself untrusted. They carry the 'aafm_local' error-data
 * flag and render a local page. Every other validation error is a proper OAuth error
 * that is safe to return to the (already-validated) redirect_uri.
 *
 * @param \WP_Error $error The validation error.
 * @return bool True when the error may be redirected back to the client.
 */
function aafm_oauth_error_is_redirectable( WP_Error $error ): bool {
	$data = $error->get_error_data();

	if ( is_array( $data ) && ! empty( $data['aafm_local'] ) ) {
		return false;
	}

	return true;
}

/**
 * Build a non-redirectable ("local") validation error.
 *
 * @param string $code    OAuth-style error code.
 * @param string $message Human-readable message.
 * @return \WP_Error
 */
function aafm_oauth_local_error( string $code, string $message ): WP_Error {
	return new WP_Error( $code, $message, array( 'aafm_local' => true ) );
}

/**
 * Look up an active OAuth client row by client_id.
 *
 * @param string $client_id The public client identifier.
 * @return array<string,mixed>|null The client row, or null when missing/inactive.
 */
function aafm_oauth_get_active_client( string $client_id ): ?array {
	if ( '' === $client_id ) {
		return null;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT * FROM {$wpdb->prefix}aafm_oauth_clients WHERE client_id = %s AND is_active = 1",
			$client_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Validate and normalize an authorization request's parameters.
 *
 * Returns a normalized param set (with `resource` defaulted to the MCP endpoint) on
 * success, or a WP_Error on failure. Local errors (bad client / unregistered
 * redirect_uri) are flagged non-redirectable via aafm_oauth_local_error(); every
 * other failure is a redirectable OAuth error. The order matters: client_id and
 * redirect_uri are resolved FIRST so an open-redirect can never seed a later error.
 *
 * @param array<string,mixed> $params Raw, already-sanitized request parameters.
 * @return array<string,string>|\WP_Error Normalized params, or a validation error.
 */
function aafm_oauth_validate_authorize_params( array $params ) {
	$response_type = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';
	$client_id     = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
	$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
	$challenge     = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';
	$method        = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
	$resource      = isset( $params['resource'] ) ? (string) $params['resource'] : '';
	$state         = isset( $params['state'] ) ? (string) $params['state'] : '';
	$scope         = isset( $params['scope'] ) ? (string) $params['scope'] : '';

	// 1. Resolve the client. Unknown/inactive => local error (never redirect).
	$client = aafm_oauth_get_active_client( $client_id );
	if ( null === $client ) {
		return aafm_oauth_local_error(
			'invalid_client',
			__( 'Unknown or inactive client.', 'agent-abilities-for-mcp' )
		);
	}

	// 2. The redirect_uri must exactly match one the client registered. Mismatch =>
	// local error: we must not bounce to an unvalidated URI (open-redirect guard).
	$registered = json_decode( isset( $client['redirect_uris'] ) ? (string) $client['redirect_uris'] : '[]', true );
	if ( ! is_array( $registered ) || '' === $redirect_uri || ! in_array( $redirect_uri, $registered, true ) ) {
		return aafm_oauth_local_error(
			'invalid_redirect_uri',
			__( 'The redirect URI does not match the client registration.', 'agent-abilities-for-mcp' )
		);
	}

	// From here on, redirect_uri is trusted: remaining failures are redirectable OAuth errors.

	// 3. Only the authorization-code flow is supported.
	if ( 'code' !== $response_type ) {
		return new WP_Error(
			'unsupported_response_type',
			__( 'Only the authorization code response type is supported.', 'agent-abilities-for-mcp' )
		);
	}

	// 4. PKCE is mandatory and S256-only.
	if ( 'S256' !== $method ) {
		return new WP_Error(
			'invalid_request',
			__( 'PKCE with the S256 method is required.', 'agent-abilities-for-mcp' )
		);
	}
	if ( '' === $challenge || ! aafm_pkce_is_valid_challenge( $challenge ) ) {
		return new WP_Error(
			'invalid_request',
			__( 'A valid PKCE code challenge is required.', 'agent-abilities-for-mcp' )
		);
	}

	// 5. Resource indicator: absent => default to the endpoint; present => must equal
	// the endpoint exactly so the minted code audience-matches the bearer validator.
	$endpoint = aafm_endpoint_url();
	if ( '' === $resource ) {
		$resource = $endpoint;
	} elseif ( ! hash_equals( $endpoint, $resource ) ) {
		return new WP_Error(
			'invalid_target',
			__( 'The requested resource is not served by this server.', 'agent-abilities-for-mcp' )
		);
	}

	return array(
		'response_type'         => 'code',
		'client_id'             => $client_id,
		'client_name'           => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
		'redirect_uri'          => $redirect_uri,
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
		'resource'              => $resource,
		'state'                 => $state,
		'scope'                 => $scope,
	);
}

/**
 * Whether the user has already consented to this client.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $client_id The public client identifier.
 * @return bool
 */
function aafm_oauth_has_consent( int $user_id, string $client_id ): bool {
	if ( $user_id <= 0 || '' === $client_id ) {
		return false;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$found = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT id FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
			$user_id,
			$client_id
		)
	);

	return null !== $found;
}

/**
 * Record (or refresh) the user's consent for a client.
 *
 * The consents table has a UNIQUE (wp_user_id, client_id) key, so $wpdb->replace()
 * makes this an idempotent upsert: a repeat approval refreshes granted_at on the
 * single existing row rather than inserting a duplicate.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $client_id The public client identifier.
 * @return void
 */
function aafm_oauth_record_consent( int $user_id, string $client_id ): void {
	if ( $user_id <= 0 || '' === $client_id ) {
		return;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->replace(
		$wpdb->prefix . 'aafm_oauth_consents',
		array(
			'wp_user_id' => $user_id,
			'client_id'  => $client_id,
			'granted_at' => gmdate( 'Y-m-d H:i:s', time() ),
		),
		array( '%d', '%s', '%s' )
	);
}

/**
 * Read and sanitize the authorize parameters from the active request.
 *
 * Reads from $_POST on a POST, $_GET otherwise. Every value is unslashed and run
 * through sanitize_text_field(). Nonce verification for the POST happens in the
 * handler before this is trusted to act, so the read itself is recommended-only.
 *
 * @return array<string,string>
 */
function aafm_oauth_read_authorize_params(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- nonce is verified in the handler before any state change.
	$source = ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) ? $_POST : $_GET;

	$keys   = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'resource', 'state', 'scope' );
	$params = array();

	foreach ( $keys as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above; values are sanitized here.
		$params[ $key ] = isset( $source[ $key ] ) ? sanitize_text_field( wp_unslash( $source[ $key ] ) ) : '';
	}

	return $params;
}

/**
 * Reduce a validated redirect_uri to its bare origin (scheme://host[:port]).
 *
 * Used to scope the consent CSP's form-action to the one approved client origin. Only
 * the scheme, host, and explicit non-default port are kept — never the path or query —
 * so the CSP source is a clean origin and nothing the client can otherwise control
 * leaks into the header. Returns '' if the URI cannot be parsed into scheme+host.
 *
 * The caller MUST pass the redirect_uri that was already exact-matched against the
 * client's registered allowlist, never raw request input, so the origin is trusted.
 *
 * @param string $redirect_uri The allowlist-validated client redirect URI.
 * @return string The origin, or '' when the URI lacks a scheme/host.
 */
function aafm_oauth_redirect_uri_origin( string $redirect_uri ): string {
	$scheme = wp_parse_url( $redirect_uri, PHP_URL_SCHEME );
	$host   = wp_parse_url( $redirect_uri, PHP_URL_HOST );

	if ( ! is_string( $scheme ) || '' === $scheme || ! is_string( $host ) || '' === $host ) {
		return '';
	}

	$origin = strtolower( $scheme ) . '://' . $host;

	$port = wp_parse_url( $redirect_uri, PHP_URL_PORT );
	if ( is_int( $port ) ) {
		$origin .= ':' . $port;
	}

	return $origin;
}

/**
 * Build the Content-Security-Policy for the consent render.
 *
 * The form-action directive governs not only where the form may POST but also where the
 * resulting response may REDIRECT. The consent form POSTs same-origin, but on Approve the server
 * answers with a 302 to the client's external redirect_uri — a cross-origin hop the
 * browser blocks against a bare 'self'. So when a validated client origin is supplied
 * it is added to form-action ('self' <origin>), permitting the redirect to exactly that
 * one approved origin and nothing else. Local error pages pass no origin and stay at
 * 'self' alone, since they never redirect off-site.
 *
 * @param string $redirect_origin Validated client origin (scheme://host[:port]) to
 *                                 allow in form-action, or '' for 'self' only.
 * @return string The CSP header value.
 */
function aafm_oauth_consent_csp( string $redirect_origin = '' ): string {
	$form_action = "form-action 'self'";
	if ( '' !== $redirect_origin ) {
		$form_action .= ' ' . $redirect_origin;
	}

	return "default-src 'none'; style-src 'self'; img-src data:; {$form_action}; base-uri 'none'; frame-ancestors 'none'";
}

/**
 * Send the strict security headers used on the consent render.
 *
 * No external scripts or styles, framing denied, and no referrer leakage of the
 * authorize URL (which carries the PKCE challenge and state). The form-action source
 * list is built by aafm_oauth_consent_csp(); see it for why the validated client origin
 * is allowed there on the consent page but not on local error pages.
 *
 * @param string $redirect_origin Validated client origin to allow in form-action, or
 *                                 '' for 'self' only.
 * @return void
 */
function aafm_oauth_send_consent_headers( string $redirect_origin = '' ): void {
	header( 'Content-Security-Policy: ' . aafm_oauth_consent_csp( $redirect_origin ) );
	header( 'X-Frame-Options: DENY' );
	header( 'Referrer-Policy: no-referrer' );
}

/**
 * Render a minimal local error page and exit.
 *
 * Used for failures that must NOT be handed back to the client: a disabled surface,
 * a capability denial, a nonce failure, or a non-redirectable validation error. Sends
 * the same hardened headers as the consent page.
 *
 * @param int    $status  HTTP status code.
 * @param string $message Human-readable message (will be escaped).
 * @return never
 */
function aafm_oauth_render_local_error( int $status, string $message ): void {
	aafm_oauth_send_consent_headers();
	status_header( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
		. esc_html__( 'Authorization error', 'agent-abilities-for-mcp' )
		. '</title></head><body><p>'
		. esc_html( $message )
		. '</p></body></html>';
	exit;
}

/**
 * Redirect back to a client redirect_uri with an OAuth error, then exit.
 *
 * The caller guarantees $redirect_uri was matched against the client's registered
 * allowlist before this runs, so wp_redirect() (raw) is used deliberately:
 * wp_safe_redirect() would reject the external client host.
 *
 * @param string $redirect_uri The allowlist-validated client redirect URI.
 * @param string $error        The OAuth error code.
 * @param string $state        The opaque state to echo back (may be empty).
 * @return void
 */
function aafm_oauth_redirect_error( string $redirect_uri, string $error, string $state ): void {
	$args = array( 'error' => $error );
	if ( '' !== $state ) {
		$args['state'] = $state;
	}
	$url = add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri );
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri is allowlist-validated against the client registration above.
	wp_redirect( $url );
	exit;
}

/**
 * Redirect back to a client redirect_uri with a fresh authorization code, then exit.
 *
 * @param array<string,string> $valid The normalized, validated authorize params.
 * @param int                  $user_id The approving user.
 * @return void
 */
function aafm_oauth_issue_code_and_redirect( array $valid, int $user_id ): void {
	$code = aafm_oauth_mint_code(
		array(
			'client_id'      => $valid['client_id'],
			'wp_user_id'     => $user_id,
			'redirect_uri'   => $valid['redirect_uri'],
			'code_challenge' => $valid['code_challenge'],
			'resource'       => aafm_endpoint_url(),
		)
	);

	// A WP_Error (or empty) code means the mint/insert failed; do not redirect with a code that
	// was never persisted.
	if ( is_wp_error( $code ) || '' === $code ) {
		aafm_oauth_render_local_error(
			500,
			__( 'Could not issue an authorization code. Please try again.', 'agent-abilities-for-mcp' )
		);
	}

	$args = array( 'code' => $code );
	if ( '' !== $valid['state'] ) {
		$args['state'] = $valid['state'];
	}
	$url = add_query_arg( array_map( 'rawurlencode', $args ), $valid['redirect_uri'] );
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri is allowlist-validated against the client registration above.
	wp_redirect( $url );
	exit;
}

/**
 * Render the consent screen for a validated authorize request, then exit.
 *
 * Carries every OAuth param as a hidden field (attribute-escaped) so the POST back
 * re-presents them, plus the consent nonce.
 *
 * @param array<string,string> $valid The normalized, validated authorize params.
 * @return void
 */
function aafm_oauth_show_consent( array $valid ): void {
	$user = wp_get_current_user();

	$hidden_keys   = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' );
	$hidden_inputs = array();
	foreach ( $hidden_keys as $key ) {
		$hidden_inputs[] = sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( $key ),
			esc_attr( isset( $valid[ $key ] ) ? $valid[ $key ] : '' )
		);
	}

	$view = array(
		'client_name'   => '' !== $valid['client_name'] ? $valid['client_name'] : $valid['client_id'],
		'user_login'    => $user->user_login,
		'site_name'     => get_bloginfo( 'name' ),
		'action_url'    => add_query_arg( 'aafm_oauth', 'authorize', home_url( '/' ) ),
		'nonce_field'   => wp_nonce_field( 'aafm_oauth_consent', '_wpnonce', true, false ),
		'hidden_inputs' => $hidden_inputs,
	);

	// The consent form's POST is answered with a 302 to this validated client origin,
	// so it must be permitted in form-action or the browser blocks the cross-origin
	// redirect and the client never receives the code. The redirect_uri here came
	// straight from the allowlist-validated $valid set, never raw request input.
	$redirect_origin = aafm_oauth_redirect_uri_origin( $valid['redirect_uri'] );

	aafm_oauth_send_consent_headers( $redirect_origin );
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	aafm_oauth_render_consent_page( $view );
	exit;
}

/**
 * Handle the authorization endpoint, hooked on `init`.
 *
 * Runs only for ?aafm_oauth=authorize. Enforces the surface toggle, HTTPS, rate
 * limiting, login (logged-out users go to wp-login and return to this exact URL), and
 * a capability gate, then validates and acts on the request per HTTP method.
 *
 * @return void
 */
function aafm_oauth_handle_authorize(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route selector only; no state change here.
	$marker = isset( $_GET['aafm_oauth'] ) ? sanitize_text_field( wp_unslash( $_GET['aafm_oauth'] ) ) : '';
	if ( 'authorize' !== $marker ) {
		return;
	}

	// Surface disabled: behave as if the endpoint does not exist.
	if ( ! aafm_oauth_enabled() ) {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}

	// HTTPS is mandatory in production.
	if ( aafm_oauth_https_required() && ! is_ssl() ) {
		aafm_oauth_render_local_error( 400, __( 'HTTPS is required for authorization.', 'agent-abilities-for-mcp' ) );
	}

	// Rate limit the authorize surface.
	if ( ! aafm_oauth_rate_ok( 'authorize', 30, 300 ) ) {
		status_header( 429 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Too many requests';
		exit;
	}

	// Require login. The authorize URL is a FRONT-END path (?aafm_oauth=authorize on
	// home_url(), not under /wp-admin), so we must NOT call auth_redirect() here: it
	// validates the secure_auth cookie scheme whenever is_ssl() || force_ssl_admin(),
	// and that cookie is scoped to /wp-admin (+ /wp-content/plugins), never to "/".
	// On a real-HTTPS or FORCE_SSL_ADMIN site the secure_auth cookie is never sent on
	// "/", so auth_redirect() would fail validation and bounce a fully logged-in user
	// to wp-login forever. The logged_in cookie IS scoped to "/" and is what determines
	// front-end login state, so gate on is_user_logged_in() instead and send only
	// genuinely logged-out users to wp-login, returning to this exact authorize URL.
	if ( ! is_user_logged_in() ) {
		// Reconstruct the current authorize URL from the request path so the user
		// returns to this exact endpoint after signing in. Built off home_url() + the
		// raw REQUEST_URI rather than add_query_arg( array(), null ), which reads the
		// superglobal implicitly and warns when it is absent.
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$return_to   = home_url( '/' );
		if ( '' !== $request_uri ) {
			$return_to = home_url( $request_uri );
		}
		// wp-login on our own host; redirect_to is WP's own param, so wp_safe_redirect
		// is appropriate (it rejects off-host targets).
		wp_safe_redirect( wp_login_url( esc_url_raw( $return_to ) ) );
		exit;
	}

	// Capability gate. A failure here is a LOCAL authorization failure, not an OAuth
	// error to hand back to the client, so render a local 403 (never redirect).
	$min_cap = apply_filters( 'aafm_oauth_min_capability', 'read' );
	if ( ! current_user_can( $min_cap ) ) {
		aafm_oauth_render_local_error(
			403,
			__( 'Your account does not have permission to authorize access.', 'agent-abilities-for-mcp' )
		);
	}

	$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

	// On POST, verify the consent nonce BEFORE trusting any submitted field. A bad
	// nonce is a local failure (render a local 403), never a redirect to the client.
	if ( $is_post ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- explicit verification on the next line.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'aafm_oauth_consent' ) ) {
			aafm_oauth_render_local_error(
				403,
				__( 'Your authorization session expired. Please start again.', 'agent-abilities-for-mcp' )
			);
		}
	}

	// Re-validate ALL params on every request (never trust hidden fields).
	$params = aafm_oauth_read_authorize_params();
	$valid  = aafm_oauth_validate_authorize_params( $params );

	if ( $valid instanceof WP_Error ) {
		// Local errors render a page; redirectable OAuth errors bounce back to the
		// (now-validated) redirect_uri. The redirect_uri is trusted only because the
		// validator resolved client + redirect BEFORE producing any redirectable error.
		// Both branches below exit internally; the trailing return makes that terminal
		// to static analysis so $valid narrows to the array shape past this block.
		if ( ! aafm_oauth_error_is_redirectable( $valid ) ) {
			aafm_oauth_render_local_error( 400, $valid->get_error_message() );
		}

		$redirect_uri = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$state        = isset( $params['state'] ) ? (string) $params['state'] : '';
		aafm_oauth_redirect_error( $redirect_uri, (string) $valid->get_error_code(), $state );
		return;
	}

	$user_id = get_current_user_id();

	if ( $is_post ) {
		// Deny: hand access_denied back to the client.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$decision = isset( $_POST['aafm_oauth_decision'] ) ? sanitize_text_field( wp_unslash( $_POST['aafm_oauth_decision'] ) ) : '';
		if ( 'deny' === $decision ) {
			aafm_oauth_redirect_error( $valid['redirect_uri'], 'access_denied', $valid['state'] );
		}

		// Approve: persist consent, then issue the code.
		aafm_oauth_record_consent( $user_id, $valid['client_id'] );
		aafm_oauth_issue_code_and_redirect( $valid, $user_id );
	}

	// GET: a prior consent issues a code immediately; otherwise show the screen.
	if ( aafm_oauth_has_consent( $user_id, $valid['client_id'] ) ) {
		aafm_oauth_issue_code_and_redirect( $valid, $user_id );
	}

	aafm_oauth_show_consent( $valid );
}
