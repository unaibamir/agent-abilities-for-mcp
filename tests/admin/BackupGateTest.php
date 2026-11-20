<?php
/**
 * The backup gate: the check, the per-call answer, the stored verdicts and the background check.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class BackupGateTest extends TestCase {

	private const TOOL = 'aafm/delete-post';

	/**
	 * Bytes hashed since set_up, by the inline check and the background check.
	 *
	 * @var int
	 */
	private $hashed = 0;

	public function set_up(): void {
		parent::set_up();
		aafm_updraft_stub_activate();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';
		$this->hashed = 0;
		add_action( 'aafm_site_backup_hashed', array( $this, 'count_hashed' ) );
		update_option( 'aafm_backup_gate', '1' );
		update_option( 'aafm_backup_gate_abilities', array( self::TOOL, 'woocommerce/product-delete' ) );
		// Start every test as a fresh tool call.
		$this->leave_execute();
	}

	public function tear_down(): void {
		$this->leave_execute();
		remove_action( 'aafm_site_backup_hashed', array( $this, 'count_hashed' ) );
		remove_all_filters( 'aafm_backup_gate_inline_checksum_max_bytes' );
		remove_all_filters( 'aafm_backup_gate_vouch' );
		remove_all_filters( 'query' );
		aafm_updraft_stub_deactivate();
		parent::tear_down();
	}

	public function count_hashed( int $bytes ): void {
		$this->hashed += $bytes;
	}

	/**
	 * Empty the executing-ability stack, so the next permission fire is the adapter's.
	 */
	private function leave_execute(): void {
		while ( null !== aafm_executing_ability() ) {
			aafm_executing_ability_pop();
		}
	}

	/**
	 * The adapter's permission fire for a tool: the start of a tool call.
	 *
	 * @param string $name Ability name.
	 */
	private function adapter_fire( string $name = self::TOOL ): string {
		$this->leave_execute();
		return aafm_backup_gate_refusal( $name );
	}

	/**
	 * Core's re-check fire inside execute() for a tool.
	 *
	 * @param string $name Ability name.
	 */
	private function recheck_fire( string $name = self::TOOL ): string {
		$this->leave_execute();
		aafm_executing_ability_push( $name );
		$answer = aafm_backup_gate_refusal( $name );
		aafm_executing_ability_pop();
		return $answer;
	}

	/**
	 * Lower the inline budget, so a 2 KB stub archive is "large".
	 *
	 * @param int $bytes Budget.
	 */
	private function budget( int $bytes ): void {
		remove_all_filters( 'aafm_backup_gate_inline_checksum_max_bytes' );
		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => $bytes );
	}

	/**
	 * Store one verdict for a set, with the fingerprint its file has now.
	 *
	 * @param string $nonce   Set nonce.
	 * @param string $file    Archive path.
	 * @param string $verdict 'verified', 'failed' or 'too_large'.
	 */
	private function store_verdict( string $nonce, string $file, string $verdict ): void {
		update_option(
			'aafm_backup_verify',
			array(
				$nonce => array(
					'fingerprint' => aafm_site_updraft_fingerprint( $nonce, $file ),
					'verdict'     => $verdict,
					'checked_at'  => time(),
				),
			)
		);
	}

	/**
	 * What must not change while the gate only checks: every option row and the cron array.
	 */
	private function write_snapshot(): string {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
		return md5( (string) wp_json_encode( $rows ) ) . '|' . md5( (string) wp_json_encode( _get_cron_array() ) );
	}

	public function test_off_refuses_nothing_and_reads_no_history(): void {
		delete_option( 'aafm_backup_gate' );
		$reads = aafm_updraft_stub_reads();

		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( '', $this->recheck_fire() );
		$this->assertSame( $reads, aafm_updraft_stub_reads() );
	}

	public function test_a_tool_that_is_not_on_the_list_is_not_refused(): void {
		$this->assertSame( '', $this->adapter_fire( 'aafm/update-post' ) );
	}

	public function test_an_empty_list_governs_nothing(): void {
		delete_option( 'aafm_backup_gate_abilities' );
		$this->assertSame( '', $this->adapter_fire() );
	}

	public function test_a_listed_tool_with_no_backup_is_refused(): void {
		$this->assertSame( 'backup_gate', $this->adapter_fire() );
		$this->assertSame( 'no_backup', aafm_backup_gate_state()['reason'] );
	}

	public function test_a_listed_tool_with_no_provider_is_refused(): void {
		aafm_updraft_stub_deactivate();

		$this->assertSame( 'backup_gate', $this->adapter_fire() );
		$state = aafm_backup_gate_state();
		$this->assertSame( 'none', $state['provider'] );
		$this->assertSame( 'no_provider', $state['reason'] );
	}

	public function test_a_good_backup_23_hours_old_opens_the_gate(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 23 * HOUR_IN_SECONDS );
		$this->assertSame( '', $this->adapter_fire() );
	}

	public function test_a_good_backup_25_hours_old_does_not(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 25 * HOUR_IN_SECONDS );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );
	}

	public function test_no_answer_outlives_a_tool_call(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$data = aafm_updraft_stub_read( $file );
		$this->assertSame( '', $this->adapter_fire() );

		aafm_updraft_stub_delete( $file );
		$this->assertSame( 'backup_gate', $this->adapter_fire(), 'A second tool call in the same process must look again.' );

		aafm_updraft_stub_write( $file, $data );
		$this->assertSame( '', $this->adapter_fire() );
	}

	public function test_the_two_fires_of_one_call_share_one_answer(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$reads = aafm_updraft_stub_reads();

		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( '', $this->recheck_fire() );

		$this->assertSame( 1, aafm_updraft_stub_reads() - $reads, 'The history is read once across the two fires.' );
		$this->assertSame( 2048, $this->hashed, 'The archive is hashed once across the two fires.' );
	}

	public function test_a_recheck_for_another_tool_does_not_use_the_answer(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$this->assertSame( '', $this->adapter_fire() );
		$reads = aafm_updraft_stub_reads();

		$this->assertSame( '', $this->recheck_fire( 'woocommerce/product-delete' ) );

		$this->assertSame( 1, aafm_updraft_stub_reads() - $reads, 'A different name computes its own answer.' );
	}

	public function test_the_answer_is_cleared_before_every_early_return(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		// Call 1, then a second adapter fire that leaves a fresh answer behind with no re-check.
		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( '', $this->recheck_fire() );
		$this->assertSame( '', $this->adapter_fire() );

		// Call 2 returns early on its adapter fire: the tool is off the list.
		aafm_updraft_stub_delete( $file );
		update_option( 'aafm_backup_gate_abilities', array( 'woocommerce/product-delete' ) );
		$this->assertSame( '', $this->adapter_fire() );

		// Its re-check, with the tool back on the list, must not find call 1's answer.
		update_option( 'aafm_backup_gate_abilities', array( self::TOOL ) );
		$reads = aafm_updraft_stub_reads();
		$this->assertSame( 'backup_gate', $this->recheck_fire() );
		$this->assertSame( 1, aafm_updraft_stub_reads() - $reads );
	}

	public function test_the_answer_is_read_at_most_once(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( '', $this->recheck_fire() );

		aafm_updraft_stub_delete( $file );
		$reads = aafm_updraft_stub_reads();
		$this->assertSame( 'backup_gate', $this->recheck_fire(), 'A second re-check with no adapter fire before it computes afresh.' );
		$this->assertSame( 1, aafm_updraft_stub_reads() - $reads );
	}

	public function test_one_walk_never_hashes_more_than_the_inline_budget(): void {
		$this->budget( 3072 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa3', 3000 );
		aafm_updraft_stub_corrupt( aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 2000 ) );
		aafm_updraft_stub_corrupt( aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 1000 ) );

		$this->assertSame( 'backup_gate_pending', $this->adapter_fire(), 'The newest fails, the second cannot be hashed any more, and the third does not open the gate.' );
		$this->assertSame( 2048, $this->hashed );
	}

	public function test_same_size_corruption_of_a_small_archive_is_refused(): void {
		aafm_updraft_stub_corrupt( aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 ) );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );
	}

	public function test_a_cut_or_deleted_archive_is_refused(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		aafm_updraft_stub_truncate( $file );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );

		aafm_updraft_stub_delete( $file );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );
	}

	/**
	 * Each rule of the archive check, broken in turn.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function bad_entries(): array {
		return array(
			'not native'        => array( array( 'native' => false ) ),
			'migration set'     => array( array( 'service' => array( 'remotesend' ) ) ),
			'no db key'         => array( array( 'db' => null ) ),
			'db-size zero'      => array( array( 'db-size' => 0 ) ),
			'db-size differs'   => array( array( 'db-size' => 2049 ) ),
			'no checksums'      => array( array( 'checksums' => null ) ),
			'checksum not sha1' => array( array( 'checksums' => array( 'sha1' => array( 'db0' => 'nope' ) ) ) ),
		);
	}

	/**
	 * A set that breaks an archive rule is refused.
	 *
	 * @dataProvider bad_entries
	 *
	 * @param array<string,mixed> $overrides Entry overrides.
	 */
	public function test_a_set_that_breaks_an_archive_rule_is_refused( array $overrides ): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600, 2048, $overrides );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );
	}

	public function test_an_archive_over_the_budget_is_pending(): void {
		$this->budget( 1024 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$this->assertSame( 'backup_gate_pending', $this->adapter_fire() );
		$this->assertSame( 'verification_pending', aafm_backup_gate_state()['reason'] );
		$this->assertSame( 0, $this->hashed );
	}

	public function test_a_stored_verdict_opens_or_closes_the_gate_for_a_large_archive(): void {
		$this->budget( 1024 );
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$this->store_verdict( 'aaaaaaaaaaa1', $file, 'verified' );
		$this->assertSame( '', $this->adapter_fire() );
		$this->assertNotNull( aafm_backup_gate_state()['last_good']['checked_at'] ?? null );

		$this->store_verdict( 'aaaaaaaaaaa1', $file, 'failed' );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );

		$this->store_verdict( 'aaaaaaaaaaa1', $file, 'too_large' );
		$this->assertSame( 'backup_gate_too_large', $this->adapter_fire() );
		$this->assertSame( 'too_large_to_verify', aafm_backup_gate_state()['reason'] );
	}

	public function test_a_verdict_is_not_honoured_after_the_file_changes(): void {
		$this->budget( 1024 );
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$this->store_verdict( 'aaaaaaaaaaa1', $file, 'verified' );
		$this->assertSame( '', $this->adapter_fire() );

		aafm_updraft_stub_touch( $file, time() - 500 );
		$this->assertSame( 'backup_gate_pending', $this->adapter_fire(), 'A new mtime makes the stored verdict stale.' );
	}

	public function test_the_fingerprint_is_nonce_size_mtime_ctime_and_inode(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		clearstatcache( true, $file );

		$this->assertSame(
			'aaaaaaaaaaa1|2048|' . filemtime( $file ) . '|' . filectime( $file ) . '|' . fileinode( $file ),
			aafm_site_updraft_fingerprint( 'aaaaaaaaaaa1', $file )
		);
		$this->assertSame( '', aafm_site_updraft_fingerprint( 'aaaaaaaaaaa1', $file . '.missing' ) );
	}

	public function test_a_rewrite_with_the_old_mtime_restored_still_changes_the_fingerprint(): void {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$this->markTestSkipped( 'ctime is the creation time on Windows.' );
		}
		$file  = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$mtime = time() - 3000;
		$data  = aafm_updraft_stub_read( $file );
		aafm_updraft_stub_touch( $file, $mtime );
		$before = aafm_site_updraft_fingerprint( 'aaaaaaaaaaa1', $file );
		$ctime  = (int) filectime( $file );

		// The fingerprint holds ctime in whole seconds. Touch a probe file beside the archive until
		// the file system's own clock has passed the archive's ctime: no wait on PHP's clock, which
		// can run ahead of the file system's.
		$probe = dirname( $file ) . '/aafm-clock-probe';
		$until = time() + 5;
		do {
			aafm_updraft_stub_touch( $probe, $mtime );
			$now = (int) filectime( $probe );
		} while ( $now <= $ctime && time() < $until );
		wp_delete_file( $probe );
		$this->assertGreaterThan( $ctime, $now, 'The file system clock moved past the first write.' );

		aafm_updraft_stub_write( $file, strrev( $data ) );
		aafm_updraft_stub_touch( $file, $mtime );

		$this->assertNotSame( $before, aafm_site_updraft_fingerprint( 'aaaaaaaaaaa1', $file ), 'Restoring the mtime sets ctime to now, so the fingerprint differs.' );
	}

	public function test_accepting_size_alone_opens_the_gate_for_an_unchecked_large_archive(): void {
		$this->budget( 1024 );
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		update_option( 'aafm_backup_gate_accept_size', '1' );

		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( 'size_only', aafm_backup_gate_state()['last_good']['database_check'] );

		$this->store_verdict( 'aaaaaaaaaaa1', $file, 'too_large' );
		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( 'size_only', aafm_backup_gate_state()['last_good']['database_check'] );
	}

	public function test_an_older_verified_set_opens_the_gate_while_a_newer_one_waits(): void {
		$this->budget( 1024 );
		$old = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 7200 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 3600 );
		$this->store_verdict( 'aaaaaaaaaaa1', $old, 'verified' );

		$this->assertSame( '', $this->adapter_fire() );
	}

	public function test_a_pending_set_beside_a_too_large_one_reads_pending(): void {
		$this->budget( 1024 );
		$old = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 7200 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 3600 );
		$this->store_verdict( 'aaaaaaaaaaa1', $old, 'too_large' );

		$this->assertSame( 'backup_gate_pending', $this->adapter_fire() );
	}

	public function test_a_live_job_row_refuses_and_a_finished_one_does_not(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		aafm_updraft_stub_job( 'aaaaaaaaaaa1', array( 'jobstatus' => 'filescreating' ) );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );

		aafm_updraft_stub_job( 'aaaaaaaaaaa1', array( 'jobstatus' => 'finished' ) );
		$this->assertSame( '', $this->adapter_fire() );
	}

	public function test_a_remote_only_set_needs_the_owner_to_accept_it(): void {
		aafm_updraft_stub_delete( aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600, 2048, array( 'service' => array( 's3' ) ) ) );
		$this->assertSame( 'backup_gate', $this->adapter_fire() );

		update_option( 'aafm_backup_gate_accept_remote', '1' );
		$this->assertSame( '', $this->adapter_fire() );
		$this->assertSame( 'trusted_unchecked', aafm_backup_gate_state()['last_good']['database_check'] );
	}

	public function test_a_history_reader_that_throws_refuses(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		aafm_updraft_stub_throw( true );

		$this->assertSame( 'backup_gate', $this->adapter_fire() );
	}

	public function test_only_an_exact_true_vouches(): void {
		add_filter( 'aafm_backup_gate_vouch', '__return_true' );
		$this->assertSame( '', $this->adapter_fire() );

		foreach ( array( 1, 'yes' ) as $loose ) {
			remove_all_filters( 'aafm_backup_gate_vouch' );
			add_filter(
				'aafm_backup_gate_vouch',
				static function () use ( $loose ) {
					return $loose;
				}
			);
			$this->assertSame( 'backup_gate', $this->adapter_fire(), wp_json_encode( $loose ) . ' is not true.' );
		}
	}

	public function test_an_unreadable_switch_row_refuses(): void {
		delete_option( 'aafm_backup_gate' );
		$answer = QueryFaultInjector::break_query_with_real_error(
			"'aafm_backup_gate'",
			function (): string {
				return $this->adapter_fire();
			}
		);

		$this->assertSame( 'backup_gate', $answer, 'A switch row that cannot be read reads as on.' );
	}

	public function test_an_unreadable_list_governs_every_write(): void {
		$answer = QueryFaultInjector::break_query_with_real_error(
			'aafm_backup_gate_abilities',
			function (): string {
				return $this->adapter_fire( 'aafm/update-post' );
			}
		);

		$this->assertSame( 'backup_gate', $answer );
	}

	public function test_an_unreadable_verdict_row_leaves_a_large_archive_pending(): void {
		$this->budget( 1024 );
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$this->store_verdict( 'aaaaaaaaaaa1', $file, 'verified' );

		$answer = QueryFaultInjector::break_query_with_real_error(
			"'aafm_backup_verify'",
			function (): string {
				return $this->adapter_fire();
			}
		);

		$this->assertSame( 'backup_gate_pending', $answer );
	}

	public function test_malformed_verdicts_are_dropped(): void {
		update_option(
			'aafm_backup_verify',
			array(
				'aaaaaaaaaaa1' => array(
					'fingerprint' => 'f',
					'verdict'     => 'verified',
					'checked_at'  => 5,
				),
				'aaaaaaaaaaa2' => array(
					'fingerprint' => 'f',
					'verdict'     => 'good',
					'checked_at'  => 5,
				),
				'aaaaaaaaaaa3' => 'verified',
				'not-a-nonce'  => array(
					'fingerprint' => 'f',
					'verdict'     => 'verified',
					'checked_at'  => 5,
				),
			)
		);
		$this->assertSame( array( 'aaaaaaaaaaa1' ), array_keys( aafm_backup_verify_read() ) );

		update_option( 'aafm_backup_verify', 'not an array' );
		$this->assertSame( array(), aafm_backup_verify_read() );
	}

	public function test_the_two_backup_tools_and_every_read_are_never_governed(): void {
		update_option( 'aafm_backup_gate_abilities', array( 'aafm/site-backup-status', 'aafm/site-list-backups', 'aafm/get-posts', self::TOOL ) );

		foreach ( array( 'aafm/site-backup-status', 'aafm/site-list-backups', 'aafm/get-posts' ) as $name ) {
			$this->assertSame( '', $this->adapter_fire( $name ), $name );
		}
		$this->assertSame( 'backup_gate', $this->adapter_fire() );
	}

	public function test_a_bridged_wrapper_is_governed_by_its_foreign_slug(): void {
		aafm_remember_bridge_source( 'aafm-bridge/woocommerce-product-delete', 'woocommerce/product-delete' );

		$this->assertSame( 'backup_gate', $this->adapter_fire( 'aafm-bridge/woocommerce-product-delete' ) );
		$this->assertSame( 'backup_gate', $this->adapter_fire( 'aafm-bridge/source-never-recorded' ), 'A wrapper whose source cannot be recovered is governed.' );

		update_option( 'aafm_backup_gate_abilities', array( self::TOOL ) );
		$this->assertSame( '', $this->adapter_fire( 'aafm-bridge/woocommerce-product-delete' ) );
	}

	public function test_an_empty_list_holds_back_no_wrapper_even_one_whose_slug_is_unknown(): void {
		delete_option( 'aafm_backup_gate_abilities' );
		$this->assertSame( '', $this->adapter_fire( 'aafm-bridge/source-never-recorded' ) );
	}

	public function test_the_schedule_state_names_why_the_check_may_not_run(): void {
		$this->assertSame( 'not_scheduled', aafm_backup_verify_schedule_state() );

		wp_schedule_event( time() + 600, 'hourly', 'aafm_backup_verify' );
		$this->assertSame( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'cron_disabled' : 'ok', aafm_backup_verify_schedule_state() );

		wp_clear_scheduled_hook( 'aafm_backup_verify' );
		wp_schedule_event( time() - 1000, 'hourly', 'aafm_backup_verify' );
		$this->assertSame( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'cron_disabled' : 'cron_late', aafm_backup_verify_schedule_state() );
	}

	public function test_each_refusal_has_its_own_code_and_message(): void {
		$plain = aafm_backup_gate_error( 'backup_gate' );
		$this->assertSame( 'aafm_backup_gate', $plain->get_error_code() );
		$this->assertStringContainsString( 'has none that passed', $plain->get_error_message() );
		$this->assertStringContainsString( 'This connection cannot start a backup.', $plain->get_error_message() );

		$pending = aafm_backup_gate_error( 'backup_gate_pending' );
		$this->assertSame( 'aafm_backup_gate_pending', $pending->get_error_code() );
		$this->assertStringContainsString( 'has not finished checking it yet', $pending->get_error_message() );
		$this->assertStringContainsString( 'Try again later.', $pending->get_error_message() );

		$large = aafm_backup_gate_error( 'backup_gate_too_large' );
		$this->assertSame( 'aafm_backup_gate_too_large', $large->get_error_code() );
		$this->assertStringContainsString( 'too large for this site to check', $large->get_error_message() );
		$this->assertStringContainsString( 'Waiting will not change that.', $large->get_error_message() );
		$this->assertStringNotContainsString( 'Try again later', $large->get_error_message() );

		foreach ( array( $plain, $pending, $large ) as $error ) {
			$this->assertStringContainsString( 'Backup gate card under Settings', $error->get_error_message() );
		}
	}

	public function test_the_check_writes_nothing(): void {
		$this->budget( 1024 );
		$large = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 7200 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 3600 );
		$this->store_verdict( 'aaaaaaaaaaa1', $large, 'verified' );
		$this->budget( 8388608 );
		$before = $this->write_snapshot();

		$this->adapter_fire();
		$this->recheck_fire();
		$this->adapter_fire( 'aafm/update-post' );
		aafm_backup_gate_state();

		$this->assertSame( $before, $this->write_snapshot() );
	}
}
