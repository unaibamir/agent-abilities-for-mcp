<?php
/**
 * Settings tab: optional safety controls (rate limit, IP allowlist, force-draft, max title).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Upper bound shared by the two numeric settings. Both are clamped to this ceiling so a
 * pasted-in absurd value can never be stored, and both floor at 0 (off) by casting to int
 * and clamping negatives to zero (absint would flip the sign, so it is not used).
 */
const OVERSIO_SETTINGS_NUMERIC_MAX = 100000;

/**
 * Sanitize the posted Settings form into a clean, bounded, validated array.
 *
 * Every value is coerced to a safe shape regardless of what was posted:
 * - oversio_rate_limit_per_min, oversio_max_title_len: floored at 0 (negative/garbage -> 0) and
 *   capped at OVERSIO_SETTINGS_NUMERIC_MAX. Note max( 0, (int) ) rather than absint(), so a
 *   negative value clamps down to 0 instead of flipping to its positive magnitude.
 * - oversio_force_draft: a plain bool from presence of the field (unchecked checkbox -> false).
 * - oversio_oauth_enabled, oversio_oauth_dcr_enabled: the STRING '1' when the checkbox is present,
 *   '0' when absent. The OAuth readers default on and treat every falsy stored form as off, so
 *   the off state must be the literal '0' string — a PHP bool false would not store as false on
 *   a never-created option, leaving the toggle stuck on.
 * - oversio_ip_allowlist: split on newlines, trimmed, blanks dropped, and every surviving line
 *   must clear oversio_is_valid_ip_or_cidr(). Invalid lines are dropped (fail-closed), so a
 *   stored non-empty list is always made up entirely of usable entries.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return array{oversio_rate_limit_per_min:int,oversio_max_title_len:int,oversio_log_retention_days:int,oversio_force_draft:bool,oversio_delete_data_on_uninstall:bool,oversio_oauth_enabled:string,oversio_oauth_dcr_enabled:string,oversio_ip_allowlist:list<string>}
 */
function oversio_sanitize_settings_input( array $posted ): array {
	$rate  = min( OVERSIO_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['oversio_rate_limit_per_min'] ?? 0 ) ) );
	$title = min( OVERSIO_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['oversio_max_title_len'] ?? 0 ) ) );
	// Retention has its own tighter ceiling (ten years); 0 keeps every entry forever.
	$retention = min( 3650, max( 0, (int) ( $posted['oversio_log_retention_days'] ?? 30 ) ) );
	$draft     = ! empty( $posted['oversio_force_draft'] );
	$delete_on = ! empty( $posted['oversio_delete_data_on_uninstall'] );

	$oauth     = empty( $posted['oversio_oauth_enabled'] ) ? '0' : '1';
	$oauth_dcr = empty( $posted['oversio_oauth_dcr_enabled'] ) ? '0' : '1';

	$raw   = isset( $posted['oversio_ip_allowlist'] ) ? (string) $posted['oversio_ip_allowlist'] : '';
	$lines = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( sanitize_text_field( (string) $line ) );
		if ( '' === $line || ! oversio_is_valid_ip_or_cidr( $line ) ) {
			continue;
		}
		$lines[] = $line;
	}

	return array(
		'oversio_rate_limit_per_min'       => $rate,
		'oversio_max_title_len'            => $title,
		'oversio_log_retention_days'       => $retention,
		'oversio_force_draft'              => $draft,
		'oversio_delete_data_on_uninstall' => $delete_on,
		'oversio_oauth_enabled'            => $oauth,
		'oversio_oauth_dcr_enabled'        => $oauth_dcr,
		'oversio_ip_allowlist'             => array_values( array_unique( $lines ) ),
	);
}

/**
 * Count how many submitted allowlist lines are invalid and would be dropped on save.
 *
 * Mirrors the sanitizer's line handling — split on newlines, trim, drop blanks — then counts
 * the non-blank lines that fail oversio_is_valid_ip_or_cidr(). Counting invalid lines explicitly
 * (rather than diffing submitted vs. kept counts) keeps a duplicate-but-valid line from being
 * miscounted as a drop. The result drives the save-time warning so an admin who pastes only
 * garbage — collapsing the list to empty, which means allow-all — is told instead of seeing a
 * bare "Saved".
 *
 * @param string $raw Raw newline-separated allowlist text as posted.
 * @return int Number of non-blank lines that are not a valid IP or CIDR range.
 */
