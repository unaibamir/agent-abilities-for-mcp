<?php
/**
 * The change history table: install, retention, caps, purges and the capture switch.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class UndoStoreTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_undo_capture_off( true );
		aafm_undo_age_batch( true );
		aafm_undo_enforce_caps( true );
	}

	public function tear_down(): void {
		delete_option( 'aafm_undo_capture_off' );
		aafm_undo_capture_off( true );
		parent::tear_down();
	}

	/**
	 * Insert one change row and return its id.
	 *
	 * @param array<string,mixed> $over Column overrides.
	 * @return int
	 */
	private function seed( array $over = array() ): int {
		$row = array_merge(
			array(
				'ability'           => 'aafm/update-post',
				'source'            => 'agent',
				'principal_user_id' => 1,
				'object_type'       => 'post',
				'object_id'         => 10,
				'undo_op'           => 'restore',
				'status'            => 'ready',
				'fields'            => '["post:post_title"]',
				'before_values'     => '{"post:post_title":"Old"}',
				'before_sha256'     => hash( 'sha256', '{"post:post_title":"Old"}' ),
			),
			$over
		);
		$id  = aafm_undo_insert( $row );
		$this->assertGreaterThan( 0, $id, 'precondition: the seed row inserted.' );
		return $id;
	}

	private function row( int $id ): ?array {
		return aafm_undo_get_view( $id )['row'];
	}

	private function count_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', aafm_changes_table() ) );
	}

	/**
	 * Bulk-insert valued rows that each claim $bytes of before-values.
	 *
	 * @param int $rows  Row count.
	 * @param int $bytes Claimed snapshot_bytes per row.
	 * @return list<int> The new ids, oldest first.
	 */
	private function fill( int $rows, int $bytes ): array {
		global $wpdb;
		$first = 0;
		for ( $done = 0; $done < $rows; $done += 1000 ) {
			$values = array();
			for ( $i = 0, $n = min( 1000, $rows - $done ); $i < $n; $i++ ) {
				$values[] = $wpdb->prepare( '(%s, %s, %s, %d, %s)', 'aafm/update-post', 'ready', '{}', $bytes, gmdate( 'Y-m-d H:i:s' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (ability, status, before_values, snapshot_bytes, created_at) VALUES ', aafm_changes_table() ) . implode( ',', $values ) );
			$first = 0 === $first ? (int) $wpdb->insert_id : $first;
		}
		return range( $first, $first + $rows - 1 );
	}

	private function set_created( int $id, int $seconds_ago ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE id = %d', aafm_changes_table(), gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ), $id ) );
	}

	public function test_installer_creates_the_changes_table_and_stamps_version_seven(): void {
		$this->assertTrue( aafm_install_activity_log() );
		$this->assertTrue( aafm_activity_log_table_present( aafm_changes_table() ) );
		$this->assertTrue( aafm_activity_log_has_column( aafm_changes_table(), 'before_sha256' ) );
		$this->assertSame( '7', get_option( 'aafm_activity_log_schema_version' ) );
		$this->assertStringEndsWith( 'aafm_changes', aafm_changes_table() );
	}

	public function test_a_missing_changes_column_blocks_the_stamp(): void {
		global $wpdb;
		$table = aafm_changes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN before_sha256', $table ) );
		update_option( 'aafm_activity_log_schema_version', '6' );

		$this->assertFalse( aafm_activity_log_schema_verify() );
		$this->assertFalse( aafm_activity_log_finalize_schema() );
		$this->assertSame( '6', get_option( 'aafm_activity_log_schema_version' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );
		aafm_install_activity_log();
		$this->assertTrue( aafm_activity_log_schema_verify() );
	}

	public function test_prune_deletes_rows_older_than_thirty_days_with_log_retention_set_to_keep_forever(): void {
		update_option( 'aafm_log_retention_days', 0 );
		$old   = $this->seed();
		$fresh = $this->seed();
		$this->set_created( $old, 31 * DAY_IN_SECONDS );

		aafm_undo_prune();

		$this->assertNull( $this->row( $old ) );
		$this->assertNotNull( $this->row( $fresh ) );
		delete_option( 'aafm_log_retention_days' );
	}

	public function test_a_row_older_than_thirty_days_is_expired_without_cron(): void {
		$id = $this->seed();
		$this->assertFalse( aafm_undo_is_aged( $this->row( $id ) ) );
		$this->set_created( $id, 31 * DAY_IN_SECONDS );
		$this->assertTrue( aafm_undo_is_aged( $this->row( $id ) ) );
		$this->set_created( $id, 29 * DAY_IN_SECONDS );
		$this->assertFalse( aafm_undo_is_aged( $this->row( $id ) ) );
	}

	public function test_rendering_the_changes_tab_purges_aged_values_without_cron(): void {
		$old = $this->seed();
		$this->set_created( $old, 40 * DAY_IN_SECONDS );

		aafm_undo_age_batch();

		$this->assertNull( $this->row( $old ), 'The age batch removes aged rows, values included, with no cron run.' );
	}

	public function test_byte_cap_expires_oldest_values_and_keeps_the_rows(): void {
		$two_mib = 2 * 1024 * 1024;
		$ids     = $this->fill( 129, $two_mib ); // 258 MiB against a 256 MiB cap.

		aafm_undo_enforce_caps();

		$oldest = $this->row( $ids[0] );
		$this->assertSame( 'expired', $oldest['status'] );
		$this->assertNull( $oldest['before_values'] );
		$this->assertSame( 0, (int) $oldest['snapshot_bytes'] );
		$this->assertSame( 'ready', $this->row( $ids[1] )['status'], 'Expiry stops once the total is back under the cap.' );
		$this->assertNotNull( $this->row( $ids[1] )['before_values'] );
		$this->assertSame( 129, $this->count_rows(), 'Rows stay; only values go.' );
	}

	public function test_byte_cap_ends_under_the_cap_when_older_rows_are_small(): void {
		global $wpdb;
		$this->fill( 201, 1024 );
		$this->fill( 128, 2 * 1024 * 1024 ); // 256 MiB of newer rows plus 201 KiB of older ones.

		aafm_undo_enforce_caps();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(snapshot_bytes), 0) FROM %i', aafm_changes_table() ) );
		$this->assertLessThanOrEqual( AAFM_UNDO_MAX_SITE_BYTES, $total, 'The pass count must not leave the site over the cap.' );
	}

	public function test_clear_keeps_the_id_counter_so_a_held_id_never_names_a_later_change(): void {
		$first = $this->seed();
		aafm_undo_clear();
		$second = $this->seed();

		$this->assertGreaterThan( $first, $second );
	}

	public function test_byte_cap_skips_valueless_rows_and_terminates(): void {
		global $wpdb;
		$id = $this->seed( array( 'snapshot_bytes' => 300 * 1024 * 1024 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET before_values = NULL WHERE id = %d', aafm_changes_table(), $id ) );

		aafm_undo_enforce_caps();

		$this->assertSame( 'ready', $this->row( $id )['status'], 'A row with no values is not touched, and the loop ends.' );
	}

	public function test_byte_cap_keeps_reverted_and_undoing_statuses(): void {
		$two_mib  = 2 * 1024 * 1024;
		$reverted = $this->seed(
			array(
				'status'         => 'reverted',
				'snapshot_bytes' => $two_mib,
			)
		);
		$undoing  = $this->seed(
			array(
				'status'         => 'undoing',
				'snapshot_bytes' => $two_mib,
			)
		);
		$this->set_created( $undoing, 2 * HOUR_IN_SECONDS );
		$this->fill( 127, $two_mib );

		aafm_undo_enforce_caps();

		$this->assertSame( 'reverted', $this->row( $reverted )['status'] );
		$this->assertNull( $this->row( $reverted )['before_values'] );
		$this->assertSame( 'undoing', $this->row( $undoing )['status'] );
	}

	public function test_row_cap_evicts_valueless_rows_before_undoable_ones(): void {
		global $wpdb;
		$table = aafm_changes_table();
		$keep  = $this->seed();
		$gone  = $this->seed( array( 'status' => 'expired' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET before_values = NULL WHERE id = %d', $table, $gone ) );
		// Fill to one over the cap with cheap rows in bulk.
		$need = AAFM_UNDO_MAX_ROWS + 1 - $this->count_rows();
		for ( $done = 0; $done < $need; $done += 1000 ) {
			$chunk  = min( 1000, $need - $done );
			$values = array();
			for ( $i = 0; $i < $chunk; $i++ ) {
				$values[] = $wpdb->prepare( '(%s, %s, %s)', 'aafm/update-post', 'ready', gmdate( 'Y-m-d H:i:s' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (ability, status, created_at) VALUES ', $table ) . implode( ',', $values ) );
		}
		$this->assertGreaterThan( AAFM_UNDO_MAX_ROWS, $this->count_rows() );

		aafm_undo_enforce_caps();

		$this->assertNull( $this->row( $gone ), 'The valueless row goes first.' );
		$this->assertNotNull( $this->row( $keep ), 'An undoable row survives while a valueless one was available.' );
		$this->assertLessThanOrEqual( AAFM_UNDO_MAX_ROWS, $this->count_rows() );
	}

	public function test_a_failed_sum_read_expires_nothing(): void {
		$id = $this->seed( array( 'snapshot_bytes' => 300 * 1024 * 1024 ) );

		QueryFaultInjector::fail_query(
			'SUM(snapshot_bytes)',
			static function (): void {
				aafm_undo_enforce_caps();
			}
		);

		$this->assertSame( 'ready', $this->row( $id )['status'] );
		$this->assertNotNull( $this->row( $id )['before_values'] );
	}

	public function test_caps_run_once_per_request(): void {
		global $wpdb;
		$this->seed();
		$seen = 0;
		$tap  = static function ( string $query ) use ( &$seen ): string {
			if ( false !== strpos( $query, 'SUM(snapshot_bytes)' ) ) {
				++$seen;
			}
			return $query;
		};
		add_filter( 'query', $tap );
		aafm_undo_enforce_caps();
		aafm_undo_enforce_caps();
		remove_filter( 'query', $tap );

		$this->assertSame( 1, $seen );
		unset( $wpdb );
	}

	public function test_a_sitewide_group_that_crosses_the_byte_cap_after_its_first_child_ends_under_the_cap(): void {
		// Caps run once, after the last child: 127 fillers plus child A sit exactly at the cap, child B tips it.
		$two_mib = 2 * 1024 * 1024;
		$fillers = $this->fill( 127, $two_mib );
		$group   = str_repeat( 'a', 32 );
		$a       = $this->seed(
			array(
				'group_id'       => $group,
				'snapshot_bytes' => $two_mib,
			)
		);
		$b       = $this->seed(
			array(
				'group_id'       => $group,
				'snapshot_bytes' => $two_mib,
			)
		);

		aafm_undo_enforce_caps();

		$this->assertSame( 'expired', $this->row( $fillers[0] )['status'], 'The oldest value goes, not the group just written.' );
		$this->assertSame( 'ready', $this->row( $a )['status'] );
		$this->assertSame( 'ready', $this->row( $b )['status'] );
	}

	public function test_permanent_post_delete_nulls_values_and_keeps_terminal_statuses(): void {
		$post     = self::factory()->post->create();
		$ready    = $this->seed( array( 'object_id' => $post ) );
		$pending  = $this->seed(
			array(
				'object_id' => $post,
				'status'    => 'pending',
			)
		);
		$reverted = $this->seed(
			array(
				'object_id' => $post,
				'status'    => 'reverted',
			)
		);
		$other    = $this->seed( array( 'object_id' => $post + 1000 ) );

		wp_delete_post( $post, true );

		$this->assertSame( 'expired', $this->row( $ready )['status'] );
		$this->assertNull( $this->row( $ready )['before_values'] );
		$this->assertSame( 'expired', $this->row( $pending )['status'] );
		$this->assertSame( 'reverted', $this->row( $reverted )['status'], 'A terminal status keeps its meaning.' );
		$this->assertNull( $this->row( $reverted )['before_values'] );
		$this->assertNotNull( $this->row( $other )['before_values'], 'Another post is untouched.' );
	}

	public function test_term_delete_nulls_values(): void {
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$id   = $this->seed(
			array(
				'object_type' => 'term',
				'object_id'   => $term,
				'fields'      => '["term:name"]',
			)
		);

		wp_delete_term( $term, 'category' );

		$this->assertNull( $this->row( $id )['before_values'] );
		$this->assertSame( 'expired', $this->row( $id )['status'] );
	}

	public function test_user_delete_removes_that_users_rows(): void {
		$user  = self::factory()->user->create();
		$mine  = $this->seed( array( 'principal_user_id' => $user ) );
		$other = $this->seed( array( 'principal_user_id' => $user + 500 ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user );

		$this->assertNull( $this->row( $mine ) );
		$this->assertNotNull( $this->row( $other ) );
	}

	public function test_capture_off_reads_on_when_the_switch_row_is_unreadable(): void {
		$this->assertFalse( aafm_undo_capture_off(), 'No row means capture is on.' );
		aafm_undo_capture_off( true );

		$broken = QueryFaultInjector::break_query_with_real_error(
			'aafm_undo_capture_off',
			static function (): bool {
				wp_cache_delete( 'aafm_undo_capture_off', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				// A batched request keeps its policy rows for the rest of the request; drop them so this read hits the fault.
				aafm_policy_reset_request_state();
				return aafm_undo_capture_off();
			}
		);
		$this->assertTrue( $broken, 'An unreadable switch row reads as the capture switch being on (capture off), so nothing is recorded.' );
	}

	public function test_capture_switch_memo_is_forgotten_when_the_option_changes(): void {
		$this->assertFalse( aafm_undo_capture_off() );
		update_option( 'aafm_undo_capture_off', true );
		$this->assertTrue( aafm_undo_capture_off(), 'update_option clears the memo.' );
		delete_option( 'aafm_undo_capture_off' );
		$this->assertFalse( aafm_undo_capture_off(), 'delete_option clears the memo.' );
	}

	public function test_reset_empties_the_table(): void {
		$this->seed();
		$this->assertTrue( aafm_undo_clear() );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_reset_succeeds_when_the_changes_table_is_missing(): void {
		global $wpdb;
		$table = aafm_changes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );

		$this->assertTrue( aafm_undo_clear() );

		aafm_install_activity_log();
	}

	public function test_a_failed_insert_writes_no_value_to_the_error_log_file(): void {
		$sentinel = 'SENTINEL_' . wp_generate_password( 12, false );
		$log      = tempnam( sys_get_temp_dir(), 'aafm' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$filter   = QueryFaultInjector::real_error_filter( 'INSERT INTO', 1 );
		add_filter( 'query', $filter );
		ob_start();
		$id      = aafm_undo_insert(
			array(
				'ability'       => 'aafm/update-post',
				'object_type'   => 'post',
				'object_id'     => 5,
				'before_values' => wp_json_encode( array( 'post:post_title' => $sentinel ) ),
			)
		);
		$printed = ob_get_clean();
		remove_filter( 'query', $filter );
		ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( 0, $id );
		$this->assertStringNotContainsString( $sentinel, $printed );
		$this->assertStringNotContainsString( $sentinel, $logged );
	}

	public function test_history_from_one_test_is_gone_in_the_next_a(): void {
		$this->seed();
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_history_from_one_test_is_gone_in_the_next_b(): void {
		$this->assertSame( 0, $this->count_rows(), 'set_up clears change history.' );
	}
}
