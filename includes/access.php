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
 * The roles the owner allows to connect apps.
 *
 * @return array<int,string>|null Role slugs (Administrators always included), or null when the owner
 *                                never saved a list (today's floor). An unreadable or malformed row is
 *                                Administrators only, never wider.
 */
function aafm_oauth_connect_roles(): ?array {
	$row = aafm_policy_row( 'aafm_oauth_connect_roles' );
	if ( ! $row['ok'] ) {
		return array( 'administrator' );
	}
	if ( ! $row['found'] ) {
		return null;
	}
	if ( ! is_array( $row['value'] ) ) {
		return array( 'administrator' );
	}
	return array_values( array_unique( array_merge( array( 'administrator' ), array_map( 'strval', $row['value'] ) ) ) );
}

/**
 * Whether a user may APPROVE a connection.
 *
 * A filter on aafm_oauth_min_capability takes priority over the stored list, detected with
 * has_filter() so a filter that returns the default still wins. With no stored list the floor is
 * `edit_posts` (contributor and up) rather than `read`, so a bare subscriber cannot mint an MCP
 * token: the token acts with the approver's full account, and a subscriber-level floor is an
 * over-broad self-service foothold on any site with open registration. The one exception is a
 * provably fresh install whose Administrators-only seed has not landed yet.
 *
 * @param WP_User $user The approving user.
 * @return bool
 */
function aafm_oauth_user_may_connect( WP_User $user ): bool {
	if ( has_filter( 'aafm_oauth_min_capability' ) ) {
		/**
		 * The capability an account needs to approve an OAuth connection. Overrides the Settings
		 * roles list when any callback is attached.
		 *
		 * @param string $capability Default 'edit_posts'. Use 'manage_options' to restrict approval to admins.
		 */
		return user_can( $user, (string) apply_filters( 'aafm_oauth_min_capability', 'edit_posts' ) );
	}
	$roles = aafm_oauth_connect_roles();
	if ( null === $roles ) {
		// No stored list. Administrators only ONLY for a provably fresh install. Prior OAuth use, or a
		// probe that could not be read, keeps the floor 1.9.0 shipped with: an uncertain read must
		// never make an existing install stricter.
		if ( true !== aafm_oauth_install_is_fresh() ) {
			return user_can( $user, 'edit_posts' );
		}
		$roles = array( 'administrator' );
	}
	return ( is_multisite() && is_super_admin( $user->ID ) ) || array() !== array_intersect( (array) $user->roles, $roles );
}

/**
 * Whether a user's EXISTING connections may still be used. Narrowing the list ends the grants of
 * users it no longer covers. A site that never saved the list keeps every grant it has: before
 * 1.10.0 the floor was checked at approval only.
 *
 * @param int $user_id The approving user of the grant.
 * @return bool
 */
function aafm_oauth_user_still_may_connect( int $user_id ): bool {
	if ( null === aafm_oauth_connect_roles() ) {
		return true;
	}
	$user = aafm_exact_object( 'user', $user_id );
	return $user instanceof WP_User && aafm_oauth_user_may_connect( $user );
}

/**
 * Whether this install has never used OAuth: no aafm_oauth_schema_version row, no row in the OAuth
 * clients table and no row in the OAuth consents table. Every one of those is a fact no Settings
 * save can change, and an absent schema row alone is NOT proof: the installer runs at activation
 * even with OAuth off and withholds the stamp after any table, engine or stamp-write failure, so an
 * unstamped 1.9.0 site can hold a working grant. A client or consent row is prior use even when the
 * stamp row is missing. The access-tokens and codes tables are not probed: a token cannot exist
 * without a client. aafm_oauth_count_active_clients_view() is not reused: it counts active clients only.
 *
 * @return bool|null True only when all three reads succeeded and found nothing; false on any sign of
 *                   prior use; null when a read failed (callers treat null like false: write nothing,
 *                   keep the legacy behaviour).
 */
function aafm_oauth_install_is_fresh(): ?bool {
	global $wpdb;
	$stamp = aafm_read_option_views( 'aafm_oauth_schema_version' );
	if ( $stamp['db_error'] ) {
		return null;
	}
	if ( $stamp['db_found'] ) {
		return false;
	}
	$suppressed = $wpdb->suppress_errors();
	$fresh      = true;
	foreach ( array( 'aafm_oauth_clients', 'aafm_oauth_consents' ) as $suffix ) {
		$view = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $wpdb->prefix . $suffix ) );
		if ( ! $view['ok'] ) {
			$fresh = null;
			break;
		}
		if ( null !== $view['value'] ) {
			$fresh = false;
			break;
		}
	}
	$wpdb->suppress_errors( $suppressed );
	return $fresh;
}

/**
 * Fresh install only: Administrators may connect apps.
 *
 * "Fresh" is aafm_oauth_install_is_fresh() and nothing else, so nothing the owner does in the
 * meantime (a Settings save, enabling OAuth) can change the verdict, and an upgraded site, stamped
 * or not, is never seeded: its Editors' grants keep working. Do NOT widen the probe to "any aafm_
 * option" (a Settings save during a failed first attempt would read a fresh install as upgraded) and
 * do NOT narrow it to the schema row alone (an unstamped 1.9.0 site would be seeded and lose its
 * Editors' grants). An unstamped 1.9.0 site with no client and no consent rows cannot be told apart
 * from a new install and is seeded too: it has no grants to lose, and the owner can widen the list.
 *
 * Called from aafm_oauth_finalize_schema() immediately before the first-ever schema stamp, so
 * activation and the lazy upgrade share one path. While it keeps failing,
 * aafm_oauth_user_may_connect() applies Administrators only to a provably fresh store and the legacy
 * floor otherwise. Never overwrites a saved list.
 *
 * @return bool True when the roles list is certified in place or the install shows prior use
 *              (nothing written); false when a fresh install could not be seeded or a probe could not
 *              be read, in which case the caller must NOT stamp the schema.
 */
function aafm_seed_fresh_install_defaults(): bool {
	$roles = aafm_read_option_views( 'aafm_oauth_connect_roles' );
	if ( $roles['db_error'] ) {
		return false;
	}
	if ( $roles['db_found'] ) {
		return true; // The owner's list, or an earlier seed: never overwritten.
	}
	$fresh = aafm_oauth_install_is_fresh();
	if ( null === $fresh ) {
		return false; // Could not tell: write nothing, withhold the stamp, retry.
	}
	if ( ! $fresh ) {
		return true; // Prior OAuth use: an upgraded install keeps the edit_posts floor.
	}
	return aafm_update_option_verified( 'aafm_oauth_connect_roles', array( 'administrator' ), false );
}

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
