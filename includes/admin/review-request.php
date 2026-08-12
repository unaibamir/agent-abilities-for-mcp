<?php
/**
 * Review-request notice: the one-time wp-admin ask for a wordpress.org review.
 *
 * Shows a dismissible admin notice asking the operator to review the plugin, but only once the
 * site has demonstrable value from it: at least ten successful agent tool calls in the activity
 * log AND at least seven days since the first such call was observed. The heading quotes what the
 * activity log holds at render time, read from the same success-narrowed source as the trigger,
 * and says so in those words: the log is pruned on aafm_log_retention_days() and the operator can
 * clear it by hand, so no in-plugin counter can honestly claim an all-time total. All state lives
 * in one per-site option
 * (aafm_review_request, autoload off) registered in aafm_config_option_names(), so a plugin
 * reset and a delete-data uninstall both clean it up without extra code.
 *
 * The dismissal contract: "Sure, I'll review" and "Already did" are permanent; "Maybe later"
 * (and the native X, wired to behave the same) snoozes 14 days, capped at two snoozes so the
 * notice can appear at most three times ever and then stops for good. A plugin update never
 * re-arms it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The successful-call threshold that arms the notice.
 *
 * @return int
 */
function aafm_review_request_call_threshold(): int {
	return 10;
}

/**
 * How long after the first observed successful call the notice stays quiet, in seconds.
 *
 * @return int
 */
function aafm_review_request_wait_seconds(): int {
	return 7 * DAY_IN_SECONDS;
}

/**
 * How long a "Maybe later" snooze lasts, in seconds.
 *
 * @return int
 */
function aafm_review_request_snooze_seconds(): int {
	return 14 * DAY_IN_SECONDS;
}

/**
 * Maximum number of snoozes before any further dismissal becomes permanent.
 *
 * With the cap at 2, the notice can appear at most three times: the first ask plus one
 * reappearance per spent snooze. On the third appearance every dismissal path stores the
 * permanent state.
 *
 * @return int
 */
function aafm_review_request_snooze_cap(): int {
	return 2;
}

/**
 * The wordpress.org review page the primary button opens.
 *
 * Same URL as the static "Review" link in the admin nav (page.php), the plain #new-post
 * anchor - never a pre-filtered star page, which would drift toward guideline 9's
 * "pressuring" line.
 *
 * @return string
 */
function aafm_review_request_url(): string {
	return 'https://wordpress.org/support/plugin/agent-abilities-for-mcp/reviews/#new-post';
}

/**
 * Read the review-request state, merged over its defaults.
 *
 * The option is a single per-site array so the whole feature is one row, and every key is
 * normalized to its expected type here so downstream code never branches on a malformed
 * stored shape.
 *
 * @return array{status:string,first_success_seen_at:int,snooze_until:int,snooze_count:int,threshold_met:int}
 */
