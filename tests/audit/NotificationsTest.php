<?php
/**
 * The notification engine: settings shapes, validation, throttling, delivery, the digest queue and
 * the admin-notice list. Detection points are in NotificationDetectionTest.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class NotificationsTest extends TestCase {

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
	 * Store a notification configuration.
	 *
	 * @param array<string,mixed> $over Overrides.
	 */
	private function configure( array $over = array() ): void {
		$base = array(
			'mode'     => 'immediate',
			'email'    => 'owner@example.com',
			'triggers' => array(
				'new_app'      => true,
				'risky_run'    => true,
				'denial_burst' => true,
				'switch_flip'  => true,
			),
		);
		$this->assertTrue( aafm_update_option_verified( 'aafm_notifications', array_merge( $base, $over ), true ) );
	}

	public function test_default_is_off_and_nothing_happens(): void {
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		$this->assertSame( array(), $this->sent() );
		$this->assertSame( array(), aafm_notify_pending_list() );
		$this->assertSame( array(), aafm_notify_queue_list() );
		$this->assertSame( 'off', aafm_notifications_settings()['mode'] );
	}

	public function test_normalize_survives_garbage(): void {
		foreach ( array(
			'x',
			5,
			null,
			array(
				'mode'     => 'bogus',
				'email'    => 'nope',
				'triggers' => 'yes',
			),
			array(
				'triggers' => array(
					'new_app' => '1',
					'nope'    => true,
				),
			),
		) as $stored ) {
			$n = aafm_notifications_normalize( $stored );
			$this->assertContains( $n['mode'], aafm_notify_modes() );
			$this->assertSame( array( 'new_app', 'risky_run', 'denial_burst', 'switch_flip' ), array_keys( $n['triggers'] ) );
			$this->assertSame( '', $n['email'] );
		}
		$this->assertTrue( aafm_notifications_normalize( array( 'triggers' => array( 'new_app' => '1' ) ) )['triggers']['new_app'] );
	}

	public function test_a_stored_address_is_accepted_only_as_typed_never_repaired(): void {
		$this->assertSame( 'owner@example.com', aafm_notifications_normalize( array( 'email' => ' owner@example.com ' ) )['email'] );
		foreach ( array( 'a@b.co, c@d.co', 'Name <a@b.co>', 'a@b.co c@d.co', "a@b.co\r\nBcc: x@y.z", 'not-an-email' ) as $raw ) {
			$this->assertSame( '', aafm_notifications_normalize( array( 'email' => $raw ) )['email'], $raw );
			$clean = aafm_notifications_sanitize(
				array(
					'aafm_notify_mode'  => 'immediate',
					'aafm_notify_email' => $raw,
				)
			);
			$this->assertSame( '', $clean['email'], 'sanitize_email() would rewrite this into a different valid recipient: ' . $raw );
		}
	}

	public function test_sanitize_and_validate(): void {
		$ok = aafm_notifications_sanitize(
			array(
				'aafm_notify_mode'      => 'immediate',
				'aafm_notify_email'     => ' owner@example.com ',
				'aafm_notify_risky_run' => '1',
			)
		);
		$this->assertSame( 'immediate', $ok['mode'] );
		$this->assertSame( 'owner@example.com', $ok['email'] );
		$this->assertTrue( $ok['triggers']['risky_run'] );
		$this->assertFalse( $ok['triggers']['new_app'] );
		$this->assertNull( aafm_notifications_validate( $ok, ' owner@example.com ' ) );

		$no_mail = aafm_notifications_sanitize(
			array(
				'aafm_notify_mode'      => 'immediate',
				'aafm_notify_risky_run' => '1',
			)
		);
		$this->assertStringContainsString( 'valid email address', (string) aafm_notifications_validate( $no_mail, '' ) );

		foreach ( array( 'a@b.co, c@d.co', 'Name <a@b.co>', 'a@b.co c@d.co', "a@b.co\r\nBcc: x@y.z", 'not-an-email' ) as $raw ) {
			$bad = aafm_notifications_sanitize(
				array(
					'aafm_notify_mode'      => 'digest',
					'aafm_notify_email'     => $raw,
					'aafm_notify_risky_run' => '1',
				)
			);
			$this->assertNotNull( aafm_notifications_validate( $bad, $raw ), $raw );
		}

		$notice_only = aafm_notifications_sanitize(
			array(
				'aafm_notify_mode'        => 'notice',
				'aafm_notify_switch_flip' => '1',
			)
		);
		$this->assertNull( aafm_notifications_validate( $notice_only, '' ), 'Admin notice only needs no address.' );

		$no_triggers = aafm_notifications_sanitize( array( 'aafm_notify_mode' => 'notice' ) );
		$this->assertStringContainsString( 'at least one', (string) aafm_notifications_validate( $no_triggers, '' ) );

		$off = aafm_notifications_sanitize( array( 'aafm_notify_mode' => 'off' ) );
		$this->assertNull( aafm_notifications_validate( $off, '' ) );

		$this->assertSame( 'off', aafm_notifications_sanitize( array( 'aafm_notify_mode' => 'bogus' ) )['mode'] );
	}

	public function test_immediate_mode_sends_one_message_with_identifiers_only(): void {
		$this->configure();
		aafm_notify_event(
			'risky_run',
			'high_risk',
			array(
				'row_id'  => 77,
				'ability' => 'aafm/wc-create-order-refund',
			)
		);

		$this->assertCount( 1, $this->sent() );
		$mail = $this->sent()[0];
		$this->assertSame( 'owner@example.com', $mail['to'] );
		$this->assertStringContainsString( 'Agent Abilities alert: A high-risk tool ran', $mail['subject'] );
		$this->assertStringContainsString( 'aafm/wc-create-order-refund', $mail['message'] );
		$this->assertStringContainsString( 'tab=activity&row=77', $mail['message'] );
		$this->assertEmpty( $mail['headers'], 'No custom headers are ever set.' );
		$this->assertStringNotContainsString( '<', $mail['message'] );
		$this->assertCount( 1, aafm_notify_pending_list(), 'The admin notice entry is always written.' );
	}

	public function test_a_second_event_in_the_same_bucket_is_throttled_and_counted(): void {
		$this->configure();
		aafm_notify_event( 'denial_burst', 'burst', array( 'count' => 10 ) );
		aafm_notify_event( 'denial_burst', 'burst', array( 'count' => 10 ) );
		aafm_notify_event( 'denial_burst', 'burst', array( 'count' => 10 ) );
		$this->assertCount( 1, $this->sent() );
		$this->assertCount( 3, aafm_notify_pending_list(), 'A throttled event still reaches the admin notice; only the mail is throttled.' );

		// The window passes: the next message reports what it swallowed.
		$slot = aafm_notify_take_slot( 'denial_burst:burst' );
		$this->assertFalse( $slot['allowed'] );
		delete_transient( 'aafm_ntf_' . substr( md5( 'denial_burst:burst' ), 0, 20 ) );
		aafm_notify_event( 'denial_burst', 'burst', array( 'count' => 10 ) );
		$this->assertCount( 2, $this->sent() );
		$this->assertStringContainsString( 'plus 3 more', $this->sent()[1]['message'] );
	}

	public function test_a_flood_of_denial_alerts_cannot_use_up_the_slots_a_critical_alert_needs(): void {
		$this->configure();
		for ( $i = 1; $i <= 8; $i++ ) {
			aafm_notify_event( 'denial_burst', 'flood' . $i, array( 'count' => 10 ) );
		}
		aafm_notify_event( 'risky_run', 'high_risk', array( 'ability' => 'aafm/delete-user' ) );
		aafm_notify_event( 'new_app', 'app_password', array( 'user_id' => 3 ) );
		aafm_notify_event( 'switch_flip', 'read_only_off' );

		$subjects = implode( ' | ', array_column( $this->sent(), 'subject' ) );
		$this->assertSame( AAFM_NOTIFY_DENIAL_MAX, substr_count( $subjects, 'Something needs a look' ), $subjects ); // The flood subtypes have no label of their own.
		$this->assertStringContainsString( 'A high-risk tool ran', $subjects );
		$this->assertStringContainsString( 'An Application Password was created', $subjects );
		$this->assertStringContainsString( 'Read-only mode was turned off', $subjects );
		$list = aafm_notify_pending_list();
		$this->assertCount( 6, $list, 'The three critical events and the three newest denials reach the notice list.' );
		$this->assertCount( 3, wp_list_filter( $list, array( 'trigger' => 'denial_burst' ) ) );
	}

	/**
	 * A run of app approvals (anyone who can register a client, or one Application Password every few
	 * minutes) used to drain the one shared pool, so a later switch flip sent nothing.
	 */
	public function test_app_approvals_cannot_use_up_the_pool_a_switch_flip_needs(): void {
		$this->configure();
		for ( $i = 1; $i <= 8; $i++ ) {
			aafm_notify_event( 'new_app', 'oauth_grant', array( 'client_id' => 'dcr-app-' . $i ) );
		}
		aafm_notify_event( 'switch_flip', 'read_only_off' );
		aafm_notify_event( 'risky_run', 'high_risk', array( 'ability' => 'aafm/delete-user' ) );

		$subjects = implode( ' | ', array_column( $this->sent(), 'subject' ) );
		$this->assertSame( 2, substr_count( $subjects, 'An app was authorized to connect' ), $subjects );
		$this->assertStringContainsString( 'Read-only mode was turned off', $subjects );
		$this->assertStringContainsString( 'A high-risk tool ran', $subjects );
	}

	public function test_a_mail_refused_for_lack_of_pool_room_is_counted_as_held_back(): void {
		$this->configure();
		set_transient( 'aafm_ntf_global_critical', 99, HOUR_IN_SECONDS );

		aafm_notify_event( 'switch_flip', 'read_only_on' );
		$this->assertSame( array(), $this->sent(), 'The pool is full, so no mail.' );

		// The next window opens and the pool has room: the message says what it missed.
		delete_transient( 'aafm_ntf_global_critical' );
		delete_transient( 'aafm_ntf_' . substr( md5( 'switch_flip:read_only_on' ), 0, 20 ) );
		aafm_notify_event( 'switch_flip', 'read_only_on' );
		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'plus 1 more', $sent[0]['message'] );
	}

	public function test_mail_is_queued_for_the_end_of_the_request_not_sent_inside_the_call(): void {
		$this->configure();
		aafm_notify_event( 'switch_flip', 'read_only_on' );

		$this->assertSame( array(), $this->mail, 'Nothing is mailed while the call is still running.' );
		$this->assertNotFalse( has_action( 'shutdown', 'aafm_notify_flush_outbox' ) );
		$this->assertCount( 1, aafm_notify_pending_list(), 'The notice entry is written inline.' );
		$this->assertCount( 1, $this->sent(), 'The flush on shutdown sends it.' );
		$this->assertCount( 1, $this->sent(), 'A flush sends each message once.' );
	}

	public function test_oauth_grants_are_throttled_per_app(): void {
		$this->configure();
		aafm_notify_event( 'new_app', 'oauth_grant', array( 'client_id' => 'app-one' ) );
		aafm_notify_event( 'new_app', 'oauth_grant', array( 'client_id' => 'app-one' ) );
		aafm_notify_event( 'new_app', 'oauth_grant', array( 'client_id' => 'app-two' ) );

		$this->assertCount( 2, $this->sent(), 'A second app approved inside the window still alerts.' );
		$this->assertStringContainsString( 'app-two', $this->sent()[1]['message'] );
	}

	/**
	 * Review Focus 5: an attacker's "off" must not be swallowed by the owner's earlier "on".
	 */
	public function test_the_flip_buckets_are_per_direction(): void {
		$this->configure();
		aafm_notify_event( 'switch_flip', 'read_only_on' );
		aafm_notify_event( 'switch_flip', 'read_only_off' );
		aafm_notify_event( 'switch_flip', 'read_only_on' );
		$this->assertCount( 2, $this->sent() );
		$this->assertStringContainsString( 'turned on', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( 'turned off', $this->sent()[1]['subject'] );
	}

	/**
	 * Review Focus 5: the hourly cap holds however many buckets are hot.
	 */
	public function test_the_global_hourly_cap_holds(): void {
		$this->configure();
		for ( $i = 1; $i <= 9; $i++ ) {
			aafm_notify_event( 'risky_run', 'bucket' . $i, array( 'row_id' => $i ) );
		}
		$this->assertCount( AAFM_NOTIFY_CRITICAL_MAX, $this->sent() );
		$this->assertCount( 9, aafm_notify_pending_list(), 'Events past the mail cap still reach the admin notice list.' );
	}

	public function test_notice_mode_sends_no_mail_but_keeps_the_notice(): void {
		$this->configure(
			array(
				'mode'  => 'notice',
				'email' => '',
			)
		);
		aafm_notify_event( 'switch_flip', 'high_risk_unlocked', array( 'row_id' => 5 ) );
		$this->assertSame( array(), $this->sent() );
		$this->assertCount( 1, aafm_notify_pending_list() );
		$this->assertSame( array(), aafm_notify_queue_list() );
	}

	public function test_a_trigger_that_is_switched_off_does_nothing(): void {
		$this->configure(
			array(
				'triggers' => array(
					'new_app'      => false,
					'risky_run'    => false,
					'denial_burst' => false,
					'switch_flip'  => true,
				),
			)
		);
		aafm_notify_event( 'risky_run', 'high_risk' );
		$this->assertSame( array(), $this->sent() );
		$this->assertSame( array(), aafm_notify_pending_list() );
	}

	public function test_digest_mode_queues_and_the_cron_sends_once_and_clears(): void {
		$this->configure( array( 'mode' => 'digest' ) );
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		aafm_notify_event(
			'risky_run',
			'permanent_delete',
			array(
				'row_id'  => 2,
				'ability' => 'aafm/delete-post',
			)
		);
		$this->assertSame( array(), $this->sent(), 'Digest mode sends nothing at event time.' );
		$this->assertCount( 2, aafm_notify_queue_list() );

		aafm_notify_digest_run();
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'daily summary', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( 'tab=activity&row=1', $this->sent()[0]['message'] );
		$this->assertSame( array(), aafm_notify_queue_list(), 'A delivered digest clears the queue.' );

		aafm_notify_digest_run();
		$this->assertCount( 1, $this->sent(), 'An empty queue sends nothing.' );
	}

	public function test_a_failed_digest_keeps_the_queue_for_the_next_run(): void {
		$this->configure( array( 'mode' => 'digest' ) );
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		$this->mail_ok = false;
		aafm_notify_digest_run();
		$this->assertCount( 1, aafm_notify_queue_list() );
		$this->mail_ok = true;
		aafm_notify_digest_run();
		$this->assertSame( array(), aafm_notify_queue_list() );
	}

	public function test_the_digest_cron_keeps_an_event_appended_while_the_mail_was_in_flight(): void {
		$this->configure( array( 'mode' => 'digest' ) );
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		$late = array(
			'id'      => 'late-event',
			'trigger' => 'risky_run',
			'subtype' => 'high_risk',
			'time'    => time(),
		);
		add_filter(
			'pre_wp_mail',
			static function () use ( $late ) {
				aafm_notify_queue_push( $late );
				return null;
			},
			5
		);

		aafm_notify_digest_run();

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( array( $late ), aafm_notify_queue_list(), 'Only the sent batch is cleared.' );
	}

	public function test_a_pending_write_that_does_not_certify_leaves_a_bounded_error_row(): void {
		$this->configure(
			array(
				'mode'  => 'notice',
				'email' => '',
			)
		);
		add_filter(
			'pre_update_option_aafm_notify_pending',
			static function ( $value, $old ) {
				unset( $value );
				return $old;
			},
			10,
			2
		);

		aafm_notify_event( 'switch_flip', 'read_only_on' );

		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/notifications',
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows, 'A notice entry that did not land is reported, not swallowed.' );
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, count( $rows ) );
	}

	public function test_a_queue_write_that_does_not_certify_leaves_an_error_row(): void {
		$this->configure( array( 'mode' => 'digest' ) );
		add_filter(
			'pre_update_option_aafm_notify_queue',
			static function ( $value, $old ) {
				unset( $value );
				return $old;
			},
			10,
			2
		);

		aafm_notify_event( 'switch_flip', 'read_only_on' );

		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/notifications',
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows );
	}

	/**
	 * Review Focus 4: mail broken. The notice still appears, the call returns, and the error rows are
	 * bounded.
	 */
	public function test_a_failing_mailer_keeps_the_notice_and_logs_bounded_error_rows(): void {
		$this->configure();
		$this->mail_ok = false;
		for ( $i = 1; $i <= 8; $i++ ) {
			aafm_notify_event( 'risky_run', 'bucket' . $i );
		}
		aafm_notify_flush_outbox();
		$this->assertCount( 8, aafm_notify_pending_list() );
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/notifications',
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows );
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, count( $rows ), 'Mail-failure rows go through aafm_denial_log_within_cap().' );
		$this->assertSame( 'setting_changed', $rows[0]['event_type'] );
	}

	public function test_an_exception_inside_the_mailer_never_escapes(): void {
		$this->configure();
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'mailer exploded' );
			},
			10,
			2
		);
		aafm_notify_event( 'switch_flip', 'read_only_on' );
		$this->assertCount( 1, aafm_notify_pending_list(), 'The call returned and the notice was written first.' );
		aafm_notify_flush_outbox(); // The failing mailer runs at the end of the request and must not escape there either.
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/notifications',
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows, 'A mailer that throws leaves an error row.' );
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, count( $rows ) );
	}

	public function test_a_throwing_throttle_cannot_stop_the_notice_being_written(): void {
		$this->configure();
		$bucket = 'switch_flip:read_only_on';
		add_filter(
			'pre_transient_aafm_ntf_' . substr( md5( $bucket ), 0, 20 ),
			static function () {
				throw new \RuntimeException( 'transient exploded' );
			}
		);

		aafm_notify_event( 'switch_flip', 'read_only_on' );

		$this->assertCount( 1, aafm_notify_pending_list(), 'The notice is written before the throttle is asked.' );
		$this->assertSame( array(), $this->sent(), 'A broken throttle means no mail.' );
	}

	public function test_a_pending_write_that_fails_does_not_use_up_a_mail_slot(): void {
		$this->configure();
		add_filter(
			'pre_update_option_aafm_notify_pending',
			static function ( $value, $old ) {
				unset( $value );
				return $old;
			},
			10,
			2
		);

		aafm_notify_event( 'switch_flip', 'read_only_on' );

		$this->assertFalse( get_transient( 'aafm_ntf_' . substr( md5( 'switch_flip:read_only_on' ), 0, 20 ) ), 'The bucket slot is untouched.' );
		$this->assertFalse( get_transient( 'aafm_ntf_global_critical' ), 'The hourly pool is untouched.' );
	}

	public function test_a_denial_flood_never_evicts_a_critical_notice(): void {
		$this->configure(
			array(
				'mode'  => 'notice',
				'email' => '',
			)
		);
		aafm_notify_event( 'switch_flip', 'read_only_on', array( 'row_id' => 1 ) );
		aafm_notify_event( 'risky_run', 'high_risk', array( 'row_id' => 2 ) );
		aafm_notify_event( 'new_app', 'oauth_grant', array( 'row_id' => 3 ) );
		for ( $i = 1; $i <= 15; $i++ ) {
			aafm_notify_event( 'denial_burst', 'bucket' . $i, array( 'row_id' => 100 + $i ) );
		}

		$list    = aafm_notify_pending_list();
		$denials = array_values( wp_list_filter( $list, array( 'trigger' => 'denial_burst' ) ) );
		$this->assertCount( 3, $denials, 'At most three denial entries are kept.' );
		$this->assertSame( 115, $denials[0]['row_id'], 'The newest denials win.' );
		$this->assertSame( array( 1, 2, 3 ), array_column( array_reverse( array_values( wp_list_filter( $list, array( 'trigger' => 'denial_burst' ), 'NOT' ) ) ), 'row_id' ) );
	}

	public function test_a_denial_cannot_push_a_critical_notice_out_of_a_full_list(): void {
		$this->configure(
			array(
				'mode'  => 'notice',
				'email' => '',
			)
		);
		for ( $i = 1; $i <= AAFM_NOTIFY_PENDING_MAX; $i++ ) {
			aafm_notify_event( 'risky_run', 'bucket' . $i, array( 'row_id' => $i ) );
		}
		aafm_notify_event( 'denial_burst', 'flood', array( 'row_id' => 99 ) );

		$list = aafm_notify_pending_list();
		$this->assertCount( AAFM_NOTIFY_PENDING_MAX, $list );
		$this->assertSame( array(), wp_list_filter( $list, array( 'trigger' => 'denial_burst' ) ), 'The full list of critical entries is untouched.' );
		$this->assertSame( 1, $list[ AAFM_NOTIFY_PENDING_MAX - 1 ]['row_id'] );
	}

	public function test_the_message_is_sanitized(): void {
		update_option( 'blogname', "Shop\r\nBcc: evil@example.com" );
		$m = aafm_notify_build_message(
			array(
				array(
					'trigger'    => 'new_app',
					'subtype'    => 'oauth_grant',
					'time'       => 1700000000,
					'row_id'     => 4,
					'ability'    => '',
					'client_id'  => 'abc123',
					'host'       => 'app.example',
					'app_name'   => str_repeat( 'N', 80 ),
					'count'      => 0,
					'suppressed' => 0,
				),
			),
			false
		);
		$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $m['subject'] );
		$this->assertStringNotContainsString( 'name the app gave itself: ' . str_repeat( 'N', 41 ), $m['body'] );
		$this->assertStringContainsString( 'name the app gave itself: ' . str_repeat( 'N', 40 ), $m['body'] );
		$this->assertStringContainsString( 'abc123', $m['body'] );
		$this->assertStringContainsString( 'app.example', $m['body'] );
		$this->assertStringContainsString( '2023-11-14 22:13:20 UTC', $m['body'] );
	}

	public function test_event_facts_are_cleaned_before_they_are_stored(): void {
		$this->configure(
			array(
				'mode'  => 'notice',
				'email' => '',
			)
		);
		aafm_notify_event(
			'new_app',
			'oauth_grant',
			array(
				'app_name'  => "<b>Evil</b>\r\nApp (1)",
				'ability'   => "aafm/x\r\n<script>",
				'client_id' => 'ab<c>12',
			)
		);
		$e = aafm_notify_pending_list()[0];
		$this->assertSame( 'Evil App (1)', $e['app_name'] );
		$this->assertSame( 'aafm/xscript', $e['ability'] );
		$this->assertSame( 'abc12', $e['client_id'] );
	}

	public function test_the_pending_list_is_capped_and_newest_first(): void {
		$this->configure(
			array(
				'mode'  => 'notice',
				'email' => '',
			)
		);
		for ( $i = 1; $i <= 14; $i++ ) {
			aafm_notify_event( 'risky_run', 'bucket' . $i, array( 'row_id' => $i ) );
		}
		$list = aafm_notify_pending_list();
		$this->assertCount( AAFM_NOTIFY_PENDING_MAX, $list );
		$this->assertSame( 14, $list[0]['row_id'] );
	}

	public function test_the_queue_is_capped(): void {
		for ( $i = 1; $i <= AAFM_NOTIFY_QUEUE_MAX + 5; $i++ ) {
			aafm_notify_queue_push(
				array(
					'trigger' => 'risky_run',
					'subtype' => 'b',
					'time'    => $i,
					'row_id'  => $i,
				)
			);
		}
		$queue = aafm_notify_queue_list();
		$this->assertCount( AAFM_NOTIFY_QUEUE_MAX, $queue );
		$this->assertSame( AAFM_NOTIFY_QUEUE_MAX + 5, $queue[ count( $queue ) - 1 ]['row_id'], 'The newest entries survive.' );
	}

	public function test_schedule_follows_the_mode(): void {
		$this->configure( array( 'mode' => 'digest' ) );
		aafm_notify_sync_schedule();
		$this->assertNotFalse( wp_next_scheduled( 'aafm_notifications_digest' ) );
		$this->configure( array( 'mode' => 'immediate' ) );
		aafm_notify_sync_schedule();
		$this->assertFalse( wp_next_scheduled( 'aafm_notifications_digest' ) );
	}

	public function test_reset_and_uninstall_know_every_notification_option(): void {
		foreach ( array( 'aafm_notifications', 'aafm_notify_queue', 'aafm_notify_pending' ) as $option ) {
			$this->assertContains( $option, aafm_config_option_names(), $option );
		}
	}

	public function test_deactivation_unschedules_the_digest_event(): void {
		$this->configure( array( 'mode' => 'digest' ) );
		aafm_notify_sync_schedule();
		$this->assertNotFalse( wp_next_scheduled( 'aafm_notifications_digest' ), 'precondition' );
		$this->assertNotFalse( has_action( 'deactivate_' . AAFM_PLUGIN_BASENAME, 'aafm_notify_unschedule' ), 'The hook is registered on the plugin deactivation action.' );

		aafm_notify_unschedule();

		$this->assertFalse( wp_next_scheduled( 'aafm_notifications_digest' ) );
	}

	public function test_the_message_never_names_a_user_or_an_ip_address(): void {
		$this->configure();
		$old_ip                 = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- test fixture restoring its own value.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		try {
			$user_id = self::factory()->user->create(
				array(
					'role'       => 'administrator',
					'user_login' => 'ana_the_admin',
				)
			);
			wp_set_current_user( $user_id );
			aafm_notify_event( 'new_app', 'app_password', array( 'user_id' => $user_id ) );
			aafm_notify_event(
				'risky_run',
				'high_risk',
				array(
					'row_id'  => 5,
					'ability' => 'aafm/wc-create-order-refund',
				)
			);
		} finally {
			if ( null === $old_ip ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $old_ip;
			}
		}
		$this->assertCount( 2, $this->sent() );
		foreach ( $this->sent() as $mail ) {
			$this->assertStringNotContainsString( 'ana_the_admin', $mail['subject'] . $mail['message'] );
			$this->assertStringNotContainsString( '203.0.113.77', $mail['subject'] . $mail['message'] );
		}
	}
}
