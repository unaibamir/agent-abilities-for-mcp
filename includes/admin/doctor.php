<?php
/**
 * Connection Doctor: checks that run from this site's own server, never from outside.
 *
 * Why a self-check: an outside probe would mean phoning home, and the readme promises no
 * telemetry. Everything here is a request this site makes to itself, so a site that cannot call
 * itself reports "Could not test" instead of a false failure.
 *
 * One probe request does two jobs. It is a POST to the plugin's own MCP endpoint carrying a
 * single-use random token in X-AAFM-Doctor and an Authorization header with a scheme nothing else
 * understands ("AAFM-Doctor <token>"). A rest_pre_dispatch handler answers it before any MCP code
 * runs, saying whether PHP received the Authorization header and whether the MCP route is
 * registered. A Basic or Bearer header is avoided on purpose: core's Application Password check
 * and this plugin's OAuth validator would consume it first, reject it, and write
 * failed-authentication rows.
 *
 * The probe token is a security boundary, and these rules are what keep it one:
 * - Bound to the administrator who started the run: the token is "<user id>.<32 random characters>"
 *   and is stored under a key made of that id and a fragment of the token's hash (one slot per run,
 *   so two runs never touch each other's), and the handler re-checks that user still has
 *   manage_options. Nobody runs a probe without that capability either.
 * - Single use: the stored hash is deleted the moment a token matches (and the run deletes only its
 *   own slot after the request, whatever the outcome).
 * - Short lived: the hash expires after AAFM_DOCTOR_PROBE_TTL seconds even if the request never arrives.
 * - Never stored or logged in clear: only sha256(token) is stored, compared with hash_equals(), and
 *   the token appears in no response body, no log row and no report.
 * - Never authenticates: answering it does not call wp_set_current_user() or touch a cookie, and the
 *   answer holds two booleans and nothing else.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AAFM_DOCTOR_PROBE_PREFIX = 'aafm_doctor_probe_';
const AAFM_DOCTOR_PROBE_TTL    = 30;
const AAFM_DOCTOR_HEADER_SEEN  = 'aafm_doctor_header_seen';
const AAFM_DOCTOR_SCHEME       = 'AAFM-Doctor';

/**
 * Build one result row.
 *
 * @param string $id     Stable row id.
 * @param string $label  Row title.
 * @param string $status pass|warn|fail|skip ("Could not test").
 * @param string $who    you|host|plugin, or '' when nothing needs fixing.
 * @param string $detail One or two plain sentences.
 * @param string $fix    A snippet to copy, shown never applied.
 * @param int    $http   HTTP status the row is about, 0 when none.
 * @param string $code   WP_Error code when a request failed, never its message.
 * @return array{id:string,label:string,status:string,who:string,detail:string,fix:string,http:int,code:string}
 */
function aafm_doctor_row( string $id, string $label, string $status, string $who, string $detail, string $fix = '', int $http = 0, string $code = '' ): array {
	return array(
		'id'     => $id,
		'label'  => $label,
		'status' => $status,
		'who'    => $who,
		'detail' => $detail,
		'fix'    => $fix,
		'http'   => $http,
		'code'   => $code,
	);
}

/**
 * Explain an HTTP status the probe got instead of the plugin's own answer.
 *
 * @param int  $code     HTTP status.
 * @param bool $oauth_on Whether OAuth is enabled (a login wall on the REST API then breaks connecting).
 * @return array{status:string,who:string,detail:string}
 */
