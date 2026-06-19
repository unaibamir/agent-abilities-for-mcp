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
 * Per-client shaping: most clients read the server map under an `mcpServers` key, so
 * that is the default. VS Code is the exception — its `.vscode/mcp.json` uses a `servers`
 * key — so a client of 'vscode' switches the top-level wrapper. The proxy package, env
 * block, local-cert handling, and Windows handling stay identical across every client.
 *
 * @param string $client   Target client slug (see {@see aafm_quickstart_clients()}).
 * @param string $username The agent username.
 * @param string $os       Target OS shape: 'unix' (default) or 'windows'.
 * @return string
 */
function aafm_client_snippet( string $client, string $username, string $os = 'unix' ): string {
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

	$server = array(
		'agent-abilities' => array(
			'command' => $command,
			'args'    => $args,
			'env'     => $env,
		),
	);

	// VS Code keys the server map as "servers"; every other client uses "mcpServers".
	$root_key = ( 'vscode' === $client ) ? 'servers' : 'mcpServers';
	$cfg      = array( $root_key => $server );

	return (string) wp_json_encode( $cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * The MCP clients we ship copy-paste quickstarts for: slug => display label.
 *
 * Only clients that connect over the mcp-wordpress-remote proxy are listed. Hosted
 * assistants that cannot run a local stdio server (ChatGPT, the hosted Gemini app) are
 * deliberately left out so the grid never hands out a config that can't work.
 *
 * @return array<string,string> Slug-keyed labels, in the order shown in the UI.
 */
function aafm_quickstart_clients(): array {
	return array(
		'claude-desktop' => __( 'Claude Desktop', 'agent-abilities-for-mcp' ),
		'claude-code'    => __( 'Claude Code', 'agent-abilities-for-mcp' ),
		'cursor'         => __( 'Cursor', 'agent-abilities-for-mcp' ),
		'vscode'         => __( 'VS Code', 'agent-abilities-for-mcp' ),
		'windsurf'       => __( 'Windsurf', 'agent-abilities-for-mcp' ),
		'gemini-cli'     => __( 'Gemini CLI', 'agent-abilities-for-mcp' ),
		'manus'          => __( 'Manus', 'agent-abilities-for-mcp' ),
		'generic'        => __( 'Generic', 'agent-abilities-for-mcp' ),
	);
}

/**
 * The one-line "where do I put this?" note for each quickstart client.
 *
 * Kept as plain prose with no markup so the render can escape each line wholesale.
 *
 * @param string $client Client slug.
 * @return string Localized note, or an empty string for an unknown slug.
 */
function aafm_quickstart_note( string $client ): string {
	switch ( $client ) {
		case 'claude-desktop':
			return __( 'Paste into claude_desktop_config.json (Settings → Developer → Edit Config), then restart Claude.', 'agent-abilities-for-mcp' );
		case 'claude-code':
			return __( "Add it to your project's .mcp.json, or run claude mcp add.", 'agent-abilities-for-mcp' );
		case 'cursor':
			return __( 'Add it to ~/.cursor/mcp.json (or Settings → MCP), then reload.', 'agent-abilities-for-mcp' );
		case 'vscode':
			return __( 'Save it as .vscode/mcp.json in your workspace. Note the key is "servers", not "mcpServers".', 'agent-abilities-for-mcp' );
		case 'windsurf':
			return __( "Add it under Windsurf's MCP config (mcp_config.json) and refresh the server list.", 'agent-abilities-for-mcp' );
		case 'gemini-cli':
			return __( 'Add it to the mcpServers block in your Gemini CLI settings.json.', 'agent-abilities-for-mcp' );
		case 'manus':
			return __( "Add it to Manus's MCP server config.", 'agent-abilities-for-mcp' );
		case 'generic':
			return __( 'For any client that speaks MCP over the mcp-wordpress-remote proxy, use this block.', 'agent-abilities-for-mcp' );
		default:
			return '';
	}
}

/**
 * AJAX: create the dedicated agent user.
 *
 * @return void
 */
function aafm_ajax_create_agent_user(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
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

	// ---- OAuth card (additive, gated; rendered first) ----
	// When OAuth is on this card explains the paste-your-URL flow, but it no longer repeats the
	// endpoint field — the canonical endpoint card below is the single place the URL is shown
	// (the same field the Help tab and Dashboard reference), so it is never rendered twice.
	if ( aafm_oauth_enabled() ) {
		echo '<section class="aafm-card aafm-card-pad aafm-oauth-card">';
		echo '<h2>' . esc_html__( 'Connect with OAuth', 'agent-abilities-for-mcp' ) . '</h2>';
		echo '<p class="sub">' . esc_html__( 'Paste your site URL into your agent (the MCP endpoint is shown below). Your agent gets access through a browser approval, so there is no secret to copy.', 'agent-abilities-for-mcp' ) . '</p>';
		echo '</section>';
	}

	// ---- Endpoint card (the single canonical endpoint display) ----
	echo '<section class="aafm-card aafm-card-pad aafm-endpoint-card">';
	echo '<div class="aafm-stat-label">' . esc_html__( 'MCP endpoint', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-field-mono">';
	printf( '<span class="aafm-endpoint">%s</span>', esc_html( $url ) );
	printf(
		// aria-label disambiguates this from the snippet copy buttons for screen-reader users.
		'<button type="button" class="aafm-btn aafm-btn-secondary aafm-copy" data-copy="%1$s" aria-label="%4$s">%2$s<span class="aafm-copy-label">%3$s</span></button>',
		esc_attr( $url ),
		aafm_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'agent-abilities-for-mcp' ),
		esc_attr__( 'Copy the MCP endpoint URL', 'agent-abilities-for-mcp' )
	);
	echo '</div>';
	echo '</section>';

	// ---- Step 1: create a dedicated agent user ----
	echo '<div class="aafm-step aafm-conn-step">';
	echo '<div class="aafm-step-head"><span class="aafm-sidx">1</span><div>';
	echo '<h2>' . esc_html__( 'Create a dedicated agent user', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'Give the agent its own user with the least privilege it needs. It can only do what that user\'s role allows, and every ability is off until you turn it on.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';
	echo '<div class="aafm-step-rail"><div class="aafm-card aafm-card-pad">';
	wp_nonce_field( 'aafm_admin', 'aafm_conn_nonce' );
	echo '<p><input type="text" id="aafm-agent-login" value="mcp-agent" class="regular-text"> <button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-create-user">' . esc_html__( 'Create agent user', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-user-status" aria-live="polite"></span></p>';
	echo '</div></div>';
	echo '</div>';

	// ---- Step 2: connect your client ----
	echo '<div class="aafm-step aafm-conn-step">';
	echo '<div class="aafm-step-head"><span class="aafm-sidx">2</span><div>';
	echo '<h2>' . esc_html__( 'Connect your client', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'Generate an Application Password for the agent user (Users → Profile → Application Passwords), pick your client, then copy the config.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	echo '<div class="aafm-step-rail"><div class="aafm-card">';

	// OS toggle + client picker row.
	echo '<div class="aafm-card-pad aafm-connect-controls">';

	echo '<div class="aafm-connect-os">';
	echo '<div class="aafm-stat-label">' . esc_html__( 'Your operating system', 'agent-abilities-for-mcp' ) . '</div>';
	// The .aafm-seg buttons double as the OS tabs admin.js binds (aafm-os-tab + data-os).
	echo '<div class="aafm-seg aafm-os-tabs" role="tablist">';
	printf(
		'<button type="button" class="aafm-os-tab is-active on" data-os="unix" role="tab" aria-selected="true">%s</button>',
		esc_html__( 'macOS / Linux', 'agent-abilities-for-mcp' )
	);
	printf(
		'<button type="button" class="aafm-os-tab" data-os="windows" role="tab" aria-selected="false">%s</button>',
		esc_html__( 'Windows', 'agent-abilities-for-mcp' )
	);
	echo '</div>';
	echo '</div>';

	echo '<div class="aafm-connect-client">';
	echo '<div class="aafm-stat-label">' . esc_html__( 'Your client', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-client-grid" id="aafm-clients">';
	$first = true;
	foreach ( aafm_quickstart_clients() as $slug => $label ) {
		printf(
			'<div class="aafm-client%1$s" data-client="%2$s"><span class="ci">%3$s</span>%4$s</div>',
			$first ? ' on' : '',
			esc_attr( $slug ),
			aafm_icon( 'client-' . $slug ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
			esc_html( $label )
		);
		$first = false;
	}
	echo '</div>';
	echo '</div>';

	echo '</div>'; // .aafm-connect-controls

	// Primary config block: the default (first) client, one .aafm-codeblock per OS.
	echo '<div class="aafm-card-pad">';

	$unix_snippet    = aafm_client_snippet( 'claude', 'mcp-agent', 'unix' );
	$windows_snippet = aafm_client_snippet( 'claude', 'mcp-agent', 'windows' );

	echo '<div class="aafm-codeblock aafm-snippet" data-os="unix">';
	printf( '<pre>%s</pre>', esc_html( $unix_snippet ) );
	printf(
		'<button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm copy-fab aafm-copy" data-copy="%1$s">%2$s<span class="aafm-copy-label">%3$s</span></button>',
		esc_attr( $unix_snippet ),
		aafm_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	echo '<div class="aafm-codeblock aafm-snippet" data-os="windows" hidden>';
	printf( '<pre>%s</pre>', esc_html( $windows_snippet ) );
	printf(
		'<button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm copy-fab aafm-copy" data-copy="%1$s">%2$s<span class="aafm-copy-label">%3$s</span></button>',
		esc_attr( $windows_snippet ),
		aafm_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	// Windows / certificate notices.
	$kses_code = array( 'code' => array() );

	$windows_note = __( 'On Windows the launcher is wrapped in <code>cmd /c</code> so the <code>npx</code> command resolves — use the Windows tab above.', 'agent-abilities-for-mcp' );

	$cert_note = __( 'If your site uses a self-signed or locally-trusted certificate (DDEV, Local, Valet), Node rejects it by default; add <code>"NODE_TLS_REJECT_UNAUTHORIZED": "0"</code> to <code>env</code> for local testing only, never on a production site.', 'agent-abilities-for-mcp' );
	if ( aafm_site_is_local() ) {
		$cert_note .= ' ' . __( 'This site looks local, so that line is already included above.', 'agent-abilities-for-mcp' );
	}

	// Bespoke notice chrome on purpose: this callout needs two labelled rows (Windows, Certificate),
	// which aafm_render_notice()'s single dashicon + single body block cannot express.
	echo '<div class="aafm-os-note notice notice-info inline">';
	echo '<p class="aafm-os-note-row"><span class="aafm-os-note-label">' . esc_html__( 'Windows', 'agent-abilities-for-mcp' ) . '</span> <span class="aafm-os-note-text">' . wp_kses( $windows_note, $kses_code ) . '</span></p>';
	echo '<p class="aafm-os-note-row"><span class="aafm-os-note-label">' . esc_html__( 'Certificate', 'agent-abilities-for-mcp' ) . '</span> <span class="aafm-os-note-text">' . wp_kses( $cert_note, $kses_code ) . '</span></p>';
	echo '</div>';

	// Per-client quickstarts: the JS-toggled grid of ready-to-paste configs, one per client.
	// Each client's exact snippet stays present here so the picker can surface any of them.
	echo '<div class="aafm-quickstarts">';
	echo '<p><button type="button" class="button aafm-quickstart-toggle" aria-expanded="false" aria-controls="aafm-quickstart-grid">' . esc_html__( 'Show config for a specific client', 'agent-abilities-for-mcp' ) . '</button></p>';
	echo '<div class="aafm-quickstart-grid" id="aafm-quickstart-grid" hidden>';
	foreach ( aafm_quickstart_clients() as $slug => $label ) {
		$snippet = aafm_client_snippet( $slug, 'mcp-agent', 'unix' );
		echo '<div class="aafm-quickstart-card" data-client="' . esc_attr( $slug ) . '" data-config="' . esc_attr( $snippet ) . '">';
		echo '<h4 class="aafm-quickstart-name">' . esc_html( $label ) . '</h4>';
		echo '<p class="aafm-quickstart-where">' . esc_html( aafm_quickstart_note( $slug ) ) . '</p>';
		echo '<div class="aafm-codeblock">';
		printf( '<pre>%s</pre>', esc_html( $snippet ) );
		printf(
			'<button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm copy-fab aafm-copy" data-copy="%1$s">%2$s<span class="aafm-copy-label">%3$s</span></button>',
			esc_attr( $snippet ),
			aafm_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
			esc_html__( 'Copy', 'agent-abilities-for-mcp' )
		);
		echo '</div>';
		echo '</div>';
	}
	echo '</div>';
	echo '</div>';

	echo '</div>'; // .aafm-card-pad config block

	echo '</div></div>'; // .aafm-card / .aafm-step-rail
	echo '</div>'; // .aafm-step 2

	// ---- Step 3: check the endpoint is reachable ----
	echo '<div class="aafm-step aafm-conn-step">';
	echo '<div class="aafm-step-head"><span class="aafm-sidx">3</span><div>';
	echo '<h2>' . esc_html__( 'Check the endpoint is reachable', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'This confirms the endpoint answers from your server. It checks as the current admin, so the tool count shown is your view — your agent connects as the low-privilege user above and will usually see fewer tools.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	echo '<div class="aafm-step-rail"><div class="aafm-card">';
	echo '<div class="aafm-card-pad aafm-connect-check">';
	echo '<button type="button" class="aafm-btn aafm-btn-primary" id="aafm-test-connection">';
	echo aafm_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo esc_html__( 'Check endpoint', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-test-status" aria-live="polite"></span>';
	echo '</div>';

	// Diagnostics rail: one row per check, status mapped to a coloured dot.
	$dot_class = array(
		'pass' => 'd-ok',
		'warn' => 'd-warn',
		'fail' => 'd-bad',
	);
	echo '<div class="aafm-diag">';
	foreach ( aafm_diagnostic_checks() as $check ) {
		$dot = $dot_class[ $check['status'] ] ?? 'd-warn';
		printf(
			'<div class="aafm-diag-row"><span class="dot-lg %1$s"></span><div><div class="d-title">%2$s</div><div class="d-detail">%3$s</div></div></div>',
			esc_attr( $dot ),
			esc_html( $check['label'] ),
			esc_html( $check['detail'] )
		);
	}
	echo '</div>';
	echo '</div></div>'; // .aafm-card / .aafm-step-rail
	echo '</div>'; // .aafm-step 3

	echo '</div>'; // .aafm-connection
}
