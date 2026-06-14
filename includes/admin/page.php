<?php
/**
 * Admin settings page: menu, tab routing, Abilities + Activity tabs, AJAX handlers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the settings submenu under Settings.
 *
 * @return void
 */
function aafm_register_admin_menu(): void {
	add_options_page(
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		__( 'Agent Abilities', 'agent-abilities-for-mcp' ),
		'manage_options',
		'agent-abilities-for-mcp',
		'aafm_render_admin_page'
	);
}

/**
 * Enqueue admin assets only on our settings page.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function aafm_enqueue_admin_assets( string $hook ): void {
	if ( 'settings_page_agent-abilities-for-mcp' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.css', array(), AAFM_VERSION );
	wp_enqueue_script( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.js', array(), AAFM_VERSION, true );
	wp_localize_script(
		'aafm-admin',
		'aafmAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aafm_admin' ),
			'i18n'    => array(
				'quickstartsShow'   => __( 'Show config for a specific client', 'agent-abilities-for-mcp' ),
				'quickstartsHide'   => __( 'Hide client configs', 'agent-abilities-for-mcp' ),
				'saving'            => __( 'Saving…', 'agent-abilities-for-mcp' ),
				'saved'             => __( 'Saved', 'agent-abilities-for-mcp' ),
				'errorSaving'       => __( 'Error saving', 'agent-abilities-for-mcp' ),
				'creating'          => __( 'Creating…', 'agent-abilities-for-mcp' ),
				'checking'          => __( 'Checking…', 'agent-abilities-for-mcp' ),
				'cleared'           => __( 'Cleared', 'agent-abilities-for-mcp' ),
				'error'             => __( 'Error', 'agent-abilities-for-mcp' ),
				'requestFailed'     => __( 'Request failed.', 'agent-abilities-for-mcp' ),
				'settingsNotSaved'  => __( 'Could not save — your previous settings are still in effect.', 'agent-abilities-for-mcp' ),
				'allowlistEmptied'  => __( 'Saved, but every line was dropped as invalid. The allowlist is now empty, so connections from anywhere are allowed.', 'agent-abilities-for-mcp' ),
				/* translators: %d: number of allowlist lines that were dropped as invalid. */
				'allowlistDropped'  => __( 'Saved. Dropped %d line(s) that were not a valid IP or range — check the allowlist.', 'agent-abilities-for-mcp' ),
				/* translators: %d: the new agent user's numeric ID. */
				'userCreated'       => __( 'Created user #%d. Now create its Application Password under Users → Profile.', 'agent-abilities-for-mcp' ),
				/* translators: 1: HTTP status code, 2: number of tools visible in the admin view. */
				'connectionOk'      => __( 'Reachable (HTTP %1$s) — %2$s tool(s) in your admin view.', 'agent-abilities-for-mcp' ),
				/* translators: %s: HTTP status code returned by the endpoint. */
				'connectionNoTools' => __( 'Endpoint answered HTTP %s but did not return a tool list.', 'agent-abilities-for-mcp' ),
				/* translators: %s: error message returned by the server. */
				'errorWithMessage'  => __( 'Error: %s', 'agent-abilities-for-mcp' ),
				'errorUnknown'      => __( 'unknown', 'agent-abilities-for-mcp' ),
				'copyCopied'        => __( 'Copied', 'agent-abilities-for-mcp' ),
				'copyFallback'      => __( 'Press Ctrl+C', 'agent-abilities-for-mcp' ),
			),
		)
	);
}

/**
 * Sanitize posted ability toggles down to known registry keys.
 *
 * The result is intersected with the live registry, so a stale, unknown, or smuggled
 * key can never enable anything — only abilities that actually exist are honored.
 *
 * @param array<string,mixed> $posted The $_POST payload, already unslashed by the caller.
 * @return array<int,string>
 */
function aafm_sanitize_enabled_input( array $posted ): array {
	$known   = array_keys( aafm_get_abilities_registry() );
	$enabled = array();
	if ( isset( $posted['aafm_abilities'] ) && is_array( $posted['aafm_abilities'] ) ) {
		foreach ( $posted['aafm_abilities'] as $name ) {
			$enabled[] = sanitize_text_field( (string) $name );
		}
	}
	return array_values( array_intersect( $enabled, $known ) );
}

/**
 * AJAX: save the enabled-abilities toggles.
 *
 * @return void
 */
function aafm_ajax_save_abilities(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$enabled = aafm_sanitize_enabled_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	update_option( 'aafm_enabled_abilities', $enabled );
	wp_send_json_success( array( 'enabled' => $enabled ) );
}

/**
 * Sanitize posted "exposed content types" down to eligible, opt-in types.
 *
 * The post and page types are always-on (forced by aafm_allowed_post_types()), so they are
 * intentionally dropped here rather than persisted. Every remaining value must clear the
 * eligibility floor, so attachment, revision, private CPTs, and junk can never be stored.
 *
 * @param array<string,mixed> $posted The $_POST payload, already unslashed by the caller.
 * @return list<string>
 */
function aafm_sanitize_allowed_post_types_input( array $posted ): array {
	$types = array();
	if ( isset( $posted['aafm_post_types'] ) && is_array( $posted['aafm_post_types'] ) ) {
		foreach ( $posted['aafm_post_types'] as $type ) {
			$types[] = sanitize_key( (string) $type );
		}
	}
	$types = array_diff( $types, array( 'post', 'page' ) );
	return array_values( array_filter( array_unique( $types ), 'aafm_post_type_is_eligible' ) );
}

/**
 * AJAX: save the exposed-content-types allowlist.
 *
 * @return void
 */
function aafm_ajax_save_post_types(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$types = aafm_sanitize_allowed_post_types_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	update_option( 'aafm_allowed_post_types', $types );
	wp_send_json_success( array( 'post_types' => $types ) );
}

