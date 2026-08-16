<?php
/**
 * MCP adapter bootstrap + coexistence guard.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use WP\MCP\Core\McpAdapter;

/**
 * The longest client name or version this plugin will persist from an initialize handshake.
 *
 * Real values are short - "claude-code", "1.2.3" - and the field exists so an operator can tell
 * one connection from another on the Connections screen, where anything past a hundred characters
 * is unreadable anyway. 128 leaves generous room for a descriptive name while making the field
 * useless as storage.
 */
if ( ! defined( 'AAFM_MCP_CLIENT_FIELD_MAX' ) ) {
	define( 'AAFM_MCP_CLIENT_FIELD_MAX', 128 );
}

/**
 * The largest initialize params object this plugin will hand to the adapter, in bytes.
 *
 * SessionManager::create_session() stores the WHOLE params array in user meta, not just the parts
 * it understands, so a bound on clientInfo alone would leave any other key just as unbounded. A
 * real handshake is a few hundred bytes; 64 KB is far beyond anything a client legitimately sends
 * and still far below a payload that can bloat a meta row. Refused rather than trimmed, because
 * silently dropping half of a client's capabilities object would break negotiation in a way that
 * is very hard to debug from the client side.
 */
if ( ! defined( 'AAFM_MCP_INITIALIZE_PARAMS_MAX' ) ) {
	define( 'AAFM_MCP_INITIALIZE_PARAMS_MAX', 65536 );
}

/**
 * The MCP server's REST namespace. Single source for the four sites that need the route
 * literal (discovery, validator, connection, server). Splitting namespace from route keeps
 * the value byte-identical to what create_server() registers and what aafm_endpoint_url()
 * builds - the OAuth audience binding (hash_equals on the endpoint URL) is byte-sensitive.
 */
if ( ! defined( 'AAFM_MCP_NAMESPACE' ) ) {
	define( 'AAFM_MCP_NAMESPACE', 'agent-abilities-for-mcp' );
}

/**
 * The MCP server's route segment under its namespace.
 */
if ( ! defined( 'AAFM_MCP_ROUTE_SEGMENT' ) ) {
	define( 'AAFM_MCP_ROUTE_SEGMENT', 'mcp' );
}

/**
 * The MCP REST route WITHOUT a leading slash: "agent-abilities-for-mcp/mcp".
 *
 * This is the exact string passed to rest_url() (in aafm_endpoint_url()) and the
 * namespace/route create_server() registers. Keep callers that need rest_url() input
 * on this form.
 *
 * @return string
 */
function aafm_mcp_rest_namespace_route(): string {
	return AAFM_MCP_NAMESPACE . '/' . AAFM_MCP_ROUTE_SEGMENT;
}

/**
 * The MCP REST route WITH a leading slash: "/agent-abilities-for-mcp/mcp".
 *
 * The form WP_REST_Request::get_route() returns and the routing predicates compare against.
 *
 * @return string
 */
function aafm_mcp_rest_route(): string {
	return '/' . aafm_mcp_rest_namespace_route();
}

/**
 * Upper bound (exclusive) for a compatible MCP adapter version.
 *
 * The plugin is built against the adapter's 0.5.x contract (create_server() signature,
 * initialize-response shape, tools-list filter), so it gates the loaded copy to the tested
 * range [floor, next-minor) and warns the operator otherwise.
 *
 * After the eager-load fix (see adapter-loader.php), our bundled copy is the one in use
 * whenever we load before the conflicting sibling - and because we sort alphabetically first
 * as "agent-abilities-for-mcp", that is the normal case. We deliberately OVERRIDE any
 * later-loading sibling's copy of ANY version (older or newer); the trade is that a sibling
 * bundling a newer adapter is forced onto our version, which is acceptable because the
 * adapter's public API is additive and stable across the versions we support.
 *
 * Consequently this floor/upper-bound check and the "too old" / "too new" notices below now
 * only fire in the residual case: an incompatible copy is declared by a plugin that loads
 * BEFORE us (an alphabetically-earlier folder), so its copy wins the class declaration before
 * our eager load runs. Bump the bound deliberately after verifying against a new adapter line.
 */
