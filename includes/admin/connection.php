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
 * Calls rest_url() only after confirming $wp_rewrite is available. The global $wp_rewrite
 * is not yet instantiated when the determine_current_user filter fires on the OAuth bearer
 * path (e.g. Query Monitor calls current_user_can() that early), and rest_url() ->
 * get_rest_url() dereferences it, causing a fatal. When $wp_rewrite is absent the URL is
 * reconstructed without it, mirroring the guard in validator.php:oversio_oauth_request_targets_mcp_route().
 *
 * Both branches MUST produce byte-identical output so the RFC 8707 audience
 * hash_equals() check in the validator passes regardless of which branch ran at
 * token-mint time versus token-check time. The reconstruction branch uses
 * get_option('permalink_structure') — which never touches $wp_rewrite — to decide
 * between the pretty-permalink (/wp-json/) and plain-permalink (?rest_route=) forms,
 * matching exactly what rest_url() would have returned.
 *
 * @return string Full URL of the MCP REST endpoint.
 */
function oversio_endpoint_url(): string {
	// Single-sourced in bootstrap.php; byte-identical to the namespace/route create_server()
	// registers, which the OAuth audience binding (hash_equals) depends on.
	$route = oversio_mcp_rest_namespace_route();

	if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
		return rest_url( $route );
	}

	// $wp_rewrite is not yet available. Reconstruct without touching it.
	// get_option('permalink_structure') returns '' on plain permalinks, a non-empty
	// template (e.g. '/%postname%/') on pretty permalinks — same information $wp_rewrite
	// would expose, but available before $wp_rewrite is instantiated.
	$permalink_structure = (string) get_option( 'permalink_structure', '' );

	if ( '' === $permalink_structure ) {
		// Plain-permalink form: home_url()/index.php?rest_route=/route.
		// Matches rest_url() on a plain-permalink install.
		return add_query_arg( 'rest_route', '/' . $route, trailingslashit( home_url() ) . 'index.php' );
	}

	// Pretty-permalink form: home_url() + REST prefix + route.
	// home_url() and rest_get_url_prefix() never dereference $wp_rewrite.
	$home = rtrim( home_url(), '/' );
	return $home . '/' . trim( rest_get_url_prefix(), '/' ) . '/' . $route;
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
function oversio_site_is_local(): bool {
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
	return (bool) apply_filters( 'oversio_site_is_local', $is_local );
}

/**
 * Run the lean, read-only connection diagnostics shown on the Connection tab.
 *
 * @return array<int,array{id:string,label:string,status:string,detail:string}>
 */
