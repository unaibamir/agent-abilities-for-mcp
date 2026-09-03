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
 * - aafm_force_draft, aafm_high_risk_abilities_unlocked, aafm_read_only_mode: a plain bool from
 *   presence of the field (unchecked checkbox -> false). Every reader defaults off on a missing
 *   option, so unlike the OAuth pair below there is nothing to work around: a stored false reads as
 *   off either way.
 * - aafm_oauth_enabled, aafm_oauth_dcr_enabled: the STRING '1' when the checkbox is present, '0'
 *   when absent. The OAuth readers treat every falsy stored form as off, so the off state must be
 *   the literal '0' string - a PHP bool false would not store as false on a never-created option,
 *   leaving the toggle stuck on. DCR defaults on, but a save always sends its explicit state, so
 *   the round-trip is symmetric with OAuth.
 * - aafm_ip_allowlist: split on newlines, trimmed, blanks dropped, and every surviving line
 *   must clear aafm_is_valid_ip_or_cidr(). Invalid lines are dropped (fail-closed), so a
 *   stored non-empty list is always made up entirely of usable entries.
 *
 * @param array<string,mixed> $posted Raw $_POST payload (slashes handled by the caller).
 * @return array{aafm_rate_limit_per_min:int,aafm_max_title_len:int,aafm_log_retention_days:int,aafm_force_draft:bool,aafm_block_guard_strict:bool,aafm_delete_data_on_uninstall:bool,aafm_high_risk_abilities_unlocked:bool,aafm_read_only_mode:bool,aafm_oauth_enabled:string,aafm_oauth_dcr_enabled:string,aafm_ip_allowlist:list<string>}
 */
