<?php
/**
 * The table (AJAX), the page count and the CSV export share one builder, so for any filter set they
 * must return the same rows. Review Focus 2.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class LogFilterParityTest extends TestCase {

	use AjaxHarness;

	public function tear_down(): void {
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	private function seed(): void {
		global $wpdb;
		for ( $i = 1; $i <= 30; $i++ ) {
			$id = aafm_log_activity(
				array(
					'ability'         => 0 === $i % 3 ? 'aafm/trash-post' : 'aafm/get-posts',
					'status'          => 0 === $i % 4 ? 'denied' : 'success',
					'principal_login' => 0 === $i % 2 ? 'ana' : 'bo',
					'client_id'       => 0 === $i % 5 ? 'client-a' : '',
					'detail'          => 0 === $i % 7 ? "seed-{$i} 100% done" : "seed-{$i} 1000 done",
				)
			);
			// Spread rows around a UTC day boundary.
			if ( 0 === $i % 3 ) {
				$day = '2026-03-01 23:59:59';
			} elseif ( $i % 2 ) {
				$day = '2026-03-02 00:00:00';
			} else {
				$day = '2026-03-01 12:00:00';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( aafm_activity_log_table(), array( 'created_at' => $day ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
	}

	/**
	 * Run the AJAX handler for a request and return what the page shows.
	 *
	 * @param array<string,string> $request Request fields.
	 * @return array{0:list<string>,1:int,2:int} details from the AJAX rows, the AJAX total, the AJAX page count.
	 */
	private function ajax( array $request ): array {
		$this->post_as( 'administrator', array_merge( array( 'page' => '1' ), $request ) );
		$json = $this->run_handler( 'aafm_ajax_get_log_page' );
		$this->assertTrue( $json['success'] ?? false, wp_json_encode( $json ) );
		$details = array_column( $json['data']['rows'], 'detail' );
		sort( $details );
		return array( $details, (int) $json['data']['total'], (int) $json['data']['total_pages'] );
	}

	/**
	 * Export the CSV for a request and return its detail cells.
	 *
	 * @param array<string,string> $request Request fields.
	 * @return list<string>
	 */
	private function csv_details( array $request ): array {
		ob_start();
		aafm_export_activity_csv( aafm_activity_filters_from_request( $request ) );
		$csv   = (string) ob_get_clean();
		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
		array_shift( $lines );
		$out = array();
		foreach ( $lines as $line ) {
			$cells = str_getcsv( $line, ',', '"', '' );
			$out[] = $cells[3];
		}
		sort( $out );
		return $out;
	}

	public function test_the_table_the_count_and_the_csv_agree_for_every_filter_set(): void {
		$this->seed();
		$sets = array(
			array(),
			array( 'filter' => 'denied' ),
			array(
				'filter' => 'success',
				'user'   => 'ana',
			),
			array( 'tool' => 'aafm/trash-post' ),
			array( 'conn' => 'client-a' ),
			array(
				'conn'   => 'none',
				'filter' => 'denied',
			),
			array( 'q' => '100%' ),
			array( 'q' => 'seed-1' ),
			array(
				'from' => '2026-03-01',
				'to'   => '2026-03-01',
			),
			array(
				'from' => '2026-03-02',
				'to'   => '2026-03-02',
			),
			array(
				'from' => '2026-03-01',
				'to'   => '2026-03-02',
				'user' => 'bo',
			),
		);
		foreach ( $sets as $request ) {
			$label                         = wp_json_encode( $request );
			list( $table, $total, $pages ) = $this->ajax( $request );
			$csv                           = $this->csv_details( $request );
			$this->assertSame( $total, count( $csv ), "Count equals CSV rows for {$label}." );
			if ( $total <= aafm_activity_page_size() ) {
				$this->assertSame( $table, $csv, "Table rows equal CSV rows for {$label}." );
			}
			$this->assertSame( max( 1, (int) ceil( $total / aafm_activity_page_size() ) ), $pages, "Page count for {$label}." );
		}
	}

	public function test_the_day_boundary_filter_is_exact(): void {
		$this->seed();
		list( , $total ) = $this->ajax(
			array(
				'from' => '2026-03-01',
				'to'   => '2026-03-01',
			)
		);
		$this->assertSame(
			aafm_activity_count_matching(
				array(
					'from' => '2026-03-01',
					'to'   => '2026-03-01',
				)
			),
			$total
		);
		$this->assertGreaterThan( 0, $total );
	}

	public function test_the_export_carries_the_two_new_columns_and_keeps_the_ip(): void {
		aafm_log_activity(
			array(
				'ability'   => 'aafm/get-posts',
				'status'    => 'success',
				'client_id' => 'gone-client-1234',
			)
		);
		ob_start();
		aafm_export_activity_csv( array() );
		$csv = (string) ob_get_clean();
		$this->assertStringStartsWith( 'created_at,event_type,ability,detail,status,arg_keys,principal_login,principal_user_id,source_ip,client_id,result_count,duration_ms,connection_name', $csv );
		$this->assertStringContainsString( 'Removed app (gone-cli)', $csv );
	}

	public function test_a_status_string_or_null_still_works_for_the_exporter(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'denied',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		ob_start();
		aafm_export_activity_csv( 'denied' );
		$denied = (string) ob_get_clean();
		ob_start();
		aafm_export_activity_csv( null );
		$all = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $denied, "\n" ) );
		$this->assertSame( 3, substr_count( $all, "\n" ) );
	}

	public function test_the_ajax_handler_refuses_a_user_without_manage_options(): void {
		$this->post_as( 'editor', array( 'page' => '1' ) );
		$json = $this->run_handler( 'aafm_ajax_get_log_page' );
		$this->assertFalse( $json['success'] ?? true );
	}

	public function test_the_ajax_handler_returns_the_summary_only_when_asked(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$this->post_as( 'administrator', array( 'page' => '1' ) );
		$plain = $this->run_handler( 'aafm_ajax_get_log_page' );
		$this->assertArrayNotHasKey( 'summary', $plain['data'] );

		$this->post_as(
			'administrator',
			array(
				'page'         => '1',
				'with_summary' => '1',
			)
		);
		$with = $this->run_handler( 'aafm_ajax_get_log_page' );
		$this->assertSame( 1, $with['data']['summary']['total'] );
		$this->assertSame( '100.0%', $with['data']['summary']['rate'] );
	}
}
