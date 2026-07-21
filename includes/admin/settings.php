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
const AAFM_SETTINGS_NUMERIC_MAX = 100000;

/**
 * Sanitize the posted Settings form into a clean, bounded, validated array.
 *
 * Every value is coerced to a safe shape regardless of what was posted:
 * - aafm_rate_limit_per_min, aafm_max_title_len: floored at 0 (negative/garbage -> 0) and
 *   capped at AAFM_SETTINGS_NUMERIC_MAX. Note max( 0, (int) ) rather than absint(), so a
 *   negative value clamps down to 0 instead of flipping to its positive magnitude.
 * - aafm_force_draft: a plain bool from presence of the field (unchecked checkbox -> false).
 * - aafm_oauth_enabled, aafm_oauth_dcr_enabled: the STRING '1' when the checkbox is present,
 *   '0' when absent. The OAuth readers default on and treat every falsy stored form as off, so
 *   the off state must be the literal '0' string - a PHP bool false would not store as false on
 *   a never-created option, leaving the toggle stuck on.
 * - aafm_ip_allowlist: split on newlines, trimmed, blanks dropped, and every surviving line
 *   must clear aafm_is_valid_ip_or_cidr(). Invalid lines are dropped (fail-closed), so a
 *   stored non-empty list is always made up entirely of usable entries.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return array{aafm_rate_limit_per_min:int,aafm_max_title_len:int,aafm_log_retention_days:int,aafm_force_draft:bool,aafm_block_guard_strict:bool,aafm_delete_data_on_uninstall:bool,aafm_oauth_enabled:string,aafm_oauth_dcr_enabled:string,aafm_ip_allowlist:list<string>}
 */
function aafm_sanitize_settings_input( array $posted ): array {
	$rate  = min( AAFM_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['aafm_rate_limit_per_min'] ?? 0 ) ) );
	$title = min( AAFM_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['aafm_max_title_len'] ?? 0 ) ) );
	// Retention has its own tighter ceiling (ten years); 0 keeps every entry forever.
	$retention   = min( 3650, max( 0, (int) ( $posted['aafm_log_retention_days'] ?? 30 ) ) );
	$draft       = ! empty( $posted['aafm_force_draft'] );
	$block_guard = ! empty( $posted['aafm_block_guard_strict'] );
	$delete_on   = ! empty( $posted['aafm_delete_data_on_uninstall'] );

	$oauth     = empty( $posted['aafm_oauth_enabled'] ) ? '0' : '1';
	$oauth_dcr = empty( $posted['aafm_oauth_dcr_enabled'] ) ? '0' : '1';

	$raw   = isset( $posted['aafm_ip_allowlist'] ) ? (string) $posted['aafm_ip_allowlist'] : '';
	$lines = array();
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( sanitize_text_field( (string) $line ) );
		if ( '' === $line || ! aafm_is_valid_ip_or_cidr( $line ) ) {
			continue;
		}
		$lines[] = $line;
	}

	return array(
		'aafm_rate_limit_per_min'       => $rate,
		'aafm_max_title_len'            => $title,
		'aafm_log_retention_days'       => $retention,
		'aafm_force_draft'              => $draft,
		'aafm_block_guard_strict'       => $block_guard,
		'aafm_delete_data_on_uninstall' => $delete_on,
		'aafm_oauth_enabled'            => $oauth,
		'aafm_oauth_dcr_enabled'        => $oauth_dcr,
		'aafm_ip_allowlist'             => array_values( array_unique( $lines ) ),
	);
}

/**
 * Count how many submitted allowlist lines are invalid and would be dropped on save.
 *
 * Mirrors the sanitizer's line handling - split on newlines, trim, drop blanks - then counts
 * the non-blank lines that fail aafm_is_valid_ip_or_cidr(). Counting invalid lines explicitly
 * (rather than diffing submitted vs. kept counts) keeps a duplicate-but-valid line from being
 * miscounted as a drop. The result drives the save-time warning so an admin who pastes only
 * garbage - collapsing the list to empty, which means allow-all - is told instead of seeing a
 * bare "Saved".
 *
 * @param string $raw Raw newline-separated allowlist text as posted.
 * @return int Number of non-blank lines that are not a valid IP or CIDR range.
 */
