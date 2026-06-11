<?php
/**
 * Audit-log row cap: the activity table is bounded so a deny-loop (denied rows are
 * the cheapest for an agent to generate) cannot bloat the operator's database
 * without limit.
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
	}

	/**
	 * Seed N rows directly and return their inserted ids in order.
	 *
	 * @param int $count How many rows to write.
	 * @return int[]
	 */
	private function seed_rows( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'denied',
				)
			);
		}
		return $ids;
	}

	public function test_max_rows_is_filterable(): void {
		$default = aafm_log_max_rows();
		$this->assertGreaterThan( 0, $default );

		$cb = static fn(): int => 5;
		add_filter( 'aafm_log_max_rows', $cb );
		$this->assertSame( 5, aafm_log_max_rows() );
		remove_filter( 'aafm_log_max_rows', $cb );
	}

	public function test_prune_keeps_newest_and_drops_oldest_beyond_cap(): void {
		add_filter( 'aafm_log_max_rows', static fn(): int => 5 );

		$ids = $this->seed_rows( 8 );
		aafm_prune_activity_log();

		$remaining = wp_list_pluck( aafm_query_activity( array( 'per_page' => 200 ) ), 'id' );
		$remaining = array_map( 'intval', $remaining );

		// Exactly the cap survives: the 5 newest ids, none of the 3 oldest.
		$this->assertCount( 5, $remaining );

		$newest_five  = array_slice( $ids, -5 );
		$oldest_three = array_slice( $ids, 0, 3 );
		foreach ( $newest_five as $kept ) {
			$this->assertContains( $kept, $remaining, "Newest row {$kept} was wrongly pruned." );
		}
		foreach ( $oldest_three as $dropped ) {
			$this->assertNotContains( $dropped, $remaining, "Oldest row {$dropped} should have been pruned." );
		}

		remove_all_filters( 'aafm_log_max_rows' );
	}

	public function test_prune_is_a_noop_when_under_cap(): void {
		add_filter( 'aafm_log_max_rows', static fn(): int => 100 );

		$this->seed_rows( 4 );
		aafm_prune_activity_log();

		$this->assertCount( 4, aafm_query_activity( array( 'per_page' => 200 ) ) );

		remove_all_filters( 'aafm_log_max_rows' );
	}

	public function test_logging_auto_prunes_so_the_table_stays_bounded(): void {
		// A tiny cap and a prune-on-every-insert interval so the auto-prune path runs
		// within the write loop, mimicking a chatty deny-loop on a real site.
		add_filter( 'aafm_log_max_rows', static fn(): int => 6 );
		add_filter( 'aafm_log_prune_interval', static fn(): int => 1 );

		$this->seed_rows( 30 );

		$count = count( aafm_query_activity( array( 'per_page' => 500 ) ) );
		// The table never grows far past the cap: at most cap + one prune interval.
		$this->assertLessThanOrEqual( 7, $count, 'Audit log grew unbounded despite the row cap.' );

		remove_all_filters( 'aafm_log_max_rows' );
		remove_all_filters( 'aafm_log_prune_interval' );
	}
}
