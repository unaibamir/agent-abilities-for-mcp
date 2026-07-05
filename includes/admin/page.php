<?php
/**
 * Admin settings page: menu, tab routing, Abilities + Activity tabs, AJAX handlers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the admin pages as a dedicated top-level menu, one submenu per tab.
 *
 * @return void
 */
function aafm_register_admin_menu(): void {
	$icon = 'dashicons-superhero';

	add_menu_page(
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		'manage_options',
		'agent-abilities-for-mcp',
		'aafm_render_admin_page',
		$icon,
		80
	);

	// One submenu per tab; the Dashboard submenu reuses the parent slug, the rest carry
	// their tab in the slug so the link is admin.php?page=…&tab=… and the parent page renders.
	foreach ( aafm_admin_tabs() as $slug => $label ) {
		$menu_slug = ( 'dashboard' === $slug )
			? 'agent-abilities-for-mcp'
			: 'agent-abilities-for-mcp&tab=' . $slug;
		add_submenu_page(
			'agent-abilities-for-mcp',
			$label,
			$label,
			'manage_options',
			$menu_slug,
			'aafm_render_admin_page'
		);
	}
}

/**
 * Add quick links to the plugin's row on the Plugins screen.
 *
 * Prepends Getting Started / Abilities / Integrations / Settings before WordPress's own
 * row actions (Deactivate), so the most-used destinations are one click from the list.
 *
 * @param array<string,string> $actions The existing row action links, keyed by handle.
 * @return array<string,string> The links with ours prepended.
 */
function aafm_plugin_action_links( array $actions ): array {
	$base = 'admin.php?page=agent-abilities-for-mcp';

	$links = array(
		'aafm-getting-started' => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( $base ) ),
			esc_html__( 'Getting Started', 'agent-abilities-for-mcp' )
		),
		'aafm-abilities'       => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( $base . '&tab=abilities' ) ),
			esc_html__( 'Abilities', 'agent-abilities-for-mcp' )
		),
		'aafm-integrations'    => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( $base . '&tab=integrations' ) ),
			esc_html__( 'Integrations', 'agent-abilities-for-mcp' )
		),
		'aafm-settings'        => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( $base . '&tab=settings' ) ),
			esc_html__( 'Settings', 'agent-abilities-for-mcp' )
		),
	);

	return array_merge( $links, $actions );
}

/**
 * Highlight the submenu item for the active tab.
 *
 * WordPress matches the current submenu only on the `page` query var, so every tab
 * would otherwise highlight the first item (Dashboard). This returns the same slug
 * `add_submenu_page()` registered for the active tab so the correct item is marked
 * current; for the dashboard, an absent tab, or an unknown tab it returns the bare
 * parent slug.
 *
 * @param string $submenu_file The submenu file WordPress is about to mark current.
 * @return string Tab-aware slug on our page, otherwise the input unchanged.
 */
function aafm_highlight_tab_submenu( $submenu_file ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only menu highlighting, no state change.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	if ( 'agent-abilities-for-mcp' !== $page ) {
		return $submenu_file;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only menu highlighting, no state change.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'dashboard';
	if ( 'dashboard' === $tab || ! in_array( $tab, array_keys( aafm_admin_tabs() ), true ) ) {
		return 'agent-abilities-for-mcp';
	}

	return 'agent-abilities-for-mcp&tab=' . $tab;
}

/**
 * Enqueue admin assets only on our top-level admin page.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function aafm_enqueue_admin_assets( string $hook ): void {
	if ( 'toplevel_page_agent-abilities-for-mcp' !== $hook ) {
		return;
	}
	// Use filemtime() as the cache-buster so ?ver= changes whenever the file changes,
	// defeating both the browser cache and any CDN/Cloudflare edge cache. A fixed
	// AAFM_VERSION string never changes between plugin updates (we stay on 1.0.0), so
	// old asset bytes stay cached across redeploys without this. Fall back to AAFM_VERSION
	// when filemtime() returns false (opcache edge or path anomaly).
	$css_path = AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.css';
	$js_path  = AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js';
	$css_ver  = filemtime( $css_path );
	$js_ver   = filemtime( $js_path );
	wp_enqueue_style( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.css', array(), (string) ( false !== $css_ver ? $css_ver : AAFM_VERSION ) );
	wp_enqueue_script( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.js', array(), (string) ( false !== $js_ver ? $js_ver : AAFM_VERSION ), true );
	wp_localize_script(
		'aafm-admin',
		'aafmAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aafm_admin' ),
			'i18n'    => array(
				'quickstartsShow'          => __( 'Show config for a specific client', 'agent-abilities-for-mcp' ),
				'quickstartsHide'          => __( 'Hide client configs', 'agent-abilities-for-mcp' ),
				'saving'                   => __( 'Saving…', 'agent-abilities-for-mcp' ),
				'saved'                    => __( 'Saved', 'agent-abilities-for-mcp' ),
				'errorSaving'              => __( 'Error saving', 'agent-abilities-for-mcp' ),
				'creating'                 => __( 'Creating…', 'agent-abilities-for-mcp' ),
				'checking'                 => __( 'Checking…', 'agent-abilities-for-mcp' ),
				'cleared'                  => __( 'Cleared', 'agent-abilities-for-mcp' ),
				'error'                    => __( 'Error', 'agent-abilities-for-mcp' ),
				'requestFailed'            => __( 'Request failed.', 'agent-abilities-for-mcp' ),
				'settingsNotSaved'         => __( 'Could not save - your previous settings are still in effect.', 'agent-abilities-for-mcp' ),
				'allowlistEmptied'         => __( 'Saved, but every line was dropped as invalid. The allowlist is now empty, so connections from anywhere are allowed.', 'agent-abilities-for-mcp' ),
				/* translators: %d: number of allowlist lines that were dropped as invalid. */
				'allowlistDropped'         => __( 'Saved. Dropped %d line(s) that were not a valid IP or range - check the allowlist.', 'agent-abilities-for-mcp' ),
				/* translators: %d: the new agent user's numeric ID. */
				'userCreated'              => __( 'Created user #%d. Now create its Application Password under Users → Profile.', 'agent-abilities-for-mcp' ),
				'editUser'                 => __( 'Edit user', 'agent-abilities-for-mcp' ),
				/* translators: 1: current page number, 2: total number of pages. */
				'pagerStatus'              => __( 'Page %1$s of %2$s', 'agent-abilities-for-mcp' ),
				'loadingPage'              => __( 'Loading…', 'agent-abilities-for-mcp' ),
				'noActivity'               => __( 'No activity recorded yet.', 'agent-abilities-for-mcp' ),
				/* translators: 1: HTTP status code, 2: number of tools visible in the admin view. */
				'connectionOk'             => __( 'Reachable (HTTP %1$s) - %2$s tool(s) in your admin view.', 'agent-abilities-for-mcp' ),
				/* translators: %s: HTTP status code returned by the endpoint. */
				'connectionNoTools'        => __( 'Endpoint answered HTTP %s but did not return a tool list.', 'agent-abilities-for-mcp' ),
				/* translators: %s: error message returned by the server. */
				'errorWithMessage'         => __( 'Error: %s', 'agent-abilities-for-mcp' ),
				'errorUnknown'             => __( 'unknown', 'agent-abilities-for-mcp' ),
				'copyCopied'               => __( 'Copied', 'agent-abilities-for-mcp' ),
				'copyFallback'             => __( 'Press Ctrl+C', 'agent-abilities-for-mcp' ),
				'resetConfirm'             => __( 'Reset the plugin to defaults? This clears every setting, your enabled abilities, and the whole activity log. Your agent user and any content it created are kept. This cannot be undone.', 'agent-abilities-for-mcp' ),
				'resetWorking'             => __( 'Resetting…', 'agent-abilities-for-mcp' ),
				'resetDone'                => __( 'Reset. Reloading…', 'agent-abilities-for-mcp' ),
				'resetFailed'              => __( 'Reset failed.', 'agent-abilities-for-mcp' ),
				'sectionToggleConfirm'     => __( 'This section includes destructive abilities (trash/delete). Enable all of them?', 'agent-abilities-for-mcp' ),
				'integrationToggleConfirm' => __( 'These abilities can read and change personal data such as customer details and orders. Turn all of them on?', 'agent-abilities-for-mcp' ),
				'revokeClientConfirm'      => __( 'Revoke this client? It is turned off and its active sessions end right away.', 'agent-abilities-for-mcp' ),
				'revokeGrantConfirm'       => __( 'Revoke this grant? The user will have to approve again to reconnect.', 'agent-abilities-for-mcp' ),
				'revokeFailed'             => __( 'Could not revoke. Please try again.', 'agent-abilities-for-mcp' ),
				'statusRevoked'            => __( 'Revoked', 'agent-abilities-for-mcp' ),
			),
		)
	);
}

