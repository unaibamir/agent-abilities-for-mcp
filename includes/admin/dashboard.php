<?php
/**
 * Dashboard read-only data helpers: agent user candidates, ability counts,
 * activity total, and the MCP protocol version. No output, no state changes.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Users that hold at least one application password — the accounts an MCP agent
 * could authenticate as. Bounded to a sane page; exposes only id/login/roles and
 * an admin flag, never email, display name, or any password material.
 *
 * @return array<int,array{id:int,login:string,roles:array<int,string>,is_admin:bool}>
 */
function aafm_agent_user_candidates(): array {
	$users = get_users(
		array(
			'number'  => 50,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'user_login' ),
		)
	);

	$candidates = array();
	foreach ( $users as $user ) {
		$user_id = (int) $user->ID;
		$app_pws = WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( empty( $app_pws ) ) {
			continue;
		}

		$wp_user = get_userdata( $user_id );
		$roles   = ( $wp_user instanceof WP_User ) ? array_values( $wp_user->roles ) : array();

		$candidates[] = array(
			'id'       => $user_id,
			'login'    => (string) $user->user_login,
			'roles'    => array_map( 'strval', $roles ),
			'is_admin' => user_can( $user_id, 'manage_options' ),
		);
	}

	return $candidates;
}

/**
 * Count of abilities the operator has enabled.
 *
 * @return int
 */
function aafm_enabled_ability_count(): int {
	return count( aafm_get_enabled_abilities() );
}

/**
 * Total abilities in the catalog (enabled or not).
 *
 * @return int
 */
function aafm_total_ability_count(): int {
	return count( aafm_get_abilities_registry() );
}

/**
 * The MCP protocol version this plugin speaks. Single source of truth so other
 * code (help tab, connection configs) can reference it rather than re-literal it.
 *
 * @return string
 */
function aafm_mcp_protocol_version(): string {
	return '2025-06-18';
}

/**
 * Total number of rows in the activity log.
 *
 * @return int Non-negative row count.
 */
function aafm_activity_count(): int {
	global $wpdb;
	// The table name is an internal constant ($wpdb->prefix . 'aafm_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table = esc_sql( aafm_activity_log_table() );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

	return max( 0, (int) $count );
}

/**
 * Exact count of distinct agent principals seen in the activity log over the last
 * 24 hours (UTC), computed in one bounded query against the plugin's own audit table.
 *
 * This is recent-principal activity read back from the audit log, NOT a live socket or
 * connection count. created_at is stored as a UTC ('mysql', true) datetime string, so the
 * cutoff is computed with gmdate() and the query counts every distinct principal at once —
 * no page cap, so it never undercounts on a busy site.
 *
 * @return int Number of distinct principals active in the last 24 hours.
 */
function aafm_recent_agent_count(): int {
	global $wpdb;
	// The table name is an internal constant ($wpdb->prefix . 'aafm_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table  = esc_sql( aafm_activity_log_table() );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT principal_user_id) FROM {$table} WHERE created_at >= %s", $cutoff ) );

	return max( 0, (int) $count );
}

/**
 * Render the Dashboard tab: a read-only status overview made of small cards.
 *
 * Every card reflects current site state — the endpoint, versions, how many abilities are
 * on, who the agent users are and how much power they hold, recent agent activity, and the
 * audit log's size. Where the state is risky (no abilities on, or an agent user that can
 * manage the site) the card carries an inline notice. Nothing here changes state.
 *
 * @return void
 */
