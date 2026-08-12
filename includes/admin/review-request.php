<?php
/**
 * Review-request notice: the one-time wp-admin ask for a wordpress.org review.
 *
 * Shows a dismissible admin notice asking the operator to review the plugin, but only once the
 * site has demonstrable value from it: at least ten successful agent tool calls in the activity
 * log AND at least seven days since the first such call was observed. The heading quotes the live
 * success count read at render time from the same source as the trigger, so the sentence is
 * provably true whenever it renders. All state lives in one per-site option
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
 * @return array{status:string,first_success_seen_at:int,snooze_until:int,snooze_count:int}
 */
function aafm_review_request_state(): array {
	$defaults = array(
		'status'                => 'pending',
		'first_success_seen_at' => 0,
		'snooze_until'          => 0,
		'snooze_count'          => 0,
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
	);
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

	// The success-narrowed count from the shared helper - never a hand-copied WHERE clause.
	$successes = aafm_agent_call_count( 'success' );

	// Stamp the seven-day clock exactly once, on the first success ever observed.
	if ( $successes > 0 && 0 === $state['first_success_seen_at'] ) {
		$state['first_success_seen_at'] = time();
		aafm_review_request_save_state( $state );
	}

	if ( $successes < aafm_review_request_call_threshold() ) {
		return false;
	}
	if ( 0 === $state['first_success_seen_at'] ) {
		return false;
	}
	return ( time() - $state['first_success_seen_at'] ) >= aafm_review_request_wait_seconds();
}

/**
 * Whether the current admin screen is one the notice may render on.
 *
 * Exactly two screens: the plugin's own page and the Plugins list. Guideline 11 requires
 * notices to be "limited in scope and used sparingly, be that contextually or only on the
 * plugin's setting page" - the main wp-admin Dashboard is not contextual to this plugin,
 * so it is deliberately NOT in this list. The Plugins list is, because that is where an
 * operator manages this plugin without ever opening its page.
 *
 * @return bool
 */
function aafm_review_request_screen_allowed(): bool {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}
	return in_array( $screen->id, array( 'toplevel_page_agent-abilities-for-mcp', 'plugins' ), true );
}

/**
 * Render the review-request notice on admin_notices.
 *
 * Gated by the screen allowlist, the Quick Connect suppression (the wizard modal owns the
 * plugin page until the operator finishes or opts out, and the first-run flow must never be
 * interrupted by an ask), and the full eligibility check. The heading reads the live
 * success count at render time from the SAME success-narrowed helper as the trigger, so the
 * number it asserts is true whenever the notice renders - any other counter could claim
 * successful calls the agent never made.
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

	$count = aafm_agent_call_count( 'success' );
	/* translators: %s: number of successful agent tool calls recorded on this site. */
	$heading = sprintf( __( 'Your agent has made %s successful calls on this site', 'agent-abilities-for-mcp' ), number_format_i18n( $count ) );
	$body    = __( 'That means Agent Abilities for MCP is doing its job. If it\'s been useful, would you take two minutes to leave a review on wordpress.org? Reviews are the main way other site owners find the plugin.', 'agent-abilities-for-mcp' );
	?>
	<div class="notice notice-info is-dismissible aafm-review-request" data-nonce="<?php echo esc_attr( wp_create_nonce( 'aafm_admin' ) ); ?>">
		<p><strong><?php echo esc_html( $heading ); ?></strong></p>
		<p><?php echo esc_html( $body ); ?></p>
		<?php // Flex with a gap so the two link-buttons are not left touching on a single word space. The plugin's admin CSS is not loaded on the Plugins list, so the rule rides inline, as the onboarding pointer's button spacing already does. ?>
		<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
			<a class="button button-primary" href="<?php echo esc_url( aafm_review_request_url() ); ?>" target="_blank" rel="noopener noreferrer" data-aafm-review="review"><?php esc_html_e( 'Sure, I\'ll review', 'agent-abilities-for-mcp' ); ?></a>
			<button type="button" class="button-link" data-aafm-review="later"><?php esc_html_e( 'Maybe later', 'agent-abilities-for-mcp' ); ?></button>
			<button type="button" class="button-link" data-aafm-review="dismiss"><?php esc_html_e( 'Already did, don\'t show again', 'agent-abilities-for-mcp' ); ?></button>
		</p>
	</div>
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
 * AJAX action and removes the notice. The native X that core's common.js injects into
 * .is-dismissible notices is caught by delegation on the notice element and posted as
 * "later", so a reflexive dismiss snoozes instead of burning the ask. Nothing user-supplied
 * ever reaches the DOM; the script only reads the server-rendered nonce attribute.
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
		fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body } );
	};
	notice.addEventListener( 'click', ( event ) => {
		const control = event.target.closest( '[data-aafm-review]' );
		if ( control ) {
			const verdict = control.dataset.aafmReview;
			send( verdict );
			if ( 'review' === verdict ) {
				// Let the link open its new tab first, then clear the notice on the next tick.
				window.setTimeout( () => notice.remove(), 0 );
			} else {
				event.preventDefault();
				notice.remove();
			}
			return;
		}
		// Core's injected X: treat it as "Maybe later" (core removes the element itself).
		if ( event.target.closest( '.notice-dismiss' ) ) {
			send( 'later' );
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
