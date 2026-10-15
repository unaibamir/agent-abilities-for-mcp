<?php
/**
 * The Activity tab markup: nine columns, the summary card, the filter row, and escaping.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ActivityFiltersUiTest extends TestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		aafm_render_activity_tab();
		return (string) ob_get_clean();
	}

	public function test_headers_are_the_nine_columns_in_order(): void {
		$this->assertSame(
			array( 'Time (UTC)', 'Principal', 'Connection', 'Event', 'Detail', 'Status', 'Duration', 'IP', 'Arg keys' ),
			aafm_activity_log_headers()
		);
	}

	public function test_the_empty_row_spans_every_column(): void {
		$this->assertStringContainsString( 'colspan="9"', aafm_activity_rows_html( array() ) );
	}

	public function test_duration_formatting(): void {
		$this->assertSame( '-', aafm_format_duration( null ) );
		$this->assertSame( '0 ms', aafm_format_duration( 0 ) );
		$this->assertSame( '250 ms', aafm_format_duration( 250 ) );
		$this->assertSame( '1.4 s', aafm_format_duration( 1400 ) );
	}

	public function test_a_row_renders_connection_duration_and_ip_escaped(): void {
		$this->acting_as( 'administrator' );
		$id = aafm_log_activity(
			array(
				'ability'   => 'aafm/get-posts',
				'status'    => 'started',
				'client_id' => 'gone-client-1234',
			)
		);
		aafm_update_activity_status( $id, 'success', null, null, 1400 );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_activity_log_table(), array( 'source_ip' => '203.0.113.<b>9' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$html = aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) );

		$this->assertStringContainsString( 'Removed app (gone-cli)', $html );
		$this->assertStringContainsString( '1.4 s', $html );
		$this->assertStringContainsString( '203.0.113.&lt;b&gt;9', $html );
		$this->assertStringNotContainsString( '<b>9', $html );
		$this->assertSame( 9, substr_count( $html, '<td' ) );
	}

	public function test_the_ajax_row_data_has_a_field_for_every_new_column(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$rows = aafm_activity_rows_data( aafm_query_activity( array( 'per_page' => 1 ) ) );
		foreach ( array( 'id', 'time', 'principal', 'connection', 'ability', 'detail', 'status', 'duration', 'ip', 'arg_keys' ) as $key ) {
			$this->assertArrayHasKey( $key, $rows[0], $key );
		}
	}

	public function test_the_tab_renders_the_summary_card_and_filter_row(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'id="aafm-log-summary"', $html );
		$this->assertStringContainsString( 'data-summary="total"', $html );
		$this->assertStringContainsString( 'class="aafm-log-filters"', $html );
		foreach ( array( 'user', 'conn', 'tool', 'plugin', 'from', 'to', 'q' ) as $key ) {
			$this->assertStringContainsString( 'data-filter-key="' . $key . '"', $html, $key );
		}
		$this->assertStringContainsString( 'id="aafm-log-live"', $html );
		$this->assertSame( 9, substr_count( $html, '<th scope="col">' ) );
	}

	public function test_url_filters_prefill_the_form_and_are_escaped(): void {
		$this->acting_as( 'administrator' );
		$_GET = array(
			'user'   => '"><script>alert(1)</script>',
			'q'      => 'abc',
			'from'   => '2026-03-01',
			'filter' => 'denied',
		);
		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'value="abc"', $html );
		$this->assertStringContainsString( 'value="2026-03-01"', $html );
		$this->assertMatchesRegularExpression( '/data-filter="denied"[^>]*aria-pressed="true"/', $html );
	}

	public function test_the_row_link_filters_to_one_row_and_offers_show_all(): void {
		$this->acting_as( 'administrator' );
		$one = aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
				'detail'  => 'only-this-one',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-pages',
				'status'  => 'success',
				'detail'  => 'not-this-one',
			)
		);
		$_GET = array( 'row' => (string) $one );
		$html = $this->render();

		$this->assertStringContainsString( 'only-this-one', $html );
		$this->assertStringNotContainsString( 'not-this-one', $html );
		$this->assertStringContainsString( 'Show all', $html );
	}

	public function test_the_export_link_carries_the_applied_filters(): void {
		$this->acting_as( 'administrator' );
		$_GET = array(
			'filter' => 'denied',
			'user'   => 'ana',
			'q'      => 'abc',
		);
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/id="aafm-export-log"/', $html );
		$this->assertStringContainsString( 'filter=denied', $html );
		$this->assertStringContainsString( 'user=ana', $html );
		$this->assertStringContainsString( 'q=abc', $html );
	}

	public function test_summary_view_formats_the_rate(): void {
		$view = aafm_activity_summary_view(
			array(
				'ok'           => true,
				'total'        => 10,
				'success'      => 6,
				'error'        => 2,
				'denied'       => 2,
				'success_rate' => 60.0,
				'top_tools'    => array(
					array(
						'ability' => 'aafm/get-posts',
						'count'   => 6,
					),
				),
			)
		);
		$this->assertSame( '60.0%', $view['rate'] );
		$this->assertSame(
			'-',
			aafm_activity_summary_view(
				array(
					'ok'           => true,
					'total'        => 0,
					'success'      => 0,
					'error'        => 0,
					'denied'       => 0,
					'success_rate' => null,
					'top_tools'    => array(),
				)
			)['rate']
		);
	}

	public function test_admin_js_wires_every_hook_the_markup_exposes(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		foreach ( array( '#aafm-log-apply', '#aafm-log-reset', '#aafm-log-live', 'data-filter-key', 'with_summary', 'visibilitychange', 'LIVE_INTERVAL_MS', 'LIVE_MAX_MS', 'appliedFilters' ) as $hook ) {
			$this->assertStringContainsString( $hook, $js, $hook );
		}
		// Whole-file assertion on an ASSIGNMENT. The file mentions the word innerHTML in ten comments (VERIFIED), so a plain substring check cannot pass;
		// and a slice from the first '#bindLogPaginationAndFilters()' is the CALL at admin.js:74, which runs to the end of the file.
		$this->assertDoesNotMatchRegularExpression( '/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML\s*\(/', $js, 'The admin script builds DOM with textContent only.' );
	}
}
