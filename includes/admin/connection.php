<?php
/**
 * Connection tab: endpoint display, agent-user helper, connect wizard, diagnostics.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The MCP endpoint URL for this site.
 *
 * @return string
 */
function aafm_endpoint_url(): string {
	return rest_url( 'agent-abilities-for-mcp/mcp' );
}

/**
 * Whether this site looks like a local/development install.
 *
 * Local stacks (DDEV, Local, Valet, wp-env) serve a self-signed or
 * locally-trusted TLS certificate. The MCP client proxies run under Node,
 * which rejects such certificates by default, so a snippet generated for a
 * local site needs a TLS hint to connect. Production sites, with a
 * publicly-trusted certificate, never need it and never get it.
 *
 * Detection uses the WordPress environment type first, then falls back to the
 * common local host suffixes so a stock DDEV install (which reports as
 * "production") is still recognised.
 *
 * @return bool
 */
function aafm_site_is_local(): bool {
	$is_local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

	if ( ! $is_local ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $host ) ) {
			$host = strtolower( $host );
			if ( 'localhost' === $host || '127.0.0.1' === $host ) {
				$is_local = true;
			} else {
				foreach ( array( '.test', '.local', '.localhost', '.ddev.site', '.wip' ) as $suffix ) {
					if ( str_ends_with( $host, $suffix ) ) {
						$is_local = true;
						break;
					}
				}
			}
		}
	}

	/**
	 * Filters whether the connection snippet treats this site as a local install.
	 *
	 * @param bool $is_local Whether the site is considered local/development.
	 */
	return (bool) apply_filters( 'aafm_site_is_local', $is_local );
}

/**
 * Run the lean, read-only connection diagnostics shown on the Connection tab.
 *
 * @return array<int,array{id:string,label:string,status:string,detail:string}>
 */
function aafm_diagnostic_checks(): array {
	$checks = array();

	// 1. Adapter active and at or above the version floor.
	$version  = function_exists( 'aafm_loaded_adapter_version' ) ? aafm_loaded_adapter_version() : null;
	$checks[] = array(
		'id'     => 'adapter',
		'label'  => __( 'MCP adapter active and compatible', 'agent-abilities-for-mcp' ),
		'status' => ( null !== $version && aafm_adapter_is_compatible( $version ) ) ? 'pass' : 'fail',
		'detail' => null !== $version
			/* translators: %s: adapter version number. */
			? sprintf( __( 'Adapter %s loaded.', 'agent-abilities-for-mcp' ), $version )
			: __( 'Adapter not loaded.', 'agent-abilities-for-mcp' ),
	);

	// 2. The MCP REST route is registered.
	$routes    = rest_get_server()->get_routes();
	$has_route = (bool) preg_grep( '#^/agent-abilities-for-mcp/mcp#', array_keys( $routes ) );
	$checks[]  = array(
		'id'     => 'endpoint',
		'label'  => __( 'MCP REST endpoint registered', 'agent-abilities-for-mcp' ),
		'status' => $has_route ? 'pass' : 'fail',
		'detail' => aafm_endpoint_url(),
	);

	// 3. The Authorization header reaches PHP (some hosts/proxies strip it).
	$auth_seen = isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	$checks[]  = array(
		'id'     => 'auth_header',
		'label'  => __( 'Authorization header reaches WordPress', 'agent-abilities-for-mcp' ),
		'status' => $auth_seen ? 'pass' : 'warn',
		'detail' => $auth_seen
			? __( 'Authorization header present on this request.', 'agent-abilities-for-mcp' )
			: __( 'Not detectable from this admin page load. Application Password auth still works if your host forwards the header.', 'agent-abilities-for-mcp' ),
	);

	return $checks;
}

/**
 * Create a dedicated low-privilege user for the agent.
 *
 * The agent connects as this user over an Application Password, so its reach is bounded
 * by the subscriber role — least privilege by construction, no custom auth code.
 *
 * @param string $login Desired login.
 * @return array{user_id:int}|WP_Error
 */
function aafm_create_agent_user( string $login ) {
	$login = sanitize_user( $login, true );
	if ( '' === $login ) {
		return new WP_Error( 'aafm_bad_login', __( 'Invalid username.', 'agent-abilities-for-mcp' ) );
	}
	if ( username_exists( $login ) ) {
		return new WP_Error( 'aafm_user_exists', __( 'That username already exists.', 'agent-abilities-for-mcp' ) );
	}

	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24, true, true ),
			'role'       => 'subscriber',
			'user_email' => '',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	return array( 'user_id' => (int) $user_id );
}