function aafm_doctor_interpret_http_status( int $code, bool $oauth_on ): array {
	if ( 401 === $code ) {
		return array(
			'status' => $oauth_on ? 'fail' : 'warn',
			'who'    => 'plugin',
			'detail' => __( 'WordPress answered 401 before this plugin could reply, so something requires a login for the whole REST API. Agents cannot finish connecting while that is on.', 'agent-abilities-for-mcp' ),
		);
	}
	if ( 403 === $code ) {
		return array(
			'status' => 'fail',
			'who'    => 'host',
			'detail' => __( 'The request was refused with 403 before the plugin answered. A firewall, a security plugin or your host\'s bot protection is blocking requests to this endpoint.', 'agent-abilities-for-mcp' ),
		);
	}
	if ( 404 === $code ) {
		return array(
			'status' => 'fail',
			'who'    => 'you',
			'detail' => __( 'The endpoint was not found (404). Check that permalinks are saved and that nothing disables the REST API.', 'agent-abilities-for-mcp' ),
		);
	}
	if ( 406 === $code ) {
		return array(
			'status' => 'fail',
			'who'    => 'host',
			'detail' => __( 'The server refused the request headers with 406. This is usually a ModSecurity rule on the host.', 'agent-abilities-for-mcp' ),
		);
	}
	if ( 429 === $code ) {
		return array(
			'status' => 'warn',
			'who'    => 'host',
			'detail' => __( 'The server or a CDN is rate limiting requests to this endpoint (429).', 'agent-abilities-for-mcp' ),
		);
	}
	if ( $code >= 500 ) {
		return array(
			'status' => 'fail',
			'who'    => 'host',
			/* translators: %d: HTTP status code. */
			'detail' => sprintf( __( 'The server answered with an error (%d). Check the PHP error log on your host.', 'agent-abilities-for-mcp' ), $code ),
		);
	}
	if ( $code >= 300 && $code < 400 ) {
		return array(
			'status' => 'warn',
			'who'    => 'you',
			/* translators: %d: HTTP status code. */
			'detail' => sprintf( __( 'The endpoint redirected (%d), usually from http to https or between www and the bare domain. Use the final address in your agent.', 'agent-abilities-for-mcp' ), $code ),
		);
	}

	return array(
		'status' => 'warn',
		'who'    => 'host',
		/* translators: %d: HTTP status code. */
		'detail' => sprintf( __( 'The endpoint answered HTTP %d, which is not what the plugin sends. A cache or CDN page may be in the way.', 'agent-abilities-for-mcp' ), $code ),
	);
}

/**
 * Whether PHP received the probe's Authorization header (either server variable hosts use).
 *
 * @return bool
 */