function oversio_count_dropped_ip_lines( string $raw ): int {
	$dropped = 0;
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( sanitize_text_field( (string) $line ) );
		if ( '' === $line ) {
			continue;
		}
		if ( ! oversio_is_valid_ip_or_cidr( $line ) ) {
			++$dropped;
		}
	}
	return $dropped;
}

/**
 * AJAX: save the safety settings.
 *
 * Nonce + manage_options gated. The sanitizer bounds every value, so the stored options are
 * always safe. The cleaned values (with the allowlist as both an array and a newline string
 * for the textarea) are echoed back, along with a count of dropped invalid IP lines, so the UI
 * can warn when lines were silently removed — including the dangerous case where every line is
 * invalid and the list collapses to empty (allow-all).
 *
 * @return void
 */
function oversio_ajax_save_settings(): void {
	check_ajax_referer( 'oversio_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'oversio-agent-abilities' ) ), 403 );
	}
	$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$clean  = oversio_sanitize_settings_input( $posted );

	$raw_allowlist = isset( $posted['oversio_ip_allowlist'] ) ? (string) $posted['oversio_ip_allowlist'] : '';
	$dropped       = oversio_count_dropped_ip_lines( $raw_allowlist );

	update_option( 'oversio_rate_limit_per_min', $clean['oversio_rate_limit_per_min'] );
	update_option( 'oversio_max_title_len', $clean['oversio_max_title_len'] );
	update_option( 'oversio_log_retention_days', $clean['oversio_log_retention_days'] );
	update_option( 'oversio_force_draft', $clean['oversio_force_draft'] );
	update_option( 'oversio_delete_data_on_uninstall', $clean['oversio_delete_data_on_uninstall'] );
	update_option( 'oversio_oauth_enabled', $clean['oversio_oauth_enabled'] );
	update_option( 'oversio_oauth_dcr_enabled', $clean['oversio_oauth_dcr_enabled'] );
	update_option( 'oversio_ip_allowlist', $clean['oversio_ip_allowlist'] );

	wp_send_json_success(
		array(
			'oversio_rate_limit_per_min'       => $clean['oversio_rate_limit_per_min'],
			'oversio_max_title_len'            => $clean['oversio_max_title_len'],
			'oversio_log_retention_days'       => $clean['oversio_log_retention_days'],
			'oversio_force_draft'              => $clean['oversio_force_draft'],
			'oversio_delete_data_on_uninstall' => $clean['oversio_delete_data_on_uninstall'],
			'oversio_oauth_enabled'            => $clean['oversio_oauth_enabled'],
			'oversio_oauth_dcr_enabled'        => $clean['oversio_oauth_dcr_enabled'],
			'oversio_ip_allowlist'             => $clean['oversio_ip_allowlist'],
			'oversio_ip_allowlist_text'        => implode( "\n", $clean['oversio_ip_allowlist'] ),
			'oversio_ip_dropped'               => $dropped,
		)
	);
}

/**
 * Every option key that a plugin reset clears.
 *
 * This is the single source of truth for "what a reset clears" — the enabled abilities, the
 * exposed post types and meta keys, and the safety controls. It deliberately excludes the
 * activity log (its own table) and anything outside the plugin's own option namespace, and it
 * never lists users or content.
 *
 * One configuration option is intentionally NOT listed here: `oversio_delete_data_on_uninstall`.
 * That flag governs whether uninstall wipes the site's data, so a "reset to defaults" must not
 * silently flip the operator's data-retention choice — it is preserved across a reset by design.
 * Because of that omission this is the reset set, not literally every stored option. Keep it in
 * sync when a new resettable configuration option is introduced.
 *
 * @return list<string> Option names a reset clears, in a stable order.
 */
function oversio_config_option_names(): array {
	return array(
		'oversio_enabled_abilities',
		'oversio_allowed_post_types',
		'oversio_allowed_meta_keys',
		'oversio_rate_limit_per_min',
		'oversio_max_title_len',
		'oversio_log_retention_days',
		'oversio_force_draft',
		'oversio_oauth_enabled',
		'oversio_oauth_dcr_enabled',
		'oversio_ip_allowlist',
		'oversio_denied_meta_keys',
		'oversio_exposed_user_meta_keys',
		'oversio_denied_user_meta_keys',
		'oversio_exposed_term_meta_keys',
		'oversio_denied_term_meta_keys',
	);
}

/**
 * Remove this plugin's data for the current blog.
 *
 * Reads the per-site data-retention flag first. When the flag is not set (the default),
 * data is kept and the function returns immediately so nothing is deleted. When the flag
 * is explicitly turned on by the site admin, the full teardown runs: every configuration
 * option, the activity-log table, and the four OAuth tables are all removed. The flag
 * itself is deleted last so it cannot leak after uninstall.
 *
 * Called once per site by oversio_run_uninstall() in uninstall.php. Declared here (settings.php)
 * so the PHPUnit suite can call it directly without bootstrapping the uninstall context.
 *
 * @return void
 */
