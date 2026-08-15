<?php
/**
 * Review-request notice: the one-time wp-admin ask for a wordpress.org review.
 *
 * Shows a dismissible admin notice asking the operator to review the plugin, but only once the
 * site has demonstrable value from it: at least ten successful agent tool calls in the activity
 * log AND at least seven days since the first such call was observed. The heading quotes what the
 * activity log holds, read through the same five-minute memo of the same success-narrowed source
 * the trigger reads, and says so in those words: the log is pruned on aafm_log_retention_days()
 * and the operator can clear it by hand, so no in-plugin counter can honestly claim an all-time
 * total. All state lives in one per-site option (aafm_review_request, autoload off) registered in
 * aafm_config_option_names(), so a plugin reset and a delete-data uninstall clean it up with no
 * code of its own. The count memo is the exception: it is a transient, so both of those paths
 * name it explicitly (includes/audit/log.php, in the clear and the uninstall).
 *
 * The dismissal contract: "Sure, I'll review" and "Already did" are permanent; "Maybe later"
 * (and the native X, wired to behave the same) snoozes 14 days, capped at two snoozes, so the
 * operator is asked to answer at most three times and the third answer is final whichever
 * button it arrives on. The cap counts answers, not renders: an ask nobody ever answers keeps
 * rendering until somebody does. A plugin update never re-arms it.
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
 * With the cap at 2, the operator answers at most three times: the first ask plus one
 * reappearance per spent snooze. The third answer stores the permanent state whichever
 * dismissal path it arrives on.
 *
 * It bounds answers, not renders. Nothing on the render path counts appearances, so an ask
 * that is never answered - never clicked, never dismissed, never X'd - goes on rendering on
 * every admin page load. That is deliberate, since an ignored ask is not a declined one, and
 * it is why the guideline 11 argument rests on the trigger and on being genuinely dismissible
 * rather than on a render bound.
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
 * The memo has to be per request, and a bare function static is not that on its own. On
 * php-fpm and mod_php, which is what WordPress almost always runs on, the process ends with
 * the request and the two are the same thing. Under a persistent worker SAPI (FrankenPHP
 * worker mode, RoadRunner, Swoole) the static outlives the request, and the same reasoning
 * is spelled out for the sibling static in includes/server.php. Here the consequences are
 * worse than a duplicate log row, because production never passes $refresh: a worker that
 * cached a below-threshold count would never see the site cross the bar, and one that cached
 * a passing count would keep quoting it after the log was cleared, defeating the render
 * guard that exists to stop exactly that. So the memo is cleared on `shutdown` rather than
 * trusted to die with the process, which makes "per request" true on every SAPI. $refresh is
 * for the test suite, which runs many logical requests without a shutdown between them.
 *
 * @param bool $refresh Recompute instead of returning the memoized value.
 * @param bool $forget  Discard the memoized value without recomputing it, and return 0. Used
 *                      by the shutdown reset, which must not spend a query on its way out.
 * @return int Non-negative count of successful agent tool calls in the log.
 */
function aafm_review_request_success_count( bool $refresh = false, bool $forget = false ): int {
	static $count = null;

	if ( $forget ) {
		$count = null;
		return 0;
	}

	if ( $refresh || null === $count ) {
		$count = aafm_agent_call_count( 'success' );
	}

	return $count;
}

/**
 * Drop the memoized success count at the end of the request.
 *
 * Hooked on `shutdown` so a persistent worker starts each request with a cold memo. On
 * php-fpm and mod_php the process was ending anyway, so this costs one function call and
 * changes nothing. It discards rather than recomputes: spending a query on the way out
 * would give back the saving the memo exists to make.
 *
 * @return void
 */
function aafm_review_request_reset_success_count(): void {
	aafm_review_request_success_count( false, true );
}

