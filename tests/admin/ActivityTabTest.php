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

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

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

	public function test_the_header_row_is_the_v5_six(): void {
		$html = $this->render_activity_tab();
		foreach ( array( 'Time (UTC)', 'Principal', 'Event', 'Detail', 'Status', 'Arg keys' ) as $header ) {
			$this->assertStringContainsString( '<th>' . $header, $html );
		}
		$this->assertStringNotContainsString( '<th>Ability', $html );
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
		$this->assertSame( 6, substr_count( $html, '<td' ) );
	}

	public function test_the_empty_state_spans_all_six_columns(): void {
		$this->assertStringContainsString( 'colspan="6"', aafm_activity_rows_html( array() ) );
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