function aafm_doctor_authorization_seen(): bool {
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only compared against a fixed prefix, never stored or output.
		if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && 0 === strpos( $_SERVER[ $key ], AAFM_DOCTOR_SCHEME . ' ' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The transient key holding the hash of one pending probe token: the administrator's id plus the
 * first 8 characters of the token's hash, so two runs by the same administrator (two tabs) each
 * keep their own slot and neither can overwrite or delete the other's.
 *
 * @param int    $user_id The administrator who started the run.
 * @param string $token   The probe token.
 * @return string
 */
function aafm_doctor_probe_key( int $user_id, string $token ): string {
	return AAFM_DOCTOR_PROBE_PREFIX . $user_id . '_' . substr( hash( 'sha256', $token ), 0, 8 );
}

/**
 * Whether the MCP route is registered on a REST server.
 *
 * @param mixed $server The REST server, or anything else to use the global one.
 * @return bool
 */
function aafm_doctor_route_registered( $server ): bool {
	if ( ! $server instanceof WP_REST_Server ) {
		$server = rest_get_server();
	}
	foreach ( array_keys( $server->get_routes() ) as $route ) {
		if ( aafm_is_mcp_route( $route ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Answer the doctor's own probe before any MCP code runs. Hooked on rest_pre_dispatch, priority 1.
 *
 * Only a POST to the MCP route carrying a live, single-use token gets an answer; everything else
 * falls through untouched, with no log row and no side effect. The token is "<user id>.<32 random
 * characters>"; the stored value is a hash of it under that user's key, compared in constant time,
 * and deleted on first match. The user must still hold manage_options. The answer says whether the
 * Authorization header arrived and whether the MCP route is registered, and nothing else.
 *
 * @param mixed $result  Short-circuit result so far.
 * @param mixed $server  REST server.
 * @param mixed $request The request.
 * @return mixed A REST response for a valid probe, otherwise $result unchanged.
 */
function aafm_doctor_handle_probe( $result, $server = null, $request = null ) {
	if ( null !== $result || ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
		return $result;
	}
	$token = (string) $request->get_header( 'x-aafm-doctor' );
	if ( 1 !== preg_match( '/^([0-9]{1,9})\.[A-Za-z0-9]{32}\z/', $token, $parts ) || ! aafm_is_mcp_route( $request->get_route() ) ) {
		return $result;
	}
	$user_id = (int) $parts[1];
	$key     = aafm_doctor_probe_key( $user_id, $token );
	$want    = get_transient( $key );
	if ( ! is_string( $want ) || '' === $want || ! hash_equals( $want, hash( 'sha256', $token ) ) ) {
		return $result;
	}
	// Spend the token before anything else can fail, so it can never answer twice.
	delete_transient( $key );
	if ( $user_id < 1 || ! user_can( $user_id, 'manage_options' ) ) {
		return $result;
	}

	return new WP_REST_Response(
		array(
			'aafm_doctor' => array(
				'authorization_seen' => aafm_doctor_authorization_seen(),
				'route_registered'   => aafm_doctor_route_registered( $server ),
			),
		),
		200
	);
}

/**
 * Whether loopback requests verify TLS: core's own loopback filter, the one Site Health applies.
 *
 * @return bool
 */
function aafm_doctor_ssl_verify(): bool {
	return (bool) apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's loopback hook.
}

/**
 * Send the probe. The target comes from aafm_endpoint_url() (core's rest_url(), filterable by any
 * active plugin), the same trust aafm_ajax_test_connection() already extends; no cookies are sent.
 * Refused outright unless the current user is an administrator, so no token is minted for anyone else.
 *
 * @return array{error:string,code:int,seen:bool|null,route:bool|null} error is a WP_Error code, '' when a response came back.
 */
function aafm_doctor_probe(): array {
	$user_id = get_current_user_id();
	if ( $user_id < 1 || ! current_user_can( 'manage_options' ) ) {
		return array(
			'error' => 'aafm_doctor_forbidden',
			'code'  => 0,
			'seen'  => null,
			'route' => null,
		);
	}

	$token = $user_id . '.' . wp_generate_password( 32, false, false );
	$key   = aafm_doctor_probe_key( $user_id, $token );
	set_transient( $key, hash( 'sha256', $token ), AAFM_DOCTOR_PROBE_TTL );

	$response = wp_remote_post(
		aafm_endpoint_url(),
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'sslverify'   => aafm_doctor_ssl_verify(),
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'X-AAFM-Doctor' => $token,
				'Authorization' => AAFM_DOCTOR_SCHEME . ' ' . $token,
			),
			'body'        => '{}',
		)
	);
	delete_transient( $key );

	if ( is_wp_error( $response ) ) {
		return array(
			// The code only, never the message: it can name an internal host.
			'error' => (string) substr( sanitize_key( (string) $response->get_error_code() ), 0, 64 ),
			'code'  => 0,
			'seen'  => null,
			'route' => null,
		);
	}

	$code   = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$answer = ( 200 === $code && is_array( $body ) && isset( $body['aafm_doctor'] ) && is_array( $body['aafm_doctor'] ) ) ? $body['aafm_doctor'] : array();

	return array(
		'error' => '',
		'code'  => $code,
		'seen'  => isset( $answer['authorization_seen'] ) ? (bool) $answer['authorization_seen'] : null,
		'route' => isset( $answer['route_registered'] ) ? (bool) $answer['route_registered'] : null,
	);
}

/**
 * Row: does the endpoint answer.
 *
 * @param array{error:string,code:int,seen:bool|null,route:bool|null} $probe    The probe result.
 * @param bool                                                        $oauth_on Whether OAuth is on.
 * @return array<string,mixed>
 */
function aafm_doctor_check_endpoint( array $probe, bool $oauth_on ): array {
	$label = __( 'The MCP endpoint answers', 'agent-abilities-for-mcp' );
	if ( '' !== $probe['error'] ) {
		return aafm_doctor_row( 'endpoint', $label, 'skip', '', __( 'Could not test: this site could not call its own endpoint. That is common behind some firewalls and hosts, and it does not mean agents cannot connect.', 'agent-abilities-for-mcp' ), '', 0, $probe['error'] );
	}
	if ( 200 === $probe['code'] && null !== $probe['seen'] ) {
		// The probe is answered before routing, so an answer alone does not prove the route exists.
		if ( false === $probe['route'] ) {
			return aafm_doctor_row( 'endpoint', $label, 'fail', 'plugin', __( 'This site answered, but this plugin\'s MCP route is not registered, so agents would get a 404. The MCP adapter may be missing or may have failed to start.', 'agent-abilities-for-mcp' ), '', 200 );
		}

		return aafm_doctor_row( 'endpoint', $label, 'pass', '', __( 'The endpoint answered the test request.', 'agent-abilities-for-mcp' ), '', 200 );
	}
	$read = aafm_doctor_interpret_http_status( $probe['code'], $oauth_on );

	return aafm_doctor_row( 'endpoint', $label, $read['status'], $read['who'], $read['detail'], '', $probe['code'] );
}

/**
 * The server-config lines that pass the Authorization header to PHP. One source for the Help tab and
 * the Doctor, so the two cannot drift. Shown, never applied.
 *
 * @return array{apache:list<string>,nginx:list<string>} Apache lines are alternatives (either one works).
 */
function aafm_authorization_header_snippets(): array {
	return array(
		'apache' => array(
			'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
		),
		'nginx'  => array(
			'fastcgi_param HTTP_AUTHORIZATION $http_authorization;',
		),
	);
}

/**
 * The snippet text for the Doctor's Authorization header row, matched to the web server.
 *
 * @return string Apache, Nginx, or both when the server is not recognised.
 */
function aafm_doctor_header_snippet(): string {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with strpos only.
	$lines    = aafm_authorization_header_snippets();
	$apache   = implode( "\n", array_merge( array( '# Apache (.htaccess), either of these:' ), $lines['apache'] ) );
	$nginx    = implode( "\n", array_merge( array( '# Nginx / FastCGI, inside the location block that passes PHP on:' ), $lines['nginx'] ) );
	if ( false !== strpos( $software, 'nginx' ) ) {
		return $nginx;
	}
	if ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) ) {
		return $apache;
	}

	return $apache . "\n\n" . $nginx;
}

/**
 * Row: does PHP receive the Authorization header. Application Passwords and OAuth both need it.
 *
 * @param array{error:string,code:int,seen:bool|null,route:bool|null} $probe The probe result.
 * @return array<string,mixed>
 */
function aafm_doctor_check_auth_header( array $probe ): array {
	$label = __( 'Authorization header test request', 'agent-abilities-for-mcp' );
	if ( null === $probe['seen'] ) {
		return aafm_doctor_row( 'auth_header', $label, 'skip', '', __( 'Could not test: the test request did not reach this plugin. See the endpoint check above.', 'agent-abilities-for-mcp' ) );
	}
	if ( $probe['seen'] ) {
		return aafm_doctor_row( 'auth_header', $label, 'pass', '', __( 'PHP received the test Authorization header.', 'agent-abilities-for-mcp' ) );
	}

	return aafm_doctor_row(
		'auth_header',
		$label,
		'fail',
		'host',
		__( 'The server removed the Authorization header before PHP saw it, so Application Passwords and OAuth tokens cannot work. Your host can pass it through; the snippet below is the usual fix.', 'agent-abilities-for-mcp' ),
		aafm_doctor_header_snippet()
	);
}

/**
 * Plugin, theme or core name that owns a source file, or '' when it cannot be resolved.
 *
 * @param string $file Absolute path.
 * @return string
 */
function aafm_doctor_file_owner( string $file ): string {
	$file = wp_normalize_path( $file );
	foreach ( array( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ) as $dir ) {
		$dir = trailingslashit( wp_normalize_path( $dir ) );
		if ( 0 !== strpos( $file, $dir ) ) {
			continue;
		}
		$folder = explode( '/', substr( $file, strlen( $dir ) ) )[0];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $basename => $data ) {
			if ( 0 === strpos( (string) $basename, $folder . '/' ) && ! empty( $data['Name'] ) ) {
				return wp_strip_all_tags( (string) $data['Name'] );
			}
		}
		return $folder;
	}
	if ( 0 === strpos( $file, trailingslashit( wp_normalize_path( get_theme_root() ) ) ) ) {
		return __( 'your theme', 'agent-abilities-for-mcp' );
	}
	if ( 0 === strpos( $file, trailingslashit( wp_normalize_path( ABSPATH . 'wp-includes' ) ) ) ) {
		return __( 'WordPress core', 'agent-abilities-for-mcp' );
	}

	return '';
}

/**
 * Who registered a filter callback, in words.
 *
 * @param mixed $callback A callable as stored in $wp_filter.
 * @return string '' when unknown.
 */
function aafm_doctor_callback_owner( $callback ): string {
	if ( '__return_false' === $callback ) {
		return __( 'a filter that returns false (check your security plugins and your theme\'s functions.php)', 'agent-abilities-for-mcp' );
	}
	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && false === strpos( $callback, '::' ) ) ) {
			$reflection = new ReflectionFunction( $callback );
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
		} elseif ( is_string( $callback ) ) {
			$reflection = new ReflectionMethod( $callback );
		} else {
			return '';
		}
		$file = $reflection->getFileName();
	} catch ( \ReflectionException $e ) {
		return '';
	}

	return is_string( $file ) ? aafm_doctor_file_owner( $file ) : '';
}

