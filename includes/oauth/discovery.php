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
 * Whether a stored toggle option counts as "on".
 *
 * The OAuth surface is OFF by default: a public authorization server and open
 * dynamic client registration must be an explicit operator opt-in, never a
 * fresh-install default. So an ABSENT option reads OFF (default '0'), and the
 * toggle is on only when a genuinely truthy value was stored. Every falsy stored
 * form ('0', '', 'false', 'no', 'off', false, 0) also reads off, which keeps the
 * reader fail-closed even against a literal boolean-false row.
 *
 * Non-breaking migration: an install that already ran a prior activation has a
 * stored row (the seed wrote '1' before this change), so get_option() returns
 * that stored '1' and the surface keeps working untouched - the default only
 * governs installs with no row, i.e. new installs, which now seed '0'.
 *
 * This is the reader for both OAuth on/off toggles. aafm_oauth_enabled() routes
 * through it with the default '0' (OAuth is off until the operator opts in), and
 * aafm_oauth_dcr_enabled() routes through it with the default '1' (dynamic client
 * registration is on by default, disable in Settings). There is no raw get_option()
 * boolean read of either toggle anywhere (verified). The $default only governs an
 * install with no stored row; a stored value always wins.
 *
 * @param string $key      The toggle option name.
 * @param string $fallback Value assumed when the row is absent: '0' (off) or '1' (on).
 * @return bool True when the toggle reads on, false otherwise.
 */
function aafm_oauth_option_is_on( string $key, string $fallback = '0' ): bool {
	$value = get_option( $key, $fallback );

	$off = array( false, 0, '0', '', 'false', 'no', 'off' );

	return ! in_array( $value, $off, true ) && (bool) $value;
}

/**
 * Whether the OAuth surface is enabled.
 *
 * @return bool True only when the operator has explicitly enabled OAuth.
 */
function aafm_oauth_enabled(): bool {
	return aafm_oauth_option_is_on( 'aafm_oauth_enabled' );
}

/**
 * Whether Dynamic Client Registration (RFC 7591) is enabled.
 *
 * DCR has its own Settings toggle and is ON by default. The MCP clients this OAuth
 * surface exists to serve - ChatGPT and Claude - only ever connect through DCR, so a
 * default-off registration endpoint was a dead end that advertised an authorization
 * server no supported client could actually reach (issue #90). Shipping it on by
 * default removes that footgun; the operator can still turn it off in Settings when
 * they register clients by hand. The public /oauth/register route is rate-limited
 * (per-IP and global, see includes/oauth/http.php) and the real authorization gate is
 * the human consent screen, so open registration alongside OAuth is low-risk.
 *
 * DCR is only reachable when OAuth is on: the register route gates on both toggles and
 * the discovery metadata is served only once OAuth is enabled, so a default-on DCR
 * never exposes a registration endpoint on a site whose OAuth surface is still off.
 *
 * The stored `aafm_oauth_dcr_enabled` option is the source of truth (default '1'),
 * read through aafm_oauth_option_is_on(). The `aafm_oauth_dcr_enabled` filter still
 * wins over the stored value, so a developer can force registration closed in code.
 *
 * @return bool True when DCR is enabled (the toggle is on and not filtered off).
 */
function aafm_oauth_dcr_enabled(): bool {
	/**
	 * Filter whether Dynamic Client Registration is enabled.
	 *
	 * Defaults to the stored `aafm_oauth_dcr_enabled` toggle, which is on unless the
	 * operator turned it off. Return false to force client self-registration closed in
	 * code. Filtering it true has no practical effect while OAuth is off: the register
	 * route and the discovery metadata are gated on OAuth being enabled as well, so
	 * registration stays refused until OAuth itself is on.
	 *
	 * @param bool $enabled Whether DCR is enabled. Defaults to the stored toggle (on by default).
	 */
	return (bool) apply_filters( 'aafm_oauth_dcr_enabled', aafm_oauth_option_is_on( 'aafm_oauth_dcr_enabled', '1' ) );
}

/**
 * Seed the OAuth toggle option to "off" at activation, only when it is absent.
 *
 * OAuth is OFF by default: a public authorization server is a deliberate opt-in, so a
 * fresh install ships it closed and the operator turns it on in Settings. Seeding
 * writes the explicit '0' so the Settings toggle renders in its true (off) state from
 * the first load. add_option() (not update_option) is deliberate: it writes only when
 * the option does not yet exist, so this never clobbers an operator's saved value -
 * and, crucially, an install that was activated under an earlier version already holds
 * a stored '1' row, which this leaves untouched. Only genuinely new installs (no row)
 * get the off default.
 *
 * DCR is seeded '1' (on by default): the whole point of the OAuth surface is to let
 * ChatGPT and Claude connect, and both only ever register dynamically, so a fresh
 * install ships registration open and the operator turns it off in Settings if they
 * prefer to register clients by hand. Seeding writes the explicit '1' so the Settings
 * toggle renders in its true (on) state from the first load. It stays inert until
 * OAuth itself is switched on, since the register route and discovery both gate on
 * OAuth as well.
 *
 * @return void
 */
