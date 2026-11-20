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

	public function test_a_save_without_the_sentinel_changes_nothing(): void {
		$this->stored( true, false, true, array( 'aafm/delete-page', 'aafm/delete-post' ) );
		aafm_backup_verify_reconcile();
		$rows  = $this->rows();
		$event = wp_next_scheduled( 'aafm_backup_verify' );
		$this->assertNotFalse( $event );

		$json = $this->save( array( 'aafm_rate_limit_per_min' => '0' ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( $rows, $this->rows(), 'A save from a page without the card leaves all four settings as they were.' );
		$this->assertSame( $event, wp_next_scheduled( 'aafm_backup_verify' ) );

		wp_clear_scheduled_hook( 'aafm_backup_verify' );
		$this->save( array( 'aafm_rate_limit_per_min' => '0' ) );
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'Without the sentinel the save does not reconcile the schedule either.' );
	}

	public function test_a_save_with_the_sentinel_and_nothing_else_turns_the_gate_off(): void {
		$this->stored( true, true, true, array( 'aafm/delete-post' ) );
		aafm_backup_verify_reconcile();

		$json = $this->save( array( 'aafm_backup_gate_present' => '1' ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame(
			array(
				'aafm_backup_gate'               => null,
				'aafm_backup_gate_abilities'     => array(),
				'aafm_backup_gate_accept_remote' => null,
				'aafm_backup_gate_accept_size'   => null,
			),
			$this->rows()
		);
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'Turning the gate off clears the event in the same request.' );
	}

	public function test_a_save_stores_what_was_posted_and_schedules_the_check(): void {
		$json = $this->save(
			array(
				'aafm_backup_gate_present'     => '1',
				'aafm_backup_gate'             => '1',
				'aafm_backup_gate_accept_size' => '1',
				'aafm_backup_gate_abilities'   => array( 'woocommerce/product-delete', 'aafm/delete-post', 'aafm/delete-post', 'Not A Slug', 'Upper/Case' ),
			)
		);

		$this->assertTrue( $json['success'] ?? false );
		$this->assertTrue( aafm_stored_switch_on( 'aafm_backup_gate' ) );
		$this->assertSame( array( 'aafm/delete-post', 'woocommerce/product-delete' ), aafm_backup_gate_list(), 'Sorted, unique, and only slug-shaped values.' );
		$this->assertTrue( aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_size' ) );
		$this->assertFalse( aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_remote' ) );
		$this->assertNotFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'Turning the gate on schedules the event in the same request.' );
	}

	public function test_a_save_that_changes_nothing_still_repairs_a_missing_event(): void {
		$this->stored( true, false, false, array( 'aafm/delete-post' ) );
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ) );

		$this->save(
			array(
				'aafm_backup_gate_present'   => '1',
				'aafm_backup_gate'           => '1',
				'aafm_backup_gate_abilities' => array( 'aafm/delete-post' ),
			)
		);

		$this->assertNotFalse( wp_next_scheduled( 'aafm_backup_verify' ) );
	}

	public function test_a_list_that_is_not_an_array_is_refused_and_writes_nothing(): void {
		$this->stored( false, false, false, array( 'aafm/delete-post' ) );
		$rows = $this->rows();

		$json = $this->save(
			array(
				'aafm_backup_gate_present'   => '1',
				'aafm_backup_gate'           => '1',
				'aafm_backup_gate_abilities' => 'aafm/delete-post',
			)
		);

		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( 'The backup gate tool list could not be read. Reload this page and try again.', $json['data']['message'] ?? '' );
		$this->assertSame( $rows, $this->rows() );
	}

	public function test_restrictive_changes_are_written_before_the_list_and_the_gate_turning_on(): void {
		$this->stored( false, true, true, array( 'aafm/delete-post' ) );
		$this->record_writes();

		$this->save(
			array(
				'aafm_backup_gate_present'   => '1',
				'aafm_backup_gate'           => '1',
				'aafm_backup_gate_abilities' => array( 'aafm/delete-page', 'aafm/delete-post' ),
			)
		);

		$this->assertSame( array( 'aafm_backup_gate_accept_remote', 'aafm_backup_gate_accept_size', 'aafm_backup_gate', 'aafm_backup_gate_abilities' ), $this->written );
	}

	public function test_permissive_changes_are_written_after_the_list_and_the_gate_turns_off_last(): void {
		$this->stored( true, false, false, array( 'aafm/delete-post' ) );
		$this->record_writes();

		$this->save(
			array(
				'aafm_backup_gate_present'       => '1',
				'aafm_backup_gate_accept_remote' => '1',
				'aafm_backup_gate_accept_size'   => '1',
				'aafm_backup_gate_abilities'     => array( 'aafm/delete-page' ),
			)
		);

		$this->assertSame( array( 'aafm_backup_gate_abilities', 'aafm_backup_gate_accept_remote', 'aafm_backup_gate_accept_size', 'aafm_backup_gate' ), $this->written );
	}

	/**
	 * Each of the seven writes, failing in turn. Scenario A makes writes 1 to 4, scenario B writes 4 to 7.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function failing_writes(): array {
		return array(
			'1 off-site off' => array( 'A', 'aafm_backup_gate_accept_remote', 'Off-site backups' ),
			'2 large off'    => array( 'A', 'aafm_backup_gate_accept_size', 'Large backups' ),
			'3 gate on'      => array( 'A', 'aafm_backup_gate', 'The backup gate' ),
			'4 list'         => array( 'A', 'aafm_backup_gate_abilities', 'The backup gate tool list' ),
			'4 list again'   => array( 'B', 'aafm_backup_gate_abilities', 'The backup gate tool list' ),
			'5 off-site on'  => array( 'B', 'aafm_backup_gate_accept_remote', 'Off-site backups' ),
			'6 large on'     => array( 'B', 'aafm_backup_gate_accept_size', 'Large backups' ),
			'7 gate off'     => array( 'B', 'aafm_backup_gate', 'The backup gate' ),
		);
	}

	/**
	 * A write that does not persist stops the block and names its setting.
	 *
	 * @dataProvider failing_writes
	 *
	 * @param string $scenario 'A' or 'B'.
	 * @param string $failing  The option whose write does not persist.
	 * @param string $label    The name the error must use for it.
	 */
	public function test_a_write_that_does_not_persist_stops_the_block_and_names_the_setting( string $scenario, string $failing, string $label ): void {
		if ( 'A' === $scenario ) {
			$this->stored( false, true, true, array( 'aafm/delete-post' ) );
			$order  = array( 'aafm_backup_gate_accept_remote', 'aafm_backup_gate_accept_size', 'aafm_backup_gate', 'aafm_backup_gate_abilities' );
			$fields = array(
				'aafm_backup_gate_present'   => '1',
				'aafm_backup_gate'           => '1',
				'aafm_backup_gate_abilities' => array( 'aafm/delete-page', 'aafm/delete-post' ),
			);
			$wanted = array(
				'aafm_backup_gate'               => '1',
				'aafm_backup_gate_abilities'     => array( 'aafm/delete-page', 'aafm/delete-post' ),
				'aafm_backup_gate_accept_remote' => null,
				'aafm_backup_gate_accept_size'   => null,
			);
		} else {
			$this->stored( true, false, false, array( 'aafm/delete-post' ) );
			$order  = array( 'aafm_backup_gate_abilities', 'aafm_backup_gate_accept_remote', 'aafm_backup_gate_accept_size', 'aafm_backup_gate' );
			$fields = array(
				'aafm_backup_gate_present'       => '1',
				'aafm_backup_gate_accept_remote' => '1',
				'aafm_backup_gate_accept_size'   => '1',
				'aafm_backup_gate_abilities'     => array( 'aafm/delete-page' ),
			);
			$wanted = array(
				'aafm_backup_gate'               => null,
				'aafm_backup_gate_abilities'     => array( 'aafm/delete-page' ),
				'aafm_backup_gate_accept_remote' => '1',
				'aafm_backup_gate_accept_size'   => '1',
			);
		}
		$before = $this->rows();
		$this->make_unpersistable( $failing );

		$json = $this->save( $fields );

		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( $label, (string) ( $json['data']['message'] ?? '' ) );
		$after  = $this->rows();
		$passed = true;
		foreach ( $order as $option ) {
			if ( $option === $failing ) {
				$passed = false;
			}
			$is_on_off = 'aafm_backup_gate_abilities' !== $option;
			$expect    = $passed ? $wanted[ $option ] : $before[ $option ];
			if ( $is_on_off ) {
				$this->assertSame( null !== $expect, null !== $after[ $option ], "{$option} after a failed write of {$failing}." );
			} else {
				$this->assertSame( $expect, $after[ $option ], "{$option} after a failed write of {$failing}." );
			}
		}
	}

	public function test_each_change_is_logged(): void {
		$this->stored( false, false, false, array() );

		$this->save(
			array(
				'aafm_backup_gate_present'       => '1',
				'aafm_backup_gate'               => '1',
				'aafm_backup_gate_accept_remote' => '1',
				'aafm_backup_gate_accept_size'   => '1',
				'aafm_backup_gate_abilities'     => array( 'aafm/delete-post', 'aafm/delete-page' ),
			)
		);

		$detail = static function ( string $ability ): string {
			$rows = aafm_query_activity( array( 'ability' => $ability ) );
			return (string) ( $rows[0]['detail'] ?? '' );
		};
		$this->assertSame( 'Backup gate turned on', $detail( 'aafm/backup-gate' ) );
		$this->assertSame( 'Backup gate tools changed: 2 chosen', $detail( 'aafm/backup-gate-tools' ) );
		$this->assertSame( 'Off-site backups accepted', $detail( 'aafm/backup-gate-off-site' ) );
		$this->assertSame( 'Large backups accepted on size alone', $detail( 'aafm/backup-gate-large' ) );

		$this->save( array( 'aafm_backup_gate_present' => '1' ) );
		$this->assertSame( 'Backup gate turned off', $detail( 'aafm/backup-gate' ) );
		$this->assertSame( 'Backup gate tools changed: 0 chosen', $detail( 'aafm/backup-gate-tools' ) );
		$this->assertSame( 'Off-site backups no longer accepted', $detail( 'aafm/backup-gate-off-site' ) );
		$this->assertSame( 'Large backups no longer accepted on size alone', $detail( 'aafm/backup-gate-large' ) );
	}

	/**
	 * The rendered card.
	 */
	private function card(): string {
		ob_start();
		aafm_render_backup_gate_section();
		return (string) ob_get_clean();
	}

	public function test_the_card_carries_the_sentinel_and_a_proper_verify_button(): void {
		update_option( 'aafm_backup_gate', '1' );
		$html = $this->card();

		$this->assertStringContainsString( 'name="aafm_backup_gate_present" value="1"', $html );
		$this->assertSame( 1, preg_match( '/<button[^>]*class="([^"]*)"[^>]*>Verify now<\/button>/', $html, $button ) );
		$this->assertStringContainsString( 'type="button"', $button[0] );
		$this->assertSame( array( 'aafm-btn', 'aafm-btn-secondary', 'aafm-backup-verify-now' ), explode( ' ', $button[1] ) );
		$this->assertStringContainsString( 'data-aafm-untracked', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertSame( 1, substr_count( $html, 'aafm-card-head-title' ), 'One card, one head.' );
		$this->assertStringContainsString( 'name="aafm_backup_gate_abilities[]" value="aafm/delete-post"', $html );
		$this->assertStringNotContainsString( 'value="aafm/get-posts"', $html, 'A read is never offered.' );
		$this->assertStringNotContainsString( 'value="aafm/site-list-backups"', $html );
	}

	public function test_the_card_shows_each_state(): void {
		$this->assertStringContainsString( 'aafm-pill-neutral">Off<', $this->card(), 'Gate off.' );
		$this->assertStringNotContainsString( 'Verify now', $this->card(), 'Nothing is checked while the gate is off, so Verify now is not offered.' );
		$this->assertStringContainsString( 'UpdraftPlus detected', $this->card() );
		$this->assertStringContainsString( 'No good backup found in the last 24 hours.', $this->card() );

		update_option( 'aafm_backup_gate', '1' );
		update_option( 'aafm_backup_gate_abilities', array( 'aafm/delete-post', 'gone/tool' ) );
		$this->assertStringContainsString( 'aafm-pill-warn">No recent backup<', $this->card(), 'On, no backup.' );
		$this->assertSame( 1, preg_match( '/value="gone\/tool"\s+checked/', $this->card() ), 'A stored slug that is no longer offered is listed, ticked.' );

		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$html = $this->card();
		$this->assertStringContainsString( 'aafm-pill-success">Backup found<', $html, 'On, a checked backup.' );
		$this->assertStringContainsString( 'database only. It matched its checksum when it was checked.', $html );

		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => 1024 );
		$html = $this->card();
		$this->assertStringContainsString( 'aafm-pill-warn">Checking backup<', $html, 'On, a backup waiting to be checked.' );
		$this->assertStringContainsString( 'A recent backup is waiting to be checked. The check is not scheduled yet. Reload this page.', $html );

		delete_option( 'aafm_backup_gate' );
		$html = $this->card();
		$this->assertStringContainsString( 'A recent backup is waiting to be checked. Backups are checked once the gate is on.', $html, 'Gate off, a backup waiting.' );
		$this->assertStringNotContainsString( 'not scheduled', $html );
		$this->assertStringNotContainsString( 'Verify now', $html );
		update_option( 'aafm_backup_gate', '1' );

		update_option(
			'aafm_backup_verify',
			array(
				'aaaaaaaaaaa1' => array(
					'fingerprint' => aafm_site_updraft_fingerprint( 'aaaaaaaaaaa1', $file ),
					'verdict'     => 'too_large',
					'checked_at'  => time(),
				),
			)
		);
		$html = $this->card();
		$this->assertStringContainsString( 'aafm-pill-warn">Backup too large to check<', $html, 'On, a backup too large to check.' );
		$this->assertStringContainsString( 'switch on Large backups below', $html );

		delete_option( 'aafm_backup_gate' );
		$html = $this->card();
		$this->assertStringContainsString( 'in the time one check is allowed. Switch on Large backups below to accept it on its size alone.', $html, 'Gate off, a backup too large to check.' );
		$this->assertStringNotContainsString( 'Verify now', $html );
		update_option( 'aafm_backup_gate', '1' );

		update_option( 'aafm_backup_gate_abilities', array() );
		$this->assertStringContainsString( 'The gate is on and no tool is chosen, so nothing is held back.', $this->card() );

		aafm_updraft_stub_deactivate();
		$html = $this->card();
		$this->assertStringContainsString( 'No backup provider detected', $html, 'No provider.' );
		$this->assertStringContainsString( 'aafm_backup_gate_vouch', $html );
		$this->assertStringNotContainsString( 'Verify now', $html, 'The button is offered only with UpdraftPlus.' );
	}
}
