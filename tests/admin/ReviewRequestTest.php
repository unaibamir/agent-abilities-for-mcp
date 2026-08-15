<?php
/**
 * Review-request notice: trigger math, the stamp-once clock, the state machine behind the three
 * answers, the snooze cap, screen and capability gating, and the success-only count source.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ReviewRequestTest extends TestCase {

	/**
	 * Deterministic start: no stored review state, an empty activity log, and the Quick Connect
	 * flags cleared. The option deletes run BEFORE the TRUNCATE so a stamp a previous test's
	 * eligibility check committed (TRUNCATE implicitly commits, see TestCase::set_up) is
	 * actually cleaned rather than rolled back into the next test.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_review_request' );
		delete_option( 'aafm_quickconnect_finished' );
		delete_option( 'aafm_quickconnect_dismissed' );
		aafm_install_activity_log();
		aafm_clear_activity_log();
		// The success count is memoized per request. A test process is many logical requests in
		// one PHP process, so it is recomputed here and after every batch this file logs.
		aafm_review_request_success_count( true );
	}

	public function tear_down(): void {
		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	/**
	 * Log N genuine successful tool-call rows.
	 *
	 * @param int $n How many rows to write.
	 */
	private function log_success_calls( int $n ): void {
		for ( $i = 0; $i < $n; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-post',
					'status'  => 'success',
				)
			);
		}
		aafm_review_request_success_count( true );
	}

	/**
	 * Seed the stored state with a first-success stamp old enough for the 7-day clock to pass.
	 */
	private function backdate_first_success(): void {
		$state                          = aafm_review_request_state();
		$state['first_success_seen_at'] = time() - ( 8 * DAY_IN_SECONDS );
		aafm_review_request_save_state( $state );
	}

	/**
	 * Render the notice on a given screen and return the output.
	 *
	 * @param string $screen_id Screen to render on (set via set_current_screen()).
	 * @return string
	 */
	private function render_on( string $screen_id ): string {
		set_current_screen( $screen_id );
		ob_start();
		aafm_render_review_request_notice();
		return (string) ob_get_clean();
	}

	/**
	 * Invoke the AJAX handler in-process; wp_die is routed through an exception so the JSON
	 * send does not exit the test. Option side effects applied before the die are already in
	 * place when this returns. The nonce is caller-controlled so the referer gate is testable.
	 *
	 * @param array $post       $_POST fields to set.
	 * @param bool  $with_nonce Whether to supply a valid aafm_review_request nonce.
	 * @return bool True if the handler called wp_die (denied or completed).
	 */
	private function run_ajax( array $post, bool $with_nonce = true ): bool {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		$nonce             = $with_nonce ? wp_create_nonce( 'aafm_review_request' ) : 'not-a-valid-nonce';
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$died = false;
		ob_start();
		try {
			aafm_ajax_review_request();
		} catch ( \WPDieException $e ) {
			$died = true;
			unset( $e );
		} finally {
			ob_end_clean();
		}

		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
		foreach ( array_keys( $post ) as $key ) {
			unset( $_POST[ $key ] );
		}
		return $died;
	}


	/**
	 * Invoke the no-JS admin-post handler in-process. Both of its exits are routed through
	 * exceptions - wp_die through the die handler, the redirect through the `wp_redirect`
	 * filter - so option side effects applied before the exit are already in place on return.
	 *
	 * @param array $query      $_GET fields to set (verdict).
	 * @param bool  $with_nonce Whether to supply a valid aafm_review_request nonce.
	 * @return string The captured redirect target, or '' when the handler died instead.
	 */
	private function run_admin_post( array $query, bool $with_nonce = true ): string {
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_handler', static fn() => $die );

		$captured = '';
		$catch    = static function ( $location ) use ( &$captured ) {
			$captured = (string) $location;
			throw new \RuntimeException( 'aafm_test_redirect' );
		};
		add_filter( 'wp_redirect', $catch, 1 );

		$query['_wpnonce'] = $with_nonce ? wp_create_nonce( 'aafm_review_request' ) : 'not-a-valid-nonce';
		foreach ( $query as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}

		ob_start();
		try {
			aafm_handle_review_request_post();
		} catch ( \WPDieException $e ) {
			unset( $e );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			ob_end_clean();
		}

		remove_filter( 'wp_redirect', $catch, 1 );
		remove_all_filters( 'wp_die_handler' );
		foreach ( array_keys( $query ) as $key ) {
			unset( $_GET[ $key ], $_REQUEST[ $key ] );
		}
		return $captured;
	}


	public function test_not_eligible_below_the_call_threshold(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 9 );
		$this->backdate_first_success();

		$this->assertFalse( aafm_review_request_eligible() );
	}

	public function test_not_eligible_before_seven_days_have_passed(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );

		// The first check stamps the clock at "now", so the 7-day wait cannot have passed yet.
		$this->assertFalse( aafm_review_request_eligible() );
		$this->assertGreaterThan( 0, aafm_review_request_state()['first_success_seen_at'] );
	}

	public function test_eligible_when_both_thresholds_are_met(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$this->assertTrue( aafm_review_request_eligible() );
	}

	public function test_denied_calls_do_not_count_toward_the_threshold(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 5 );
		for ( $i = 0; $i < 20; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/trash-post',
					'status'  => 'denied',
				)
			);
		}
		$this->backdate_first_success();

		// 25 tool calls, but only 5 successes: the value bar is not met.
		$this->assertSame( 25, aafm_agent_call_count() );
		$this->assertSame( 5, aafm_agent_call_count( 'success' ) );
		$this->assertFalse( aafm_review_request_eligible() );
	}


	public function test_first_success_stamp_is_written_once_and_survives_a_log_clear(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 1 );

		// The first eligibility check observes a success and stamps the clock.
		$this->assertFalse( aafm_review_request_eligible() );
		$stamp = aafm_review_request_state()['first_success_seen_at'];
		$this->assertGreaterThan( 0, $stamp );

		// Clearing the log resets the count but must never touch the stamp: pruning or a
		// clear can only DELAY the ask (the count rebuilds), never re-arm or shorten it.
		aafm_clear_activity_log();
		aafm_review_request_success_count( true );
		$this->assertFalse( aafm_review_request_eligible() );
		$this->assertSame( $stamp, aafm_review_request_state()['first_success_seen_at'] );
	}

	public function test_an_existing_stamp_is_never_overwritten(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();
		$stamp = aafm_review_request_state()['first_success_seen_at'];

		// Later checks with more successes in the log must keep the original stamp.
		$this->assertTrue( aafm_review_request_eligible() );
		$this->assertSame( $stamp, aafm_review_request_state()['first_success_seen_at'] );
	}


	/**
	 * Two callers want the same number on one page load, and the COUNT only has to run once.
	 * The memo is the mechanism, so a count taken after the log changed but before the memo was
	 * dropped still reads the earlier value - the guarantee is "once per request", not "live at
	 * every call site within a request".
	 */
	public function test_the_success_count_is_computed_once_per_request(): void {
		$this->log_success_calls( 4 );
		$this->assertSame( 4, aafm_review_request_success_count() );

		$this->log_success_calls( 3 );
		aafm_review_request_success_count( true );
		$this->assertSame( 7, aafm_review_request_success_count() );

		// A write the memo has not been told about does not move the memoized answer.
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
			)
		);
		$this->assertSame( 8, aafm_agent_call_count( 'success' ) );
		$this->assertSame( 7, aafm_review_request_success_count() );
	}

	/**
	 * "pending" has no time limit, so an operator who ignores the notice would pay for the
	 * eligibility COUNT on every admin page load forever. Clearing the bar is latched into the
	 * option instead, and later loads answer from the option alone.
	 */
	public function test_meeting_the_threshold_is_latched_into_the_option(): void {
		$this->acting_as( 'administrator' );
		$this->assertSame( 0, aafm_review_request_state()['threshold_met'] );

		$this->log_success_calls( 10 );
		$this->backdate_first_success();
		$this->assertTrue( aafm_review_request_eligible() );

		$this->assertSame( 1, aafm_review_request_state()['threshold_met'] );

		// Proof that the later loads no longer count: with the log emptied and the memo
		// dropped, a re-count would drop back under the bar and return false.
		aafm_clear_activity_log();
		aafm_review_request_success_count( true );
		$this->assertSame( 0, aafm_agent_call_count( 'success' ) );
		$this->assertTrue( aafm_review_request_eligible() );
	}

	/**
	 * Two admin tabs can run the eligibility check at once, and the COUNT is slow enough that the
	 * other tab's answer lands while this one is still counting. The re-read before the write is
	 * there for exactly that, so what it finds has to be merged rather than overwritten: a latch
	 * another request already earned must survive, and the stamp must keep the earlier of the two
	 * times rather than being nudged forward.
	 */
	public function test_a_concurrent_latch_and_an_earlier_stamp_survive_the_write_back(): void {
		$this->acting_as( 'administrator' );
		$earlier = time() - HOUR_IN_SECONDS;
		$reads   = 0;

		// Stands in for the other tab: this request opens on a state with nothing latched, and by
		// the time it re-reads to write, the other tab has latched the threshold and stamped the
		// clock an hour earlier. Both reads are served from the filter; the save that follows falls
		// through to the database.
		$other_tab = static function ( $pre ) use ( &$reads, $earlier ) {
			++$reads;
			if ( 1 === $reads ) {
				return array(
					'status'                => 'pending',
					'first_success_seen_at' => 0,
					'snooze_until'          => 0,
					'snooze_count'          => 0,
					'threshold_met'         => 0,
				);
			}
			if ( 2 === $reads ) {
				return array(
					'status'                => 'pending',
					'first_success_seen_at' => $earlier,
					'snooze_until'          => 0,
					'snooze_count'          => 0,
					'threshold_met'         => 1,
				);
			}
			return $pre;
		};

		// Below the bar, so this request stamps the clock (the write it needs) without latching.
		$this->log_success_calls( 3 );
		add_filter( 'pre_option_aafm_review_request', $other_tab );
		aafm_review_request_eligible();
		remove_filter( 'pre_option_aafm_review_request', $other_tab );

		$stored = aafm_review_request_state();
		$this->assertSame( 1, $stored['threshold_met'] );
		$this->assertSame( $earlier, $stored['first_success_seen_at'] );
	}

	/**
	 * The latch records only that the bar was cleared, never the number. The heading has to
	 * quote what the log holds when it renders, so a site that keeps working sees its real,
	 * current count and not the ten it happened to have on the day the notice armed. "When it
	 * renders" is within the render count's five-minute memo, which is why this renders once.
	 * The point being pinned is that the latch does not freeze the number, not that the heading
	 * is live to the row.
	 */
	public function test_the_latch_does_not_freeze_the_heading_count(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();
		$this->assertTrue( aafm_review_request_eligible() );

		$this->log_success_calls( 2 );

		$this->assertStringContainsString(
			'Your activity log shows 12 successful agent calls on this site',
			$this->render_on( 'plugins' )
		);
	}

	/**
	 * Once the threshold is latched the eligibility check stops running the COUNT, which leaves
	 * the render path running a full table scan on every admin page load for as long as the ask
	 * goes unanswered. So the number it quotes is memoized for five minutes. A clear has to drop
	 * that memo on the spot, because stopping the ask claiming a total the log can no longer
	 * back is the render guard's whole job.
	 */
	public function test_the_heading_count_is_memoized_and_a_clear_drops_the_memo(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$this->assertStringContainsString( 'shows 10 successful agent calls', $this->render_on( 'plugins' ) );
		$this->assertSame( 10, (int) get_transient( 'aafm_review_request_count' ) );

		// Two more calls inside the window: the heading keeps the memoized number rather than
		// paying for a second scan on the next admin page load.
		$this->log_success_calls( 2 );
		$this->assertStringContainsString( 'shows 10 successful agent calls', $this->render_on( 'plugins' ) );

		// A clear is the case that cannot wait for the expiry.
		aafm_clear_activity_log();
		aafm_review_request_success_count( true );
		$this->assertFalse( get_transient( 'aafm_review_request_count' ) );
		$this->assertSame( '', $this->render_on( 'plugins' ) );
	}

	/**
	 * The latch deliberately survives a log clear, because a clear must never re-arm or shorten
	 * the ask. The heading must not survive it: quoting "0 successful agent calls" while asking
	 * for a review off the back of them is the misleading-claim line this feature stays behind.
	 * The ask waits for the log to rebuild instead.
	 */
	public function test_the_notice_stays_quiet_when_the_log_can_no_longer_back_its_claim(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();
		$this->assertStringContainsString( 'aafm-review-request', $this->render_on( 'plugins' ) );

		aafm_clear_activity_log();
		aafm_review_request_success_count( true );

		$this->assertTrue( aafm_review_request_eligible() );
		$this->assertSame( '', $this->render_on( 'plugins' ) );
	}


	public function test_review_verdict_is_permanent(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'review' ) );

		$this->assertSame( 'reviewed', aafm_review_request_state()['status'] );

		// Terminal: even with the trigger fully met, the notice never returns.
		$this->log_success_calls( 10 );
		$this->backdate_first_success();
		$state           = aafm_review_request_state();
		$state['status'] = 'reviewed';
		aafm_review_request_save_state( $state );
		$this->assertFalse( aafm_review_request_eligible() );
	}

	public function test_dismiss_verdict_is_permanent(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'dismiss' ) );

		$this->assertSame( 'dismissed', aafm_review_request_state()['status'] );

		$this->log_success_calls( 10 );
		$this->backdate_first_success();
		$state           = aafm_review_request_state();
		$state['status'] = 'dismissed';
		aafm_review_request_save_state( $state );
		$this->assertFalse( aafm_review_request_eligible() );
	}

	public function test_later_verdict_snoozes_fourteen_days(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'later' ) );

		$state = aafm_review_request_state();
		$this->assertSame( 'snoozed', $state['status'] );
		$this->assertSame( 1, $state['snooze_count'] );
		// The snooze horizon is ~14 days out, computed server-side.
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $state['snooze_until'], 5 );
	}

	public function test_active_snooze_blocks_eligibility_and_an_expired_one_does_not(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$state                 = aafm_review_request_state();
		$state['status']       = 'snoozed';
		$state['snooze_count'] = 1;
		$state['snooze_until'] = time() + DAY_IN_SECONDS;
		aafm_review_request_save_state( $state );
		$this->assertFalse( aafm_review_request_eligible() );

		// Once the snooze passes, the state reads as pending again.
		$state['snooze_until'] = time() - 10;
		aafm_review_request_save_state( $state );
		$this->assertTrue( aafm_review_request_eligible() );
	}

	public function test_snooze_cap_makes_the_third_later_permanent(): void {
		$this->acting_as( 'administrator' );
		$state                 = aafm_review_request_state();
		$state['status']       = 'snoozed';
		$state['snooze_count'] = 2;
		$state['snooze_until'] = time() - 10;
		aafm_review_request_save_state( $state );

		// Both snoozes are spent, so this "later" (the native X posts the same verdict) is
		// the third appearance's dismissal and becomes permanent.
		$this->run_ajax( array( 'verdict' => 'later' ) );

		$after = aafm_review_request_state();
		$this->assertSame( 'dismissed', $after['status'] );
		$this->assertFalse( aafm_review_request_eligible() );
	}


	/**
	 * Two tabs, one notice. Answering "Already did" in the first settles the state for good;
	 * the second tab still shows the answered notice, and its "Maybe later" used to fall
	 * through to the snooze branch and bring a permanently declined ask back in fourteen days.
	 */
	public function test_a_stale_later_cannot_downgrade_a_terminal_state(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'dismiss' ) );
		$this->assertSame( 'dismissed', aafm_review_request_state()['status'] );

		$this->run_ajax( array( 'verdict' => 'later' ) );

		$after = aafm_review_request_state();
		$this->assertSame( 'dismissed', $after['status'] );
		$this->assertSame( 0, $after['snooze_count'] );
		$this->assertSame( 0, $after['snooze_until'] );
	}

	public function test_a_stale_later_cannot_downgrade_a_reviewed_state(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'review' ) );

		$this->run_ajax( array( 'verdict' => 'later' ) );

		$this->assertSame( 'reviewed', aafm_review_request_state()['status'] );
	}

	/**
	 * One appearance may only ever spend one snooze. A second "later" from another tab showing
	 * the same (already answered) notice must not burn a second slot off the three-appearance
	 * cap, nor push the horizon another fourteen days out.
	 */
	public function test_a_repeat_later_during_a_live_snooze_does_not_double_spend(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'later' ) );
		$first = aafm_review_request_state();

		$this->run_ajax( array( 'verdict' => 'later' ) );

		$after = aafm_review_request_state();
		$this->assertSame( 1, $after['snooze_count'] );
		$this->assertSame( $first['snooze_until'], $after['snooze_until'] );
	}

	/**
	 * The guard only blocks downgrades. A stale tab whose operator clicks the primary action
	 * (or "Already did") is giving a stronger answer than the snooze already stored, and that
	 * answer has to stick - otherwise someone who reviewed the plugin gets asked again.
	 */
	public function test_review_still_applies_over_a_live_snooze(): void {
		$this->acting_as( 'administrator' );
		$this->run_ajax( array( 'verdict' => 'later' ) );
		$this->assertSame( 'snoozed', aafm_review_request_state()['status'] );

		$this->run_ajax( array( 'verdict' => 'review' ) );

		$this->assertSame( 'reviewed', aafm_review_request_state()['status'] );
	}

	/**
	 * An expired snooze is a genuine new appearance, so its "later" spends the next slot.
	 */
	public function test_a_later_after_the_snooze_expires_spends_the_next_snooze(): void {
		$this->acting_as( 'administrator' );
		$state                 = aafm_review_request_state();
		$state['status']       = 'snoozed';
		$state['snooze_count'] = 1;
		$state['snooze_until'] = time() - 10;
		aafm_review_request_save_state( $state );

		$this->run_ajax( array( 'verdict' => 'later' ) );

		$after = aafm_review_request_state();
		$this->assertSame( 'snoozed', $after['status'] );
		$this->assertSame( 2, $after['snooze_count'] );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $after['snooze_until'], 5 );
	}


	public function test_ajax_rejects_a_bad_nonce_without_touching_state(): void {
		$this->acting_as( 'administrator' );
		$died = $this->run_ajax( array( 'verdict' => 'dismiss' ), false );

		$this->assertTrue( $died );
		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}

	public function test_ajax_rejects_a_non_admin_without_touching_state(): void {
		$this->acting_as( 'subscriber' );
		$died = $this->run_ajax( array( 'verdict' => 'dismiss' ) );

		$this->assertTrue( $died );
		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}

	public function test_ajax_rejects_an_unknown_verdict_without_touching_state(): void {
		$this->acting_as( 'administrator' );
		$died = $this->run_ajax( array( 'verdict' => 'reset-everything' ) );

		$this->assertTrue( $died );
		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}


	public function test_notice_renders_on_the_plugins_screen_when_eligible(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		$this->assertStringContainsString( 'aafm-review-request', $html );
		$this->assertStringContainsString( 'Your activity log shows 10 successful agent calls on this site', $html );
		$this->assertStringContainsString( 'wordpress.org/support/plugin/agent-abilities-for-mcp/reviews/#new-post', $html );
		$this->assertStringContainsString( 'Maybe later', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
	}

	public function test_notice_renders_site_wide_across_admin_screens(): void {
		// Operator decision: the ask is site wide, which guideline 11 permits for a dismissible
		// notice. "Used sparingly" is carried by the trigger and the snooze cap, not by a screen
		// allowlist, so any admin screen may render it. This pins that intent: a screen the old
		// two-item allowlist excluded must now show it.
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		foreach ( array( 'dashboard', 'edit-post', 'options-general', 'users' ) as $screen_id ) {
			$this->assertStringContainsString(
				'aafm-review-request',
				$this->render_on( $screen_id ),
				"The notice should render on the {$screen_id} screen."
			);
		}
	}

	public function test_notice_never_renders_for_a_non_admin(): void {
		$this->acting_as( 'editor' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$this->assertFalse( aafm_review_request_eligible() );
		$this->assertSame( '', $this->render_on( 'plugins' ) );
	}

	public function test_notice_is_suppressed_while_the_quick_connect_wizard_is_due(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		// Fresh flags: the wizard modal owns the plugin page, so the ask stays out of the
		// first-run flow there...
		$this->assertSame( '', $this->render_on( 'toplevel_page_agent-abilities-for-mcp' ) );

		// ...and renders again once the operator has finished the wizard.
		update_option( 'aafm_quickconnect_finished', '1' );
		$html = $this->render_on( 'toplevel_page_agent-abilities-for-mcp' );
		$this->assertStringContainsString( 'aafm-review-request', $html );
	}

	/**
	 * The heading may only claim what the log can back. Retention pruning (default 30 days,
	 * operator-configurable, 0 meaning keep forever) and the "Clear log" button both mean the
	 * count is the log's current contents, never an all-time total, so the sentence is worded
	 * as what the log shows rather than what the agent has ever done.
	 */
	public function test_heading_claims_the_log_contents_not_an_all_time_total(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		$this->assertStringContainsString( 'Your activity log shows 10 successful agent calls on this site', $html );
		$this->assertStringNotContainsString( 'has made', $html );
	}

	/**
	 * The heading's number must come from the success-narrowed shared helper: OAuth ceremony
	 * rows (which can carry status success), transport refusals, and denied tool calls are
	 * all in the log, and none of them may inflate the count the notice asserts.
	 */
	public function test_heading_count_comes_from_the_success_filtered_source(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		for ( $i = 0; $i < 3; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/trash-post',
					'status'  => 'denied',
				)
			);
		}
		aafm_oauth_log_event( 'authorize', 'success', array( 'user_id' => get_current_user_id() ) );
		aafm_oauth_log_event( 'token', 'success', array( 'user_id' => get_current_user_id() ) );
		aafm_log_activity(
			array(
				'ability' => '(transport)',
				'status'  => 'denied',
			)
		);
		$this->backdate_first_success();

		// 16 rows total, 13 genuine tool calls, 10 successes: the heading quotes 10.
		$this->assertSame( 16, aafm_activity_count() );
		$this->assertSame( 13, aafm_agent_call_count() );
		$this->assertSame( 10, aafm_agent_call_count( 'success' ) );

		$html = $this->render_on( 'plugins' );
		$this->assertStringContainsString( 'Your activity log shows 10 successful agent calls on this site', $html );
	}


	/**
	 * Every answer removes the element the focused control sits in, so the notice ships two
	 * empty siblings that outlive it: an anchor to catch focus and a status region to speak the
	 * outcome. Both must render alongside the notice or the JS has nothing to hand off to.
	 */
	public function test_notice_renders_a_focus_anchor_and_a_status_region(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		$this->assertStringContainsString( '<div class="aafm-review-request-anchor" tabindex="-1"></div>', $html );
		$this->assertStringContainsString( 'class="screen-reader-text aafm-review-request-status" role="status" aria-live="polite"', $html );
	}

	/**
	 * The hand-off relocates the focus anchor and nothing else. after() on a connected node
	 * removes and reinserts it, so moving the live region in the same task it is written to is
	 * how an announcement gets dropped. The region is visually hidden, so its position in the
	 * document buys nothing to weigh against that.
	 */
	public function test_the_live_region_is_not_relocated_when_it_is_written_to(): void {
		$js = aafm_review_request_footer_js();

		$this->assertStringContainsString( 'notice.after( anchor );', $js );
		$this->assertStringNotContainsString( 'notice.after( anchor, status );', $js );
	}

	/**
	 * The notice renders on every admin screen, so the nonce it prints has to open one door and
	 * not nineteen. It must verify against its own action and must not be a usable aafm_admin
	 * token, which any other plugin's admin-page XSS could otherwise lift and spend.
	 */
	public function test_the_notice_prints_its_own_nonce_and_not_the_shared_one(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', $html, $matches ) );
		$nonce = $matches[1];
		$this->assertNotFalse( wp_verify_nonce( $nonce, 'aafm_review_request' ) );
		$this->assertFalse( wp_verify_nonce( $nonce, 'aafm_admin' ) );
	}

	/**
	 * Each verdict announces its own outcome, so a screen reader user learns which of the three
	 * answers was recorded rather than watching the notice vanish in silence. The strings are
	 * server-rendered (and translated) onto the notice; the script only reads them back.
	 */
	public function test_each_verdict_carries_its_own_announcement(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		$this->assertStringContainsString( 'data-msg-review="Thanks. The review page is open in a new tab."', $html );
		$this->assertStringContainsString( 'data-msg-later="Okay. The review request is hidden for now."', $html );
		$this->assertStringContainsString( 'data-msg-dismiss="Thanks. The review request is closed."', $html );

		// The script moves focus before removal and reads the matching message back out.
		$js = aafm_review_request_footer_js();
		$this->assertStringContainsString( "notice.getAttribute( 'data-msg-' + verdict )", $js );
		$this->assertStringContainsString( 'anchor.focus();', $js );
		$this->assertStringContainsString( 'status.textContent = message;', $js );
	}

	/**
	 * The primary action opens wordpress.org in a new tab, so it carries the same warning the
	 * plugin's own nav links use (page.php). Unannounced new tabs are disorienting for screen
	 * reader users, who otherwise get no signal that the context changed.
	 */
	public function test_the_review_link_warns_that_it_opens_a_new_tab(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		$this->assertStringContainsString( '<span class="screen-reader-text">(opens in a new tab)</span>', $html );
	}

	/**
	 * The verdict POST has to survive the navigation the primary action starts, otherwise a
	 * click on "Sure, I'll review" can be dropped mid-flight and the ask comes back.
	 */
	public function test_the_verdict_post_is_sent_with_keepalive(): void {
		$this->assertStringContainsString( 'keepalive: true', aafm_review_request_footer_js() );
	}

	/**
	 * The option rides the canonical config list, so reset clears it (re-arming only after a
	 * fresh 7 days + 10 new successes, because reset also empties the log) and the delete-data
	 * uninstall path removes it (pinned functionally by UninstallTest's loop over the list).
	 */
	public function test_option_is_registered_and_cleared_by_reset(): void {
		$this->assertContains( 'aafm_review_request', aafm_config_option_names() );

		aafm_install_oauth_tables();
		$state           = aafm_review_request_state();
		$state['status'] = 'dismissed';
		aafm_review_request_save_state( $state );

		aafm_reset_plugin();

		$this->assertFalse( get_option( 'aafm_review_request', false ) );
		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}

	public function test_the_shutdown_reset_forgets_the_memo_without_spending_a_query(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 3 );

		// Prime the memo, then confirm a second read costs nothing.
		$this->assertSame( 3, aafm_review_request_success_count() );
		$before = $wpdb->num_queries;
		$this->assertSame( 3, aafm_review_request_success_count() );
		$this->assertSame( $before, $wpdb->num_queries, 'A memoized read must not query.' );

		// The shutdown reset discards the value. It must not recompute on its way out, or it
		// would spend the query the memo exists to save.
		$before = $wpdb->num_queries;
		aafm_review_request_reset_success_count();
		$this->assertSame( $before, $wpdb->num_queries, 'The reset must not query.' );

		// The next read is cold, so it sees rows written after the memo was primed. Without the
		// reset a persistent worker would keep quoting the stale 3 forever.
		$this->log_success_calls( 2 );
		aafm_review_request_reset_success_count();
		$this->assertSame( 5, aafm_review_request_success_count() );
	}

	/**
	 * Every verdict is posted by the notice's footer script, so a site where that script never
	 * runs - a strict CSP with no nonce filter installed, or another plugin throwing earlier in
	 * the admin footer - used to be left with two dead buttons and an ask that came back on
	 * every page load with nothing able to stop it. The two dismissal controls are real nonced
	 * links to admin-post.php now, and the script preventDefault()s them, so the JS path is
	 * unchanged and the no-JS path exists.
	 */
	public function test_the_dismissal_controls_are_nonced_admin_post_links(): void {
		$this->acting_as( 'administrator' );
		$this->log_success_calls( 10 );
		$this->backdate_first_success();

		$html = $this->render_on( 'plugins' );

		foreach ( array( 'later', 'dismiss' ) as $verdict ) {
			$this->assertSame(
				1,
				preg_match( '/<a class="button-link" href="([^"]+)" data-aafm-review="' . $verdict . '"/', $html, $matches ),
				"The {$verdict} control must render as a link."
			);
			$url = html_entity_decode( $matches[1] );
			$this->assertStringContainsString( 'admin-post.php', $url );
			$this->assertStringContainsString( 'action=aafm_review_request', $url );
			$this->assertStringContainsString( 'verdict=' . $verdict, $url );

			$query = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'] ?? '', 'aafm_review_request' ) );
		}

		// The script still intercepts them, so nothing changes when JS is running.
		$this->assertStringContainsString( 'event.preventDefault();', aafm_review_request_footer_js() );
	}

	public function test_admin_post_dismiss_is_permanent_and_redirects_back(): void {
		$this->acting_as( 'administrator' );
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );

		$target = $this->run_admin_post( array( 'verdict' => 'dismiss' ) );

		$this->assertSame( 'dismissed', aafm_review_request_state()['status'] );
		$this->assertSame( admin_url( 'plugins.php' ), $target );
		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_admin_post_later_snoozes_like_the_ajax_path(): void {
		$this->acting_as( 'administrator' );

		$this->run_admin_post( array( 'verdict' => 'later' ) );

		$state = aafm_review_request_state();
		$this->assertSame( 'snoozed', $state['status'] );
		$this->assertSame( 1, $state['snooze_count'] );
		$this->assertGreaterThan( time() + ( 13 * DAY_IN_SECONDS ), $state['snooze_until'] );
	}

	/**
	 * The link path spends the snooze cap on the same schedule the AJAX path does, because both
	 * run the one shared transition. Two snoozes, then the third answer is permanent.
	 */
	public function test_admin_post_honours_the_snooze_cap(): void {
		$this->acting_as( 'administrator' );

		for ( $i = 0; $i < 3; $i++ ) {
			$state                 = aafm_review_request_state();
			$state['snooze_until'] = 0;
			aafm_review_request_save_state( $state );
			$this->run_admin_post( array( 'verdict' => 'later' ) );
		}

		$this->assertSame( 'dismissed', aafm_review_request_state()['status'] );
	}

	public function test_admin_post_rejects_a_bad_nonce_without_touching_state(): void {
		$this->acting_as( 'administrator' );

		$this->run_admin_post( array( 'verdict' => 'dismiss' ), false );

		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}

	public function test_admin_post_rejects_a_non_admin_without_touching_state(): void {
		$this->acting_as( 'editor' );

		$this->run_admin_post( array( 'verdict' => 'dismiss' ) );

		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}

	public function test_admin_post_rejects_an_unknown_verdict_without_touching_state(): void {
		$this->acting_as( 'administrator' );

		$this->run_admin_post( array( 'verdict' => 'nope' ) );

		$this->assertSame( 'pending', aafm_review_request_state()['status'] );
	}
}
