<?php
/**
 * The owner's Revert, Revert all, Clear history and notice dismissal: nonce, capability, the activity
 * rows they write, and what happens when that row cannot be opened or closed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\Undo\UndoTestCase;

final class UndoAjaxTest extends UndoTestCase {

	use AjaxHarness;

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		delete_option( 'aafm_read_only_mode' );
		delete_option( 'aafm_undo_notice_dismissed' );
		parent::tear_down();
	}

	/**
	 * Make a change and return its id.
	 *
	 * @param int    $post  Post id.
	 * @param string $title New title.
	 * @return int
	 */
	private function change( int $post, string $title = 'B' ): int {
		$result = $this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => $title,
			)
		);
		$this->assertIsArray( $result );
		return (int) $result['undo']['change_ids'][0];
	}

	/**
	 * Every undo_action activity row.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function undo_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_type = %s ORDER BY id', aafm_activity_log_table(), 'undo_action' ), ARRAY_A );
	}

	/**
	 * Run a handler as an administrator with a valid nonce.
	 *
	 * @param string              $handler Handler function.
	 * @param array<string,mixed> $fields  POST fields.
	 * @return array<string,mixed>
	 */
	private function call( string $handler, array $fields ): array {
		$this->post_as( 'administrator', $fields );
		return $this->run_handler( $handler );
	}

	public function test_revert_needs_a_valid_nonce_and_logs_the_refusal(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		$this->acting_as( 'administrator' );
		$_POST             = array(
			'nonce'     => 'bad',
			'change_id' => $change,
		);
		$_REQUEST['nonce'] = 'bad';
		$this->intercept_die();

		$out = $this->run_handler( 'aafm_ajax_undo_revert' );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$rows = $this->undo_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_revert_needs_manage_options(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		$this->post_as( 'editor', array( 'change_id' => $change ) );

		$out = $this->run_handler( 'aafm_ajax_undo_revert' );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$rows = $this->undo_rows();
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_revert_checks_the_object_capability_at_click_time(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		$deny   = static function ( array $caps, string $cap, int $user, array $args ) use ( $post ): array {
			if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $post ) {
				$caps[] = 'do_not_allow';
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		$out = $this->call( 'aafm_ajax_undo_revert', array( 'change_id' => $change ) );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'denied', $this->undo_rows()[0]['status'] );
	}

	public function test_revert_writes_one_undo_action_row_with_ids_only(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'SECRET-TITLE-A' ) );
		$change = $this->change( $post, 'SECRET-TITLE-B' );
		$out    = $this->call( 'aafm_ajax_undo_revert', array( 'change_id' => $change ) );

		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		$this->assertSame( 'reverted', $out['data']['outcome'] );
		$this->assertSame( 'SECRET-TITLE-A', get_post( $post )->post_title );
		$rows = $this->undo_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'aafm/owner-revert', $rows[0]['ability'] );
		$this->assertStringContainsString( (string) $change, (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( 'SECRET', (string) $rows[0]['detail'] );
	}

	public function test_revert_refuses_and_writes_nothing_when_its_activity_row_cannot_be_opened(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		$this->post_as( 'administrator', array( 'change_id' => $change ) );
		ob_start();
		$out = QueryFaultInjector::break_query_with_real_error( 'INSERT INTO `' . aafm_activity_log_table() . '`', fn() => $this->run_handler( 'aafm_ajax_undo_revert' ) );
		ob_end_clean();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'] );
	}

	public function test_revert_reports_the_outcome_when_its_activity_row_cannot_be_finished(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		$this->post_as( 'administrator', array( 'change_id' => $change ) );
		ob_start();
		$out = QueryFaultInjector::break_query_with_real_error( 'UPDATE `' . aafm_activity_log_table() . '`', fn() => $this->run_handler( 'aafm_ajax_undo_revert' ) );
		ob_end_clean();

		$this->assertTrue( $out['success'], 'The write landed, so the answer says so.' );
		$this->assertSame( 'reverted', $out['data']['outcome'] );
		$this->assertArrayHasKey( 'note', $out['data'] );
		$this->assertSame( 'A', get_post( $post )->post_title );
	}

	public function test_revert_all_reverts_the_others_and_refuses_the_changed_one(): void {
		$one = self::factory()->post->create(
			array(
				'post_title'   => 'one',
				'post_content' => 'needle here',
			)
		);
		$two = self::factory()->post->create(
			array(
				'post_title'   => 'two',
				'post_content' => 'needle there',
			)
		);
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$rows  = $this->change_rows();
		$group = (string) $rows[0]['group_id'];
		$this->assertSame( 32, strlen( $group ) );
		wp_update_post(
			array(
				'ID'           => $two,
				'post_content' => 'edited by a human',
			)
		);

		$out = $this->call( 'aafm_ajax_undo_revert_group', array( 'group_id' => $group ) );

		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		$this->assertSame( 'needle here', get_post( $one )->post_content );
		$this->assertSame( 'edited by a human', get_post( $two )->post_content );
		$outcomes = array_column( $out['data']['results'], 'outcome' );
		$this->assertContains( 'refused', $outcomes );
		$this->assertContains( 'reverted', $outcomes );
	}

	public function test_revert_all_answers_an_error_when_every_member_was_refused(): void {
		$posts = array(
			self::factory()->post->create( array( 'post_content' => 'needle one' ) ),
			self::factory()->post->create( array( 'post_content' => 'needle two' ) ),
		);
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$group = (string) $this->change_rows()[0]['group_id'];
		foreach ( $posts as $post ) {
			wp_update_post(
				array(
					'ID'           => $post,
					'post_content' => 'edited by a human',
				)
			);
		}

		$out = $this->call( 'aafm_ajax_undo_revert_group', array( 'group_id' => $group ) );

		$this->assertFalse( $out['success'], 'Nothing reverted is not a success.' );
		$this->assertStringContainsString( 'Nothing was reverted.', $out['data']['message'] );
		$this->assertStringContainsString( aafm_undo_reason_message( 'changed_since' ), $out['data']['message'] );
		$this->assertSame( array( 'refused', 'refused' ), array_column( $out['data']['results'], 'outcome' ) );
		$rows = $this->undo_rows();
		$this->assertSame( 'error', end( $rows )['status'] );
	}

	public function test_a_change_id_sent_as_an_array_reverts_nothing(): void {
		global $wpdb;
		// An array used to count as the integer 1, so the change under test is the one with id 1.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = 1', aafm_changes_table() ) );
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$id   = $this->change( $post );
		$this->assertSame( 1, $id, 'precondition: the change has id 1.' );

		$this->post_as( 'administrator', array( 'change_id' => array( (string) $id ) ) );
		$out = $this->run_handler( 'aafm_ajax_undo_revert' );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'ready', aafm_undo_get_view( $id )['row']['status'] );
	}

	public function test_revert_all_refuses_a_malformed_group_id_and_non_sitewide_rows(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		global $wpdb;
		$group = str_repeat( 'a', 32 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_changes_table(), array( 'group_id' => $group ), array( 'id' => $change ) );

		$bad   = $this->call( 'aafm_ajax_undo_revert_group', array( 'group_id' => 'short' ) );
		$mixed = $this->call( 'aafm_ajax_undo_revert_group', array( 'group_id' => $group ) );

		$this->assertFalse( $bad['success'] );
		$this->assertFalse( $mixed['success'] );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_clear_history_empties_the_table_and_logs_it(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$this->change( $post );
		$out = $this->call( 'aafm_ajax_undo_clear_history', array() );

		$this->assertTrue( $out['success'] );
		$this->assertTrue( $out['data']['cleared'] );
		$this->assertSame( array(), $this->change_rows() );
		$this->assertSame( 'aafm/owner-clear-history', $this->undo_rows()[0]['ability'] );
	}

	public function test_clearing_the_activity_log_leaves_change_history_and_groups_untouched(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'one',
				'post_content' => 'needle',
			)
		);
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$before = $this->change_rows();
		aafm_clear_activity_log();
		$after = $this->change_rows();

		$this->assertSame( array_column( $before, 'group_id' ), array_column( $after, 'group_id' ) );
		$this->assertSame( array_column( $before, 'status' ), array_column( $after, 'status' ) );
		$this->assertNotEmpty( $post );
	}

	public function test_revert_works_in_read_only_mode_and_while_paused(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change( $post );
		update_option( 'aafm_read_only_mode', '1' );
		$out = $this->call( 'aafm_ajax_undo_revert', array( 'change_id' => $change ) );

		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		$this->assertSame( 'A', get_post( $post )->post_title );
	}

	public function test_dismissing_the_notice_needs_a_nonce(): void {
		$this->acting_as( 'administrator' );
		$_POST             = array( 'nonce' => 'bad' );
		$_REQUEST['nonce'] = 'bad';
		$this->intercept_die();
		$bad = $this->run_handler( 'aafm_ajax_undo_dismiss_notice' );
		$this->assertFalse( (bool) get_option( 'aafm_undo_notice_dismissed', false ) );

		$good = $this->call( 'aafm_ajax_undo_dismiss_notice', array() );

		$this->assertSame( array(), $bad, 'check_ajax_referer dies with -1, not JSON.' );
		$this->assertTrue( $good['success'] );
		$this->assertNotEmpty( get_option( 'aafm_undo_notice_dismissed', false ) );
	}
}
