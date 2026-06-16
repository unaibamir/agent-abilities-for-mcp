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
 *   the off state must be the literal '0' string — a PHP bool false would not store as false on
 *   a never-created option, leaving the toggle stuck on.
 * - aafm_ip_allowlist: split on newlines, trimmed, blanks dropped, and every surviving line
 *   must clear aafm_is_valid_ip_or_cidr(). Invalid lines are dropped (fail-closed), so a
 *   stored non-empty list is always made up entirely of usable entries.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return array{aafm_rate_limit_per_min:int,aafm_max_title_len:int,aafm_force_draft:bool,aafm_oauth_enabled:string,aafm_oauth_dcr_enabled:string,aafm_ip_allowlist:list<string>}
 */
function aafm_sanitize_settings_input( array $posted ): array {
	$rate  = min( AAFM_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['aafm_rate_limit_per_min'] ?? 0 ) ) );
	$title = min( AAFM_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['aafm_max_title_len'] ?? 0 ) ) );
	$draft = ! empty( $posted['aafm_force_draft'] );

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
		'aafm_rate_limit_per_min' => $rate,
		'aafm_max_title_len'      => $title,
		'aafm_force_draft'        => $draft,
		'aafm_oauth_enabled'      => $oauth,
		'aafm_oauth_dcr_enabled'  => $oauth_dcr,
		'aafm_ip_allowlist'       => array_values( array_unique( $lines ) ),
	);
}

/**
 * Count how many submitted allowlist lines are invalid and would be dropped on save.
 *
 * Mirrors the sanitizer's line handling — split on newlines, trim, drop blanks — then counts
 * the non-blank lines that fail aafm_is_valid_ip_or_cidr(). Counting invalid lines explicitly
 * (rather than diffing submitted vs. kept counts) keeps a duplicate-but-valid line from being
 * miscounted as a drop. The result drives the save-time warning so an admin who pastes only
 * garbage — collapsing the list to empty, which means allow-all — is told instead of seeing a
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
 * can warn when lines were silently removed — including the dangerous case where every line is
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
	update_option( 'aafm_force_draft', $clean['aafm_force_draft'] );
	update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );
	update_option( 'aafm_oauth_dcr_enabled', $clean['aafm_oauth_dcr_enabled'] );
	update_option( 'aafm_ip_allowlist', $clean['aafm_ip_allowlist'] );

	wp_send_json_success(
		array(
			'aafm_rate_limit_per_min' => $clean['aafm_rate_limit_per_min'],
			'aafm_max_title_len'      => $clean['aafm_max_title_len'],
			'aafm_force_draft'        => $clean['aafm_force_draft'],
			'aafm_oauth_enabled'      => $clean['aafm_oauth_enabled'],
			'aafm_oauth_dcr_enabled'  => $clean['aafm_oauth_dcr_enabled'],
			'aafm_ip_allowlist'       => $clean['aafm_ip_allowlist'],
			'aafm_ip_allowlist_text'  => implode( "\n", $clean['aafm_ip_allowlist'] ),
			'aafm_ip_dropped'         => $dropped,
		)
	);
}

/**
 * Every option key that holds plugin configuration.
 *
 * This is the single source of truth for "what a reset clears" — the enabled abilities, the
 * exposed post types and meta keys, and the four safety controls. It deliberately excludes the
 * activity log (its own table) and anything outside the plugin's own option namespace, and it
 * never lists users or content. Keep it in sync when a new configuration option is introduced.
 *
 * @return list<string> Option names, in a stable order.
 */
function aafm_config_option_names(): array {
	return array(
		'aafm_enabled_abilities',
		'aafm_allowed_post_types',
		'aafm_allowed_meta_keys',
		'aafm_rate_limit_per_min',
		'aafm_max_title_len',
		'aafm_force_draft',
		'aafm_oauth_enabled',
		'aafm_oauth_dcr_enabled',
		'aafm_ip_allowlist',
	);
}

/**
 * Reset the plugin to its out-of-the-box state.
 *
 * Deletes every configuration option (so each setting falls back to its safe default) and empties
 * the activity log. It deliberately does NOT touch the agent user, its Application Passwords, or
 * any content the agent created (posts, terms, media, etc.) — this clears the plugin's own
 * configuration and audit trail only. The activity-log table itself is kept (rows truncated) so
 * logging keeps working immediately afterwards. This cannot be undone.
 *
 * @return void
 */
