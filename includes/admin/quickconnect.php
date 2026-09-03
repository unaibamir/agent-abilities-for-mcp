<?php
/**
 * Quick Connect: the first-run onboarding wizard and its state.
 *
 * A one-screen modal, rendered over the plugin's own admin page, that collapses the essential
 * subset of the tabbed settings into three jobs: turn on the connection (OAuth, or an Application
 * Password for a dedicated agent user), choose what the agent may read and optionally write, and
 * finish. It never widens the security posture on its own - OAuth is flipped on only when the
 * operator actively proceeds, and the write bundle is off by default and never includes a
 * destructive ability.
 *
 * State lives in two options:
 *   - aafm_quickconnect_finished  '1' once the operator completes setup (the only permanent close).
 *   - aafm_quickconnect_dismissed '1' when the operator asks never to see the wizard again.
 * A manual close (X / Esc / outside click) sets neither, so the wizard reopens on the next visit.
 * Both are listed in aafm_config_option_names(), so a plugin reset returns the site to first-run.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The login used for the dedicated agent user the app-password path creates.
 *
 * @return string
 */
function aafm_quickconnect_agent_login(): string {
	return 'mcp-agent';
}

/**
 * Whether the operator has completed the wizard (the one permanent completion).
 *
 * @return bool
 */
function aafm_quickconnect_is_finished(): bool {
	return '1' === (string) get_option( 'aafm_quickconnect_finished', '' );
}

/**
 * Whether the operator has permanently opted out of the wizard.
 *
 * @return bool
 */
function aafm_quickconnect_is_dismissed(): bool {
	return '1' === (string) get_option( 'aafm_quickconnect_dismissed', '' );
}

/**
 * Whether the site shows evidence that someone has already set this plugin up.
 *
 * The wizard is a FIRST-RUN screen, but the two flags above only ever record what the operator did
 * inside the wizard itself. Someone who closes it with the X and then configures the plugin through
 * the normal tabs has done the whole job and set neither flag, so the wizard used to greet them on
 * every visit forever with no way out short of finding the one exact "Don't show this again".
 *
 * So ask the site instead. Any one of these means setup has happened somewhere:
 *
 *   - an ability is switched on (the option ships empty, so anything at all is a deliberate act)
 *   - an OAuth client is registered
 *   - a grant is live
 *   - an agent has actually called something
 *
 * That last one counts real agent calls through aafm_agent_call_count(), never raw log rows. A
 * plugin reset clears the log and then writes its own "activity log cleared" marker, so counting
 * rows would leave a freshly reset site looking configured and suppress the very first-run screen
 * the reset exists to bring back. The same helper backs the review-request notice, so both features
 * agree on what an agent call is rather than keeping two definitions that can drift apart.
 *
 * Read in that order: the option is autoloaded and answers for almost every configured site, so the
 * three table reads below it rarely run. Nothing here WRITES, deliberately. The flags keep meaning
 * exactly what they meant ("the operator finished the wizard" and "the operator opted out"), the
 * onboarding checklist can still tell those apart, and a render still changes no state, which is
 * the promise made at page.php's dashboard notice.
 *
 * Deliberately NOT memoized. aafm_quickconnect_apply_abilities() writes the enabled-abilities option
 * partway through a request, so a cached answer could outlive the state it describes and report a
 * site as unconfigured after the wizard had just configured it. The option read below is autoloaded
 * and settles the common case before any table is touched, which is cheaper than the staleness
 * would be to reason about.
 *
 * @return bool
 */
function aafm_quickconnect_site_looks_configured(): bool {
	// Native and bridged abilities are stored in two separate options and either one on its own is
	// a deliberate act of configuration. A site running purely on bridged integrations has no
	// native ability enabled at all, so reading only the native option would keep calling it a
	// first-run site.
	foreach ( array( 'aafm_enabled_abilities', 'aafm_enabled_bridged_abilities' ) as $option ) {
		$stored = get_option( $option, array() );
		if ( is_array( $stored ) && array() !== array_filter( array_map( 'strval', $stored ) ) ) {
			return true;
		}
	}
	// An active client is the OAuth evidence. The consent (grant) table deliberately is NOT
	// consulted as a second signal, and that is worth stating because reading it looks obviously
	// right: revoking a client deactivates the client row and its tokens but LEAVES the consent
	// row, and aafm_oauth_list_grants() does not filter on the client being active (shipped 1.6.3
	// behaviour, which the admin grant listing still depends on and which is not changed here). A
	// grant against a client that is still active is already covered by the check above, since such
	// a grant implies an active client. So the only thing a grants check can add is the case where
	// no client is active, where every row it can match belongs to a revoked or deleted client. It
	// would suppress the first-run wizard for an administrator who had revoked their only client
	// and left them no way to get it back.
	if ( function_exists( 'aafm_oauth_count_active_clients' ) && aafm_oauth_count_active_clients() > 0 ) {
		return true;
	}
	if ( function_exists( 'aafm_agent_call_count' ) && aafm_agent_call_count() > 0 ) {
		return true;
	}

	return false;
}