function aafm_review_request_state(): array {
	$defaults = array(
		'status'                => 'pending',
		'first_success_seen_at' => 0,
		'snooze_until'          => 0,
		'snooze_count'          => 0,
		'threshold_met'         => 0,
	);
	$stored   = get_option( 'aafm_review_request', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$state  = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	$status = (string) $state['status'];
	if ( ! in_array( $status, array( 'pending', 'snoozed', 'reviewed', 'dismissed' ), true ) ) {
		$status = 'pending';
	}
	return array(
		'status'                => $status,
		'first_success_seen_at' => max( 0, (int) $state['first_success_seen_at'] ),
		'snooze_until'          => max( 0, (int) $state['snooze_until'] ),
		'snooze_count'          => max( 0, (int) $state['snooze_count'] ),
		'threshold_met'         => $state['threshold_met'] ? 1 : 0,
	);
}

/**
 * The success-narrowed tool-call count, computed at most once per request.
 *
 * Two callers want the same number on the same page load: the eligibility check reads it to
 * decide whether to show the ask, and the heading reads it to quote it. That is one COUNT
 * over the activity log, not two. Cheap on a small log (a fraction of a millisecond at a few
 * hundred rows) but not free on a busy one, and it sits on the admin page-load path.
 *
 * The memo is per request, which is the whole scope that matters: a new page load recomputes
 * it, so the notice can never quote a number from an earlier request. Only the test suite
 * runs several logical "requests" in one PHP process, which is what $refresh is for.
 *
 * @param bool $refresh Recompute instead of returning the memoized value.
 * @return int Non-negative count of successful agent tool calls in the log.
 */
function aafm_review_request_success_count( bool $refresh = false ): int {
	static $count = null;

	if ( $refresh || null === $count ) {
		$count = aafm_agent_call_count( 'success' );
	}

	return $count;
}

/**
 * Persist the review-request state.
 *
 * Autoload stays off: the option is only ever read on a handful of admin screens, so it has
 * no business riding along on every front-end request.
 *
 * @param array<string,mixed> $state The full state array to store.
 * @return void
 */
function aafm_review_request_save_state( array $state ): void {
	update_option( 'aafm_review_request', $state, false );
}

/**
 * Whether the review-request notice should show for the current user right now.
 *
 * Runs the whole decision: capability, network-admin exclusion, the terminal states, the
 * active snooze, and the two trigger conditions (call threshold plus the seven-day clock).
 * As a side effect it stamps first_success_seen_at ONCE, the first time any eligibility
 * check observes a successful call. The stamp lives in the option, not the log, so pruning
 * or clearing the activity log can only delay the ask (the count has to rebuild), never
 * restart or shorten the clock.
 *
 * The COUNT only runs while the threshold is still unproven. Once the site clears it, that
 * fact is latched into the option and every later page load skips the query outright, which
 * matters because "pending" has no time limit: an operator who never answers, or a site that
 * never reaches ten successes, would otherwise pay for the query on every admin page load
 * forever. The latch records only that the bar WAS cleared. It never stores the number, so
 * the heading still reads a live count when it renders.
 *
 * The success-only count is a deliberate divergence from aafm_agent_call_count()'s default
 * contract: denied calls prove the connection works, but the review ask needs evidence of
 * value, and an operator whose agent racks up denials has a configuration problem, not a
 * five-star story.
 *
 * @return bool
 */
function aafm_review_request_eligible(): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( is_network_admin() ) {
		return false;
	}

	$state = aafm_review_request_state();
	if ( in_array( $state['status'], array( 'reviewed', 'dismissed' ), true ) ) {
		return false;
	}
	if ( 'snoozed' === $state['status'] && time() < $state['snooze_until'] ) {
		return false;
	}

	if ( ! $state['threshold_met'] ) {
		// The success-narrowed count from the shared helper - never a hand-copied WHERE clause.
		$successes = aafm_review_request_success_count();
		$changed   = false;

		// Stamp the seven-day clock exactly once, on the first success ever observed.
		if ( $successes > 0 && 0 === $state['first_success_seen_at'] ) {
			$state['first_success_seen_at'] = time();
			$changed                        = true;
		}
		if ( $successes >= aafm_review_request_call_threshold() ) {
			$state['threshold_met'] = 1;
			$changed                = true;
		}
		// One write for both, so the page that first sees ten successes does not save twice.
		if ( $changed ) {
			aafm_review_request_save_state( $state );
		}

		if ( ! $state['threshold_met'] ) {
			return false;
		}
	}

	if ( 0 === $state['first_success_seen_at'] ) {
		return false;
	}
	return ( time() - $state['first_success_seen_at'] ) >= aafm_review_request_wait_seconds();
}

/**
 * Whether the current admin screen is one the notice may render on.
 *
 * Site wide across wp-admin, by operator decision. Guideline 11 contemplates this directly:
 * "Site wide notices or embedded dashboard widgets must be dismissible or self-dismiss when
 * resolved." This notice is dismissible four ways (the primary action, the two link actions,
 * and core's injected X) and self-limits to three appearances ever before it stops for good,
 * so the "used sparingly" half of the same guideline is carried by the trigger and the snooze
 * cap rather than by a screen allowlist.
 *
 * One exclusion is left, and it is the only thing this function does: outside wp-admin there
 * is no screen at all (get_current_screen() is not even defined on a front-end or cron
 * request), so a false here keeps the notice off every non-admin context. Any real admin
 * screen answers true.
 *
 * The other exclusion moved out rather than away. Network admin is still out, because the
 * state option is per site and an ask rendered there would read the wrong site's counts, but
 * that check lives in aafm_review_request_eligible() next to the capability gate.
 *
 * @return bool
 */
function aafm_review_request_screen_allowed(): bool {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	return (bool) get_current_screen();
}

/**
 * Render the review-request notice on admin_notices.
 *
 * Gated by the admin-screen check (any wp-admin screen qualifies since the ask went site
 * wide; the network-admin and capability exclusions sit in the eligibility check), the
 * Quick Connect suppression (the wizard modal owns the
 * plugin page until the operator finishes or opts out, and the first-run flow must never be
 * interrupted by an ask), and the full eligibility check. The heading reads the count at
 * render time from the SAME success-narrowed helper as the trigger, and describes it as what
 * the activity log shows, which is the only claim the data supports: any other counter could
 * credit the agent with calls it never made, and even this one is bounded by retention
 * pruning and the operator's own "Clear log" button.
 *
 * @return void
 */