function aafm_sanitize_settings_input( array $posted ): array {
	$rate  = min( AAFM_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['aafm_rate_limit_per_min'] ?? 0 ) ) );
	$title = min( AAFM_SETTINGS_NUMERIC_MAX, max( 0, (int) ( $posted['aafm_max_title_len'] ?? 0 ) ) );
	// Retention has its own tighter ceiling (ten years); 0 keeps every entry forever.
	$retention   = min( 3650, max( 0, (int) ( $posted['aafm_log_retention_days'] ?? 30 ) ) );
	$draft       = ! empty( $posted['aafm_force_draft'] );
	$block_guard = ! empty( $posted['aafm_block_guard_strict'] );
	$delete_on   = ! empty( $posted['aafm_delete_data_on_uninstall'] );
	$high_risk   = ! empty( $posted['aafm_high_risk_abilities_unlocked'] );
	$read_only   = ! empty( $posted['aafm_read_only_mode'] );

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
		'aafm_rate_limit_per_min'           => $rate,
		'aafm_max_title_len'                => $title,
		'aafm_log_retention_days'           => $retention,
		'aafm_force_draft'                  => $draft,
		'aafm_block_guard_strict'           => $block_guard,
		'aafm_delete_data_on_uninstall'     => $delete_on,
		'aafm_high_risk_abilities_unlocked' => $high_risk,
		'aafm_read_only_mode'               => $read_only,
		'aafm_oauth_enabled'                => $oauth,
		'aafm_oauth_dcr_enabled'            => $oauth_dcr,
		'aafm_ip_allowlist'                 => array_values( array_unique( $lines ) ),
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

	// Read the stored master switch before anything is written, so the audit row below compares
	// against what was actually in place. The raw option, not aafm_high_risk_unlocked(), because a
	// site that hard-blocks the category through the filter still has an operator setting to record.
	$high_risk_before = (bool) get_option( 'aafm_high_risk_abilities_unlocked', false );
	// Same again for read-only mode. The raw option, not aafm_read_only_mode(), so a site that
	// forces the mode on through the filter still records what the operator actually changed.
	$read_only_before = (bool) get_option( 'aafm_read_only_mode', false );

	update_option( 'aafm_rate_limit_per_min', $clean['aafm_rate_limit_per_min'] );
	update_option( 'aafm_max_title_len', $clean['aafm_max_title_len'] );
	update_option( 'aafm_log_retention_days', $clean['aafm_log_retention_days'] );
	update_option( 'aafm_force_draft', $clean['aafm_force_draft'] );
	update_option( 'aafm_block_guard_strict', $clean['aafm_block_guard_strict'] );
	update_option( 'aafm_delete_data_on_uninstall', $clean['aafm_delete_data_on_uninstall'] );
	// Delete rather than store false: locked is the option's out-of-the-box (row-absent) state, and
	// both readers (this file's checked() call and aafm_high_risk_unlocked()) already default to
	// off on a missing row, so a stored false and an absent row behave identically today. But an
	// explicit false is a state a fresh install can never be in, and it is the one state the UI
	// itself cannot restore to once the switch has been touched once. Deleting on "off" keeps a
	// site that never unlocks the category, or that unlocks then re-locks it, in the same absent
	// row either way.
	$high_risk_persisted = aafm_set_high_risk_unlocked( $clean['aafm_high_risk_abilities_unlocked'] );
	// Off deletes the row here too, for the reason spelled out above the high-risk branch.
	$read_only_persisted = aafm_set_read_only_mode( $clean['aafm_read_only_mode'] );
	update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );
	update_option( 'aafm_oauth_dcr_enabled', $clean['aafm_oauth_dcr_enabled'] );
	update_option( 'aafm_ip_allowlist', $clean['aafm_ip_allowlist'] );

	aafm_log_high_risk_switch_change( $high_risk_before, $clean['aafm_high_risk_abilities_unlocked'], $high_risk_persisted );
	aafm_log_read_only_switch_change( $read_only_before, $clean['aafm_read_only_mode'], $read_only_persisted );

	// Every other setting above is already stored. These two are the governance switches, and a
	// save that reports success while one of them silently kept its old value is exactly the
	// failure seen live behind a host Redis drop-in. Say what happened instead.
	if ( ! $high_risk_persisted ) {
		wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'The high-risk abilities switch', 'agent-abilities-for-mcp' ) ) ) );
	}
	if ( ! $read_only_persisted ) {
		wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'Read-only mode', 'agent-abilities-for-mcp' ) ) ) );
	}

	wp_send_json_success(
		array(
			'aafm_rate_limit_per_min'           => $clean['aafm_rate_limit_per_min'],
			'aafm_max_title_len'                => $clean['aafm_max_title_len'],
			'aafm_log_retention_days'           => $clean['aafm_log_retention_days'],
			'aafm_force_draft'                  => $clean['aafm_force_draft'],
			'aafm_block_guard_strict'           => $clean['aafm_block_guard_strict'],
			'aafm_delete_data_on_uninstall'     => $clean['aafm_delete_data_on_uninstall'],
			'aafm_high_risk_abilities_unlocked' => $clean['aafm_high_risk_abilities_unlocked'],
			'aafm_read_only_mode'               => $clean['aafm_read_only_mode'],
			'aafm_oauth_enabled'                => $clean['aafm_oauth_enabled'],
			'aafm_oauth_dcr_enabled'            => $clean['aafm_oauth_dcr_enabled'],
			'aafm_ip_allowlist'                 => $clean['aafm_ip_allowlist'],
			'aafm_ip_allowlist_text'            => implode( "\n", $clean['aafm_ip_allowlist'] ),
			'aafm_ip_dropped'                   => $dropped,
		)
	);
}

/**
 * Record a change to the high-risk abilities master switch.
 *
 * Unlocking this category is the single highest-consequence setting in the plugin: it is what
 * makes refunds, gateway settings, and account creation reachable at all. It gets the same
 * treatment as an ability toggle, for the same reason, so an incident timeline shows who opened
 * the category and when, not only which call fired afterwards.
 *
 * @param bool $before    Previous value.
 * @param bool $after     New value.
 * @param bool $persisted Whether the write actually took (aafm_persist_operator_switch()).
 * @return void
 */