function aafm_oauth_seed_default_options(): void {
	// Both toggles are read on requests that touch the OAuth surface: aafm_oauth_enabled() gates
	// the CORS filters at bootstrap and the .well-known handler on parse_request, and
	// aafm_oauth_request_targets_mcp_route() consults it on determine_current_user;
	// aafm_oauth_dcr_enabled() is read by the register route and the discovery metadata. They must
	// stay autoloaded ('yes', the add_option default) so those hot-path reads never trigger a
	// separate query - switching either to autoload 'no' would be a per-request regression.
	add_option( 'aafm_oauth_enabled', '0', '', true );
	add_option( 'aafm_oauth_dcr_enabled', '1', '', true );
}

/**
 * Preserve an upgrading install's effective OAuth state across the off-by-default change.
 *
 * Before this release the toggle readers defaulted ON, so an install that updated in
 * place from a pre-seed version - one with no stored toggle row - was serving OAuth on
 * that on-by-default default. This release flips the default OFF so a genuinely new
 * install ships fail-closed. For a fresh install that is correct: aafm_oauth_seed_default_options()
 * writes an explicit '0' row at activation, which runs before this ever fires, so the
 * row is present and this leaves it off. But an in-place upgrade never re-runs activation,
 * so such a site holds NO row and relied on the old default; without this, the new '0'
 * default would silently disable its OAuth surface - and any live Claude/ChatGPT
 * connection - on update.
 *
 * So, exactly once per install: when the OAuth toggle row is ABSENT, write '1' to
 * preserve the prior behaviour. add_option() only writes a missing row, so it never
 * clobbers an operator's explicit '0' opt-out or a seeded fresh install. A guard option
 * makes it idempotent and keeps the steady-state cost to one autoloaded read; a later
 * legitimate absence (e.g. a reset returning to off-by-default) is therefore not forced
 * back on.
 *
 * The DCR default-on adoption is handled separately by aafm_oauth_dcr_adopt_on_by_default(),
 * which carries its own guard: this function's guard row is already set on installs that
 * shipped the earlier migration, so folding the DCR flip in here would skip exactly the
 * installs that need it.
 *
 * Hooked on plugins_loaded (priority 1) so it completes before any request-time toggle
 * read - determine_current_user, the .well-known handler on parse_request, and the REST
 * route gates all read later in the request - leaving no window on the first post-update
 * request.
 *
 * @return void
 */
function aafm_oauth_preserve_toggle_on_upgrade(): void {
	if ( '1' === get_option( 'aafm_oauth_toggle_migrated', '' ) ) {
		return;
	}

	// A stored toggle is always the string '0' or '1', so get_option() returns false
	// only when the row is genuinely absent - the signal for a pre-seed in-place upgrade.
	if ( false === get_option( 'aafm_oauth_enabled', false ) ) {
		add_option( 'aafm_oauth_enabled', '1', '', true );
	}

	update_option( 'aafm_oauth_toggle_migrated', '1', true );
}

/**
 * Bring an upgrading install up to the DCR-on-by-default policy, exactly once.
 *
 * Dynamic client registration used to default OFF, and its activation seed wrote an
 * explicit '0', so most installs that predate this release hold a stored '0'. That
 * default was the #90 footgun: ChatGPT and Claude only connect through DCR, so a site
 * with OAuth on but DCR off advertised an authorization server no supported client
 * could reach. This release makes DCR on by default, and the same stored '0' would
 * otherwise keep those installs broken.
 *
 * So, once per install: when the DCR row is absent or reads falsy, write '1'. A stored
 * '0' is treated as the old default rather than a deliberate opt-out - the old UI made
 * turning it off a footgun, not a considered choice - so it is flipped on here. This
 * runs a single time (its own guard row), so an operator who turns the toggle off again
 * afterward is respected, and a developer who wants it off in code can use the
 * aafm_oauth_dcr_enabled filter regardless.
 *
 * Hooked on plugins_loaded (priority 1) alongside aafm_oauth_preserve_toggle_on_upgrade()
 * so the row is settled before any request-time read.
 *
 * @return void
 */