function aafm_render_review_request_notice(): void {
	if ( ! aafm_review_request_screen_allowed() ) {
		return;
	}
	// The wizard modal only ever renders over the plugin's own page, so the suppression is
	// scoped to that screen; on the Plugins list there is no flow to interrupt.
	$screen = get_current_screen();
	if ( $screen && 'toplevel_page_agent-abilities-for-mcp' === $screen->id
		&& function_exists( 'aafm_quickconnect_should_render' ) && aafm_quickconnect_should_render() ) {
		return;
	}
	if ( ! aafm_review_request_eligible() ) {
		return;
	}

	// Memoized, so this is the same COUNT the eligibility check already ran on this request,
	// or the only one it runs when the latch let that check skip the query.
	$count = aafm_review_request_success_count();
	// The latch survives a log clear (deliberately: a clear must never re-arm or shorten the
	// ask). The heading must not, so an ask that would now quote fewer calls than the bar it
	// claims to have cleared simply waits for the log to rebuild.
	if ( $count < aafm_review_request_call_threshold() ) {
		return;
	}

	$heading = sprintf(
		/* translators: %s: number of successful agent tool calls currently held in the site's activity log. */
		_n(
			'Your activity log shows %s successful agent call on this site',
			'Your activity log shows %s successful agent calls on this site',
			$count,
			'agent-abilities-for-mcp'
		),
		number_format_i18n( $count )
	);
	$body    = __( 'That means Agent Abilities for MCP is doing its job. If it\'s been useful, would you take two minutes to leave a review on wordpress.org? Reviews are the main way other site owners find the plugin.', 'agent-abilities-for-mcp' );
	?>
	<div class="notice notice-info is-dismissible aafm-review-request"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'aafm_admin' ) ); ?>"
		data-msg-review="<?php echo esc_attr__( 'Thanks. The review page is open in a new tab.', 'agent-abilities-for-mcp' ); ?>"
		data-msg-later="<?php echo esc_attr__( 'Okay. The review request is hidden for now.', 'agent-abilities-for-mcp' ); ?>"
		data-msg-dismiss="<?php echo esc_attr__( 'Thanks. The review request is closed and will not come back.', 'agent-abilities-for-mcp' ); ?>">
		<p><strong><?php echo esc_html( $heading ); ?></strong></p>
		<p><?php echo esc_html( $body ); ?></p>
		<?php // Flex with a gap so the two link-buttons are not left touching on a single word space. The plugin's admin CSS is not loaded on the Plugins list, so the rule rides inline, as the onboarding pointer's button spacing already does. ?>
		<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
			<a class="button button-primary" href="<?php echo esc_url( aafm_review_request_url() ); ?>" target="_blank" rel="noopener noreferrer" data-aafm-review="review"><?php esc_html_e( 'Sure, I\'ll review', 'agent-abilities-for-mcp' ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'agent-abilities-for-mcp' ); ?></span></a>
			<button type="button" class="button-link" data-aafm-review="later"><?php esc_html_e( 'Maybe later', 'agent-abilities-for-mcp' ); ?></button>
			<button type="button" class="button-link" data-aafm-review="dismiss"><?php esc_html_e( 'Already did', 'agent-abilities-for-mcp' ); ?></button>
		</p>
	</div>
	<?php
	// Two empty siblings that outlive the notice, because every answer destroys the element the
	// focused control lives in. The anchor catches focus so it cannot fall to <body> (which
	// restarts tabbing at the top of the page), and the status region speaks the outcome. They
	// are separate on purpose: focusing a live region makes some screen readers read it twice.
	?>
	<div class="aafm-review-request-anchor" tabindex="-1"></div>
	<p class="screen-reader-text aafm-review-request-status" role="status" aria-live="polite"></p>
	<?php
	// The behaviour script is only needed when the notice actually rendered, and the Plugins
	// list does not load the plugin's admin.js handle, so it goes out as a footer inline
	// script wired up here on demand (mirroring the onboarding pointer's inline approach).
	add_action( 'admin_footer', 'aafm_review_request_print_footer_js' );
}

/**
 * Print the notice's behaviour script in the admin footer.
 *
 * Vanilla JS, no handle dependency: posts the clicked verdict to the aafm_review_request
 * AJAX action, hands focus and an announcement to the two siblings the notice leaves behind,
 * then removes the notice. The native X that core's common.js injects into .is-dismissible
 * notices is caught by delegation on the notice element and posted as "later", so a reflexive
 * dismiss snoozes instead of burning the ask; core removes that element itself, so the script
 * only does the focus and announcement half for it.
 *
 * Nothing user-supplied ever reaches the DOM. The script reads the server-rendered nonce and
 * the three server-rendered, translated outcome messages off the notice's data attributes,
 * and writes a message with textContent, never innerHTML.
 *
 * The POST is deliberately not awaited. The verdict write is idempotent, the notice is gone
 * either way, and re-rendering the ask to report a failed dismiss would be a worse answer
 * than staying quiet: if the write really did fail, the next admin page load simply shows the
 * ask again, which is the honest outcome. keepalive keeps the request alive past the
 * navigation the primary action starts.
 *
 * @return void
 */