/**
 * Sanitize the posted "exposed meta keys" textarea into a clean, de-duplicated allowlist.
 *
 * Splits on newlines, trims each line (meta keys are case-sensitive, so case is preserved;
 * only surrounding whitespace and control chars are stripped via sanitize_text_field), drops
 * empties, drops any hard-blocked key so a blocked key can never even be stored, de-duplicates,
 * and re-indexes. The read path (aafm_allowed_meta_keys) re-floors anyway; this is best-effort.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled here).
 * @return list<string>
 */
function aafm_sanitize_allowed_meta_keys_input( array $posted ): array {
	$raw  = isset( $posted['aafm_meta_keys'] ) ? (string) $posted['aafm_meta_keys'] : '';
	$keys = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$key = sanitize_text_field( trim( (string) $line ) );
		if ( '' === $key || aafm_hard_blocked_meta_key( $key ) ) {
			continue;
		}
		$keys[] = $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Sample up to 50 distinct, non-hard-blocked meta keys present on posts of the allowlisted
 * types — the "Detected on your exposed types" chip source for the selector.
 *
 * One read-only, prepared query (dynamic IN of bound %s placeholders for the exposed types),
 * filtered against the hard-block, sliced to 50, cached 5 minutes in a best-effort transient.
 * Purely cosmetic: the cache is advisory and the allowlist gate never trusts this list.
 *
 * @return list<string>
 */
function aafm_detected_meta_keys(): array {
	$cached = get_transient( 'aafm_detected_meta_keys' );
	if ( is_array( $cached ) ) {
		return array_values( array_map( 'strval', $cached ) );
	}
	global $wpdb;
	$types = aafm_allowed_post_types();
	if ( empty( $types ) ) {
		return array();
	}
	$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// $ph is a list of %s placeholders, the type values are bound via prepare() below.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ($ph) ORDER BY pm.meta_key ASC LIMIT 200", $types ) );
	$keys = array_map( 'strval', (array) $rows );
	$keys = array_values( array_filter( $keys, static fn( string $k ): bool => ! aafm_hard_blocked_meta_key( $k ) ) );
	$keys = array_slice( $keys, 0, 50 );
	set_transient( 'aafm_detected_meta_keys', $keys, 5 * MINUTE_IN_SECONDS );
	return $keys;
}

/**
 * AJAX: save the exposed-meta-keys allowlist.
 *
 * @return void
 */
function aafm_ajax_save_meta_keys(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$keys = aafm_sanitize_allowed_meta_keys_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	update_option( 'aafm_allowed_meta_keys', $keys );
	delete_transient( 'aafm_detected_meta_keys' );
	wp_send_json_success( array( 'meta_keys' => $keys ) );
}

/**
 * Contribute suggested privacy-policy text describing what an exposed content type leaks.
 *
 * @return void
 */
function aafm_register_privacy_policy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = wp_kses_post(
		'<p>' . __( 'When an administrator exposes a content type to AI agents through Agent Abilities for MCP, an authenticated agent can read that type\'s title, slug, excerpt, status, permalink, publish/modified dates, and author id. If an administrator also exposes specific meta keys, an agent can read and change those keys\' values on any post it is allowed to edit. Protected keys (those prefixed with an underscore) and authentication-related keys can never be exposed. Only expose content types and meta keys whose values do not hold personal data.', 'agent-abilities-for-mcp' ) . '</p>'
	);
	wp_add_privacy_policy_content( __( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ), $content );
}

/**
 * AJAX: clear the activity log.
 *
 * @return void
 */
function aafm_ajax_clear_log(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	aafm_clear_activity_log();
	wp_send_json_success();
}

/**
 * Render the page shell + the active tab.
 *
 * @return void
 */
