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
	 * @param bool  $with_nonce Whether to supply a valid aafm_admin nonce.
	 * @return bool True if the handler called wp_die (denied or completed).
	 */
	private function run_ajax( array $post, bool $with_nonce = true ): bool {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		$nonce             = $with_nonce ? wp_create_nonce( 'aafm_admin' ) : 'not-a-valid-nonce';
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
		$this->assertStringContainsString( 'Your agent has made 10 successful calls on this site', $html );
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
		$this->assertStringContainsString( 'Your agent has made 10 successful calls on this site', $html );
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
}