/**
 * Whether the wizard should render on the plugin page for the current request.
 *
 * True only for a capable admin who has neither finished it, nor permanently dismissed it, nor
 * already set the plugin up some other way. A manual close still leaves both flags untouched, so on
 * a genuinely untouched site the wizard does reopen, which is the point of a first-run screen.
 *
 * @return bool
 */
function aafm_quickconnect_should_render(): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( aafm_quickconnect_is_finished() || aafm_quickconnect_is_dismissed() ) {
		return false;
	}
	return ! aafm_quickconnect_site_looks_configured();
}

/**
 * Native subjects whose read-only abilities make up the wizard's "Read" bundle.
 *
 * Deliberately a safe, defensible subset of the full catalog: core site info plus content, media,
 * and taxonomy READS. It excludes the two native subjects that carry personal data - users and
 * comments - and every third-party integration (SEO, WooCommerce, ACF, which all declare their own
 * non-native subjects), so the wizard never turns on a personal-data or integration read. Those are
 * deferred to the full Abilities and Integrations tabs behind the wizard's "Settings" link.
 *
 * @return list<string>
 */
function aafm_quickconnect_read_subjects(): array {
	return array( 'content', 'media', 'taxonomies', 'site' );
}

/**
 * The ability names the wizard's "Read content" row turns on: every read-only ability in the
 * read subjects that exists in the live registry.
 *
 * Derived from the registry (not a hardcoded list) so a newly added native read is covered
 * automatically, and intersected with the live host-gated registry so only real, registered names
 * are ever written to the enabled-abilities option.
 *
 * @return list<string> Ability names, in registry order.
 */
function aafm_quickconnect_read_abilities(): array {
	$subjects = aafm_quickconnect_read_subjects();
	$out      = array();
	foreach ( aafm_get_abilities_registry() as $name => $def ) {
		$subject = isset( $def['subject'] ) ? (string) $def['subject'] : '';
		$group   = isset( $def['group'] ) ? (string) $def['group'] : '';
		if ( 'reads' === $group && in_array( $subject, $subjects, true ) ) {
			$out[] = (string) $name;
		}
	}
	return $out;
}

/**
 * The ability names the wizard's optional "Create and edit content" (write) row turns on: content
 * writes only, with every destructive ability excluded.
 *
 * The filter is subject === 'content' AND group === 'writes' AND risk !== 'destructive'. That yields
 * the create/edit surface (create draft/post/page, update post/page, replace-in-post, create/update
 * content item, and the content-scoped meta/revision writes) and can never include a trash or delete
 * ability, because those are the ones marked 'destructive' in the registry. No media, taxonomy, user,
 * comment, or integration write is in this bundle - all of those live behind the wizard's Settings
 * link. This is the defensible write subset the sprint locked; the WizardAbilitiesTest pins that no
 * destructive ability leaks in.
 *
 * @return list<string> Ability names, in registry order.
 */
function aafm_quickconnect_write_abilities(): array {
	$out = array();
	foreach ( aafm_get_abilities_registry() as $name => $def ) {
		$subject = isset( $def['subject'] ) ? (string) $def['subject'] : '';
		$group   = isset( $def['group'] ) ? (string) $def['group'] : '';
		$risk    = isset( $def['risk'] ) ? (string) $def['risk'] : '';
		if ( 'content' === $subject && 'writes' === $group && 'destructive' !== $risk ) {
			$out[] = (string) $name;
		}
	}
	return $out;
}

/**
 * Apply the wizard's content-access choice to the enabled-abilities option, and set the site's
 * read-only mode to match it.
 *
 * The Read bundle is always turned on (the wizard's Read row is on by default and cannot be turned
 * off there). The Write bundle is turned on when $write is true and turned off when it is false, so
 * a run of the wizard is authoritative for its own two rows. Any other enabled ability the operator
 * set elsewhere (the full Abilities tab, an integration) is preserved untouched. The result is
 * intersected with the live registry so no stale name is ever written.
 *
 * The mode is what makes the wizard's promise durable. Leaving the write row off used to be a
 * one-time set of ticks that eroded the moment anyone switched a single write on somewhere else;
 * setting the mode instead means the read-only posture the operator chose on day one is still the
 * posture in month six. Turning the write row ON has to clear the mode in the same breath, or the
 * wizard would enable a write bundle that the floor then refuses to register - a screen saying yes
 * over a server saying no.
 *
 * The mode is set FIRST, before the option is written, for two reasons: the enabled list is read
 * raw below so the floor cannot subtract the operator's existing writes out of their own option,
 * and aafm_set_enabled_abilities() refuses a write that is not already stored while the mode is on,
 * which would otherwise swallow the write bundle this call is trying to enable.
 *
 * The return value is whether the read-only-mode switch actually persisted, so the caller can
 * answer the operator honestly instead of reporting success over a write that silently stuck
 * (see aafm_persist_operator_switch() for why a stale persistent object cache can do this).
 *
 * @param bool $write Whether the optional write bundle should be enabled.
 * @return bool True when read-only mode now reads back as the state this call requested.
 */
