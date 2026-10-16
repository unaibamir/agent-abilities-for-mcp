<?php
/**
 * Activity tab renders rows including denials, escaped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ActivityTabTest extends TestCase {

	public function test_tab_lists_a_denied_row(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability'  => 'aafm/trash-post',
				'status'   => 'denied',
				'arg_keys' => array( 'post_id' ),
			)
		);

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm/trash-post', $html );
		$this->assertStringContainsString( 'denied', $html );
		$this->assertStringContainsString( 'post_id', $html );
		// Status renders inside a pill, and the presentational filter control is present.
		$this->assertStringContainsString( 'aafm-pill', $html );
		$this->assertStringContainsString( 'aafm-seg', $html );
	}

	public function test_tab_escapes_ability_names(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => '<script>x</script>',
				'status'  => 'error',
			)
		);

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_empty_log_shows_placeholder_row(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		// A clear-log control is always present; the empty state renders without fataling.
		$this->assertStringContainsString( 'aafm-clear-log', $html );
		$this->assertStringContainsString( 'aafm-log-table', $html );
	}

	/**
	 * L4: clearing the log used to leave no trace of its own clearing - an operator (or an
	 * attacker with manage_options) could wipe the audit trail without the log itself ever
	 * showing it happened. aafm_ajax_clear_log() must write one final row recording the clear,
	 * who did it, and when, so the emptied log is never completely silent about its own history.
	 */
	public function test_clearing_the_log_leaves_a_tamper_marker_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$this->assertGreaterThan( 0, aafm_activity_count(), 'Seed row should be present before clearing.' );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;

		$json = $this->run_handler( 'aafm_ajax_clear_log' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( aafm_activity_count_note( 0, aafm_log_retention_days() ), $json['data']['count_note'] ?? null );

		// Exactly one row survives: the marker, not the seeded call.
		$this->assertSame( 1, aafm_activity_count() );
		$rows = aafm_query_activity( array() );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( $admin, (int) $rows[0]['principal_user_id'] );
		$this->assertNotSame( 'aafm/get-posts', $rows[0]['ability'], 'The marker must not be mistaken for the cleared call.' );
	}

	/**
	 * The marker row is an event in its own right, not an ability call, so it has to say so in
	 * the column that exists for exactly that. The ability name stays synthetic on purpose: rows
	 * written before v5 carry no event_type, and keeping the name means old and new markers still
	 * read as the same thing in an operator's log.
	 */
	public function test_the_cleared_marker_carries_the_log_cleared_event_type(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;

		$this->run_handler( 'aafm_ajax_clear_log' );
		$rows = aafm_query_activity( array( 'per_page' => 1 ) );
		$this->assertSame( 'log_cleared', $rows[0]['event_type'] );
		$this->assertSame( 'aafm/activity-log-cleared', $rows[0]['ability'] );
	}

	/**
	 * Codex round 9, R9-8: a failed TRUNCATE used to still let the handler write the "Activity
	 * log cleared" marker and report success, leaving the original row plus a marker falsely
	 * claiming the clear happened. Both must now fail: the handler must report an error and the
	 * original row must survive untouched by any marker.
	 */
	public function test_clear_log_reports_failure_and_writes_no_marker_when_truncate_fails(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);

		global $wpdb;
		add_filter(
			'query',
			static function ( string $query ) use ( $wpdb ): string {
				return false !== strpos( $query, 'TRUNCATE TABLE `' . $wpdb->prefix . 'aafm_activity_log`' )
					? 'SELECT * FROM aafm_missing_table_for_test'
					: $query;
			}
		);
		$suppressed = $wpdb->suppress_errors( true );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$json              = $this->run_handler( 'aafm_ajax_clear_log' );

		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'A failed truncate must not report success.' );
		$this->assertSame( 1, aafm_activity_count(), 'The original row must survive; no false marker should be added.' );
		$rows = aafm_query_activity( array() );
		$this->assertSame( 'aafm/get-posts', $rows[0]['ability'], 'No tamper marker must be written over a clear that did not happen.' );
	}

	/**
	 * Codex round 10, R10-3: the round 9 fix above only faulted the TRUNCATE and left the
	 * confirming COUNT healthy, so it could not see that a get_var()-style confirming read that
	 * itself fails casts straight to `(int) null === 0` - the same "unreadable, so call it empty"
	 * mistake the TRUNCATE fix was meant to close, one query later. Faulting the TRUNCATE and its
	 * confirming COUNT together must still report failure and write no marker, not a false success
	 * from an unreadable table looking exactly like a genuinely empty one.
	 */
	public function test_clear_log_reports_failure_and_writes_no_marker_when_truncate_and_its_confirming_count_both_fail(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);

		global $wpdb;
		add_filter(
			'query',
			static function ( string $query ) use ( $wpdb ): string {
				$is_truncate = false !== strpos( $query, 'TRUNCATE TABLE `' . $wpdb->prefix . 'aafm_activity_log`' );
				$is_count    = false !== strpos( $query, 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'aafm_activity_log`' );
				return ( $is_truncate || $is_count ) ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		$suppressed = $wpdb->suppress_errors( true );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$json              = $this->run_handler( 'aafm_ajax_clear_log' );

		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'A truncate whose confirming count also fails must not report success.' );
		$this->assertSame( 1, aafm_activity_count(), 'The original row must survive; no false marker should be added.' );
		$rows = aafm_query_activity( array() );
		$this->assertSame( 'aafm/get-posts', $rows[0]['ability'], 'No tamper marker must be written when the clear could never be confirmed.' );
	}

	public function test_the_header_row_keeps_the_v5_labels(): void {
		$html = $this->render_activity_tab();
		foreach ( array( 'Time (UTC)', 'Principal', 'Connection', 'Event', 'Detail', 'Status', 'Duration', 'IP', 'Arg keys' ) as $header ) {
			$this->assertStringContainsString( '<th scope="col">' . $header, $html );
		}
		$this->assertStringNotContainsString( '<th scope="col">Ability', $html );
	}

	/**
	 * The acceptance test from 146 section 7.3, written against the exact shape of the rows
	 * already sitting in the operator's bench log: no event_type, no detail supplied. Such a row
	 * still has to render, with the detail cell simply empty.
	 */
	public function test_a_legacy_row_renders_with_an_empty_detail_cell(): void {
		// Exactly the shape of a row written before v5: no event_type, no detail supplied.
		aafm_log_activity(
			array(
				'ability'  => 'aafm/get-posts',
				'status'   => 'success',
				'arg_keys' => array( 'per_page' ),
			)
		);

		$html = aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) );
		$this->assertStringContainsString( 'aafm/get-posts', $html );
		$this->assertStringContainsString( 'per_page', $html );
		$this->assertSame( 9, substr_count( $html, '<td' ) );
	}

	public function test_the_empty_state_spans_all_nine_columns(): void {
		$this->assertStringContainsString( 'colspan="9"', aafm_activity_rows_html( array() ) );
	}

	public function test_a_detail_bearing_row_renders_its_detail(): void {
		aafm_log_activity(
			array(
				'ability'    => 'aafm/create-page',
				'status'     => 'success',
				'event_type' => 'ability_call',
				'detail'     => 'Created page #482',
			)
		);
		$this->assertStringContainsString( 'Created page #482', aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) ) );
	}

	/**
	 * F2: a post detail's identifier links to that post's edit screen.
	 *
	 * The detail string is built through the real result builder against the real return shape
	 * of aafm_exec_create_page(), never hand-typed - a hand-built fixture would still pass even
	 * if aafm_build_activity_detail_from_result() were broken, which is exactly how the bare
	 * result_id bug survived an earlier draft of this feature undetected.
	 */
	public function test_a_post_detail_links_to_the_edit_screen(): void {
		$this->acting_as( 'administrator' );
		$result = aafm_exec_create_page(
			array(
				'title'   => 'Some Page',
				'content' => 'body',
			)
		);
		$this->assertIsArray( $result, 'create-page returned an error; fix the input before asserting on the link.' );
		$detail = aafm_build_activity_detail_from_result( 'aafm/create-page', $result );
		$this->assertNotNull( $detail, 'The builder produced no detail; the linkification test is meaningless without one.' );

		aafm_log_activity(
			array(
				'ability' => 'aafm/create-page',
				'status'  => 'success',
				'detail'  => $detail,
			)
		);

		$html = aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) );
		$this->assertStringContainsString( 'post.php?post=' . (int) $result['post']['id'], $html );
		$this->assertStringContainsString( '<a href=', $html );
	}

	/**
	 * A detail that names an object which no longer exists (or that get_edit_post_link() refuses)
	 * must never render a dead link - the identifier stays as plain text.
	 */
	public function test_a_deleted_object_renders_plain_text_not_a_dead_link(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/create-page',
				'status'  => 'success',
				'detail'  => 'Created page #999999',
			)
		);

		$html = aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) );
		$this->assertStringContainsString( 'Created page #999999', $html );
		$this->assertStringNotContainsString( '<a', $html );
	}

	/**
	 * An ability the detail map declares no `link` for is never linkified, even when its detail
	 * string happens to contain something that looks like an id.
	 */
	public function test_an_ability_with_no_link_declaration_is_never_linkified(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
				'detail'  => 'Saw post #12',
			)
		);

		$html = aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) );
		$this->assertStringContainsString( 'Saw post #12', $html );
		$this->assertStringNotContainsString( '<a', $html );
	}

	/**
	 * Regression test for the linkify bug where the id pattern was matched against the
	 * already-escaped detail string. esc_html() turns an apostrophe into `&#039;`, and `#039`
	 * satisfies `/#(\d+)/` just as well as a real id - since that entity sits earlier in the
	 * string than the real "#<id>", the old code matched it first every time, regardless of what
	 * the real id was. Depending on whether a post with id 39 happened to exist, the result was
	 * either a link to the wrong post or no link at all (str_replace found no literal "#39" to
	 * replace unless the real id happened to start with "39"). Matching against the raw string
	 * fixes both: the link always points at the real id, and the visible text is never mangled.
	 */
	public function test_a_detail_with_an_apostrophe_links_the_real_id_not_an_escaped_entity_fragment(): void {
		$this->acting_as( 'administrator' );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		aafm_log_activity(
			array(
				'ability' => 'aafm/create-page',
				'status'  => 'success',
				'detail'  => "Updated the site's page #{$page_id}",
			)
		);

		$html = aafm_activity_rows_html( aafm_query_activity( array( 'per_page' => 1 ) ) );

		// The apostrophe survives, escaped, ahead of the real id in the string.
		$this->assertStringContainsString( 'Updated the site&#039;s page', $html );
		// The link points at the real id...
		$this->assertStringContainsString( 'post.php?post=' . $page_id, $html );
		// ...and the visible identifier is the real id, not a mangled or truncated fragment.
		$this->assertStringContainsString( '>#' . $page_id . '<', $html );
	}

	/**
	 * Regression test for the gap that shipped in 5576dac: the activity table's header declared
	 * six columns (Time, Principal, Event, Detail, Status, Arg keys) but aafm_activity_rows_data()
	 * - the shape admin.js's #renderLogRows() builds AJAX-refreshed rows from - never carried a
	 * 'detail' field, so the JS renderer built only five cells and every column after Event
	 * rendered one header off. Pins one field per header column so a future column change cannot
	 * silently desync the AJAX row shape from the header again.
	 */
	public function test_the_ajax_row_data_carries_one_field_per_header_column(): void {
		aafm_log_activity(
			array(
				'ability'    => 'aafm/create-page',
				'status'     => 'success',
				'event_type' => 'ability_call',
				'detail'     => 'Created page #482',
				'arg_keys'   => array( 'title' ),
			)
		);

		$rows = aafm_activity_rows_data( aafm_query_activity( array( 'per_page' => 1 ) ) );
		$this->assertCount( 1, $rows );

		// One field per header column - Time, Principal, Connection, Event, Detail, Status, Duration, IP, Arg keys.
		foreach ( array( 'time', 'principal', 'connection', 'ability', 'detail', 'status', 'duration', 'ip', 'arg_keys' ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$rows[0],
				"aafm_activity_rows_data() is missing the '{$key}' field admin.js needs to render that column."
			);
		}
		$this->assertSame( 'Created page #482', $rows[0]['detail'] );
	}

	/**
	 * Posting filter=started through the real AJAX handler must return only the started rows.
	 * Without this, the handler's own allowlist coerces the unknown value to 'all' and the
	 * operator silently gets every row - the JS/PHP boundary class that shipped in 1.6.0 past
	 * every gate.
	 */
	public function test_the_ajax_page_handler_honours_the_started_filter_end_to_end(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'started',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
			)
		);

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$_POST['filter']   = 'started';

		$json = $this->run_handler( 'aafm_ajax_get_log_page' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( 'started', $json['data']['filter'] ?? null, 'The handler must echo the filter it honoured, not a coerced all.' );
		$this->assertSame( 1, $json['data']['total'] ?? null );
		$this->assertCount( 1, $json['data']['rows'] ?? array() );
		$this->assertSame( 'started', $json['data']['rows'][0]['status'] ?? null );
	}

	public function test_the_ajax_page_handler_says_whether_filters_emptied_the_table(): void {
		$this->acting_as( 'administrator' );
		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;

		$this->assertFalse( $this->run_handler( 'aafm_ajax_get_log_page' )['data']['filtered'] ?? null, 'No filter, so an empty log is just empty.' );

		$_POST['filter'] = 'denied';
		$this->assertTrue( $this->run_handler( 'aafm_ajax_get_log_page' )['data']['filtered'] ?? null );
	}

	public function test_the_ajax_page_handler_sends_the_count_note_pluralized_for_the_count(): void {
		$this->acting_as( 'administrator' );
		for ( $i = 0; $i < 2; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
				)
			);
		}
		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;

		// A language with a third plural form: the script only knows one and many, so the word has to come from the server.
		$few = static function ( $translation, $single, $plural, $number ) {
			return 'entry' === $single && 2 === $number ? 'few-form' : $translation;
		};
		add_filter( 'ngettext', $few, 10, 4 );
		try {
			$json     = $this->run_handler( 'aafm_ajax_get_log_page' );
			$expected = aafm_activity_count_note( 2, aafm_log_retention_days() );
		} finally {
			remove_filter( 'ngettext', $few, 10 );
		}

		$this->assertSame( 2, $json['data']['total'] ?? null );
		$this->assertSame( $expected, $json['data']['count_note'] ?? null );
		$this->assertStringStartsWith( 'few-form', (string) ( $json['data']['count_note'] ?? '' ) );
	}

	public function test_the_ajax_page_handler_ignores_an_array_page_parameter(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$_POST['page']     = array( 'x' );

		$json = $this->run_handler( 'aafm_ajax_get_log_page' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ), 'An array page is ignored, not a PHP warning.' );
		$this->assertSame( 1, $json['data']['page'] ?? null );
	}

	/**
	 * Render the activity tab as an administrator and return its markup.
	 *
	 * @return string
	 */
	private function render_activity_tab(): string {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_activity_tab();
		return (string) ob_get_clean();
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 * Mirrors the pattern in BridgeDirectorySaveTest / OauthRevokeAjaxTest.
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

	/**
	 * Run an AJAX handler and return its captured JSON payload.
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}
}
