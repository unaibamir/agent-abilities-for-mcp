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

	public function test_a_table_emptied_by_filters_says_so_and_an_empty_log_does_not(): void {
		$this->acting_as( 'administrator' );
		$this->assertStringContainsString( 'No entries match these filters.', aafm_activity_rows_html( array(), true ) );
		$this->assertStringNotContainsString( 'No activity recorded yet.', aafm_activity_rows_html( array(), true ) );
		$this->assertStringContainsString( 'No activity recorded yet.', aafm_activity_rows_html( array() ) );

		$this->assertStringContainsString( 'No activity recorded yet.', $this->render() );
		$_GET['filter'] = 'denied';
		$filtered       = $this->render();
		$this->assertStringContainsString( 'No entries match these filters.', $filtered );
		$this->assertStringNotContainsString( 'No activity recorded yet.', $filtered );
	}

	public function test_the_count_note_agrees_with_the_count(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$this->assertMatchesRegularExpression( '#aafm-count-num">1</strong> <span class="aafm-count-note">entry, #', $this->render() );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$this->assertMatchesRegularExpression( '#aafm-count-num">2</strong> <span class="aafm-count-note">entries, #', $this->render() );
		$this->assertSame( 'entry, keeping everything', aafm_activity_count_note( 1, 0 ) );
		$this->assertSame( 'entries, keeping the last 1 day', aafm_activity_count_note( 0, 1 ) );
		$this->assertSame( 'entries, keeping the last 30 days', aafm_activity_count_note( 5, 30 ) );
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

	public function test_the_connection_select_tells_two_apps_with_the_same_name_apart(): void {
		aafm_install_oauth_tables();
		$ids = array();
		foreach ( array( 1, 2 ) as $n ) {
			$client = aafm_oauth_register_client(
				array(
					'redirect_uris' => array( 'https://app' . $n . '.example/cb' ),
					'client_name'   => 'Claude',
				)
			);
			$this->assertIsArray( $client );
			$ids[] = $client['client_id'];
		}

		ob_start();
		aafm_render_activity_filters( array() );
		$html = (string) ob_get_clean();

		foreach ( $ids as $id ) {
			$this->assertStringContainsString( '>Claude (' . substr( $id, 0, 8 ) . ')</option>', $html );
		}
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

	public function test_a_failed_summary_query_renders_hyphens_not_zero_calls(): void {
		ob_start();
		aafm_render_activity_summary(
			array(
				'ok'    => false,
				'total' => 0,
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Summary unavailable.', $html );
		$this->assertStringNotContainsString( 'No calls in the last 7 days.', $html );
		$this->assertMatchesRegularExpression( '/data-summary="total">-</', $html );
		$this->assertMatchesRegularExpression( '/data-summary="denied">-</', $html );
		$this->assertMatchesRegularExpression( '/data-summary="errors">-</', $html );

		ob_start();
		aafm_render_activity_summary(
			array(
				'ok'    => true,
				'total' => 0,
			)
		);
		$this->assertMatchesRegularExpression( '/data-summary="total">0</', (string) ob_get_clean(), 'A successful empty summary still says 0.' );
	}

	/**
	 * The Live and filter state logic has no JS test runner in this repo, so these pin the source shape of
	 * each fix. They go red if the behaviour is reverted to the earlier wiring.
	 */
	public function test_admin_js_keeps_the_log_state_fixes(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );

		// Applied filters change only after the request succeeded, never when Apply or Reset is clicked.
		$this->assertDoesNotMatchRegularExpression( '/(?<!let )appliedFilters = (this\.#logFilterValues\(\)|\{\});/', substr( $js, strpos( $js, '#bindLogPaginationAndFilters() {' ) ), 'Apply and Reset must not pre-commit filters.' );
		$this->assertMatchesRegularExpression( '/return \'failed\';\s*\}.*?appliedFilters = values;/s', $js, 'appliedFilters is assigned after the failed-return.' );

		// Paging beyond page 1 stops Live before the page request starts.
		$this->assertSame( 2, substr_count( $js, 'stopLiveForPaging();' ), 'Prev (beyond page 1) and Next both stop Live.' );

		// A Live tick never supersedes a viewer's own pending Apply, Reset or paging request.
		$this->assertMatchesRegularExpression( '/if \( withSummary && userLoads > 0 \) \{\s*return \'stale\';/', $js, 'Live yields to a pending user request.' );
		$this->assertMatchesRegularExpression( '/\} finally \{\s*if \( ! withSummary \) \{\s*userLoads -= 1;/', $js, 'The pending count is released even when the request throws.' );

		// Clearing the log resets the summary card.
		$this->assertMatchesRegularExpression( '/#bindClearLog\(\).*?this\.#renderLogSummary\( \{ ok: true, total: 0/s', $js );

		// A failed summary shows a hyphen, not zero.
		$this->assertStringContainsString( "this.#t( 'summaryUnavailable'", $js );

		// The deep-link note goes with the row filter.
		$this->assertStringContainsString( "document.querySelector( '.aafm-log-row-note' )?.remove();", $js );

		// Live is switched off for a search or user filter with no date range, with a hint.
		$this->assertStringContainsString( 'appliedFilters.q || appliedFilters.user', $js );
		$this->assertStringContainsString( 'liveBtn.disabled = blocked;', $js );
		$this->assertStringContainsString( 'ponytail:', $js );

		// Both new strings reach the script through the localized bag.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$page = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/page.php' );
		$this->assertStringContainsString( "'summaryUnavailable'", $page );
		$this->assertStringContainsString( "'liveNeedsRange'", $page );
	}

	public function test_admin_js_resets_the_status_hints_a_short_search_and_follows_the_count(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );

		// Reset returns the status segment to All, so the table and the export link leave "Denied".
		$this->assertMatchesRegularExpression( "/resetBtn\\?\\.addEventListener\\( 'click'.*?load\\( 1, 'all', \\{ filters: \\{\\} \\} \\);/s", $js );
		// A one-character search is not sent; the viewer gets the browser's own validation bubble.
		$this->assertStringContainsString( "this.#t( 'searchTooShort'", $js );
		$this->assertStringContainsString( 'reportValidity()', $js );
		// The empty row and the count note come from the localized bag.
		$this->assertStringContainsString( "this.#t( 'noMatches'", $js );
		$this->assertStringContainsString( "'countNoteOne'", $js );
		$this->assertStringContainsString( "'countNoteMany'", $js );
	}
}