function aafm_quickconnect_apply_abilities( bool $write ): bool {
	$read_set  = aafm_quickconnect_read_abilities();
	$write_set = aafm_quickconnect_write_abilities();

	$read_only_before    = (bool) get_option( 'aafm_read_only_mode', false );
	$read_only_persisted = aafm_set_read_only_mode( ! $write );
	aafm_log_read_only_switch_change( $read_only_before, ! $write, $read_only_persisted );

	// The RAW stored list, not aafm_get_enabled_abilities(): with read-only mode already on from a
	// previous run, the floored reader would hand back reads only and every write the operator had
	// chosen elsewhere would be dropped from their own option by a wizard finish.
	$current = aafm_get_stored_enabled_abilities_raw();

	// Start from what is already enabled, then turn the Read bundle on unconditionally.
	$enabled = array_values( array_unique( array_merge( $current, $read_set ) ) );

	if ( $write ) {
		$enabled = array_values( array_unique( array_merge( $enabled, $write_set ) ) );
	} else {
		// The wizard owns its write row: unticking it removes exactly the write bundle.
		$enabled = array_values( array_diff( $enabled, $write_set ) );
	}

	// Only persist names that still exist in the registry.
	$known   = array_keys( aafm_get_abilities_registry() );
	$enabled = array_values( array_intersect( $enabled, $known ) );

	// aafm_set_enabled_abilities() is the single choke point for this option: it strips any
	// newly-attempted locked name before it persists. The wizard's read/write bundles never
	// include one by construction (content-subject, non-destructive abilities only), so this is
	// a defensive pass-through rather than something expected to fire here.
	//
	// The toggle diff is logged exactly like the main save path (B18): a wizard run makes
	// abilities reachable, and "when did this become reachable, and who made it so" is the
	// question the ability_enabled/ability_disabled rows exist to answer. $current is the raw
	// stored list read above, before any of this function's writes.
	$enabled_persisted = aafm_set_enabled_abilities( $enabled );
	aafm_log_ability_toggle_diff( $current, $enabled_persisted );

	return $read_only_persisted;
}

/**
 * AJAX: enable or disable OAuth from the wizard's connection step.
 *
 * This is the ONLY path by which the wizard writes aafm_oauth_enabled, and it runs solely on an
 * explicit operator action (clicking "Continue" past the connection step). Rendering the wizard
 * never touches the option, so a new install keeps OAuth off until the operator actively proceeds -
 * the 1.3.0 off-by-default posture is preserved. Nonce + manage_options gated.
 *
 * Dynamic Client Registration is on by default and has its own Settings toggle, so the wizard does
 * not write it here: turning OAuth on is enough to leave a state a real MCP client can connect to,
 * because DCR is already on unless the operator deliberately turned it off. This is what fixes the
 * #90 dead end, where a user who set OAuth up through the wizard (its copy says this "is what lets
 * ChatGPT, Claude, and Manus connect") ended up connectable in name only because DCR defaulted off.
 * The response reports the current DCR state so the UI can reflect it, and the wizard never
 * overrides an operator who chose to switch DCR off.
 *
 * @return void
 */
function aafm_ajax_quickconnect_oauth(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$enabled = ! empty( $_POST['enabled'] ) ? '1' : '0';
	if ( ! aafm_update_option_verified( 'aafm_oauth_enabled', $enabled ) ) {
		wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'Enable OAuth', 'agent-abilities-for-mcp' ) ) ) );
	}
	wp_send_json_success(
		array(
			'aafm_oauth_enabled'     => $enabled,
			// The current DCR toggle state (on by default), reported so the UI can reflect it.
			'aafm_oauth_dcr_enabled' => aafm_oauth_dcr_enabled() ? '1' : '0',
		)
	);
}

/**
 * AJAX: complete the wizard.
 *
 * Applies the content-access choice (Read always, Write per the posted flag), then records the
 * permanent completion flag so the wizard does not reopen. Nonce + manage_options gated. Does not
 * touch OAuth - that is handled by aafm_ajax_quickconnect_oauth() on the connection step, so the
 * option is only ever written on the operator's explicit connection action.
 *
 * @return void
 */