// Registered here at include time, beside the memo whose contract it enforces, rather than in
// the bootstrap's is_admin() block. The memo function carries no such gate, so a future CLI
// command, REST route, or cron callback that calls it would otherwise run in a context where
// the reset was never registered and quietly get the process-lifetime cache this exists to
// prevent. includes/audit/log.php wires its own hooks the same way and for the same reason.
add_action( 'shutdown', 'aafm_review_request_reset_success_count' );

/**
 * The count both the trigger and the heading read, memoized across requests for five minutes.
 *
 * Each of them would otherwise run the query on EVERY admin page load: the eligibility check
 * until the threshold latches, the heading for as long as the ask then sits unanswered. That
 * is the window in which the log is largest, because it is by definition a site whose agent is
 * working, and it never closes on a site that stays under ten successes. The query is a
 * confirmed full table scan (neither predicate is selective enough for any index the table
 * carries), and aafm_log_retention_days() can be set to 0, which means keep forever and takes
 * away the only bound there was.
 *
 * Five minutes of staleness costs nothing to either. The heading quotes a rounded human number
 * to make a case for leaving a review, not a live meter, and the trigger only reads it to move
 * two fields that never move back, so an old count delays the stamp or the latch by minutes at
 * worst. The render guard below still has to stay honest, so both paths that
 * delete rows drop this memo: aafm_clear_activity_log() and the retention prune. A prune
 * normally moves the number gradually, but an operator who shortens the retention window takes
 * most of the log out on the next daily run, and the guard would then be reading the same stale
 * memo as the heading it is supposed to catch.
 *
 * One window stays open, and closing it would cost more than it saves. The fill below is a
 * plain read then write, so a request that computed its count just before a clear can store it
 * just after, and the ask quotes a pre-clear number until the memo expires. Sub-second to hit,
 * five minutes at the outside to heal, on a display number.
 *
 * @return int Non-negative count of successful agent tool calls in the log.
 */
function aafm_review_request_display_count(): int {
	$cached = get_transient( 'aafm_review_request_count' );
	if ( false !== $cached ) {
		return max( 0, (int) $cached );
	}
	$count = aafm_review_request_success_count();
	set_transient( 'aafm_review_request_count', $count, 5 * MINUTE_IN_SECONDS );
	return $count;
}

/**
 * Drop the memo, so the next read pays for a fresh count.
 *
 * The memo's key belongs to this file, but the code that has to invalidate it lives in the
 * activity log: deleting rows is what makes a stored count wrong. Spelling the key out over
 * there points the dependency the wrong way and hides a rename from whoever does it, and a
 * missed copy is not loud - the notice simply goes on quoting a number the log can no longer
 * back until the memo expires. So the audit layer asks for this instead. Callers there guard
 * with function_exists(), because uninstall.php loads includes/audit/log.php on its own with no
 * admin layer behind it.
 *
 * @return void
 */
function aafm_review_request_flush_display_count(): void {
	delete_transient( 'aafm_review_request_count' );
	// The per-request memo behind the transient has to go too, or a caller that read the count
	// earlier in the same request refills the transient from its own warm static and puts the
	// pre-flush number back for another five minutes. No caller does that today, but the
	// promise in the line above is unconditional and this is what makes it true. Forgetting
	// costs nothing: it discards the value rather than recomputing it.
	aafm_review_request_success_count( false, true );
}