function oversio_diagnostic_checks(): array {
	$checks = array();

	// 1. Adapter active and at or above the version floor.
	$version  = function_exists( 'oversio_loaded_adapter_version' ) ? oversio_loaded_adapter_version() : null;
	$checks[] = array(
		'id'     => 'adapter',
		'label'  => __( 'MCP adapter active and compatible', 'oversio-agent-abilities' ),
		'status' => ( null !== $version && oversio_adapter_is_compatible( $version ) ) ? 'pass' : 'fail',
		'detail' => null !== $version
			/* translators: %s: adapter version number. */
			? sprintf( __( 'Adapter %s loaded.', 'oversio-agent-abilities' ), $version )
			: __( 'Adapter not loaded.', 'oversio-agent-abilities' ),
	);

	// 2. The MCP REST route is registered.
	$routes    = rest_get_server()->get_routes();
	$has_route = (bool) preg_grep( '#^' . preg_quote( oversio_mcp_rest_route(), '#' ) . '#', array_keys( $routes ) );
	$checks[]  = array(
		'id'     => 'endpoint',
		'label'  => __( 'MCP REST endpoint registered', 'oversio-agent-abilities' ),
		'status' => $has_route ? 'pass' : 'fail',
		'detail' => oversio_endpoint_url(),
	);

	// 3. The Authorization header reaches PHP (some hosts/proxies strip it).
	$auth_seen = isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	$checks[]  = array(
		'id'     => 'auth_header',
		'label'  => __( 'Authorization header reaches WordPress', 'oversio-agent-abilities' ),
		'status' => $auth_seen ? 'pass' : 'warn',
		'detail' => $auth_seen
			? __( 'Authorization header present on this request.', 'oversio-agent-abilities' )
			: __( 'Not detectable from this admin page load. Application Password auth still works if your host forwards the header.', 'oversio-agent-abilities' ),
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
function oversio_create_agent_user( string $login ) {
	$login = sanitize_user( $login, true );
	if ( '' === $login ) {
		return new WP_Error( 'oversio_bad_login', __( 'Invalid username.', 'oversio-agent-abilities' ) );
	}
	$existing_id = username_exists( $login );
	if ( $existing_id ) {
		// The user is already there — hand back a friendly message plus the existing user's
		// id and edit link so the caller can offer "Edit user" instead of a dead-end error.
		return new WP_Error(
			'oversio_user_exists',
			__( 'That user already exists, so there is nothing to create. You can edit it instead.', 'oversio-agent-abilities' ),
			array(
				'user_id'  => (int) $existing_id,
				'edit_url' => (string) get_edit_user_link( (int) $existing_id ),
			)
		);
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
 * On a local install (see {@see oversio_site_is_local()}) the env block also carries
 * `NODE_TLS_REJECT_UNAUTHORIZED=0` so the Node proxy will accept the self-signed
 * certificate during local testing. Production snippets never include it.
 *
 * Per-client shaping: most clients read the server map under an `mcpServers` key, so
 * that is the default. VS Code is the exception — its `.vscode/mcp.json` uses a `servers`
 * key — so a client of 'vscode' switches the top-level wrapper. The proxy package, env
 * block, local-cert handling, and Windows handling stay identical across every client.
 *
 * @param string $client   Target client slug (see {@see oversio_quickstart_clients()}).
 * @param string $username The agent username.
 * @param string $os       Target OS shape: 'unix' (default) or 'windows'.
 * @return string
 */
function oversio_client_snippet( string $client, string $username, string $os = 'unix' ): string {
	$env = array(
		'WP_API_URL'      => oversio_endpoint_url(),
		'WP_API_USERNAME' => $username,
		'WP_API_PASSWORD' => 'PASTE-APPLICATION-PASSWORD-HERE',
	);
	if ( oversio_site_is_local() ) {
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
function oversio_quickstart_clients(): array {
	return array(
		'claude-desktop' => __( 'Claude Desktop', 'oversio-agent-abilities' ),
		'claude-code'    => __( 'Claude Code', 'oversio-agent-abilities' ),
		'cursor'         => __( 'Cursor', 'oversio-agent-abilities' ),
		'vscode'         => __( 'VS Code', 'oversio-agent-abilities' ),
		'windsurf'       => __( 'Windsurf', 'oversio-agent-abilities' ),
		'gemini-cli'     => __( 'Gemini CLI', 'oversio-agent-abilities' ),
		'manus'          => __( 'Manus', 'oversio-agent-abilities' ),
		'generic'        => __( 'Generic', 'oversio-agent-abilities' ),
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
function oversio_quickstart_note( string $client ): string {
	switch ( $client ) {
		case 'claude-desktop':
			return __( 'Paste into claude_desktop_config.json (Settings → Developer → Edit Config), then restart Claude.', 'oversio-agent-abilities' );
		case 'claude-code':
			return __( "Add it to your project's .mcp.json, or run claude mcp add.", 'oversio-agent-abilities' );
		case 'cursor':
			return __( 'Add it to ~/.cursor/mcp.json (or Settings → MCP), then reload.', 'oversio-agent-abilities' );
		case 'vscode':
			return __( 'Save it as .vscode/mcp.json in your workspace. Note the key is "servers", not "mcpServers".', 'oversio-agent-abilities' );
		case 'windsurf':
			return __( "Add it under Windsurf's MCP config (mcp_config.json) and refresh the server list.", 'oversio-agent-abilities' );
		case 'gemini-cli':
			return __( 'Add it to the mcpServers block in your Gemini CLI settings.json.', 'oversio-agent-abilities' );
		case 'manus':
			return __( "Add it to Manus's MCP server config.", 'oversio-agent-abilities' );
		case 'generic':
			return __( 'For any client that speaks MCP over the mcp-wordpress-remote proxy, use this block.', 'oversio-agent-abilities' );
		default:
			return '';
	}
}

/**
 * Build a copy-paste config snippet for the OAuth bridge path.
 *
 * Like {@see oversio_client_snippet()}, but for the OAuth connection method.  The
 * agent connects over the browser-based OAuth approval flow — no stored secret
 * is ever needed, so the snippet carries only the endpoint URL (via mcp-remote)
 * and never any `WP_API_*` env vars.
 *
 * On a local install the `env` block carries `NODE_EXTRA_CA_CERTS` pointing at a
 * placeholder path so the operator can substitute their own mkcert root CA and
 * avoid Node rejecting the locally-trusted certificate.  Production snippets
 * include no `env` block at all.
 *
 * The unix/windows and VS Code root-key rules match {@see oversio_client_snippet()}
 * exactly so the two snippet renderers feel consistent.
 *
 * @param string $client Target client slug (see {@see oversio_quickstart_clients()}).
 * @param string $os     Target OS shape: 'unix' (default) or 'windows'.
 * @return string JSON snippet, credential-free.
 */
function oversio_oauth_client_snippet( string $client, string $os = 'unix' ): string {
	$package = 'mcp-remote';
	$url     = oversio_endpoint_url();

	if ( 'windows' === $os ) {
		$command = 'cmd';
		$args    = array( '/c', 'npx', '-y', $package, $url );
	} else {
		$command = 'npx';
		$args    = array( '-y', $package, $url );
	}

	$entry = array(
		'command' => $command,
		'args'    => $args,
	);

	if ( oversio_site_is_local() ) {
		// Placeholder path: local TLS certificates (mkcert, DDEV, Valet) are machine-specific.
		// The operator replaces this string with the real path from `mkcert -CAROOT`.
		$entry['env'] = array( 'NODE_EXTRA_CA_CERTS' => 'PATH-TO-YOUR-mkcert-rootCA.pem' );
	}

	$server   = array( 'agent-abilities' => $entry );
	$root_key = ( 'vscode' === $client ) ? 'servers' : 'mcpServers';

	return (string) wp_json_encode( array( $root_key => $server ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * Whether this client's primary OAuth connection mode is native (URL-based) or bridge.
 *
 * Clients that have first-class support for adding a remote MCP server by URL and running
 * the OAuth browser flow natively are classed as 'native'.  Clients that cannot run the
 * browser flow by themselves are guided through the mcp-remote stdio bridge first ('bridge').
 *
 * @param string $client Client slug.
 * @return string 'native' or 'bridge'.
 */
function oversio_oauth_client_mode( string $client ): string {
	$native_clients = array( 'claude-desktop', 'claude-code', 'cursor', 'vscode', 'windsurf', 'gemini-cli' );
	return in_array( $client, $native_clients, true ) ? 'native' : 'bridge';
}

/**
 * Per-client one-line note explaining where to paste the OAuth endpoint URL.
 *
 * Plain prose, no markup, fully translatable. Returns an empty string for unknown slugs so
 * callers can skip the note rather than rendering a blank paragraph.
 *
 * @param string $client Client slug.
 * @return string Localized instruction, or '' for an unknown slug.
 */
function oversio_oauth_client_note( string $client ): string {
	switch ( $client ) {
		case 'claude-desktop':
			return __( 'Settings → Connectors → Add custom connector, then paste the endpoint URL.', 'oversio-agent-abilities' );
		case 'claude-code':
			return __( 'Run: claude mcp add --transport http agent-abilities <endpoint-url>', 'oversio-agent-abilities' );
		case 'cursor':
			return __( 'Add a server to ~/.cursor/mcp.json with a "url" pointing at the endpoint, then reload.', 'oversio-agent-abilities' );
		case 'vscode':
			return __( 'Add a .vscode/mcp.json server with "type":"http" and the endpoint "url" (key is "servers").', 'oversio-agent-abilities' );
		case 'windsurf':
			return __( "Add the endpoint URL as a server in Windsurf's MCP config, then refresh.", 'oversio-agent-abilities' );
		case 'gemini-cli':
			return __( 'Add the endpoint under httpUrl in your Gemini CLI settings.json mcpServers block.', 'oversio-agent-abilities' );
		case 'manus':
			return __( "Add the endpoint URL in Manus's MCP server config.", 'oversio-agent-abilities' );
		case 'generic':
			return __( 'Use the bridge snippet below with any MCP client that runs a local stdio server.', 'oversio-agent-abilities' );
		default:
			return '';
	}
}

/**
 * AJAX: create the dedicated agent user.
 *
 * @return void
 */
function oversio_ajax_create_agent_user(): void {
	check_ajax_referer( 'oversio_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to create users.', 'oversio-agent-abilities' ) ), 403 );
	}
	$login  = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( (string) $_POST['login'] ), true ) : '';
	$result = oversio_create_agent_user( $login );
	if ( is_wp_error( $result ) ) {
		$payload = array( 'message' => $result->get_error_message() );
		// On a duplicate username, carry the existing user's id + edit link so the JS can
		// render an "Edit user" link rather than a bare error string.
		$data = $result->get_error_data();
		if ( is_array( $data ) && ! empty( $data['user_id'] ) ) {
			$payload['user_id']  = (int) $data['user_id'];
			$payload['edit_url'] = isset( $data['edit_url'] ) ? esc_url_raw( (string) $data['edit_url'] ) : '';
		}
		wp_send_json_error( $payload );
	}
	wp_send_json_success( $result );
}

/**
 * AJAX: revoke an OAuth client from the Connections management table.
 *
 * Deactivates the client and revokes its active tokens, so it is locked out at once.
 * Nonce + manage_options gated; the client_id is the only client-supplied value and
 * it is sanitized as plain text before use.
 *
 * @return void
 */
function oversio_ajax_oauth_revoke_client(): void {
	check_ajax_referer( 'oversio_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'oversio-agent-abilities' ) ), 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) : '';
	if ( '' === $client_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing client.', 'oversio-agent-abilities' ) ) );
	}

	oversio_oauth_deactivate_client( $client_id );
	$revoked = oversio_oauth_revoke_client_tokens( $client_id );
	// Drop any pending (not-yet-redeemed) authorization codes too, or one could still mint
	// fresh tokens within its short window after the client is revoked.
	oversio_oauth_revoke_client_codes( $client_id );

	wp_send_json_success(
		array(
			'client_id'      => $client_id,
			'revoked_tokens' => $revoked,
		)
	);
}

/**
 * AJAX: revoke an OAuth grant (user consent) from the Connections management table.
 *
 * Deletes the user's consent for the client and revokes that user+client's active
 * tokens, so the user must re-approve to reconnect. Nonce + manage_options gated;
 * user_id is cast with absint and client_id sanitized as plain text.
 *
 * @return void
 */
function oversio_ajax_oauth_revoke_grant(): void {
	check_ajax_referer( 'oversio_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'oversio-agent-abilities' ) ), 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) : '';
	if ( $user_id <= 0 || '' === $client_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing grant.', 'oversio-agent-abilities' ) ) );
	}

	oversio_oauth_delete_consent( $user_id, $client_id );
	$revoked = oversio_oauth_revoke_user_client_tokens( $user_id, $client_id );
	// Drop any pending (not-yet-redeemed) authorization codes for this user+client too, or one
	// could still mint fresh tokens after the consent and existing tokens are gone.
	oversio_oauth_revoke_user_client_codes( $user_id, $client_id );

	wp_send_json_success(
		array(
			'user_id'        => $user_id,
			'client_id'      => $client_id,
			'revoked_tokens' => $revoked,
		)
	);
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
function oversio_ajax_test_connection(): void {
	check_ajax_referer( 'oversio_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'oversio-agent-abilities' ) ), 403 );
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
		oversio_endpoint_url(),
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
 * Render the OAuth management UI: a Registered Clients table and an Active Grants table.
 *
 * Both read from the plugin's own OAuth tables and render fully escaped, using the same
 * widefat striped / oversio-pill / oversio-btn styling as the rest of the admin. Each table
 * shows a one-line empty state when it has no rows. Revoke buttons are wired in admin.js
 * (confirm + nonce-checked AJAX); the nonce field printed here is what those calls read.
 *
 * Only ever called from inside the capability-gated Connection tab, within the
 * oversio_oauth_enabled() card.
 *
 * @return void
 */
function oversio_render_oauth_management(): void {
	$clients = oversio_oauth_list_clients();
	$grants  = oversio_oauth_list_grants();

	echo '<div class="oversio-oauth-manage">';
	wp_nonce_field( 'oversio_admin', 'oversio_oauth_admin_nonce' );

	// ---- Registered clients ----
	echo '<h3>' . esc_html__( 'Registered clients', 'oversio-agent-abilities' ) . '</h3>';
	echo '<p class="sub">' . esc_html__( 'Apps that have registered to connect over OAuth. Revoking a client turns it off and ends its active sessions right away.', 'oversio-agent-abilities' ) . '</p>';

	if ( empty( $clients ) ) {
		echo '<p class="oversio-empty-state">' . esc_html__( 'No clients have registered yet.', 'oversio-agent-abilities' ) . '</p>';
	} else {
		echo '<div class="oversio-table-wrap">';
		echo '<table class="widefat striped oversio-oauth-table oversio-clients-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Client', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Client ID', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Redirect URIs', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Active tokens', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'oversio-agent-abilities' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $clients as $client ) {
			$full_id  = $client['client_id'];
			$short_id = strlen( $full_id ) > 14 ? substr( $full_id, 0, 14 ) . '…' : $full_id;
			$name     = '' !== $client['client_name'] ? $client['client_name'] : __( '(unnamed client)', 'oversio-agent-abilities' );

			printf( '<tr data-client-row="%s">', esc_attr( $full_id ) );
			printf( '<td>%s</td>', esc_html( $name ) );
			printf( '<td><code title="%1$s">%2$s</code></td>', esc_attr( $full_id ), esc_html( $short_id ) );

			echo '<td>';
			if ( empty( $client['redirect_uris'] ) ) {
				echo '<span class="oversio-muted">' . esc_html__( 'None', 'oversio-agent-abilities' ) . '</span>';
			} else {
				$links = array();
				foreach ( $client['redirect_uris'] as $uri ) {
					$links[] = '<a href="' . esc_url( $uri ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $uri ) . '</a>';
				}
				echo implode( '<br>', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each URI escaped above.
			}
			echo '</td>';

			printf( '<td>%s</td>', esc_html( oversio_format_admin_datetime( $client['created_at'] ) ) );
			printf( '<td class="oversio-client-tokens">%s</td>', esc_html( number_format_i18n( $client['active_tokens'] ) ) );

			echo '<td class="oversio-status-cell">';
			if ( $client['is_active'] ) {
				echo '<span class="oversio-pill oversio-pill-success">' . esc_html__( 'Active', 'oversio-agent-abilities' ) . '</span>';
			} else {
				echo '<span class="oversio-pill oversio-pill-neutral">' . esc_html__( 'Revoked', 'oversio-agent-abilities' ) . '</span>';
			}
			echo '</td>';

			echo '<td>';
			if ( $client['is_active'] ) {
				printf(
					'<button type="button" class="oversio-btn oversio-btn-secondary oversio-revoke-client" data-client-id="%s">%s</button>',
					esc_attr( $full_id ),
					esc_html__( 'Revoke', 'oversio-agent-abilities' )
				);
			} else {
				echo '<span class="oversio-muted">' . esc_html__( 'Revoked', 'oversio-agent-abilities' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	// ---- Active grants ----
	echo '<h3>' . esc_html__( 'Active grants', 'oversio-agent-abilities' ) . '</h3>';
	echo '<p class="sub">' . esc_html__( 'People who have approved an app to act as them. Revoking a grant ends that connection; they would need to approve again to reconnect.', 'oversio-agent-abilities' ) . '</p>';

	if ( empty( $grants ) ) {
		echo '<p class="oversio-empty-state">' . esc_html__( 'No one has approved an OAuth connection yet.', 'oversio-agent-abilities' ) . '</p>';
	} else {
		$scope_hint = __( 'The app can only do what this user\'s role allows and what you have turned on under Abilities.', 'oversio-agent-abilities' );

		echo '<div class="oversio-table-wrap">';
		echo '<table class="widefat striped oversio-oauth-table oversio-grants-table"><thead><tr>';
		echo '<th>' . esc_html__( 'User', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Client', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Scope', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Granted', 'oversio-agent-abilities' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'oversio-agent-abilities' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $grants as $grant ) {
			$client_name = '' !== $grant['client_name'] ? $grant['client_name'] : __( '(unnamed client)', 'oversio-agent-abilities' );

			printf(
				'<tr data-grant-row="%1$s|%2$s">',
				esc_attr( (string) $grant['user_id'] ),
				esc_attr( $grant['client_id'] )
			);
			printf(
				/* translators: 1: user display name, 2: user login. */
				'<td>%1$s <span class="oversio-muted">(%2$s)</span></td>',
				esc_html( $grant['user_display'] ),
				esc_html( $grant['user_login'] )
			);
			printf( '<td>%s</td>', esc_html( $client_name ) );
			printf(
				'<td><span title="%1$s">%2$s</span></td>',
				esc_attr( $scope_hint ),
				esc_html__( 'Full access', 'oversio-agent-abilities' )
			);
			printf( '<td>%s</td>', esc_html( oversio_format_admin_datetime( $grant['granted_at'] ) ) );
			printf(
				'<td><button type="button" class="oversio-btn oversio-btn-secondary oversio-revoke-grant" data-user-id="%1$s" data-client-id="%2$s">%3$s</button></td>',
				esc_attr( (string) $grant['user_id'] ),
				esc_attr( $grant['client_id'] ),
				esc_html__( 'Revoke', 'oversio-agent-abilities' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	echo '</div>'; // .oversio-oauth-manage
}

/**
 * Format a stored UTC datetime for admin display, falling back to the raw value.
 *
 * The OAuth tables store created_at/granted_at as UTC 'Y-m-d H:i:s'. This renders them
 * with the site's date+time format so the management tables read consistently with the
 * rest of the admin. An unparseable value is returned as-is (already escaped by the caller).
 *
 * @param string $utc Stored UTC datetime string.
 * @return string Formatted local datetime, or the original string when it cannot be parsed.
 */
function oversio_format_admin_datetime( string $utc ): string {
	if ( '' === $utc ) {
		return '';
	}
	$ts = strtotime( $utc . ' UTC' );
	if ( false === $ts ) {
		return $utc;
	}
	$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	return false === $formatted ? $utc : $formatted;
}

/**
 * Render the Connection tab.
 *
 * Layout (OAuth-first):
 *   1. Endpoint card — single canonical endpoint display.
 *   2. OAuth card (Recommended) — guided browser-approval flow when OAuth is enabled;
 *      a short notice when it is turned off.
 *   3. App-Password fallback — the existing three-step wizard wrapped in a <details>
 *      element, collapsed by default when OAuth is on, open when OAuth is off.
 *
 * @return void
 */
function oversio_render_connection_tab(): void {
	$url       = oversio_endpoint_url();
	$oauth_on  = oversio_oauth_enabled();
	$is_local  = oversio_site_is_local();
	$kses_code = array( 'code' => array() );

	echo '<div class="oversio-connection">';

	// ---- 1. Endpoint card (the single canonical endpoint display) ----
	echo '<section class="oversio-card oversio-card-pad oversio-endpoint-card">';
	echo '<div class="oversio-stat-label">' . esc_html__( 'MCP endpoint', 'oversio-agent-abilities' ) . '</div>';
	echo '<div class="oversio-field-mono">';
	printf( '<span class="oversio-endpoint">%s</span>', esc_html( $url ) );
	printf(
		'<button type="button" class="oversio-btn oversio-btn-secondary oversio-copy" data-copy="%1$s" aria-label="%4$s">%2$s<span class="oversio-copy-label">%3$s</span></button>',
		esc_attr( $url ),
		oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'oversio-agent-abilities' ),
		esc_attr__( 'Copy the MCP endpoint URL', 'oversio-agent-abilities' )
	);
	echo '</div>';
	echo '</section>';

	// ---- 2. OAuth card ----
	echo '<section class="oversio-card oversio-card-pad oversio-oauth-card">';
	echo '<div class="oversio-oauth-card-head">';
	echo '<h2>' . esc_html__( 'Connect with OAuth', 'oversio-agent-abilities' ) . '</h2>';
	echo '<span class="oversio-pill oversio-pill-info oversio-recommended-pill">' . esc_html__( 'Recommended', 'oversio-agent-abilities' ) . '</span>';
	echo '</div>';

	if ( $oauth_on ) {
		echo '<p class="sub">' . esc_html__( 'Paste your site\'s MCP endpoint URL into your agent. It opens a browser tab for approval — no secret to copy or store.', 'oversio-agent-abilities' ) . '</p>';

		// OAuth client picker: OS tabs + client grid + per-client instructions and bridge snippet.
		echo '<div class="oversio-connect-controls oversio-oauth-picker">';

		echo '<div class="oversio-connect-os">';
		echo '<div class="oversio-stat-label" id="oversio-os-label-oauth">' . esc_html__( 'Your operating system', 'oversio-agent-abilities' ) . '</div>';
		// The OS tabs are a cross-cutting filter over the per-client snippet pairs (one tablist,
		// many panels), so they carry no aria-controls (optional in the WAI-ARIA tabs pattern);
		// the tablist is given an accessible name via aria-labelledby instead.
		echo '<div class="oversio-seg oversio-os-tabs" role="tablist" aria-labelledby="oversio-os-label-oauth">';
		printf(
			'<button type="button" class="oversio-os-tab is-active on" data-os="unix" role="tab" aria-selected="true">%s</button>',
			esc_html__( 'macOS / Linux', 'oversio-agent-abilities' )
		);
		printf(
			'<button type="button" class="oversio-os-tab" data-os="windows" role="tab" aria-selected="false">%s</button>',
			esc_html__( 'Windows', 'oversio-agent-abilities' )
		);
		echo '</div>';
		echo '</div>';

		echo '<div class="oversio-connect-client">';
		echo '<div class="oversio-stat-label">' . esc_html__( 'Your client', 'oversio-agent-abilities' ) . '</div>';
		echo '<div class="oversio-client-grid" id="oversio-oauth-clients">';
		$first = true;
		foreach ( oversio_quickstart_clients() as $slug => $label ) {
			printf(
				'<div class="oversio-client%1$s" data-client="%2$s"><span class="ci">%3$s</span>%4$s</div>',
				$first ? ' on' : '',
				esc_attr( $slug ),
				oversio_icon( 'client-' . $slug ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
				esc_html( $label )
			);
			$first = false;
		}
		echo '</div>';
		echo '</div>';

		echo '</div>'; // .oversio-oauth-picker

		// Per-client OAuth instructions + bridge snippet: hidden data cards the JS swaps in.
		// Native-mode clients lead with the URL paste note; bridge-mode clients lead with the snippet.
		echo '<div class="oversio-oauth-panels">';
		foreach ( oversio_quickstart_clients() as $slug => $label ) {
			$mode = oversio_oauth_client_mode( $slug );
			$note = oversio_oauth_client_note( $slug );

			$unix_oauth    = oversio_oauth_client_snippet( $slug, 'unix' );
			$windows_oauth = oversio_oauth_client_snippet( $slug, 'windows' );

			printf(
				'<div class="oversio-oauth-panel" data-client="%s"%s>',
				esc_attr( $slug ),
				'claude-desktop' === $slug ? '' : ' hidden'
			);

			if ( 'native' === $mode ) {
				// Native: lead with URL-paste instructions, offer bridge as secondary.
				if ( '' !== $note ) {
					echo '<p class="oversio-oauth-note">' . esc_html( $note ) . '</p>';
				}
				// Local cert notice for native-mode clients when the site is local.
				if ( $is_local ) {
					$local_note = __( 'Your site looks local. If the client throws a certificate error, use the bridge snippet below and point NODE_EXTRA_CA_CERTS at your mkcert root CA.', 'oversio-agent-abilities' );
					echo '<p class="oversio-oauth-local-note">' . esc_html( $local_note ) . '</p>';
				}
				echo '<details class="oversio-bridge-alt">';
				echo '<summary>' . esc_html__( 'Need a local server instead? Use the mcp-remote bridge.', 'oversio-agent-abilities' ) . '</summary>';
			} else {
				// Bridge-mode clients: lead straight with the snippet.
				if ( '' !== $note ) {
					echo '<p class="oversio-oauth-note">' . esc_html( $note ) . '</p>';
				}
				echo '<div class="oversio-bridge-alt">';
			}

			// Bridge snippet (shown for both modes; inside <details> for native clients).
			echo '<div class="oversio-codeblock oversio-snippet" data-os="unix">';
			printf( '<pre>%s</pre>', esc_html( $unix_oauth ) );
			printf(
				'<button type="button" class="oversio-btn oversio-btn-secondary oversio-btn-sm copy-fab oversio-copy" data-copy="%1$s">%2$s<span class="oversio-copy-label">%3$s</span></button>',
				esc_attr( $unix_oauth ),
				oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
				esc_html__( 'Copy', 'oversio-agent-abilities' )
			);
			echo '</div>';

			echo '<div class="oversio-codeblock oversio-snippet" data-os="windows" hidden>';
			printf( '<pre>%s</pre>', esc_html( $windows_oauth ) );
			printf(
				'<button type="button" class="oversio-btn oversio-btn-secondary oversio-btn-sm copy-fab oversio-copy" data-copy="%1$s">%2$s<span class="oversio-copy-label">%3$s</span></button>',
				esc_attr( $windows_oauth ),
				oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
				esc_html__( 'Copy', 'oversio-agent-abilities' )
			);
			echo '</div>';

			if ( 'native' === $mode ) {
				echo '</details>'; // .oversio-bridge-alt
			} else {
				echo '</div>'; // .oversio-bridge-alt
			}

			echo '</div>'; // .oversio-oauth-panel
		}
		echo '</div>'; // .oversio-oauth-panels

		// OAuth management tables (registered clients + active grants).
		oversio_render_oauth_management();

	} else {
		// OAuth is off — short notice instead of the picker.
		echo '<p class="sub">' . esc_html__(
			'OAuth is turned off. Enable it under Settings to use the browser-approval flow, or connect with an Application Password below.',
			'oversio-agent-abilities'
		) . '</p>';
	}

	echo '</section>';

	// ---- 3. App-Password fallback: existing three-step wizard inside <details> ----
	// Open by default when OAuth is off so the wizard is immediately visible; collapsed when
	// OAuth is on because most operators will use the OAuth path above.
	$open_attr = $oauth_on ? '' : ' open';
	printf( '<details class="oversio-app-password-fallback"%s>', esc_attr( $open_attr ) );
	echo '<summary>' . esc_html__( 'OAuth not working? Connect with an Application Password', 'oversio-agent-abilities' ) . '</summary>';

	// ---- Step 1: create a dedicated agent user ----
	$default_agent_login = 'mcp-agent';
	$existing_agent_id   = (int) username_exists( $default_agent_login );
	$agent_exists        = $existing_agent_id > 0;

	printf(
		'<div class="oversio-step oversio-conn-step%s">',
		$agent_exists ? ' oversio-step-done' : ''
	);
	echo '<div class="oversio-step-head"><span class="oversio-sidx">';
	if ( $agent_exists ) {
		echo oversio_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	} else {
		echo '1';
	}
	echo '</span><div>';
	echo '<h2>' . esc_html__( 'Create a dedicated agent user', 'oversio-agent-abilities' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'Give the agent its own user with the least privilege it needs. It can only do what that user\'s role allows, and every ability is off until you turn it on.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div></div>';
	echo '<div class="oversio-step-rail"><div class="oversio-card oversio-card-pad">';
	wp_nonce_field( 'oversio_admin', 'oversio_conn_nonce' );
	if ( $agent_exists ) {
		$edit_url = (string) get_edit_user_link( $existing_agent_id );
		echo '<p class="oversio-agent-done">';
		echo '<span class="oversio-pill oversio-pill-success">' . esc_html__( 'Done', 'oversio-agent-abilities' ) . '</span> ';
		printf(
			/* translators: %s: the agent user's login. */
			esc_html__( 'The %s user already exists, so you can move straight to connecting your client.', 'oversio-agent-abilities' ),
			'<strong>' . esc_html( $default_agent_login ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- login escaped above.
		);
		if ( '' !== $edit_url ) {
			printf(
				' <a href="%1$s">%2$s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit user', 'oversio-agent-abilities' )
			);
		}
		echo '</p>';
	} else {
		echo '<p><input type="text" id="oversio-agent-login" value="' . esc_attr( $default_agent_login ) . '" class="regular-text"> <button type="button" class="oversio-btn oversio-btn-secondary" id="oversio-create-user">' . esc_html__( 'Create agent user', 'oversio-agent-abilities' ) . '</button> <span class="oversio-user-status" aria-live="polite"></span></p>';
	}
	echo '</div></div>';
	echo '</div>';

	// ---- Step 2: connect your client (App Password path) ----
	echo '<div class="oversio-step oversio-conn-step">';
	echo '<div class="oversio-step-head"><span class="oversio-sidx">2</span><div>';
	echo '<h2>' . esc_html__( 'Connect your client', 'oversio-agent-abilities' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'Generate an Application Password for the agent user (Users → Profile → Application Passwords), pick your client, then copy the config.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div></div>';

	echo '<div class="oversio-step-rail"><div class="oversio-card">';

	// OS toggle + client picker row.
	echo '<div class="oversio-card-pad oversio-connect-controls">';

	echo '<div class="oversio-connect-os">';
	echo '<div class="oversio-stat-label" id="oversio-os-label-bridge">' . esc_html__( 'Your operating system', 'oversio-agent-abilities' ) . '</div>';
	// The .oversio-seg buttons double as the OS tabs admin.js binds (oversio-os-tab + data-os).
	// One tablist filters many snippet panels, so no aria-controls; named via aria-labelledby.
	echo '<div class="oversio-seg oversio-os-tabs" role="tablist" aria-labelledby="oversio-os-label-bridge">';
	printf(
		'<button type="button" class="oversio-os-tab is-active on" data-os="unix" role="tab" aria-selected="true">%s</button>',
		esc_html__( 'macOS / Linux', 'oversio-agent-abilities' )
	);
	printf(
		'<button type="button" class="oversio-os-tab" data-os="windows" role="tab" aria-selected="false">%s</button>',
		esc_html__( 'Windows', 'oversio-agent-abilities' )
	);
	echo '</div>';
	echo '</div>';

	echo '<div class="oversio-connect-client">';
	echo '<div class="oversio-stat-label">' . esc_html__( 'Your client', 'oversio-agent-abilities' ) . '</div>';
	echo '<div class="oversio-client-grid" id="oversio-clients">';
	$first = true;
	foreach ( oversio_quickstart_clients() as $slug => $label ) {
		printf(
			'<div class="oversio-client%1$s" data-client="%2$s"><span class="ci">%3$s</span>%4$s</div>',
			$first ? ' on' : '',
			esc_attr( $slug ),
			oversio_icon( 'client-' . $slug ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
			esc_html( $label )
		);
		$first = false;
	}
	echo '</div>';
	echo '</div>';

	echo '</div>'; // .oversio-connect-controls

	// Primary config block: the default (first) client, one .oversio-codeblock per OS.
	echo '<div class="oversio-card-pad">';

	// Use the first real client slug from the grid (claude-desktop), not a non-existent 'claude'
	// that only happened to fall through to the default root key.
	$unix_snippet    = oversio_client_snippet( 'claude-desktop', 'mcp-agent', 'unix' );
	$windows_snippet = oversio_client_snippet( 'claude-desktop', 'mcp-agent', 'windows' );

	echo '<div class="oversio-codeblock oversio-snippet" data-os="unix">';
	printf( '<pre>%s</pre>', esc_html( $unix_snippet ) );
	printf(
		'<button type="button" class="oversio-btn oversio-btn-secondary oversio-btn-sm copy-fab oversio-copy" data-copy="%1$s">%2$s<span class="oversio-copy-label">%3$s</span></button>',
		esc_attr( $unix_snippet ),
		oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'oversio-agent-abilities' )
	);
	echo '</div>';

	echo '<div class="oversio-codeblock oversio-snippet" data-os="windows" hidden>';
	printf( '<pre>%s</pre>', esc_html( $windows_snippet ) );
	printf(
		'<button type="button" class="oversio-btn oversio-btn-secondary oversio-btn-sm copy-fab oversio-copy" data-copy="%1$s">%2$s<span class="oversio-copy-label">%3$s</span></button>',
		esc_attr( $windows_snippet ),
		oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'oversio-agent-abilities' )
	);
	echo '</div>';

	// Windows / certificate notices.
	$windows_note = __( 'On Windows the launcher is wrapped in <code>cmd /c</code> so the <code>npx</code> command resolves — use the Windows tab above.', 'oversio-agent-abilities' );

	$cert_note = __( 'If your site uses a self-signed or locally-trusted certificate (DDEV, Local, Valet), Node rejects it by default; add <code>"NODE_TLS_REJECT_UNAUTHORIZED": "0"</code> to <code>env</code> for local testing only, never on a production site.', 'oversio-agent-abilities' );
	if ( $is_local ) {
		$cert_note .= ' ' . __( 'This site looks local, so that line is already included above.', 'oversio-agent-abilities' );
	}

	// Bespoke notice chrome on purpose: this callout needs two labelled rows (Windows, Certificate),
	// which oversio_render_notice()'s single dashicon + single body block cannot express.
	echo '<div class="oversio-os-note notice notice-info inline">';
	echo '<p class="oversio-os-note-row"><span class="oversio-os-note-label">' . esc_html__( 'Windows', 'oversio-agent-abilities' ) . '</span> <span class="oversio-os-note-text">' . wp_kses( $windows_note, $kses_code ) . '</span></p>';
	echo '<p class="oversio-os-note-row"><span class="oversio-os-note-label">' . esc_html__( 'Certificate', 'oversio-agent-abilities' ) . '</span> <span class="oversio-os-note-text">' . wp_kses( $cert_note, $kses_code ) . '</span></p>';
	echo '</div>';

	// Per-client quickstarts: the JS-toggled grid of ready-to-paste configs, one per client.
	// Each client's exact snippet stays present here so the picker can surface any of them.
	echo '<div class="oversio-quickstarts">';
	echo '<p><button type="button" class="button oversio-quickstart-toggle" aria-expanded="false" aria-controls="oversio-quickstart-grid">' . esc_html__( 'Show config for a specific client', 'oversio-agent-abilities' ) . '</button></p>';
	echo '<div class="oversio-quickstart-grid" id="oversio-quickstart-grid" hidden>';
	foreach ( oversio_quickstart_clients() as $slug => $label ) {
		$snippet = oversio_client_snippet( $slug, 'mcp-agent', 'unix' );
		echo '<div class="oversio-quickstart-card" data-client="' . esc_attr( $slug ) . '" data-config="' . esc_attr( $snippet ) . '">';
		echo '<h4 class="oversio-quickstart-name">' . esc_html( $label ) . '</h4>';
		echo '<p class="oversio-quickstart-where">' . esc_html( oversio_quickstart_note( $slug ) ) . '</p>';
		echo '<div class="oversio-codeblock">';
		printf( '<pre>%s</pre>', esc_html( $snippet ) );
		printf(
			'<button type="button" class="oversio-btn oversio-btn-secondary oversio-btn-sm copy-fab oversio-copy" data-copy="%1$s">%2$s<span class="oversio-copy-label">%3$s</span></button>',
			esc_attr( $snippet ),
			oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
			esc_html__( 'Copy', 'oversio-agent-abilities' )
		);
		echo '</div>';
		echo '</div>';
	}
	echo '</div>';
	echo '</div>';

	echo '</div>'; // .oversio-card-pad config block

	echo '</div></div>'; // .oversio-card / .oversio-step-rail
	echo '</div>'; // .oversio-step 2

	// ---- Step 3: check the endpoint is reachable ----
	echo '<div class="oversio-step oversio-conn-step">';
	echo '<div class="oversio-step-head"><span class="oversio-sidx">3</span><div>';
	echo '<h2>' . esc_html__( 'Check the endpoint is reachable', 'oversio-agent-abilities' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'This confirms the endpoint answers from your server. It checks as the current admin, so the tool count shown is your view — your agent connects as the low-privilege user above and will usually see fewer tools.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div></div>';

	echo '<div class="oversio-step-rail"><div class="oversio-card">';
	echo '<div class="oversio-card-pad oversio-connect-check">';
	echo '<button type="button" class="oversio-btn oversio-btn-primary" id="oversio-test-connection">';
	echo oversio_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo esc_html__( 'Check endpoint', 'oversio-agent-abilities' ) . '</button> <span class="oversio-test-status" aria-live="polite"></span>';
	echo '</div>';

	// Diagnostics rail: one row per check, status mapped to a coloured dot.
	$dot_class = array(
		'pass' => 'd-ok',
		'warn' => 'd-warn',
		'fail' => 'd-bad',
	);
	echo '<div class="oversio-diag">';
	foreach ( oversio_diagnostic_checks() as $check ) {
		$dot = $dot_class[ $check['status'] ] ?? 'd-warn';
		printf(
			'<div class="oversio-diag-row"><span class="dot-lg %1$s"></span><div><div class="d-title">%2$s</div><div class="d-detail">%3$s</div></div></div>',
			esc_attr( $dot ),
			esc_html( $check['label'] ),
			esc_html( $check['detail'] )
		);
	}
	echo '</div>';
	echo '</div></div>'; // .oversio-card / .oversio-step-rail
	echo '</div>'; // .oversio-step 3

	echo '</details>'; // .oversio-app-password-fallback

	echo '</div>'; // .oversio-connection
}
