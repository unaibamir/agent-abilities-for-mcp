<?php
/**
 * Alert mail off the visitor's request: the response is closed first where the host can do it, and
 * otherwise each message is handed to WP-Cron as its own event, with the in-request send kept as the
 * fallback when scheduling is not possible.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class NotifyCronHandoffTest extends TestCase {

	/**
	 * Messages captured instead of sent.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $mail = array();

	/**
	 * What the mail filter returns: true for sent, false for a failed send.
	 *
	 * @var bool
	 */
	private bool $mail_ok = true;

	public function set_up(): void {
		parent::set_up();
		$this->mail    = array();
		$this->mail_ok = true;
		aafm_notify_outbox( 'take' );
		wp_unschedule_hook( 'aafm_notify_send_scheduled' );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		// The core test bootstrap sets DISABLE_WP_CRON; these tests model a site where WP-Cron runs.
		add_filter( 'aafm_notify_use_cron', '__return_true' );
	}

	public function tear_down(): void {
		remove_all_filters( 'aafm_notify_finish_function' );
		remove_all_filters( 'pre_schedule_event' );
		remove_all_filters( 'aafm_notify_use_cron' );
		wp_unschedule_hook( 'aafm_notify_send_scheduled' );
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
	 * One outbox item, the shape aafm_notify_send_later() queues.
	 *
	 * @param string                         $subtype Event subtype.
	 * @param array<int,array<string,mixed>> $drain   Digest events to remove after a send.
	 * @return array<string,mixed>
	 */
	private function item( string $subtype = 'read_only_on', array $drain = array() ): array {
		return array(
			'to'     => 'owner@example.com',
			'events' => array(
				array(
					'id'      => wp_generate_uuid4(),
					'trigger' => 'switch_flip',
					'subtype' => $subtype,
					'time'    => time(),
				),
			),
			'digest' => false,
			'extra'  => '',
			'drain'  => $drain,
		);
	}

	/**
	 * Every scheduled alert-mail event: its timestamp and its single argument.
	 *
	 * @return list<array{time:int,item:array<string,mixed>}>
	 */
	private function scheduled(): array {
		$out = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( (array) ( $hooks['aafm_notify_send_scheduled'] ?? array() ) as $event ) {
				$out[] = array(
					'time' => (int) $time,
					'item' => $event['args'][0],
				);
			}
		}
		return $out;
	}

	/**
	 * Notification error rows in the activity log.
	 *
	 * @return int
	 */
	private function failure_rows(): int {
		return count(
			aafm_query_activity(
				array(
					'ability' => 'aafm/notifications',
					'status'  => 'error',
				)
			)
		);
	}

	/**
	 * A host with neither finish function, so the cron handoff is the path under test.
	 *
	 * @return void
	 */
	private function no_finish_function(): void {
		add_filter( 'aafm_notify_finish_function', '__return_null' );
	}

	/**
	 * Only a separate process can declare the LiteSpeed function, since no test may leave it behind.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_litespeed_finish_function_is_used_when_present(): void {
		$this->assertFalse( function_exists( 'fastcgi_finish_request' ), 'The CLI has no FastCGI finish function.' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a global host function cannot be declared inside this namespaced file.
		eval( 'function litespeed_finish_request() { $GLOBALS["aafm_test_litespeed_finished"] = true; return true; }' );
		aafm_notify_outbox( 'add', $this->item() );

		aafm_notify_flush_outbox();

		$this->assertTrue( $GLOBALS['aafm_test_litespeed_finished'] ?? false, 'The response was closed with the LiteSpeed function.' );
		$this->assertCount( 1, $this->mail, 'The mail went out in the request, after the response closed.' );
		$this->assertSame( array(), $this->scheduled() );
	}

	public function test_no_finish_function_schedules_one_event_per_item(): void {
		$this->no_finish_function();
		$first  = $this->item( 'read_only_on' );
		$second = $this->item( 'high_risk_unlocked' );
		aafm_notify_outbox( 'add', $first );
		aafm_notify_outbox( 'add', $second );

		aafm_notify_flush_outbox();

		$this->assertSame( array(), $this->mail, 'Nothing is mailed inside the visitor\'s request.' );
		$items = wp_list_pluck( $this->scheduled(), 'item' );
		$this->assertCount( 2, $items );
		$this->assertEqualsCanonicalizing( array( $first + array( 'attempt' => 1 ), $second + array( 'attempt' => 1 ) ), $items );
		foreach ( $items as $item ) {
			$this->assertSame( 1, $item['attempt'] );
		}
	}

	public function test_scheduling_failure_sends_in_request(): void {
		$this->no_finish_function();
		add_filter( 'pre_schedule_event', '__return_false' );
		aafm_notify_outbox( 'add', $this->item() );

		aafm_notify_flush_outbox();

		$this->assertCount( 1, $this->mail );
		$this->assertSame( array(), $this->scheduled() );
	}

	public function test_wp_cron_disabled_sends_in_request(): void {
		remove_all_filters( 'aafm_notify_use_cron' );
		$this->assertTrue( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'The core test bootstrap switches WP-Cron off.' );
		$this->no_finish_function();
		aafm_notify_outbox( 'add', $this->item() );

		aafm_notify_flush_outbox();

		$this->assertCount( 1, $this->mail );
		$this->assertSame( array(), $this->scheduled() );
	}

	public function test_cron_callback_sends_and_removes_drain_events(): void {
		$queued = array(
			'id'      => 'q1',
			'trigger' => 'switch_flip',
			'subtype' => 'read_only_on',
			'time'    => time(),
		);
		$this->assertTrue( aafm_notify_queue_push( $queued ) );
		$this->assertNotFalse( has_action( 'aafm_notify_send_scheduled', 'aafm_notify_send_scheduled' ) );

		do_action( 'aafm_notify_send_scheduled', $this->item( 'read_only_on', array( $queued ) ) + array( 'attempt' => 1 ) );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'owner@example.com', $this->mail[0]['to'] );
		$this->assertSame( array(), aafm_notify_queue_list(), 'The digest events the message described are gone.' );
		$this->assertSame( array(), $this->scheduled() );
	}

	public function test_cron_callback_failure_reschedules_once_then_logs(): void {
		$this->mail_ok = false;
		$item          = $this->item();
		$before        = $this->failure_rows();

		do_action( 'aafm_notify_send_scheduled', $item + array( 'attempt' => 1 ) );

		$retry = $this->scheduled();
		$this->assertCount( 1, $retry, 'One retry is scheduled.' );
		$this->assertSame( 2, $retry[0]['item']['attempt'] );
		$this->assertSame( $item, array_diff_key( $retry[0]['item'], array( 'attempt' => 0 ) ), 'The retry carries the same message.' );
		$this->assertGreaterThan( time(), $retry[0]['time'], 'The retry waits a few minutes.' );
		$this->assertSame( $before + 1, $this->failure_rows() );

		wp_unschedule_hook( 'aafm_notify_send_scheduled' ); // What WP-Cron does as it runs the event.
		do_action( 'aafm_notify_send_scheduled', $retry[0]['item'] );

		$this->assertSame( array(), $this->scheduled(), 'The second failure schedules nothing.' );
		$this->assertSame( $before + 2, $this->failure_rows(), 'The second failure is logged once.' );
		$this->assertCount( 2, $this->mail );
	}

	public function test_item_without_attempt_never_reschedules(): void {
		$this->mail_ok = false;

		do_action( 'aafm_notify_send_scheduled', $this->item() );
		do_action( 'aafm_notify_send_scheduled', $this->item() + array( 'attempt' => '1' ) );

		$this->assertSame( array(), $this->scheduled() );
	}

	public function test_a_retry_that_cannot_be_scheduled_is_logged(): void {
		$this->mail_ok = false;
		add_filter( 'pre_schedule_event', '__return_false' );
		$before = $this->failure_rows();

		do_action( 'aafm_notify_send_scheduled', $this->item() + array( 'attempt' => 1 ) );

		$this->assertSame( array(), $this->scheduled() );
		$this->assertSame( $before + 2, $this->failure_rows(), 'The failed send and the failed retry each leave a row.' );
	}

	public function test_reset_keeps_already_scheduled_alert_mail(): void {
		aafm_install_oauth_tables();
		$this->no_finish_function();
		aafm_notify_outbox( 'add', $this->item() );
		aafm_notify_flush_outbox();
		$this->assertCount( 1, $this->scheduled() );

		aafm_reset_plugin();

		$this->assertCount( 1, $this->scheduled(), 'An alert raised before the reset still goes out.' );
	}

	public function test_uninstall_clears_the_scheduled_hook(): void {
		$this->no_finish_function();
		aafm_notify_outbox( 'add', $this->item( 'read_only_on' ) );
		aafm_notify_outbox( 'add', $this->item( 'high_risk_unlocked' ) );
		aafm_notify_flush_outbox();
		$this->assertCount( 2, $this->scheduled() );

		aafm_uninstall_site_data();

		$this->assertSame( array(), $this->scheduled(), 'Every event goes, whatever its arguments.' );
	}

	public function test_deactivation_clears_the_scheduled_hook(): void {
		$this->no_finish_function();
		aafm_notify_outbox( 'add', $this->item( 'read_only_on' ) );
		aafm_notify_outbox( 'add', $this->item( 'high_risk_unlocked' ) );
		aafm_notify_flush_outbox();
		$this->assertCount( 2, $this->scheduled() );

		aafm_notify_unschedule( false );

		$this->assertSame( array(), $this->scheduled() );
	}

	public function test_mode_label_and_right_away_paragraph_allow_a_short_delay(): void {
		$this->acting_as( 'administrator' );
		$label = aafm_notify_modes()['immediate'];

		ob_start();
		aafm_render_notifications_section();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'right away', $label );
		$this->assertStringContainsString( 'admin notice', $label );
		$this->assertStringNotContainsString( 'do not depend on cron', $html );
		$this->assertStringContainsString( esc_html( $label ), $html );
		$this->assertStringContainsString( 'next visit', $html, 'The paragraph says the email can wait for the next visit.' );
	}
}
