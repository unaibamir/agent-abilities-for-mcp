<?php
/**
 * The MCP Adapter's default server: whether it exists, and the guard that keeps this plugin's
 * tools off it in every mode.
 *
 * The adapter can register one built-in server (id `mcp-adapter-default-server`). Its tools list
 * is three meta tools (discover, get-info, execute) that find and run any ability whose effective
 * `meta.mcp.public` is true, so it serves other plugins' tools and would serve ours if they were
 * public. Two layers keep ours off it: our abilities are registered `meta.mcp.public = false`
 * (includes/register.php), and the three filters below refuse any aafm/* or aafm-bridge/* tool on
 * that server whatever an ability's metadata says or a future adapter version does.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AAFM_DEFAULT_SERVER_MODE_OPTION = 'aafm_default_server_mode';
const AAFM_ADAPTER_DEFAULT_SERVER_ID  = 'mcp-adapter-default-server';

/**
 * The accepted modes, in the order the Settings select shows them.
 *
 * @return list<string>
 */
function aafm_default_server_modes(): array {
	return array( 'auto', 'on', 'off' );
}

/**
 * The stored mode. An absent row or any other value reads as the default, auto.
 *
 * A policy read like the Safety switches: the row decides when the cache copy core answered from
 * disagrees with it, and when the default came back the row is asked, since a failed read also
 * answers with the default. An unreadable row reads as off, which publishes nothing.
 *
 * @return string One of aafm_default_server_modes().
 */
function aafm_default_server_mode(): string {
	$normalise = static function ( $stored ): string {
		return ( is_string( $stored ) && in_array( $stored, aafm_default_server_modes(), true ) ) ? $stored : 'auto';
	};

	$mode = $normalise( get_option( AAFM_DEFAULT_SERVER_MODE_OPTION, 'auto' ) );
	if ( 'auto' === $mode ) {
		// Auto may be a failed read's default: the row decides, and an unreadable row means off.
		$row  = aafm_policy_row( AAFM_DEFAULT_SERVER_MODE_OPTION );
		$mode = ! $row['ok'] ? 'off' : ( $row['found'] ? $normalise( $row['value'] ) : 'auto' );
	}

	// A cache copy that disagrees with the row: the row decides; an unreadable row means off.
	$row = aafm_policy_row_if_stale( AAFM_DEFAULT_SERVER_MODE_OPTION );
	if ( null !== $row ) {
		$mode = ! $row['ok'] ? 'off' : ( $row['found'] ? $normalise( $row['value'] ) : 'auto' );
	}

	return $mode;
}

/**
 * Whether an ability name belongs to this plugin: a native `aafm/` tool or an `aafm-bridge/` copy.
 *
 * @param string $name Ability name.
 * @return bool
 */
function aafm_is_own_ability_name( string $name ): bool {
	return str_starts_with( $name, 'aafm/' ) || str_starts_with( $name, AAFM_BRIDGE_NAMESPACE . '/' );
}

/**
 * Names of the abilities that publish themselves to the default server and are not ours,
 * WordPress core's or the adapter's own.
 *
 * Exposure is decided by the adapter's own resolver, so an explicit `meta.mcp.public` wins, an
 * absent one inherits `meta.public`, and a malformed `meta.mcp` fails closed.
 *
 * @param iterable $abilities Registered abilities.
 * @phpstan-param iterable<\WP_Ability> $abilities
 * @return list<string> Ability names, in the order given.
 */