/**
 * Build a copy-paste client config snippet pre-pointed at the scoped agent user.
 *
 * The snippet never contains a real secret — it carries a paste placeholder so the
 * operator drops in the Application Password they generate through WordPress core.
 *
 * Two shapes are produced from the same data:
 *
 * - unix    — `npx` is launched directly (macOS, Linux).
 * - windows — the launcher is wrapped in `cmd /c` because Windows MCP clients
 *             cannot spawn the `npx` shim by name.
 *
 * On a local install (see {@see aafm_site_is_local()}) the env block also carries
 * `NODE_TLS_REJECT_UNAUTHORIZED=0` so the Node proxy will accept the self-signed
 * certificate during local testing. Production snippets never include it.
 *
 * @param string $client   Target client (reserved for future per-client shaping).
 * @param string $username The agent username.
 * @param string $os       Target OS shape: 'unix' (default) or 'windows'.
 * @return string
 */
function aafm_client_snippet( string $client, string $username, string $os = 'unix' ): string {
	unset( $client );

	$env = array(
		'WP_API_URL'      => aafm_endpoint_url(),
		'WP_API_USERNAME' => $username,
		'WP_API_PASSWORD' => 'PASTE-APPLICATION-PASSWORD-HERE',
	);
	if ( aafm_site_is_local() ) {
		$env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
	}

	$package = '@automattic/mcp-wordpress-remote@latest';
	if ( 'windows' === $os ) {
		$command = 'cmd';
		$args    = array( '/c', 'npx', '-y', $package );
	} else {
		$command = 'npx';
		$args    = array( '-y', $package );
	}

	$cfg = array(
		'mcpServers' => array(
			'agent-abilities' => array(
				'command' => $command,
				'args'    => $args,
				'env'     => $env,
			),
		),
	);
	return (string) wp_json_encode( $cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * AJAX: create the dedicated agent user.
 *
 * @return void
 */
function aafm_ajax_create_agent_user(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'create_users' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to create users.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$login  = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( (string) $_POST['login'] ), true ) : '';
	$result = aafm_create_agent_user( $login );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( $result );
}

/**
 * AJAX: check that the MCP endpoint is reachable and answers JSON-RPC.
 *
 * This is an honest reachability probe, NOT an impersonation of the agent. It self-calls
 * the endpoint using the current admin's cookie plus a fresh REST nonce, so it confirms
 * the route is registered, reachable, and replies to `tools/list`. The tool count it
 * reports is the ADMIN's view — the agent connects as the low-privilege user over an
 * Application Password and will usually see fewer tools. The response is labeled so it
 * never implies "this is what your agent will see," and it never sends or logs the
 * Application Password.
 *
 * @return void
 */
function aafm_ajax_test_connection(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// Forward the current admin's own cookies on the self-call (admin-eye reachability check).
	$cookies = array();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- entry already nonce + cap checked; reading own cookies for a self-call.
	foreach ( wp_unslash( $_COOKIE ) as $cookie_name => $cookie_value ) {
		$cookies[ (string) $cookie_name ] = is_scalar( $cookie_value ) ? (string) $cookie_value : '';
	}

	$body = (string) wp_json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
			'params'  => new stdClass(),
		)
	);

	$response = wp_remote_post(
		aafm_endpoint_url(),
		array(
			'timeout' => 10,
			'cookies' => $cookies,
			'headers' => array(
				'Content-Type' => 'application/json',
				// A cookie-authenticated REST call needs the REST nonce or core rejects it with 401.
				'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
			),
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => $response->get_error_message() ) );
	}

	$code       = (int) wp_remote_retrieve_response_code( $response );
	$body       = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$reachable  = ( 200 === $code && is_array( $body ) && isset( $body['result'] ) );
	$tool_count = ( is_array( $body ) && isset( $body['result']['tools'] ) && is_array( $body['result']['tools'] ) )
		? count( $body['result']['tools'] )
		: 0;

	wp_send_json_success(
		array(
			'http_code'        => $code,
			'reachable'        => $reachable,
			'admin_tool_count' => $tool_count, // The admin's-eye count, not the agent's.
		)
	);
}

/**
 * Render the Connection tab.
 *
 * @return void
 */