/**
 * Who has callbacks on a hook.
 *
 * @param string $hook Hook name.
 * @return list<string>
 */
function aafm_doctor_filter_owners( string $hook ): array {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
		return array();
	}
	$owners = array();
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$owners[] = aafm_doctor_callback_owner( $callback['function'] );
		}
	}

	return array_values( array_unique( array_filter( $owners ) ) );
}

/**
 * Why Application Passwords are unsupported on a site core considers neither HTTPS nor local.
 *
 * A home URL that already says https means a proxy or load balancer ends TLS and WordPress is not
 * told, so "plain HTTP" would send the owner to fix the wrong thing.
 *
 * @param string $home_scheme The scheme of the home URL.
 * @return string
 */
function aafm_doctor_unsupported_detail( string $home_scheme ): string {
	if ( 'https' === $home_scheme ) {
		return __( 'Your site address is HTTPS, but WordPress does not see this request as HTTPS, so Application Passwords stay off. Behind a proxy or load balancer, make it forward the X-Forwarded-Proto header and have WordPress trust it.', 'agent-abilities-for-mcp' );
	}

	return __( 'Application Passwords need HTTPS, or a local environment type. This site is on plain HTTP.', 'agent-abilities-for-mcp' );
}

/**
 * Row: are Application Passwords available.
 *
 * @return array<string,mixed>
 */