function aafm_ajax_quickconnect_finish(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$write = ! empty( $_POST['write'] );

	$read_only_persisted = aafm_quickconnect_apply_abilities( $write );
	update_option( 'aafm_quickconnect_finished', '1' );

	// Mirrors aafm_ajax_save_settings(): a wizard finish that reports success while the read-only
	// switch it just flipped silently stuck is the same silent-wrong-answer this diff exists to close.
	if ( ! $read_only_persisted ) {
		wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'Read-only mode', 'agent-abilities-for-mcp' ) ) ) );
	}

	wp_send_json_success(
		array(
			'write'         => $write ? 1 : 0,
			'enabled_count' => count( aafm_get_enabled_abilities() ),
			'oauth_enabled' => aafm_oauth_enabled() ? 1 : 0,
			// Reported so the success receipt can reflect the real connectable state: OAuth without
			// DCR is not a state a self-registering MCP client can connect to (issue #90).
			'dcr_enabled'   => aafm_oauth_dcr_enabled() ? 1 : 0,
		)
	);
}

/**
 * AJAX: permanently dismiss the wizard ("Don't show this again").
 *
 * Sets the dismissed flag so aafm_quickconnect_should_render() returns false from now on, giving an
 * operator who never wants the wizard a real opt-out. Nonce + manage_options gated.
 *
 * @return void
 */
function aafm_ajax_quickconnect_dismiss(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	update_option( 'aafm_quickconnect_dismissed', '1' );
	wp_send_json_success();
}

/**
 * Render the Quick Connect wizard markup on the plugin page.
 *
 * Called from aafm_render_admin_page(); it returns immediately unless the wizard is due. The markup
 * is a WP-admin-native modal (scrim + dialog) styled with the plugin's own admin tokens, carrying
 * the live endpoint URL, a copy control that stays inert until OAuth is on, the app-password path
 * (a dedicated agent user + a pre-filled client config), a Read row (on) and an optional Write row
 * (amber, off), and a success receipt. The behaviour lives in admin.js (#bindQuickConnect).
 *
 * @return void
 */