function oversio_uninstall_site_data(): void {
	if ( ! get_option( 'oversio_delete_data_on_uninstall', false ) ) {
		return;
	}

	oversio_uninstall_site();
	oversio_drop_oauth_tables();
	delete_option( 'oversio_oauth_schema_version' );
	wp_clear_scheduled_hook( 'oversio_oauth_cleanup' );
	delete_option( 'oversio_delete_data_on_uninstall' );
}

/**
 * Reset the plugin to its out-of-the-box state.
 *
 * Deletes every configuration option (so each setting falls back to its safe default), empties the
 * activity log, and empties the four OAuth data tables (clients, codes, access tokens, consents).
 * It deliberately does NOT touch the agent user, its Application Passwords, or any content the
 * agent created (posts, terms, media, etc.) — this clears the plugin's own configuration, audit
 * trail, and OAuth state only. The activity-log and OAuth tables themselves are kept (rows cleared)
 * so the plugin keeps working immediately afterwards. This cannot be undone.
 *
 * @return void
 */
function oversio_reset_plugin(): void {
	foreach ( oversio_config_option_names() as $option ) {
		delete_option( $option );
	}
	oversio_clear_activity_log();
	oversio_truncate_oauth_tables();
}

/**
 * AJAX: reset the plugin to defaults.
 *
 * Nonce + manage_options gated, mirroring the other admin actions. The destructive scope is fixed
 * server-side (config options + activity log only) — there is no client-supplied target, so a
 * tampered request can never widen what gets deleted. The browser confirms intent before calling.
 *
 * @return void
 */
function oversio_ajax_reset_plugin(): void {
	check_ajax_referer( 'oversio_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'oversio-agent-abilities' ) ), 403 );
	}
	oversio_reset_plugin();
	wp_send_json_success(
		array(
			'message' => __( 'Plugin reset. Every setting and the activity log were cleared; your agent user and its content were left alone.', 'oversio-agent-abilities' ),
		)
	);
}

/**
 * Render the Settings tab: a card of labelled rows for the four optional safety controls, plus an OAuth card with its two toggles.
 *
 * Each control reads its current value through its safety.php getter (filterable, bounded,
 * default off) and writes via the oversio_save_settings AJAX action. Everything is escaped on
 * output; the IP-lockout caution is rendered through the shared warning notice next to the
 * field it warns about.
 *
 * @return void
 */