function aafm_review_request_print_footer_js(): void {
	wp_print_inline_script_tag( aafm_review_request_footer_js() );
}

/**
 * The notice's behaviour JS, kept as a small self-contained IIFE (no asset file, no handle).
 *
 * @return string
 */
function aafm_review_request_footer_js(): string {
	return <<<'JS'
( function () {
	const notice = document.querySelector( '.aafm-review-request' );
	if ( ! notice ) {
		return;
	}
	const anchor = document.querySelector( '.aafm-review-request-anchor' );
	const status = document.querySelector( '.aafm-review-request-status' );
	let sent = false;
	const send = ( verdict ) => {
		if ( sent ) {
			return;
		}
		sent = true;
		const body = new FormData();
		body.append( 'action', 'aafm_review_request' );
		body.append( 'nonce', notice.dataset.nonce || '' );
		body.append( 'verdict', verdict );
		fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', keepalive: true, body } );
	};
	// Park focus on the anchor and speak the outcome, before the element holding the focused
	// control disappears. Without this, focus lands on <body>: the next Tab restarts at the
	// top of the page and a screen reader is told nothing at all.
	const handOff = ( verdict ) => {
		const message = notice.getAttribute( 'data-msg-' + verdict ) || '';
		if ( anchor ) {
			anchor.focus();
		}
		if ( status && message ) {
			status.textContent = message;
		}
	};
	notice.addEventListener( 'click', ( event ) => {
		const control = event.target.closest( '[data-aafm-review]' );
		if ( control ) {
			const verdict = control.dataset.aafmReview;
			send( verdict );
			if ( 'review' === verdict ) {
				// Let the link open its new tab first, then clear the notice on the next tick.
				window.setTimeout( () => {
					handOff( verdict );
					notice.remove();
				}, 0 );
			} else {
				event.preventDefault();
				handOff( verdict );
				notice.remove();
			}
			return;
		}
		// Core's injected X: treat it as "Maybe later" (core removes the element itself).
		if ( event.target.closest( '.notice-dismiss' ) ) {
			send( 'later' );
			handOff( 'later' );
		}
	} );
}() );
JS;
}

/**
 * AJAX: record the operator's answer to the review ask.
 *
 * Nonce plus manage_options gated like every other aafm_* admin action. The verdict is
 * limited server-side to the three known answers; timestamps are computed here, never
 * accepted from the client. "review" and "dismiss" are permanent. "later" spends a snooze,
 * and once the cap is spent the same click stores the permanent state instead, so the
 * notice keeps its at-most-three-appearances promise no matter which dismissal path the
 * operator uses.
 *
 * State only ever moves forward. Now that the notice is site wide, two admin tabs both
 * showing it is the ordinary case, and the second tab's click arrives against a state the
 * first tab already settled. So a stored terminal answer wins over anything a stale tab
 * sends, and a "later" that lands while a snooze is still running is dropped rather than
 * spending a second snooze off one appearance - otherwise a permanently declined ask would
 * come back in fourteen days, or the three-appearance cap would burn down without the
 * operator ever seeing three notices. A stale "review" or "already did" still applies over a
 * live snooze: those are stronger answers, and honouring them is the point.
 *
 * @return void
 */
function aafm_ajax_review_request(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( (string) $_POST['verdict'] ) ) : '';
	if ( ! in_array( $verdict, array( 'review', 'later', 'dismiss' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown action.', 'agent-abilities-for-mcp' ) ), 400 );
	}

	$state = aafm_review_request_state();

	// Already answered for good: report the stored state and change nothing.
	if ( in_array( $state['status'], array( 'reviewed', 'dismissed' ), true ) ) {
		wp_send_json_success( array( 'status' => $state['status'] ) );
	}
	// Already snoozed and the snooze has not run out: a repeat "later" is a stale tab, not a
	// second appearance, so it must not spend another snooze.
	if ( 'later' === $verdict && 'snoozed' === $state['status'] && time() < $state['snooze_until'] ) {
		wp_send_json_success( array( 'status' => $state['status'] ) );
	}

	if ( 'review' === $verdict ) {
		$state['status'] = 'reviewed';
	} elseif ( 'dismiss' === $verdict ) {
		$state['status'] = 'dismissed';
	} elseif ( $state['snooze_count'] >= aafm_review_request_snooze_cap() ) {
		// The cap is spent: this "later" is the final appearance's dismissal, made permanent.
		$state['status'] = 'dismissed';
	} else {
		$state['status']       = 'snoozed';
		$state['snooze_count'] = $state['snooze_count'] + 1;
		$state['snooze_until'] = time() + aafm_review_request_snooze_seconds();
	}
	aafm_review_request_save_state( $state );

	wp_send_json_success( array( 'status' => $state['status'] ) );
}