function aafm_render_connection_tab(): void {
	$url = aafm_endpoint_url();

	echo '<div class="aafm-connection">';

	echo '<h3>' . esc_html__( 'Endpoint', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<p><code class="aafm-endpoint">%1$s</code> <button type="button" class="button aafm-copy" data-copy="%2$s">%3$s</button></p>',
		esc_html( $url ),
		esc_attr( $url ),
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);

	echo '<h3>' . esc_html__( 'Step 1 — Create a dedicated agent user', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p>' . esc_html__( 'Give the agent its own user with the least privilege it needs. It can only do what that user\'s role allows, and every ability is off until you turn it on.', 'agent-abilities-for-mcp' ) . '</p>';
	wp_nonce_field( 'aafm_admin', 'aafm_conn_nonce' );
	echo '<p><input type="text" id="aafm-agent-login" value="mcp-agent" class="regular-text"> <button type="button" class="button" id="aafm-create-user">' . esc_html__( 'Create agent user', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-user-status" aria-live="polite"></span></p>';

	echo '<h3>' . esc_html__( 'Step 2 — Connect your client', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p>' . esc_html__( 'Generate an Application Password for the agent user (Users → Profile → Application Passwords), then paste this config into your client:', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<div class="aafm-os-tabs" role="tablist">';
	printf(
		'<button type="button" class="button aafm-os-tab is-active" data-os="unix" role="tab" aria-selected="true">%s</button>',
		esc_html__( 'macOS / Linux', 'agent-abilities-for-mcp' )
	);
	printf(
		'<button type="button" class="button aafm-os-tab" data-os="windows" role="tab" aria-selected="false">%s</button>',
		esc_html__( 'Windows', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	printf(
		'<textarea readonly rows="14" class="large-text code aafm-snippet" data-os="unix">%s</textarea>',
		esc_textarea( aafm_client_snippet( 'claude', 'mcp-agent', 'unix' ) )
	);
	printf(
		'<textarea readonly rows="16" class="large-text code aafm-snippet" data-os="windows" hidden>%s</textarea>',
		esc_textarea( aafm_client_snippet( 'claude', 'mcp-agent', 'windows' ) )
	);

	$kses_code = array( 'code' => array() );

	$windows_note = __( 'On Windows the launcher is wrapped in <code>cmd /c</code> so the <code>npx</code> command resolves — use the Windows tab above.', 'agent-abilities-for-mcp' );

	$cert_note = __( 'If your site uses a self-signed or locally-trusted certificate (DDEV, Local, Valet), Node rejects it by default; add <code>"NODE_TLS_REJECT_UNAUTHORIZED": "0"</code> to <code>env</code> for local testing only, never on a production site.', 'agent-abilities-for-mcp' );
	if ( aafm_site_is_local() ) {
		$cert_note .= ' ' . __( 'This site looks local, so that line is already included above.', 'agent-abilities-for-mcp' );
	}

	echo '<div class="aafm-os-note notice notice-info inline">';
	echo '<p class="aafm-os-note-row"><span class="aafm-os-note-label">' . esc_html__( 'Windows', 'agent-abilities-for-mcp' ) . '</span> <span class="aafm-os-note-text">' . wp_kses( $windows_note, $kses_code ) . '</span></p>';
	echo '<p class="aafm-os-note-row"><span class="aafm-os-note-label">' . esc_html__( 'Certificate', 'agent-abilities-for-mcp' ) . '</span> <span class="aafm-os-note-text">' . wp_kses( $cert_note, $kses_code ) . '</span></p>';
	echo '</div>';

	echo '<h3>' . esc_html__( 'Step 3 — Check the endpoint is reachable', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p>' . esc_html__( 'This confirms the endpoint answers from your server. It checks as the current admin, so the tool count shown is your view — your agent connects as the low-privilege user above and will usually see fewer tools.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<p><button type="button" class="button button-primary" id="aafm-test-connection">' . esc_html__( 'Check endpoint', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-test-status" aria-live="polite"></span></p>';

	echo '<h3>' . esc_html__( 'Diagnostics', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<table class="widefat striped aafm-diagnostics"><tbody>';
	foreach ( aafm_diagnostic_checks() as $check ) {
		printf(
			'<tr><td><span class="aafm-dot aafm-dot-%1$s"></span></td><td>%2$s</td><td>%3$s</td></tr>',
			esc_attr( $check['status'] ),
			esc_html( $check['label'] ),
			esc_html( $check['detail'] )
		);
	}
	echo '</tbody></table>';

	echo '</div>';
}