function aafm_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'dashboard'  => __( 'Dashboard', 'agent-abilities-for-mcp' ),
		'connection' => __( 'Connection', 'agent-abilities-for-mcp' ),
		'abilities'  => __( 'Abilities', 'agent-abilities-for-mcp' ),
		'settings'   => __( 'Settings', 'agent-abilities-for-mcp' ),
		'activity'   => __( 'Activity Log', 'agent-abilities-for-mcp' ),
		'help'       => __( 'Help', 'agent-abilities-for-mcp' ),
	);

	// Dashicon per tab, keyed by tab slug — decorative, so the spans are static literals.
	$tab_icons = array(
		'dashboard'  => 'dashicons-dashboard',
		'connection' => 'dashicons-rest-api',
		'abilities'  => 'dashicons-superhero',
		'settings'   => 'dashicons-admin-settings',
		'activity'   => 'dashicons-list-view',
		'help'       => 'dashicons-editor-help',
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing, no state change.
	$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'dashboard';
	if ( ! isset( $tabs[ $active ] ) ) {
		$active = 'dashboard';
	}

	$adapter_version = aafm_loaded_adapter_version();
	if ( null !== $adapter_version ) {
		$pill_class = 'aafm-pill aafm-pill-success';
		$pill_label = __( 'Endpoint live', 'agent-abilities-for-mcp' );
	} else {
		$pill_class = 'aafm-pill aafm-pill-warn';
		$pill_label = __( 'Adapter not loaded', 'agent-abilities-for-mcp' );
	}

	echo '<div class="wrap aafm-wrap">';
	echo '<h1>' . esc_html__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' );
	printf(
		' <span class="aafm-status-pill %1$s">%2$s</span>',
		esc_attr( $pill_class ),
		esc_html( $pill_label )
	);
	echo '</h1>';
	echo '<p class="aafm-page-lede">' . esc_html__( 'Give an AI agent scoped, audited access to this site. Nothing is exposed until you turn it on, and every call is logged.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		$icon = $tab_icons[ $slug ];
		printf(
			'<a href="%s" class="nav-tab %s"><span class="dashicons %s" aria-hidden="true"></span> %s</a>',
			esc_url(
				add_query_arg(
					array(
						'page' => 'agent-abilities-for-mcp',
						'tab'  => $slug,
					),
					admin_url( 'options-general.php' )
				)
			),
			esc_attr( $active === $slug ? 'nav-tab-active' : '' ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}
	echo '</h2>';

	switch ( $active ) {
		case 'connection':
			aafm_render_connection_tab();
			break;
		case 'abilities':
			aafm_render_abilities_tab();
			break;
		case 'settings':
			aafm_render_settings_tab();
			break;
		case 'activity':
			aafm_render_activity_tab();
			break;
		case 'help':
			aafm_render_help_tab();
			break;
		default:
			aafm_render_dashboard_tab();
	}
	echo '</div>';
}

/**
 * The ordered subject sub-tabs for the Abilities tab.
 *
 * Keys are the `subject` slugs each ability declares in the registry; values are the
 * display labels. Order here is the order the sub-tabs render in. A subject is only
 * shown if at least one registered ability claims it, so this map can safely list
 * subjects that have no abilities yet.
 *
 * @return array<string,string>
 */
function aafm_abilities_subjects(): array {
	return array(
		'content'    => __( 'Content', 'agent-abilities-for-mcp' ),
		'taxonomies' => __( 'Taxonomies & Terms', 'agent-abilities-for-mcp' ),
		'comments'   => __( 'Comments', 'agent-abilities-for-mcp' ),
		'users'      => __( 'Users', 'agent-abilities-for-mcp' ),
		'media'      => __( 'Media', 'agent-abilities-for-mcp' ),
		'site'       => __( 'Site & structure', 'agent-abilities-for-mcp' ),
	);
}

/**
 * Render the Abilities tab: subject sub-tabs, each split Reads then Writes, all OFF by default.
 *
 * This is presentation only. Every checkbox across every sub-tab lives inside the one
 * form, so hidden panels still submit — the saved option stays a flat list of enabled
 * ability names regardless of which sub-tab was visible at save time.
 *
 * @return void
 */
function aafm_render_abilities_tab(): void {
	$registry = aafm_get_abilities_registry();
	$enabled  = aafm_get_enabled_abilities();

	// Bucket the registry by subject so each panel only walks its own abilities.
	$by_subject = array();
	foreach ( $registry as $name => $meta ) {
		$subject                  = (string) ( $meta['subject'] ?? '' );
		$by_subject[ $subject ][] = array( 'name' => (string) $name ) + $meta;
	}

	// Keep only subjects that actually have abilities, in the declared order.
	$subjects = array();
	foreach ( aafm_abilities_subjects() as $slug => $label ) {
		if ( ! empty( $by_subject[ $slug ] ) ) {
			$subjects[ $slug ] = $label;
		}
	}

	echo '<form id="aafm-abilities-form" class="aafm-abilities">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	// Sub-tab bar — pill style (.aafm-subtabs); .aafm-subject-tab stays the JS hook the
	// toggle binds to and data-subject is preserved so panel switching keeps working.
	$first = array_key_first( $subjects );
	echo '<div class="aafm-subtabs aafm-subject-tabs" role="tablist">';
	foreach ( $subjects as $slug => $label ) {
		$is_active = ( $slug === $first );
		printf(
			'<button type="button" class="aafm-subject-tab%1$s" role="tab" aria-selected="%2$s" data-subject="%3$s">%4$s</button>',
			$is_active ? ' is-active' : '',
			$is_active ? 'true' : 'false',
			esc_attr( $slug ),
			esc_html( $label )
		);
	}
	echo '</div>';

	$groups = array(
		'reads'  => __( 'Reads', 'agent-abilities-for-mcp' ),
		'writes' => __( 'Writes', 'agent-abilities-for-mcp' ),
	);

	$disclosures = aafm_ability_disclosures();

	foreach ( $subjects as $slug => $label ) {
		$is_active = ( $slug === $first );
		printf(
			'<div class="aafm-subject-panel" data-subject="%1$s" role="tabpanel"%2$s>',
			esc_attr( $slug ),
			$is_active ? '' : ' hidden'
		);

		// Per-subject count badge: how many of this subject's abilities are enabled.
		$subject_total   = count( $by_subject[ $slug ] );
		$subject_enabled = 0;
		foreach ( $by_subject[ $slug ] as $ability ) {
			if ( in_array( (string) $ability['name'], $enabled, true ) ) {
				++$subject_enabled;
			}
		}
		printf(
			'<p class="aafm-subject-heading"><span class="aafm-count-badge">%1$s / %2$s</span> %3$s</p>',
			esc_html( (string) $subject_enabled ),
			esc_html( (string) $subject_total ),
			esc_html__( 'enabled', 'agent-abilities-for-mcp' )
		);

		if ( 'content' === $slug ) {
			aafm_render_post_types_selector();
		}

		foreach ( $groups as $group => $heading ) {
			$rows = array();
			foreach ( $by_subject[ $slug ] as $ability ) {
				if ( ( $ability['group'] ?? '' ) === $group ) {
					$rows[] = $ability;
				}
			}
			if ( empty( $rows ) ) {
				continue;
			}

			// Per-group enabled count for the group head.
			$group_enabled = 0;
			foreach ( $rows as $ability ) {
				if ( in_array( (string) $ability['name'], $enabled, true ) ) {
					++$group_enabled;
				}
			}

			// Group head: the Reads/Writes heading plus an enabled-over-total count. The bare
			// >Reads< / >Writes< text the panel-structure test keys off lives in this <h3>.
			printf(
				'<div class="aafm-ability-group-head"><h3>%1$s</h3><span class="aafm-count-badge">%2$s / %3$s</span></div>',
				esc_html( $heading ),
				esc_html( (string) $group_enabled ),
				esc_html( (string) count( $rows ) )
			);

			echo '<div class="aafm-card aafm-ability-list">';
			foreach ( $rows as $ability ) {
				$name = (string) $ability['name'];
				$risk = (string) ( $ability['risk'] ?? 'read' );
				$hint = (string) ( $disclosures[ $name ] ?? ( $ability['description'] ?? '' ) );

				// Toggle switch wraps the checkbox. The <input> keeps its exact name/value/checked()
				// contract — the save handler and its tests bind to that, not to this markup.
				echo '<div class="aafm-ability-row">';
				printf(
					'<label class="aafm-switch"><input type="checkbox" name="aafm_abilities[]" value="%1$s" %2$s><span class="aafm-switch-track"></span></label>',
					esc_attr( $name ),
					checked( in_array( $name, $enabled, true ), true, false )
				);

				echo '<div class="aafm-ability-main"><div class="aafm-ability-title">';
				printf(
					'<h4>%1$s</h4><span class="aafm-badge aafm-badge-%2$s">%2$s</span>',
					esc_html( (string) ( $ability['label'] ?? $name ) ),
					esc_attr( $risk )
				);

				// Read-only badge only on read-risk rows; never on write/destructive. The read-only
				// state is derived from risk === 'read' because the registry entries this UI walks do
				// not carry an annotations.readonly field — that flag lives only in each ability's MCP
				// arg-builder definition, not in the catalog. So at render time, risk === 'read' is the
				// authoritative read-only signal.
				if ( 'read' === $risk ) {
					echo ' <span class="aafm-badge aafm-badge-readonly aafm-readonly-badge">' . esc_html__( 'read-only', 'agent-abilities-for-mcp' ) . '</span>';
				}

				printf(
					'</div><p class="aafm-ability-hint">%1$s</p></div></div>',
					esc_html( $hint )
				);
			}
			echo '</div>';
		}

		// Rendered after the ability tables as a layout choice — the meta selector belongs below
		// the abilities it governs. No test depends on this placement: the panel-structure test
		// slices to the next subject panel (or the form's save status), not a bare </div>.
		if ( 'content' === $slug ) {
			aafm_render_meta_keys_selector();
		}

		echo '</div>';
	}

	echo '<div class="aafm-savebar"><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></div>';
	echo '</form>';

	// Future: per-connection / per-client ability allowlist scoping is a separate roadmapped
	// feature — it would filter $enabled per principal here rather than at render time.
}

/**
 * Render the "Exposed content types" opt-in selector inside the Content sub-tab.
 *
 * Lists every eligible (public, non-internal) CPT except post/page (always-on). Each row
 * names the exact fields the agent can read and flags read-only (non map_meta_cap) types,
 * so the operator opts in informed. Saved via the aafm_save_post_types AJAX action; the
 * stored option is always re-floored on read, so the UI is a convenience, not the gate.
 *
 * @return void
 */
function aafm_render_post_types_selector(): void {
	$eligible = array_values( array_diff( aafm_eligible_post_types(), array( 'post', 'page' ) ) );
	$allowed  = aafm_allowed_post_types();

	if ( empty( $eligible ) ) {
		echo '<div class="aafm-card aafm-card-pad">';
		echo '<h3>' . esc_html__( 'Exposed content types', 'agent-abilities-for-mcp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Posts and pages are always available. Any custom content type is off until you turn it on here. The agent can read only these fields of an exposed type: title, slug, excerpt, status, link, dates, author id.', 'agent-abilities-for-mcp' ) . '</p>';
		aafm_render_notice( 'info', __( 'No custom content types on this site are eligible to expose. Only public, non-internal types can be offered here.', 'agent-abilities-for-mcp' ) );
		echo '</div>';
		return;
	}

	// The selector is a plain <div> (never a nested <form>): only the outer abilities <form>
	// may open a form here, and the save control below is a type="button" the JS binds to.
	echo '<div id="aafm-post-types-form" class="aafm-card aafm-card-pad aafm-post-types">';
	echo '<h3>' . esc_html__( 'Exposed content types', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'Posts and pages are always available. Any custom content type is off until you turn it on here. The agent can read only these fields of an exposed type: title, slug, excerpt, status, link, dates, author id.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<table class="widefat striped aafm-post-types-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Expose', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Type', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Writes', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'REST', 'agent-abilities-for-mcp' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $eligible as $type ) {
		$obj    = get_post_type_object( $type );
		$label  = $obj instanceof WP_Post_Type ? $obj->labels->singular_name : $type;
		$caps   = aafm_type_caps( $type );
		$mapped = $caps['mapped'];
		$rest   = $obj instanceof WP_Post_Type && $obj->show_in_rest;

		printf(
			'<tr><td><label class="aafm-switch"><input type="checkbox" name="aafm_post_types[]" value="%1$s" %2$s><span class="aafm-switch-track"></span></label></td><td><strong>%3$s</strong> <code>%4$s</code></td><td>%5$s</td><td>%6$s</td></tr>',
			esc_attr( $type ),
			checked( in_array( $type, $allowed, true ), true, false ),
			esc_html( (string) $label ),
			esc_html( $type ),
			$mapped
				? esc_html__( 'Allowed', 'agent-abilities-for-mcp' )
				: '<span class="aafm-badge aafm-badge-read">' . esc_html__( 'read-only — writes need map_meta_cap', 'agent-abilities-for-mcp' ) . '</span>',
			$rest ? esc_html__( 'yes', 'agent-abilities-for-mcp' ) : esc_html__( 'no', 'agent-abilities-for-mcp' )
		);
	}

	echo '</tbody></table>';
	aafm_render_notice( 'warning', __( 'Exposed types are still gated by that type\'s capabilities and your low-privilege agent user. Only expose types whose title, slug, and excerpt are not sensitive — for example, a type that stores a person\'s name in the title would make that name readable.', 'agent-abilities-for-mcp' ) );
	echo '<p><button type="button" id="aafm-post-types-save" class="button button-primary">' . esc_html__( 'Save content types', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-post-types-status" aria-live="polite"></span></p>';
	echo '</div>';
}

/**
 * Render the "Exposed meta keys" opt-in selector inside the Content sub-tab.
 *
 * One key per line in the textarea is the allowlist; chips below offer the meta keys
 * actually detected on the exposed types as one-click adds. Saved via the
 * aafm_save_meta_keys AJAX action; the stored allowlist is always re-floored against the
 * hard-block on read, so this UI is a convenience, not the gate. It mirrors the post-types
 * selector exactly: a plain <div> (never a nested <form>) with a type="button" save, so the
 * one outer abilities <form> is never closed early.
 *
 * @return void
 */
function aafm_render_meta_keys_selector(): void {
	$allowed  = aafm_allowed_meta_keys();
	$detected = aafm_detected_meta_keys();

	// Mirrors the post-types selector: a plain <div> (never a nested <form>) with a
	// type="button" save, so the one outer abilities <form> is never closed early.
	echo '<div id="aafm-meta-keys-form" class="aafm-card aafm-card-pad aafm-meta-keys">';
	echo '<h3>' . esc_html__( 'Exposed meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'One meta key per line. These are the only meta keys an agent can read or write on a post it can already edit. Everything else stays hidden.', 'agent-abilities-for-mcp' ) . '</p>';
	aafm_render_notice( 'warning', __( 'Meta can hold private data. Only expose keys whose values are safe for an agent to read and write. Protected keys (anything starting with an underscore) and authentication keys are blocked for good and can\'t be added.', 'agent-abilities-for-mcp' ) );

	printf(
		'<textarea name="aafm_meta_keys" rows="6" class="large-text code">%s</textarea>',
		esc_textarea( implode( "\n", $allowed ) )
	);

	echo '<p class="description">' . esc_html__( 'Detected on your exposed types', 'agent-abilities-for-mcp' ) . '</p>';
	if ( empty( $detected ) ) {
		echo '<p class="description">' . esc_html__( 'Nothing detected yet on the types you expose.', 'agent-abilities-for-mcp' ) . '</p>';
	} else {
		echo '<div class="aafm-meta-chips">';
		foreach ( $detected as $key ) {
			printf(
				'<button type="button" class="button aafm-meta-chip" data-key="%1$s">%2$s</button>',
				esc_attr( $key ),
				esc_html( $key )
			);
		}
		echo '</div>';
	}

	echo '<p><button type="button" id="aafm-meta-keys-save" class="button button-primary">' . esc_html__( 'Save meta keys', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-meta-keys-status" aria-live="polite"></span></p>';
	echo '</div>';
}

/**
 * Render the Activity Log tab (includes denials and errors).
 *
 * Every cell renders stored audit data, so each value is escaped on output. The log
 * only ever holds argument KEYS (never values) and a REMOTE_ADDR source IP, so there is
 * no PII to redact here beyond standard escaping.
 *
 * @return void
 */
function aafm_render_activity_tab(): void {
	$rows = aafm_query_activity( array( 'per_page' => 100 ) );

	echo '<div class="aafm-activity">';
	wp_nonce_field( 'aafm_admin', 'aafm_log_nonce' );

	// Presentational status filter — static segmented control, no client-side wiring yet.
	echo '<div class="aafm-activity-toolbar">';
	echo '<div class="aafm-seg" role="group" aria-label="' . esc_attr__( 'Filter by status', 'agent-abilities-for-mcp' ) . '">';
	echo '<button type="button" class="aafm-seg-btn is-active on" data-filter="all">' . esc_html__( 'All', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-seg-btn" data-filter="success">' . esc_html__( 'Success', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-seg-btn" data-filter="error">' . esc_html__( 'Errors', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-seg-btn" data-filter="denied">' . esc_html__( 'Denied', 'agent-abilities-for-mcp' ) . '</button>';
	echo '</div>';
	echo '<button type="button" class="button" id="aafm-clear-log">' . esc_html__( 'Clear log', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-clear-status" aria-live="polite"></span>';
	echo '</div>';

	echo '<table class="widefat striped aafm-log-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Time (UTC)', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Principal', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Ability', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Arg keys', 'agent-abilities-for-mcp' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( empty( $rows ) ) {
		echo '<tr><td colspan="5">' . esc_html__( 'No activity recorded yet.', 'agent-abilities-for-mcp' ) . '</td></tr>';
	}
	// Map each log status to a pill variant; the status word stays visible (never colour-only).
	$status_variants = array(
		'success' => 'success',
		'error'   => 'danger',
		'denied'  => 'warn',
		'started' => 'neutral',
	);
	foreach ( $rows as $row ) {
		$status  = (string) ( $row['status'] ?? '' );
		$variant = $status_variants[ $status ] ?? 'neutral';
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td><span class="aafm-pill aafm-pill-%4$s aafm-status aafm-status-%5$s">%6$s</span></td><td>%7$s</td></tr>',
			esc_html( (string) ( $row['created_at'] ?? '' ) ),
			esc_html( (string) ( $row['principal_login'] ?? '' ) . ' (#' . (int) ( $row['principal_user_id'] ?? 0 ) . ')' ),
			esc_html( (string) ( $row['ability'] ?? '' ) ),
			esc_attr( $variant ),
			esc_attr( $status ),
			esc_html( $status ),
			esc_html( (string) ( $row['arg_keys'] ?? '' ) )
		);
	}
	echo '</tbody></table>';
	echo '</div>';
}

/**
 * Render a single troubleshooting entry as a native <details> accordion.
 *
 * The question (summary) and body are pre-built, escaped HTML fragments. Bodies may
 * carry inline <code>, <p>, <ul>/<li>, <strong>, and <a> built by the caller — each
 * passed through wp_kses() with a tight allowed-tags list so nothing else slips in.
 *
 * @param string $summary Plain-text question shown in the <summary>.
 * @param string $body    Pre-escaped HTML body (already run through wp_kses by caller).
 * @return void
 */
function aafm_render_help_entry( string $summary, string $body ): void {
	echo '<details class="aafm-help-entry"><summary><span class="dashicons dashicons-editor-help" aria-hidden="true"></span>' . esc_html( $summary ) . '</summary><div class="aafm-help-body">';
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is built locally and run through wp_kses by the caller.
	echo '</div></details>';
}

/**
 * Render a copyable single-line code snippet (reuses the .aafm-copy / data-copy JS).
 *
 * @param string $code The exact code line to display and copy.
 * @return string Escaped HTML.
 */
function aafm_help_copy_line( string $code ): string {
	return sprintf(
		'<div class="aafm-help-copy-line"><code>%1$s</code> <button type="button" class="button button-small aafm-copy" data-copy="%2$s">%3$s</button></div>',
		esc_html( $code ),
		esc_attr( $code ),
		esc_html__( 'Copy', 'agent-abilities-for-mcp' )
	);
}

/**
 * Render the Help tab: a site-admin troubleshooting reference.
 *
 * This is a backend-findable support page, not developer/CI documentation. Issues are
 * grouped into headed sections; each is a native <details>/<summary> accordion so the
 * page stays scannable with no new JS. Every dynamic string is escaped, and inline
 * markup is whitelisted through wp_kses().
 *
 * @return void
 */
function aafm_render_help_tab(): void {
	// Tight allow-lists for the inline markup used inside accordion bodies.
	$inline = array(
		'p'      => array(),
		'code'   => array(),
		'strong' => array(),
		'em'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'   => array(),
			'target' => array(),
			'rel'    => array(),
		),
		'div'    => array( 'class' => array() ),
		'button' => array(
			'type'      => array(),
			'class'     => array(),
			'data-copy' => array(),
		),
	);

	echo '<div class="aafm-help">';

	echo '<p class="description aafm-help-intro">' . esc_html__( 'Common connection and permission problems, with the fix for each. Cross-references the Connection tab where a built-in check or generated config already covers the case.', 'agent-abilities-for-mcp' ) . '</p>';

	// Section 1 — Connecting.
	echo '<div class="aafm-acc-group">';
	echo '<h2>' . esc_html__( 'Connecting', 'agent-abilities-for-mcp' ) . '</h2>';

	// 1. Client won't connect / endpoint unreachable.
	aafm_render_help_entry(
		__( 'My client won\'t connect, or the endpoint looks unreachable', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Start on the Connection tab: run Step 3 "Check the endpoint is reachable". If that fails, open Diagnostics on the same tab and confirm "MCP adapter active and compatible" and "MCP REST endpoint registered" both show green.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'The endpoint URL depends on your permalink mode. With pretty permalinks it is the /wp-json/ form; with plain permalinks it is the index.php?rest_route= form. Always copy whatever the Connection tab shows under "Endpoint" rather than typing it by hand:', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Pretty:', 'agent-abilities-for-mcp' ) . '</strong> <code>/wp-json/agent-abilities-for-mcp/mcp</code></li>'
			. '<li><strong>' . esc_html__( 'Plain:', 'agent-abilities-for-mcp' ) . '</strong> <code>index.php?rest_route=/agent-abilities-for-mcp/mcp</code></li>'
			. '</ul>',
			$inline
		)
	);

	// 4. Windows: client config won't start.
	aafm_render_help_entry(
		__( 'The client connects but the AI backend gets blocked (403 / 406 / 429)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'This is the most common failure, and it is not an auth problem: the JSON-RPC POST never reaches WordPress at all. A CDN, WAF, or managed-host security rule sees automated traffic and rejects it before PHP runs, so you get a 403 (blocked), 406 (request looks like a bot), or 429 (rate limited) instead of a real MCP reply.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p><strong>' . esc_html__( 'Cloudflare:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'turn off "Block AI Bots" / Bot Fight Mode for this site, and add a WAF skip (allow) rule for the MCP route so it is never challenged or blocked. If the site is behind Cloudflare Zero Trust / Access, exempt the route there too.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p><strong>' . esc_html__( 'ModSecurity / managed-host rules:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'a generic rule returning 406 or 429 on POSTs from HTTP libraries is common on managed WordPress hosts. Ask the host to allow the MCP route, or add the path to the firewall allowlist.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Add the allow / skip rule for whichever endpoint form your site uses (copy the exact one from the Connection tab):', 'agent-abilities-for-mcp' ) . '</p>'
				. '<ul>'
				. '<li><strong>' . esc_html__( 'Pretty:', 'agent-abilities-for-mcp' ) . '</strong> <code>/wp-json/agent-abilities-for-mcp/*</code></li>'
				. '<li><strong>' . esc_html__( 'Plain:', 'agent-abilities-for-mcp' ) . '</strong> <code>/index.php?rest_route=/agent-abilities-for-mcp/*</code></li>'
				. '</ul>'
				. '<p>' . esc_html__( 'To confirm it is the edge and not WordPress, run the curl probe below: if curl from your own machine gets a 200 but the AI client still fails, the block is on the proxy or IP path the AI backend uses, not on your endpoint.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

		// CDN / WAF: page or edge cache intercepts the REST route.
		aafm_render_help_entry(
			__( 'A page cache or CDN is intercepting the endpoint', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'Full-page caching (a caching plugin) or edge caching (the CDN) can serve a cached or empty response for the MCP route instead of letting the request hit PHP. The symptom is a stale, blank, or HTML response where JSON-RPC was expected.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Exclude the MCP endpoint path from both full-page cache and edge cache. REST routes are dynamic and must never be cached:', 'agent-abilities-for-mcp' ) . '</p>'
				. '<ul>'
				. '<li><code>/wp-json/agent-abilities-for-mcp/*</code> ' . esc_html__( '(pretty permalinks)', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><code>/index.php?rest_route=/agent-abilities-for-mcp/*</code> ' . esc_html__( '(plain permalinks)', 'agent-abilities-for-mcp' ) . '</li>'
				. '</ul>',
				$inline
			)
		);

		// Connecting: a redirect breaks the POST.
		aafm_render_help_entry(
			__( 'A redirect is breaking the request (trailing slash or http to https)', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'A 301 redirect — adding or removing a trailing slash, or forcing http to https — can drop the POST body or the Authorization header on the way through, so the request that finally reaches WordPress is empty or unauthenticated. This is the request not arriving intact, not a credentials problem.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Use the exact endpoint URL the Connection tab shows, with the right scheme (https) and no extra trailing slash, so no redirect is triggered. If your server force-redirects http to https, make sure the config URL already starts with https so the POST is never redirected.', 'agent-abilities-for-mcp' ) . '</p>',
				$inline
			)
		);

		// Connecting: self-test with curl (verified against a live endpoint: 200/401/403-406-429/404/5xx).
		aafm_render_help_entry(
			__( 'Test the endpoint yourself with curl', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'This one-liner sends a real MCP "initialize" call to your endpoint with the agent user\'s Application Password. It tells you in one shot whether the endpoint is reachable, whether auth works, and — if it fails — which layer to blame. Replace the host, the username, and the Application Password (the password is the one shown once when you created it; keep its spaces):', 'agent-abilities-for-mcp' ) . '</p>'
				. aafm_help_copy_line( 'curl -i -X POST "https://example.com/wp-json/agent-abilities-for-mcp/mcp" -u "mcp-agent:XXXX XXXX XXXX XXXX XXXX XXXX" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-probe","version":"1.0"}}}\'' )
				. '<p>' . esc_html__( 'If your permalinks are plain, use the index.php form instead:', 'agent-abilities-for-mcp' ) . '</p>'
				. aafm_help_copy_line( 'curl -i -X POST "https://example.com/index.php?rest_route=/agent-abilities-for-mcp/mcp" -u "mcp-agent:XXXX XXXX XXXX XXXX XXXX XXXX" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-probe","version":"1.0"}}}\'' )
				. '<p><strong>' . esc_html__( 'How to read the result (the HTTP status on the first line):', 'agent-abilities-for-mcp' ) . '</strong></p>'
				. '<ul>'
				. '<li><strong>' . esc_html__( '200', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'reachable and authenticated; the body is a JSON-RPC result. Everything is working — if the AI client still fails, the block is on its side (see the 403/406/429 entry above).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '401', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'reached WordPress but auth failed: wrong or expired Application Password, or the Authorization header is being stripped (see the Authentication section).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '403 / 406 / 429', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'a WAF, CDN, or host security rule is blocking the request before WordPress (see the 403/406/429 entry above).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '404', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'the route is not registered for this URL: flush permalinks (Settings → Permalinks → Save) and confirm you copied the endpoint exactly from the Connection tab.', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '5xx', 'agent-abilities-for-mcp' ) . '</strong> — ' . esc_html__( 'a server-side error: check your PHP error log and the host status.', 'agent-abilities-for-mcp' ) . '</li>'
				. '</ul>',
				$inline
			)
		);

		// 4. Windows: client config won't start.
		aafm_render_help_entry(
			__( 'Windows: my client config won\'t start', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'Windows MCP clients cannot spawn the npx shim by its name alone. The launcher has to be wrapped so the command resolves:', 'agent-abilities-for-mcp' ) . ' <code>cmd /c npx …</code></p>'
				. '<p>' . esc_html__( 'You do not need to hand-edit anything — switch to the "Windows" tab in Connection → Step 2 and copy the config it generates. It already wraps the launcher correctly.', 'agent-abilities-for-mcp' ) . '</p>',
				$inline
			)
		);

	// 5. Local / staging won't connect (self-signed cert).
	aafm_render_help_entry(
		__( 'My local or staging site won\'t connect (self-signed certificate)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Local stacks (DDEV, Local, Valet) serve a certificate Node does not trust, so the proxy refuses the TLS handshake. For local testing only, you can tell Node to accept it. The Connection tab already adds this for you when it detects a local site.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Quick (least safe) — add this to the config env block:', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( '"NODE_TLS_REJECT_UNAUTHORIZED": "0"' )
			. '<p>' . esc_html__( 'Better — point Node at your local CA instead of disabling verification entirely (for example mkcert\'s rootCA.pem):', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( '"NODE_EXTRA_CA_CERTS": "/path/to/rootCA.pem"' )
			. aafm_help_copy_line( '"NODE_USE_SYSTEM_CA": "1"' )
			. '<p><strong>' . esc_html__( 'Never use any of these on a production site.', 'agent-abilities-for-mcp' ) . '</strong></p>',
			$inline
		)
	);

	echo '</div>';

	// Section 2 — Authentication.
	echo '<div class="aafm-acc-group">';
	echo '<h2>' . esc_html__( 'Authentication', 'agent-abilities-for-mcp' ) . '</h2>';

	// 2. Authorization header diagnostic fails.
	aafm_render_help_entry(
		__( 'The "Authorization header reaches WordPress" diagnostic fails', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Some hosts and reverse proxies strip the Authorization header before it reaches PHP, so the Application Password never arrives and auth silently fails. Forward the header at the web-server layer.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Apache (.htaccess) — either of these:', 'agent-abilities-for-mcp' ) . '</strong></p>'
			. aafm_help_copy_line( 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' )
			. aafm_help_copy_line( 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]' )
			. '<p><strong>' . esc_html__( 'Nginx / FastCGI:', 'agent-abilities-for-mcp' ) . '</strong></p>'
			. aafm_help_copy_line( 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;' )
			. '<p>' . esc_html__( 'After applying, reload the web server and re-run Connection → Diagnostics.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 3. Application Passwords option missing.
	aafm_render_help_entry(
		__( 'The Application Passwords option is missing from my profile', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'WordPress core only offers Application Passwords over a secure (https) connection. Behind a TLS-terminating proxy or load balancer, WordPress can see the request as plain HTTP even though the browser is on https — so it hides the option.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Fix the proxy or HTTPS headers (or your site URL) so WordPress correctly detects https. Forwarding the standard X-Forwarded-Proto header from the proxy is the usual fix.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Do not enable Application Passwords over genuine plaintext HTTP in production — the credential would travel unencrypted.', 'agent-abilities-for-mcp' ) . '</strong></p>',
			$inline
		)
	);

	echo '</div>';

	// Section 3 — Abilities & permissions.
	echo '<div class="aafm-acc-group">';
	echo '<h2>' . esc_html__( 'Abilities & permissions', 'agent-abilities-for-mcp' ) . '</h2>';

	// 6. Agent sees fewer tools than expected.
	aafm_render_help_entry(
		__( 'My agent sees fewer tools than I expected', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'This is intentional least-privilege behaviour. Each connection is filtered by the agent user\'s own capabilities, so the agent only ever sees abilities its role allows: reads need read capabilities; writes need the matching edit, publish, moderate, or manage capabilities.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'To expose more tools, grant the agent user the role or capabilities those abilities require. Granting more, of course, widens what the agent can do — keep it to what the agent genuinely needs.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 7. Ability enabled but agent still can't use it.
	aafm_render_help_entry(
		__( 'An ability is enabled but the agent still can\'t use it', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Two things both have to be true for an ability to work:', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li>' . esc_html__( 'The ability is turned ON on the Abilities tab. Everything is OFF by default.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li>' . esc_html__( 'The agent user holds the WordPress capability that ability requires.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'If the toggle is on but the agent still gets refused, it is almost always the capability. Check the agent user\'s role.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	echo '</div>';

	// Section 4 — Clients, privacy & limits.
	echo '<div class="aafm-acc-group">';
	echo '<h2>' . esc_html__( 'Clients, privacy & limits', 'agent-abilities-for-mcp' ) . '</h2>';

	// 8. Which AI clients work, and how to set each one up.
	aafm_render_help_entry(
		__( 'Which AI clients work, and how do I set each one up?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Claude Desktop, Claude Code, Cursor, and Windsurf all connect the same way: through the @automattic/mcp-wordpress-remote proxy, which is the package the Connection tab puts in the generated config. The proxy reads your endpoint URL and the agent user\'s Application Password and builds the auth itself.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Claude Desktop:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'paste the generated block into its claude_desktop_config.json (Settings → Developer → Edit Config) and restart the app.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Claude Code:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'add the same server to its MCP config (claude mcp add, or the .mcp.json in your project).', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Cursor:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'add the block to its MCP config (~/.cursor/mcp.json, or Settings → MCP) and reload.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Windsurf:', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'add it under its MCP / plugins config (mcp_config.json) and refresh the server list.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'Copy the config straight from Connection → Step 2 — do not hand-build it. On Windows, use that tab\'s "Windows" view (it wraps the launcher in cmd /c); for a local or staging site, it adds the certificate handling. Both are covered in the Connecting section above.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'The hosted ChatGPT and Gemini apps cannot connect in this release.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Their web connectors expect a native streamable HTTP/SSE MCP transport, which the bundled adapter does not serve yet, so they cannot reach the proxy the way the clients above do. Gemini CLI is the exception: it runs as a proxy client, like Claude Code, so it works today, and the Connection tab has a ready-made quickstart for it.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 8b. Plain-language security model (the differentiator).
	aafm_render_help_entry(
		__( 'What can and can\'t this plugin do? (the security model in plain language)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'The plugin is built to be safe by default. In plain terms:', 'agent-abilities-for-mcp' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'No external calls.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'It never phones home. Your credentials and your content never leave the site — the AI client connects in to you, not the other way round.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'A dedicated low-privilege user.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'The agent authenticates as its own separate WordPress user via an Application Password — not as you, and not as an administrator. You choose that user\'s role, so you set its ceiling.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Two locks on every ability.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'An ability works only if you explicitly enabled it on the Abilities tab AND the agent user\'s capabilities allow it. The default is nothing enabled — the agent starts with zero abilities until you turn them on.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Deletes are trash, not destroy.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Delete-style abilities move content to the Trash, where you can restore it; they do not permanently erase it.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Everything is logged, values are not.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Every call — including denied ones — is recorded on the Activity Log tab with the argument KEYS only, never the values. You can see what was attempted without leaking what was in it.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Optional extra guardrails.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'The Settings tab adds a per-minute rate limit, an IP allowlist, a force-to-draft switch, and a maximum title length. All four are off by default, so you turn on only the ones you want.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>',
			$inline
		)
	);

	// 9. Privacy / what gets logged.
	aafm_render_help_entry(
		__( 'What does the plugin log, and does it call out to anything?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'The plugin makes no external calls — nothing about your site or its content is sent anywhere.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'The activity log records only the argument KEYS of each call (never the values) plus the source IP address of the request. You can clear it any time from the Activity Log tab.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// 10. Rate limiting.
	aafm_render_help_entry(
		__( 'Is there rate limiting?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Yes. The Settings tab has a "Rate limit (per minute)" field. Set it to a number and each connection can make at most that many agent calls per minute; set it to 0 to turn the limit off. The cap is counted per agent user, so two connections do not eat into each other\'s budget.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Calls that go over the limit are denied, and each denial is written to the Activity Log like any other blocked call, so you can see when a connection is hitting the ceiling.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);
	echo '</div>';

	echo '</div>';
}