function aafm_log_high_risk_switch_change( bool $before, bool $after, bool $persisted = true ): void {
	if ( $before === $after && $persisted ) {
		return;
	}
	$user = wp_get_current_user();
	if ( $persisted ) {
		$detail = $after
			? __( 'High-risk abilities unlocked', 'agent-abilities-for-mcp' )
			: __( 'High-risk abilities locked', 'agent-abilities-for-mcp' );
	} else {
		// The write did not take (a stale persistent object cache, see
		// aafm_persist_operator_switch()). A "locked" row over a category that is still open would
		// be the worst possible lie in this log, so the row says what actually happened.
		$detail = $after
			? __( 'High-risk abilities could not be unlocked: object cache stale', 'agent-abilities-for-mcp' )
			: __( 'High-risk abilities could not be locked: object cache stale', 'agent-abilities-for-mcp' );
	}
	aafm_log_activity(
		array(
			// Both the admin table (page.php's Event column) and the AJAX-paginated re-render put
			// the raw `ability` value straight into the Event cell - neither reads `event_type` at
			// all. A blank ability therefore rendered as a blank Event cell for every master-switch
			// row, while every other non-call event (log_cleared) already carries a synthetic
			// ability-like name for exactly this reason. Mirrors aafm_log_activity_cleared_marker()'s
			// 'aafm/activity-log-cleared'; the direction (locked/unlocked) stays in detail below.
			'ability'           => 'aafm/high-risk-abilities-unlocked',
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => $persisted ? 'success' : 'error',
			'event_type'        => 'setting_changed',
			'detail'            => $detail,
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
		'aafm_oauth_access_ttl',
		'aafm_oauth_refresh_ttl',
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
		// The review-request notice's whole state (status, first-success stamp, snooze bookkeeping).
		// Clearing it on reset re-arms the ask, but only after a fresh 7 days plus 10 new successful
		// calls, because reset also empties the activity log; uninstall-with-delete-data removes it.
		'aafm_review_request',
		// The one-time agent-user marker backfill guard. Listed so a reset clears it (letting the
		// backfill re-run against a legacy install) and uninstall-with-delete-data removes the row
		// rather than orphaning it. The marker USER META is intentionally NOT touched here - uninstall
		// keeps the agent user, so its marker travels with the account.
		'aafm_agent_user_marker_backfilled',
		// The high-risk unlock switch. Locked is the safe default, so a reset must land the
		// money-moving abilities back behind the floor rather than leave them reachable because
		// someone unlocked the category once; uninstall-with-delete-data removes the row outright.
		'aafm_high_risk_abilities_unlocked',
		// Read-only mode. Cleared for the same reason the switch above is, from the other side: a
		// reset returns the site to out-of-the-box, and out of the box the mode is off. Nothing is
		// exposed by that on its own, because a reset also clears every enabled ability.
		'aafm_read_only_mode',
		// Derived state, not user config: the set of abilities the registration-time preflight left
		// out of the server (schema over the bounds, or over the tool cap). It is regenerated on the
		// next MCP request, so clearing it here is safe and correct - a reset wipes the enabled set
		// that produced any breach, and a delete-data uninstall must not leak the row. Listed here
		// (rather than deleted ad hoc) so it travels with the canonical cleanup like every sibling.
		// The literal is AAFM_OMITTED_ABILITIES_OPTION (includes/server.php); it is spelled out here
		// because uninstall.php loads this file but NOT server.php, so the constant is not defined in
		// the uninstall context. A drift guard in OmittedAbilitiesPreflightTest keeps the two in step.
		'aafm_omitted_abilities',
	);
}

/**
 * Remove this plugin's data for the current blog.
 *
 * Clears this site's cron registrations FIRST, unconditionally - cron registrations are
 * executable plugin machinery, not retained user data, so they go regardless of the
 * data-retention choice below them. Without this, a default (retain-data) uninstall left both
 * daily events behind: their callbacks no longer exist once the plugin is gone, so WordPress
 * reschedules and examines two orphaned hooks on every site forever. The plugin's own
 * deactivation callbacks (agent-abilities-for-mcp.php) clear both correctly, but only in the
 * CURRENT blog context - they cannot reach every site on a multisite network the way
 * aafm_run_uninstall() (uninstall.php) does by switching through each one.
 *
 * Reads the per-site data-retention flag next. When the flag is not set (the default), data is
 * kept and the function returns before touching anything else. When the flag is explicitly
 * turned on by the site admin, the full teardown runs: every configuration option, the
 * activity-log table, and the four OAuth tables are all removed. The flag itself is deleted
 * last so it cannot leak after uninstall.
 *
 * Called once per site by aafm_run_uninstall() in uninstall.php. Declared here (settings.php)
 * so the PHPUnit suite can call it directly without bootstrapping the uninstall context.
 *
 * @return void
 */
function aafm_uninstall_site_data(): void {
	wp_clear_scheduled_hook( 'aafm_prune_activity_log_daily' );
	wp_clear_scheduled_hook( 'aafm_oauth_cleanup' );

	if ( ! get_option( 'aafm_delete_data_on_uninstall', false ) ) {
		return;
	}

	aafm_uninstall_site();
	aafm_drop_oauth_tables();
	delete_option( 'aafm_oauth_schema_version' );
	delete_option( 'aafm_activity_log_schema_version' );
	// The one-time OAuth upgrade-preserve guard and the DCR default-on adoption guard (both written by
	// the plugins_loaded migrations). Cleared here so a delete-data uninstall leaves nothing behind.
	// They are deliberately NOT in the reset set: clearing a guard on reset would let its migration
	// re-run and could flip a toggle back on.
	delete_option( 'aafm_oauth_toggle_migrated' );
	delete_option( 'aafm_oauth_dcr_default_on_migrated' );
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
	// Cache-safe on purpose: reset is what an operator reaches for when a setting looks stuck, and
	// a stale persistent object cache is one way a setting gets stuck (aafm_forget_option_caches()).
	foreach ( aafm_config_option_names() as $option ) {
		aafm_delete_option_cache_safe( $option );
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
 * Render the Settings tab: an OAuth card with its enable toggle (and a note that dynamic client
 * registration follows it), then one Safety controls card of
 * labelled rows - the two governance switches first, then the optional per-behaviour controls -
 * and the Danger zone last. Both cards sit inside #aafm-settings-form and share the one sticky
 * save bar at its foot.
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

	echo '<form id="aafm-settings-form">';

	// OAuth: one switch row plus a note. Same .aafm-switch / .aafm-set-row markup as the
	// force-draft row below; the <input> name/value/checked() contract is what the save
	// handler binds to, not this markup. The OAuth reader defaults off (discovery.php), and
	// dynamic client registration now follows it rather than carrying its own toggle.
	//
	// This card leads the tab because connecting an agent is the first thing an operator does
	// here, and the safety controls below only start mattering once something is connected.
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

	// Enable dynamic client registration. On by default so ChatGPT and Claude, which only ever
	// connect by self-registering, work as soon as OAuth is on; turn it off to require manual client
	// setup. Same .aafm-switch / .aafm-set-row markup and aria wiring as the row above. It stays
	// inert while OAuth is off, since the register route and discovery both gate on OAuth too.
	echo '<div class="aafm-set-row">';
	echo '<div class="aafm-set-label" id="aafm-oauth-dcr-enabled-title">' . esc_html__( 'Enable dynamic client registration', 'agent-abilities-for-mcp' ) . '</div>';
	echo '<div class="aafm-set-control">';
	echo '<label class="aafm-switch"><input type="checkbox" id="aafm-oauth-dcr-enabled" name="aafm_oauth_dcr_enabled" value="1" aria-labelledby="aafm-oauth-dcr-enabled-title aafm-oauth-dcr-enabled-desc" ' . checked( aafm_oauth_dcr_enabled(), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	echo '<label for="aafm-oauth-dcr-enabled" id="aafm-oauth-dcr-enabled-desc">' . esc_html__( 'Let agents self-register a client automatically.', 'agent-abilities-for-mcp' ) . '</label>';
	echo '<p class="help">' . esc_html__( 'On by default, so ChatGPT and Claude can connect. Turn it off to require manual client setup.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div></div>';

	// Scope-is-not-a-boundary note. A connecting app can request an OAuth scope, and the consent
	// screen tells the approving human the app acts with their full capabilities either way - but
	// nothing said that on the admin side until now. Stated once, here, next to the two switches.
	echo '<p class="help">' . esc_html__( 'If a connecting app requests a narrower OAuth scope, this plugin does not use it to limit what the resulting token can do. Every grant acts with the full capabilities of the account that approved it, unless a developer has wired the aafm_oauth_token_capabilities filter to narrow it.', 'agent-abilities-for-mcp' ) . '</p>';

	$oauth_body = (string) ob_get_clean();
	aafm_render_section(
		array(
			'icon'  => 'connection',
			'title' => __( 'OAuth', 'agent-abilities-for-mcp' ),
			'body'  => $oauth_body,
		)
	);

	// Caption for the card immediately below it. It names the safety controls outright, so it
	// travelled down with them when OAuth took the top of the tab rather than staying at the head
	// of the page describing a card it no longer introduces.
	aafm_render_notice(
		'info',
		__( 'These safety controls are optional. They all start off, and the plugin runs fine without any of them. Turn on only what you need.', 'agent-abilities-for-mcp' )
	);

	// Safety controls section. Each labelled control is a pre-built row passed to the shared
	// aafm_render_section() component; the <input> name/value/checked() contracts are unchanged,
	// only the surrounding wrapper moved onto the shared .aafm-section component.
	ob_start();

	// Read-only mode. The first row of the card, because it is the widest ceiling on this tab:
	// while it is on, every write is held, including the ones the high-risk row below it governs.
	// Same .aafm-switch / .aafm-set-row contract as every other row, and inside #aafm-settings-form
	// so admin.js reads it.
	$read_only_control  = '<label class="aafm-switch"><input type="checkbox" id="aafm-read-only-mode" name="aafm_read_only_mode" value="1" ' . checked( (bool) get_option( 'aafm_read_only_mode', false ), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	$read_only_control .= '<label for="aafm-read-only-mode">' . esc_html__( 'Let agents read this site, and nothing else.', 'agent-abilities-for-mcp' ) . '</label>';
	$read_only_control .= '<p class="help">' . esc_html__( 'While this is on, no ability that creates, changes, or deletes anything can be switched on, and none that is already switched on is reachable.', 'agent-abilities-for-mcp' ) . '</p>';

	// The rest of the explanation sits behind a "See more" so this row reads at the same length as
	// the plain ones above it. Collapsed, not cut: every sentence is still in the markup.
	$read_only_more  = '<p class="help">' . esc_html__( 'That covers this plugin\'s own abilities and any bridged in from other plugins. It never turns anything on for you: your selections are kept exactly as they are, so switching this back off restores them.', 'agent-abilities-for-mcp' ) . '</p>';
	$read_only_more .= '<p class="help">' . esc_html__( 'A bridged ability counts as a read only when the plugin that wrote it says so. That is the same declaration this plugin already trusts elsewhere, but it is the other plugin making it, not this one.', 'agent-abilities-for-mcp' ) . '</p>';
	$read_only_more .= '<p class="help">' . esc_html__( 'Every flip of this switch is recorded in the activity log, and those entries expire on the same retention schedule as everything else in it.', 'agent-abilities-for-mcp' ) . '</p>';

	$read_only_control .= aafm_get_set_more_html(
		__( 'See more about read-only mode', 'agent-abilities-for-mcp' ),
		$read_only_more
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Read-only mode', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Off by default', 'agent-abilities-for-mcp' ),
			'control' => $read_only_control,
		)
	);

	// The high-risk master switch, directly beneath the switch that can hold it. It is a normal
	// setting an operator is expected to reach on purpose, not a destructive action, so it stays
	// here rather than in the Danger zone.
	//
	// While read-only mode is on this switch is moot: all eight high-risk abilities are writes, so
	// the read-only floor already has them. Say so, and dim the row, rather than leaving it looking
	// live while flipping it changes nothing. The checkbox itself stays enabled so its current value
	// still rides along on every save - disabling it would post nothing, and the sanitizer reads a
	// missing field as off, which would quietly re-lock the category behind the operator's back.
	$read_only_on = aafm_read_only_mode();

	$high_risk_control = $read_only_on
		? aafm_get_notice_html(
			'info',
			__( 'Read-only mode is on, so this does nothing right now. Every high-risk ability writes, and read-only mode is already holding all of them. Turn read-only mode off above to use this switch.', 'agent-abilities-for-mcp' ),
			array( 'inline' => true )
		)
		: '';

	$high_risk_control .= '<label class="aafm-switch"><input type="checkbox" id="aafm-high-risk-unlocked" name="aafm_high_risk_abilities_unlocked" value="1" ' . checked( (bool) get_option( 'aafm_high_risk_abilities_unlocked', false ), true, false ) . '><span class="aafm-switch-track"></span></label> ';
	$high_risk_control .= '<label for="aafm-high-risk-unlocked">' . esc_html__( 'Allow refunds, order changes, payment gateway settings, coupons, and tax rates to be switched on individually.', 'agent-abilities-for-mcp' ) . '</label>';
	$high_risk_control .= '<p class="help">' . esc_html__( 'While this is off, no agent can issue a refund, change an order or a payment gateway setting, or create or change a coupon or a tax rate, no matter what you have enabled on the Integrations tab.', 'agent-abilities-for-mcp' ) . '</p>';

	$high_risk_more  = '<p class="help">' . esc_html__( 'Turn it on and each of those becomes an ordinary checkbox you switch on one at a time, still badged high-risk so you can always tell them apart.', 'agent-abilities-for-mcp' ) . '</p>';
	$high_risk_more .= '<p class="help">' . esc_html__( 'This switch covers this plugin\'s own abilities only. Abilities bridged in from other plugins are not covered by it, so check those separately.', 'agent-abilities-for-mcp' ) . '</p>';
	$high_risk_more .= '<p class="help">' . esc_html__( 'The plugin logs every flip of this switch, and those entries are kept for the same number of days as everything else in the activity log. Raise the retention setting below if you want a longer record of when the category was opened.', 'agent-abilities-for-mcp' ) . '</p>';

	$high_risk_control .= aafm_get_set_more_html(
		__( 'See more about the high-risk category', 'agent-abilities-for-mcp' ),
		$high_risk_more
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Unlock the category', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Off by default', 'agent-abilities-for-mcp' ),
			'class'   => $read_only_on ? 'is-inactive' : '',
			'pill'    => $read_only_on
				? '<span class="aafm-pill aafm-pill-neutral">' . esc_html__( 'Held by read-only mode', 'agent-abilities-for-mcp' ) . '</span>'
				: '',
			'control' => $high_risk_control,
		)
	);

	// Rate limit. Ships off (0) by default, same as every other optional control on this tab, but
	// this one is easy to miss because a 0 in a number field does not read as "off" the way an
	// unchecked switch does - so the help text says so plainly, suggests a concrete starting
	// value, and names the filter a developer can set it with instead.
	aafm_render_set_row(
		array(
			'label'   => __( 'Rate limit', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Per minute', 'agent-abilities-for-mcp' ),
			'control' => sprintf(
				'<input type="number" id="aafm-rate-limit" name="aafm_rate_limit_per_min" class="small-text" min="0" step="1" value="%1$s" aria-label="%2$s">',
				esc_attr( (string) aafm_rate_limit_per_min() ),
				esc_attr__( 'Rate limit, per minute', 'agent-abilities-for-mcp' )
			),
			'help'    => __( 'How many agent calls one connection can make per minute. Off by default (0), so nothing is capped until you set a number here. If you are not sure where to start, 60 is a reasonable limit for a single connected agent. Developers can also set this with the aafm_rate_limit_per_min filter.', 'agent-abilities-for-mcp' ),
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
				'<textarea id="aafm-ip-allowlist" name="aafm_ip_allowlist" rows="5" class="large-text code" aria-label="%2$s">%1$s</textarea>',
				esc_textarea( implode( "\n", aafm_ip_allowlist() ) ),
				esc_attr__( 'IP allowlist, one per line', 'agent-abilities-for-mcp' )
			) . '<p class="help">' . esc_html__( 'One IP address or CIDR range per line. Leave it empty to allow connections from anywhere. When you save, any line that is not a valid IP or range is dropped.', 'agent-abilities-for-mcp' ) . '</p>' . $ip_notice,
		)
	);

	// Force draft. The toggle switch wraps the checkbox; the <input> keeps its exact
	// name/value/checked() contract - the save handler and its tests bind to that, not this markup.
	aafm_render_set_row(
		array(
			'label'   => __( 'Force draft on create', 'agent-abilities-for-mcp' ),
			'control' => '<label class="aafm-switch"><input type="checkbox" id="aafm-force-draft" name="aafm_force_draft" value="1" ' . checked( aafm_force_draft(), true, false ) . '><span class="aafm-switch-track"></span></label> '
				. '<label for="aafm-force-draft">' . esc_html__( 'Save the content an agent creates as a draft, no matter what status the request asked for.', 'agent-abilities-for-mcp' ) . '</label>',
			'help'    => __( 'Turn this on if you want to look over agent-created content before it goes live. Covers posts, pages, custom content items, reusable blocks, and WooCommerce products. Things without a draft state (media, menus, terms, comments, users, coupons, orders) and product variations are not affected.', 'agent-abilities-for-mcp' ),
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
				'<input type="number" id="aafm-max-title" name="aafm_max_title_len" class="small-text" min="0" step="1" value="%1$s" aria-label="%2$s">',
				esc_attr( (string) aafm_max_title_len() ),
				esc_attr__( 'Maximum title length, in characters', 'agent-abilities-for-mcp' )
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
				'<input type="number" id="aafm-log-retention" name="aafm_log_retention_days" class="small-text" min="0" max="3650" step="1" value="%1$s" aria-label="%2$s">',
				esc_attr( (string) aafm_log_retention_days() ),
				esc_attr__( 'Keep activity log for, in days', 'agent-abilities-for-mcp' )
			),
			'help'    => __( 'How many days of activity to keep. A daily cleanup removes anything older. Set it to 0 to keep every entry.', 'agent-abilities-for-mcp' ),
		)
	);

	// Delete data on uninstall. Same toggle-switch contract as force draft; the <input>
	// name/value/checked() is what the save handler and uninstall.php bind to, not this markup.
	// The second, distinct caution - that uninstalling never revokes an agent's access on its own,
	// whichever way this switch is set - is long enough to break the rhythm of the card, so it sits
	// behind the row's "See more" as its own paragraph rather than getting run together with the
	// first one.
	$delete_on_uninstall_control  = '<label class="aafm-switch"><input type="checkbox" id="aafm-delete-data-on-uninstall" name="aafm_delete_data_on_uninstall" value="1" ' . checked( (bool) get_option( 'aafm_delete_data_on_uninstall', false ), true, false ) . '><span class="aafm-switch-track"></span></label> '
		. '<label for="aafm-delete-data-on-uninstall">' . esc_html__( 'Permanently remove all plugin data when the plugin is deleted.', 'agent-abilities-for-mcp' ) . '</label>';
	$delete_on_uninstall_control .= '<p class="help">' . esc_html__( 'When this is off (the default), your settings, activity log, and OAuth data are kept if you delete the plugin, so a reinstall picks up your configuration. Turn it on only if you want everything removed. This cannot be undone.', 'agent-abilities-for-mcp' ) . '</p>';
	$delete_on_uninstall_control .= aafm_get_set_more_html(
		__( 'See more about deleting data on uninstall', 'agent-abilities-for-mcp' ),
		'<p class="help">' . esc_html__( "Either way, uninstalling this plugin never revokes an agent's access on its own. It never removes the dedicated agent user or any Application Password issued to it, since those are ordinary WordPress account credentials the plugin does not own. To cut off an agent, revoke its OAuth grant on the Connection tab, or delete its Application Password or user account from the Users screen, before or after you remove the plugin.", 'agent-abilities-for-mcp' ) . '</p>'
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Delete data on uninstall', 'agent-abilities-for-mcp' ),
			'control' => $delete_on_uninstall_control,
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

	aafm_render_notice(
		'warning',
		__( 'These controls change how agent requests behave. Test a connection after you change anything here so you do not lock yourself out or quietly drop valid requests.', 'agent-abilities-for-mcp' )
	);

	// One Save, in the shared .aafm-savebar the Abilities, Integrations and Bridge tabs already
	// use: it sticks to the foot of the viewport while the tab is scrolled, so the operator never
	// has to travel the whole tab to reach it, and it settles into flow at the end of the form.
	// It is the only save control on the tab on purpose - a second one would mean a second binding
	// and a second status element to keep in step. The button classes and the .aafm-save-status
	// span are unchanged; admin.js binds to those.
	//
	// The bar's sticky range ends where the form does, so it can never ride over the Danger zone,
	// which renders after </form>.
	echo '<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save settings', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></div>';
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