/**
 * Persist the review-request state.
 *
 * Autoload stays off. The notice is site wide, so this is read on every wp-admin page load for
 * every administrator, but never once on the front end, and autoloading it would put it in the
 * bundle every front-end request pays for to answer questions only wp-admin asks.
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
 * The COUNT only runs here while the threshold is still unproven. Once the site clears it,
 * that fact is latched into the option and every later page load skips the ELIGIBILITY query
 * outright, which matters because "pending" has no time limit. It does not make those page
 * loads query-free: the heading still reads the count to quote it, and what bounds that half
 * is the five-minute memo, not the latch. A site that never reaches ten successes never
 * latches at all, so while the bar is unproven the number comes from that same memo rather
 * than a fresh scan each time. The latch records only that the bar WAS cleared. It never
 * stores the number, so the heading quotes what the log holds when it renders instead of
 * repeating whatever it held on the day the ask armed.
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
	// Belt and braces rather than the thing doing the work: core fires admin_notices from
	// wp-admin/admin-header.php and never from the network admin header, so this cannot be
	// true on the render path. It is kept because the reason still holds - the state option is
	// per site, so an ask rendered there would read the wrong site's counts - and because
	// eligibility is a public predicate that a future caller could reach from anywhere.
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
		// The success-narrowed count from the shared helper - never a hand-copied WHERE clause -
		// and through the same five-minute memo the heading reads. Until the threshold latches
		// this query runs on every admin page load for every administrator, and a site that never
		// reaches ten successes never latches, so without the memo that site pays for a full scan
		// forever and has no notice to show for it. Both readings only move one way, so a count up
		// to five minutes old can delay the stamp or the latch and can never reverse either.
		$successes = aafm_review_request_display_count();
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
		//
		// The COUNT above is the slowest thing on the page, and the notice is site wide, so an
		// answer can land in another tab while it runs. Saving the whole array read before the
		// COUNT would write the pre-answer status back over it and resurrect a dismissed ask.
		// So the option is re-read past the cache and only the two monotonic fields are merged
		// onto the fresh copy. A settled answer is left exactly as it stands and ends the check
		// there, on the render side as well as in the option. Both fields only ever move one way,
		// so merging them forward loses nothing: the stamp still cannot be restarted or shortened
		// by a log clear.
		if ( $changed ) {
			wp_cache_delete( 'aafm_review_request', 'options' );
			// The option's own key is only half of the cache. get_option() consults the
			// 'notoptions' blob BEFORE the per-option cache and records a missing row there on the
			// first read, so on a site where the row does not exist yet - a fresh install, or one
			// the operator has just reset - dropping the key alone leaves the re-read answering
			// defaults out of memory without ever reaching the database. It would then miss an
			// answer another tab stored in the meantime and write straight over it. Core rebuilds
			// the blob on the next miss, so this costs one cache round trip on a path that is
			// about to write anyway.
			wp_cache_delete( 'notoptions', 'options' );
			$fresh = aafm_review_request_state();
			if ( in_array( $fresh['status'], array( 'reviewed', 'dismissed' ), true ) ) {
				// Answered for good while this request was counting. Leaving the stored answer alone
				// is only half of honouring it: the checks below run on the local copy, which was read
				// before the answer landed, so falling through would render the ask one more time
				// after it was permanently settled.
				return false;
			}
			// Merged, not assigned. Another tab running this same check can have latched the
			// threshold or stamped the clock while this request's COUNT was still running, and
			// writing this request's older copy over the top would un-latch that latch or push the
			// stamp forward by however far apart the two requests were. So the latch is the OR of
			// the two and the stamp is the earlier of the two: whichever request writes second,
			// both fields end up where the earliest evidence puts them.
			$fresh['threshold_met'] = max( $fresh['threshold_met'], $state['threshold_met'] );
			if ( $state['first_success_seen_at'] > 0 ) {
				$fresh['first_success_seen_at'] = $fresh['first_success_seen_at'] > 0
					? min( $fresh['first_success_seen_at'], $state['first_success_seen_at'] )
					: $state['first_success_seen_at'];
			}
			aafm_review_request_save_state( $fresh );

			// A "maybe later" can land in the same window a permanent answer can, and the checks
			// below still run on the local copy read before it. The snooze is honoured after the
			// save rather than instead of it, because the merged fields go on mattering once the
			// snooze runs out - only the render for this page load is dropped.
			if ( 'snoozed' === $fresh['status'] && time() < $fresh['snooze_until'] ) {
				return false;
			}
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
 * and core's injected X), and any of them can end it for good, so the "used sparingly" half of
 * the same guideline is carried by the trigger (ten successful calls plus seven days) and by
 * that dismissibility rather than by a screen allowlist. It is not carried by an appearance
 * bound: the snooze cap limits how many times the operator is asked to ANSWER, and an ask
 * nobody answers keeps rendering.
 *
 * One exclusion is left, and it is the only thing this function does: outside wp-admin there
 * is no screen at all (get_current_screen() is not even defined on a front-end or cron
 * request), so a false here keeps the notice off every non-admin context. Any real admin
 * screen answers true.
 *
 * Which means it never actually excludes anything at its one call site. admin_notices fires
 * from wp-admin/admin-header.php, after the screen is set, so both halves are already true by
 * the time the render path asks. It stays as the cheap guard against a future caller running
 * the render function outside an admin request, but nothing is currently kept out by it, and a
 * reader looking for what keeps the notice off the front end should read the hook, not this.
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
 * The admin-post URL that records one verdict without JavaScript.
 *
 * Nonced on the notice's own action, the same one the AJAX handler and the notice's data-nonce
 * use, so the fallback opens exactly the one door the script path does.
 *
 * @param string $verdict One of 'review', 'later', 'dismiss'.
 * @return string
 */