if ( ! defined( 'AAFM_MAX_ADAPTER_VERSION' ) ) {
	define( 'AAFM_MAX_ADAPTER_VERSION', '0.6.0' );
}

/**
 * Whether the loaded adapter version is within the tested compatibility range.
 *
 * Requires the version to be at or above the floor AND strictly below the upper bound, so a
 * too-old adapter (below the floor) and a too-new one (at or above the next breaking line)
 * are both rejected.
 *
 * @param string $loaded_version Version reported by the active adapter copy.
 * @return bool
 */
function aafm_adapter_is_compatible( string $loaded_version ): bool {
	return version_compare( $loaded_version, AAFM_MIN_ADAPTER_VERSION, '>=' )
		&& version_compare( $loaded_version, AAFM_MAX_ADAPTER_VERSION, '<' );
}

/**
 * Whether the loaded adapter is newer than the tested upper bound.
 *
 * Lets aafm_init_mcp() pick the "too new" notice apart from the "too old" one.
 *
 * @param string $loaded_version Version reported by the active adapter copy.
 * @return bool
 */
function aafm_adapter_is_too_new( string $loaded_version ): bool {
	return version_compare( $loaded_version, AAFM_MAX_ADAPTER_VERSION, '>=' );
}

/**
 * The version of the adapter actually loaded - whichever copy our eager load committed PHP to, or
 * a sibling's copy that was declared before us.
 *
 * @return string|null
 */
function aafm_loaded_adapter_version(): ?string {
	if ( ! class_exists( McpAdapter::class ) ) {
		return null;
	}
	return defined( McpAdapter::class . '::VERSION' ) ? (string) McpAdapter::VERSION : '0.0.0';
}

/**
 * Initialize the MCP layer if a compatible adapter is present; otherwise show a notice.
 *
 * @return bool True when initialization proceeded.
 */
function aafm_init_mcp(): bool {
	$version = aafm_loaded_adapter_version();

	if ( null === $version ) {
		add_action( 'admin_notices', 'aafm_notice_adapter_missing' );
		return false;
	}
	if ( ! aafm_adapter_is_compatible( $version ) ) {
		if ( aafm_adapter_is_too_new( $version ) ) {
			add_action( 'admin_notices', 'aafm_notice_adapter_too_new' );
		} else {
			add_action( 'admin_notices', 'aafm_notice_adapter_outdated' );
		}
		return false;
	}

	// Only our governed server should exist.
	add_filter( 'mcp_adapter_create_default_server', '__return_false' );

	McpAdapter::instance();
	add_action( 'mcp_adapter_init', 'aafm_register_mcp_server' );

	return true;
}

/**
 * Admin notice: no adapter available.
 *
 * @return void
 */
function aafm_notice_adapter_missing(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agent Abilities for MCP could not load the MCP adapter. Please reinstall the plugin.', 'agent-abilities-for-mcp' );
	echo '</p></div>';
}

/**
 * Admin notice: another plugin loaded an adapter older than our floor.
 *
 * Names the offending plugin when it can be resolved (reflect the loaded McpAdapter class file,
 * map it to its plugin folder under WP_PLUGIN_DIR, read its header name), and reports the loaded
 * vs required versions, so the operator knows exactly which plugin to update or deactivate. Falls
 * back to the generic wording when the plugin cannot be resolved. All output is escaped.
 *
 * @return void
 */
