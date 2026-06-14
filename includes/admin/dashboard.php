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
 * The three setup steps, each derived from real, observable site state — never a
 * faked "connected" signal. Step done-ness comes straight from the data helpers:
 *
 *   [0] an agent user exists  — aafm_agent_user_candidates() is non-empty
 *   [1] abilities are enabled — aafm_enabled_ability_count() > 0
 *   [2] a call has been made  — aafm_activity_count() > 0 (logged for real)
 *
 * The zero-based index is the contract callers rely on: $steps[1] is always the
 * abilities step.
 *
 * @return array<int,array{title:string,desc:string,done:bool,href:string}>
 */
function aafm_setup_steps(): array {
	$tab_url = static function ( string $tab ): string {
		return add_query_arg(
			array(
				'page' => 'agent-abilities-for-mcp',
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	};

	return array(
		array(
			'title' => __( 'Create a dedicated agent user', 'agent-abilities-for-mcp' ),
			'desc'  => __( 'Give the agent its own low-privilege account with an Application Password, so its reach stays capped by that role.', 'agent-abilities-for-mcp' ),
			'done'  => ! empty( aafm_agent_user_candidates() ),
			'href'  => $tab_url( 'connection' ),
		),
		array(
			'title' => __( 'Enable the abilities you want', 'agent-abilities-for-mcp' ),
			'desc'  => __( 'Nothing is exposed until you turn it on. Pick the abilities the agent should have on the Abilities tab.', 'agent-abilities-for-mcp' ),
			'done'  => aafm_enabled_ability_count() > 0,
			'href'  => $tab_url( 'abilities' ),
		),
		array(
			'title' => __( 'Connect your client and make a call', 'agent-abilities-for-mcp' ),
			'desc'  => __( 'Point your MCP client at the endpoint and run one request. It shows up here once the activity log records it.', 'agent-abilities-for-mcp' ),
			'done'  => aafm_activity_count() > 0,
			'href'  => $tab_url( 'connection' ),
		),
	);
}

/**
 * Render the Dashboard tab: a guided setup checklist, a four-card stat grid, and a
 * two-card row (endpoint + versions).
 *
 * The checklist reflects real, observable state from aafm_setup_steps(); when all three
 * steps are done it collapses into a single "all set" success notice. The stat grid and
 * cards reuse the same counts the page already computes — enabled abilities, recent agent
 * activity (read from the audit log, not live connections), audit-log size, and agent
 * users, with an inline warning when an agent user can manage the site. Nothing here
 * changes state. The page shell (heading and lede) is rendered by page.php, not here.
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

	$steps      = aafm_setup_steps();
	$done_count = count( array_filter( $steps, static fn( array $s ): bool => ! empty( $s['done'] ) ) );
	$step_total = count( $steps );

	echo '<div class="aafm-dashboard">';

	// Setup checklist — or, once every step is done, a single "all set" notice.
	if ( $done_count === $step_total ) {
		aafm_render_notice(
			'success',
			__( 'All set — your agent is connected and working.', 'agent-abilities-for-mcp' )
		);
	} else {
		echo '<section class="aafm-card aafm-setup">';
		echo '<div class="aafm-setup-top">';
		echo '<h2>' . esc_html__( 'Finish setting up', 'agent-abilities-for-mcp' ) . '</h2>';
		printf(
			'<span class="aafm-setup-count">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: number of completed setup steps, 2: total setup steps. */
					__( '%1$d of %2$d done', 'agent-abilities-for-mcp' ),
					$done_count,
					$step_total
				)
			)
		);
		echo '</div>';

		foreach ( $steps as $step ) {
			$is_done    = ! empty( $step['done'] );
			$state_cls  = $is_done ? 'aafm-step-done' : 'aafm-step-todo';
			$state_icon = $is_done ? 'dashicons-yes-alt' : 'dashicons-marker';
			$state_text = $is_done ? __( 'Done', 'agent-abilities-for-mcp' ) : __( 'To do', 'agent-abilities-for-mcp' );

			printf( '<div class="aafm-step %s">', esc_attr( $state_cls ) );
			printf(
				'<span class="aafm-sidx"><span class="dashicons %1$s" aria-hidden="true"></span></span>',
				esc_attr( $state_icon )
			);
			echo '<div class="aafm-step-body">';
			printf( '<h3>%s</h3>', esc_html( (string) $step['title'] ) );
			printf( '<p>%s</p>', esc_html( (string) $step['desc'] ) );
			if ( ! $is_done ) {
				printf(
					'<p class="aafm-step-act"><a class="button" href="%s">%s</a></p>',
					esc_url( (string) $step['href'] ),
					esc_html__( 'Go to step', 'agent-abilities-for-mcp' )
				);
			}
			echo '</div>';
			printf(
				'<span class="aafm-step-state">%s</span>',
				esc_html( $state_text )
			);
			echo '</div>';
		}
		echo '</section>';
	}

	// Stat grid — four cards reusing the counts computed above.
	echo '<div class="aafm-stat-grid">';

	// Enabled abilities.
	echo '<div class="aafm-stat aafm-stat-abilities">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Enabled abilities', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic"><span class="dashicons dashicons-superhero" aria-hidden="true"></span></span>';
	echo '</div>';
	printf(
		'<div class="stat-value">%1$s <small>%2$s</small></div>',
		esc_html( number_format_i18n( $enabled ) ),
		esc_html(
			sprintf(
				/* translators: %d: total number of abilities in the catalog. */
				__( 'of %d', 'agent-abilities-for-mcp' ),
				$total
			)
		)
	);
	if ( 0 === $enabled ) {
		aafm_render_notice(
			'warning',
			__( 'No abilities are enabled, so the agent can do nothing yet. Turn on the abilities you want it to have on the Abilities tab.', 'agent-abilities-for-mcp' ),
			array( 'inline' => true )
		);
	}
	echo '</div>';

	// Recent agents (24h).
	echo '<div class="aafm-stat aafm-stat-recent">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Recent agents (24h)', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic"><span class="dashicons dashicons-groups" aria-hidden="true"></span></span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( $recent ) ) );
	echo '<div class="stat-sub">' . esc_html__( 'Separate agent users seen in the activity log in the last 24 hours. This is recent activity from the log, not a count of live connections.', 'agent-abilities-for-mcp' ) . '</div>';
	echo '</div>';

	// Audit log.
	echo '<div class="aafm-stat aafm-stat-audit">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Audit log', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic"><span class="dashicons dashicons-list-view" aria-hidden="true"></span></span>';
	echo '</div>';
	printf(
		'<div class="stat-value">%1$s <small>%2$s</small></div>',
		esc_html( number_format_i18n( $log_rows ) ),
		esc_html(
			sprintf(
				/* translators: %s: maximum number of rows kept in the audit log. */
				__( '/ %s rows', 'agent-abilities-for-mcp' ),
				number_format_i18n( $log_cap )
			)
		)
	);
	echo '<div class="stat-sub">' . esc_html__( 'Logging is on. Every call is recorded, including denied ones; the oldest rows drop once the cap is reached.', 'agent-abilities-for-mcp' ) . '</div>';
	echo '</div>';

	// Agent users.
	echo '<div class="aafm-stat aafm-stat-agent-users">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Agent users', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic"><span class="dashicons dashicons-groups" aria-hidden="true"></span></span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( count( $candidates ) ) ) );
	if ( empty( $candidates ) ) {
		aafm_render_notice(
			'info',
			__( 'No agent user is connected yet. Create a dedicated low-privilege user on the Connection tab and give it an Application Password.', 'agent-abilities-for-mcp' ),
			array( 'inline' => true )
		);
	} elseif ( empty( $admin_agents ) ) {
		aafm_render_notice(
			'success',
			__( 'Your agent users are all low-privilege. None of them can manage the site.', 'agent-abilities-for-mcp' ),
			array( 'inline' => true )
		);
	} else {
		$logins = implode( ', ', array_map( static fn( array $c ): string => (string) $c['login'], $admin_agents ) );
		aafm_render_notice(
			'warning',
			sprintf(
				/* translators: %s: comma-separated list of user logins that can manage the site. */
				__( 'These agent users can manage the site: %s. Give the agent its own low-privilege user instead. Move this one to a lower role, or connect a different user.', 'agent-abilities-for-mcp' ),
				$logins
			),
			array( 'inline' => true )
		);
	}
	echo '</div>';

	echo '</div>'; // .aafm-stat-grid

	// Lower row: endpoint + versions.
	echo '<div class="aafm-stat-grid aafm-dashboard-lower">';

	// Endpoint card — keeps the existing aafm-copy button + data-copy contract (admin.js binds to it).
	echo '<section class="aafm-card aafm-card-endpoint">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span></span>';
	echo '<h2>' . esc_html__( 'Endpoint', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '</div>';
	echo '<div class="aafm-card-pad">';
	printf(
		'<div class="aafm-field-mono"><code class="aafm-endpoint">%1$s</code> <button type="button" class="button aafm-copy" data-copy="%2$s">%3$s</button></div>',
		esc_html( $endpoint ),
		esc_attr( $endpoint ),
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);
	echo '<p class="description">' . esc_html__( 'Point your MCP client here. The Connection tab builds the full client config for you.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div>';
	echo '</section>';

	// Versions card.
	echo '<section class="aafm-card aafm-card-versions">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon"><span class="dashicons dashicons-clock" aria-hidden="true"></span></span>';
	echo '<h2>' . esc_html__( 'Versions', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '</div>';
	echo '<div class="aafm-card-pad">';
	echo '<dl class="aafm-kv">';
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'Plugin', 'agent-abilities-for-mcp' ),
		esc_html( AAFM_VERSION )
	);
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'PHP', 'agent-abilities-for-mcp' ),
		esc_html( PHP_VERSION )
	);
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'MCP protocol', 'agent-abilities-for-mcp' ),
		esc_html( aafm_mcp_protocol_version() )
	);
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'Bundled adapter', 'agent-abilities-for-mcp' ),
		esc_html( $adapter_label )
	);
	echo '</dl>';
	echo '</div>';
	echo '</section>';

	echo '</div>'; // .aafm-dashboard-lower

	echo '</div>'; // .aafm-dashboard
}
