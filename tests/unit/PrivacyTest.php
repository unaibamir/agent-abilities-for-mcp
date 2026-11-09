<?php
/**
 * Export and erase personal data for the change history and the activity log.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class PrivacyTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
	}

	/**
	 * Insert a change row directly.
	 *
	 * @param array<string,mixed> $row Columns to set.
	 * @return int
	 */
	private function change( array $row ): int {
		return aafm_undo_insert(
			array_merge(
				array(
					'ability'           => 'aafm/update-post',
					'object_type'       => 'post',
					'object_id'         => 1,
					'undo_op'           => 'restore',
					'status'            => 'ready',
					'fields'            => '["post:post_title"]',
					'before_values'     => '{"post:post_title":"x"}',
					'before_sha256'     => hash( 'sha256', '{"post:post_title":"x"}' ),
					'principal_user_id' => 0,
				),
				$row
			)
		);
	}

	/**
	 * Count rows of the change table.
	 *
	 * @return int
	 */
	private function change_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', aafm_changes_table() ) );
	}

	/**
	 * Drive an eraser until it reports done.
	 *
	 * @param callable $eraser Eraser callback.
	 * @param string   $email  Email.
	 * @return int How many calls it took.
	 */
	private function drain( callable $eraser, string $email ): int {
		$calls = 0;
		do {
			++$calls;
			$out = $eraser( $email, $calls );
		} while ( ! $out['done'] && $calls < 20 );
		return $calls;
	}

	public function test_change_exporter_returns_field_names_and_no_values(): void {
		$user = self::factory()->user->create( array( 'user_email' => 'export@example.test' ) );
		$this->change(
			array(
				'principal_user_id' => $user,
				'before_values'     => '{"post:post_title":"SENTINEL"}',
			)
		);
		$out  = aafm_privacy_export_changes( 'export@example.test', 1 );
		$json = (string) wp_json_encode( $out );

		$this->assertCount( 1, $out['data'] );
		$this->assertTrue( $out['done'] );
		$this->assertStringContainsString( 'post_title', $json );
		$this->assertStringNotContainsString( 'SENTINEL', $json );
		$this->assertStringStartsWith( 'aafm-change-', $out['data'][0]['item_id'] );
	}

	public function test_change_exporter_offers_the_same_rows_the_eraser_removes(): void {
		$user      = self::factory()->user->create( array( 'user_email' => 'both@example.test' ) );
		$other     = self::factory()->user->create();
		$post      = self::factory()->post->create( array( 'post_author' => $user ) );
		$by_author = $this->change(
			array(
				'principal_user_id' => $other,
				'object_id'         => $post,
			)
		);
		$by_email  = $this->change(
			array(
				'principal_user_id' => $other,
				'before_values'     => '{"post:post_content":"write to both@example.test"}',
			)
		);
		$this->change( array( 'principal_user_id' => $other ) );

		$out  = aafm_privacy_export_changes( 'both@example.test', 1 );
		$ids  = array_column( $out['data'], 'item_id' );
		$json = (string) wp_json_encode( $out );

		sort( $ids );
		$this->assertSame( array( 'aafm-change-' . $by_author, 'aafm-change-' . $by_email ), $ids );
		$this->assertStringNotContainsString( 'write to both', $json, 'Rows are listed, values are not.' );
	}

	public function test_an_empty_email_erases_nothing(): void {
		global $wpdb;
		$other = self::factory()->user->create();
		$this->change( array( 'principal_user_id' => $other ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			aafm_activity_log_table(),
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'event_type'        => 'ability_call',
				'principal_user_id' => 0,
				'principal_login'   => '',
				'source_ip'         => '203.0.113.9',
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$changes  = aafm_privacy_erase_changes( '', 1 );
		$activity = aafm_privacy_erase_activity( '', 1 );

		$this->assertTrue( $changes['done'] );
		$this->assertFalse( $changes['items_removed'] );
		$this->assertTrue( $activity['done'] );
		$this->assertFalse( $activity['items_removed'] );
		$this->assertSame( 1, $this->change_count() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( '203.0.113.9', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT source_ip FROM %i LIMIT 1', aafm_activity_log_table() ) ) );
	}

	public function test_change_eraser_removes_rows_by_actor_by_author_and_by_email(): void {
		$user      = self::factory()->user->create( array( 'user_email' => 'gone@example.test' ) );
		$other     = self::factory()->user->create();
		$post      = self::factory()->post->create( array( 'post_author' => $user ) );
		$by_actor  = $this->change( array( 'principal_user_id' => $user ) );
		$by_author = $this->change(
			array(
				'principal_user_id' => $other,
				'object_id'         => $post,
			)
		);
		$by_email  = $this->change(
			array(
				'principal_user_id' => $other,
				'before_values'     => '{"post:post_content":"write to gone@example.test"}',
			)
		);
		$kept      = $this->change( array( 'principal_user_id' => $other ) );

		$this->drain( 'aafm_privacy_erase_changes', 'gone@example.test' );

		$left = array_column( (array) aafm_wpdb_results( $GLOBALS['wpdb']->prepare( 'SELECT id FROM %i', aafm_changes_table() ) )['value'], 'id' );
		$this->assertSame( array( (string) $kept ), array_map( 'strval', $left ) );
		$this->assertNotEmpty( array( $by_actor, $by_author, $by_email ) );
	}

	public function test_change_eraser_removes_all_of_1200_rows_across_pages(): void {
		global $wpdb;
		$user = self::factory()->user->create( array( 'user_email' => 'many@example.test' ) );
		for ( $i = 0; $i < 1200; $i += 300 ) {
			$tuples = array();
			for ( $j = 0; $j < 300; ++$j ) {
				$tuples[] = $wpdb->prepare( "(%d, 'aafm/update-post', 'post', 1, 'restore', 'ready', '[]')", $user );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixture tuples are prepared.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (principal_user_id, ability, object_type, object_id, undo_op, status, fields) VALUES ', aafm_changes_table() ) . implode( ',', $tuples ) );
		}
		$this->assertSame( 1200, $this->change_count() );

		$calls = $this->drain( 'aafm_privacy_erase_changes', 'many@example.test' );

		$this->assertSame( 0, $this->change_count() );
		$this->assertGreaterThanOrEqual( 3, $calls );
	}

	public function test_log_eraser_by_login_blanks_all_of_1200_rows_across_pages(): void {
		global $wpdb;
		$user   = self::factory()->user->create(
			array(
				'user_email' => 'log@example.test',
				'user_login' => 'loguser',
			)
		);
		$tuples = array();
		for ( $j = 0; $j < 1200; ++$j ) {
			$tuples[] = $wpdb->prepare( "(%d, 'loguser', 'aafm/x', 'success', '10.0.0.1')", $user );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixture tuples are prepared.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (principal_user_id, principal_login, ability, status, source_ip) VALUES ', aafm_activity_log_table() ) . implode( ',', $tuples ) );

		$this->drain( 'aafm_privacy_erase_activity', 'log@example.test' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE principal_user_id = %d AND ( principal_login <> '' OR source_ip <> '' )", aafm_activity_log_table(), $user ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE principal_user_id = %d', aafm_activity_log_table(), $user ) );
		$this->assertSame( 0, $left );
		$this->assertSame( 1200, $rows, 'The rows stay; only the login and IP go.' );
	}

	public function test_change_eraser_matches_a_json_escaped_email(): void {
		$other = self::factory()->user->create();
		$this->change(
			array(
				'principal_user_id' => $other,
				'before_values'     => (string) wp_json_encode( array( 'post:post_content' => 'ping someone@example.test' ) ),
			)
		);
		$id = $this->change(
			array(
				'principal_user_id' => $other,
				'before_values'     => (string) wp_json_encode( array( 'post:post_content' => 'ping josé/x@example.test' ) ),
			)
		);
		$this->assertNotSame( 0, $id );

		$this->drain( 'aafm_privacy_erase_changes', 'josé/x@example.test' );

		$this->assertSame( 1, $this->change_count(), 'The row holding the escaped form is gone.' );
	}

	public function test_a_failed_change_export_read_is_an_error_not_an_empty_finished_export(): void {
		self::factory()->user->create( array( 'user_email' => 'readfail@example.test' ) );
		ob_start();
		$out = QueryFaultInjector::break_query_with_real_error( 'FROM `' . aafm_changes_table() . '`', fn() => aafm_privacy_export_changes( 'readfail@example.test', 1 ) );
		ob_end_clean();

		$this->assertWPError( $out );
		$this->assertSame( 'aafm_privacy_read_failed', $out->get_error_code() );
	}

	public function test_a_failed_activity_export_read_is_an_error_not_an_empty_finished_export(): void {
		$user = self::factory()->user->create( array( 'user_email' => 'logfail@example.test' ) );
		ob_start();
		$out = QueryFaultInjector::break_query_with_real_error( 'FROM `' . aafm_activity_log_table() . '`', fn() => aafm_privacy_export_activity( 'logfail@example.test', 1 ) );
		ob_end_clean();

		$this->assertWPError( $out );
		$this->assertSame( 'aafm_privacy_read_failed', $out->get_error_code() );
		$this->assertNotSame( 0, $user );
	}

	public function test_change_eraser_reports_retained_on_a_failed_query(): void {
		self::factory()->user->create( array( 'user_email' => 'fail@example.test' ) );
		ob_start();
		$out = QueryFaultInjector::break_query_with_real_error( 'DELETE FROM `' . aafm_changes_table() . '`', fn() => aafm_privacy_erase_changes( 'fail@example.test', 1 ) );
		ob_end_clean();

		$this->assertTrue( $out['items_retained'] );
		$this->assertFalse( $out['items_removed'] );
		$this->assertNotSame( array(), $out['messages'] );
	}

	public function test_log_exporter_returns_the_users_rows_with_ip(): void {
		$user = self::factory()->user->create( array( 'user_email' => 'lx@example.test' ) );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'principal_user_id' => $user,
				'principal_login'   => 'lx',
				'status'            => 'success',
			)
		);
		$out = aafm_privacy_export_activity( 'lx@example.test', 1 );

		$this->assertCount( 1, $out['data'] );
		$names = array_column( $out['data'][0]['data'], 'name' );
		$this->assertContains( 'Source IP', $names );
		$this->assertStringStartsWith( 'aafm-log-', $out['data'][0]['item_id'] );
	}

	public function test_log_eraser_blanks_login_and_ip_and_keeps_the_rows(): void {
		global $wpdb;
		$user = self::factory()->user->create( array( 'user_email' => 'le@example.test' ) );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'principal_user_id' => $user,
				'principal_login'   => 'le',
				'status'            => 'success',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_activity_log_table(), array( 'source_ip' => '10.1.1.1' ), array( 'principal_user_id' => $user ) );

		$out = aafm_privacy_erase_activity( 'le@example.test', 1 );

		$this->assertTrue( $out['items_removed'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT principal_login, source_ip FROM %i WHERE principal_user_id = %d', aafm_activity_log_table(), $user ), ARRAY_A );
		$this->assertSame(
			array(
				'principal_login' => '',
				'source_ip'       => '',
			),
			$row
		);
	}

	public function test_log_eraser_for_a_deleted_user_matches_by_login(): void {
		global $wpdb;
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'principal_user_id' => 99999,
				'principal_login'   => 'old@example.test',
				'status'            => 'success',
			)
		);

		$out = aafm_privacy_erase_activity( 'old@example.test', 1 );

		$this->assertTrue( $out['items_removed'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( '', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT principal_login FROM %i WHERE principal_user_id = 99999', aafm_activity_log_table() ) ) );
	}

	public function test_log_eraser_for_a_deleted_user_leaves_a_live_users_rows_with_the_same_login(): void {
		global $wpdb;
		$live = self::factory()->user->create( array( 'user_login' => 'shared@example.test' ) );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'principal_user_id' => $live,
				'principal_login'   => 'shared@example.test',
				'status'            => 'success',
			)
		);

		$out = aafm_privacy_erase_activity( 'shared@example.test', 1 );

		$this->assertFalse( $out['items_removed'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'shared@example.test', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT principal_login FROM %i WHERE principal_user_id = %d', aafm_activity_log_table(), $live ) ) );
	}

	public function test_log_eraser_for_an_unmatchable_deleted_user_reports_retained(): void {
		$out = aafm_privacy_erase_activity( 'nobody@example.test', 1 );

		$this->assertTrue( $out['items_retained'] );
		$this->assertNotSame( array(), $out['messages'] );
	}

	public function test_policy_text_mentions_change_history(): void {
		$this->assertStringContainsString( 'Change history', aafm_privacy_policy_change_history_text() );
		$this->assertStringContainsString( '30 days', aafm_privacy_policy_change_history_text() );
		$this->assertArrayHasKey( 'agent-abilities-for-mcp-changes', aafm_privacy_register_exporters( array() ) );
		$this->assertArrayHasKey( 'agent-abilities-for-mcp-activity', aafm_privacy_register_erasers( array() ) );
	}
}
