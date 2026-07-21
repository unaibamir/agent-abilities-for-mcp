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
 * Users that hold at least one application password - the accounts an MCP agent
 * could authenticate as. Exposes only id/login/roles and an admin flag, never
 * email, display name, or any password material.
 *
 * Queries by the application-passwords meta key directly rather than paging through every
 * user on the site: a fixed page (M11) meant an agent user created after the cap was
 * invisible to the dashboard on any site with more users than the page held. A user still
 * carries the meta key after deleting all their passwords (WP_Application_Passwords stores
 * an empty array rather than removing the key), so the per-user emptiness check below still
 * does the real filtering; this query only narrows the candidate set.
 *
 * @return array<int,array{id:int,login:string,roles:array<int,string>,is_admin:bool}>
 */
function aafm_agent_user_candidates(): array {
	$users = get_users(
		array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- narrows to app-password holders instead of paging through every user.
			'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS,
			'meta_compare' => 'EXISTS',
			'orderby'      => 'ID',
			'order'        => 'ASC',
			'fields'       => array( 'ID', 'user_login' ),
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
 * Meta key stamped on a user this plugin created through the dedicated-agent-user flow
 * ({@see aafm_create_agent_user()}). It is the durable, plugin-owned record that tells a real
 * agent connection apart from any unrelated application-password holder (Jetpack, a mobile app,
 * some other REST integration), so the onboarding "Connect your agent" signal never reads a
 * false positive. Single source of truth: both the stamp and the reader below reference it.
 *
 * @return string
 */
function aafm_agent_user_marker_meta_key(): string {
	return 'aafm_agent_user';
}

/**
 * IDs of users this plugin created as dedicated agent users - the ones carrying the marker
 * meta {@see aafm_agent_user_marker_meta_key()}. Unlike {@see aafm_agent_user_candidates()},
 * which counts every application-password holder for the security warning, this counts only
 * accounts the plugin itself made for the agent, so it is the honest "an agent user exists"
 * signal for the setup checklist.
 *
 * @return int[] User IDs, ascending.
 */
function aafm_created_agent_users(): array {
	$ids = get_users(
		array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- narrows to plugin-created agent users by our own marker meta.
			'meta_key'     => aafm_agent_user_marker_meta_key(),
			'meta_compare' => 'EXISTS',
			'orderby'      => 'ID',
			'order'        => 'ASC',
			'fields'       => 'ids',
		)
	);

	return array_map( 'intval', $ids );
}

/**
 * Whether the plugin has created at least one dedicated agent user.
 *
 * @return bool
 */
function aafm_has_created_agent_user(): bool {
	return array() !== aafm_created_agent_users();
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
	// never user input; the %i identifier placeholder passes it through $wpdb->prepare().
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', aafm_activity_log_table() ) );

	return max( 0, (int) $count );
}

/**
 * Exact count of distinct agent principals seen in the activity log over the last
 * 24 hours (UTC), computed in one bounded query against the plugin's own audit table.
 *
 * This is recent-principal activity read back from the audit log, NOT a live socket or
 * connection count. created_at is stored as a UTC ('mysql', true) datetime string, so the
 * cutoff is computed with gmdate() and the query counts every distinct principal at once -
 * no page cap, so it never undercounts on a busy site.
 *
 * @return int Number of distinct principals active in the last 24 hours.
 */
function aafm_recent_agent_count(): int {
	global $wpdb;
	$table  = aafm_activity_log_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT principal_user_id) FROM %i WHERE created_at >= %s', $table, $cutoff ) );

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
function aafm_has_oauth_grant(): bool {
	return function_exists( 'aafm_oauth_list_grants' ) && ! empty( aafm_oauth_list_grants() );
}

/**
 * The three setup steps, each derived from real, observable site state - never a
 * faked "connected" signal. Step done-ness comes straight from the data helpers:
 *
 *   [0] abilities are enabled - aafm_enabled_ability_count() > 0
 *   [1] agent is connected    - aafm_has_oauth_grant() || aafm_has_created_agent_user()
 *   [2] a call has been made  - aafm_activity_count() > 0 (logged for real)
 *
 * Step [1] keys off a live OAuth grant or a user THIS plugin created as a dedicated agent
 * (the marker meta), never a bare application-password holder - app passwords are minted for
 * many unrelated reasons, so counting them here flagged "connected" before any agent had.
 *
 * The zero-based index is the contract callers rely on: $steps[0] is always the
 * abilities step, $steps[1] is always the connect step.
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
			admin_url( 'admin.php' )
		);
	};

	return array(
		array(
			'title' => __( 'Enable the abilities you want', 'agent-abilities-for-mcp' ),
			'desc'  => __( 'Nothing is exposed until you turn it on. Pick the abilities the agent should have on the Abilities tab.', 'agent-abilities-for-mcp' ),
			'done'  => aafm_enabled_ability_count() > 0,
			'href'  => $tab_url( 'abilities' ),
		),
		array(
			'title' => __( 'Connect your agent', 'agent-abilities-for-mcp' ),
			'desc'  => __( 'Approve it in the browser over OAuth, or set up an Application Password for a dedicated agent user. Either way its reach stays capped by what you turn on.', 'agent-abilities-for-mcp' ),
			'done'  => aafm_has_oauth_grant() || aafm_has_created_agent_user(),
			'href'  => $tab_url( 'connection' ),
		),
		array(
			'title' => __( 'Make your first call', 'agent-abilities-for-mcp' ),
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
 * cards reuse the same counts the page already computes - enabled abilities, recent agent
 * activity (read from the audit log, not live connections), audit-log size, and agent
 * users, with an inline warning when an agent user can manage the site. Nothing here
 * changes state. The page shell (heading and lede) is rendered by page.php, not here.
 *
 * @return void
 */
function aafm_render_dashboard_tab(): void {
	$endpoint = aafm_endpoint_url();
	$enabled  = aafm_enabled_ability_count();
	// Single source of truth for "available / total" - counts the full catalog (core + every
	// integration's manifest total) so an inactive integration still contributes its count and
	// the Dashboard never disagrees with the Abilities tab.
	$total          = aafm_available_ability_count();
	$adapter        = aafm_loaded_adapter_version();
	$candidates     = aafm_agent_user_candidates();
	$created_agents = aafm_created_agent_users();
	$recent         = aafm_recent_agent_count();
	$log_rows       = aafm_activity_count();
	$retention      = aafm_log_retention_days();
	// Two different questions, kept apart on purpose. $created_agents is the honest "agent
	// users this plugin made" count (the card value). $admin_agents is the security signal:
	// admins who hold an application password, so they can reach the API and manage the site -
	// the correct broad scope for that caution, whether or not this plugin created them.
	$admin_agents  = array_values( array_filter( $candidates, static fn( array $c ): bool => ! empty( $c['is_admin'] ) ) );
	$adapter_label = ( null === $adapter ) ? __( 'not loaded', 'agent-abilities-for-mcp' ) : $adapter;

	$steps      = aafm_setup_steps();
	$done_count = count( array_filter( $steps, static fn( array $s ): bool => ! empty( $s['done'] ) ) );
	$step_total = count( $steps );

	echo '<div class="aafm-dashboard">';

	// Setup steps are always rendered inside a collapsible <details class="aafm-setup">. While any
	// step is pending the panel is open (the open attribute) with a "Finish setting up" summary, a
	// progress bar, and the X-of-Y count. Once every step is done the panel collapses (no open
	// attribute) into a "Setup complete" recap - the steps stay available behind the summary, and
	// the old standalone success notice is dropped so there is no notice-plus-gap to clean up.
	$is_complete = $done_count === $step_total;
	$open_attr   = $is_complete ? '' : ' open';

	printf( '<details class="aafm-setup"%s>', esc_attr( $open_attr ) );
	echo '<summary class="aafm-setup-top">';
	if ( $is_complete ) {
		echo '<span class="aafm-setup-ic" aria-hidden="true">';
		echo wp_kses( aafm_icon( 'success' ), aafm_svg_allowed_html() );
		echo '</span>';
		echo '<h2>' . esc_html__( 'Setup complete, steps below', 'agent-abilities-for-mcp' ) . '</h2>';
	} else {
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
		// Progress bar: filled to done / total. Width is a computed integer percent.
		$progress_pct = $step_total > 0 ? (int) round( ( $done_count / $step_total ) * 100 ) : 0;
		printf(
			'<div class="aafm-progress" aria-hidden="true"><span style="width:%s%%"></span></div>',
			esc_attr( (string) $progress_pct )
		);
	}
	echo '</summary>';

	echo '<div class="aafm-setup-steps">';
	// The first not-done step is the "active" one (blue marker, number shown); the rest
	// of the not-done steps are plain "to do" (grey marker, number shown). Done steps get
	// the green check marker. When complete there is no active step at all.
	$active_marked = false;
	$step_num      = 0;
	foreach ( $steps as $step ) {
		++$step_num;
		$is_done = ! empty( $step['done'] );

		if ( $is_done ) {
			$state_cls  = 'aafm-step-done';
			$pill_class = 'aafm-pill aafm-pill-success';
			$pill_text  = __( 'Done', 'agent-abilities-for-mcp' );
		} elseif ( ! $active_marked ) {
			$state_cls     = 'aafm-step-active';
			$pill_class    = 'aafm-pill aafm-pill-warn';
			$pill_text     = __( 'To do', 'agent-abilities-for-mcp' );
			$active_marked = true;
		} else {
			$state_cls  = 'aafm-step-todo';
			$pill_class = 'aafm-pill aafm-pill-neutral';
			$pill_text  = __( 'To do', 'agent-abilities-for-mcp' );
		}

		printf( '<div class="aafm-step %s">', esc_attr( $state_cls ) );
		if ( $is_done ) {
			echo '<span class="aafm-sidx">';
			echo wp_kses( aafm_icon( 'check' ), aafm_svg_allowed_html() );
			echo '</span>';
		} else {
			printf( '<span class="aafm-sidx">%s</span>', esc_html( (string) $step_num ) );
		}
		echo '<div class="aafm-step-body">';
		printf( '<h3>%s</h3>', esc_html( (string) $step['title'] ) );
		printf( '<p>%s</p>', esc_html( (string) $step['desc'] ) );
		// The active step gets a primary CTA with a trailing arrow; other to-do steps don't.
		if ( 'aafm-step-active' === $state_cls ) {
			echo wp_kses(
				sprintf(
					'<p class="aafm-step-act"><a class="aafm-btn aafm-btn-primary aafm-btn-sm" href="%1$s">%2$s %3$s</a></p>',
					esc_url( (string) $step['href'] ),
					esc_html__( 'Go to step', 'agent-abilities-for-mcp' ),
					aafm_icon( 'arrow-right' )
				),
				aafm_admin_allowed_html()
			);
		}
		echo '</div>';
		printf(
			'<span class="aafm-step-state %1$s">%2$s</span>',
			esc_attr( $pill_class ),
			esc_html( $pill_text )
		);
		echo '</div>';
	}
	echo '</div>'; // .aafm-setup-steps
	echo '</details>';

	// Stat grid - four cards reusing the counts computed above. The compact mockup
	// treatment: a value line plus a .stat-sub and/or a small pill, no embedded notices.
	echo '<div class="aafm-stat-grid">';

	// Enabled abilities.
	echo '<div class="aafm-stat aafm-stat-abilities">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Enabled abilities', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic">';
	echo wp_kses( aafm_icon( 'bolt' ), aafm_svg_allowed_html() );
	echo '</span>';
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
		echo '<div class="stat-sub">' . esc_html__( 'Turn some on to start', 'agent-abilities-for-mcp' ) . '</div>';
	} else {
		$still_off = max( 0, $total - $enabled );
		printf(
			'<div class="stat-sub">%s</div>',
			esc_html(
				sprintf(
					/* translators: %s: number of abilities still turned off. */
					__( '%s still off', 'agent-abilities-for-mcp' ),
					number_format_i18n( $still_off )
				)
			)
		);
	}
	echo '</div>';

	// Recent agents (24h).
	echo '<div class="aafm-stat aafm-stat-recent">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Recent agents (24h)', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic">';
	echo wp_kses( aafm_icon( 'recent' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( $recent ) ) );
	echo '<div class="stat-sub">' . esc_html__( 'Separate agent users seen in the activity log in the last 24 hours. This is recent activity from the log, not a count of live connections.', 'agent-abilities-for-mcp' ) . '</div>';
	echo '</div>';

	// Audit log.
	echo '<div class="aafm-stat aafm-stat-audit">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Audit log', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic">';
	echo wp_kses( aafm_icon( 'audit' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '</div>';
	printf(
		'<div class="stat-value">%1$s <small>%2$s</small></div>',
		esc_html( number_format_i18n( $log_rows ) ),
		esc_html( _n( 'entry', 'entries', $log_rows, 'agent-abilities-for-mcp' ) )
	);
	if ( $retention > 0 ) {
		$log_sub = sprintf(
			/* translators: %s: number of days of activity the log keeps. */
			_n(
				'Keeping the last %s day of activity.',
				'Keeping the last %s days of activity.',
				$retention,
				'agent-abilities-for-mcp'
			),
			number_format_i18n( $retention )
		);
	} else {
		$log_sub = __( 'Keeping all activity.', 'agent-abilities-for-mcp' );
	}
	printf( '<div class="stat-sub">%s</div>', esc_html( $log_sub ) );
	echo '</div>';

	// Agent users. The VALUE counts only agent users this plugin created (the marker), never a
	// bare application-password holder - counting those flagged admins with app passwords as
	// "agent users" they never were. The admin-app-password caution below is a separate, honest
	// security heads-up computed from the broad candidate set, worded so it does not imply those
	// admins are agent users.
	$created_count = count( $created_agents );
	echo '<div class="aafm-stat aafm-stat-agent-users">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Agent users', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic">';
	echo wp_kses( aafm_icon( 'groups' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( $created_count ) ) );
	if ( 0 === $created_count ) {
		echo '<div class="stat-sub">' . esc_html__( 'No agent user yet', 'agent-abilities-for-mcp' ) . '</div>';
	} else {
		echo '<div class="stat-sub">' . esc_html__( 'Created by this plugin', 'agent-abilities-for-mcp' ) . '</div>';
	}

	// Separate security caution: admins who hold an application password can reach the API and
	// manage the site, whether or not they are the plugin's agent user. Kept out of the value so
	// the card's number and this warning never contradict each other.
	if ( ! empty( $admin_agents ) ) {
		$admin_count = count( $admin_agents );
		$logins      = implode( ', ', array_map( static fn( array $c ): string => (string) $c['login'], $admin_agents ) );
		echo '<div class="stat-sub"><span class="aafm-pill aafm-pill-warn">' . esc_html__( 'Review access', 'agent-abilities-for-mcp' ) . '</span></div>';
		$caution = sprintf(
			/* translators: 1: number of admin accounts, 2: comma-separated list of their logins. */
			_n(
				'%1$s account can reach the API and manage this site: %2$s.',
				'%1$s accounts can reach the API and manage this site: %2$s.',
				$admin_count,
				'agent-abilities-for-mcp'
			),
			number_format_i18n( $admin_count ),
			$logins
		);
		$caution .= ' ' . __( 'If you connect an agent, give it its own low-privilege user instead.', 'agent-abilities-for-mcp' );
		printf( '<div class="stat-sub">%s</div>', esc_html( $caution ) );
	}
	echo '</div>';

	echo '</div>'; // .aafm-stat-grid

	// Lower row: endpoint + versions.
	echo '<div class="aafm-stat-grid aafm-dashboard-lower">';

	// Endpoint card - keeps the existing aafm-copy button + data-copy contract (admin.js binds to it).
	echo '<section class="aafm-card aafm-card-endpoint">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon">';
	echo wp_kses( aafm_icon( 'endpoint' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '<h2>' . esc_html__( 'MCP endpoint', 'agent-abilities-for-mcp' ) . '</h2>';
	// Permalink-mode info pill on the right, like the mockup.
	$pretty          = (bool) get_option( 'permalink_structure' );
	$permalink_label = $pretty
		? __( 'Pretty permalinks', 'agent-abilities-for-mcp' )
		: __( 'Plain permalinks', 'agent-abilities-for-mcp' );
	printf(
		'<span class="aafm-pill aafm-pill-info" style="margin-inline-start:auto">%s</span>',
		esc_html( $permalink_label )
	);
	echo '</div>';
	echo '<div class="aafm-card-pad">';
	echo wp_kses(
		sprintf(
			'<div class="aafm-field-mono"><code class="aafm-endpoint">%1$s</code> <button type="button" class="aafm-btn aafm-btn-secondary aafm-copy" data-copy="%2$s">%3$s<span class="aafm-copy-label">%4$s</span></button></div>',
			esc_html( $endpoint ),
			esc_attr( $endpoint ),
			aafm_icon( 'copy' ),
			esc_html__( 'Copy', 'agent-abilities-for-mcp' )
		),
		aafm_admin_allowed_html()
	);
	echo '<p class="description">' . esc_html__( 'Point your MCP client here. The Connection tab builds the full client config for you.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div>';
	echo '</section>';

	// Versions card.
	echo '<section class="aafm-card aafm-card-versions">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon">';
	echo wp_kses( aafm_icon( 'clock' ), aafm_svg_allowed_html() );
	echo '</span>';
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
