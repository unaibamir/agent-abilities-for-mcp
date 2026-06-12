<?php
/**
 * Admin settings page: menu, tab routing, Abilities + Activity tabs, AJAX handlers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the settings submenu under Settings.
 *
 * @return void
 */
function aafm_register_admin_menu(): void {
	add_options_page(
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		__( 'Agent Abilities', 'agent-abilities-for-mcp' ),
		'manage_options',
		'agent-abilities-for-mcp',
		'aafm_render_admin_page'
	);
}

/**
 * Enqueue admin assets only on our settings page.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function aafm_enqueue_admin_assets( string $hook ): void {
	if ( 'settings_page_agent-abilities-for-mcp' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.css', array(), AAFM_VERSION );
	wp_enqueue_script( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.js', array(), AAFM_VERSION, true );
	wp_localize_script(
		'aafm-admin',
		'aafmAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aafm_admin' ),
		)
	);
}

/**
 * Sanitize posted ability toggles down to known registry keys.
 *
 * The result is intersected with the live registry, so a stale, unknown, or smuggled
 * key can never enable anything — only abilities that actually exist are honored.
 *
 * @param array<string,mixed> $posted The raw $_POST payload (slashes handled here).
 * @return array<int,string>
 */
function aafm_sanitize_enabled_input( array $posted ): array {
	$known   = array_keys( aafm_get_abilities_registry() );
	$enabled = array();
	if ( isset( $posted['aafm_abilities'] ) && is_array( $posted['aafm_abilities'] ) ) {
		foreach ( wp_unslash( $posted['aafm_abilities'] ) as $name ) {
			$enabled[] = sanitize_text_field( (string) $name );
		}
	}
	return array_values( array_intersect( $enabled, $known ) );
}

/**
 * AJAX: save the enabled-abilities toggles.
 *
 * @return void
 */
function aafm_ajax_save_abilities(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$enabled = aafm_sanitize_enabled_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	update_option( 'aafm_enabled_abilities', $enabled );
	wp_send_json_success( array( 'enabled' => $enabled ) );
}

/**
 * AJAX: clear the activity log.
 *
 * @return void
 */
function aafm_ajax_clear_log(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	aafm_clear_activity_log();
	wp_send_json_success();
}

/**
 * Render the page shell + the active tab.
 *
 * @return void
 */
function aafm_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'connection' => __( 'Connection', 'agent-abilities-for-mcp' ),
		'abilities'  => __( 'Abilities', 'agent-abilities-for-mcp' ),
		'activity'   => __( 'Activity Log', 'agent-abilities-for-mcp' ),
		'help'       => __( 'Help', 'agent-abilities-for-mcp' ),
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing, no state change.
	$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connection';
	if ( ! isset( $tabs[ $active ] ) ) {
		$active = 'connection';
	}

	echo '<div class="wrap aafm-wrap">';
	echo '<h1>' . esc_html__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '</h1>';
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'page' => 'agent-abilities-for-mcp',
						'tab'  => $slug,
					),
					admin_url( 'options-general.php' )
				)
			),
			esc_attr( $active === $slug ? 'nav-tab-active' : '' ),
			esc_html( $label )
		);
	}
	echo '</h2>';

	switch ( $active ) {
		case 'abilities':
			aafm_render_abilities_tab();
			break;
		case 'activity':
			aafm_render_activity_tab();
			break;
		case 'help':
			aafm_render_help_tab();
			break;
		default:
			aafm_render_connection_tab();
	}
	echo '</div>';
}

/**
 * Render the Abilities tab: grouped toggles, all OFF by default.
 *
 * @return void
 */