function aafm_doctor_check_app_passwords(): array {
	$label = __( 'Application Passwords', 'agent-abilities-for-mcp' );
	if ( wp_is_application_passwords_available() ) {
		return aafm_doctor_row( 'app_passwords', $label, 'pass', '', __( 'Application Passwords are available.', 'agent-abilities-for-mcp' ) );
	}
	if ( function_exists( 'wp_is_application_passwords_supported' ) && ! wp_is_application_passwords_supported() ) {
		return aafm_doctor_row( 'app_passwords', $label, 'fail', 'you', aafm_doctor_unsupported_detail( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME ) ) );
	}
	$owners = aafm_doctor_filter_owners( 'wp_is_application_passwords_available' );
	if ( array() === $owners ) {
		return aafm_doctor_row( 'app_passwords', $label, 'fail', 'plugin', __( 'Something has switched Application Passwords off with a filter or a constant. A security plugin is the usual cause.', 'agent-abilities-for-mcp' ) );
	}

	return aafm_doctor_row(
		'app_passwords',
		$label,
		'fail',
		'plugin',
		/* translators: %s: comma-separated list of what added the filter. */
		sprintf( __( 'Application Passwords are switched off by a filter added by: %s.', 'agent-abilities-for-mcp' ), implode( ', ', $owners ) )
	);
}