function aafm_render_dashboard_tab(): void {
	$endpoint      = aafm_endpoint_url();
	$enabled       = aafm_enabled_ability_count();
	$total         = aafm_total_ability_count();
	$adapter       = aafm_loaded_adapter_version();
	$candidates    = aafm_agent_user_candidates();
	$recent        = aafm_recent_agent_count();
	$log_rows      = aafm_activity_count();
	$log_cap       = defined( 'AAFM_LOG_MAX_ROWS' ) ? (int) AAFM_LOG_MAX_ROWS : 10000;
	$admin_agents  = array_values( array_filter( $candidates, static fn( array $c ): bool => ! empty( $c['is_admin'] ) ) );
	$adapter_label = ( null === $adapter ) ? __( 'not loaded', 'agent-abilities-for-mcp' ) : $adapter;

	echo '<div class="aafm-dashboard">';

	echo '<p class="aafm-dashboard-intro">' . esc_html__( 'This plugin starts from least privilege. Nothing is exposed until you turn it on, and the agent\'s reach stays capped by its user role.', 'agent-abilities-for-mcp' ) . '</p>';

	// Endpoint card.
	echo '<div class="aafm-card aafm-card-endpoint">';
	echo '<h3>' . esc_html__( 'Endpoint', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<p><code class="aafm-endpoint">%1$s</code> <button type="button" class="button aafm-copy" data-copy="%2$s">%3$s</button></p>',
		esc_html( $endpoint ),
		esc_attr( $endpoint ),
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	// Versions card.
	echo '<div class="aafm-card aafm-card-versions">';
	echo '<h3>' . esc_html__( 'Versions', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<ul class="aafm-card-list">';
	printf(
		'<li><span class="aafm-card-key">%1$s</span> <span class="aafm-card-val">%2$s</span></li>',
		esc_html__( 'Plugin', 'agent-abilities-for-mcp' ),
		esc_html( AAFM_VERSION )
	);
	printf(
		'<li><span class="aafm-card-key">%1$s</span> <span class="aafm-card-val">%2$s</span></li>',
		esc_html__( 'PHP', 'agent-abilities-for-mcp' ),
		esc_html( PHP_VERSION )
	);
	printf(
		'<li><span class="aafm-card-key">%1$s</span> <span class="aafm-card-val">%2$s</span></li>',
		esc_html__( 'MCP protocol', 'agent-abilities-for-mcp' ),
		esc_html( aafm_mcp_protocol_version() )
	);
	printf(
		'<li><span class="aafm-card-key">%1$s</span> <span class="aafm-card-val">%2$s</span></li>',
		esc_html__( 'Bundled adapter', 'agent-abilities-for-mcp' ),
		esc_html( $adapter_label )
	);
	echo '</ul>';
	echo '</div>';

	// Enabled abilities card.
	echo '<div class="aafm-card aafm-card-abilities">';
	echo '<h3>' . esc_html__( 'Enabled abilities', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<p class="aafm-card-figure">%1$s</p>',
		esc_html(
			sprintf(
				/* translators: 1: number of enabled abilities, 2: total abilities. */
				__( '%1$d of %2$d enabled', 'agent-abilities-for-mcp' ),
				$enabled,
				$total
			)
		)
	);
	if ( 0 === $enabled ) {
		aafm_render_notice(
			'warning',
			__( 'No abilities are enabled, so the agent can do nothing yet. Turn on the abilities you want it to have on the Abilities tab.', 'agent-abilities-for-mcp' )
		);
	}
	echo '</div>';

	// Agent-user least-privilege card.
	echo '<div class="aafm-card aafm-card-agent-users">';
	echo '<h3>' . esc_html__( 'Agent users', 'agent-abilities-for-mcp' ) . '</h3>';
	if ( empty( $candidates ) ) {
		aafm_render_notice(
			'info',
			__( 'No agent user is connected yet. Create a dedicated low-privilege user on the Connection tab and give it an Application Password.', 'agent-abilities-for-mcp' )
		);
	} elseif ( empty( $admin_agents ) ) {
		aafm_render_notice(
			'success',
			__( 'Your agent users are all low-privilege. None of them can manage the site.', 'agent-abilities-for-mcp' )
		);
	} else {
		$logins = implode( ', ', array_map( static fn( array $c ): string => (string) $c['login'], $admin_agents ) );
		aafm_render_notice(
			'warning',
			sprintf(
				/* translators: %s: comma-separated list of user logins that can manage the site. */
				__( 'These agent users can manage the site: %s. Give the agent its own low-privilege user instead. Move this one to a lower role, or connect a different user.', 'agent-abilities-for-mcp' ),
				$logins
			)
		);
	}
	echo '</div>';

	// Recent agents card (read from the audit log — not a live connection count).
	echo '<div class="aafm-card aafm-card-recent">';
	echo '<h3>' . esc_html__( 'Recent agents (24h)', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<p class="aafm-card-figure">%s</p>',
		esc_html( (string) $recent )
	);
	echo '<p class="description">' . esc_html__( 'Separate agent users seen in the activity log in the last 24 hours. This is recent activity from the log, not a count of live connections.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div>';

	// Audit health card.
	echo '<div class="aafm-card aafm-card-audit">';
	echo '<h3>' . esc_html__( 'Audit log', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<p class="aafm-card-figure">%1$s</p>',
		esc_html(
			sprintf(
				/* translators: 1: current row count, 2: maximum rows kept. */
				__( '%1$s of %2$s rows', 'agent-abilities-for-mcp' ),
				number_format_i18n( $log_rows ),
				number_format_i18n( $log_cap )
			)
		)
	);
	echo '<p class="description">' . esc_html__( 'Logging is on. Every call is recorded, including denied ones; the oldest rows drop once the cap is reached.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div>';

	echo '</div>';
}