function aafm_oauth_dcr_adopt_on_by_default(): void {
	if ( '1' === get_option( 'aafm_oauth_dcr_default_on_migrated', '' ) ) {
		return;
	}

	$stored = get_option( 'aafm_oauth_dcr_enabled', false );
	$off    = array( false, 0, '0', '', 'false', 'no', 'off' );
	if ( in_array( $stored, $off, true ) ) {
		update_option( 'aafm_oauth_dcr_enabled', '1', true );
	}

	update_option( 'aafm_oauth_dcr_default_on_migrated', '1', true );
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
 * This site's OAuth issuer identifier.
 *
 * Single-sourced so the `issuer` the AS metadata publishes (RFC 8414) and the
 * `iss` parameter RFC 9207 requires on every authorization response are always
 * the same value. Two independent home_url() calls would drift, and a drifted
 * `iss` is worse than an absent one: a client that validates it rejects an
 * otherwise legitimate response.
 *
 * @return string
 */
function aafm_oauth_issuer(): string {
	return home_url();
}

/**
 * Authorization-server metadata (RFC 8414).
 *
 * Describes the endpoints and capabilities of this site as an OAuth 2.1
 * authorization server: authorization code with PKCE S256, refresh tokens, and
 * public clients (no client secret).
 *
 * `registration_endpoint` is included only when OAuth is on AND DCR is on - the
 * exact pair the register route itself gates on (includes/oauth/rest.php). The route
 * 404s otherwise, so advertising the key when either is off would point clients at a
 * dead endpoint. RFC 8414 section 2 marks `registration_endpoint` specifically as
 * OPTIONAL (unlike `issuer` and `response_types_supported`, which are REQUIRED), so
 * omitting only this key is spec-correct. In production this builder is only reached
 * via aafm_oauth_maybe_serve_well_known(), which already returns early unless OAuth
 * is enabled; the OAuth check here keeps the builder honest when it is called directly
 * (DCR is on by default, so it cannot be relied on alone to imply OAuth is on).
 *
 * `authorization_response_iss_parameter_supported` is RFC 9207 section 3: an
 * authorization server that provides the iss parameter (we now do on every
 * authorization response, success and error alike) MUST advertise that support
 * in its metadata, so a validating client knows to expect and check it.
 *
 * @return array<string, mixed>
 */
function aafm_oauth_authorization_server_metadata(): array {
	$metadata = array(
		'issuer'                                         => aafm_oauth_issuer(),
		'authorization_endpoint'                         => add_query_arg( 'aafm_oauth', 'authorize', home_url( '/' ) ),
		'token_endpoint'                                 => rest_url( 'agent-abilities-for-mcp/oauth/token' ),
		'registration_endpoint'                          => rest_url( 'agent-abilities-for-mcp/oauth/register' ),
		'revocation_endpoint'                            => rest_url( 'agent-abilities-for-mcp/oauth/revoke' ),
		'response_types_supported'                       => array( 'code' ),
		'grant_types_supported'                          => array( 'authorization_code', 'refresh_token' ),
		'code_challenge_methods_supported'               => array( 'S256' ),
		'token_endpoint_auth_methods_supported'          => array( 'none' ),
		'authorization_response_iss_parameter_supported' => true,
	);

	if ( ! aafm_oauth_enabled() || ! aafm_oauth_dcr_enabled() ) {
		unset( $metadata['registration_endpoint'] );
	}

	return $metadata;
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
 * Set the WWW-Authenticate challenge on the dispatched MCP 401.
 *
 * The bundled adapter discards a permission_callback's WP_Error (it logs the error
 * and returns bare false), so WordPress core manufactures its own rest_forbidden
 * 401 with no challenge data - the header can't ride on the WP_Error. This
 * rest_post_dispatch filter therefore RE-DERIVES the condition from the request and
 * response: OAuth enabled, a 401 status (logged-out for this route - a logged-in but
 * unauthorized request is a 403 and must not get the beacon), and the MCP route.
 *
 * The MCP route is '/agent-abilities-for-mcp/mcp', mirroring create_server() in
 * includes/server.php (namespace 'agent-abilities-for-mcp' + route 'mcp'). The
 * route gate keeps the header off unrelated 401s site-wide. Defensive by design:
 * any miss returns the response untouched and the filter never throws.
 *
 * @param mixed           $response The dispatch result (WP_REST_Response on the REST path).
 * @param \WP_REST_Server $server   The REST server (unused).
 * @param mixed           $request  The originating request (WP_REST_Request on the REST path).
 * @return mixed The response, with the header set when the condition matches.
 */
function aafm_oauth_filter_rest_challenge( $response, $server, $request ) {
	unset( $server );

	if ( ! aafm_oauth_enabled() ) {
		return $response;
	}

	if ( ! $response instanceof WP_REST_Response ) {
		return $response;
	}

	if ( 401 !== (int) $response->get_status() ) {
		return $response;
	}

	// aafm_mcp_rest_route() is defined in bootstrap.php, which loads inside aafm_bootstrap() on
	// `plugins_loaded`. This filter is registered at plugin-include time, so it can fire earlier:
	// another active plugin that issues a rest_do_request() during `plugins_loaded` (before our
	// bootstrap) and gets a 401 would reach the aafm_mcp_rest_route() call below before it exists,
	// fataling inside a REST dispatch filter. Bail until the helper is loaded; the genuine MCP 401
	// challenge is added later, during normal REST dispatch, once the plugin is fully loaded.
	if ( ! function_exists( 'aafm_mcp_rest_route' ) ) {
		return $response;
	}

	$route = $request instanceof WP_REST_Request ? $request->get_route() : '';

	// The MCP route the adapter registers (single-sourced in bootstrap.php), matched
	// case-insensitively like core itself matches REST routes (class-wp-rest-server.php
	// builds its route regex with the `i` modifier) and like the sibling
	// aafm_oauth_filter_malformed_json() already matches its own route family.
	if ( 0 !== strcasecmp( aafm_mcp_rest_route(), $route ) ) {
		return $response;
	}

	$response->header( 'WWW-Authenticate', aafm_oauth_challenge_header() );

	return $response;
}

/**
 * Expose the OAuth challenge and MCP session headers to CORS (browser) clients.
 *
 * WordPress defaults Access-Control-Expose-Headers to X-WP-Total, X-WP-TotalPages,
 * and Link, so per the Fetch/CORS spec a browser MCP client reading
 * response.headers.get('WWW-Authenticate') sees null and never finds the discovery
 * pointer on the 401. The adapter's Streamable-HTTP transport likewise issues
 * Mcp-Session-Id on initialize and a client must read it back. Adding all three to
 * the exposed set lets a fetch()-based client complete the handshake; dedupe keeps
 * the header list clean when a value is already present.
 *
 * @param array<int, string> $headers Header names WordPress already exposes.
 * @return array<int, string> The exposed set plus the OAuth + MCP session headers.
 */
function aafm_oauth_filter_exposed_cors_headers( array $headers ): array {
	// Registered unconditionally so a runtime toggle-on takes effect on the same request; no-op when
	// OAuth is off, leaving the core CORS headers exactly as they were.
	if ( ! aafm_oauth_enabled() ) {
		return $headers;
	}

	foreach ( array( 'WWW-Authenticate', 'Mcp-Session-Id', 'MCP-Protocol-Version' ) as $header ) {
		$headers[] = $header;
	}

	return array_values( array_unique( $headers ) );
}

/**
 * Let CORS clients SEND the MCP session + protocol headers on follow-up requests.
 *
 * Access-Control-Allow-Headers gates which request headers a browser may include on
 * a CORS request. The adapter REQUIRES Mcp-Session-Id on every call after initialize
 * (and honors MCP-Protocol-Version), so without these the preflight rejects them and
 * post-init calls fail session validation. Additive and deduped, matching the
 * exposed-headers filter.
 *
 * @param array<int, string> $headers Header names WordPress already allows.
 * @return array<int, string> The allowed set plus the MCP session + protocol headers.
 */
function aafm_oauth_filter_allowed_cors_headers( array $headers ): array {
	// Registered unconditionally; no-op when OAuth is off (see the exposed-headers filter above).
	if ( ! aafm_oauth_enabled() ) {
		return $headers;
	}

	foreach ( array( 'Mcp-Session-Id', 'MCP-Protocol-Version' ) as $header ) {
		$headers[] = $header;
	}

	return array_values( array_unique( $headers ) );
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

	// RFC 9728 3.1: when the protected resource identifier has a path component, the metadata
	// document is ALSO discoverable at a URL formed by inserting the well-known path segment
	// immediately after the authority, before the resource's own path. aafm_endpoint_url() is the
	// exact URL this plugin advertises as `resource` in the metadata document itself
	// (aafm_oauth_protected_resource_metadata()), so the two must stay in lockstep by construction.
	$resource_path = ltrim( (string) wp_parse_url( aafm_endpoint_url(), PHP_URL_PATH ), '/' );
	if ( '' !== $resource_path && '.well-known/oauth-protected-resource/' . $resource_path === $path ) {
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
	header( 'Content-Type: application/json; charset=utf-8' );
	status_header( 200 );
	// Standalone RFC 8414 / RFC 9728 well-known metadata endpoint: emits a JSON document,
	// not HTML. $metadata is a plugin-built, constant-shaped array with no user-supplied
	// values; wp_json_encode() is the correct safe serializer for this context.
	echo wp_json_encode( $metadata );
	exit;
}
