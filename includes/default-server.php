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
const AAFM_OWN_SERVER_ID              = 'aafm-server';

/**
 * Set once, the first time Automatic mode actually starts the default server on a site, so the
 * activity log records that moment and not every request.
 */
const AAFM_DEFAULT_SERVER_NOTED_OPTION = 'aafm_default_server_auto_noted';

/**
 * Run the mode filter late, so a later `__return_true` or `__return_false` from another plugin
 * cannot silently override the owner's "Always on" or "Always off".
 */
const AAFM_DEFAULT_SERVER_FILTER_PRIORITY = PHP_INT_MAX - 10;

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
 * Whether the loaded adapter has the exposure resolver the publisher scan calls.
 *
 * Another plugin's older adapter copy can win the class-declaration race, and the adapter's own
 * autoloader is not guaranteed to be registered then, so the class is checked, not assumed. The
 * filter is the seam tests use to stand in for such an adapter.
 *
 * @return bool
 */
function aafm_adapter_exposure_resolver_available(): bool {
	return (bool) apply_filters( 'aafm_adapter_exposure_resolver_available', class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) );
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
	// An older adapter copy loaded by another plugin has no exposure resolver (it arrived in 0.6.1): publish nothing rather than fatal.
	if ( ! aafm_adapter_exposure_resolver_available() ) {
		return array();
	}
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
 * Whether the Abilities API registries have finished initialising, so a lookup of registered
 * abilities cannot start them early.
 *
 * @return bool
 */
function aafm_abilities_registries_ready(): bool {
	return did_action( 'wp_abilities_api_init' ) > 0 && ! doing_action( 'wp_abilities_api_categories_init' ) && ! doing_action( 'wp_abilities_api_init' );
}

/**
 * Remove the adapter's category and three meta abilities, which it registered while the Automatic
 * answer was still "maybe". Called once the answer is no, so Automatic with no publisher leaves
 * nothing behind, the same as Always off.
 *
 * @return void
 */
function aafm_forget_default_server_abilities(): void {
	foreach ( array( 'discover-abilities', 'get-ability-info', 'execute-ability' ) as $slug ) {
		if ( wp_has_ability( 'mcp-adapter/' . $slug ) ) {
			wp_unregister_ability( 'mcp-adapter/' . $slug );
		}
	}
	if ( wp_has_ability_category( 'mcp-adapter' ) ) {
		wp_unregister_ability_category( 'mcp-adapter' );
	}
}

/**
 * The `mcp_adapter_create_default_server` callback.
 *
 * Looking up registered abilities starts the Abilities API registries if nothing has yet, and
 * starting them from inside the adapter's own registration (0.7.x asks while it registers its
 * category and meta abilities) or before it has attached its hooks (0.6.x asks first) leaves a
 * default server with no tools. So in Automatic the answer is the value so far until the registries
 * have finished, and aafm_veto_default_server_without_publisher() makes the real call afterwards.
 *
 * @param mixed $create Filter value so far.
 * @return bool
 */
function aafm_filter_default_server_enabled( $create ): bool {
	$mode = aafm_default_server_mode();
	if ( 'auto' === $mode && ! aafm_abilities_registries_ready() ) {
		return (bool) $create;
	}

	$run = aafm_default_server_decision( $mode, (bool) $create, 'aafm_default_server_has_publisher' );
	if ( 'auto' === $mode && ! $run ) {
		aafm_forget_default_server_abilities();
	}

	return $run;
}

/**
 * Automatic's real decision, taken just before the adapter creates its default server (hooked on
 * `mcp_adapter_init` ahead of the adapter's own callback). By now every ability is registered, so
 * "does another plugin publish tools here" has an answer: when none does, the adapter's one
 * create callback is taken off the hook and its leftover category and meta abilities are removed.
 *
 * This removes a callback that is not ours, an explicit exception to the rule against touching
 * other plugins' callbacks on adapter hooks. The reason: adapter 0.6.x evaluates the create-server
 * filter once, before it attaches its registration hooks, and looking up abilities at that moment
 * would start the registries without them, so the filter cannot decide in time. Only that single
 * callback is removed, found by identity; nothing else on the hook is touched.
 *
 * Only REST and WP-CLI requests fire `mcp_adapter_init`. In any other request (admin, front end,
 * cron) the registries can start with the adapter's meta abilities registered and nothing removes
 * them, so code that lists registered abilities must skip the `mcp-adapter` namespace itself, as
 * the Bridge directory does.
 *
 * @return void
 */
function aafm_veto_default_server_without_publisher(): void {
	if ( 'auto' !== aafm_default_server_mode() ) {
		return;
	}
	$create   = array( 'WP\\MCP\\Servers\\DefaultServerFactory', 'create' );
	$priority = has_action( 'mcp_adapter_init', $create );
	if ( false === $priority || aafm_default_server_has_publisher() ) {
		return;
	}

	remove_action( 'mcp_adapter_init', $create, $priority );
	aafm_forget_default_server_abilities();
}

/**
 * Whether the default server is running on this site right now, resolved the way the adapter does
 * at start-up: the stored mode, then (in Automatic) whether another plugin publishes tools there.
 *
 * @return bool
 */
function aafm_default_server_running(): bool {
	return aafm_default_server_decision( aafm_default_server_mode(), true, 'aafm_default_server_has_publisher' );
}

