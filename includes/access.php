<?php
/**
 * Owner-level access switches: pause everything, and who may approve a connection.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether the owner has paused all MCP access.
 *
 * Same shape as aafm_read_only_mode_stored(): the row decides; a cache copy that disagrees is not
 * trusted for "off"; an unreadable row reads as paused (fail closed).
 *
 * @return bool
 */
function aafm_pause_all(): bool {
	$is_on = static function ( $stored ): bool {
		return is_array( $stored ) || is_object( $stored ) || (bool) $stored;
	};
	$raw   = get_option( 'aafm_pause_all', false );
	$on    = $is_on( $raw );
	if ( false === $raw ) {
		$row = aafm_policy_row( 'aafm_pause_all' );
		$on  = ! $row['ok'] || ( $row['found'] && $is_on( $row['value'] ) );
	}
	$row = aafm_policy_row_if_stale( 'aafm_pause_all' );
	if ( null !== $row ) {
		$on = $on || ! $row['ok'] || ( $row['found'] && $is_on( $row['value'] ) );
	}
	return $on;
}

/**
 * Turn pause-all on or off. Off is the absent row.
 *
 * @param bool $on Whether to pause all access.
 * @return bool True when the switch now certifies as $on.
 */
function aafm_set_pause_all( bool $on ): bool {
	return aafm_persist_operator_switch( 'aafm_pause_all', $on );
}

/**
 * Refuse every authenticated MCP request while the owner has paused all access: this plugin's route
 * and the adapter's default server (/mcp/mcp-adapter-default-server, includes/default-server.php),
 * whatever credential carries it. Logged-out callers keep the normal 401. Application Passwords
 * still reach the rest of core's REST API; this switch does not close that.
 *
 * @param mixed            $result  Short-circuit result, or null to continue.
 * @param mixed            $server  The REST server (unused).
 * @param \WP_REST_Request $request The request being dispatched.
 * @return mixed A 403 WP_Error while paused, otherwise $result unchanged.
 */
function aafm_pause_all_guard( $result, $server, $request ) {
	unset( $server );
	if ( null !== $result || ! $request instanceof WP_REST_Request || ! is_user_logged_in() ) {
		return $result;
	}
	$route = (string) $request->get_route();
	if ( ! aafm_is_mcp_route( $route ) && 1 !== preg_match( '@^/mcp/@i', $route ) ) {
		return $result;
	}
	if ( ! aafm_pause_all() ) {
		return $result;
	}
	if ( aafm_denial_log_within_cap( 'pau' ) ) {
		$user = wp_get_current_user();
		aafm_log_activity(
			array(
				'ability'           => '(transport)',
				'status'            => 'denied',
				'principal_user_id' => (int) $user->ID,
				'principal_login'   => (string) $user->user_login,
				'detail'            => aafm_compose_denied_detail( null, 'access_paused' ),
			)
		);
	}
	return new WP_Error( 'aafm_access_paused', __( 'Access to this site is paused by its owner.', 'agent-abilities-for-mcp' ), array( 'status' => 403 ) );
}
// Priority 0: ahead of the plugin's own MCP body filters at 10, so a paused site never parses an MCP body.
add_filter( 'rest_pre_dispatch', 'aafm_pause_all_guard', 0, 3 );

/**
 * Write one setting_changed row for a change the acting admin made.
 *
 * @param string $ability Row label, for example the option or card name.
 * @param string $detail  What changed.
 * @param bool   $ok      Whether the change took.
 * @return int The row id, or 0.
 */
function aafm_log_setting_change( string $ability, string $detail, bool $ok = true ): int {
	$user = wp_get_current_user();
	return (int) aafm_log_activity(
		array(
			'ability'           => $ability,
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => (string) $user->user_login,
			'status'            => $ok ? 'success' : 'error',
			'event_type'        => 'setting_changed',
			'detail'            => $detail,
		)
	);
}