function aafm_default_server_publishers( iterable $abilities ): array {
	$names = array();
	foreach ( $abilities as $ability ) {
		$name = $ability->get_name();
		if ( aafm_is_own_ability_name( $name ) || str_starts_with( $name, 'core/' ) || str_starts_with( $name, 'mcp-adapter/' ) ) {
			continue;
		}
		if ( \WP\MCP\Abilities\McpAbilityExposure::is_meta_public( $ability->get_meta() ) ) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * Whether another plugin publishes tools to the default server right now: a public foreign
 * ability, or any callback on the adapter's default-server config filter.
 *
 * @return bool
 */
function aafm_default_server_has_publisher(): bool {
	if ( false !== has_filter( 'mcp_adapter_default_server_config' ) ) {
		return true;
	}

	return array() !== aafm_default_server_publishers( wp_get_abilities() );
}

/**
 * The create-or-not decision, with the publisher lookup deferred until it can matter.
 *
 * Off: never. On: always, even over an earlier plugin's false (the owner asked for it). Auto: only
 * when nobody earlier vetoed it and another plugin publishes tools there.
 *
 * @param string   $mode          One of aafm_default_server_modes().
 * @param bool     $create        The value the filter chain holds so far.
 * @param callable $has_publisher Returns bool; called only in auto mode with $create true.
 * @return bool
 */
function aafm_default_server_decision( string $mode, bool $create, callable $has_publisher ): bool {
	if ( 'off' === $mode ) {
		return false;
	}
	if ( 'on' === $mode ) {
		return true;
	}

	return $create && (bool) $has_publisher();
}

/**
 * The `mcp_adapter_create_default_server` callback.
 *
 * @param mixed $create Filter value so far.
 * @return bool
 */
function aafm_filter_default_server_enabled( $create ): bool {
	return aafm_default_server_decision( aafm_default_server_mode(), (bool) $create, 'aafm_default_server_has_publisher' );
}

/**
 * Whether a handler's server object is the adapter's default server.
 *
 * @param mixed $server The McpServer a handler filter passes.
 * @return bool
 */
function aafm_is_adapter_default_server( $server ): bool {
	return is_object( $server ) && method_exists( $server, 'get_server_id' ) && AAFM_ADAPTER_DEFAULT_SERVER_ID === $server->get_server_id();
}

/**
 * Guard 1: drop any of our tools from a tools/list on the default server.
 *
 * @param mixed $tools  Tool objects.
 * @param mixed $server The server.
 * @return mixed
 */
function aafm_guard_default_server_tools_list( $tools, $server = null ) {
	if ( ! is_array( $tools ) || ! aafm_is_adapter_default_server( $server ) ) {
		return $tools;
	}

	return array_values(
		array_filter(
			$tools,
			static function ( $tool ): bool {
				$name = ( is_object( $tool ) && method_exists( $tool, 'getName' ) ) ? (string) $tool->getName() : '';
				return ! str_starts_with( $name, 'aafm-' );
			}
		)
	);
}

/**
 * Guard 2: refuse a call on the default server that names one of our abilities, and log the
 * refusal as a denied call.
 *
 * @param mixed $args      Tool arguments.
 * @param mixed $tool_name Wire tool name.
 * @param mixed $mcp_tool  The tool (unused).
 * @param mixed $server    The server.
 * @return mixed The arguments, or a WP_Error that makes the adapter return a tool error.
 */
function aafm_guard_default_server_tool_call( $args, $tool_name = '', $mcp_tool = null, $server = null ) {
	unset( $mcp_tool );
	if ( ! aafm_is_adapter_default_server( $server ) ) {
		return $args;
	}

	$target = ( is_array( $args ) && isset( $args['ability_name'] ) && is_string( $args['ability_name'] ) ) ? $args['ability_name'] : '';
	$direct = is_string( $tool_name ) && str_starts_with( $tool_name, 'aafm-' );
	if ( ! $direct && ! aafm_is_own_ability_name( $target ) ) {
		return $args;
	}

	$user = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => '' !== $target ? $target : (string) $tool_name,
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'denied',
			'detail'            => 'refused on the adapter default server',
		)
	);

	return new WP_Error(
		'aafm_not_on_default_server',
		__( 'This tool is only available through the Agent Abilities for MCP endpoint.', 'agent-abilities-for-mcp' )
	);
}

/**
 * Guard 3: strip our abilities from what the default server's discover tool returns.
 *
 * @param mixed $result    Tool result.
 * @param mixed $args      Tool arguments (unused).
 * @param mixed $tool_name Wire tool name (unused).
 * @param mixed $mcp_tool  The tool (unused).
 * @param mixed $server    The server.
 * @return mixed
 */
function aafm_guard_default_server_call_result( $result, $args = array(), $tool_name = '', $mcp_tool = null, $server = null ) {
	unset( $args, $tool_name, $mcp_tool );
	if ( ! aafm_is_adapter_default_server( $server ) || ! is_array( $result ) || ! isset( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
		return $result;
	}

	$result['abilities'] = array_values(
		array_filter(
			$result['abilities'],
			static function ( $entry ): bool {
				return ! ( is_array( $entry ) && isset( $entry['name'] ) && is_string( $entry['name'] ) && aafm_is_own_ability_name( $entry['name'] ) );
			}
		)
	);

	return $result;
}

/**
 * Attach the three guards, ahead of this plugin's other adapter filters (priority 1 against 5).
 *
 * @return void
 */
function aafm_register_default_server_guards(): void {
	add_filter( 'mcp_adapter_tools_list', 'aafm_guard_default_server_tools_list', 1, 2 );
	add_filter( 'mcp_adapter_pre_tool_call', 'aafm_guard_default_server_tool_call', 1, 4 );
	add_filter( 'mcp_adapter_tool_call_result', 'aafm_guard_default_server_call_result', 1, 5 );
}

/**
 * Record a change of the default-server mode in the activity log.
 *
 * @param string $before Mode before the save.
 * @param string $after  Mode after the save.
 * @return void
 */
function aafm_log_default_server_mode_change( string $before, string $after ): void {
	if ( $before === $after ) {
		return;
	}
	$user = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => 'aafm/default-server-mode',
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'success',
			'event_type'        => 'setting_changed',
			/* translators: %s: the new mode, one of auto, on or off. */
			'detail'            => sprintf( __( 'Default server set to %s', 'agent-abilities-for-mcp' ), $after ),
		)
	);
}