function aafm_review_request_action_url( string $verdict ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'aafm_review_request',
				'verdict' => $verdict,
			),
			admin_url( 'admin-post.php' )
		),
		'aafm_review_request'
	);
}

/**
 * Render the review-request notice on admin_notices.
 *
 * Gated by the admin-screen check (any wp-admin screen qualifies now the ask is site wide;
 * the network-admin and capability exclusions sit in the eligibility check), the Quick
 * Connect suppression (the wizard modal owns the plugin page until the operator finishes or
 * opts out, and the first-run flow must never be interrupted by an ask), and the full
 * eligibility check. The heading reads the count from the SAME memoized, success-narrowed
 * helper as the trigger, so the two cannot quote different numbers, and describes it as what
 * the activity log shows, which is the only claim the data supports: any other counter could
 * credit the agent with calls it never made, and even this one is bounded by retention pruning
 * and the operator's own "Clear log" button.
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

	// Memoized for five minutes across requests. Once the threshold is latched the eligibility
	// check above skips the query entirely, which would leave this path running the scan on every
	// admin page load until the ask is answered.
	$count = aafm_review_request_display_count();
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
	$body = __( 'That means Agent Abilities for MCP is doing its job. If it\'s been useful, would you take two minutes to leave a review on wordpress.org? Reviews are the main way other site owners find the plugin.', 'agent-abilities-for-mcp' );
	?>
	<div class="notice notice-info is-dismissible aafm-review-request"
		<?php // Its own action, not the shared aafm_admin one. This notice renders on every admin screen, so printing the shared nonce would hand every page in wp-admin a token good for all nineteen of the plugin's AJAX actions. This one opens exactly one door. ?>
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'aafm_review_request' ) ); ?>"
		data-msg-review="<?php echo esc_attr__( 'Thanks. The review page is open in a new tab.', 'agent-abilities-for-mcp' ); ?>"
		data-msg-later="<?php echo esc_attr__( 'Okay. The review request is hidden for now.', 'agent-abilities-for-mcp' ); ?>"
		<?php // "Closed", not "will not come back": the POST is fire and forget, so the stronger promise is one a failed write turns into a lie the operator hears and cannot check. This describes what they can see instead. ?>
		data-msg-dismiss="<?php echo esc_attr__( 'Thanks. The review request is closed.', 'agent-abilities-for-mcp' ); ?>">
		<p><strong><?php echo esc_html( $heading ); ?></strong></p>
		<p><?php echo esc_html( $body ); ?></p>
		<?php // Flex with a gap so the two link-buttons are not left touching on a single word space. The plugin's admin CSS is not loaded on the Plugins list, so the rule rides inline, as the onboarding pointer's button spacing already does. ?>
		<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
			<a class="button button-primary" href="<?php echo esc_url( aafm_review_request_url() ); ?>" target="_blank" rel="noopener noreferrer" data-aafm-review="review"><?php esc_html_e( 'Sure, I\'ll review', 'agent-abilities-for-mcp' ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'agent-abilities-for-mcp' ); ?></span></a>
			<?php // Real links, not buttons. The footer script preventDefault()s both, so with JS running nothing about them changes, but if that script never runs (a strict CSP with no nonce filter, or another plugin throwing in the admin footer) they still work and the ask is still dismissible. ?>
			<a class="button-link" href="<?php echo esc_url( aafm_review_request_action_url( 'later' ) ); ?>" data-aafm-review="later"><?php esc_html_e( 'Maybe later', 'agent-abilities-for-mcp' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( aafm_review_request_action_url( 'dismiss' ) ); ?>" data-aafm-review="dismiss"><?php esc_html_e( 'Already did', 'agent-abilities-for-mcp' ); ?></a>
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
 * then removes the notice. The two dismissal controls are links to admin-post.php, so the
 * handler preventDefault()s them and answers over AJAX instead; if this script never runs they
 * are followed as ordinary links and the same verdict is recorded server side. The native X that core's common.js injects into .is-dismissible
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
 * ask again, which is the honest outcome. keepalive is there for the case where the review
 * page opens in this tab rather than a new one - a user setting or an extension can force
 * that - which would otherwise cancel the POST on its way out.
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
		// Not awaited, by design (see the docblock), but the rejection still has to land
		// somewhere or a failed dismiss logs an unhandled promise error in the console.
		fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', keepalive: true, body } ).catch( () => {} );
	};
	// Park focus on the anchor and speak the outcome, before the element holding the focused
	// control disappears. Without this, focus lands on <body>: the next Tab restarts at the
	// top of the page and a screen reader is told nothing at all.
	const handOff = ( verdict ) => {
		const message = notice.getAttribute( 'data-msg-' + verdict ) || '';
		// Core's common.js moves div.notice to just after .wp-header-end on ready, and leaves
		// these two siblings where admin_notices printed them, above .wrap entirely. Focus
		// would then land outside the main content, above the page heading the operator had
		// already tabbed past. Re-uniting the anchor here runs after the relocation whenever it
		// happened, so it works either way round.
		//
		// Only the anchor moves. after() on a connected node is a remove-and-reinsert, and a
		// live region torn out of the tree and written to in the same task is the classic way to
		// lose the announcement. The status region is screen-reader-text, so where it sits in
		// the document changes nothing a user can perceive - only the anchor's position matters,
		// and only for tab order.
		if ( anchor ) {
			notice.after( anchor );
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
 * Apply the operator's answer to the stored state and return the state it left behind.
 *
 * The single place the three verdicts turn into a status, shared by the AJAX handler and the
 * admin-post fallback so the two cannot drift apart. The verdict is trusted here: both callers
 * check the nonce, the capability, and the verdict against the known three before calling in.
 *
 * "review" and "dismiss" are permanent. "later" spends a snooze, and once the cap is spent the
 * same click stores the permanent state instead, so the ask keeps its promise about how many
 * times the operator has to answer no matter which dismissal path they use. Timestamps are
 * computed here, never accepted from the caller.
 *
 * State moves forward against a sequentially stale tab. Now that the notice is site wide, two
 * admin tabs both showing it is the ordinary case, and the second tab's click arrives against a
 * state the first tab already settled. The state is read from the database as late as possible,
 * so a stored terminal answer wins over anything a stale tab sends, and a "later" that lands
 * while a snooze is still running is dropped rather than spending a second snooze off one
 * appearance - otherwise a permanently declined ask would come back in fourteen days, or the
 * answer cap would burn down without the operator ever answering that many times. A stale
 * "review" or "already did" still applies over a live snooze: those are stronger answers, and
 * honouring them is the point.
 *
 * Two answers issued inside one request round trip remain last-write-wins. The option is not
 * compare-and-set and WordPress has no primitive that would make it one, so the honest
 * description of the guarantee is the one above: the late read shrinks the window to the gap
 * between the SELECT and the UPDATE. Adding a lock for a two-click race an operator has to work
 * at would cost more machinery than the preference row is worth.
 *
 * @param string $verdict One of 'review', 'later', 'dismiss'.
 * @return array{status:string,first_success_seen_at:int,snooze_until:int,snooze_count:int,threshold_met:int}
 */
