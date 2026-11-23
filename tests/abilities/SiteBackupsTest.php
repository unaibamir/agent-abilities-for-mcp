<?php
/**
 * UpdraftPlus readers, the backup set check and the two backup abilities, against the stub store
 * and real archive files in a temp directory.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SiteBackupsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_updraft_stub_activate();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';
		add_filter( 'aafm_integration_active_site_backups', '__return_true' );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_site_backups', '__return_true' );
		remove_all_filters( 'aafm_backup_gate_inline_checksum_max_bytes' );
		aafm_flush_registry_cache();
		aafm_updraft_stub_deactivate();
		parent::tear_down();
	}

	/**
	 * The check of one set, as the list tool makes it (budget 0) or as the gate makes it.
	 *
	 * @param string             $nonce    Set nonce.
	 * @param int                $age      The age the set was added with.
	 * @param int                $budget   Inline budget.
	 * @param array<mixed>       $verdicts Stored verdicts.
	 * @param array<string,bool> $accept   'remote' and 'size' switches.
	 */
	private function check( string $nonce, int $age, int $budget = 8388608, array $verdicts = array(), array $accept = array() ): string {
		return aafm_site_updraft_set_check( time() - $age, aafm_updraft_stub_entry( $nonce ), $verdicts, ! empty( $accept['remote'] ), ! empty( $accept['size'] ), $budget );
	}

	/**
	 * A stored verdict for a set, with the fingerprint its file has now.
	 *
	 * @param string $nonce   Set nonce.
	 * @param string $file    Archive path.
	 * @param string $verdict 'verified', 'failed' or 'too_large'.
	 * @return array<string,array<string,mixed>>
	 */
	private function verdict( string $nonce, string $file, string $verdict ): array {
		return array(
			$nonce => array(
				'fingerprint' => aafm_site_updraft_fingerprint( $nonce, $file ),
				'verdict'     => $verdict,
				'checked_at'  => 1795000000,
			),
		);
	}

	public function test_the_directory_is_the_stored_absolute_path(): void {
		$this->assertSame( aafm_updraft_stub_dir(), aafm_site_updraft_dir() );

		update_option( 'updraft_dir', aafm_updraft_stub_dir() . '/' );
		$this->assertSame( aafm_updraft_stub_dir(), aafm_site_updraft_dir(), 'A trailing slash is removed.' );
	}

	public function test_a_relative_directory_resolves_under_wp_content(): void {
		$want = is_dir( WP_CONTENT_DIR . '/uploads' ) ? WP_CONTENT_DIR . '/uploads' : null;

		update_option( 'updraft_dir', 'uploads' );
		$this->assertSame( $want, aafm_site_updraft_dir() );

		if ( ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
			update_option( 'updraft_dir', 'wp-content/uploads' );
			$this->assertSame( $want, aafm_site_updraft_dir(), 'The wp-content/ prefix form resolves in memory.' );
		}
	}

	public function test_an_absent_or_non_string_directory_is_the_default(): void {
		$want = is_dir( WP_CONTENT_DIR . '/updraft' ) ? WP_CONTENT_DIR . '/updraft' : null;

		delete_option( 'updraft_dir' );
		$this->assertSame( $want, aafm_site_updraft_dir() );

		update_option( 'updraft_dir', array( 'not', 'a', 'string' ) );
		$this->assertSame( $want, aafm_site_updraft_dir() );
	}

	public function test_a_directory_with_a_dot_segment_is_refused(): void {
		foreach ( array( '../outside', 'a/../b', './here', 'a/.' ) as $stored ) {
			update_option( 'updraft_dir', $stored );
			$this->assertNull( aafm_site_updraft_dir(), $stored );
		}
	}

	public function test_a_missing_directory_is_null(): void {
		update_option( 'updraft_dir', aafm_updraft_stub_dir() . '/does-not-exist' );
		$this->assertNull( aafm_site_updraft_dir() );
	}

	public function test_the_history_is_newest_first_with_integer_keys(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 7200 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 3600 );

		$history = aafm_site_updraft_history();
		$this->assertSame( array( 'aaaaaaaaaaa2', 'aaaaaaaaaaa1' ), array_column( $history, 'nonce' ) );
		$this->assertContainsOnly( 'int', array_keys( $history ) );
	}

	public function test_the_window_is_the_last_24_hours_with_five_minutes_of_slack(): void {
		$now = time();
		$this->assertTrue( aafm_site_updraft_in_window( $now ) );
		$this->assertTrue( aafm_site_updraft_in_window( $now - DAY_IN_SECONDS + 60 ) );
		$this->assertFalse( aafm_site_updraft_in_window( $now - DAY_IN_SECONDS - 60 ) );
		$this->assertTrue( aafm_site_updraft_in_window( $now + 240 ) );
		$this->assertFalse( aafm_site_updraft_in_window( $now + 360 ) );
	}

	public function test_a_good_archive_is_present_with_its_checksum_and_fingerprint(): void {
		$file    = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$archive = aafm_site_updraft_db_archive( aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ) );

		$this->assertSame( 'present', $archive['state'] );
		$this->assertSame( 2048, $archive['size'] );
		$this->assertSame( sha1( aafm_updraft_stub_read( $file ) ), $archive['sha1'] );
		$this->assertSame( 1, preg_match( '/^aaaaaaaaaaa1\|2048\|\d+\|\d+\|\d+$/', (string) $archive['fingerprint'] ) );
	}

	/**
	 * Each rule of the archive check, broken in turn.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function bad_entries(): array {
		return array(
			'nonce not 12 hex'     => array( array( 'nonce' => 'XYZ' ) ),
			'not native'           => array( array( 'native' => false ) ),
			'migration set'        => array( array( 'service' => array( 'remotesend' ) ) ),
			'migration set string' => array( array( 'service' => 'remotesend' ) ),
			'no db key'            => array( array( 'db' => null ) ),
			'db is an array'       => array( array( 'db' => array( 'backup-db.gz' ) ) ),
			'db holds a path'      => array( array( 'db' => '../backup-db.gz' ) ),
			'db-size missing'      => array( array( 'db-size' => null ) ),
			'db-size zero'         => array( array( 'db-size' => 0 ) ),
			'db-size a string'     => array( array( 'db-size' => '2048' ) ),
			'db-size differs'      => array( array( 'db-size' => 2049 ) ),
			'no checksums'         => array( array( 'checksums' => null ) ),
			'checksum not sha1'    => array( array( 'checksums' => array( 'sha1' => array( 'db0' => 'nope' ) ) ) ),
		);
	}

	/**
	 * Each broken archive rule makes the archive bad and the set failed.
	 *
	 * @dataProvider bad_entries
	 *
	 * @param array<string,mixed> $overrides Entry overrides.
	 */
	public function test_a_broken_archive_rule_is_bad_and_the_set_fails( array $overrides ): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600, 2048, $overrides );
		$entry = aafm_updraft_stub_entry( 'aaaaaaaaaaa1' );
		if ( array() === $entry ) {
			// The override replaced the nonce, so look the entry up by position.
			$entry = (array) current( aafm_site_updraft_history() );
		}

		$this->assertSame( 'bad', aafm_site_updraft_db_archive( $entry )['state'] );
		$budget = 8388608;
		$this->assertSame( 'failed', aafm_site_updraft_set_check( time() - 3600, $entry, array(), true, true, $budget ) );
	}

	public function test_a_live_job_row_makes_the_archive_bad_and_a_finished_one_does_not(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$entry = aafm_updraft_stub_entry( 'aaaaaaaaaaa1' );

		aafm_updraft_stub_job( 'aaaaaaaaaaa1', array( 'jobstatus' => 'filescreating' ) );
		$this->assertSame( 'filescreating', aafm_site_updraft_job_status( 'aaaaaaaaaaa1' ) );
		$this->assertSame( 'bad', aafm_site_updraft_db_archive( $entry )['state'] );

		aafm_updraft_stub_job( 'aaaaaaaaaaa1', array( 'jobstatus' => 'finished' ) );
		$this->assertSame( 'present', aafm_site_updraft_db_archive( $entry )['state'] );

		aafm_updraft_stub_job( 'aaaaaaaaaaa1', array( 'no' => 'status' ) );
		$this->assertSame( '', aafm_site_updraft_job_status( 'aaaaaaaaaaa1' ) );
		$this->assertSame( 'bad', aafm_site_updraft_db_archive( $entry )['state'] );

		$this->assertNull( aafm_site_updraft_job_status( 'ffffffffffff' ) );
	}

	public function test_a_deleted_archive_is_not_local_and_a_cut_one_is_bad(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		aafm_updraft_stub_truncate( $file );
		$this->assertSame( 'bad', aafm_site_updraft_db_archive( aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ) )['state'] );

		aafm_updraft_stub_delete( $file );
		$this->assertSame( 'not_local', aafm_site_updraft_db_archive( aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ) )['state'] );
	}

	public function test_an_encrypted_archive_is_compared_with_its_own_checksum(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$sha  = sha1( aafm_updraft_stub_read( $file ) );
		$dir  = aafm_updraft_stub_dir();
		// The same bytes under a .crypt name, with the two checksums an encrypted set carries.
		aafm_updraft_stub_write( $dir . '/backup-db.gz.crypt', aafm_updraft_stub_read( $file ) );
		$entry              = aafm_updraft_stub_entry( 'aaaaaaaaaaa1' );
		$entry['db']        = 'backup-db.gz.crypt';
		$entry['checksums'] = array(
			'sha1' => array(
				'db0'       => str_repeat( '0', 40 ),
				'db0.crypt' => $sha,
			),
		);

		$budget = 8388608;
		$this->assertSame( 'verified', aafm_site_updraft_set_check( time() - 3600, $entry, array(), false, false, $budget ) );

		unset( $entry['checksums']['sha1']['db0.crypt'] );
		$budget = 8388608;
		$this->assertSame( 'failed', aafm_site_updraft_set_check( time() - 3600, $entry, array(), false, false, $budget ), 'Without the .crypt checksum the set is not verified.' );
	}

	public function test_a_small_archive_is_hashed_inline_and_the_budget_shrinks(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$budget = 5000;

		$this->assertSame( 'verified', aafm_site_updraft_set_check( time() - 3600, aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ), array(), false, false, $budget ) );
		$this->assertSame( 5000 - 2048, $budget );
	}

	public function test_same_size_corruption_fails_the_inline_check(): void {
		aafm_updraft_stub_corrupt( aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 ) );
		$this->assertSame( 'failed', $this->check( 'aaaaaaaaaaa1', 3600 ) );
	}

	public function test_an_archive_over_the_budget_is_pending_or_size_only(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$this->assertSame( 'pending', $this->check( 'aaaaaaaaaaa1', 3600, 0 ) );
		$this->assertSame( 'pending', $this->check( 'aaaaaaaaaaa1', 3600, 2047 ) );
		$this->assertSame( 'size_only', $this->check( 'aaaaaaaaaaa1', 3600, 0, array(), array( 'size' => true ) ) );
	}

	public function test_a_set_outside_the_window_is_not_checked(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 2 * DAY_IN_SECONDS );
		$this->assertSame( 'not_checked', $this->check( 'aaaaaaaaaaa1', 2 * DAY_IN_SECONDS ) );
	}

	public function test_a_stored_verdict_decides_while_its_fingerprint_matches(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$this->assertSame( 'verified', $this->check( 'aaaaaaaaaaa1', 3600, 0, $this->verdict( 'aaaaaaaaaaa1', $file, 'verified' ) ) );
		$this->assertSame( 'failed', $this->check( 'aaaaaaaaaaa1', 3600, 0, $this->verdict( 'aaaaaaaaaaa1', $file, 'failed' ) ) );
		$this->assertSame( 'too_large', $this->check( 'aaaaaaaaaaa1', 3600, 0, $this->verdict( 'aaaaaaaaaaa1', $file, 'too_large' ) ) );
		$this->assertSame( 'size_only', $this->check( 'aaaaaaaaaaa1', 3600, 0, $this->verdict( 'aaaaaaaaaaa1', $file, 'too_large' ), array( 'size' => true ) ) );

		$stale                                = $this->verdict( 'aaaaaaaaaaa1', $file, 'verified' );
		$stale['aaaaaaaaaaa1']['fingerprint'] = 'aaaaaaaaaaa1|2048|1|1|1';
		$this->assertSame( 'pending', $this->check( 'aaaaaaaaaaa1', 3600, 0, $stale ), 'A verdict for another fingerprint is not honoured.' );
	}

	public function test_a_set_kept_only_off_site_is_trusted_only_when_the_owner_says_so(): void {
		aafm_updraft_stub_delete( aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600, 2048, array( 'service' => array( 's3' ) ) ) );
		$this->assertSame( 'failed', $this->check( 'aaaaaaaaaaa1', 3600 ) );
		$this->assertSame( 'trusted_unchecked', $this->check( 'aaaaaaaaaaa1', 3600, 8388608, array(), array( 'remote' => true ) ) );

		// The same without a storage method is a local set whose file is gone.
		aafm_updraft_stub_delete( aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 1800 ) );
		$this->assertSame( 'failed', $this->check( 'aaaaaaaaaaa2', 1800, 8388608, array(), array( 'remote' => true ) ) );
	}

	public function test_the_files_check_compares_sizes_only(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$this->assertSame( 'not_included', aafm_site_updraft_files_check( aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ) ) );

		aafm_updraft_stub_add_file( 'plugins.zip', 10 );
		aafm_updraft_stub_add_file( 'uploads.zip', 20 );
		aafm_updraft_stub_add_file( 'uploads2.zip', 30 );
		$entry = array_merge(
			aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ),
			array(
				'plugins'       => array( 'plugins.zip' ),
				'plugins-size'  => 10,
				'uploads'       => array( 'uploads.zip', 'uploads2.zip' ),
				'uploads-size'  => 20,
				'uploads1-size' => 30,
			)
		);
		$this->assertSame( 'size_ok', aafm_site_updraft_files_check( $entry ) );

		$entry['uploads1-size'] = 31;
		$this->assertSame( 'failed', aafm_site_updraft_files_check( $entry ) );

		$entry['uploads1-size'] = 30;
		$entry['plugins']       = array( 'gone.zip' );
		$this->assertSame( 'failed', aafm_site_updraft_files_check( $entry ), 'A listed file that is missing from a local set fails.' );

		$remote = array(
			'service'      => array( 's3' ),
			'plugins'      => array( 'gone.zip' ),
			'plugins-size' => 10,
		);
		$this->assertSame( 'not_local', aafm_site_updraft_files_check( $remote ) );
	}

	public function test_a_set_with_no_copy_anywhere_reports_storage_none_and_is_never_usable(): void {
		aafm_updraft_stub_delete( aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 ) );
		$entry = aafm_updraft_stub_entry( 'aaaaaaaaaaa1' );

		$budget  = 8388608;
		$summary = aafm_site_updraft_set_summary( time() - 3600, $entry, array(), true, true, $budget );
		$this->assertSame( 'none', $summary['storage'] );
		$this->assertSame( 'failed', $summary['database_check'] );
		$this->assertNull( aafm_site_updraft_window_walk( array(), true, true )['good'], 'The gate and site-backup-status never take it as a good backup.' );
	}

	public function test_the_summary_holds_only_the_fixed_fields(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600, 2048, array( 'service_instance_ids' => array( 's3' => array( 'secret-id' ) ) ) );
		aafm_updraft_stub_add_file( 'plugins.zip', 10 );
		$entry  = array_merge(
			aafm_updraft_stub_entry( 'aaaaaaaaaaa1' ),
			array(
				'plugins'      => array( 'plugins.zip' ),
				'plugins-size' => 10,
			)
		);
		$budget = 8388608;
		$when   = time() - 3600;

		$summary = aafm_site_updraft_set_summary( $when, $entry, array(), false, false, $budget );

		$this->assertSame(
			array(
				'started_at'     => $when,
				'scope'          => 'full',
				'entities'       => array( 'db', 'plugins' ),
				'total_bytes'    => 2058,
				'storage'        => 'local',
				'database_check' => 'verified',
				'files_check'    => 'size_ok',
				'checked_at'     => null,
			),
			$summary
		);
	}

	public function test_the_walk_returns_the_newest_good_set_and_says_what_it_waits_for(): void {
		$this->assertSame(
			array(
				'good'    => null,
				'waiting' => '',
			),
			aafm_site_updraft_window_walk( array(), false, false )
		);

		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 7200 );
		aafm_updraft_stub_corrupt( aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 3600 ) );
		$walk = aafm_site_updraft_window_walk( array(), false, false );
		$this->assertSame( time() - 7200, $walk['good']['started_at'] ?? null, 'A failed newer set is skipped.' );

		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => 0 );
		$walk = aafm_site_updraft_window_walk( array(), false, false );
		$this->assertNull( $walk['good'] );
		$this->assertSame( 'pending', $walk['waiting'] );
	}

	public function test_job_states_follow_the_resume_event_and_the_last_run(): void {
		$now = time();
		$job = static function ( float $last_run ) use ( $now ): array {
			return array(
				'jobstatus'    => 'filescreating',
				'runs_started' => array( $last_run ),
				'backup_time'  => $now - 600,
			);
		};

		aafm_updraft_stub_job( 'cccccccccc01', $job( (float) $now - 100 ) );
		$this->assertSame(
			array(
				array(
					'state'      => 'running',
					'stage'      => 'files',
					'started_at' => $now - 600,
				),
			),
			aafm_site_updraft_jobs(),
			'No resume event and a run 100 seconds ago: still running.'
		);

		aafm_updraft_stub_job( 'cccccccccc01', $job( (float) $now - 140 ) );
		$this->assertSame( 'failed', aafm_site_updraft_jobs()[0]['state'] ?? null, 'No resume event and a run 140 seconds ago: dead.' );

		wp_schedule_single_event( $now - 880, 'updraft_backup_resume', array( 1, 'cccccccccc01' ) );
		$this->assertSame( 'running', aafm_site_updraft_jobs()[0]['state'] ?? null, 'A resume event under 900 seconds overdue.' );

		wp_clear_scheduled_hook( 'updraft_backup_resume', array( 1, 'cccccccccc01' ) );
		wp_schedule_single_event( $now - 920, 'updraft_backup_resume', array( 2, 'cccccccccc01' ) );
		$this->assertSame( 'stalled', aafm_site_updraft_jobs()[0]['state'] ?? null, 'A resume event over 900 seconds overdue.' );
	}

	public function test_a_start_event_without_a_row_is_queued_and_a_finished_row_is_not_a_job(): void {
		aafm_updraft_stub_job( 'cccccccccc02', array( 'jobstatus' => 'finished' ) );
		$this->assertSame( array(), aafm_site_updraft_jobs() );

		wp_schedule_single_event(
			time() - 400,
			'updraft_backupnow_backup_database',
			array(
				array(
					'nocloud'   => 1,
					'use_nonce' => 'dddddddddd01',
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'state'      => 'queued',
					'stage'      => '',
					'started_at' => 0,
				),
			),
			aafm_site_updraft_jobs()
		);
	}

	public function test_backup_status_reports_the_good_set_and_the_gate(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$out = aafm_exec_site_backup_status();

		$this->assertIsArray( $out );
		$this->assertSame( 'updraftplus', $out['provider'] );
		$this->assertFalse( $out['running'] );
		$this->assertSame( '', $out['stage'] );
		$this->assertSame( gmdate( 'c', time() - 3600 ), $out['last_good']['started_at'] );
		$this->assertSame( 'database', $out['last_good']['scope'] );
		$this->assertSame( 'local', $out['last_good']['storage'] );
		$this->assertSame( 'verified', $out['last_good']['database_check'] );
		$this->assertSame( 'not_included', $out['last_good']['files_check'] );
		$this->assertNull( $out['last_good']['checked_at'] );
		$this->assertGreaterThanOrEqual( 3600, $out['last_good']['age_seconds'] );
		$this->assertSame(
			array(
				'on'              => false,
				'satisfied'       => true,
				'reason'          => 'ok',
				'verify_schedule' => 'not_scheduled',
			),
			$out['gate']
		);
	}

	public function test_backup_status_reports_a_running_job_and_a_dead_one(): void {
		$now = time();
		aafm_updraft_stub_job(
			'cccccccccc01',
			array(
				'jobstatus'    => 'dbcreating',
				'runs_started' => array( (float) $now - 5 ),
				'backup_time'  => $now - 5,
			)
		);
		aafm_updraft_stub_job(
			'cccccccccc02',
			array(
				'jobstatus'    => 'filescreating',
				'runs_started' => array( (float) $now - 4000 ),
				'backup_time'  => $now - 4000,
			)
		);

		$out = aafm_exec_site_backup_status();

		$this->assertTrue( $out['running'] );
		$this->assertSame( 'database', $out['stage'] );
		$this->assertTrue( $out['failed_job'] );
		$this->assertFalse( $out['queued'] );
		$this->assertFalse( $out['stalled'] );
		$this->assertNull( $out['last_good'] );
		$this->assertSame( 'no_backup', $out['gate']['reason'] );
	}

	public function test_a_history_reader_that_throws_gives_no_good_set_and_no_list(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		aafm_updraft_stub_throw( true );

		$status = aafm_exec_site_backup_status();
		$this->assertNull( $status['last_good'] );
		$this->assertFalse( $status['gate']['satisfied'] );
		$this->assertSame( 'no_backup', $status['gate']['reason'] );

		$list = aafm_exec_site_list_backups( array() );
		$this->assertWPError( $list );
		$this->assertSame( 'aafm_site_backup_no_provider', $list->get_error_code() );
	}

	public function test_without_updraftplus_status_says_none_and_the_list_is_refused(): void {
		aafm_updraft_stub_deactivate();

		$status = aafm_exec_site_backup_status();
		$this->assertSame(
			array(
				'provider'   => 'none',
				'running'    => false,
				'stage'      => '',
				'queued'     => false,
				'stalled'    => false,
				'failed_job' => false,
				'last_good'  => null,
				'gate'       => array(
					'on'              => false,
					'satisfied'       => false,
					'reason'          => 'no_provider',
					'verify_schedule' => 'not_scheduled',
				),
			),
			$status
		);

		$list = aafm_exec_site_list_backups( array() );
		$this->assertWPError( $list );
		$this->assertSame( 'aafm_site_backup_no_provider', $list->get_error_code() );
	}

	public function test_the_list_pages_newest_first_and_never_hashes(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3 * DAY_IN_SECONDS );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa2', 7200 );
		aafm_updraft_stub_corrupt( aafm_updraft_stub_add_set( 'aaaaaaaaaaa3', 3600 ) );
		$hashed = 0;
		add_action(
			'aafm_site_backup_hashed',
			static function ( int $bytes ) use ( &$hashed ): void {
				$hashed += $bytes;
			}
		);

		$out = aafm_exec_site_list_backups( array( 'per_page' => 2 ) );

		$this->assertSame( 3, $out['total'] );
		$this->assertSame( 1, $out['page'] );
		$this->assertSame( 2, $out['per_page'] );
		$this->assertSame( array( 'pending', 'pending' ), array_column( $out['backups'], 'database_check' ), 'A corrupted set still reads pending here: the list never hashes.' );
		$this->assertSame( gmdate( 'c', time() - 3600 ), $out['backups'][0]['started_at'] );
		$this->assertSame( 0, $hashed );

		$page_two = aafm_exec_site_list_backups(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( array( 'not_checked' ), array_column( $page_two['backups'], 'database_check' ) );

		$past = aafm_exec_site_list_backups( array( 'page' => 9 ) );
		$this->assertSame( array(), $past['backups'] );
		remove_all_actions( 'aafm_site_backup_hashed' );
	}

	public function test_the_list_shows_a_stored_verdict_with_its_time(): void {
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		update_option( 'aafm_backup_verify', $this->verdict( 'aaaaaaaaaaa1', $file, 'failed' ) );

		$out = aafm_exec_site_list_backups( array() );

		$this->assertSame( 'failed', $out['backups'][0]['database_check'] );
		$this->assertSame( gmdate( 'c', 1795000000 ), $out['backups'][0]['checked_at'] );
	}

	public function test_no_output_holds_a_file_name_a_path_or_a_nonce(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$text = (string) wp_json_encode( array( aafm_exec_site_backup_status(), aafm_exec_site_list_backups( array() ) ) );

		$this->assertStringNotContainsString( 'aaaaaaaaaaa1', $text );
		$this->assertStringNotContainsString( '.gz', $text );
		$this->assertStringNotContainsString( 'aafm-updraft-stub', $text );
	}

	public function test_both_output_schemas_are_the_fixed_lists(): void {
		$status = aafm_args_site_backup_status()['output_schema']['properties'];
		$this->assertSame( array( 'provider', 'running', 'stage', 'queued', 'stalled', 'failed_job', 'last_good', 'gate' ), array_keys( $status ) );
		$this->assertSame( array( 'started_at', 'age_seconds', 'scope', 'storage', 'database_check', 'files_check', 'checked_at' ), array_keys( $status['last_good']['properties'] ) );
		$this->assertSame( array( 'on', 'satisfied', 'reason', 'verify_schedule' ), array_keys( $status['gate']['properties'] ) );
		$this->assertSame( array( 'verified', 'size_only', 'trusted_unchecked' ), $status['last_good']['properties']['database_check']['enum'] );

		$list = aafm_args_site_list_backups()['output_schema']['properties'];
		$this->assertSame( array( 'backups', 'total', 'page', 'per_page' ), array_keys( $list ) );
		$this->assertSame(
			array(
				'started_at'     => 'string',
				'scope'          => 'string',
				'entities'       => 'array',
				'total_bytes'    => 'integer',
				'storage'        => 'string',
				'database_check' => 'string',
				'files_check'    => 'string',
				'checked_at'     => array( 'string', 'null' ),
			),
			array_map( static fn( array $property ) => $property['type'], $list['backups']['items']['properties'] )
		);
		$this->assertSame( array( 'verified', 'pending', 'too_large', 'size_only', 'trusted_unchecked', 'not_checked', 'failed' ), $list['backups']['items']['properties']['database_check']['enum'] );
	}

	public function test_both_abilities_are_registered_reads_with_closed_inputs(): void {
		$registry = aafm_get_abilities_registry();
		foreach ( array( 'aafm/site-backup-status', 'aafm/site-list-backups' ) as $name ) {
			$this->assertSame( 'read', $registry[ $name ]['risk'] ?? null, $name );
			$this->assertSame( 'site_backups', $registry[ $name ]['subject'] ?? null, $name );
			$args = call_user_func( $registry[ $name ]['args_builder'] );
			$this->assertFalse( $args['input_schema']['additionalProperties'], $name );
			$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'], $name );
			$this->assertStringContainsString( 'Requires the manage-options capability.', $args['description'], $name );
			$this->assertStringContainsString( 'The background check runs only while the backup gate is on.', $args['description'], $name . ': with the gate off, pending never resolves.' );
		}
		$this->assertSame( array( 'page', 'per_page' ), array_keys( aafm_args_site_list_backups()['input_schema']['properties'] ) );
	}
}