function aafm_count_dropped_ip_lines( string $raw ): int {
	$dropped = 0;
	foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( sanitize_text_field( (string) $line ) );
		if ( '' === $line ) {
			continue;
		}
		if ( ! aafm_is_valid_ip_or_cidr( $line ) ) {
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
 * can warn when lines were silently removed - including the dangerous case where every line is
 * invalid and the list collapses to empty (allow-all).
 *
 * @return void
 */
function aafm_ajax_save_settings(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$clean  = aafm_sanitize_settings_input( $posted );

	$raw_allowlist = isset( $posted['aafm_ip_allowlist'] ) ? (string) $posted['aafm_ip_allowlist'] : '';
	$dropped       = aafm_count_dropped_ip_lines( $raw_allowlist );

	update_option( 'aafm_rate_limit_per_min', $clean['aafm_rate_limit_per_min'] );
	update_option( 'aafm_max_title_len', $clean['aafm_max_title_len'] );
	update_option( 'aafm_log_retention_days', $clean['aafm_log_retention_days'] );
	update_option( 'aafm_force_draft', $clean['aafm_force_draft'] );
	update_option( 'aafm_block_guard_strict', $clean['aafm_block_guard_strict'] );
	update_option( 'aafm_delete_data_on_uninstall', $clean['aafm_delete_data_on_uninstall'] );
	update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );
	update_option( 'aafm_oauth_dcr_enabled', $clean['aafm_oauth_dcr_enabled'] );
	update_option( 'aafm_ip_allowlist', $clean['aafm_ip_allowlist'] );

	wp_send_json_success(
		array(
			'aafm_rate_limit_per_min'       => $clean['aafm_rate_limit_per_min'],
			'aafm_max_title_len'            => $clean['aafm_max_title_len'],
			'aafm_log_retention_days'       => $clean['aafm_log_retention_days'],
			'aafm_force_draft'              => $clean['aafm_force_draft'],
			'aafm_block_guard_strict'       => $clean['aafm_block_guard_strict'],
			'aafm_delete_data_on_uninstall' => $clean['aafm_delete_data_on_uninstall'],
			'aafm_oauth_enabled'            => $clean['aafm_oauth_enabled'],
			'aafm_oauth_dcr_enabled'        => $clean['aafm_oauth_dcr_enabled'],
			'aafm_ip_allowlist'             => $clean['aafm_ip_allowlist'],
			'aafm_ip_allowlist_text'        => implode( "\n", $clean['aafm_ip_allowlist'] ),
			'aafm_ip_dropped'               => $dropped,
		)
	);
}

/**
 * Every option key that a plugin reset clears.
 *
 * This is the single source of truth for "what a reset clears" - the enabled abilities, the
 * exposed post types and meta keys, and the safety controls. It deliberately excludes the
 * activity log (its own table) and anything outside the plugin's own option namespace, and it
 * never lists users or content.
 *
 * One configuration option is intentionally NOT listed here: `aafm_delete_data_on_uninstall`.
 * That flag governs whether uninstall wipes the site's data, so a "reset to defaults" must not
 * silently flip the operator's data-retention choice - it is preserved across a reset by design.
 * Because of that omission this is the reset set, not literally every stored option. Keep it in
 * sync when a new resettable configuration option is introduced.
 *
 * @return list<string> Option names a reset clears, in a stable order.
 */
function aafm_config_option_names(): array {
	return array(
		'aafm_enabled_abilities',
		'aafm_enabled_bridged_abilities',
		'aafm_allowed_post_types',
		'aafm_allowed_meta_keys',
		'aafm_rate_limit_per_min',
		'aafm_max_title_len',
		'aafm_log_retention_days',
		'aafm_force_draft',
		'aafm_block_guard_strict',
		'aafm_oauth_enabled',
		'aafm_oauth_dcr_enabled',
		'aafm_ip_allowlist',
		'aafm_denied_meta_keys',
		'aafm_exposed_user_meta_keys',
		'aafm_denied_user_meta_keys',
		'aafm_exposed_term_meta_keys',
		'aafm_denied_term_meta_keys',
		// First-run onboarding state. Clearing these on reset returns the site to first-run, so the
		// Quick Connect wizard opens again; the menu-pointer flag is included so uninstall cleans it too.
		'aafm_quickconnect_finished',
		'aafm_quickconnect_dismissed',
		'aafm_menu_pointer_active',
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
 * Called once per site by aafm_run_uninstall() in uninstall.php. Declared here (settings.php)
 * so the PHPUnit suite can call it directly without bootstrapping the uninstall context.
 *
 * @return void
 */
function aafm_uninstall_site_data(): void {
	if ( ! get_option( 'aafm_delete_data_on_uninstall', false ) ) {
		return;
	}

	aafm_uninstall_site();
	aafm_drop_oauth_tables();
	delete_option( 'aafm_oauth_schema_version' );
	delete_option( 'aafm_activity_log_schema_version' );
	wp_clear_scheduled_hook( 'aafm_oauth_cleanup' );
	delete_option( 'aafm_delete_data_on_uninstall' );
}

/**
 * Reset the plugin to its out-of-the-box state.
 *
 * Deletes every configuration option (so each setting falls back to its safe default), empties the
 * activity log, and empties the four OAuth data tables (clients, codes, access tokens, consents).
 * It deliberately does NOT touch the agent user, its Application Passwords, or any content the
 * agent created (posts, terms, media, etc.) - this clears the plugin's own configuration, audit
 * trail, and OAuth state only. The activity-log and OAuth tables themselves are kept (rows cleared)
 * so the plugin keeps working immediately afterwards. This cannot be undone.
 *
 * Wiping the activity log is itself security-relevant, so a tamper-evident marker is written
 * after the clear (L4, shared with the direct "Clear log" action in
 * aafm_ajax_clear_log()) - the emptied log always shows who reset the plugin and when.
 *
 * @return void
 */
function aafm_reset_plugin(): void {
	foreach ( aafm_config_option_names() as $option ) {
		delete_option( $option );
	}
	aafm_clear_activity_log();
	aafm_log_activity_cleared_marker();
	aafm_truncate_oauth_tables();
}

/**
 * AJAX: reset the plugin to defaults.
 *
 * Nonce + manage_options gated, mirroring the other admin actions. The destructive scope is fixed
 * server-side (config options + activity log only) - there is no client-supplied target, so a
 * tampered request can never widen what gets deleted. The browser confirms intent before calling.
 *
 * @return void
 */
function aafm_ajax_reset_plugin(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	aafm_reset_plugin();
	wp_send_json_success(
		array(
			'message' => __( 'Plugin reset. Every setting and the activity log were cleared; your agent user and its content were left alone.', 'agent-abilities-for-mcp' ),
		)
	);
}

/**
 * Render the Settings tab: a card of labelled rows for the four optional safety controls, plus an OAuth card with its two toggles.
 *
 * Each control reads its current value through its safety.php getter (filterable, bounded,
 * default off) and writes via the aafm_save_settings AJAX action. Everything is escaped on
 * output; the IP-lockout caution is rendered through the shared warning notice next to the
 * field it warns about.
 *
 * @return void
 */
function aafm_render_settings_tab(): void {
	echo '<div class="aafm-settings">';
	wp_nonce_field( 'aafm_admin', 'aafm_settings_nonce' );

	aafm_render_notice(
		'info',
		__( 'These safety controls are optional. They all start off, and the plugin runs fine without any of them. Turn on only what you need.', 'agent-abilities-for-mcp' )
	);

	echo '<form id="aafm-settings-form">';

	// Safety controls section. Each labelled control is a pre-built row passed to the shared
	// aafm_render_section() component; the <input> name/value/checked() contracts are unchanged,
	// only the surrounding wrapper moved onto the shared .aafm-section component.
	ob_start();

	// Rate limit.
	aafm_render_set_row(
		array(
			'label'   => __( 'Rate limit', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Per minute', 'agent-abilities-for-mcp' ),
			'control' => sprintf(
				'<input type="number" id="aafm-rate-limit" name="aafm_rate_limit_per_min" class="small-text" min="0" step="1" value="%s">',
				esc_attr( (string) aafm_rate_limit_per_min() )
			),
			'help'    => __( 'How many agent calls one connection can make per minute. Set it to 0 to leave the limit off.', 'agent-abilities-for-mcp' ),
		)
	);

	// IP allowlist - the control bundles the textarea plus the lockout warning notice.
	ob_start();
	aafm_render_notice(
		'warning',
		__( 'Before you save a list with anything in it, add the IP address your AI client connects from. As soon as this list has one entry, any request from an address that is not on it is blocked, including your own agent. Get it wrong and every agent call stops.', 'agent-abilities-for-mcp' )
	);
	$ip_notice = (string) ob_get_clean();
	aafm_render_set_row(
		array(
			'label'   => __( 'IP allowlist', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'One per line', 'agent-abilities-for-mcp' ),
			'control' => sprintf(
				'<textarea id="aafm-ip-allowlist" name="aafm_ip_allowlist" rows="5" class="large-text code">%s</textarea>',
				esc_textarea( implode( "\n", aafm_ip_allowlist() ) )
			) . '<p class="help">' . esc_html__( 'One IP address or CIDR range per line. Leave it empty to allow connections from anywhere. When you save, any line that is not a valid IP or range is dropped.', 'agent-abilities-for-mcp' ) . '</p>' . $ip_notice,
		)
	);

	// Force draft. The toggle switch wraps the checkbox; the <input> keeps its exact
	// name/value/checked() contract - the save handler and its tests bind to that, not this markup.
	aafm_render_set_row(
		array(
			'label'   => __( 'Force draft on create', 'agent-abilities-for-mcp' ),
			'control' => '<label class="aafm-switch"><input type="checkbox" id="aafm-force-draft" name="aafm_force_draft" value="1" ' . checked( aafm_force_draft(), true, false ) . '><span class="aafm-switch-track"></span></label> '
				. '<label for="aafm-force-draft">' . esc_html__( 'Save everything an agent creates as a draft, no matter what status the request asked for.', 'agent-abilities-for-mcp' ) . '</label>',
			'help'    => __( 'Turn this on if you want to look over agent-created content before it goes live.', 'agent-abilities-for-mcp' ),
		)
	);

	// Strict block validation. Same toggle-switch contract as force draft; the <input>
	// name/value/checked() is what the save handler and its tests bind to, not this markup.
	aafm_render_set_row(
		array(
			'label'   => __( 'Strict block validation', 'agent-abilities-for-mcp' ),
			'control' => '<label class="aafm-switch"><input type="checkbox" id="aafm-block-guard-strict" name="aafm_block_guard_strict" value="1" ' . checked( aafm_block_guard_is_strict(), true, false ) . '><span class="aafm-switch-track"></span></label> '
				. '<label for="aafm-block-guard-strict">' . esc_html__( 'Reject an agent write when its block markup would show as invalid content in the editor.', 'agent-abilities-for-mcp' ) . '</label>',
			'help'    => __( 'When this is off (the default), a write with questionable block markup still saves, and the agent is sent a warning so it can fix its next attempt. Turn it on to refuse the write instead, so nothing that could look broken in the editor is ever stored.', 'agent-abilities-for-mcp' ),
		)
	);

	// Max title length.
	aafm_render_set_row(
		array(
			'label'   => __( 'Maximum title length', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Characters', 'agent-abilities-for-mcp' ),
			'control' => sprintf(
				'<input type="number" id="aafm-max-title" name="aafm_max_title_len" class="small-text" min="0" step="1" value="%s">',
				esc_attr( (string) aafm_max_title_len() )
			),
			'help'    => __( 'The longest title, in characters, an agent can set. Set it to 0 to leave the limit off.', 'agent-abilities-for-mcp' ),
		)
	);

	// Activity-log retention. A daily job removes entries older than this many days.
	aafm_render_set_row(
		array(
			'label'   => __( 'Keep activity log for', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Days', 'agent-abilities-for-mcp' ),
			'control' => sprintf(
				'<input type="number" id="aafm-log-retention" name="aafm_log_retention_days" class="small-text" min="0" max="3650" step="1" value="%s">',
				esc_attr( (string) aafm_log_retention_days() )
			),
			'help'    => __( 'How many days of activity to keep. A daily cleanup removes anything older. Set it to 0 to keep every entry.', 'agent-abilities-for-mcp' ),
		)
	);

	// Delete data on uninstall. Same toggle-switch contract as force draft; the <input>
	// name/value/checked() is what the save handler and uninstall.php bind to, not this markup.
	aafm_render_set_row(
		array(
			'label'   => __( 'Delete data on uninstall', 'agent-abilities-for-mcp' ),
			'control' => '<label class="aafm-switch"><input type="checkbox" id="aafm-delete-data-on-uninstall" name="aafm_delete_data_on_uninstall" value="1" ' . checked( (bool) get_option( 'aafm_delete_data_on_uninstall', false ), true, false ) . '><span class="aafm-switch-track"></span></label> '
				. '<label for="aafm-delete-data-on-uninstall">' . esc_html__( 'Permanently remove all plugin data when the plugin is deleted.', 'agent-abilities-for-mcp' ) . '</label>',
			'help'    => __( 'When this is off (the default), your settings, activity log, and OAuth data are kept if you delete the plugin, so a reinstall picks up your configuration. Turn it on only if you want everything removed. This cannot be undone.', 'agent-abilities-for-mcp' ),
		)
	);

	$safety_body = (string) ob_get_clean();
	aafm_render_section(
		array(
			'icon'  => 'shield',
			'title' => __( 'Safety controls', 'agent-abilities-for-mcp' ),
			'body'  => $safety_body,
		)
	);

	// OAuth: two additive switch rows. Same .aafm-switch / .aafm-set-row markup as the
	// force-draft row above; the <input> name/value/checked() contract is what the save
	// handler binds to, not this markup. Both readers default on (discovery.php).
	ob_start();

	// Enable OAuth. The row title and the sentence label each carry an id, and the checkbox
	// points at both with aria-labelledby, so the toggle's accessible name is the title plus the
	// descriptive sentence. The sentence <label for> stays put - it is the existing single
	// association, not a second one, so the redundant-`for` defect cannot recur. The set-row
	// label carries the title id here so the existing aria-labelledby reference resolves.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label" id="aafm-oauth-enabled-title">' . esc_html__( 'Enable OAuth', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-set-control">';
	echo '<label class="aafm-switch"><input type="checkbox" id="aafm-oauth-enabled" name="aafm_oauth_enabled" value="1" aria-labelledby="aafm-oauth-enabled-title aafm-oauth-enabled-desc" ' . checked( aafm_oauth_enabled(), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	echo '<label for="aafm-oauth-enabled" id="aafm-oauth-enabled-desc">' . esc_html__( 'Let agents connect by pasting your site URL.', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Let agents connect by pasting your site URL. Application Passwords keep working either way.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	// Enable dynamic client registration. Same tie-up as the row above.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label" id="aafm-oauth-dcr-enabled-title">' . esc_html__( 'Enable dynamic client registration', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-set-control">';
	echo '<label class="aafm-switch"><input type="checkbox" id="aafm-oauth-dcr-enabled" name="aafm_oauth_dcr_enabled" value="1" aria-labelledby="aafm-oauth-dcr-enabled-title aafm-oauth-dcr-enabled-desc" ' . checked( aafm_oauth_dcr_enabled(), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	echo '<label for="aafm-oauth-dcr-enabled" id="aafm-oauth-dcr-enabled-desc">' . esc_html__( 'Allow agents to self-register a client automatically.', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Allow agents to self-register a client automatically. Turn off to require manual client setup.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	$oauth_body = (string) ob_get_clean();
	aafm_render_section(
		array(
			'icon'  => 'connection',
			'title' => __( 'OAuth', 'agent-abilities-for-mcp' ),
			'body'  => $oauth_body,
		)
	);

	aafm_render_notice(
		'warning',
		__( 'These controls change how agent requests behave. Test a connection after you change anything here so you do not lock yourself out or quietly drop valid requests.', 'agent-abilities-for-mcp' )
	);

	echo '<p><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save settings', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></p>';
	echo '</form>';

	// Danger zone - a destructive, irreversible reset. Sits outside the settings <form> so the
	// button (type=button, wired in admin.js with a confirm step) never submits the form. It uses
	// the shared .aafm-section .aafm-card classes for spacing parity, plus the .aafm-danger
	// red-accent modifier the component does not emit, so the markup is hand-rolled rather than
	// run through aafm_render_section(). Same .aafm-card-head/.aafm-card-pad structure the
	// component produces, so the spacing matches the other two sections.
	echo '<section class="aafm-section aafm-card aafm-danger">';
	echo '<div class="aafm-card-head">';
	echo '<span class="aafm-card-head-ic">';
	echo wp_kses( aafm_icon( 'warning' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '<div class="aafm-card-head-text"><h3 class="aafm-card-head-title">' . esc_html__( 'Danger zone', 'agent-abilities-for-mcp' ) . '</h3></div>';
	echo '</div>';
	echo '<div class="aafm-card-pad aafm-section-body">';
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label">' . esc_html__( 'Reset plugin', 'agent-abilities-for-mcp' ) . '<span class="opt">' . esc_html__( 'Cannot be undone', 'agent-abilities-for-mcp' ) . '</span></div>';
	echo '<div class="aafm-set-control">';
	echo '<button type="button" id="aafm-reset-plugin" class="button button-link-delete">' . esc_html__( 'Reset plugin to defaults', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-reset-status" aria-live="polite"></span>';
	echo '<p class="help">' . esc_html__( 'Clears every plugin setting - enabled abilities, exposed content types and meta keys, and all safety controls - and empties the activity log. Your agent user and anything it created (posts and other content) are left untouched. This cannot be undone.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	echo '</div>';
	echo '</section>';

	echo '</div>';
}
