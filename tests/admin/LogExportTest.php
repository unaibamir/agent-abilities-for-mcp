<?php
/**
 * CSV export of the activity log: header, batching past the page cap, the status filter, and the
 * formula-injection guard.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class LogExportTest extends TestCase {

	public function test_the_header_row_names_every_exported_column(): void {
		$csv = $this->capture_export();
		$this->assertStringStartsWith(
			'created_at,event_type,ability,detail,status,arg_keys,principal_login,principal_user_id,source_ip,client_id,result_count',
			$csv
		);
	}

	public function test_it_exports_every_row_past_the_two_hundred_page_cap(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
				)
			);
		}
		// 205 rows + the header line. Proves the exporter paginates past aafm_query_activity()'s
		// 200-row cap rather than silently truncating at it.
		$this->assertSame( 206, substr_count( $this->capture_export(), "\n" ) );
	}

	/**
	 * R9-3: a failed page read used to be indistinguishable from "reached the end of the
	 * table" - both came back as an empty/short page, so the export loop stopped and the
	 * download completed looking whole while everything after the failure was silently
	 * missing. Fails the SECOND page's read (of a 205-row export, so a genuine third page
	 * would otherwise follow) and proves the export ends with an unmistakable failure marker
	 * instead of quietly closing the file after only the first 200 rows.
	 */
	public function test_a_failed_page_read_marks_the_export_incomplete_instead_of_truncating_silently(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
				)
			);
		}

		$csv = QueryFaultInjector::fail_nth_query(
			'ORDER BY created_at DESC, id DESC',
			2,
			fn() => $this->capture_export()
		);

		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
		array_shift( $lines ); // Drop the header row.

		$this->assertCount( 201, $lines, 'The first (successful) page of 200 rows plus one failure-marker line.' );
		$this->assertStringContainsString( 'EXPORT INCOMPLETE', $csv, 'A failed page read must leave an unmistakable marker in the file.' );
		$this->assertStringContainsString( 'Re-run the export', $csv );
	}

	/**
	 * R9-3, the sibling failure: the export's pagination snapshot (aafm_activity_max_id_result())
	 * used to collapse a failed read to 0, the same value an empty table produces, so every page's
	 * `id <= 0` filter came back empty and the export finished looking like a genuinely empty log
	 * instead of a failed one.
	 */
	public function test_a_failed_max_id_snapshot_marks_the_export_incomplete_instead_of_looking_empty(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);

		$csv = QueryFaultInjector::fail_query(
			'SELECT MAX(id) FROM',
			fn() => $this->capture_export()
		);

		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
		array_shift( $lines ); // Drop the header row.

		$this->assertStringContainsString( 'EXPORT INCOMPLETE', $csv, 'A failed max-id snapshot must not produce a file that looks like a complete, empty export.' );
		$this->assertCount( 1, $lines, 'Only the failure marker should be present - the real row was never reachable once the snapshot failed.' );
	}

	/**
	 * The termination fix (count($rows) < 200, replacing the old `200 === $row_count` check) has
	 * to keep behaving exactly as before for an export that legitimately ends on a page boundary:
	 * an off-by-one here would either drop the last full page or spin an extra empty page into a
	 * spurious failure marker. Exactly 200 seeded rows means the first page comes back full and
	 * the second page's genuinely-empty (ok=true) result is what ends the loop.
	 */
	public function test_an_export_ending_exactly_on_a_page_boundary_completes_normally(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
				)
			);
		}

		$csv   = $this->capture_export();
		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
		array_shift( $lines ); // Drop the header row.

		$this->assertCount( 200, $lines, 'Every seeded row must be exported, no more and no fewer.' );
		$this->assertStringNotContainsString( 'EXPORT INCOMPLETE', $csv, 'A boundary-exact, fully successful export must never be flagged incomplete.' );
	}

	public function test_it_honours_the_status_filter(): void {
		aafm_log_activity(
			array(
				'ability' => 'a/ok',
				'status'  => 'success',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'a/no',
				'status'  => 'denied',
			)
		);

		$csv = $this->capture_export( 'denied' );
		$this->assertStringContainsString( 'a/no', $csv );
		$this->assertStringNotContainsString( 'a/ok', $csv );
	}

	/**
	 * A leading formula-triggering character is neutralised with a leading single quote.
	 *
	 * @dataProvider injection_prefixes
	 *
	 * @param string $prefix A spreadsheet-formula-triggering leading character.
	 */
	public function test_a_formula_prefix_is_neutralised( string $prefix ): void {
		aafm_log_activity(
			array(
				'ability' => 'a/x',
				'status'  => 'success',
				'detail'  => $prefix . 'SUM(A1)',
			)
		);
		$this->assertStringContainsString( "'" . $prefix . 'SUM(A1)', $this->capture_export() );
	}

	/**
	 * The formula-triggering leading characters a spreadsheet would otherwise execute.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function injection_prefixes(): array {
		return array(
			'equals' => array( '=' ),
			'plus'   => array( '+' ),
			'minus'  => array( '-' ),
			'at'     => array( '@' ),
		);
	}

	/**
	 * Regression test for the pagination primitive aafm_export_activity_csv() is built on:
	 * aafm_query_activity() paginated with LIMIT/OFFSET over a table that can grow between calls.
	 * A row inserted between two page fetches shifts the OFFSET window, so rows near the page
	 * boundary reappear on the next page - nothing is skipped, but a duplicate in a compliance
	 * export is still a real defect. Bounding every page to a snapshot of the highest row id taken
	 * before the first page ran (max_id) fixes it: rows inserted after the snapshot fall outside
	 * `id <= max_id` and can never appear in a page that belongs to this run.
	 *
	 * Deliberately exercises aafm_query_activity() directly rather than going through the full
	 * exporter, so the test needs no fix-specific instrumentation to reproduce the bug: it fails
	 * against the unmodified pre-fix aafm_query_activity() (which silently ignores an unknown
	 * 'max_id' key and falls back to plain OFFSET pagination) exactly as it would inside the real
	 * export loop, and passes once max_id is honoured.
	 */
	public function test_id_bounded_pagination_excludes_rows_inserted_between_pages(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
					'detail'  => 'seed-' . $i,
				)
			);
		}
		// The newest row's id (aafm_query_activity() already sorts created_at DESC, id DESC) -
		// deliberately not aafm_activity_max_id(), which is new code added by this fix and would
		// make the "fails against pre-fix code" proof depend on code that did not exist yet.
		$newest = aafm_query_activity(
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);
		$max_id = (int) ( $newest[0]['id'] ?? 0 );

		$page1 = aafm_query_activity(
			array(
				'per_page' => 200,
				'page'     => 1,
				'max_id'   => $max_id,
			)
		);

		// Simulate 3 MCP calls landing between the exporter's first and second page fetches.
		for ( $i = 0; $i < 3; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
					'detail'  => 'mid-export-' . $i,
				)
			);
		}

		$page2 = aafm_query_activity(
			array(
				'per_page' => 200,
				'page'     => 2,
				'max_id'   => $max_id,
			)
		);

		$this->assertCount( 200, $page1, 'The first page should hold every seeded row.' );
		$this->assertCount( 0, $page2, 'A second page bound to the pre-insert snapshot must find nothing - not the mid-fetch inserts, and no seed row shifted into it.' );

		$page1_ids = array_column( $page1, 'id' );
		$page2_ids = array_column( $page2, 'id' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ), 'No row id should appear on both pages.' );
	}

	/**
	 * End-to-end companion to the primitive test above: proves the real CSV output the operator
	 * downloads has no duplicate lines, not just that the underlying query is bounded correctly.
	 * aafm_activity_export_batch fires once per page and is the hook this test uses to insert
	 * rows "mid-export" without needing real concurrency; each row's detail is a unique marker so
	 * a duplicate line in the CSV is detectable without an id column (the export deliberately does
	 * not carry one). The hook itself is new, added by this fix for exactly this purpose, so this
	 * test cannot reproduce the bug against genuinely unmodified pre-fix code - there was no
	 * per-page observation point to hook into before the fix - the primitive-level test above is
	 * the one that fails against the pre-fix pagination.
	 */
	public function test_no_row_is_exported_twice_when_new_rows_arrive_mid_export(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
					'detail'  => 'seed-' . $i,
				)
			);
		}

		$inserted          = false;
		$insert_mid_export = function () use ( &$inserted ): void {
			if ( $inserted ) {
				return;
			}
			$inserted = true;
			for ( $i = 0; $i < 3; $i++ ) {
				aafm_log_activity(
					array(
						'ability' => 'aafm/get-posts',
						'status'  => 'success',
						'detail'  => 'mid-export-' . $i,
					)
				);
			}
		};
		add_action( 'aafm_activity_export_batch', $insert_mid_export );

		$csv = $this->capture_export();

		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
		array_shift( $lines ); // Drop the header row.
		$details = array_map(
			static function ( string $line ): string {
				// Mirror the exporter's own escape argument. PHP 8.4 deprecates relying on the
				// implicit default, and reading back with a different escape than fputcsv() wrote
				// with would parse a quoted field wrong the moment one contains a backslash.
				$fields = str_getcsv( $line, ',', '"', '' );
				return $fields[3] ?? ''; // detail is column index 3: created_at,event_type,ability,detail,...
			},
			$lines
		);

		$this->assertCount( 200, $details, 'Every seeded row should be exported exactly once.' );
		$this->assertSame( $details, array_values( array_unique( $details ) ), 'A row was exported more than once.' );
		$this->assertStringNotContainsString( 'mid-export-', $csv, 'Rows inserted after the export snapshot must not appear in this export.' );
	}

	public function test_a_field_with_no_formula_prefix_is_left_untouched(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$csv = $this->capture_export();
		$this->assertStringContainsString( 'aafm/get-posts', $csv );
		$this->assertStringNotContainsString( "'aafm/get-posts", $csv );
	}

	/**
	 * The admin_post handler refuses a caller without manage_options before it ever reaches the
	 * exporter - checked via the same wp_die() interception pattern ActivityTabTest and
	 * OauthRevokeAjaxTest use, so the success path's raw exit() is never invoked by a test.
	 */
	public function test_the_export_handler_refuses_a_caller_without_manage_options(): void {
		$this->acting_as( 'subscriber' );
		$this->intercept_die();

		$nonce                = wp_create_nonce( 'aafm_admin' );
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( \WPDieException::class );
		aafm_handle_export_activity_log();
	}

	/**
	 * The admin_post handler refuses a request carrying no valid nonce, even from an
	 * administrator - the capability check passes here (an administrator has manage_options),
	 * so this specifically exercises check_admin_referer(), which runs second.
	 */
	public function test_the_export_handler_refuses_a_missing_nonce(): void {
		$this->acting_as( 'administrator' );
		$this->intercept_die();

		unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->expectException( \WPDieException::class );
		aafm_handle_export_activity_log();
	}

	/**
	 * Capture aafm_export_activity_csv()'s streamed output.
	 *
	 * @param string|null $status Optional status filter, mirrored to the tested call.
	 * @return string
	 */
	private function capture_export( ?string $status = null ): string {
		ob_start();
		aafm_export_activity_csv( $status );
		return (string) ob_get_clean();
	}

	/**
	 * Route wp_die through a throwing handler so a refusal is observable in-process instead of
	 * terminating the test run. Mirrors ActivityTabTest::intercept_die().
	 *
	 * @return void
	 */
	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}
}
