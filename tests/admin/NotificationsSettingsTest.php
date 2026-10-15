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
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
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
		$this->sent[] = $atts;
		return true;
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

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'old@example.com', $this->sent[0]['to'] );
		$this->assertStringContainsString( 'The notification address was changed', $this->sent[0]['subject'] );
		$this->assertStringNotContainsString( 'new@example.com', $this->sent[0]['message'] );
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
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'owner@example.com', $this->sent[0]['to'] );

		$this->post_as( 'administrator' );
		$second = $this->run_handler( 'aafm_ajax_notify_test' );
		$this->assertFalse( $second['success'] ?? true );
		$this->assertCount( 1, $this->sent );
	}

	public function test_the_test_button_needs_an_address_and_manage_options(): void {
		$this->post_as( 'administrator' );
		$this->assertFalse( $this->run_handler( 'aafm_ajax_notify_test' )['success'] ?? true );
		$this->post_as( 'editor' );
		$this->assertFalse( $this->run_handler( 'aafm_ajax_notify_test' )['success'] ?? true );
		$this->assertSame( array(), $this->sent );
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
		$subjects = implode( ' | ', array_column( $this->sent, 'subject' ) );
		$this->assertCount( 2, $this->sent, $subjects );
		$this->assertStringContainsString( 'Read-only mode was turned on', $subjects, 'The flip alert uses the settings stored BEFORE the save.' );
		$this->assertStringContainsString( 'Email alerts were turned off', $subjects );
		$this->assertSame( array( 'owner@example.com', 'owner@example.com' ), array_column( $this->sent, 'to' ) );
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

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'old@example.com', $this->sent[0]['to'] );
		$this->assertStringContainsString( 'Email alerts were turned off', $this->sent[0]['subject'] );
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

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'switch changes were turned off', $this->sent[0]['subject'] );
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

		$this->assertSame( array(), $this->sent );
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