/**
 * Row: is the site on HTTPS. ChatGPT and Claude.ai refuse plain-HTTP connectors.
 *
 * @return array<string,mixed>
 */
function aafm_doctor_check_https(): array {
	$label = __( 'HTTPS', 'agent-abilities-for-mcp' );
	if ( 'https' === wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME ) ) {
		return aafm_doctor_row( 'https', $label, 'pass', '', __( 'The site address uses HTTPS.', 'agent-abilities-for-mcp' ) );
	}
	$detail = aafm_site_is_local()
		? __( 'The site address uses plain HTTP. That is fine for local testing, but hosted apps such as ChatGPT and Claude.ai only connect to HTTPS sites.', 'agent-abilities-for-mcp' )
		: __( 'The site address uses plain HTTP. Hosted apps such as ChatGPT and Claude.ai refuse to connect to it. Switch the site to HTTPS.', 'agent-abilities-for-mcp' );

	return aafm_doctor_row( 'https', $label, 'warn', 'you', $detail );
}

/**
 * Row: permalinks. Plain permalinks make the endpoint address awkward and break some clients.
 *
 * @return array<string,mixed>
 */
function aafm_doctor_check_permalinks(): array {
	$label = __( 'Permalinks', 'agent-abilities-for-mcp' );
	if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
		return aafm_doctor_row( 'permalinks', $label, 'warn', 'you', __( 'Permalinks are set to Plain. The endpoint address then carries ?rest_route= and some clients and discovery documents fail. Pick any other structure under Settings, Permalinks.', 'agent-abilities-for-mcp' ) );
	}

	return aafm_doctor_row( 'permalinks', $label, 'pass', '', __( 'Pretty permalinks are on.', 'agent-abilities-for-mcp' ) );
}

/**
 * Plugins known to restrict the REST API or Application Passwords, by main file. Names only: being
 * active means "can restrict", never "does".
 *
 * @return array<string,string> Plugin basename => display name.
 */
function aafm_doctor_known_blockers(): array {
	$known = array(
		'wordfence/wordfence.php'                     => 'Wordfence',
		'better-wp-security/better-wp-security.php'   => 'Solid Security (iThemes Security)',
		'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
		'disable-json-api/disable-json-api.php'       => 'Disable REST API',
		'disable-wp-rest-api/disable-wp-rest-api.php' => 'Disable WP REST API',
		'wp-simple-firewall/icwp-wpsf.php'            => 'Shield Security',
		'ninjafirewall/ninjafirewall.php'             => 'NinjaFirewall',
	);

	return (array) apply_filters( 'aafm_doctor_known_blockers', $known );
}

/**
 * Row: security plugins that can get in the way.
 *
 * @return array<string,mixed>
 */
function aafm_doctor_check_security_plugins(): array {
	$label = __( 'Security plugins', 'agent-abilities-for-mcp' );
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$active = array();
	foreach ( aafm_doctor_known_blockers() as $basename => $name ) {
		$basename = (string) $basename;
		if ( is_plugin_active( $basename ) || ( is_multisite() && is_plugin_active_for_network( $basename ) ) ) {
			$active[] = (string) $name;
		}
	}
	if ( array() === $active ) {
		return aafm_doctor_row( 'security_plugins', $label, 'pass', '', __( 'None of the security plugins this check knows about is active.', 'agent-abilities-for-mcp' ) );
	}

	return aafm_doctor_row(
		'security_plugins',
		$label,
		'warn',
		'plugin',
		/* translators: %s: comma-separated plugin names. */
		sprintf( __( '%s can restrict the REST API or Application Passwords. If a connection fails, check its REST API and login settings first.', 'agent-abilities-for-mcp' ), implode( ', ', $active ) )
	);
}

/**
 * Row: are the two OAuth discovery documents reachable. Only meaningful, and only asked, when OAuth is on.
 *
 * @return array<string,mixed>
 */