function aafm_reset_plugin(): void {
	foreach ( aafm_config_option_names() as $option ) {
		delete_option( $option );
	}
	aafm_clear_activity_log();
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
 * Render the Settings tab: a card of labelled rows for the four optional safety controls.
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
	echo '<section class="aafm-card">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon">';
	echo aafm_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '<h2>' . esc_html__( 'Safety controls', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '</div>';

	// Rate limit.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label"><label for="aafm-rate-limit">' . esc_html__( 'Rate limit', 'agent-abilities-for-mcp' ) . '</label><span class="opt">' . esc_html__( 'Per minute', 'agent-abilities-for-mcp' ) . '</span></div>';
	echo '<div class="aafm-set-control">';
	printf(
		'<input type="number" id="aafm-rate-limit" name="aafm_rate_limit_per_min" class="small-text" min="0" step="1" value="%s">',
		esc_attr( (string) aafm_rate_limit_per_min() )
	);
	echo '<p class="help">' . esc_html__( 'How many agent calls one connection can make per minute. Set it to 0 to leave the limit off.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	// IP allowlist.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label"><label for="aafm-ip-allowlist">' . esc_html__( 'IP allowlist', 'agent-abilities-for-mcp' ) . '</label><span class="opt">' . esc_html__( 'One per line', 'agent-abilities-for-mcp' ) . '</span></div>';
	echo '<div class="aafm-set-control">';
	printf(
		'<textarea id="aafm-ip-allowlist" name="aafm_ip_allowlist" rows="5" class="large-text code">%s</textarea>',
		esc_textarea( implode( "\n", aafm_ip_allowlist() ) )
	);
	echo '<p class="help">' . esc_html__( 'One IP address or CIDR range per line. Leave it empty to allow connections from anywhere. When you save, any line that is not a valid IP or range is dropped.', 'agent-abilities-for-mcp' ) . '</p>';
	aafm_render_notice(
		'warning',
		__( 'Before you save a list with anything in it, add the IP address your AI client connects from. As soon as this list has one entry, any request from an address that is not on it is blocked, including your own agent. Get it wrong and every agent call stops.', 'agent-abilities-for-mcp' )
	);
	echo '</div></div>';

	// Force draft.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label">' . esc_html__( 'Force draft on create', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-set-control">';
	// Toggle switch wraps the checkbox. The <input> keeps its exact name/value/checked()
	// contract — the save handler and its tests bind to that, not to this markup.
	echo '<label class="aafm-switch"><input type="checkbox" id="aafm-force-draft" name="aafm_force_draft" value="1" ' . checked( aafm_force_draft(), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	echo '<label for="aafm-force-draft">' . esc_html__( 'Save everything an agent creates as a draft, no matter what status the request asked for.', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Turn this on if you want to look over agent-created content before it goes live.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	// Max title length.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label"><label for="aafm-max-title">' . esc_html__( 'Maximum title length', 'agent-abilities-for-mcp' ) . '</label><span class="opt">' . esc_html__( 'Characters', 'agent-abilities-for-mcp' ) . '</span></div>';
	echo '<div class="aafm-set-control">';
	printf(
		'<input type="number" id="aafm-max-title" name="aafm_max_title_len" class="small-text" min="0" step="1" value="%s">',
		esc_attr( (string) aafm_max_title_len() )
	);
	echo '<p class="help">' . esc_html__( 'The longest title, in characters, an agent can set. Set it to 0 to leave the limit off.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	echo '</section>';

	// OAuth: two additive switch rows. Same .aafm-switch / .aafm-set-row markup as the
	// force-draft row above; the <input> name/value/checked() contract is what the save
	// handler binds to, not this markup. Both readers default on (discovery.php).
	echo '<section class="aafm-card">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon">';
	echo aafm_icon( 'connection' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '<h2>' . esc_html__( 'OAuth', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '</div>';

	// Enable OAuth.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label">' . esc_html__( 'Enable OAuth', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-set-control">';
	echo '<label class="aafm-switch"><input type="checkbox" id="aafm-oauth-enabled" name="aafm_oauth_enabled" value="1" ' . checked( aafm_oauth_enabled(), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	echo '<label for="aafm-oauth-enabled">' . esc_html__( 'Let agents connect by pasting your site URL.', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Let agents connect by pasting your site URL. Application Passwords keep working either way.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	// Enable dynamic client registration.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label">' . esc_html__( 'Enable dynamic client registration', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-set-control">';
	echo '<label class="aafm-switch"><input type="checkbox" id="aafm-oauth-dcr-enabled" name="aafm_oauth_dcr_enabled" value="1" ' . checked( aafm_oauth_dcr_enabled(), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	echo '<label for="aafm-oauth-dcr-enabled">' . esc_html__( 'Allow agents to self-register a client automatically.', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'Allow agents to self-register a client automatically. Turn off to require manual client setup.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	echo '</section>';

	aafm_render_notice(
		'warning',
		__( 'These controls change how agent requests behave. Test a connection after you change anything here so you do not lock yourself out or quietly drop valid requests.', 'agent-abilities-for-mcp' )
	);

	echo '<p><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save settings', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></p>';
	echo '</form>';

	// Danger zone — a destructive, irreversible reset. Sits outside the settings <form> so the
	// button (type=button, wired in admin.js with a confirm step) never submits the form.
	echo '<section class="aafm-card aafm-danger">';
	echo '<div class="aafm-card-head">';
	echo '<span class="icon">';
	echo aafm_icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
	echo '</span>';
	echo '<h2>' . esc_html__( 'Danger zone', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '</div>';
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label">' . esc_html__( 'Reset plugin', 'agent-abilities-for-mcp' ) . '<span class="opt">' . esc_html__( 'Cannot be undone', 'agent-abilities-for-mcp' ) . '</span></div>';
	echo '<div class="aafm-set-control">';
	echo '<button type="button" id="aafm-reset-plugin" class="button button-link-delete">' . esc_html__( 'Reset plugin to defaults', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-reset-status" aria-live="polite"></span>';
	echo '<p class="help">' . esc_html__( 'Clears every plugin setting — enabled abilities, exposed content types and meta keys, and all safety controls — and empties the activity log. Your agent user and anything it created (posts and other content) are left untouched. This cannot be undone.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';
	echo '</section>';

	echo '</div>';
}