function aafm_review_request_record_verdict( string $verdict ): array {
	// Read immediately before the write, and past the request's own object cache, so the two
	// precedence checks below run against what another tab has actually stored rather than
	// against a copy this request took earlier. Without the cache drop a second get_option()
	// answers from memory and the re-read decides nothing. Nothing is merged from an earlier
	// copy either: every field the transition touches lives in this one option.
	wp_cache_delete( 'aafm_review_request', 'options' );
	$state = aafm_review_request_state();

	// Already answered for good: report the stored state and change nothing.
	if ( in_array( $state['status'], array( 'reviewed', 'dismissed' ), true ) ) {
		return $state;
	}
	// Already snoozed and the snooze has not run out: a repeat "later" is a stale tab, not a
	// second appearance, so it must not spend another snooze.
	if ( 'later' === $verdict && 'snoozed' === $state['status'] && time() < $state['snooze_until'] ) {
		return $state;
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

	return $state;
}

/**
 * AJAX: record the operator's answer to the review ask.
 *
 * Nonce plus manage_options gated like every other aafm_* admin action, on the notice's own
 * nonce action rather than the shared one. The verdict is limited to the three known answers
 * here; what each of them means to the stored state lives in
 * aafm_review_request_record_verdict(), shared with the no-JS fallback.
 *
 * @return void
 */
function aafm_ajax_review_request(): void {
	check_ajax_referer( 'aafm_review_request', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( (string) $_POST['verdict'] ) ) : '';
	if ( ! in_array( $verdict, array( 'review', 'later', 'dismiss' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown action.', 'agent-abilities-for-mcp' ) ), 400 );
	}

	$state = aafm_review_request_record_verdict( $verdict );

	wp_send_json_success( array( 'status' => $state['status'] ) );
}

/**
 * Record the answer over admin-post, for when the notice's links are followed rather than
 * intercepted.
 *
 * The two dismissal controls are real links to admin-post.php, so the ask is dismissible with
 * no JavaScript at all - a strict Content-Security-Policy with no nonce filter installed, or an
 * error thrown earlier in the admin footer by another plugin, used to leave the operator with
 * two dead buttons and an ask that returned on every page load with no way to stop it. With the
 * script running, the click handler preventDefault()s these and nothing reaches this handler.
 *
 * Same three checks as the AJAX path, in the same order, and the same shared transition. It
 * redirects back to where the notice was rather than leaving the operator on a blank
 * admin-post.php response.
 *
 * @return void
 */
function aafm_handle_review_request_post(): void {
	check_admin_referer( 'aafm_review_request' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ), '', array( 'response' => 403 ) );
	}

	$verdict = isset( $_GET['verdict'] ) ? sanitize_key( wp_unslash( (string) $_GET['verdict'] ) ) : '';
	if ( ! in_array( $verdict, array( 'review', 'later', 'dismiss' ), true ) ) {
		wp_die( esc_html__( 'Unknown action.', 'agent-abilities-for-mcp' ), '', array( 'response' => 400 ) );
	}

	aafm_review_request_record_verdict( $verdict );

	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url() );
	exit;
}
