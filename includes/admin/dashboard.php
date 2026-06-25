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
function oversio_agent_user_candidates(): array {
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
function oversio_enabled_ability_count(): int {
	return count( oversio_get_enabled_abilities() );
}

/**
 * Total abilities in the catalog (enabled or not).
 *
 * @return int
 */
function oversio_total_ability_count(): int {
	return count( oversio_get_abilities_registry() );
}

/**
 * The MCP protocol version this plugin speaks. Single source of truth so other
 * code (help tab, connection configs) can reference it rather than re-literal it.
 *
 * @return string
 */
function oversio_mcp_protocol_version(): string {
	return '2025-06-18';
}

/**
 * Total number of rows in the activity log.
 *
 * @return int Non-negative row count.
 */
function oversio_activity_count(): int {
	global $wpdb;
	// The table name is an internal constant ($wpdb->prefix . 'oversio_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table = esc_sql( oversio_activity_log_table() );

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
function oversio_recent_agent_count(): int {
	global $wpdb;
	// The table name is an internal constant ($wpdb->prefix . 'oversio_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table  = esc_sql( oversio_activity_log_table() );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT principal_user_id) FROM {$table} WHERE created_at >= %s", $cutoff ) );

	return max( 0, (int) $count );
}

/**
 * Whether any user has approved an OAuth connection (a live grant exists).
 *
 * Read-only; returns false when OAuth is disabled or nobody has approved yet so
 * callers never need to guard around the OAuth functions existing.
 *
 * @return bool
 */
function oversio_has_oauth_grant(): bool {
	return function_exists( 'oversio_oauth_list_grants' ) && ! empty( oversio_oauth_list_grants() );
}

/**
 * The three setup steps, each derived from real, observable site state — never a
 * faked "connected" signal. Step done-ness comes straight from the data helpers:
 *
 *   [0] abilities are enabled — oversio_enabled_ability_count() > 0
 *   [1] agent is connected    — oversio_has_oauth_grant() || oversio_agent_user_candidates() non-empty
 *   [2] a call has been made  — oversio_activity_count() > 0 (logged for real)
 *
 * The zero-based index is the contract callers rely on: $steps[0] is always the
 * abilities step, $steps[1] is always the connect step.
 *
 * @return array<int,array{title:string,desc:string,done:bool,href:string}>
 */
function oversio_setup_steps(): array {
	$tab_url = static function ( string $tab ): string {
		return add_query_arg(
			array(
				'page' => 'oversio-agent-abilities',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	};

	return array(
		array(
			'title' => __( 'Enable the abilities you want', 'oversio-agent-abilities' ),
			'desc'  => __( 'Nothing is exposed until you turn it on. Pick the abilities the agent should have on the Abilities tab.', 'oversio-agent-abilities' ),
			'done'  => oversio_enabled_ability_count() > 0,
			'href'  => $tab_url( 'abilities' ),
		),
		array(
			'title' => __( 'Connect your agent', 'oversio-agent-abilities' ),
			'desc'  => __( 'Approve it in the browser over OAuth, or set up an Application Password for a dedicated agent user. Either way its reach stays capped by what you turn on.', 'oversio-agent-abilities' ),
			'done'  => oversio_has_oauth_grant() || ! empty( oversio_agent_user_candidates() ),
			'href'  => $tab_url( 'connection' ),
		),
		array(
			'title' => __( 'Make your first call', 'oversio-agent-abilities' ),
			'desc'  => __( 'Point your MCP client at the endpoint and run one request. It shows up here once the activity log records it.', 'oversio-agent-abilities' ),
			'done'  => oversio_activity_count() > 0,
			'href'  => $tab_url( 'connection' ),
		),
	);
}

/**
 * Render the Dashboard tab: a guided setup checklist, a four-card stat grid, and a
 * two-card row (endpoint + versions).
 *
 * The checklist reflects real, observable state from oversio_setup_steps(); when all three
 * steps are done it collapses into a single "all set" success notice. The stat grid and
 * cards reuse the same counts the page already computes — enabled abilities, recent agent
 * activity (read from the audit log, not live connections), audit-log size, and agent
 * users, with an inline warning when an agent user can manage the site. Nothing here
 * changes state. The page shell (heading and lede) is rendered by page.php, not here.
 *
 * @return void
 */
function oversio_render_dashboard_tab(): void {
	$endpoint = oversio_endpoint_url();
	$enabled  = oversio_enabled_ability_count();
	// Single source of truth for "available / total" — counts the full catalog (core + every
	// integration's manifest total) so an inactive integration still contributes its count and
	// the Dashboard never disagrees with the Abilities tab.
	$total         = oversio_available_ability_count();
	$adapter       = oversio_loaded_adapter_version();
	$candidates    = oversio_agent_user_candidates();
	$recent        = oversio_recent_agent_count();
	$log_rows      = oversio_activity_count();
	$retention     = oversio_log_retention_days();
	$admin_agents  = array_values( array_filter( $candidates, static fn( array $c ): bool => ! empty( $c['is_admin'] ) ) );
	$adapter_label = ( null === $adapter ) ? __( 'not loaded', 'oversio-agent-abilities' ) : $adapter;

	$steps      = oversio_setup_steps();
	$done_count = count( array_filter( $steps, static fn( array $s ): bool => ! empty( $s['done'] ) ) );
	$step_total = count( $steps );

	echo '<div class="oversio-dashboard">';

	// Setup steps are always rendered inside a collapsible <details class="oversio-setup">. While any
	// step is pending the panel is open (the open attribute) with a "Finish setting up" summary, a
	// progress bar, and the X-of-Y count. Once every step is done the panel collapses (no open
	// attribute) into a "Setup complete" recap — the steps stay available behind the summary, and
	// the old standalone success notice is dropped so there is no notice-plus-gap to clean up.
	$is_complete = $done_count === $step_total;
	$open_attr   = $is_complete ? '' : ' open';

	printf( '<details class="oversio-setup"%s>', esc_attr( $open_attr ) );
	echo '<summary class="oversio-setup-top">';
	if ( $is_complete ) {
		echo '<span class="oversio-setup-ic" aria-hidden="true">';
		echo oversio_icon( 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		echo '</span>';
		echo '<h2>' . esc_html__( 'Setup complete, steps below', 'oversio-agent-abilities' ) . '</h2>';
	} else {
		echo '<h2>' . esc_html__( 'Finish setting up', 'oversio-agent-abilities' ) . '</h2>';
		printf(
			'<span class="oversio-setup-count">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: number of completed setup steps, 2: total setup steps. */
					__( '%1$d of %2$d done', 'oversio-agent-abilities' ),
					$done_count,
					$step_total
				)
			)
		);
		// Progress bar: filled to done / total. Width is a computed integer percent.
		$progress_pct = $step_total > 0 ? (int) round( ( $done_count / $step_total ) * 100 ) : 0;
		printf(
			'<div class="oversio-progress" aria-hidden="true"><span style="width:%s%%"></span></div>',
			esc_attr( (string) $progress_pct )
		);
	}
	echo '</summary>';

	echo '<div class="oversio-setup-steps">';
	// The first not-done step is the "active" one (blue marker, number shown); the rest
	// of the not-done steps are plain "to do" (grey marker, number shown). Done steps get
	// the green check marker. When complete there is no active step at all.
	$active_marked = false;
	$step_num      = 0;
	foreach ( $steps as $step ) {
		++$step_num;
		$is_done = ! empty( $step['done'] );

		if ( $is_done ) {
			$state_cls  = 'oversio-step-done';
			$pill_class = 'oversio-pill oversio-pill-success';
			$pill_text  = __( 'Done', 'oversio-agent-abilities' );
		} elseif ( ! $active_marked ) {
			$state_cls     = 'oversio-step-active';
			$pill_class    = 'oversio-pill oversio-pill-warn';
			$pill_text     = __( 'To do', 'oversio-agent-abilities' );
			$active_marked = true;
		} else {
			$state_cls  = 'oversio-step-todo';
			$pill_class = 'oversio-pill oversio-pill-neutral';
			$pill_text  = __( 'To do', 'oversio-agent-abilities' );
		}

		printf( '<div class="oversio-step %s">', esc_attr( $state_cls ) );
		if ( $is_done ) {
			echo '<span class="oversio-sidx">';
			echo oversio_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
			echo '</span>';
		} else {
			printf( '<span class="oversio-sidx">%s</span>', esc_html( (string) $step_num ) );
		}
		echo '<div class="oversio-step-body">';
		printf( '<h3>%s</h3>', esc_html( (string) $step['title'] ) );
		printf( '<p>%s</p>', esc_html( (string) $step['desc'] ) );
		// The active step gets a primary CTA with a trailing arrow; other to-do steps don't.
		if ( 'oversio-step-active' === $state_cls ) {
			printf(
				'<p class="oversio-step-act"><a class="oversio-btn oversio-btn-primary oversio-btn-sm" href="%1$s">%2$s %3$s</a></p>',
				esc_url( (string) $step['href'] ),
				esc_html__( 'Go to step', 'oversio-agent-abilities' ),
				oversio_icon( 'arrow-right' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
			);
		}
		echo '</div>';
		printf(
			'<span class="oversio-step-state %1$s">%2$s</span>',
			esc_attr( $pill_class ),
			esc_html( $pill_text )
		);
		echo '</div>';
	}
	echo '</div>'; // .oversio-setup-steps
	echo '</details>';

	// Stat grid — four cards reusing the counts computed above. The compact mockup
	// treatment: a value line plus a .stat-sub and/or a small pill, no embedded notices.
	echo '<div class="oversio-stat-grid">';

	// Enabled abilities.
	echo '<div class="oversio-stat oversio-stat-abilities">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Enabled abilities', 'oversio-agent-abilities' ) . '</span>';
	echo '<span class="stat-ic">';
	echo oversio_icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '</div>';
	printf(
		'<div class="stat-value">%1$s <small>%2$s</small></div>',
		esc_html( number_format_i18n( $enabled ) ),
		esc_html(
			sprintf(
				/* translators: %d: total number of abilities in the catalog. */
				__( 'of %d', 'oversio-agent-abilities' ),
				$total
			)
		)
	);
	if ( 0 === $enabled ) {
		echo '<div class="stat-sub">' . esc_html__( 'Turn some on to start', 'oversio-agent-abilities' ) . '</div>';
	} else {
		$still_off = max( 0, $total - $enabled );
		printf(
			'<div class="stat-sub">%s</div>',
			esc_html(
				sprintf(
					/* translators: %s: number of abilities still turned off. */
					__( '%s still off', 'oversio-agent-abilities' ),
					number_format_i18n( $still_off )
				)
			)
		);
	}
	echo '</div>';

	// Recent agents (24h).
	echo '<div class="oversio-stat oversio-stat-recent">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Recent agents (24h)', 'oversio-agent-abilities' ) . '</span>';
	echo '<span class="stat-ic">';
	echo oversio_icon( 'recent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( $recent ) ) );
	echo '<div class="stat-sub">' . esc_html__( 'Separate agent users seen in the activity log in the last 24 hours. This is recent activity from the log, not a count of live connections.', 'oversio-agent-abilities' ) . '</div>';
	echo '</div>';

	// Audit log.
	echo '<div class="oversio-stat oversio-stat-audit">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Audit log', 'oversio-agent-abilities' ) . '</span>';
	echo '<span class="stat-ic">';
	echo oversio_icon( 'audit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '</div>';
	printf(
		'<div class="stat-value">%1$s <small>%2$s</small></div>',
		esc_html( number_format_i18n( $log_rows ) ),
		esc_html( _n( 'entry', 'entries', $log_rows, 'oversio-agent-abilities' ) )
	);
	if ( $retention > 0 ) {
		$log_sub = sprintf(
			/* translators: %s: number of days of activity the log keeps. */
			_n(
				'Keeping the last %s day of activity.',
				'Keeping the last %s days of activity.',
				$retention,
				'oversio-agent-abilities'
			),
			number_format_i18n( $retention )
		);
	} else {
		$log_sub = __( 'Keeping all activity.', 'oversio-agent-abilities' );
	}
	printf( '<div class="stat-sub">%s</div>', esc_html( $log_sub ) );
	echo '</div>';

	// Agent users. The security signal is preserved: when an admin-capable agent exists,
	// a warn pill flags it AND the sub text names the login(s).
	echo '<div class="oversio-stat oversio-stat-agent-users">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Agent users', 'oversio-agent-abilities' ) . '</span>';
	echo '<span class="stat-ic">';
	echo oversio_icon( 'groups' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( count( $candidates ) ) ) );
	if ( empty( $candidates ) ) {
		echo '<div class="stat-sub">' . esc_html__( 'No agent user yet', 'oversio-agent-abilities' ) . '</div>';
	} elseif ( empty( $admin_agents ) ) {
		echo '<div class="stat-sub">' . esc_html__( 'All low-privilege', 'oversio-agent-abilities' ) . '</div>';
	} else {
		$logins = implode( ', ', array_map( static fn( array $c ): string => (string) $c['login'], $admin_agents ) );
		echo '<div class="stat-sub"><span class="oversio-pill oversio-pill-warn">' . esc_html__( 'Review role', 'oversio-agent-abilities' ) . '</span></div>';
		printf(
			'<div class="stat-sub">%s</div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of user logins that can manage the site. */
					__( 'Can manage the site: %s. Give the agent its own low-privilege user instead.', 'oversio-agent-abilities' ),
					$logins
				)
			)
		);
	}
	echo '</div>';

	echo '</div>'; // .oversio-stat-grid

	// Lower row: endpoint + versions.
	echo '<div class="oversio-stat-grid oversio-dashboard-lower">';

	// Endpoint card — keeps the existing oversio-copy button + data-copy contract (admin.js binds to it).
	echo '<section class="oversio-card oversio-card-endpoint">';
	echo '<div class="oversio-card-head">';
	echo '<span class="icon">';
	echo oversio_icon( 'endpoint' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '<h2>' . esc_html__( 'MCP endpoint', 'oversio-agent-abilities' ) . '</h2>';
	// Permalink-mode info pill on the right, like the mockup.
	$pretty          = (bool) get_option( 'permalink_structure' );
	$permalink_label = $pretty
		? __( 'Pretty permalinks', 'oversio-agent-abilities' )
		: __( 'Plain permalinks', 'oversio-agent-abilities' );
	printf(
		'<span class="oversio-pill oversio-pill-info" style="margin-inline-start:auto">%s</span>',
		esc_html( $permalink_label )
	);
	echo '</div>';
	echo '<div class="oversio-card-pad">';
	printf(
		'<div class="oversio-field-mono"><code class="oversio-endpoint">%1$s</code> <button type="button" class="oversio-btn oversio-btn-secondary oversio-copy" data-copy="%2$s">%3$s<span class="oversio-copy-label">%4$s</span></button></div>',
		esc_html( $endpoint ),
		esc_attr( $endpoint ),
		oversio_icon( 'copy' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		esc_html__( 'Copy', 'oversio-agent-abilities' )
	);
	echo '<p class="description">' . esc_html__( 'Point your MCP client here. The Connection tab builds the full client config for you.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div>';
	echo '</section>';

	// Versions card.
	echo '<section class="oversio-card oversio-card-versions">';
	echo '<div class="oversio-card-head">';
	echo '<span class="icon">';
	echo oversio_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '<h2>' . esc_html__( 'Versions', 'oversio-agent-abilities' ) . '</h2>';
	echo '</div>';
	echo '<div class="oversio-card-pad">';
	echo '<dl class="oversio-kv">';
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'Plugin', 'oversio-agent-abilities' ),
		esc_html( OVERSIO_VERSION )
	);
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'PHP', 'oversio-agent-abilities' ),
		esc_html( PHP_VERSION )
	);
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'MCP protocol', 'oversio-agent-abilities' ),
		esc_html( oversio_mcp_protocol_version() )
	);
	printf(
		'<dt>%1$s</dt><dd>%2$s</dd>',
		esc_html__( 'Bundled adapter', 'oversio-agent-abilities' ),
		esc_html( $adapter_label )
	);
	echo '</dl>';
	echo '</div>';
	echo '</section>';

	echo '</div>'; // .oversio-dashboard-lower

	echo '</div>'; // .oversio-dashboard
}