function aafm_doctor_check_oauth_discovery(): array {
	$label   = __( 'OAuth discovery', 'agent-abilities-for-mcp' );
	$bad     = array();
	$skipped = false;
	foreach ( array( '.well-known/oauth-protected-resource', '.well-known/oauth-authorization-server' ) as $path ) {
		$response = wp_remote_get(
			home_url( '/' . $path ),
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => aafm_doctor_ssl_verify(),
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$skipped = true;
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			$bad[ $path ] = $code;
		}
	}
	if ( array() !== $bad ) {
		$fix = implode(
			"\n",
			array(
				'# Nginx: put this above the dotfile deny rule',
				'location ^~ /.well-known/ {',
				'    try_files $uri $uri/ /index.php?$args;',
				'}',
			)
		);

		return aafm_doctor_row(
			'oauth_discovery',
			$label,
			'fail',
			'host',
			__( 'The OAuth discovery documents under /.well-known/ did not come back from WordPress. Some servers deny paths that start with a dot before PHP runs. ChatGPT and Claude.ai cannot connect until they are reachable.', 'agent-abilities-for-mcp' ),
			$fix,
			(int) reset( $bad )
		);
	}
	if ( $skipped ) {
		return aafm_doctor_row( 'oauth_discovery', $label, 'skip', '', __( 'Could not test: this site could not request its own discovery documents.', 'agent-abilities-for-mcp' ) );
	}

	return aafm_doctor_row( 'oauth_discovery', $label, 'pass', '', __( 'Both OAuth discovery documents are reachable.', 'agent-abilities-for-mcp' ) );
}

/**
 * Remember what the last probe learned about the Authorization header, so the Connection tab's
 * diagnostics rail can show it on the next page load. A run that could not test forgets the old answer.
 *
 * @param bool|null $seen Whether PHP received the header, null when the probe could not tell.
 * @return void
 */
function aafm_doctor_remember_header_result( ?bool $seen ): void {
	if ( null === $seen ) {
		delete_transient( AAFM_DOCTOR_HEADER_SEEN );
		return;
	}
	set_transient( AAFM_DOCTOR_HEADER_SEEN, $seen ? '1' : '0', DAY_IN_SECONDS );
}

/**
 * What the last probe learned about the Authorization header.
 *
 * @return bool|null true or false from the last run, null when never tested or not testable.
 */
function aafm_doctor_last_header_result(): ?bool {
	$stored = get_transient( AAFM_DOCTOR_HEADER_SEEN );
	if ( '1' === $stored ) {
		return true;
	}

	return '0' === $stored ? false : null;
}

/**
 * Run every check.
 *
 * @return list<array<string,mixed>> Rows, endpoint first.
 */
function aafm_doctor_run_checks(): array {
	$oauth_on = aafm_oauth_enabled();
	$probe    = aafm_doctor_probe();
	aafm_doctor_remember_header_result( $probe['seen'] );
	$rows = array(
		aafm_doctor_check_endpoint( $probe, $oauth_on ),
		aafm_doctor_check_auth_header( $probe ),
		aafm_doctor_check_app_passwords(),
		aafm_doctor_check_https(),
		aafm_doctor_check_permalinks(),
		aafm_doctor_check_security_plugins(),
	);
	if ( $oauth_on ) {
		$rows[] = aafm_doctor_check_oauth_discovery();
	}

	return $rows;
}

/**
 * The plain-text report an owner can paste into a support thread.
 *
 * Holds versions, the site host, active plugin names, and each check's result and HTTP code. It
 * never holds a key, password, cookie, user name, URL or request error text.
 *
 * @param list<array<string,mixed>> $rows Rows from aafm_doctor_run_checks().
 * @return string
 */