function aafm_render_abilities_tab(): void {
	$registry = aafm_get_abilities_registry();
	$enabled  = aafm_get_enabled_abilities();

	echo '<form id="aafm-abilities-form" class="aafm-abilities">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	$groups = array(
		'reads'  => __( 'Reads', 'agent-abilities-for-mcp' ),
		'writes' => __( 'Writes', 'agent-abilities-for-mcp' ),
	);

	foreach ( $groups as $group => $heading ) {
		echo '<h3>' . esc_html( $heading ) . '</h3>';
		echo '<table class="widefat striped aafm-ability-table"><tbody>';
		foreach ( $registry as $name => $meta ) {
			if ( ( $meta['group'] ?? '' ) !== $group ) {
				continue;
			}
			$risk = (string) ( $meta['risk'] ?? 'read' );
			printf(
				'<tr><td><label><input type="checkbox" name="aafm_abilities[]" value="%1$s" %2$s> %3$s</label></td><td><span class="aafm-badge aafm-badge-%4$s">%4$s</span></td><td>%5$s</td></tr>',
				esc_attr( (string) $name ),
				checked( in_array( (string) $name, $enabled, true ), true, false ),
				esc_html( (string) ( $meta['label'] ?? $name ) ),
				esc_attr( $risk ),
				esc_html( (string) ( $meta['description'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></p>';
	echo '</form>';
}

/**
 * Render the Activity Log tab (includes denials and errors).
 *
 * Every cell renders stored audit data, so each value is escaped on output. The log
 * only ever holds argument KEYS (never values) and a REMOTE_ADDR source IP, so there is
 * no PII to redact here beyond standard escaping.
 *
 * @return void
 */
function aafm_render_activity_tab(): void {
	$rows = aafm_query_activity( array( 'per_page' => 100 ) );

	echo '<div class="aafm-activity">';
	wp_nonce_field( 'aafm_admin', 'aafm_log_nonce' );
	echo '<p><button type="button" class="button" id="aafm-clear-log">' . esc_html__( 'Clear log', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-clear-status" aria-live="polite"></span></p>';
	echo '<table class="widefat striped aafm-log-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Time (UTC)', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Principal', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Ability', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Arg keys', 'agent-abilities-for-mcp' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( empty( $rows ) ) {
		echo '<tr><td colspan="5">' . esc_html__( 'No activity recorded yet.', 'agent-abilities-for-mcp' ) . '</td></tr>';
	}
	foreach ( $rows as $row ) {
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td><span class="aafm-status aafm-status-%4$s">%5$s</span></td><td>%6$s</td></tr>',
			esc_html( (string) ( $row['created_at'] ?? '' ) ),
			esc_html( (string) ( $row['principal_login'] ?? '' ) . ' (#' . (int) ( $row['principal_user_id'] ?? 0 ) . ')' ),
			esc_html( (string) ( $row['ability'] ?? '' ) ),
			esc_attr( (string) ( $row['status'] ?? '' ) ),
			esc_html( (string) ( $row['status'] ?? '' ) ),
			esc_html( (string) ( $row['arg_keys'] ?? '' ) )
		);
	}
	echo '</tbody></table>';
	echo '</div>';
}

/**
 * Render a single troubleshooting entry as a native <details> accordion.
 *
 * The question (summary) and body are pre-built, escaped HTML fragments. Bodies may
 * carry inline <code>, <p>, <ul>/<li>, <strong>, and <a> built by the caller — each
 * passed through wp_kses() with a tight allowed-tags list so nothing else slips in.
 *
 * @param string $summary Plain-text question shown in the <summary>.
 * @param string $body    Pre-escaped HTML body (already run through wp_kses by caller).
 * @return void
 */
function aafm_render_help_entry( string $summary, string $body ): void {
	echo '<details class="aafm-help-entry"><summary>' . esc_html( $summary ) . '</summary><div class="aafm-help-body">';
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is built locally and run through wp_kses by the caller.
	echo '</div></details>';
}

/**
 * Render a copyable single-line code snippet (reuses the .aafm-copy / data-copy JS).
 *
 * @param string $code The exact code line to display and copy.
 * @return string Escaped HTML.
 */
function aafm_help_copy_line( string $code ): string {
	return sprintf(
		'<div class="aafm-help-copy-line"><code>%1$s</code> <button type="button" class="button button-small aafm-copy" data-copy="%2$s">%3$s</button></div>',
		esc_html( $code ),
		esc_attr( $code ),
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);
}

/**
 * Render the Help tab: a site-admin troubleshooting reference.
 *
 * This is a backend-findable support page, not developer/CI documentation. Issues are
 * grouped into headed sections; each is a native <details>/<summary> accordion so the
 * page stays scannable with no new JS. Every dynamic string is escaped, and inline
 * markup is whitelisted through wp_kses().
 *
 * @return void
 */
function aafm_render_help_tab(): void {
	// Tight allow-lists for the inline markup used inside accordion bodies.
	$inline = array(
		'p'      => array(),
		'code'   => array(),
		'strong' => array(),
		'em'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'   => array(),
			'target' => array(),
			'rel'    => array(),
		),
		'div'    => array( 'class' => array() ),
		'button' => array(
			'type'      => array(),
			'class'     => array(),
			'data-copy' => array(),
		),
	);

	echo '<div class="aafm-help">';

	echo '<p class="description aafm-help-intro">' . esc_html__( 'Common connection and permission problems, with the fix for each. Cross-references the Connection tab where a built-in check or generated config already covers the case.', 'agent-abilities-for-mcp' ) . '</p>';

	// Section 1 — Connecting.
	echo '<h3>' . esc_html__( 'Connecting', 'agent-abilities-for-mcp' ) . '</h3>';

	// 1. Client won't connect / endpoint unreachable.
	aafm_render_help_entry(
		__( 'My client won\'t connect, or the endpoint looks unreachable', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Start on the Connection tab: run Step 3 "Check the endpoint is reachable". If that fails, open Diagnostics on the same tab and confirm "MCP adapter active and compatible" and "MCP REST endpoint registered" both show green.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'The endpoint URL depends on your permalink mode. With pretty permalinks it is the /wp-json/ form; with plain permalinks it is the index.php?rest_route= form. Always copy whatever the Connection tab shows under "Endpoint" rather than typing it by hand:', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Pretty:', 'agent-abilities-for-mcp' ) . '</strong> <code>/wp-json/agent-abilities-for-mcp/mcp</code></li>'
			. '<li><strong>' . esc_html__( 'Plain:', 'agent-abilities-for-mcp' ) . '</strong> <code>index.php?rest_route=/agent-abilities-for-mcp/mcp</code></li>'
			. '</ul>',
			$inline
		)
	);

	// 4. Windows: client config won't start.
	aafm_render_help_entry(
		__( 'The client connects but the AI backend gets blocked (403 / 406 / 429)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'This is the most common failure, and it is not an auth problem: the JSON-RPC POST never reaches WordPress at all. A CDN, WAF, or managed-host security rule sees automated traffic and rejects it before PHP runs, so you get a 403 (blocked), 406 (request looks like a bot), or 429 (rate limited) instead of a real MCP reply.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p><strong>' . esc_html__( 'Cloudflare:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'turn off "Block AI Bots" / Bot Fight Mode for this site, and add a WAF skip (allow) rule for the MCP route so it is never challenged or blocked. If the site is behind Cloudflare Zero Trust / Access, exempt the route there too.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p><strong>' . esc_html__( 'ModSecurity / managed-host rules:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'a generic rule returning 406 or 429 on POSTs from HTTP libraries is common on managed WordPress hosts. Ask the host to allow the MCP route, or add the path to the firewall allowlist.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Add the allow / skip rule for whichever endpoint form your site uses (copy the exact one from the Connection tab):', 'agent-abilities-for-mcp' ) . '</p>'
				. '<ul>'
				. '<li><strong>' . esc_html__( 'Pretty:', 'agent-abilities-for-mcp' ) . '</strong> <code>/wp-json/agent-abilities-for-mcp/*</code></li>'
				. '<li><strong>' . esc_html__( 'Plain:', 'agent-abilities-for-mcp' ) . '</strong> <code>/index.php?rest_route=/agent-abilities-for-mcp/*</code></li>'
				. '</ul>'
				. '<p>' . esc_html__( 'To confirm it is the edge and not WordPress, run the curl probe below: if curl from your own machine gets a 200 but the AI client still fails, the block is on the proxy or IP path the AI backend uses, not on your endpoint.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

		// CDN / WAF: page or edge cache intercepts the REST route.
		aafm_render_help_entry(
			__( 'A page cache or CDN is intercepting the endpoint', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'Full-page caching (a caching plugin) or edge caching (the CDN) can serve a cached or empty response for the MCP route instead of letting the request hit PHP. The symptom is a stale, blank, or HTML response where JSON-RPC was expected.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Exclude the MCP endpoint path from both full-page cache and edge cache. REST routes are dynamic and must never be cached:', 'agent-abilities-for-mcp' ) . '</p>'
				. '<ul>'
				. '<li><code>/wp-json/agent-abilities-for-mcp/*</code> ' . esc_html__( '(pretty permalinks)', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><code>/index.php?rest_route=/agent-abilities-for-mcp/*</code> ' . esc_html__( '(plain permalinks)', 'agent-abilities-for-mcp' ) . '</li>'
				. '</ul>',
				$inline
			)
		);

		// Connecting: a redirect breaks the POST.
		aafm_render_help_entry(
			__( 'A redirect is breaking the request (trailing slash or http to https)', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'A 301 redirect — adding or removing a trailing slash, or forcing http to https — can drop the POST body or the Authorization header on the way through, so the request that finally reaches WordPress is empty or unauthenticated. This is the request not arriving intact, not a credentials problem.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Use the exact endpoint URL the Connection tab shows, with the right scheme (https) and no extra trailing slash, so no redirect is triggered. If your server force-redirects http to https, make sure the config URL already starts with https so the POST is never redirected.', 'agent-abilities-for-mcp' ) . '</p>',
				$inline
			)
		);

		// Connecting: self-test with curl (verified against a live endpoint: 200/401/403-406-429/404/5xx).
		aafm_render_help_entry(
			__( 'Test the endpoint yourself with curl', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'This one-liner sends a real MCP "initialize" call to your endpoint with the agent user\'s Application Password. It tells you in one shot whether the endpoint is reachable, whether auth works, and — if it fails — which layer to blame. Replace the host, the username, and the Application Password (the password is the one shown once when you created it; keep its spaces):', 'agent-abilities-for-mcp' ) . '</p>'
				. aafm_help_copy_line( 'curl -i -X POST "https://example.com/wp-json/agent-abilities-for-mcp/mcp" -u "mcp-agent:XXXX XXXX XXXX XXXX XXXX XXXX" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-probe","version":"1.0"}}}\'' )
				. '<p>' . esc_html__( 'If your permalinks are plain, use the index.php form instead:', 'agent-abilities-for-mcp' ) . '</p>'
				. aafm_help_copy_line( 'curl -i -X POST "https://example.com/index.php?rest_route=/agent-abilities-for-mcp/mcp" -u "mcp-agent:XXXX XXXX XXXX XXXX XXXX XXXX" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-probe","version":"1.0"}}}\'' )
				. '<p><strong>' . esc_html__( 'How to read the result (the HTTP status on the first line):', 'agent-abilities-for-mcp' ) . '</strong></p>'
				. '<ul>'
				. '<li><strong>' . esc_html__( '200', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'reachable and authenticated; the body is a JSON-RPC result. Everything is working — if the AI client still fails, the block is on its side (see the 403/406/429 entry above).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '401', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'reached WordPress but auth failed: wrong or expired Application Password, or the Authorization header is being stripped (see the Authentication section).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '403 / 406 / 429', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'a WAF, CDN, or host security rule is blocking the request before WordPress (see the 403/406/429 entry above).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '404', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'the route is not registered for this URL: flush permalinks (Settings → Permalinks → Save) and confirm you copied the endpoint exactly from the Connection tab.', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '5xx', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'a server-side error: check your PHP error log and the host status.', 'agent-abilities-for-mcp' ) . '</li>'
				. '</ul>',
				$inline
			)
		);

		// 4. Windows: client config won't start.
		aafm_render_help_entry(
			__( 'Windows: my client config won\'t start', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'Windows MCP clients cannot spawn the npx shim by its name alone. The launcher has to be wrapped so the command resolves:', 'agent-abilities-for-mcp' ) . ' <code>cmd /c npx …</code></p>'
				. '<p>' . esc_html__( 'You do not need to hand-edit anything — switch to the "Windows" tab in Connection → Step 2 and copy the config it generates. It already wraps the launcher correctly.', 'agent-abilities-for-mcp' ) . '</p>',
				$inline
			)
		);

	// 5. Local / staging won't connect (self-signed cert).
	aafm_render_help_entry(
		__( 'My local or staging site won\'t connect (self-signed certificate)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Local stacks (DDEV, Local, Valet) serve a certificate Node does not trust, so the proxy refuses the TLS handshake. For local testing only, you can tell Node to accept it. The Connection tab already adds this for you when it detects a local site.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Quick (least safe) — add this to the config env block:', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( '"NODE_TLS_REJECT_UNAUTHORIZED": "0"' )
			. '<p>' . esc_html__( 'Better — point Node at your local CA instead of disabling verification entirely (for example mkcert\'s rootCA.pem):', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( '"NODE_EXTRA_CA_CERTS": "/path/to/rootCA.pem"' )
			. aafm_help_copy_line( '"NODE_USE_SYSTEM_CA": "1"' )
			. '<p><strong>' . esc_html__( 'Never use any of these on a production site.', 'agent-abilities-for-mcp' ) . '</strong></p>',
			$inline
		)
	);

	// Section 2 — Authentication.
	echo '<h3>' . esc_html__( 'Authentication', 'agent-abilities-for-mcp' ) . '</h3>';

	// 2. Authorization header diagnostic fails.
	aafm_render_help_entry(
		__( 'The "Authorization header reaches WordPress" diagnostic fails', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Some hosts and reverse proxies strip the Authorization header before it reaches PHP, so the Application Password never arrives and auth silently fails. Forward the header at the web-server layer.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Apache (.htaccess) — either of these:', 'agent-abilities-for-mcp' ) . '</strong></p>'
			. aafm_help_copy_line( 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' )
			. aafm_help_copy_line( 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]' )
			. '<p><strong>' . esc_html__( 'Nginx / FastCGI:', 'agent-abilities-for-mcp' ) . '</strong></p>'
			. aafm_help_copy_line( 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;' )
			. '<p>' . esc_html__( 'After applying, reload the web server and re-run Connection → Diagnostics.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 3. Application Passwords option missing.
	aafm_render_help_entry(
		__( 'The Application Passwords option is missing from my profile', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'WordPress core only offers Application Passwords over a secure (https) connection. Behind a TLS-terminating proxy or load balancer, WordPress can see the request as plain HTTP even though the browser is on https — so it hides the option.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Fix the proxy or HTTPS headers (or your site URL) so WordPress correctly detects https. Forwarding the standard X-Forwarded-Proto header from the proxy is the usual fix.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Do not enable Application Passwords over genuine plaintext HTTP in production — the credential would travel unencrypted.', 'agent-abilities-for-mcp' ) . '</strong></p>',
			$inline
		)
	);

	// Section 3 — Abilities & permissions.
	echo '<h3>' . esc_html__( 'Abilities & permissions', 'agent-abilities-for-mcp' ) . '</h3>';

	// 6. Agent sees fewer tools than expected.
	aafm_render_help_entry(
		__( 'My agent sees fewer tools than I expected', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'This is intentional least-privilege behaviour. Each connection is filtered by the agent user\'s own capabilities, so the agent only ever sees abilities its role allows: reads need read capabilities; writes need the matching edit, publish, moderate, or manage capabilities.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'To expose more tools, grant the agent user the role or capabilities those abilities require. Granting more, of course, widens what the agent can do — keep it to what the agent genuinely needs.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 7. Ability enabled but agent still can't use it.
	aafm_render_help_entry(
		__( 'An ability is enabled but the agent still can\'t use it', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Two things both have to be true for an ability to work:', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li>' . esc_html__( 'The ability is turned ON on the Abilities tab. Everything is OFF by default.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li>' . esc_html__( 'The agent user holds the WordPress capability that ability requires.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'If the toggle is on but the agent still gets refused, it is almost always the capability. Check the agent user\'s role.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// Section 4 — Clients, privacy & limits.
	echo '<h3>' . esc_html__( 'Clients, privacy & limits', 'agent-abilities-for-mcp' ) . '</h3>';

	// 8. Which AI clients work, and how to set each one up.
	aafm_render_help_entry(
		__( 'Which AI clients work, and how do I set each one up?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Claude Desktop, Claude Code, Cursor, and Windsurf all connect the same way: through the @automattic/mcp-wordpress-remote proxy, which is the package the Connection tab puts in the generated config. The proxy reads your endpoint URL and the agent user\'s Application Password and builds the auth itself.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Claude Desktop:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'paste the generated block into its claude_desktop_config.json (Settings → Developer → Edit Config) and restart the app.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Claude Code:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'add the same server to its MCP config (claude mcp add, or the .mcp.json in your project).', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Cursor:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'add the block to its MCP config (~/.cursor/mcp.json, or Settings → MCP) and reload.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Windsurf:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'add it under its MCP / plugins config (mcp_config.json) and refresh the server list.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'Copy the config straight from Connection → Step 2 — do not hand-build it. On Windows, use that tab\'s "Windows" view (it wraps the launcher in cmd /c); for a local or staging site, it adds the certificate handling. Both are covered in the Connecting section above.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'ChatGPT and Gemini are not supported in this release.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Their remote connectors expect a native streamable HTTP/SSE MCP transport, which the bundled adapter does not serve yet — they cannot use the proxy the way the clients above do.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 8b. Plain-language security model (the differentiator).
	aafm_render_help_entry(
		__( 'What can and can\'t this plugin do? (the security model in plain language)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'The plugin is built to be safe by default. In plain terms:', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'No external calls.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'It never phones home. Your credentials and your content never leave the site — the AI client connects in to you, not the other way round.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'A dedicated low-privilege user.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'The agent authenticates as its own separate WordPress user via an Application Password — not as you, and not as an administrator. You choose that user\'s role, so you set its ceiling.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Two locks on every ability.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'An ability works only if you explicitly enabled it on the Abilities tab AND the agent user\'s capabilities allow it. The default is nothing enabled — the agent starts with zero abilities until you turn them on.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Deletes are trash, not destroy.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Delete-style abilities move content to the Trash, where you can restore it; they do not permanently erase it.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Everything is logged, values are not.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Every call — including denied ones — is recorded on the Activity Log tab with the argument KEYS only, never the values. You can see what was attempted without leaking what was in it.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>',
			$inline
		)
	);

	// 9. Privacy / what gets logged.
	aafm_render_help_entry(
		__( 'What does the plugin log, and does it call out to anything?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'The plugin makes no external calls — nothing about your site or its content is sent anywhere.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'The activity log records only the argument KEYS of each call (never the values) plus the source IP address of the request. You can clear it any time from the Activity Log tab.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 10. Rate limiting.
	aafm_render_help_entry(
		__( 'Is there rate limiting?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Not in this release. Until it lands, bind the agent to a low-privilege user and enable only the abilities you actually need — that keeps the blast radius small regardless of call volume.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	echo '</div>';
}