function oversio_render_settings_tab(): void {
	echo '<div class="oversio-settings">';
	wp_nonce_field( 'oversio_admin', 'oversio_settings_nonce' );

	oversio_render_notice(
		'info',
		__( 'These safety controls are optional. They all start off, and the plugin runs fine without any of them. Turn on only what you need.', 'oversio-agent-abilities' )
	);

	echo '<form id="oversio-settings-form">';

	// Safety controls section. Each labelled control is a pre-built row passed to the shared
	// oversio_render_section() component; the <input> name/value/checked() contracts are unchanged,
	// only the surrounding wrapper moved onto the shared .oversio-section component.
	ob_start();

	// Rate limit.
	oversio_render_set_row(
		array(
			'label'   => __( 'Rate limit', 'oversio-agent-abilities' ),
			'opt'     => __( 'Per minute', 'oversio-agent-abilities' ),
			'control' => sprintf(
				'<input type="number" id="oversio-rate-limit" name="oversio_rate_limit_per_min" class="small-text" min="0" step="1" value="%s">',
				esc_attr( (string) oversio_rate_limit_per_min() )
			),
			'help'    => __( 'How many agent calls one connection can make per minute. Set it to 0 to leave the limit off.', 'oversio-agent-abilities' ),
		)
	);

	// IP allowlist — the control bundles the textarea plus the lockout warning notice.
	ob_start();
	oversio_render_notice(
		'warning',
		__( 'Before you save a list with anything in it, add the IP address your AI client connects from. As soon as this list has one entry, any request from an address that is not on it is blocked, including your own agent. Get it wrong and every agent call stops.', 'oversio-agent-abilities' )
	);
	$ip_notice = (string) ob_get_clean();
	oversio_render_set_row(
		array(
			'label'   => __( 'IP allowlist', 'oversio-agent-abilities' ),
			'opt'     => __( 'One per line', 'oversio-agent-abilities' ),
			'control' => sprintf(
				'<textarea id="oversio-ip-allowlist" name="oversio_ip_allowlist" rows="5" class="large-text code">%s</textarea>',
				esc_textarea( implode( "\n", oversio_ip_allowlist() ) )
			) . '<p class="help">' . esc_html__( 'One IP address or CIDR range per line. Leave it empty to allow connections from anywhere. When you save, any line that is not a valid IP or range is dropped.', 'oversio-agent-abilities' ) . '</p>' . $ip_notice,
		)
	);

	// Force draft. The toggle switch wraps the checkbox; the <input> keeps its exact
	// name/value/checked() contract — the save handler and its tests bind to that, not this markup.
	oversio_render_set_row(
		array(
			'label'   => __( 'Force draft on create', 'oversio-agent-abilities' ),
			'control' => '<label class="oversio-switch"><input type="checkbox" id="oversio-force-draft" name="oversio_force_draft" value="1" ' . checked( oversio_force_draft(), true, false ) . '><span class="oversio-switch-track"></span></label> '
				. '<label for="oversio-force-draft">' . esc_html__( 'Save everything an agent creates as a draft, no matter what status the request asked for.', 'oversio-agent-abilities' ) . '</label>',
			'help'    => __( 'Turn this on if you want to look over agent-created content before it goes live.', 'oversio-agent-abilities' ),
		)
	);

	// Max title length.
	oversio_render_set_row(
		array(
			'label'   => __( 'Maximum title length', 'oversio-agent-abilities' ),
			'opt'     => __( 'Characters', 'oversio-agent-abilities' ),
			'control' => sprintf(
				'<input type="number" id="oversio-max-title" name="oversio_max_title_len" class="small-text" min="0" step="1" value="%s">',
				esc_attr( (string) oversio_max_title_len() )
			),
			'help'    => __( 'The longest title, in characters, an agent can set. Set it to 0 to leave the limit off.', 'oversio-agent-abilities' ),
		)
	);

	// Activity-log retention. A daily job removes entries older than this many days.
	oversio_render_set_row(
		array(
			'label'   => __( 'Keep activity log for', 'oversio-agent-abilities' ),
			'opt'     => __( 'Days', 'oversio-agent-abilities' ),
			'control' => sprintf(
				'<input type="number" id="oversio-log-retention" name="oversio_log_retention_days" class="small-text" min="0" max="3650" step="1" value="%s">',
				esc_attr( (string) oversio_log_retention_days() )
			),
			'help'    => __( 'How many days of activity to keep. A daily cleanup removes anything older. Set it to 0 to keep every entry.', 'oversio-agent-abilities' ),
		)
	);

	// Delete data on uninstall. Same toggle-switch contract as force draft; the <input>
	// name/value/checked() is what the save handler and uninstall.php bind to, not this markup.
	oversio_render_set_row(
		array(
			'label'   => __( 'Delete data on uninstall', 'oversio-agent-abilities' ),
			'control' => '<label class="oversio-switch"><input type="checkbox" id="oversio-delete-data-on-uninstall" name="oversio_delete_data_on_uninstall" value="1" ' . checked( (bool) get_option( 'oversio_delete_data_on_uninstall', false ), true, false ) . '><span class="oversio-switch-track"></span></label> '
				. '<label for="oversio-delete-data-on-uninstall">' . esc_html__( 'Permanently remove all plugin data when the plugin is deleted.', 'oversio-agent-abilities' ) . '</label>',
			'help'    => __( 'When this is off (the default), your settings, activity log, and OAuth data are kept if you delete the plugin, so a reinstall picks up your configuration. Turn it on only if you want everything removed. This cannot be undone.', 'oversio-agent-abilities' ),
		)
	);

	$safety_body = (string) ob_get_clean();
	oversio_render_section(
		array(
			'icon'  => 'shield',
			'title' => __( 'Safety controls', 'oversio-agent-abilities' ),
			'body'  => $safety_body,
		)
	);

	// OAuth: two additive switch rows. Same .oversio-switch / .oversio-set-row markup as the
	// force-draft row above; the <input> name/value/checked() contract is what the save
	// handler binds to, not this markup. Both readers default on (discovery.php).
	ob_start();

	// Enable OAuth. The row title and the sentence label each carry an id, and the checkbox
	// points at both with aria-labelledby, so the toggle's accessible name is the title plus the
	// descriptive sentence. The sentence <label for> stays put — it is the existing single
	// association, not a second one, so the redundant-`for` defect cannot recur. The set-row
	// label carries the title id here so the existing aria-labelledby reference resolves.
	echo '<div class="oversio-set-row">';
	echo '<div class="oversio-set-label" id="oversio-oauth-enabled-title">' . esc_html__( 'Enable OAuth', 'oversio-agent-abilities' ) . '</div>';
	echo '<div class="oversio-set-control">';
	echo '<label class="oversio-switch"><input type="checkbox" id="oversio-oauth-enabled" name="oversio_oauth_enabled" value="1" aria-labelledby="oversio-oauth-enabled-title oversio-oauth-enabled-desc" ' . checked( oversio_oauth_enabled(), true, false ) . '><span class="oversio-switch-track"></span></label> ';
	echo '<label for="oversio-oauth-enabled" id="oversio-oauth-enabled-desc">' . esc_html__( 'Let agents connect by pasting your site URL.', 'oversio-agent-abilities' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Let agents connect by pasting your site URL. Application Passwords keep working either way.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div></div>';

	// Enable dynamic client registration. Same tie-up as the row above.
	echo '<div class="oversio-set-row">';
	echo '<div class="oversio-set-label" id="oversio-oauth-dcr-enabled-title">' . esc_html__( 'Enable dynamic client registration', 'oversio-agent-abilities' ) . '</div>';
	echo '<div class="oversio-set-control">';
	echo '<label class="oversio-switch"><input type="checkbox" id="oversio-oauth-dcr-enabled" name="oversio_oauth_dcr_enabled" value="1" aria-labelledby="oversio-oauth-dcr-enabled-title oversio-oauth-dcr-enabled-desc" ' . checked( oversio_oauth_dcr_enabled(), true, false ) . '><span class="oversio-switch-track"></span></label> ';
	echo '<label for="oversio-oauth-dcr-enabled" id="oversio-oauth-dcr-enabled-desc">' . esc_html__( 'Allow agents to self-register a client automatically.', 'oversio-agent-abilities' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Allow agents to self-register a client automatically. Turn off to require manual client setup.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div></div>';

	$oauth_body = (string) ob_get_clean();
	oversio_render_section(
		array(
			'icon'  => 'connection',
			'title' => __( 'OAuth', 'oversio-agent-abilities' ),
			'body'  => $oauth_body,
		)
	);

	oversio_render_notice(
		'warning',
		__( 'These controls change how agent requests behave. Test a connection after you change anything here so you do not lock yourself out or quietly drop valid requests.', 'oversio-agent-abilities' )
	);

	echo '<p><button type="submit" class="oversio-btn oversio-btn-primary">' . esc_html__( 'Save settings', 'oversio-agent-abilities' ) . '</button> <span class="oversio-save-status" aria-live="polite"></span></p>';
	echo '</form>';

	// Danger zone — a destructive, irreversible reset. Sits outside the settings <form> so the
	// button (type=button, wired in admin.js with a confirm step) never submits the form. It uses
	// the shared .oversio-section .oversio-card classes for spacing parity, plus the .oversio-danger
	// red-accent modifier the component does not emit, so the markup is hand-rolled rather than
	// run through oversio_render_section(). Same .oversio-card-head/.oversio-card-pad structure the
	// component produces, so the spacing matches the other two sections.
	echo '<section class="oversio-section oversio-card oversio-danger">';
	echo '<div class="oversio-card-head">';
	echo '<span class="oversio-card-head-ic">';
	echo oversio_icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '<div class="oversio-card-head-text"><h3 class="oversio-card-head-title">' . esc_html__( 'Danger zone', 'oversio-agent-abilities' ) . '</h3></div>';
	echo '</div>';
	echo '<div class="oversio-card-pad oversio-section-body">';
	echo '<div class="oversio-set-row">';
	echo '<div class="oversio-set-label">' . esc_html__( 'Reset plugin', 'oversio-agent-abilities' ) . '<span class="opt">' . esc_html__( 'Cannot be undone', 'oversio-agent-abilities' ) . '</span></div>';
	echo '<div class="oversio-set-control">';
	echo '<button type="button" id="oversio-reset-plugin" class="button button-link-delete">' . esc_html__( 'Reset plugin to defaults', 'oversio-agent-abilities' ) . '</button> <span class="oversio-reset-status" aria-live="polite"></span>';
	echo '<p class="help">' . esc_html__( 'Clears every plugin setting — enabled abilities, exposed content types and meta keys, and all safety controls — and empties the activity log. Your agent user and anything it created (posts and other content) are left untouched. This cannot be undone.', 'oversio-agent-abilities' ) . '</p>';
	echo '</div></div>';

	echo '</div>';
	echo '</section>';

	echo '</div>';
}