function aafm_doctor_build_report( array $rows ): string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$names = array();
	foreach ( get_plugins() as $basename => $data ) {
		$basename = (string) $basename;
		if ( is_plugin_active( $basename ) || ( is_multisite() && is_plugin_active_for_network( $basename ) ) ) {
			$names[] = wp_strip_all_tags( (string) $data['Name'] );
		}
	}
	sort( $names );

	$host     = wp_parse_url( home_url(), PHP_URL_HOST );
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) preg_replace( '/[^A-Za-z0-9._-].*$/', '', (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to the product name by the regex.
	$scheme   = wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME );
	$adapter  = aafm_loaded_adapter_version();

	$lines = array(
		'Agent Abilities for MCP connection report',
		'Plugin: ' . AAFM_VERSION . ' | WordPress: ' . get_bloginfo( 'version' ) . ' | PHP: ' . PHP_VERSION . ' | MCP adapter: ' . ( null === $adapter ? 'not loaded' : $adapter ),
		'Site host: ' . ( is_string( $host ) ? $host : 'unknown' ) . ' | Environment: ' . wp_get_environment_type() . ' | Server: ' . ( '' !== $software ? $software : 'unknown' ),
		'Permalinks: ' . ( '' === (string) get_option( 'permalink_structure', '' ) ? 'plain' : 'pretty' ) . ' | HTTPS: ' . ( 'https' === $scheme ? 'yes' : 'no' ) . ' | OAuth: ' . ( aafm_oauth_enabled() ? 'on' : 'off' ),
		'Active plugins (' . count( $names ) . '): ' . implode( ', ', $names ),
		'',
		'Checks:',
	);
	foreach ( $rows as $row ) {
		$line = '- ' . $row['id'] . ': ' . $row['status'];
		if ( '' !== $row['who'] ) {
			$line .= ' [' . $row['who'] . ']';
		}
		if ( (int) $row['http'] > 0 ) {
			$line .= ' HTTP ' . (int) $row['http'];
		}
		if ( '' !== $row['code'] ) {
			$line .= ' (' . $row['code'] . ')';
		}
		$lines[] = $line;
	}

	return implode( "\n", $lines ) . "\n";
}

/**
 * AJAX: run the checks and return the rows and the support report.
 *
 * @return void
 */
function aafm_ajax_run_doctor(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$rows = aafm_doctor_run_checks();
	wp_send_json_success(
		array(
			'rows'   => $rows,
			'report' => aafm_doctor_build_report( $rows ),
			// The diagnostics rail's Authorization header row, as it now reads, so the page can update it.
			'rail'   => aafm_diagnostic_auth_header_check(),
		)
	);
}

/**
 * Render the "Check my connection" card on the Connection tab. Rows are drawn by admin.js from
 * the AJAX response; only warnings, failures and "could not test" rows show by default.
 *
 * @return void
 */
function aafm_render_doctor_card(): void {
	echo '<section class="aafm-card aafm-doctor" id="aafm-doctor">';
	echo '<div class="aafm-card-head"><span class="icon">';
	echo wp_kses( aafm_icon( 'check' ), aafm_svg_allowed_html() );
	echo '</span><h2>' . esc_html__( 'Check my connection', 'agent-abilities-for-mcp' ) . '</h2></div>';
	echo '<div class="aafm-card-pad">';
	echo '<p class="description">' . esc_html__( 'These checks run from this site\'s own server. Nothing is sent to anyone else. They look for the usual reasons an agent cannot connect and say who can fix each one.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<div class="aafm-doctor-actions">';
	echo '<button type="button" class="aafm-btn aafm-btn-primary" id="aafm-doctor-run">' . esc_html__( 'Check my connection', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-btn aafm-btn-secondary aafm-copy" id="aafm-doctor-copy" data-copy="" hidden><span class="aafm-copy-label">' . esc_html__( 'Copy report for support', 'agent-abilities-for-mcp' ) . '</span></button>';
	echo '<label class="aafm-doctor-showall"><input type="checkbox" id="aafm-doctor-show-all"> ' . esc_html__( 'Show passing checks', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<span class="aafm-doctor-status" aria-live="polite"></span>';
	echo '</div>';
	echo '<p class="aafm-doctor-allgood" hidden>' . esc_html__( 'Everything this check covers looks fine. Turn on "Show passing checks" to see each result.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<div class="aafm-diag" id="aafm-doctor-results"></div>';
	echo '</div>';
	echo '</section>';
}