/**
 * Sanitize posted ability toggles down to known registry keys.
 *
 * The result is intersected with the live registry, so a stale, unknown, or smuggled
 * key can never enable anything - only abilities that actually exist are honored.
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
 * Resolve the final enabled-abilities list for a scoped save.
 *
 * A scoped form (the Integrations tab) only renders toggles for the subjects it owns, so it must
 * NOT replace the whole enabled option. Instead of trusting client-side hidden inputs to carry the
 * off-tab abilities forward, this merges server-side: every persisted ability whose subject is
 * OUTSIDE the posted scope is preserved verbatim, and only the in-scope abilities are taken from
 * the POST. A tampered or missing hidden input for an off-tab ability therefore cannot flip it.
 *
 * When no scope is posted (the Abilities tab), the posted list replaces the option in full - the
 * unchanged legacy behavior.
 *
 * @param array<string,mixed> $posted The $_POST payload, already unslashed by the caller.
 * @return array<int,string> The enabled-abilities list to persist.
 */
function aafm_resolve_scoped_enabled_input( array $posted ): array {
	$posted_enabled = aafm_sanitize_enabled_input( $posted );

	// No scope marker: full replace (Abilities tab).
	if ( ! isset( $posted['aafm_scope'] ) || ! is_array( $posted['aafm_scope'] ) ) {
		return $posted_enabled;
	}

	$scope = array_map(
		static fn( $s ): string => sanitize_key( (string) $s ),
		$posted['aafm_scope']
	);

	$registry = aafm_get_abilities_registry();
	$known    = array_keys( $registry );

	// Persisted abilities OUTSIDE the posted scope are kept from the server, not the POST.
	$preserved = array();
	foreach ( aafm_get_enabled_abilities() as $name ) {
		if ( ! in_array( $name, $known, true ) ) {
			continue; // Drop stale/removed abilities.
		}
		$subject = (string) ( $registry[ $name ]['subject'] ?? '' );
		if ( ! in_array( $subject, $scope, true ) ) {
			$preserved[] = $name;
		}
	}

	// In-scope abilities come from the POST (only those whose subject is in the scope).
	$in_scope = array();
	foreach ( $posted_enabled as $name ) {
		$subject = (string) ( $registry[ $name ]['subject'] ?? '' );
		if ( in_array( $subject, $scope, true ) ) {
			$in_scope[] = $name;
		}
	}

	return array_values( array_unique( array_merge( $preserved, $in_scope ) ) );
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
	$enabled = aafm_resolve_scoped_enabled_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
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
 * Parse the denied-post-meta textarea into a clean list.
 *
 * Mirrors aafm_sanitize_allowed_meta_keys_input() but for the DENY list: it KEEPS the `*`
 * wildcard sentinel (deny-all) and does NOT strip hard-blocked keys - denying an already
 * hard-blocked key is a harmless no-op, and the deny list must be able to name anything an
 * admin wants refused. Splits on newlines, trims, sanitize_text_field (never sanitize_key,
 * which would strip `*`), drops empties, and de-duplicates.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return list<string>
 */
function aafm_sanitize_denied_meta_keys_input( array $posted ): array {
	$raw  = isset( $posted['aafm_deny_meta_keys'] ) ? (string) $posted['aafm_deny_meta_keys'] : '';
	$keys = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$key = sanitize_text_field( trim( (string) $line ) );
		if ( '' === $key ) {
			continue;
		}
		$keys[] = $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Parse the exposed-user-meta textarea into a clean list.
 *
 * Mirrors the allow-list sanitizer but for user meta and KEEPS the `*` wildcard. Splits on
 * newlines, trims, sanitize_text_field (never sanitize_key, which would strip `*`), drops
 * empties and any hard-blocked user key (best-effort - aafm_allowed_user_meta_keys() re-floors
 * anyway), and de-duplicates.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return list<string>
 */
function aafm_sanitize_exposed_user_meta_keys_input( array $posted ): array {
	$raw  = isset( $posted['aafm_exposed_user_meta_keys'] ) ? (string) $posted['aafm_exposed_user_meta_keys'] : '';
	$keys = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$key = sanitize_text_field( trim( (string) $line ) );
		if ( '' === $key || ( '*' !== $key && aafm_hard_blocked_user_meta_key( $key ) ) ) {
			continue;
		}
		$keys[] = $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Parse the denied-user-meta textarea into a clean list.
 *
 * Like aafm_sanitize_denied_meta_keys_input() but user-scoped: KEEPS `*` (deny-all) and does
 * NOT strip hard-blocked keys. Splits on newlines, trims, sanitize_text_field, drops empties,
 * de-duplicates.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return list<string>
 */
function aafm_sanitize_denied_user_meta_keys_input( array $posted ): array {
	$raw  = isset( $posted['aafm_denied_user_meta_keys'] ) ? (string) $posted['aafm_denied_user_meta_keys'] : '';
	$keys = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$key = sanitize_text_field( trim( (string) $line ) );
		if ( '' === $key ) {
			continue;
		}
		$keys[] = $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Parse the exposed-term-meta textarea into a clean list.
 *
 * Mirrors aafm_sanitize_exposed_user_meta_keys_input() but term-scoped (the term/post-meta
 * hard-block applies). KEEPS the `*` wildcard, splits on newlines, trims, sanitize_text_field
 * (never sanitize_key, which would strip `*`), drops empties and any hard-blocked key
 * (best-effort - aafm_allowed_term_meta_keys() re-floors anyway), and de-duplicates.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return list<string>
 */
function aafm_sanitize_exposed_term_meta_keys_input( array $posted ): array {
	$raw  = isset( $posted['aafm_exposed_term_meta_keys'] ) ? (string) $posted['aafm_exposed_term_meta_keys'] : '';
	$keys = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$key = sanitize_text_field( trim( (string) $line ) );
		if ( '' === $key || ( '*' !== $key && aafm_hard_blocked_meta_key( $key ) ) ) {
			continue;
		}
		$keys[] = $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Parse the denied-term-meta textarea into a clean list.
 *
 * Like aafm_sanitize_denied_user_meta_keys_input() but term-scoped: KEEPS `*` (deny-all) and
 * does NOT strip hard-blocked keys. Splits on newlines, trims, sanitize_text_field, drops
 * empties, de-duplicates.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return list<string>
 */
function aafm_sanitize_denied_term_meta_keys_input( array $posted ): array {
	$raw  = isset( $posted['aafm_denied_term_meta_keys'] ) ? (string) $posted['aafm_denied_term_meta_keys'] : '';
	$keys = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$key = sanitize_text_field( trim( (string) $line ) );
		if ( '' === $key ) {
			continue;
		}
		$keys[] = $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Sample up to 50 distinct, non-hard-blocked meta keys present on posts of the allowlisted
 * types - the "Detected on your exposed types" chip source for the selector.
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
 * AJAX: save BOTH the exposed and denied post-meta lists in one request.
 *
 * Mirrors aafm_ajax_save_user_meta_keys() / aafm_ajax_save_term_meta_keys(): one click, one
 * request, one success/failure verdict for the whole post-meta selector. The exposed list and
 * the deny list are persisted together so the UI can never report "Saved" while one of the two
 * writes silently failed (the prior split-handler design could). The deny field is optional in
 * the payload, so a caller that posts only aafm_meta_keys simply clears the deny list, matching
 * the old standalone behavior.
 *
 * @return void
 */
function aafm_ajax_save_meta_keys(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$keys   = aafm_sanitize_allowed_meta_keys_input( $posted );
	$denied = aafm_sanitize_denied_meta_keys_input( $posted );
	update_option( 'aafm_allowed_meta_keys', $keys );
	update_option( 'aafm_denied_meta_keys', $denied );
	delete_transient( 'aafm_detected_meta_keys' );
	wp_send_json_success(
		array(
			'meta_keys'      => $keys,
			'deny_meta_keys' => $denied,
		)
	);
}

/**
 * AJAX: save the denied-post-meta list on its own.
 *
 * Retained for the registered aafm_save_denied_meta_keys action and any external caller; the
 * admin UI now sends the deny list together with the exposed list through aafm_save_meta_keys
 * (see aafm_ajax_save_meta_keys), so this handler is no longer exercised by the bundled JS.
 *
 * @return void
 */
function aafm_ajax_save_denied_meta_keys(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$keys = aafm_sanitize_denied_meta_keys_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	update_option( 'aafm_denied_meta_keys', $keys );
	wp_send_json_success( array( 'deny_meta_keys' => $keys ) );
}

/**
 * AJAX: save BOTH the exposed and denied user-meta lists in one request.
 *
 * @return void
 */
function aafm_ajax_save_user_meta_keys(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$posted  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$exposed = aafm_sanitize_exposed_user_meta_keys_input( $posted );
	$denied  = aafm_sanitize_denied_user_meta_keys_input( $posted );
	update_option( 'aafm_exposed_user_meta_keys', $exposed );
	update_option( 'aafm_denied_user_meta_keys', $denied );
	wp_send_json_success(
		array(
			'exposed_user_meta_keys' => $exposed,
			'denied_user_meta_keys'  => $denied,
		)
	);
}

/**
 * AJAX: save BOTH the exposed and denied term-meta lists in one request.
 *
 * @return void
 */
function aafm_ajax_save_term_meta_keys(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$posted  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$exposed = aafm_sanitize_exposed_term_meta_keys_input( $posted );
	$denied  = aafm_sanitize_denied_term_meta_keys_input( $posted );
	update_option( 'aafm_exposed_term_meta_keys', $exposed );
	update_option( 'aafm_denied_term_meta_keys', $denied );
	wp_send_json_success(
		array(
			'exposed_term_meta_keys' => $exposed,
			'denied_term_meta_keys'  => $denied,
		)
	);
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
 * AJAX: return one page of activity rows for a given page number and status filter.
 *
 * Server-side paging and filtering so the table never has to load tens of thousands of rows
 * into the DOM. The incoming filter is sanitized against the known set (all|success|error|denied)
 * and mapped to a query status; the page number is clamped to the filtered total. Returns the
 * rendered <tr> HTML (every cell escaped by aafm_activity_rows_html()) plus the paging state the
 * JS needs to update the pager. Read-only - never touches state.
 *
 * @return void
 */
function aafm_ajax_get_log_page(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter'] ) ) : 'all';
	if ( ! in_array( $filter, array( 'all', 'success', 'error', 'denied' ), true ) ) {
		$filter = 'all';
	}
	$status = aafm_activity_filter_status( $filter );

	$per_page    = aafm_activity_page_size();
	$total       = aafm_activity_count_filtered( $status );
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$page = isset( $_POST['page'] ) ? absint( wp_unslash( (string) $_POST['page'] ) ) : 1;
	$page = min( max( 1, $page ), $total_pages );

	$rows = aafm_query_activity(
		array(
			'per_page' => $per_page,
			'page'     => $page,
			'status'   => $status,
		)
	);

	wp_send_json_success(
		array(
			'rows'        => aafm_activity_rows_data( $rows ),
			'page'        => $page,
			'total_pages' => $total_pages,
			'total'       => $total,
			'filter'      => $filter,
		)
	);
}

/**
 * Normalize activity rows to a flat, JSON-safe shape for the client renderer.
 *
 * The JS builds each table cell with textContent (never innerHTML), so it needs plain values,
 * not markup. Only the columns the table shows are exposed; the log holds argument KEYS (never
 * values) plus a REMOTE_ADDR source IP, so there is no PII to strip beyond shaping.
 *
 * @param array<int,array<string,mixed>> $rows Rows from aafm_query_activity().
 * @return array<int,array{time:string,principal:string,ability:string,status:string,variant:string,arg_keys:string}>
 */
function aafm_activity_rows_data( array $rows ): array {
	$status_variants = array(
		'success' => 'success',
		'error'   => 'danger',
		'denied'  => 'warn',
		'started' => 'neutral',
	);

	$out = array();
	foreach ( $rows as $row ) {
		$status = (string) ( $row['status'] ?? '' );
		$out[]  = array(
			'time'      => (string) ( $row['created_at'] ?? '' ),
			'principal' => (string) ( $row['principal_login'] ?? '' ) . ' (#' . (int) ( $row['principal_user_id'] ?? 0 ) . ')',
			'ability'   => (string) ( $row['ability'] ?? '' ),
			'status'    => $status,
			'variant'   => $status_variants[ $status ] ?? 'neutral',
			'arg_keys'  => (string) ( $row['arg_keys'] ?? '' ),
		);
	}
	return $out;
}

/**
 * The admin tab slugs → labels, shared by menu registration and the page renderer.
 *
 * @return array<string,string>
 */
function aafm_admin_tabs(): array {
	return array(
		'dashboard'    => __( 'Dashboard', 'agent-abilities-for-mcp' ),
		'connection'   => __( 'Connection', 'agent-abilities-for-mcp' ),
		'abilities'    => __( 'Abilities', 'agent-abilities-for-mcp' ),
		'integrations' => __( 'Integrations', 'agent-abilities-for-mcp' ),
		'bridge'       => __( 'Abilities from other plugins', 'agent-abilities-for-mcp' ),
		'settings'     => __( 'Settings', 'agent-abilities-for-mcp' ),
		'activity'     => __( 'Activity Log', 'agent-abilities-for-mcp' ),
		'help'         => __( 'Help', 'agent-abilities-for-mcp' ),
	);
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
	$tabs = aafm_admin_tabs();

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

	// Header: title + lede on the left, the status pill on the right (moved out of the h1).
	echo '<div class="aafm-page-head"><div class="title-wrap">';
	echo '<h1>' . esc_html__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '</h1>';
	echo '<p class="aafm-page-lede">' . esc_html__( 'Give an AI agent scoped, audited access to this site. Nothing is exposed until you turn it on, and every call is logged.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div>';
	printf(
		'<span class="aafm-status-pill %1$s">%2$s</span>',
		esc_attr( $pill_class ),
		esc_html( $pill_label )
	);
	echo '</div>';

	// Anchor for core's admin-notice relocation: the <h1> now sits inside
	// .aafm-page-head, so mark where WordPress should drop notices.
	echo '<hr class="wp-header-end">';

	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		echo wp_kses(
			sprintf(
				'<a href="%s" class="nav-tab %s">%s %s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => 'agent-abilities-for-mcp',
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_attr( $active === $slug ? 'nav-tab-active' : '' ),
				aafm_icon( $slug ),
				esc_html( $label )
			),
			aafm_admin_allowed_html()
		);
	}
	echo '</nav>';

	switch ( $active ) {
		case 'connection':
			aafm_render_connection_tab();
			break;
		case 'abilities':
			aafm_render_abilities_tab();
			break;
		case 'integrations':
			aafm_render_integrations_tab();
			break;
		case 'bridge':
			aafm_render_bridge_directory();
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
 * Presentation-only sub-grouping for the single 'site' subject panel.
 *
 * The catalog keeps one 'site' subject (re-subjecting ~28 entries across six files would churn
 * a load-bearing contract the registry, MCP buckets, and tests all assert on). This map is
 * consulted ONLY when rendering the site panel, to split it into readable sub-groups. It never
 * changes any ability's registry subject. Search is mapped here by NAME even though its registry
 * subject is 'content', so the operator finds it where they expect it. Any site-subject ability
 * not listed here falls into a rendered "Other" group, so a future addition is never silently
 * dropped (the AbilitiesSaveTest guard enforces that).
 *
 * @return array<string,array{label:string,abilities:list<string>}> Group slug => label + ability names.
 */
function aafm_site_subgroups(): array {
	return array(
		'site_settings' => array(
			'label'     => __( 'Site settings', 'agent-abilities-for-mcp' ),
			'abilities' => array(
				'aafm/get-site-settings',
				'aafm/update-site-settings',
				'aafm/get-post-types',
				'aafm/get-taxonomies',
				'aafm/get-site-info',
				'aafm/get-activity-log',
			),
		),
		'plugins'       => array(
			'label'     => __( 'Plugins', 'agent-abilities-for-mcp' ),
			'abilities' => array(
				'aafm/list-plugins',
			),
		),
		'themes'        => array(
			'label'     => __( 'Themes & styles', 'agent-abilities-for-mcp' ),
			'abilities' => array(
				'aafm/get-active-theme',
				'aafm/list-themes',
				'aafm/list-templates',
				'aafm/get-template',
				'aafm/update-template',
				'aafm/get-global-styles',
			),
		),
		'blocks'        => array(
			'label'     => __( 'Blocks', 'agent-abilities-for-mcp' ),
			'abilities' => array(
				'aafm/list-blocks',
				'aafm/get-block',
				'aafm/create-block',
				'aafm/update-block',
				'aafm/delete-block',
			),
		),
		'menus'         => array(
			'label'     => __( 'Menus', 'agent-abilities-for-mcp' ),
			'abilities' => array(
				'aafm/list-menus',
				'aafm/get-menu',
				'aafm/list-menu-items',
				'aafm/create-menu',
				'aafm/update-menu',
				'aafm/delete-menu',
				'aafm/create-menu-item',
				'aafm/update-menu-item',
				'aafm/delete-menu-item',
			),
		),
		'search'        => array(
			'label'     => __( 'Search', 'agent-abilities-for-mcp' ),
			'abilities' => array(
				'aafm/search-content',
			),
		),
	);
}

/**
 * Render the Abilities tab: subject sub-tabs, each split Reads then Writes, all OFF by default.
 *
 * This is presentation only. Every checkbox across every sub-tab lives inside the one
 * form, so hidden panels still submit - the saved option stays a flat list of enabled
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

	// Stats box - sits between the page nav and the sub-tabs, reusing the dashboard .aafm-stat
	// markup. Total reads the single source of truth (core + every integration manifest total),
	// the same function the Dashboard uses, so the two tabs can never disagree. Enabled counts
	// what the operator has turned on, labelled "of N".
	$ability_total   = aafm_available_ability_count();
	$ability_enabled = aafm_enabled_ability_count();
	echo '<div class="aafm-stat-grid aafm-abilities-stats">';
	echo '<div class="aafm-stat aafm-stat-abilities">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Total abilities', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic">';
	echo wp_kses( aafm_icon( 'abilities' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '</div>';
	printf( '<div class="stat-value">%s</div>', esc_html( number_format_i18n( $ability_total ) ) );
	echo '</div>';
	echo '<div class="aafm-stat aafm-stat-enabled">';
	echo '<div class="stat-top">';
	echo '<span class="stat-label">' . esc_html__( 'Enabled', 'agent-abilities-for-mcp' ) . '</span>';
	echo '<span class="stat-ic">';
	echo wp_kses( aafm_icon( 'bolt' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '</div>';
	printf(
		'<div class="stat-value">%1$s <small>%2$s</small></div>',
		esc_html( number_format_i18n( $ability_enabled ) ),
		esc_html(
			sprintf(
				/* translators: %d: total number of abilities in the catalog. */
				__( 'of %d', 'agent-abilities-for-mcp' ),
				$ability_total
			)
		)
	);
	echo '</div>';
	echo '</div>'; // .aafm-abilities-stats

	echo '<form id="aafm-abilities-form" class="aafm-abilities">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	// This form and the Integrations tab both save through the same aafm_save_abilities action, but
	// each only renders the toggles for the subjects it owns. Declare the core subjects this form
	// owns via aafm_scope[] so the server preserves every persisted ability OUTSIDE that scope (the
	// integration abilities - WooCommerce, Yoast, ACF) from the stored option instead of treating a
	// save here as a full replace that drops them. Mirrors the Integrations form; resolved by
	// aafm_resolve_scoped_enabled_input(). $subjects is already the core subjects that have abilities.
	foreach ( array_keys( $subjects ) as $scope_subject ) {
		printf(
			'<input type="hidden" name="aafm_scope[]" value="%s">',
			esc_attr( (string) $scope_subject )
		);
	}

	$groups = array(
		'reads'  => __( 'Reads', 'agent-abilities-for-mcp' ),
		'writes' => __( 'Writes', 'agent-abilities-for-mcp' ),
	);

	$disclosures = aafm_ability_disclosures();

	// Expand the subject list into the display tabs that actually render. Every subject maps to one
	// display tab using its own slug, except 'site', which is split into the six site groups from
	// aafm_site_subgroups() - each becomes its own top-level chip + panel, taking the place the
	// single "Site & structure" chip used to hold (after 'media'). This is presentation only: no
	// ability's registry subject changes (the catalog-lock tests pin those). Each display tab is
	// { slug, label, rows } where rows are the ability entries to render under it.
	$display_tabs = aafm_abilities_display_tabs( $subjects, $by_subject, $registry );

	// Sub-tab bar - pill style (.aafm-subtabs); .aafm-subject-tab stays the JS hook the
	// toggle binds to and data-subject is the display-tab slug so panel switching keeps working.
	// Full WAI-ARIA tabs pattern: each tab carries a stable id + aria-controls pointing at its
	// panel, roving tabindex (only the active tab is in the tab sequence), and the JS adds
	// Arrow/Home/End handling. Each panel below carries the matching id + aria-labelledby.
	$first = array_key_first( $display_tabs );
	echo '<div class="aafm-subtabs aafm-subject-tabs" role="tablist" aria-label="' . esc_attr__( 'Ability subjects', 'agent-abilities-for-mcp' ) . '">';
	foreach ( $display_tabs as $slug => $tab ) {
		$is_active = ( $slug === $first );
		$tab_id    = 'aafm-subject-tab-' . $slug;
		$panel_id  = 'aafm-subject-panel-' . $slug;
		printf(
			'<button type="button" class="aafm-subject-tab%1$s" role="tab" id="%2$s" aria-controls="%3$s" aria-selected="%4$s" tabindex="%5$s" data-subject="%6$s">%7$s <span class="count">%8$s</span></button>',
			$is_active ? ' is-active' : '',
			esc_attr( $tab_id ),
			esc_attr( $panel_id ),
			$is_active ? 'true' : 'false',
			$is_active ? '0' : '-1',
			esc_attr( $slug ),
			esc_html( $tab['label'] ),
			esc_html( (string) count( $tab['rows'] ) )
		);
	}
	echo '</div>';

	foreach ( $display_tabs as $slug => $tab ) {
		$is_active = ( $slug === $first );
		$tab_rows  = $tab['rows'];
		printf(
			'<div class="aafm-subject-panel" data-subject="%1$s" role="tabpanel" id="%2$s" aria-labelledby="%3$s" tabindex="0"%4$s>',
			esc_attr( $slug ),
			esc_attr( 'aafm-subject-panel-' . $slug ),
			esc_attr( 'aafm-subject-tab-' . $slug ),
			$is_active ? '' : ' hidden'
		);

		// Per-tab count badge: how many of this tab's abilities are enabled.
		$subject_total   = count( $tab_rows );
		$subject_enabled = 0;
		foreach ( $tab_rows as $ability ) {
			if ( in_array( (string) $ability['name'], $enabled, true ) ) {
				++$subject_enabled;
			}
		}
		// H2 so the document outline runs H1 (page title) → H2 (subject group) → H3 (Reads/Writes)
		// → H4 (each ability), with no skipped level. The visible weight is unchanged in CSS.
		printf(
			'<h2 class="aafm-subject-heading"><span class="aafm-count-badge">%1$s / %2$s</span> %3$s</h2>',
			esc_html( (string) $subject_enabled ),
			esc_html( (string) $subject_total ),
			esc_html__( 'enabled', 'agent-abilities-for-mcp' )
		);

		// Per-section enable/disable-all control. JS scopes by .aafm-subject-panel[data-subject]
		// and toggles every checkbox in this panel; data-has-destructive tells it to confirm before
		// bulk-enabling a section that contains a destructive ability.
		$has_destructive = false;
		foreach ( $tab_rows as $ability ) {
			if ( 'destructive' === (string) ( $ability['risk'] ?? '' ) ) {
				$has_destructive = true;
				break;
			}
		}
		printf(
			'<p class="aafm-section-toggle"><button type="button" class="aafm-btn aafm-btn-secondary aafm-section-toggle-all" data-subject="%1$s"%2$s>%3$s</button></p>',
			esc_attr( $slug ),
			$has_destructive ? ' data-has-destructive="1"' : '',
			esc_html__( 'Enable all / Disable all', 'agent-abilities-for-mcp' )
		);

		if ( 'content' === $slug ) {
			aafm_render_post_types_selector();
		}

		// Each display tab renders its abilities split Reads then Writes where both exist; a tab
		// with a single risk class renders a flat list (the empty group is skipped). The bare
		// >Reads< / >Writes< text the panel-structure test keys off lives in the group <h3>.
		foreach ( $groups as $group => $heading ) {
			$rows = array();
			foreach ( $tab_rows as $ability ) {
				if ( ( $ability['group'] ?? '' ) === $group ) {
					$rows[] = $ability;
				}
			}
			if ( empty( $rows ) ) {
				continue;
			}

			$group_enabled = 0;
			foreach ( $rows as $ability ) {
				if ( in_array( (string) $ability['name'], $enabled, true ) ) {
					++$group_enabled;
				}
			}

			printf(
				'<div class="aafm-ability-group-head"><h3>%1$s</h3><span class="aafm-count-badge">%2$s / %3$s</span></div>',
				esc_html( $heading ),
				esc_html( (string) $group_enabled ),
				esc_html( (string) count( $rows ) )
			);

			echo '<div class="aafm-card aafm-ability-list">';
			foreach ( $rows as $ability ) {
				aafm_render_ability_row( $ability, $enabled, $disclosures );
			}
			echo '</div>';
		}

		// Rendered after the ability tables as a layout choice - the meta selector belongs below
		// the abilities it governs. No test depends on this placement: the panel-structure test
		// slices to the next subject panel (or the form's save status), not a bare </div>.
		if ( 'content' === $slug ) {
			aafm_render_meta_keys_selector();
		}

		if ( 'users' === $slug ) {
			aafm_render_user_meta_keys_selector();
		}

		if ( 'taxonomies' === $slug ) {
			aafm_render_term_meta_keys_selector();
		}

		echo '</div>';
	}

	echo '<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></div>';
	echo '</form>';

	// Future: per-connection / per-client ability allowlist scoping is a separate roadmapped
	// feature - it would filter $enabled per principal here rather than at render time.
}

/**
 * Render one ability checkbox row.
 *
 * Shared by the flat Reads/Writes view and the site sub-group view so both produce identical
 * markup. The <input> keeps its exact name/value/checked() contract - the save handler and its
 * tests bind to that, not to this markup.
 *
 * @param array<string,mixed>  $ability     The registry entry, with its 'name' key set.
 * @param array<int,string>    $enabled     The enabled ability names.
 * @param array<string,string> $disclosures Disclosure text keyed by ability name.
 * @return void
 */
function aafm_render_ability_row( array $ability, array $enabled, array $disclosures ): void {
	$name = (string) ( $ability['name'] ?? '' );
	$risk = (string) ( $ability['risk'] ?? 'read' );
	$hint = (string) ( $disclosures[ $name ] ?? ( $ability['description'] ?? '' ) );

	// Per-ability id on the title <h4>, used as the checkbox's accessible name via
	// aria-labelledby - otherwise a screen reader announces the bare toggle as just
	// "checkbox". sanitize_key keeps the slug DOM-safe (ability names hold a slash).
	$title_id = 'aafm-ability-title-' . sanitize_key( $name );

	echo '<div class="aafm-ability-row">';
	printf(
		'<label class="aafm-switch"><input type="checkbox" name="aafm_abilities[]" value="%1$s" aria-labelledby="%2$s" %3$s><span class="aafm-switch-track"></span></label>',
		esc_attr( $name ),
		esc_attr( $title_id ),
		checked( in_array( $name, $enabled, true ), true, false )
	);

	echo '<div class="aafm-ability-main"><div class="aafm-ability-title">';
	printf(
		'<h4 id="%1$s">%2$s</h4><span class="aafm-badge aafm-badge-%3$s">%3$s</span>',
		esc_attr( $title_id ),
		esc_html( (string) ( $ability['label'] ?? $name ) ),
		esc_attr( $risk )
	);

	// Read-only badge only on read-risk rows; never on write/destructive. risk === 'read' is the
	// authoritative read-only signal at render time (the catalog carries no annotations.readonly).
	if ( 'read' === $risk ) {
		echo ' <span class="aafm-badge aafm-badge-readonly aafm-readonly-badge">' . esc_html__( 'read-only', 'agent-abilities-for-mcp' ) . '</span>';
	}

	printf(
		'</div><p class="aafm-ability-hint">%1$s</p></div></div>',
		esc_html( $hint )
	);
}

/**
 * Expand the subject list into the ordered display tabs the Abilities tab renders.
 *
 * Each Abilities-tab subject maps to one display tab keyed by its own slug, EXCEPT 'site', which is
 * split into the six groups from aafm_site_subgroups() - each becomes its own display tab (chip +
 * panel), inserted where the single "Site & structure" chip used to sit. This is presentation only:
 * no ability's registry subject changes. A group's rows are pulled from the full registry by name,
 * so an ability mapped in by name from another subject (Search's search-content, registry subject
 * 'content') still lands under its site group. Any site-subject ability no group claims is folded
 * into the Site settings tab, so a future addition is never silently dropped (the AbilitiesSaveTest
 * union guard enforces that).
 *
 * @param array<string,string>                    $subjects   Used Abilities-tab subjects, slug => label, in order.
 * @param array<string,list<array<string,mixed>>> $by_subject Registry rows bucketed by subject (with 'name').
 * @param array<string,array<string,mixed>>       $registry   The full registry, for by-name lookups.
 * @return array<string,array{label:string,rows:list<array<string,mixed>>}> Display tabs, slug => { label, rows }.
 */
function aafm_abilities_display_tabs( array $subjects, array $by_subject, array $registry ): array {
	$site_groups = aafm_site_subgroups();

	// Which site-subject abilities a group has claimed, so the rest fall into Site settings.
	$claimed = array();
	foreach ( $site_groups as $group ) {
		foreach ( $group['abilities'] as $ability_name ) {
			$claimed[ $ability_name ] = true;
		}
	}

	// search-content and any other ability mapped into a site group by name carries a foreign
	// registry subject; it renders under its site group only, never in the flat view of its subject.
	$relocated = array();
	foreach ( $site_groups as $group ) {
		foreach ( $group['abilities'] as $ability_name ) {
			if ( isset( $registry[ $ability_name ] ) && 'site' !== (string) ( $registry[ $ability_name ]['subject'] ?? '' ) ) {
				$relocated[ $ability_name ] = true;
			}
		}
	}

	$tabs = array();
	foreach ( $subjects as $slug => $label ) {
		if ( 'site' !== $slug ) {
			// A plain subject tab: its own rows, minus any relocated into a site group by name.
			$rows = array();
			foreach ( $by_subject[ $slug ] as $ability ) {
				if ( isset( $relocated[ (string) $ability['name'] ] ) ) {
					continue;
				}
				$rows[] = $ability;
			}
			$tabs[ $slug ] = array(
				'label' => $label,
				'rows'  => $rows,
			);
			continue;
		}

		// The 'site' slot expands into one display tab per site group, in map order.
		foreach ( $site_groups as $group_slug => $group ) {
			$rows = array();
			foreach ( $group['abilities'] as $ability_name ) {
				if ( ! isset( $registry[ $ability_name ] ) ) {
					continue;
				}
				$rows[] = array( 'name' => $ability_name ) + $registry[ $ability_name ];
			}

			// Fold any unclaimed site-subject ability into Site settings, so nothing is dropped.
			if ( 'site_settings' === $group_slug ) {
				foreach ( $by_subject['site'] ?? array() as $ability ) {
					$name = (string) ( $ability['name'] ?? '' );
					if ( '' !== $name && ! isset( $claimed[ $name ] ) ) {
						$rows[]           = $ability;
						$claimed[ $name ] = true; // Render once, even if listed twice.
					}
				}
			}

			$tabs[ $group_slug ] = array(
				'label' => (string) $group['label'],
				'rows'  => $rows,
			);
		}
	}

	return $tabs;
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
	echo '<div class="aafm-table-wrap">';
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
			'<tr><td><label class="aafm-switch"><input type="checkbox" name="aafm_post_types[]" value="%1$s" aria-label="%7$s" %2$s><span class="aafm-switch-track"></span></label></td><td><strong>%3$s</strong> <code>%4$s</code></td><td>%5$s</td><td>%6$s</td></tr>',
			esc_attr( $type ),
			checked( in_array( $type, $allowed, true ), true, false ),
			esc_html( (string) $label ),
			esc_html( $type ),
			$mapped
				? esc_html__( 'Allowed', 'agent-abilities-for-mcp' )
				: '<span class="aafm-badge aafm-badge-read">' . esc_html__( 'read-only - writes need map_meta_cap', 'agent-abilities-for-mcp' ) . '</span>',
			$rest ? esc_html__( 'yes', 'agent-abilities-for-mcp' ) : esc_html__( 'no', 'agent-abilities-for-mcp' ),
			esc_attr(
				sprintf(
					/* translators: %s: the content type's singular label, e.g. "Product". */
					__( 'Expose %s to agents', 'agent-abilities-for-mcp' ),
					(string) $label
				)
			)
		);
	}

	echo '</tbody></table>';
	echo '</div>'; // .aafm-table-wrap
	aafm_render_notice( 'warning', __( 'Exposed types are still gated by that type\'s capabilities and your low-privilege agent user. Only expose types whose title, slug, and excerpt are not sensitive - for example, a type that stores a person\'s name in the title would make that name readable.', 'agent-abilities-for-mcp' ) );
	echo '<p><button type="button" id="aafm-post-types-save" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save content types', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-post-types-status" aria-live="polite"></span></p>';
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
	$denied   = aafm_denied_meta_keys();
	$detected = aafm_detected_meta_keys();

	// The getters strip the '*' sentinel, so surface it in the textareas when the wildcard is
	// set - otherwise the box renders empty and a re-save would silently wipe allow-all/deny-all.
	if ( aafm_meta_allow_has_star() ) {
		array_unshift( $allowed, '*' );
	}
	if ( aafm_meta_deny_has_star() ) {
		array_unshift( $denied, '*' );
	}

	// Mirrors the post-types selector: a plain <div> (never a nested <form>) with a
	// type="button" save, so the one outer abilities <form> is never closed early.
	echo '<div id="aafm-meta-keys-form" class="aafm-card aafm-card-pad aafm-meta-keys">';
	echo '<h3 id="' . esc_attr( 'aafm-meta-keys-label' ) . '">' . esc_html__( 'Exposed meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'One meta key per line. These are the only meta keys an agent can read or write on a post it can already edit. Everything else stays hidden.', 'agent-abilities-for-mcp' ) . '</p>';
	aafm_render_notice( 'warning', __( 'Meta can hold private data. Only expose keys whose values are safe for an agent to read and write. Protected keys (anything starting with an underscore) and authentication keys are blocked for good and can\'t be added.', 'agent-abilities-for-mcp' ) );

	printf(
		'<textarea name="aafm_meta_keys" id="%1$s" rows="6" class="large-text code" aria-labelledby="%2$s" aria-describedby="%3$s">%4$s</textarea>',
		esc_attr( 'aafm-meta-keys' ),
		esc_attr( 'aafm-meta-keys-label' ),
		esc_attr( 'aafm-meta-keys-hint' ),
		esc_textarea( implode( "\n", $allowed ) )
	);
	echo '<p class="description" id="' . esc_attr( 'aafm-meta-keys-hint' ) . '">' . esc_html__( 'One key per line. * matches any key.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<p class="description">' . esc_html__( 'Detected on your exposed types', 'agent-abilities-for-mcp' ) . '</p>';
	if ( empty( $detected ) ) {
		echo '<p class="description">' . esc_html__( 'Nothing detected yet on the types you expose.', 'agent-abilities-for-mcp' ) . '</p>';
	} else {
		echo '<div class="aafm-meta-chips">';
		foreach ( $detected as $key ) {
			printf(
				'<button type="button" class="aafm-meta-chip" data-key="%1$s">%2$s</button>',
				esc_attr( $key ),
				esc_html( $key )
			);
		}
		echo '</div>';
	}

	// Deny list, below the exposed list. Denied keys always win over the exposed list, even
	// when it uses *. The chip source above writes only into the Exposed textarea.
	echo '<h3 id="' . esc_attr( 'aafm-deny-meta-keys-label' ) . '">' . esc_html__( 'Denied meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<textarea name="aafm_deny_meta_keys" id="%1$s" rows="4" class="large-text code" aria-labelledby="%2$s" aria-describedby="%3$s">%4$s</textarea>',
		esc_attr( 'aafm-deny-meta-keys' ),
		esc_attr( 'aafm-deny-meta-keys-label' ),
		esc_attr( 'aafm-deny-meta-keys-hint' ),
		esc_textarea( implode( "\n", $denied ) )
	);
	echo '<p class="description" id="' . esc_attr( 'aafm-deny-meta-keys-hint' ) . '">' . esc_html__( 'Denied keys win over exposed, even with *. One per line.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<p><button type="button" id="aafm-meta-keys-save" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save meta keys', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-meta-keys-status" aria-live="polite"></span></p>';
	echo '</div>';
}

/**
 * Render the exposed/denied user-meta selector for the Users sub-tab.
 *
 * Mirrors aafm_render_meta_keys_selector() but for user meta: a plain <div> (never a nested
 * <form>) with two textareas (exposed above denied) and a type="button" save, so the one outer
 * abilities <form> is never closed early. The deny list always wins over the exposed list,
 * even when the exposed list uses *.
 *
 * @return void
 */
function aafm_render_user_meta_keys_selector(): void {
	$exposed = aafm_allowed_user_meta_keys();
	$denied  = aafm_denied_user_meta_keys();

	// The getters strip the '*' sentinel, so surface it in the textareas when the wildcard is
	// set - otherwise the box renders empty and a re-save would silently wipe allow-all/deny-all.
	if ( aafm_user_meta_allow_has_star() ) {
		array_unshift( $exposed, '*' );
	}
	if ( aafm_user_meta_deny_has_star() ) {
		array_unshift( $denied, '*' );
	}

	echo '<div id="aafm-user-meta-keys-form" class="aafm-card aafm-card-pad aafm-meta-keys">';
	echo '<h3 id="' . esc_attr( 'aafm-exposed-user-meta-keys-label' ) . '">' . esc_html__( 'Exposed user meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'These are the only user meta keys an agent can read or write on a user it can already edit. Denied keys always win, even when the exposed list uses *.', 'agent-abilities-for-mcp' ) . '</p>';
	aafm_render_notice( 'warning', __( 'User meta can hold private data. Only expose keys whose values are safe for an agent to read and write. Authentication keys, capabilities, and password keys are blocked for good and cannot be added.', 'agent-abilities-for-mcp' ) );

	printf(
		'<textarea name="aafm_exposed_user_meta_keys" id="%1$s" rows="6" class="large-text code" aria-labelledby="%2$s" aria-describedby="%3$s">%4$s</textarea>',
		esc_attr( 'aafm-exposed-user-meta-keys' ),
		esc_attr( 'aafm-exposed-user-meta-keys-label' ),
		esc_attr( 'aafm-exposed-user-meta-keys-hint' ),
		esc_textarea( implode( "\n", $exposed ) )
	);
	echo '<p class="description" id="' . esc_attr( 'aafm-exposed-user-meta-keys-hint' ) . '">' . esc_html__( 'One key per line. * matches any key.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<h3 id="' . esc_attr( 'aafm-denied-user-meta-keys-label' ) . '">' . esc_html__( 'Denied user meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<textarea name="aafm_denied_user_meta_keys" id="%1$s" rows="4" class="large-text code" aria-labelledby="%2$s" aria-describedby="%3$s">%4$s</textarea>',
		esc_attr( 'aafm-denied-user-meta-keys' ),
		esc_attr( 'aafm-denied-user-meta-keys-label' ),
		esc_attr( 'aafm-denied-user-meta-keys-hint' ),
		esc_textarea( implode( "\n", $denied ) )
	);
	echo '<p class="description" id="' . esc_attr( 'aafm-denied-user-meta-keys-hint' ) . '">' . esc_html__( 'Denied keys win over exposed, even with *. One per line.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<p><button type="button" id="aafm-user-meta-keys-save" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save user meta keys', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-user-meta-keys-status" aria-live="polite"></span></p>';
	echo '</div>';
}

/**
 * Render the exposed/denied term-meta selector for the Taxonomies & Terms sub-tab.
 *
 * Mirrors aafm_render_user_meta_keys_selector() but for term meta: a plain <div> (never a
 * nested <form>) with two textareas (exposed above denied) and a type="button" save, so the
 * one outer abilities <form> is never closed early. The deny list always wins over the exposed
 * list, even when the exposed list uses *.
 *
 * @return void
 */
function aafm_render_term_meta_keys_selector(): void {
	$exposed = aafm_allowed_term_meta_keys();
	$denied  = aafm_denied_term_meta_keys();

	// The getters strip the '*' sentinel, so surface it in the textareas when the wildcard is
	// set - otherwise the box renders empty and a re-save would silently wipe allow-all/deny-all.
	if ( aafm_term_meta_allow_has_star() ) {
		array_unshift( $exposed, '*' );
	}
	if ( aafm_term_meta_deny_has_star() ) {
		array_unshift( $denied, '*' );
	}

	echo '<div id="aafm-term-meta-keys-form" class="aafm-card aafm-card-pad aafm-meta-keys">';
	echo '<h3 id="' . esc_attr( 'aafm-exposed-term-meta-keys-label' ) . '">' . esc_html__( 'Exposed term meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'These are the only term meta keys an agent can read or write on a term it can already edit. Denied keys always win, even when the exposed list uses *.', 'agent-abilities-for-mcp' ) . '</p>';
	aafm_render_notice( 'warning', __( 'Term meta can hold private data. Only expose keys whose values are safe for an agent to read and write. Protected keys (anything starting with an underscore) and authentication keys are blocked for good and cannot be added.', 'agent-abilities-for-mcp' ) );

	printf(
		'<textarea name="aafm_exposed_term_meta_keys" id="%1$s" rows="6" class="large-text code" aria-labelledby="%2$s" aria-describedby="%3$s">%4$s</textarea>',
		esc_attr( 'aafm-exposed-term-meta-keys' ),
		esc_attr( 'aafm-exposed-term-meta-keys-label' ),
		esc_attr( 'aafm-exposed-term-meta-keys-hint' ),
		esc_textarea( implode( "\n", $exposed ) )
	);
	echo '<p class="description" id="' . esc_attr( 'aafm-exposed-term-meta-keys-hint' ) . '">' . esc_html__( 'One key per line. * matches any key.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<h3 id="' . esc_attr( 'aafm-denied-term-meta-keys-label' ) . '">' . esc_html__( 'Denied term meta keys', 'agent-abilities-for-mcp' ) . '</h3>';
	printf(
		'<textarea name="aafm_denied_term_meta_keys" id="%1$s" rows="4" class="large-text code" aria-labelledby="%2$s" aria-describedby="%3$s">%4$s</textarea>',
		esc_attr( 'aafm-denied-term-meta-keys' ),
		esc_attr( 'aafm-denied-term-meta-keys-label' ),
		esc_attr( 'aafm-denied-term-meta-keys-hint' ),
		esc_textarea( implode( "\n", $denied ) )
	);
	echo '<p class="description" id="' . esc_attr( 'aafm-denied-term-meta-keys-hint' ) . '">' . esc_html__( 'Denied keys win over exposed, even with *. One per line.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<p><button type="button" id="aafm-term-meta-keys-save" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save term meta keys', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-term-meta-keys-status" aria-live="polite"></span></p>';
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
	$per_page = aafm_activity_page_size();
	$rows     = aafm_query_activity( array( 'per_page' => $per_page ) );

	$total       = aafm_activity_count();
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$retention   = aafm_log_retention_days();

	echo '<div class="aafm-activity">';
	wp_nonce_field( 'aafm_admin', 'aafm_log_nonce' );

	// Status filter - server-side: each button re-queries page 1 with its status (admin.js).
	echo '<div class="aafm-activity-toolbar">';
	echo '<div class="aafm-seg" role="group" aria-label="' . esc_attr__( 'Filter by status', 'agent-abilities-for-mcp' ) . '">';
	echo '<button type="button" class="aafm-seg-btn is-active on" data-filter="all" aria-pressed="true">' . esc_html__( 'All', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-seg-btn" data-filter="success" aria-pressed="false">' . esc_html__( 'Success', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-seg-btn" data-filter="error" aria-pressed="false">' . esc_html__( 'Errors', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<button type="button" class="aafm-seg-btn" data-filter="denied" aria-pressed="false">' . esc_html__( 'Denied', 'agent-abilities-for-mcp' ) . '</button>';
	echo '</div>';

	// Live count headline plus a plain clarifier describing the retention window.
	if ( $retention > 0 ) {
		$count_note = sprintf(
			/* translators: %s: number of days of activity the log keeps. */
			_n(
				'entries, keeping the last %s day',
				'entries, keeping the last %s days',
				$retention,
				'agent-abilities-for-mcp'
			),
			number_format_i18n( $retention )
		);
	} else {
		$count_note = __( 'entries, keeping everything', 'agent-abilities-for-mcp' );
	}
	printf(
		'<span class="aafm-activity-count" aria-live="polite"><strong class="aafm-count-num">%1$s</strong> <span class="aafm-count-note">%2$s</span></span>',
		esc_html( number_format_i18n( $total ) ),
		esc_html( $count_note )
	);

	echo '<button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-clear-log">' . esc_html__( 'Clear log', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-clear-status" aria-live="polite"></span>';
	echo '</div>';

	printf(
		'<div class="aafm-table-wrap" id="aafm-log-table-wrap" data-page="1" data-filter="all" data-total-pages="%s">',
		esc_attr( (string) $total_pages )
	);
	echo '<table class="widefat striped aafm-log-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Time (UTC)', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Principal', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Ability', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Arg keys', 'agent-abilities-for-mcp' ) . '</th>';
	echo '</tr></thead><tbody>';

	echo wp_kses( aafm_activity_rows_html( $rows ), aafm_admin_allowed_html() );

	echo '</tbody></table>';
	echo '</div>'; // .aafm-table-wrap

	// Pager: Prev / "Page X of Y" / Next. Disabled at the ends; updated by admin.js on filter/page.
	echo '<div class="aafm-pager">';
	echo '<button type="button" class="aafm-btn aafm-btn-secondary aafm-pager-prev" disabled>' . esc_html__( 'Previous', 'agent-abilities-for-mcp' ) . '</button>';
	printf(
		'<span class="aafm-pager-status" aria-live="polite">%s</span>',
		esc_html(
			sprintf(
				/* translators: 1: current page number, 2: total number of pages. */
				__( 'Page %1$s of %2$s', 'agent-abilities-for-mcp' ),
				number_format_i18n( 1 ),
				number_format_i18n( $total_pages )
			)
		)
	);
	printf(
		'<button type="button" class="aafm-btn aafm-btn-secondary aafm-pager-next"%s>%s</button>',
		$total_pages > 1 ? '' : ' disabled',
		esc_html__( 'Next', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	echo '</div>';
}

/**
 * Number of activity rows shown per page in the admin table.
 *
 * @return int Positive page size, capped at the aafm_query_activity() ceiling (200).
 */
function aafm_activity_page_size(): int {
	$size = (int) apply_filters( 'aafm_activity_page_size', 50 );
	return min( 200, max( 1, $size ) );
}

/**
 * Map a status-filter segment slug to a query status (or null for "all").
 *
 * @param string $filter One of all|success|error|denied (anything else falls back to all).
 * @return string|null The status to query on, or null for no status filter.
 */
function aafm_activity_filter_status( string $filter ): ?string {
	return in_array( $filter, array( 'success', 'error', 'denied' ), true ) ? $filter : null;
}

/**
 * Render one page of activity rows as escaped <tr> HTML.
 *
 * Every cell is run through esc_html()/esc_attr(); the log only ever holds argument KEYS
 * (never values) plus a REMOTE_ADDR source IP, so standard escaping is sufficient. An empty
 * set renders a single "no activity" row so the table never collapses to a bare <tbody>.
 *
 * @param array<int,array<string,mixed>> $rows Rows from aafm_query_activity().
 * @return string Escaped <tr>…</tr> markup.
 */
function aafm_activity_rows_html( array $rows ): string {
	if ( empty( $rows ) ) {
		return '<tr><td colspan="5">' . esc_html__( 'No activity recorded yet.', 'agent-abilities-for-mcp' ) . '</td></tr>';
	}

	// Map each log status to a pill variant; the status word stays visible (never colour-only).
	$status_variants = array(
		'success' => 'success',
		'error'   => 'danger',
		'denied'  => 'warn',
		'started' => 'neutral',
	);

	$html = '';
	foreach ( $rows as $row ) {
		$status  = (string) ( $row['status'] ?? '' );
		$variant = $status_variants[ $status ] ?? 'neutral';
		$html   .= sprintf(
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
	return $html;
}

/**
 * Render a single troubleshooting entry as a native <details> accordion.
 *
 * The question (summary) and body are pre-built, escaped HTML fragments. Bodies may
 * carry inline <code>, <p>, <ul>/<li>, <strong>, and <a> built by the caller - each
 * passed through wp_kses() with a tight allowed-tags list so nothing else slips in.
 *
 * @param string $summary Plain-text question shown in the <summary>.
 * @param string $body    Pre-escaped HTML body (already run through wp_kses by caller).
 * @return void
 */
function aafm_render_help_entry( string $summary, string $body ): void {
	echo '<details class="aafm-help-entry"><summary>';
	echo wp_kses( aafm_icon( 'help' ), aafm_svg_allowed_html() );
	echo esc_html( $summary ) . '</summary><div class="aafm-help-body">';
	echo wp_kses( $body, aafm_admin_allowed_html() );
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
		'<div class="aafm-help-copy-line"><code>%1$s</code> <button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm aafm-copy" data-copy="%2$s">%3$s<span class="aafm-copy-label">%4$s</span></button></div>',
		esc_html( $code ),
		esc_attr( $code ),
		aafm_icon( 'copy' ), // Static literal SVG.
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

	// Section 1 - Connecting.
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
				'<p>' . esc_html__( 'A 301 redirect - adding or removing a trailing slash, or forcing http to https - can drop the POST body or the Authorization header on the way through, so the request that finally reaches WordPress is empty or unauthenticated. This is the request not arriving intact, not a credentials problem.', 'agent-abilities-for-mcp' ) . '</p>'
				. '<p>' . esc_html__( 'Use the exact endpoint URL the Connection tab shows, with the right scheme (https) and no extra trailing slash, so no redirect is triggered. If your server force-redirects http to https, make sure the config URL already starts with https so the POST is never redirected.', 'agent-abilities-for-mcp' ) . '</p>',
				$inline
			)
		);

		// Connecting: self-test with curl (verified against a live endpoint: 200/401/403-406-429/404/5xx).
		aafm_render_help_entry(
			__( 'Test the endpoint yourself with curl', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'This one-liner sends a real MCP "initialize" call to your endpoint with the agent user\'s Application Password. It tells you in one shot whether the endpoint is reachable, whether auth works, and - if it fails - which layer to blame. Replace the host, the username, and the Application Password (the password is the one shown once when you created it; keep its spaces):', 'agent-abilities-for-mcp' ) . '</p>'
				. aafm_help_copy_line( 'curl -i -X POST "https://example.com/wp-json/agent-abilities-for-mcp/mcp" -u "mcp-agent:XXXX XXXX XXXX XXXX XXXX XXXX" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-probe","version":"1.0"}}}\'' )
				. '<p>' . esc_html__( 'If your permalinks are plain, use the index.php form instead:', 'agent-abilities-for-mcp' ) . '</p>'
				. aafm_help_copy_line( 'curl -i -X POST "https://example.com/index.php?rest_route=/agent-abilities-for-mcp/mcp" -u "mcp-agent:XXXX XXXX XXXX XXXX XXXX XXXX" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-probe","version":"1.0"}}}\'' )
				. '<p><strong>' . esc_html__( 'How to read the result (the HTTP status on the first line):', 'agent-abilities-for-mcp' ) . '</strong></p>'
				. '<ul>'
				. '<li><strong>' . esc_html__( '200', 'agent-abilities-for-mcp' ) . '</strong> - ' . esc_html__( 'reachable and authenticated; the body is a JSON-RPC result. Everything is working - if the AI client still fails, the block is on its side (see the 403/406/429 entry above).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '401', 'agent-abilities-for-mcp' ) . '</strong> - ' . esc_html__( 'reached WordPress but auth failed: wrong or expired Application Password, or the Authorization header is being stripped (see the Authentication section).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '403 / 406 / 429', 'agent-abilities-for-mcp' ) . '</strong> - ' . esc_html__( 'a WAF, CDN, or host security rule is blocking the request before WordPress (see the 403/406/429 entry above).', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '404', 'agent-abilities-for-mcp' ) . '</strong> - ' . esc_html__( 'the route is not registered for this URL: flush permalinks (Settings → Permalinks → Save) and confirm you copied the endpoint exactly from the Connection tab.', 'agent-abilities-for-mcp' ) . '</li>'
				. '<li><strong>' . esc_html__( '5xx', 'agent-abilities-for-mcp' ) . '</strong> - ' . esc_html__( 'a server-side error: check your PHP error log and the host status.', 'agent-abilities-for-mcp' ) . '</li>'
				. '</ul>',
				$inline
			)
		);

		// 4. Windows: client config won't start.
		aafm_render_help_entry(
			__( 'Windows: my client config won\'t start', 'agent-abilities-for-mcp' ),
			wp_kses(
				'<p>' . esc_html__( 'Windows MCP clients cannot spawn the npx shim by its name alone. The launcher has to be wrapped so the command resolves:', 'agent-abilities-for-mcp' ) . ' <code>cmd /c npx …</code></p>'
				. '<p>' . esc_html__( 'You do not need to hand-edit anything - switch to the "Windows" tab in Connection → Step 2 and copy the config it generates. It already wraps the launcher correctly.', 'agent-abilities-for-mcp' ) . '</p>',
				$inline
			)
		);

	// 5. Local / staging won't connect (self-signed cert).
	aafm_render_help_entry(
		__( 'My local or staging site won\'t connect (self-signed certificate)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Local stacks (DDEV, Local, Valet) serve a certificate Node does not trust, so the proxy refuses the TLS handshake. For local testing only, you can tell Node to accept it. The Connection tab already adds this for you when it detects a local site.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Quick (least safe) - add this to the config env block:', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( '"NODE_TLS_REJECT_UNAUTHORIZED": "0"' )
			. '<p>' . esc_html__( 'Better - point Node at your local CA instead of disabling verification entirely (for example mkcert\'s rootCA.pem):', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( '"NODE_EXTRA_CA_CERTS": "/path/to/rootCA.pem"' )
			. aafm_help_copy_line( '"NODE_USE_SYSTEM_CA": "1"' )
			. '<p><strong>' . esc_html__( 'Never use any of these on a production site.', 'agent-abilities-for-mcp' ) . '</strong></p>',
			$inline
		)
	);

	// Browser / OAuth clients: how a client connects without a copied secret.
	aafm_render_help_entry(
		__( 'Connecting a browser or OAuth client', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'There are two ways for an agent to authenticate. Most desktop clients use an Application Password: create one for the agent user under Users → Profile → Application Passwords, then paste the generated config from Connection → Step 2. Keep the password\'s spaces when you copy it.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'OAuth is the other way, and it is off by default. Turn it on under Settings and the agent connects by browser approval instead of a copied secret: you paste your site URL into the client and approve the request in your browser. The Connection tab shows the OAuth card and the endpoint to use once it is enabled.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'For browser-based clients, the plugin already exposes the session and approval headers a browser needs to read across origins (the MCP session id, the protocol version, and the OAuth challenge), so a web client can complete the handshake. If a browser client still cannot read the session header, check that nothing in front of WordPress is stripping CORS response headers.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	echo '</div>';

	// Section 2 - Authentication.
	echo '<div class="aafm-acc-group">';
	echo '<h2>' . esc_html__( 'Authentication', 'agent-abilities-for-mcp' ) . '</h2>';

	// 2. Authorization header diagnostic fails.
	aafm_render_help_entry(
		__( 'The "Authorization header reaches WordPress" diagnostic fails', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Some hosts and reverse proxies strip the Authorization header before it reaches PHP, so the Application Password never arrives and auth silently fails. Forward the header at the web-server layer.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Apache (.htaccess) - either of these:', 'agent-abilities-for-mcp' ) . '</strong></p>'
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
			'<p>' . esc_html__( 'WordPress core only offers Application Passwords over a secure (https) connection. Behind a TLS-terminating proxy or load balancer, WordPress can see the request as plain HTTP even though the browser is on https - so it hides the option.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'Fix the proxy or HTTPS headers (or your site URL) so WordPress correctly detects https. Forwarding the standard X-Forwarded-Proto header from the proxy is the usual fix.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Do not enable Application Passwords over genuine plaintext HTTP in production - the credential would travel unencrypted.', 'agent-abilities-for-mcp' ) . '</strong></p>',
			$inline
		)
	);

	echo '</div>';

	// Section 3 - Abilities & permissions.
	echo '<div class="aafm-acc-group">';
	echo '<h2>' . esc_html__( 'Abilities & permissions', 'agent-abilities-for-mcp' ) . '</h2>';

	// 6. Agent sees fewer tools than expected.
	aafm_render_help_entry(
		__( 'My agent sees fewer tools than I expected', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'This is intentional least-privilege behaviour. Each connection is filtered by the agent user\'s own capabilities, so the agent only ever sees abilities its role allows: reads need read capabilities; writes need the matching edit, publish, moderate, or manage capabilities.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'To expose more tools, grant the agent user the role or capabilities those abilities require. Granting more, of course, widens what the agent can do - keep it to what the agent genuinely needs.', 'agent-abilities-for-mcp' ) . '</p>',
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

	// Meta governance: the allow/deny model and the * wildcard.
	aafm_render_help_entry(
		__( 'How the exposed and denied meta key lists work (and what * does)', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Meta is locked down by default: until you list a key under "Exposed meta keys" on the Abilities tab, an agent cannot read or write any post meta. Two keys are always off limits no matter what you list: keys starting with an underscore (protected meta) and authentication keys. Those stay blocked even if you try to add them.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'A single * in the exposed list means "allow every key except the ones that are blocked or denied". The order the plugin checks is fixed: blocked keys lose first, then the denied list, then the exposed list (or the * wildcard). So the deny list always wins. A key in "Denied meta keys" stays hidden even when the exposed list is set to *.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'One thing to know about * and reading all of a post\'s meta at once: a single-key read works for any key * covers, but the "read all meta" ability only returns the keys you spelled out by name. Under a bare *, with no named keys, it returns an empty set. List the keys you want returned in bulk.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// Term meta is filter-only (no admin field).
	aafm_render_help_entry(
		__( 'Exposing term (category and tag) meta', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Term meta has no field on the Abilities tab. It is off by default and you open it up with a small filter in your own code (a site-specific plugin or your theme\'s functions.php). Add the key names you want an agent to read or write on terms:', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( 'add_filter( \'aafm_allowed_term_meta_keys\', fn( $keys ) => array_merge( $keys, [ \'my_term_color\', \'my_term_icon\' ] ) );' )
			. '<p>' . esc_html__( 'The same protections apply as for post meta: underscore-prefixed and authentication keys are stripped out even if your filter tries to add them.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	// Widening the exposed post types.
	aafm_render_help_entry(
		__( 'My custom post type is not available to the agent', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'Out of the box an agent can only touch posts and pages. Every other content type is off until you turn it on under "Exposed content types" on the Abilities tab. Only public, non-internal types show up there to choose from.', 'agent-abilities-for-mcp' ) . '</p>'
			. '<p>' . esc_html__( 'If you would rather set this in code, the same list runs through a filter you can add to:', 'agent-abilities-for-mcp' ) . '</p>'
			. aafm_help_copy_line( 'add_filter( \'aafm_allowed_post_types\', fn( $types ) => array_merge( $types, [ \'product\', \'event\' ] ) );' )
			. '<p>' . esc_html__( 'After exposing a type, the agent still needs the WordPress capabilities for that type, and the relevant abilities still have to be turned on.', 'agent-abilities-for-mcp' ) . '</p>',
			$inline
		)
	);

	echo '</div>';

	// Section 4 - Clients, privacy & limits.
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
			. '<p>' . esc_html__( 'Copy the config straight from Connection → Step 2 - do not hand-build it. On Windows, use that tab\'s "Windows" view (it wraps the launcher in cmd /c); for a local or staging site, it adds the certificate handling. Both are covered in the Connecting section above.', 'agent-abilities-for-mcp' ) . '</p>'
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
			. '<li><strong>' . esc_html__( 'No external calls.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'It never phones home. Your credentials and your content never leave the site - the AI client connects in to you, not the other way round.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'A dedicated low-privilege user.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'The agent authenticates as its own separate WordPress user via an Application Password - not as you, and not as an administrator. You choose that user\'s role, so you set its ceiling.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Two locks on every ability.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'An ability works only if you explicitly enabled it on the Abilities tab AND the agent user\'s capabilities allow it. The default is nothing enabled - the agent starts with zero abilities until you turn them on.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Trash and permanent delete are different abilities.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Trash abilities (trash a post or page) move content to the Trash, where you can restore it. Delete abilities erase for good and cannot be undone: deleting a post or page outright, and every media or user deletion, is permanent.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Everything is logged, values are not.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'Every call - including denied ones - is recorded on the Activity Log tab with the argument KEYS only, never the values. You can see what was attempted without leaking what was in it.', 'agent-abilities-for-mcp' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Optional extra guardrails.', 'agent-abilities-for-mcp' ) . '</strong> ' . esc_html__( 'The Settings tab adds a per-minute rate limit, an IP allowlist, a force-to-draft switch, and a maximum title length. All four are off by default, so you turn on only the ones you want.', 'agent-abilities-for-mcp' ) . '</li>'
			. '</ul>',
			$inline
		)
	);

	// 9. Privacy / what gets logged.
	aafm_render_help_entry(
		__( 'What does the plugin log, and does it call out to anything?', 'agent-abilities-for-mcp' ),
		wp_kses(
			'<p>' . esc_html__( 'The plugin makes no external calls - nothing about your site or its content is sent anywhere.', 'agent-abilities-for-mcp' ) . '</p>'
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