function aafm_notice_adapter_outdated(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$loaded = aafm_loaded_adapter_version() ?? __( 'unknown', 'agent-abilities-for-mcp' );
	$plugin = aafm_resolve_adapter_owner_plugin();

	echo '<div class="notice notice-warning"><p>';
	if ( '' !== $plugin ) {
		printf(
			/* translators: 1: offending plugin name, 2: loaded adapter version, 3: minimum required adapter version. */
			esc_html__( 'Agent Abilities for MCP is disabled: the plugin %1$s is loading MCP Adapter %2$s, but Agent Abilities for MCP requires %3$s or newer. Update or deactivate %1$s to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $plugin ),
			esc_html( $loaded ),
			esc_html( AAFM_MIN_ADAPTER_VERSION )
		);
	} else {
		printf(
			/* translators: 1: loaded adapter version, 2: minimum required adapter version. */
			esc_html__( 'Agent Abilities for MCP is disabled: another active plugin is loading MCP Adapter %1$s, but %2$s or newer is required. Update or deactivate that plugin to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $loaded ),
			esc_html( AAFM_MIN_ADAPTER_VERSION )
		);
	}
	echo '</p></div>';
}

/**
 * Admin notice: another plugin loaded an adapter NEWER than our tested upper bound.
 *
 * A 0.6+ adapter may have changed the create_server() signature or response shape the plugin is
 * built against, so it is disabled rather than risking a runtime break. Names the offending plugin
 * when it can be resolved, and reports the loaded vs maximum-supported versions. All output escaped.
 *
 * @return void
 */
function aafm_notice_adapter_too_new(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$loaded = aafm_loaded_adapter_version() ?? __( 'unknown', 'agent-abilities-for-mcp' );
	$plugin = aafm_resolve_adapter_owner_plugin();

	echo '<div class="notice notice-warning"><p>';
	if ( '' !== $plugin ) {
		printf(
			/* translators: 1: offending plugin name, 2: loaded adapter version, 3: maximum supported adapter version (exclusive). */
			esc_html__( 'Agent Abilities for MCP is disabled: the plugin %1$s is loading MCP Adapter %2$s, which is newer than this plugin supports (below %3$s). Update Agent Abilities for MCP, or deactivate %1$s, to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $plugin ),
			esc_html( $loaded ),
			esc_html( AAFM_MAX_ADAPTER_VERSION )
		);
	} else {
		printf(
			/* translators: 1: loaded adapter version, 2: maximum supported adapter version (exclusive). */
			esc_html__( 'Agent Abilities for MCP is disabled: another active plugin is loading MCP Adapter %1$s, which is newer than this plugin supports (below %2$s). Update Agent Abilities for MCP, or deactivate that plugin, to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $loaded ),
			esc_html( AAFM_MAX_ADAPTER_VERSION )
		);
	}
	echo '</p></div>';
}

/**
 * Resolve the display name of the plugin whose copy of the MCP adapter won the autoload.
 *
 * Reflects the loaded WP\MCP\Core\McpAdapter class file path, finds which plugin folder under
 * WP_PLUGIN_DIR contains it, then reads that plugin's header name via get_plugins(). Returns an
 * empty string when the class is absent, the file is outside the plugins directory, or no plugin
 * header can be matched - the caller then uses the generic wording.
 *
 * @return string Plugin display name, or '' when it cannot be resolved.
 */
function aafm_resolve_adapter_owner_plugin(): string {
	if ( ! class_exists( McpAdapter::class ) ) {
		return '';
	}

	try {
		$file = ( new ReflectionClass( McpAdapter::class ) )->getFileName();
	} catch ( \ReflectionException $e ) {
		return '';
	}
	if ( ! is_string( $file ) || '' === $file ) {
		return '';
	}

	$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
	$class_file  = wp_normalize_path( $file );

	// The class must live under the plugins directory for us to name a plugin.
	if ( 0 !== strpos( $class_file, trailingslashit( $plugins_dir ) ) ) {
		return '';
	}

	// The first path segment after the plugins dir is the owning plugin's folder.
	$relative = ltrim( substr( $class_file, strlen( $plugins_dir ) ), '/' );
	$segments = explode( '/', $relative );
	$folder   = $segments[0] ?? '';
	if ( '' === $folder ) {
		return '';
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all_plugins = get_plugins();
	foreach ( $all_plugins as $plugin_file => $data ) {
		// $plugin_file is "folder/entry.php"; match on the folder segment.
		if ( 0 === strpos( $plugin_file, $folder . '/' ) && ! empty( $data['Name'] ) ) {
			return (string) $data['Name'];
		}
	}

	return '';
}

/**
 * Bound the initialize params before the MCP adapter turns them into a session row.
 *
 * SessionManager::create_session() writes the entire params array into the calling user's
 * `mcp_adapter_sessions` meta, verbatim and unvalidated, up to the per-user session cap. A
 * subscriber - sixteen read-only tools, no write ability anywhere - sent a two-million-character
 * clientInfo.name, repeated it to the cap, and grew their own usermeta row from 288 bytes to 64 MB.
 * Every call answered 200, and their next ordinary handshake took nearly four times as long,
 * because that row is unserialised on every request that primes their meta (B2-12).
 *
 * The storage belongs to the vendored adapter, but the gate belongs here: this plugin is the
 * governance layer in front of it, and "the library wrote it" is not a defence a governance layer
 * gets to use. Bounding at the request is also the only place the fix can live without patching
 * vendor code that composer will replace.
 *
 * Two different treatments, deliberately:
 *
 * - clientInfo is REWRITTEN. It is descriptive metadata, the protocol defines exactly two fields
 *   for it, and truncating an absurd name still leaves a usable label. Unknown fields are dropped
 *   rather than shortened, since nothing downstream reads them. The name goes through the same
 *   plain-text helper every other stored, operator-visible name does.
 * - Everything else is REFUSED when the whole params object is oversized. Trimming an unknown key
 *   risks silently mangling a capabilities negotiation, and a client that sends 64 KB of handshake
 *   deserves a clear error rather than a quiet, partial success.
 *
 * @param mixed $result  Existing short-circuit result, or null.
 * @param mixed $server  The REST server (unused).
 * @param mixed $request The request being dispatched.
 * @return mixed A 400 WP_Error for an oversized params object, otherwise $result unchanged.
 */
function aafm_bound_mcp_initialize_params( $result, $server = null, $request = null ) {
	unset( $server );

	if ( null !== $result || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
		return $result;
	}
	// Case-insensitive, matching core's own route matching and the sibling checks in server.php.
	if ( 0 !== strcasecmp( rtrim( (string) $request->get_route(), '/' ), rtrim( aafm_mcp_rest_route(), '/' ) ) ) {
		return $result;
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) || 'initialize' !== ( $body['method'] ?? '' ) || ! isset( $body['params'] ) || ! is_array( $body['params'] ) ) {
		return $result;
	}

	$params = $body['params'];

	if ( isset( $params['clientInfo'] ) && is_array( $params['clientInfo'] ) ) {
		$client = array();
		foreach ( array( 'name', 'version' ) as $field ) {
			if ( ! isset( $params['clientInfo'][ $field ] ) || ! is_scalar( $params['clientInfo'][ $field ] ) ) {
				continue;
			}
			$value = aafm_sanitize_plain_text( (string) $params['clientInfo'][ $field ] );
			if ( strlen( $value ) > AAFM_MCP_CLIENT_FIELD_MAX ) {
				$value = substr( $value, 0, AAFM_MCP_CLIENT_FIELD_MAX );
			}
			$client[ $field ] = $value;
		}
		$params['clientInfo'] = $client;
	}

	$encoded = (string) wp_json_encode( $params );
	if ( strlen( $encoded ) > AAFM_MCP_INITIALIZE_PARAMS_MAX ) {
		return new WP_Error(
			'aafm_initialize_params_too_large',
			__( 'The initialize parameters are too large. Send a normal handshake: a protocol version, your capabilities, and a short client name and version.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	if ( $params !== $body['params'] ) {
		$body['params'] = $params;
		// set_body() clears the parsed-JSON cache, so the adapter reads the bounded version.
		$request->set_body( (string) wp_json_encode( $body ) );
	}

	return $result;
}

add_filter( 'rest_pre_dispatch', 'aafm_bound_mcp_initialize_params', 10, 3 );