function aafm_quickconnect_render(): void {
	if ( ! aafm_quickconnect_should_render() ) {
		return;
	}

	$endpoint = aafm_endpoint_url();
	$login    = aafm_quickconnect_agent_login();
	$snippet  = aafm_client_snippet( 'generic', $login );
	$profile  = admin_url( 'profile.php#application-passwords-section' );
	$settings = add_query_arg(
		array(
			'page' => 'agent-abilities-for-mcp',
			'tab'  => 'abilities',
		),
		admin_url( 'admin.php' )
	);

	// The shield-with-check brand mark, matched to assets/wp-admin-icon.svg geometry, accent blue.
	$brandmark = '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" aria-hidden="true" focusable="false"><path d="M16 5 7 8.4v6.9c0 5.4 3.7 9.6 9 11.2 5.3-1.6 9-5.8 9-11.2V8.4L16 5Z" stroke-width="1.9" stroke-linejoin="round"/><path d="m11.6 15.6 3 3 6-6.4" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	?>
	<?php // With page JS off, every close path (X, scrim, Escape, Continue, opt-out) is dead, so hide the overlay and let the server-rendered admin behind it show through. ?>
	<noscript><style>.aafm-qc-overlay{display:none !important;}</style></noscript>
	<div class="aafm-qc-overlay" id="aafm-qc" role="dialog" aria-modal="true" aria-labelledby="aafm-qc-title" data-endpoint="<?php echo esc_attr( $endpoint ); ?>">
		<div class="aafm-qc-scrim" data-qc-close="temporary"></div>
		<div class="aafm-qc-modal">

			<header class="aafm-qc-head">
				<div class="aafm-qc-brandrow">
					<span class="aafm-qc-brandmark"><?php echo wp_kses( $brandmark, aafm_svg_allowed_html() ); ?></span>
					<span class="aafm-qc-brandtext"><?php esc_html_e( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ); ?><small><?php esc_html_e( 'Quick connect', 'agent-abilities-for-mcp' ); ?></small></span>
					<button type="button" class="aafm-qc-close" data-qc-close="temporary" aria-label="<?php esc_attr_e( 'Close for now (the wizard reopens until you finish setup)', 'agent-abilities-for-mcp' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
					</button>
				</div>
				<?php // tabindex="-1" so admin.js can put the opening focus on the dialog's own title. ?>
				<h1 id="aafm-qc-title" tabindex="-1"><?php esc_html_e( 'Connect an AI agent to this site', 'agent-abilities-for-mcp' ); ?></h1>
				<p class="aafm-qc-lede"><?php esc_html_e( 'Give an AI agent a governed way in. Turn the connection on, choose what it can touch, and you are done. Everything else lives in Settings.', 'agent-abilities-for-mcp' ); ?></p>
				<div class="aafm-qc-meter" aria-hidden="true">
					<div class="aafm-qc-meter-top">
						<span class="aafm-qc-count"><?php esc_html_e( 'Step', 'agent-abilities-for-mcp' ); ?> <b data-qc-stepnow>1</b> <?php esc_html_e( 'of 3 to connected', 'agent-abilities-for-mcp' ); ?></span>
						<span class="aafm-qc-pct" data-qc-pct>0%</span>
					</div>
					<div class="aafm-qc-meter-track">
						<span class="aafm-qc-seg" data-qc-seg="1"></span>
						<span class="aafm-qc-seg" data-qc-seg="2"></span>
						<span class="aafm-qc-seg" data-qc-seg="3"></span>
					</div>
				</div>
			</header>

			<div class="aafm-qc-body" data-qc-body>

				<?php // JOB 1: connect. ?>
				<section class="aafm-qc-job is-current" data-qc-job="1">
					<div class="aafm-qc-job-head">
						<span class="aafm-qc-marker" aria-hidden="true"><span class="num">1</span><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg></span>
						<div class="aafm-qc-job-titles">
							<div class="jt"><?php esc_html_e( 'Turn on the connection', 'agent-abilities-for-mcp' ); ?></div>
							<div class="js"><?php esc_html_e( 'Open the door, then hand the address to the agent', 'agent-abilities-for-mcp' ); ?></div>
						</div>
						<span class="aafm-qc-tag now" data-qc-tag><?php esc_html_e( 'In progress', 'agent-abilities-for-mcp' ); ?></span>
					</div>
					<div class="aafm-qc-job-body"><div><div class="aafm-qc-job-inner">

						<div class="aafm-qc-control">
							<label class="aafm-qc-toggle">
								<input type="checkbox" data-qc-oauth checked aria-label="<?php esc_attr_e( 'Enable OAuth', 'agent-abilities-for-mcp' ); ?>">
								<span class="aafm-qc-track"></span>
							</label>
							<div class="cx">
								<div class="cl"><?php esc_html_e( 'Enable OAuth', 'agent-abilities-for-mcp' ); ?> <span class="soft">(<?php esc_html_e( 'recommended', 'agent-abilities-for-mcp' ); ?>)</span></div>
								<div class="cd"><?php esc_html_e( 'This is what lets ChatGPT, Claude, and Manus connect. The agent approves access in its own browser tab, so there is no secret to copy or store.', 'agent-abilities-for-mcp' ); ?></div>
							</div>
						</div>

						<p class="aafm-qc-byline">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
							<span><?php esc_html_e( 'It stays off until you continue past this step, and you can change it later in Settings.', 'agent-abilities-for-mcp' ); ?></span>
						</p>

						<div class="aafm-qc-urlwrap">
							<div class="aafm-qc-urlfield is-live" data-qc-urlfield>
								<span class="u" data-qc-urltext><?php echo esc_html( $endpoint ); ?></span>
								<button type="button" class="aafm-qc-ucopy aafm-copy" data-copy="<?php echo esc_attr( $endpoint ); ?>" aria-label="<?php esc_attr_e( 'Copy MCP endpoint URL', 'agent-abilities-for-mcp' ); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
									<span class="aafm-copy-label"><?php esc_html_e( 'Copy', 'agent-abilities-for-mcp' ); ?></span>
								</button>
							</div>
							<p class="aafm-qc-nextline" data-qc-nextline>
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
								<span><?php echo wp_kses( __( 'Paste into <b>ChatGPT &rarr; add a custom connector</b>, or <b>Claude &rarr; Settings &rarr; Connectors</b>, then approve in the browser tab that opens.', 'agent-abilities-for-mcp' ), array( 'b' => array() ) ); ?></span>
							</p>
						</div>

						<div class="aafm-qc-altauth" data-qc-altauth>
							<button type="button" class="aafm-qc-alttrigger" data-qc-alttrigger aria-expanded="false">
								<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
								<span><?php echo wp_kses( __( 'Not using ChatGPT or Claude? <b>Connect with a username and application password instead</b>', 'agent-abilities-for-mcp' ), array( 'b' => array() ) ); ?></span>
							</button>
							<?php // Collapsed panels stay in the layout at zero height, so they ship inert: without it every control inside is still tabbable. admin.js flips it with the disclosure. ?>
							<div class="aafm-qc-altpanel" inert><div><div class="aafm-qc-altbody">

								<div class="aafm-qc-substep" data-qc-substep="a">
									<span class="sn">1</span>
									<div class="sc">
										<div class="sl"><?php esc_html_e( 'Create a dedicated agent user', 'agent-abilities-for-mcp' ); ?></div>
										<div class="sd"><?php echo wp_kses( __( 'A real, low-privilege <code>subscriber</code> account. The agent signs in as this user, so its reach is capped by that role from the start. One thing to know: the Application Password you generate next is a whole-site credential, not something this plugin can scope. It works anywhere that role can authenticate, REST API and XML-RPC included; the abilities and safety controls below only govern calls made through this plugin\'s MCP endpoint.', 'agent-abilities-for-mcp' ), array( 'code' => array() ) ); ?></div>
										<div class="aafm-qc-userrow">
											<input class="aafm-qc-uinput" value="<?php echo esc_attr( $login ); ?>" readonly aria-label="<?php esc_attr_e( 'Agent username', 'agent-abilities-for-mcp' ); ?>">
											<button type="button" class="aafm-btn aafm-btn-primary aafm-btn-sm" id="aafm-qc-create-user"><?php esc_html_e( 'Create agent user', 'agent-abilities-for-mcp' ); ?></button>
											<span class="aafm-qc-userstatus" data-qc-userstatus aria-live="polite"></span>
										</div>
									</div>
								</div>

								<div class="aafm-qc-substep" data-qc-substep="b">
									<span class="sn">2</span>
									<div class="sc">
										<div class="sl"><?php esc_html_e( 'Generate an Application Password', 'agent-abilities-for-mcp' ); ?></div>
										<div class="sd">
											<?php
											echo wp_kses(
												sprintf(
													/* translators: 1: opening link tag to the profile page, 2: closing link tag, 3: agent username. */
													__( 'Open %1$sUsers &rarr; Profile &rarr; Application Passwords%2$s for %3$s, name it "AI agent", and copy the generated password once.', 'agent-abilities-for-mcp' ),
													'<a href="' . esc_url( $profile ) . '">',
													'</a>',
													'<b>' . esc_html( $login ) . '</b>'
												),
												array(
													'a' => array( 'href' => array() ),
													'b' => array(),
												)
											);
											?>
										</div>
									</div>
								</div>

								<div class="aafm-qc-substep" data-qc-substep="c">
									<span class="sn">3</span>
									<div class="sc">
										<div class="sl"><?php esc_html_e( 'Copy the config for your MCP client', 'agent-abilities-for-mcp' ); ?></div>
										<div class="sd"><?php echo wp_kses( __( 'The endpoint and the <code>mcp-agent</code> username are already filled in.', 'agent-abilities-for-mcp' ), array( 'code' => array() ) ); ?></div>
										<div class="aafm-qc-codeblock">
											<div class="cbhead">
												<span class="fn"><?php esc_html_e( 'mcp client config', 'agent-abilities-for-mcp' ); ?></span>
												<button type="button" class="aafm-qc-cbcopy aafm-copy" data-copy="<?php echo esc_attr( $snippet ); ?>">
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
													<span class="aafm-copy-label"><?php esc_html_e( 'Copy', 'agent-abilities-for-mcp' ); ?></span>
												</button>
											</div>
											<pre><?php echo esc_html( $snippet ); ?></pre>
										</div>
										<p class="aafm-qc-nextline is-static">
											<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>
											<span><?php echo wp_kses( __( 'Paste your password over <b>PASTE-APPLICATION-PASSWORD-HERE</b>, then start your client.', 'agent-abilities-for-mcp' ), array( 'b' => array() ) ); ?></span>
										</p>
									</div>
								</div>

							</div></div></div>
						</div>

						<div class="aafm-qc-btnrow">
							<button type="button" class="aafm-btn aafm-btn-primary" data-qc-next="2"><?php esc_html_e( 'Continue', 'agent-abilities-for-mcp' ); ?></button>
							<span class="aafm-qc-hint" data-qc-hint><?php esc_html_e( 'OAuth is on. Copy the URL, then continue.', 'agent-abilities-for-mcp' ); ?></span>
						</div>

					</div></div></div>
				</section>

				<?php // JOB 2: content access. ?>
				<section class="aafm-qc-job is-todo" data-qc-job="2">
					<div class="aafm-qc-job-head">
						<span class="aafm-qc-marker" aria-hidden="true"><span class="num">2</span><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg></span>
						<div class="aafm-qc-job-titles">
							<div class="jt"><?php esc_html_e( 'Choose what the agent can touch', 'agent-abilities-for-mcp' ); ?></div>
							<div class="js"><?php esc_html_e( 'Reading is on. Writing is your call.', 'agent-abilities-for-mcp' ); ?></div>
						</div>
						<span class="aafm-qc-tag todo" data-qc-tag><?php esc_html_e( 'Not started', 'agent-abilities-for-mcp' ); ?></span>
					</div>
					<?php // Steps that are not the current one ship inert; admin.js moves it as the wizard advances. ?>
					<div class="aafm-qc-job-body" inert><div><div class="aafm-qc-job-inner">

						<div class="aafm-qc-control">
							<label class="aafm-qc-toggle">
								<input type="checkbox" checked disabled aria-label="<?php esc_attr_e( 'Read content', 'agent-abilities-for-mcp' ); ?>">
								<span class="aafm-qc-track"></span>
							</label>
							<div class="cx">
								<div class="cl accent"><?php esc_html_e( 'Read content', 'agent-abilities-for-mcp' ); ?> <span class="soft">&middot; <?php esc_html_e( 'on by default', 'agent-abilities-for-mcp' ); ?></span></div>
								<div class="cd"><?php esc_html_e( 'Core and content: posts, pages, media, and terms. Read-only and safe, so the agent can see the site without changing it.', 'agent-abilities-for-mcp' ); ?> <?php esc_html_e( 'Leaving the write row below off also turns on read-only mode, so nothing that writes can be switched on anywhere until you turn that off in Settings.', 'agent-abilities-for-mcp' ); ?></div>
							</div>
						</div>

						<div class="aafm-qc-control is-write">
							<label class="aafm-qc-toggle amber">
								<input type="checkbox" data-qc-write aria-label="<?php esc_attr_e( 'Create and edit content', 'agent-abilities-for-mcp' ); ?>">
								<span class="aafm-qc-track"></span>
							</label>
							<div class="cx">
								<div class="cl"><?php esc_html_e( 'Create and edit content', 'agent-abilities-for-mcp' ); ?> <span class="amber">(<?php esc_html_e( 'write', 'agent-abilities-for-mcp' ); ?>)</span></div>
								<div class="cd"><?php esc_html_e( 'Let the agent draft, update, and publish content.', 'agent-abilities-for-mcp' ); ?></div>
							</div>
						</div>

						<div class="aafm-qc-amberwarn" data-qc-writewarn><div>
							<div class="aafm-qc-amberbox">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/></svg>
								<div class="at"><?php echo wp_kses( __( '<b>Write is on.</b> The agent can create, update, and publish posts and pages as the connected user. Nothing is deleted permanently; deletes go to Trash.', 'agent-abilities-for-mcp' ), array( 'b' => array() ) ); ?></div>
							</div>
						</div></div>

						<div class="aafm-qc-trust" aria-label="<?php esc_attr_e( 'Safety guarantees', 'agent-abilities-for-mcp' ); ?>">
							<?php
							foreach (
								array(
									__( 'Off by default', 'agent-abilities-for-mcp' ),
									__( 'Capped to your role', 'agent-abilities-for-mcp' ),
									__( 'Every action is logged', 'agent-abilities-for-mcp' ),
									// Reads the enabled set rather than asserting recoverability the
									// permanent-delete abilities do not have. See aafm_delete_guarantee().
									aafm_delete_guarantee()[2],
								) as $chip
							) {
								echo '<span class="aafm-qc-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>' . esc_html( $chip ) . '</span>';
							}
							?>
						</div>

						<p class="aafm-qc-settingslink">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.6 7.6 0 0 0 0-2l1.8-1.4-1.8-3.2-2.1.9a7.6 7.6 0 0 0-1.7-1L15.2 3H8.8l-.4 2.3a7.6 7.6 0 0 0-1.7 1l-2.1-.9-1.8 3.2L4.6 11a7.6 7.6 0 0 0 0 2l-1.8 1.4 1.8 3.2 2.1-.9a7.6 7.6 0 0 0 1.7 1l.4 2.3h6.4l.4-2.3a7.6 7.6 0 0 0 1.7-1l2.1.9 1.8-3.2L19.4 13Z"/></svg>
							<span><?php esc_html_e( 'Need SEO, WooCommerce, or custom fields?', 'agent-abilities-for-mcp' ); ?> <a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Set those up in Settings', 'agent-abilities-for-mcp' ); ?></a></span>
						</p>

						<div class="aafm-qc-btnrow">
							<button type="button" class="aafm-btn aafm-btn-primary" data-qc-next="3"><?php esc_html_e( 'Continue', 'agent-abilities-for-mcp' ); ?></button>
							<button type="button" class="aafm-btn aafm-btn-secondary" data-qc-back="1"><?php esc_html_e( 'Back', 'agent-abilities-for-mcp' ); ?></button>
						</div>

					</div></div></div>
				</section>

				<?php // JOB 3: finish. ?>
				<section class="aafm-qc-job is-todo" data-qc-job="3">
					<div class="aafm-qc-job-head">
						<span class="aafm-qc-marker" aria-hidden="true"><span class="num">3</span><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg></span>
						<div class="aafm-qc-job-titles">
							<div class="jt"><?php esc_html_e( 'Finish and connect', 'agent-abilities-for-mcp' ); ?></div>
							<div class="js"><?php esc_html_e( 'Review, then hand off to the agent', 'agent-abilities-for-mcp' ); ?></div>
						</div>
						<span class="aafm-qc-tag todo" data-qc-tag><?php esc_html_e( 'Not started', 'agent-abilities-for-mcp' ); ?></span>
					</div>
					<div class="aafm-qc-job-body" inert><div><div class="aafm-qc-job-inner">
						<p class="aafm-qc-finishcopy"><?php esc_html_e( 'You are set. Approve the connection in the agent when it asks, and it will see exactly what you allowed here, nothing more.', 'agent-abilities-for-mcp' ); ?></p>
						<div class="aafm-qc-btnrow">
							<button type="button" class="aafm-btn aafm-btn-primary" id="aafm-qc-finish">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>
								<?php esc_html_e( 'Finish setup', 'agent-abilities-for-mcp' ); ?>
							</button>
							<button type="button" class="aafm-btn aafm-btn-secondary" data-qc-back="2"><?php esc_html_e( 'Back', 'agent-abilities-for-mcp' ); ?></button>
						</div>
					</div></div></div>
				</section>

			</div>

			<div class="aafm-qc-foot">
				<span>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
					<?php esc_html_e( 'Closing keeps this for later; the wizard opens again next time until you finish setup.', 'agent-abilities-for-mcp' ); ?>
				</span>
				<button type="button" class="aafm-qc-optout" id="aafm-qc-dismiss"><?php esc_html_e( 'Don\'t show this again', 'agent-abilities-for-mcp' ); ?></button>
			</div>

			<?php // SUCCESS receipt. ?>
			<div class="aafm-qc-success" data-qc-success>
				<canvas class="aafm-qc-confetti" data-qc-confetti aria-hidden="true"></canvas>
				<svg class="aafm-qc-confetti-svg" viewBox="0 0 660 300" aria-hidden="true" preserveAspectRatio="xMidYMid slice">
					<rect x="90" y="40" width="10" height="10" rx="2" fill="#2271b1" transform="rotate(20 95 45)"/>
					<rect x="180" y="70" width="9" height="9" rx="2" fill="#dba617" transform="rotate(-15 184 74)"/>
					<rect x="300" y="34" width="11" height="11" rx="2" fill="#00a32a" transform="rotate(35 305 39)"/>
					<rect x="430" y="66" width="9" height="9" rx="2" fill="#2271b1" transform="rotate(-25 434 70)"/>
					<rect x="540" y="44" width="10" height="10" rx="2" fill="#dba617" transform="rotate(12 545 49)"/>
					<circle cx="140" cy="110" r="5" fill="#00a32a"/>
					<circle cx="500" cy="120" r="5" fill="#2271b1"/>
					<circle cx="360" cy="96" r="4" fill="#dba617"/>
				</svg>
				<span class="aafm-qc-bigshield" aria-hidden="true">
					<svg viewBox="0 0 24 24"><path fill="#2271b1" d="M12 2.2 20.5 5.3v6.4c0 5.1-3.6 8.9-8.5 10.6C7.1 20.6 3.5 16.8 3.5 11.7V5.3L12 2.2Z"/><path fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M8 12l2.6 2.6L16 9"/></svg>
				</span>
				<h2><?php esc_html_e( 'Connected and governed', 'agent-abilities-for-mcp' ); ?></h2>
				<p><?php esc_html_e( 'The agent can reach this site through the MCP server now. Approve it in your client and it will only see what you switched on.', 'agent-abilities-for-mcp' ); ?></p>
				<div class="aafm-qc-receipt">
					<div class="rrow"><span class="ri"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg></span><span class="rl"><?php esc_html_e( 'Connection method', 'agent-abilities-for-mcp' ); ?></span><span class="rv" data-qc-rmethod><?php esc_html_e( 'OAuth', 'agent-abilities-for-mcp' ); ?></span></div>
					<div class="rrow"><span class="ri"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg></span><span class="rl"><?php esc_html_e( 'Read content', 'agent-abilities-for-mcp' ); ?></span><span class="rv"><?php esc_html_e( 'On', 'agent-abilities-for-mcp' ); ?></span></div>
					<div class="rrow"><span class="ri" data-qc-rwriteicon><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg></span><span class="rl"><?php esc_html_e( 'Create & edit content', 'agent-abilities-for-mcp' ); ?></span><span class="rv" data-qc-rwrite><?php esc_html_e( 'Off', 'agent-abilities-for-mcp' ); ?></span></div>
				</div>
				<div class="aafm-qc-sbtns">
					<button type="button" class="aafm-btn aafm-btn-primary" id="aafm-qc-godash"><?php esc_html_e( 'Go to dashboard', 'agent-abilities-for-mcp' ); ?></button>
				</div>
			</div>

		</div>
	</div>
	<?php
}
