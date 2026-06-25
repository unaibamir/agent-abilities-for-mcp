<?php
/**
 * Activity-log retention: the table is trimmed by a day-based window so old audit
 * rows do not accumulate forever, while a retention of 0 keeps everything.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class LogPruneTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		delete_option( 'aafm_log_retention_days' );
	}

	public function tear_down(): void {
		delete_option( 'aafm_log_retention_days' );
		parent::tear_down();
	}

	/**
	 * Insert one row with an explicit created_at (UTC mysql string) and return its id.
	 *
	 * @param string $created_at UTC 'Y-m-d H:i:s' timestamp for the row.
	 * @return int
	 */
	private function seed_row_at( string $created_at ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			aafm_activity_log_table(),
			array(
				'ability'    => 'aafm/get-posts',
				'status'     => 'denied',
				'created_at' => $created_at,
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function test_prune_drops_rows_older_than_retention_window(): void {
		update_option( 'aafm_log_retention_days', 30 );

		$recent = $this->seed_row_at( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$old    = $this->seed_row_at( gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) );

		aafm_prune_activity_log();

		$remaining = array_map( 'intval', wp_list_pluck( aafm_query_activity( array( 'per_page' => 200 ) ), 'id' ) );

		$this->assertContains( $recent, $remaining, 'A row inside the window should be kept.' );
		$this->assertNotContains( $old, $remaining, 'A row older than the window should be pruned.' );
	}

	public function test_prune_keeps_everything_when_retention_is_zero(): void {
		update_option( 'aafm_log_retention_days', 0 );

		$recent = $this->seed_row_at( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$old    = $this->seed_row_at( gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );

		aafm_prune_activity_log();

		$remaining = array_map( 'intval', wp_list_pluck( aafm_query_activity( array( 'per_page' => 200 ) ), 'id' ) );

		$this->assertContains( $recent, $remaining );
		$this->assertContains( $old, $remaining, 'Retention 0 must keep every entry forever.' );
	}

	public function test_logging_does_not_prune_on_write(): void {
		// With a 1-day window, a backdated row only disappears when the prune runs,
		// never as a side effect of a later insert.
		update_option( 'aafm_log_retention_days', 1 );

		$old = $this->seed_row_at( gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		for ( $i = 0; $i < 5; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'denied',
				)
			);
		}

		$remaining = array_map( 'intval', wp_list_pluck( aafm_query_activity( array( 'per_page' => 200 ) ), 'id' ) );
		$this->assertContains( $old, $remaining, 'Writing rows must not trigger a prune.' );
	}

	public function test_schedule_and_unschedule_daily_prune_event(): void {
		aafm_unschedule_log_prune();
		$this->assertFalse( wp_next_scheduled( 'aafm_prune_activity_log_daily' ) );

		aafm_schedule_log_prune();
		$this->assertNotFalse(
			wp_next_scheduled( 'aafm_prune_activity_log_daily' ),
			'The daily prune event should be scheduled.'
		);

		aafm_unschedule_log_prune();
		$this->assertFalse(
			wp_next_scheduled( 'aafm_prune_activity_log_daily' ),
			'Deactivation should clear the daily prune event.'
		);
	}
}