/**
 * The plain-text state shown beside the Settings select.
 *
 * @return string
 */
function aafm_default_server_state_label(): string {
	return aafm_default_server_running()
		? __( 'Currently running', 'agent-abilities-for-mcp' )
		: __( 'Currently not running', 'agent-abilities-for-mcp' );
}

/**
 * Record the first time Automatic mode actually starts the default server on this site.
 *
 * Hooked on mcp_adapter_init after the adapter has created its servers. Once only: the flag is a
 * certified option write and is checked against the database row, so a stale object cache cannot
 * make it log again. A flag that cannot be written means no row, never a row per request.
 *
 * @param mixed $adapter The McpAdapter instance.
 * @return void
 */
function aafm_note_automatic_default_server( $adapter = null ): void {
	if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_server' ) || 'auto' !== aafm_default_server_mode() ) {
		return;
	}
	if ( null === $adapter->get_server( AAFM_ADAPTER_DEFAULT_SERVER_ID ) ) {
		return;
	}
	$row = aafm_option_row( AAFM_DEFAULT_SERVER_NOTED_OPTION );
	if ( ! $row['ok'] || $row['found'] || ! aafm_update_option_verified( AAFM_DEFAULT_SERVER_NOTED_OPTION, '1', false ) ) {
		return;
	}

	$publishers = array_slice( aafm_default_server_publishers( wp_get_abilities() ), 0, 3 );
	aafm_log_activity(
		array(
			'ability'    => 'aafm/default-server-mode',
			'status'     => 'success',
			'event_type' => 'setting_changed',
			'detail'     => array() === $publishers
				? __( 'Default server started automatically because another plugin asked for it', 'agent-abilities-for-mcp' )
				/* translators: %s: up to three ability names, comma separated. */
				: sprintf( __( 'Default server started automatically because other plugins publish tools there, for example %s', 'agent-abilities-for-mcp' ), implode( ', ', $publishers ) ),
		)
	);
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
 * Whether a handler's server object is any server other than this plugin's governed one. The
 * guards apply to all of them, so a custom server another plugin registers is covered too.
 * No server object (a direct call) is not a known other server and passes through.
 *
 * @param mixed $server The McpServer a handler filter passes.
 * @return bool
 */
function aafm_is_other_server( $server ): bool {
	if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
		return false;
	}
	$id = (string) $server->get_server_id();

	/**
	 * Whether a server is this plugin's own governed server, which the default-server guards leave
	 * alone. True only for `aafm-server`. Internal: the test suite widens it to the throwaway servers
	 * its wire tests build around this plugin's tools. Nothing else should hook it.
	 *
	 * @param bool   $governed Whether the id is `aafm-server`.
	 * @param string $id       The server id.
	 */
	return ! (bool) apply_filters( 'aafm_server_is_governed', AAFM_OWN_SERVER_ID === $id, $id );
}

/**
 * Whether a wire tool name is one of this plugin's tools.
 *
 * Tool names are ability names with the slash turned into a dash, so `aafm-x-ping` is ambiguous on
 * its own: it is ours only when no registered foreign ability (`aafm-x/ping`) maps to it. A name
 * that starts with `aafm-` and matches no foreign ability is treated as ours (fails closed).
 *
 * @param string $tool_name Wire tool name.
 * @return bool
 */
function aafm_is_own_tool_name( string $tool_name ): bool {
	if ( ! str_starts_with( $tool_name, 'aafm-' ) ) {
		return false;
	}
	if ( function_exists( 'wp_get_abilities' ) ) {
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( aafm_is_own_ability_name( $name ) ) {
				continue;
			}
			if ( aafm_mcp_tool_name( $name ) === $tool_name ) {
				return false;
			}
			/** This filter is defined by the MCP adapter (RegisterAbilityAsMcpTool::resolve_tool_name). */
			$renamed = apply_filters( 'mcp_adapter_tool_name', aafm_mcp_tool_name( $name ), $ability ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook; applied to match its tool-name derivation.
			if ( is_string( $renamed ) && $renamed === $tool_name ) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Guard 1: drop any of our tools from a tools/list on the default server.
 *
 * @param mixed $tools  Tool objects.
 * @param mixed $server The server.
 * @return mixed
 */
function aafm_guard_default_server_tools_list( $tools, $server = null ) {
	if ( ! is_array( $tools ) || ! aafm_is_other_server( $server ) ) {
		return $tools;
	}

	return array_values(
		array_filter(
			$tools,
			static function ( $tool ): bool {
				$name = ( is_object( $tool ) && method_exists( $tool, 'getName' ) ) ? (string) $tool->getName() : '';
				return ! aafm_is_own_tool_name( $name );
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
	if ( ! aafm_is_other_server( $server ) ) {
		return $args;
	}

	$target = ( is_array( $args ) && isset( $args['ability_name'] ) && is_string( $args['ability_name'] ) ) ? $args['ability_name'] : '';
	$direct = is_string( $tool_name ) && aafm_is_own_tool_name( $tool_name );
	if ( ! $direct && ! aafm_is_own_ability_name( $target ) ) {
		return $args;
	}

	// Any authenticated caller can reach this refusal, so its row shares a per-IP cap with the other denial classes.
	if ( aafm_denial_log_within_cap( 'dsg' ) ) {
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
	}

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
	if ( ! aafm_is_other_server( $server ) || ! is_array( $result ) || ! isset( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
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
