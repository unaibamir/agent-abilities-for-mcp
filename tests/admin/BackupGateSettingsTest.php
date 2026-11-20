<?php
/**
 * The Backup gate card: the save path with its marker field, the schedule reconcile step, the
 * "Verify now" handler and the rendered card.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class BackupGateSettingsTest extends TestCase {

	use AjaxHarness;

	private const OPTIONS = array( 'aafm_backup_gate', 'aafm_backup_gate_abilities', 'aafm_backup_gate_accept_remote', 'aafm_backup_gate_accept_size' );

	/**
	 * Option names written during a save, in order, first write of each only.
	 *
	 * @var list<string>
	 */
	private $written = array();

	public function set_up(): void {
		parent::set_up();
		aafm_updraft_stub_activate();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';
		$this->written = array();
		wp_clear_scheduled_hook( 'aafm_backup_verify' );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		remove_all_filters( 'aafm_backup_gate_inline_checksum_max_bytes' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			remove_all_actions( $hook );
		}
		wp_clear_scheduled_hook( 'aafm_backup_verify' );
		aafm_updraft_stub_deactivate();
		parent::tear_down();
	}

	/**
	 * Run a real Settings save as an administrator and return the JSON it answered.
	 *
	 * @param array<string,mixed> $fields POST fields.
	 * @return array<string,mixed>
	 */
	private function save( array $fields ): array {
		$this->post_as( 'administrator', $fields );
		$json = $this->run_handler( 'aafm_ajax_save_settings' );
		$this->reset_ajax_harness();
		return $json;
	}

	/**
	 * The stored row of one option, straight from the database: null when there is no row.
	 *
	 * @param string $option Option name.
	 * @return mixed
	 */
	private function row( string $option ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		return null === $value ? null : maybe_unserialize( $value );
	}

	/**
	 * All four owner settings, straight from the database.
	 *
	 * @return array<string,mixed>
	 */
	private function rows(): array {
		$out = array();
		foreach ( self::OPTIONS as $option ) {
			$out[ $option ] = $this->row( $option );
		}
		return $out;
	}

	/**
	 * Put the four owner settings into a known state.
	 *
	 * @param bool              $on     Gate switch.
	 * @param bool              $remote Accept off-site backups.
	 * @param bool              $size   Accept size alone.
	 * @param array<int,string> $tools Tool list.
	 */
	private function stored( bool $on, bool $remote, bool $size, array $tools ): void {
		foreach (
			array(
				'aafm_backup_gate'               => $on,
				'aafm_backup_gate_accept_remote' => $remote,
				'aafm_backup_gate_accept_size'   => $size,
			) as $option => $value
		) {
			if ( $value ) {
				update_option( $option, '1' );
			} else {
				delete_option( $option );
			}
		}
		update_option( 'aafm_backup_gate_abilities', $tools );
	}

	/**
	 * Record the order in which the gate options are written.
	 */
	private function record_writes(): void {
		$record = function ( $option ): void {
			if ( in_array( $option, self::OPTIONS, true ) && ! in_array( $option, $this->written, true ) ) {
				$this->written[] = $option;
			}
		};
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, $record );
		}
	}

	/**
	 * Make every write to one option fail to persist: whatever is written, the row is put straight
	 * back to what it holds now.
	 *
	 * @param string $option Option name.
	 */
	private function make_unpersistable( string $option ): void {
		global $wpdb;
		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$revert = static function ( $changed ) use ( $option, $raw, $wpdb ): void {
			if ( $changed !== $option ) {
				return;
			}
			if ( null === $raw ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
			} else {
				$wpdb->query( $wpdb->prepare( "REPLACE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $option, $raw ) );
			}
		};
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, $revert );
		}
	}

	public function test_the_reconcile_step_follows_the_stored_switch(): void {
		update_option( 'aafm_backup_gate', '1' );
		aafm_backup_verify_reconcile();
		$event = wp_next_scheduled( 'aafm_backup_verify' );
		$this->assertNotFalse( $event, 'On and not scheduled: it schedules.' );
		$this->assertSame( 'hourly', wp_get_schedule( 'aafm_backup_verify' ) );

		aafm_backup_verify_reconcile();
		$this->assertSame( $event, wp_next_scheduled( 'aafm_backup_verify' ), 'On and scheduled: nothing changes.' );

		delete_option( 'aafm_backup_gate' );
		aafm_backup_verify_reconcile();
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'Off and scheduled: it clears.' );

		aafm_backup_verify_reconcile();
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'Off and not scheduled: nothing changes.' );
	}

	public function test_the_admin_init_callback_is_hooked_and_skips_ajax_requests(): void {
		$this->assertNotFalse( has_action( 'admin_init', 'aafm_backup_verify_reconcile_on_admin_init' ) );
		update_option( 'aafm_backup_gate', '1' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		aafm_backup_verify_reconcile_on_admin_init();
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'An AJAX request never touches the schedule through the hook.' );

		aafm_backup_verify_reconcile();
		$this->assertNotFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'The settings save calls the function itself, AJAX or not.' );

		remove_filter( 'wp_doing_ajax', '__return_true' );
		wp_clear_scheduled_hook( 'aafm_backup_verify' );
		aafm_backup_verify_reconcile_on_admin_init();
		$this->assertNotFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'A wp-admin page load schedules it.' );
	}

	public function test_a_reset_leaves_no_event_after_the_next_admin_page_load(): void {
		$this->stored( true, false, false, array( 'aafm/delete-post' ) );
		aafm_backup_verify_reconcile();
		$this->assertNotFalse( wp_next_scheduled( 'aafm_backup_verify' ) );

		aafm_install_oauth_tables();
		aafm_reset_plugin();
		aafm_backup_verify_reconcile_on_admin_init();

		$this->assertFalse( aafm_stored_switch_on( 'aafm_backup_gate' ) );
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ) );
	}

	public function test_deactivation_clears_the_event(): void {
		wp_schedule_event( time() + 60, 'hourly', 'aafm_backup_verify' );
		$this->assertNotFalse( wp_next_scheduled( 'aafm_backup_verify' ) );

		aafm_notify_unschedule();

		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ) );
	}

	public function test_no_activation_hook_touches_the_backup_gate(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$main = (string) file_get_contents( AAFM_PLUGIN_FILE );
		$this->assertSame( 0, preg_match( '/register_activation_hook\([^;]*aafm_backup/', $main ), 'Activation runs without the bootstrap; the schedule is reconciled on admin_init instead.' );
	}

	public function test_verify_now_needs_the_capability_and_the_nonce(): void {
		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => 1024 );
		update_option( 'aafm_backup_gate', '1' );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$this->post_as( 'subscriber' );
		$json = $this->run_handler( 'aafm_ajax_backup_verify_now' );
		$this->reset_ajax_harness();
		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( array(), aafm_backup_verify_read(), 'Nothing ran without manage_options.' );

		$this->post_as( 'administrator' );
		$_POST['nonce']    = 'not-a-nonce';
		$_REQUEST['nonce'] = 'not-a-nonce';
		$this->run_handler( 'aafm_ajax_backup_verify_now' );
		$this->reset_ajax_harness();
		$this->assertSame( array(), aafm_backup_verify_read(), 'Nothing ran without a valid nonce.' );
	}

	public function test_verify_now_runs_once_and_retries_a_too_large_verdict(): void {
		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => 1024 );
		update_option( 'aafm_backup_gate', '1' );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$this->assertSame( 'ran', aafm_backup_verify_run( 0 ) );
		$this->assertSame( 'too_large', aafm_backup_verify_read()['aaaaaaaaaaa1']['verdict'] );

		$this->post_as( 'administrator' );
		$json = $this->run_handler( 'aafm_ajax_backup_verify_now' );
		$this->reset_ajax_harness();

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( 'ran', $json['data']['result'] ?? '' );
		$this->assertStringContainsString( 'Reload', (string) ( $json['data']['message'] ?? '' ) );
		$this->assertSame( 'verified', aafm_backup_verify_read()['aaaaaaaaaaa1']['verdict'] );
	}
}
