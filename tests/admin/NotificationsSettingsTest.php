<?php
/**
 * Saving notification settings through the one Settings save, the card, the test email, the admin
 * notice and its dismissal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class NotificationsSettingsTest extends TestCase {

	use AjaxHarness;

	/**
	 * Messages captured instead of sent.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $mail = array();

	/**
	 * The captured messages, after the end-of-request flush a real request runs on shutdown.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function sent(): array {
		aafm_notify_flush_outbox();
		return $this->mail;
	}

	/**
	 * What the mail filter returns: true for sent, false for a failed send.
	 *
	 * @var bool
	 */
	private bool $mail_ok = true;

	public function set_up(): void {
		parent::set_up();
		$this->mail = array();
		aafm_notify_outbox( 'take' ); // Drop anything an earlier test left queued.
		$this->mail_ok = true;
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	/**
	 * Capture the message instead of sending it.
	 *
	 * @param mixed               $pre  Short-circuit value.
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $pre, array $atts ): bool {
		unset( $pre );
		$this->mail[] = $atts;
		return $this->mail_ok;
	}

	/**
	 * Post the fields as an administrator and run the Settings save.
	 *
	 * @param array<string,string> $fields Extra posted fields.
	 * @return array<string,mixed>
	 */
	private function save( array $fields ): array {
		$this->post_as( 'administrator', $fields );
		return $this->run_handler( 'aafm_ajax_save_settings' );
	}

	public function test_a_valid_save_stores_the_settings_and_schedules_the_digest(): void {
		$json = $this->save(
			array(
				'aafm_notify_mode'        => 'digest',
				'aafm_notify_email'       => 'owner@example.com',
				'aafm_notify_switch_flip' => '1',
				'aafm_notify_risky_run'   => '1',
			)
		);
		$this->assertTrue( $json['success'] ?? false, wp_json_encode( $json ) );
		$s = aafm_notifications_settings();
		$this->assertSame( 'digest', $s['mode'] );
		$this->assertSame( 'owner@example.com', $s['email'] );
		$this->assertTrue( $s['triggers']['switch_flip'] );
		$this->assertFalse( $s['triggers']['new_app'] );
		$this->assertNotFalse( wp_next_scheduled( 'aafm_notifications_digest' ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/notifications' ) );
		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( 'owner@example.com', (string) $rows[0]['detail'], 'The address never goes in the log.' );
	}

	public function test_an_invalid_save_changes_nothing_at_all(): void {
		update_option( 'aafm_rate_limit_per_min', 3 );
		$json = $this->save(
			array(
				'aafm_rate_limit_per_min' => '99',
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'a@b.co, c@d.co',
				'aafm_notify_new_app'     => '1',
			)
		);
		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( 'does not look valid', (string) $json['data']['message'] );
		$this->assertSame( 3, (int) get_option( 'aafm_rate_limit_per_min' ), 'A rejected notification setting must not let the rest of the form half-save.' );
		$this->assertSame( 'off', aafm_notifications_settings()['mode'] );
	}

	public function test_a_save_without_the_notification_fields_leaves_them_alone(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		$json = $this->save( array( 'aafm_rate_limit_per_min' => '5' ) );
		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( 'notice', aafm_notifications_settings()['mode'] );
	}

	public function test_changing_the_address_warns_the_old_one(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'old@example.com',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'new@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'old@example.com', $this->sent()[0]['to'] );
		$this->assertStringContainsString( 'The notification address was changed', $this->sent()[0]['subject'] );
		$this->assertStringNotContainsString( 'new@example.com', $this->sent()[0]['message'] );
	}

	public function test_turning_notifications_off_clears_the_schedule(): void {
		$this->save(
			array(
				'aafm_notify_mode'        => 'digest',
				'aafm_notify_email'       => 'owner@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);
		$this->assertNotFalse( wp_next_scheduled( 'aafm_notifications_digest' ) );
		$this->save( array( 'aafm_notify_mode' => 'off' ) );
		$this->assertFalse( wp_next_scheduled( 'aafm_notifications_digest' ) );
	}

	public function test_the_card_renders_with_everything_off_by_default(): void {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="aafm_notify_mode"', $html );
		$this->assertStringContainsString( 'name="aafm_notify_email"', $html );
		foreach ( array( 'new_app', 'risky_run', 'denial_burst', 'switch_flip' ) as $slug ) {
			$this->assertStringContainsString( 'name="aafm_notify_' . $slug . '"', $html );
			$this->assertDoesNotMatchRegularExpression( '/name="aafm_notify_' . $slug . '"[^>]*checked/', $html );
		}
		$this->assertMatchesRegularExpression( '/<option value="off"[^>]*selected/', $html );
		$this->assertStringContainsString( 'id="aafm-notify-test"', $html );
		$this->assertStringContainsString( 'wp-cron.php', $html, 'The WP-Cron note is on the card.' );
	}

	public function test_the_card_escapes_a_stored_address(): void {
		$this->acting_as( 'administrator' );
		update_option(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => '"><script>x</script>@example.com',
				'triggers' => array(),
			)
		);
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>x</script>', $html );
	}

	public function test_the_test_button_sends_to_the_saved_address_only_and_is_throttled(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'owner@example.com',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		$this->post_as( 'administrator', array( 'aafm_notify_email' => 'attacker@example.com' ) );
		$first = $this->run_handler( 'aafm_ajax_notify_test' );
		$this->assertTrue( $first['success'] ?? false );
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'owner@example.com', $this->sent()[0]['to'] );

		$this->post_as( 'administrator' );
		$second = $this->run_handler( 'aafm_ajax_notify_test' );
		$this->assertFalse( $second['success'] ?? true );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_the_test_button_needs_an_address_and_manage_options(): void {
		$this->post_as( 'administrator' );
		$this->assertFalse( $this->run_handler( 'aafm_ajax_notify_test' )['success'] ?? true );
		$this->post_as( 'editor' );
		$this->assertFalse( $this->run_handler( 'aafm_ajax_notify_test' )['success'] ?? true );
		$this->assertSame( array(), $this->sent() );
	}

	private function render_notice(): string {
		set_current_screen( 'dashboard' );
		ob_start();
		aafm_notify_admin_notice();
		return (string) ob_get_clean();
	}

	public function test_the_admin_notice_shows_only_to_admins_and_only_when_on(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		aafm_notify_event( 'switch_flip', 'read_only_off', array( 'row_id' => 12 ) );

		$this->acting_as( 'editor' );
		$this->assertSame( '', $this->render_notice() );

		$this->acting_as( 'administrator' );
		$html = $this->render_notice();
		$this->assertStringContainsString( 'Read-only mode was turned off', $html );
		$this->assertStringContainsString( 'tab=activity&#038;row=12', $html );
		$this->assertStringContainsString( 'aafm_notify_dismiss', $html );

		update_option(
			'aafm_notifications',
			array(
				'mode'     => 'off',
				'email'    => '',
				'triggers' => array(),
			)
		);
		$this->assertSame( '', $this->render_notice() );
	}

	public function test_the_admin_notice_says_how_many_more_alerts_wait_in_the_log(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		for ( $i = 1; $i <= 5; $i++ ) {
			aafm_notify_event( 'switch_flip', 'bucket' . $i, array( 'row_id' => 20 + $i ) );
		}
		$this->acting_as( 'administrator' );

		$html = $this->render_notice();

		$this->assertSame( 3, substr_count( $html, 'View in Activity Log' ), 'Three entries are listed.' );
		$this->assertStringContainsString( 'and 2 more', $html, 'Dismiss clears all five, so the other two are counted, not hidden.' );
		$this->assertStringContainsString( 'tab=activity"', $html );

		aafm_notify_event( 'switch_flip', 'bucket6', array( 'row_id' => 30 ) );
		$this->assertStringContainsString( 'and 3 more', $this->render_notice() );
	}

	public function test_the_admin_notice_says_how_many_times_a_folded_alert_happened(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'new_app' => true ),
			),
			true
		);
		for ( $i = 1; $i <= 4; $i++ ) {
			aafm_notify_event( 'new_app', 'app_password', array( 'user_id' => 3 ) );
		}
		$this->acting_as( 'administrator' );

		$html = $this->render_notice();

		$this->assertSame( 1, substr_count( $html, 'View in Activity Log' ) );
		$this->assertStringContainsString( 'An Application Password was created (4 times)', $html );
	}

	public function test_the_admin_notice_has_no_more_line_for_three_or_fewer_alerts(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		for ( $i = 1; $i <= 3; $i++ ) {
			aafm_notify_event( 'switch_flip', 'bucket' . $i, array( 'row_id' => 20 + $i ) );
		}
		$this->acting_as( 'administrator' );

		$this->assertStringNotContainsString( ' more', $this->render_notice() );
	}

	public function test_the_dismiss_link_needs_a_nonce_and_clears_the_list(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		aafm_notify_event( 'switch_flip', 'read_only_off', array( 'row_id' => 12 ) );
		$this->acting_as( 'administrator' );

		$this->assertSame( '', $this->run_dismiss( false ) );
		$this->assertNotEmpty( aafm_notify_pending_list(), 'A bad nonce clears nothing.' );

		$this->assertNotSame( '', $this->run_dismiss( true ) );
		$this->assertSame( array(), aafm_notify_pending_list() );
	}

	public function test_a_save_that_flips_read_only_and_turns_alerts_off_still_alerts_the_owner(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'owner@example.com',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);

		$json = $this->save(
			array(
				'aafm_read_only_mode' => '1',
				'aafm_notify_mode'    => 'off',
			)
		);

		$this->assertTrue( $json['success'] ?? false, wp_json_encode( $json ) );
		$this->assertSame( 'off', aafm_notifications_settings()['mode'] );
		$subjects = implode( ' | ', array_column( $this->sent(), 'subject' ) );
		$this->assertCount( 2, $this->sent(), $subjects );
		$this->assertStringContainsString( 'Read-only mode was turned on', $subjects, 'The flip alert uses the settings stored BEFORE the save.' );
		$this->assertStringContainsString( 'Email alerts were turned off', $subjects );
		$this->assertSame( array( 'owner@example.com', 'owner@example.com' ), array_column( $this->sent(), 'to' ) );
	}

	public function test_turning_alerts_off_warns_the_old_address(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'digest',
				'email'    => 'old@example.com',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);

		$this->save(
			array(
				'aafm_notify_mode'  => 'off',
				'aafm_notify_email' => 'old@example.com',
			)
		);

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'old@example.com', $this->sent()[0]['to'] );
		$this->assertStringContainsString( 'Email alerts were turned off', $this->sent()[0]['subject'] );
	}

	public function test_unticking_the_switch_alert_warns_the_old_address(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'owner@example.com',
				'triggers' => array(
					'switch_flip' => true,
					'risky_run'   => true,
				),
			),
			true
		);

		$this->save(
			array(
				'aafm_notify_mode'      => 'immediate',
				'aafm_notify_email'     => 'owner@example.com',
				'aafm_notify_risky_run' => '1',
			)
		);

		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'switch changes were turned off', $this->sent()[0]['subject'] );
	}

	public function test_unticking_any_trigger_warns_the_old_address_and_names_it_in_the_log(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'owner@example.com',
				'triggers' => array(
					'new_app'      => true,
					'risky_run'    => true,
					'denial_burst' => true,
					'switch_flip'  => true,
				),
			),
			true
		);

		$this->save(
			array(
				'aafm_notify_mode'         => 'immediate',
				'aafm_notify_email'        => 'owner@example.com',
				'aafm_notify_switch_flip'  => '1',
				'aafm_notify_denial_burst' => '1',
			)
		);

		$subjects = implode( ' | ', array_column( $this->sent(), 'subject' ) );
		$this->assertCount( 2, $this->sent(), $subjects );
		$this->assertStringContainsString( 'Alerts for new apps', $subjects );
		$this->assertStringContainsString( 'Alerts for high-risk', $subjects );
		foreach ( $this->sent() as $mail ) {
			$this->assertSame( 'owner@example.com', $mail['to'] );
		}
		$details = implode( ' | ', array_column( aafm_query_activity( array( 'ability' => 'aafm/notifications' ) ), 'detail' ) );
		$this->assertStringContainsString( 'Notifications: alerts for new apps', $details );
		$this->assertStringContainsString( 'turned off', $details );
		$this->assertStringNotContainsString( 'owner@example.com', $details );
	}

	public function test_ticking_a_trigger_on_sends_no_warning_and_no_off_row(): void {
		$this->store( 'immediate' );

		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'owner@example.com',
				'aafm_notify_switch_flip' => '1',
				'aafm_notify_risky_run'   => '1',
				'aafm_notify_new_app'     => '1',
			)
		);

		$this->assertSame( array(), $this->sent() );
		$details = implode( ' | ', array_column( aafm_query_activity( array( 'ability' => 'aafm/notifications' ) ), 'detail' ) );
		$this->assertStringNotContainsString( 'turned off', $details );
	}

	public function test_a_save_that_changes_nothing_important_sends_nothing(): void {
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'owner@example.com',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);

		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'owner@example.com',
				'aafm_notify_switch_flip' => '1',
				'aafm_notify_risky_run'   => '1',
			)
		);

		$this->assertSame( array(), $this->sent() );
	}

	/**
	 * Store notification settings the way an earlier save left them.
	 *
	 * @param string $mode  Stored mode.
	 * @param string $email Stored address.
	 */
	private function store( string $mode, string $email = 'owner@example.com' ): void {
		$this->assertTrue(
			aafm_update_option_verified(
				'aafm_notifications',
				array(
					'mode'     => $mode,
					'email'    => $email,
					'triggers' => array(
						'switch_flip' => true,
						'risky_run'   => true,
					),
				),
				true
			)
		);
	}

	public function test_a_digest_save_that_flips_read_only_and_turns_alerts_off_still_alerts_the_owner(): void {
		$this->store( 'digest' );

		$json = $this->save(
			array(
				'aafm_read_only_mode' => '1',
				'aafm_notify_mode'    => 'off',
			)
		);

		$this->assertTrue( $json['success'] ?? false, wp_json_encode( $json ) );
		$this->assertCount( 2, $this->sent(), implode( ' | ', array_column( $this->sent(), 'subject' ) ) );
		$this->assertSame( array( 'owner@example.com', 'owner@example.com' ), array_column( $this->sent(), 'to' ) );
		$this->assertStringContainsString( 'daily summary', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( 'Read-only mode was turned on', $this->sent()[0]['message'], 'The flip reaches the OLD address.' );
		$this->assertSame( array(), aafm_notify_queue_list(), 'Nothing stays queued behind a mode that is no longer digest.' );
	}

	public function test_changing_the_address_in_digest_mode_sends_the_queue_to_the_old_address_only(): void {
		$this->store( 'digest', 'old@example.com' );
		aafm_notify_event( 'risky_run', 'high_risk', array( 'ability' => 'aafm/delete-user' ) );
		$this->assertCount( 1, aafm_notify_queue_list(), 'precondition' );

		$this->save(
			array(
				'aafm_notify_mode'      => 'digest',
				'aafm_notify_email'     => 'new@example.com',
				'aafm_notify_risky_run' => '1',
			)
		);

		$this->assertSame( array( 'old@example.com', 'old@example.com' ), array_column( $this->sent(), 'to' ) );
		$this->assertStringContainsString( 'daily summary', $this->sent()[0]['subject'] );
		$this->assertSame( array(), aafm_notify_queue_list() );
	}

	public function test_leaving_digest_clears_the_queue_once_the_old_address_was_mailed(): void {
		$this->store( 'digest', 'old@example.com' );
		aafm_notify_event( 'risky_run', 'high_risk', array( 'ability' => 'aafm/delete-user' ) );

		$this->save(
			array(
				'aafm_notify_mode'      => 'notice',
				'aafm_notify_risky_run' => '1',
			)
		);

		$this->assertCount( 1, aafm_notify_queue_list(), 'The queue stays until the mail has actually gone out.' );
		$this->assertStringContainsString( 'daily summary', $this->sent()[0]['subject'] );
		$this->assertSame( array(), aafm_notify_queue_list(), 'A stale queue must not mail weeks later.' );
	}

	public function test_leaving_digest_keeps_the_queue_when_the_mailer_fails(): void {
		$this->store( 'digest', 'old@example.com' );
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		$this->mail_ok = false;

		$this->save(
			array(
				'aafm_notify_mode'        => 'notice',
				'aafm_notify_switch_flip' => '1',
			)
		);
		$this->sent();

		$this->assertCount( 1, aafm_notify_queue_list(), 'A flip that was never delivered is not thrown away.' );
	}

	public function test_leaving_digest_keeps_the_queue_and_logs_a_row_when_the_hourly_cap_is_full(): void {
		$this->store( 'digest', 'old@example.com' );
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		set_transient( 'aafm_ntf_global_governance', AAFM_NOTIFY_GOVERNANCE_MAX, HOUR_IN_SECONDS );

		$this->save(
			array(
				'aafm_notify_mode'        => 'notice',
				'aafm_notify_switch_flip' => '1',
			)
		);

		$this->assertSame( array(), $this->sent() );
		$this->assertCount( 1, aafm_notify_queue_list(), 'The cap rejected the send, so nothing may be dropped.' );
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/notifications',
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows );
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, count( $rows ) );
	}

	public function test_a_stale_queue_drops_chatter_but_keeps_an_undelivered_flip(): void {
		$this->store( 'notice', '' );
		aafm_update_option_verified(
			'aafm_notify_queue',
			array(
				array(
					'id'      => 'a',
					'trigger' => 'risky_run',
					'subtype' => 'high_risk',
				),
				array(
					'id'      => 'b',
					'trigger' => 'switch_flip',
					'subtype' => 'read_only_off',
				),
			),
			false
		);

		$this->save( array( 'aafm_notify_mode' => 'off' ) );

		$left = aafm_notify_queue_list();
		$this->assertCount( 1, $left );
		$this->assertSame( 'b', $left[0]['id'] );
	}

	public function test_a_digest_save_that_changes_nothing_important_keeps_the_queue(): void {
		$this->store( 'digest' );
		aafm_notify_event( 'risky_run', 'high_risk', array( 'ability' => 'aafm/delete-user' ) );

		$this->save(
			array(
				'aafm_notify_mode'        => 'digest',
				'aafm_notify_email'       => 'owner@example.com',
				'aafm_notify_switch_flip' => '1',
				'aafm_notify_risky_run'   => '1',
			)
		);

		$this->assertSame( array(), $this->sent() );
		$this->assertCount( 1, aafm_notify_queue_list() );
	}

	public function test_the_settings_save_queues_its_mail_for_the_end_of_the_request(): void {
		$this->store( 'immediate', 'old@example.com' );

		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'new@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);

		$this->assertSame( array(), $this->mail, 'The save does not wait on the mail host.' );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_the_old_address_warning_respects_the_shared_hourly_cap(): void {
		$this->store( 'immediate', 'old@example.com' );
		set_transient( 'aafm_ntf_global_governance', AAFM_NOTIFY_GOVERNANCE_MAX, HOUR_IN_SECONDS );

		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'new@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);

		$this->assertSame( array(), $this->sent(), 'A warning goes through the same cap as every other message.' );
	}

	public function test_a_failed_old_address_warning_writes_a_bounded_error_row(): void {
		$this->store( 'immediate', 'old@example.com' );
		$this->mail_ok = false;

		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'new@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);

		aafm_notify_flush_outbox();
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/notifications',
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows, 'A mail failure leaves a row.' );
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, count( $rows ) );
	}

	public function test_the_recipient_change_throttle_is_per_old_address(): void {
		$this->store( 'immediate', 'a@example.com' );
		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'b@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);
		$this->save(
			array(
				'aafm_notify_mode'        => 'immediate',
				'aafm_notify_email'       => 'c@example.com',
				'aafm_notify_switch_flip' => '1',
			)
		);

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), array_column( $this->sent(), 'to' ), 'B hears that its address was replaced.' );
	}

	/**
	 * Put the site in the state a reset weakens: alerts mailed to an address, read-only mode on, the
	 * high-risk category unlocked.
	 *
	 * @param string $mode Stored notification mode.
	 */
	private function arm_for_reset( string $mode ): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		$this->acting_as( 'administrator' );
		$this->assertTrue(
			aafm_update_option_verified(
				'aafm_notifications',
				array(
					'mode'     => $mode,
					'email'    => 'old@example.com',
					'triggers' => array( 'switch_flip' => true ),
				),
				true
			)
		);
		$this->assertTrue( aafm_persist_operator_switch( 'aafm_read_only_mode', true ) );
		$this->assertTrue( aafm_persist_operator_switch( 'aafm_high_risk_abilities_unlocked', true ) );
	}

	public function test_a_reset_warns_the_old_address_about_the_reset_and_each_switch_it_changed(): void {
		$this->arm_for_reset( 'immediate' );

		$this->assertTrue( aafm_reset_plugin() );

		$mail     = $this->sent();
		$subjects = implode( ' | ', array_column( $mail, 'subject' ) );
		$this->assertCount( 3, $mail, $subjects );
		$this->assertStringContainsString( 'plugin settings were reset', $subjects );
		$this->assertStringContainsString( 'Read-only mode was turned off', $subjects );
		$this->assertStringContainsString( 'The high-risk lock was closed', $subjects );
		foreach ( $mail as $one ) {
			$this->assertSame( 'old@example.com', $one['to'] );
		}
	}

	public function test_a_reset_hands_the_queued_digest_to_the_old_address(): void {
		$this->arm_for_reset( 'digest' );
		aafm_update_option_verified(
			'aafm_notify_queue',
			array(
				array(
					'trigger' => 'switch_flip',
					'subtype' => 'read_only_on',
					'time'    => 1,
				),
			),
			false
		);

		$this->assertTrue( aafm_reset_plugin() );

		$subjects = implode( ' | ', array_column( $this->sent(), 'subject' ) );
		$this->assertStringContainsString( 'daily summary', $subjects );
		foreach ( $this->sent() as $one ) {
			$this->assertSame( 'old@example.com', $one['to'] );
		}
	}

	public function test_a_reset_records_the_switch_changes_in_a_log_that_survives_the_clear(): void {
		$this->arm_for_reset( 'notice' );

		$this->assertTrue( aafm_reset_plugin() );

		$details = array_column( aafm_query_activity( array() ), 'detail' );
		$this->assertContains( 'Activity log cleared', $details );
		$this->assertContains( 'Read-only mode turned off', $details );
		$this->assertContains( 'High-risk abilities locked', $details );
		$this->assertSame( array(), $this->sent(), 'A notice-only setup had no address to warn.' );
	}

	public function test_a_reset_warns_nobody_when_alerts_were_never_emailed(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		$this->acting_as( 'administrator' );

		$this->assertTrue( aafm_reset_plugin() );

		$this->assertSame( array(), $this->sent() );
	}

	public function test_reset_removes_the_notification_options_and_the_tabs_still_render(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'digest',
				'email'    => 'owner@example.com',
				'triggers' => array( 'switch_flip' => true ),
			),
			true
		);
		update_option(
			'aafm_notify_queue',
			array(
				array(
					'trigger' => 'switch_flip',
					'subtype' => 'read_only_on',
					'time'    => 1,
				),
			)
		);
		update_option(
			'aafm_notify_pending',
			array(
				array(
					'trigger' => 'switch_flip',
					'subtype' => 'read_only_on',
					'time'    => 1,
				),
			)
		);
		aafm_notify_sync_schedule();
		$this->assertNotFalse( wp_next_scheduled( 'aafm_notifications_digest' ), 'precondition' );

		$this->assertTrue( aafm_reset_plugin() );

		foreach ( array( 'aafm_notifications', 'aafm_notify_queue', 'aafm_notify_pending' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), $option );
		}
		aafm_notify_sync_schedule();
		$this->assertFalse( wp_next_scheduled( 'aafm_notifications_digest' ), 'The next admin_init clears the event once the mode is off.' );

		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_settings_tab();
		$settings = (string) ob_get_clean();
		ob_start();
		aafm_render_activity_tab();
		$activity = (string) ob_get_clean();
		ob_start();
		aafm_notify_admin_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="aafm_notify_mode"', $settings );
		$this->assertStringContainsString( 'aafm-log-table', $activity );
		$this->assertSame( '', $notice );
	}

	/**
	 * Run the dismiss handler with or without a valid nonce.
	 *
	 * @param bool $with_nonce Whether to send a valid nonce.
	 * @return string The redirect target, or '' when the handler died.
	 */
	private function run_dismiss( bool $with_nonce ): string {
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
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- test fixture building the request its handler reads.
		$_REQUEST['_wpnonce'] = $with_nonce ? wp_create_nonce( 'aafm_notify_dismiss' ) : 'bad';
		$_GET['_wpnonce']     = $_REQUEST['_wpnonce'];
		// phpcs:enable
		ob_start();
		try {
			aafm_handle_notify_dismiss();
		} catch ( \WPDieException $e ) {
			unset( $e );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			ob_end_clean();
		}
		remove_filter( 'wp_redirect', $catch, 1 );
		remove_all_filters( 'wp_die_handler' );
		unset( $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );
		return $captured;
	}
}
