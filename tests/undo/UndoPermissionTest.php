<?php
/**
 * What an agent connection may list and undo: its own changes from the last 24 hours, and only what the
 * forward tool would still let it do today.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoPermissionTest extends UndoTestCase {

	private const TOOLS = array( 'aafm/update-post', 'aafm/create-post', 'aafm/trash-post', 'aafm/update-post-meta', 'aafm/undo-change', 'aafm/list-changes' );

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
		$this->register_enabled( self::TOOLS );
		aafm_oauth_current_client_id( 'client-a' );
	}

	public function tear_down(): void {
		aafm_oauth_current_client_id( null, true );
		delete_option( 'aafm_force_draft' );
		remove_all_filters( 'aafm_trash_is_enabled' );
		parent::tear_down();
	}

	/**
	 * Run a tool on the current connection.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return mixed
	 */
	private function call( string $ability, array $input ) {
		return wp_get_ability( $ability )->execute( $input );
	}

	/**
	 * Edit a post title as the agent and return the change id.
	 *
	 * @param int    $post  Post id.
	 * @param string $title New title.
	 * @return int
	 */
	private function edit( int $post, string $title = 'B' ): int {
		$result = $this->call(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => $title,
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return (int) $result['undo']['change_ids'][0];
	}

	/**
	 * Undo as the agent and return the error code, or 'ok'.
	 *
	 * @param int $change_id Change id.
	 * @return string
	 */
	private function undo_code( int $change_id ): string {
		$result = $this->call( 'aafm/undo-change', array( 'change_id' => $change_id ) );
		return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
	}

	public function test_own_change_undoes(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );

		$result = $this->call( 'aafm/undo-change', array( 'change_id' => $change ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'reverted', $result['outcome'] );
		$this->assertSame( array( 'post_title' ), $result['fields'] );
		$this->assertFalse( $result['undo']['undoable'] );
		$this->assertSame( 'needs_owner', $result['undo']['reason'] );
		$this->assertSame( 'A', get_post( $post )->post_title );
	}

	public function test_a_foreign_row_answers_the_same_as_a_missing_id(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );
		aafm_oauth_current_client_id( 'client-b' );

		$foreign = $this->call( 'aafm/undo-change', array( 'change_id' => $change ) );
		$missing = $this->call( 'aafm/undo-change', array( 'change_id' => 999999 ) );

		$this->assertWPError( $foreign );
		$this->assertSame( $missing->get_error_code(), $foreign->get_error_code() );
		$this->assertSame( $missing->get_error_message(), $foreign->get_error_message() );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_an_owner_sourced_row_answers_the_same_as_a_missing_id(): void {
		global $wpdb;
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_changes_table(), array( 'source' => 'owner' ), array( 'id' => $change ) );

		$this->assertSame( $this->undo_code( 999999 ), $this->undo_code( $change ) );
	}

	public function test_two_unidentified_connections_of_one_user_cannot_undo_each_other(): void {
		aafm_oauth_current_client_id( null, true );
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );

		$this->assertSame( $this->undo_code( 999999 ), $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_older_than_24_hours_is_refused(): void {
		global $wpdb;
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_changes_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS - 60 ) ), array( 'id' => $change ) );

		$this->assertSame( 'aafm_undo_window_passed', $this->undo_code( $change ) );
	}

	public function test_an_undo_row_is_refused_as_needs_owner(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );
		$result = $this->call( 'aafm/undo-change', array( 'change_id' => $change ) );

		$this->assertSame( 'aafm_undo_needs_owner', $this->undo_code( (int) $result['new_change_id'] ) );
	}

	public function test_forward_tool_disabled_since_is_refused(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );
		update_option( 'aafm_enabled_abilities', array( 'aafm/undo-change' ) );

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_meta_key_denied_since_is_refused(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$result = $this->call(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$change = (int) $result['undo']['change_ids'][0];
		update_option( 'aafm_allowed_meta_keys', array() );

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( 'new', get_post_meta( $post, 'aafm_note', true ) );
	}

	public function test_admin_connection_cannot_restore_markup_the_forward_tool_strips(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( 'post_title' => '<b>tagged</b> title' ), array( 'ID' => $post ) );
		clean_post_cache( $post );
		$change = $this->edit( $post );

		$this->assertSame( 'aafm_undo_needs_owner', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_own_create_undoes_to_the_trash_when_the_trash_tool_is_enabled(): void {
		$result = $this->call( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$post = (int) $result['post']['id'];

		$undo = $this->call( 'aafm/undo-change', array( 'change_id' => (int) $result['undo']['change_ids'][0] ) );

		$this->assertIsArray( $undo, is_wp_error( $undo ) ? $undo->get_error_message() : '' );
		$this->assertSame( 'trash', get_post( $post )->post_status );
	}

	public function test_create_undo_without_the_trash_tool_is_refused_as_needs_owner(): void {
		$result = $this->call( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		update_option( 'aafm_enabled_abilities', array( 'aafm/undo-change', 'aafm/create-post' ) );

		$this->assertSame( 'aafm_undo_needs_owner', $this->undo_code( (int) $result['undo']['change_ids'][0] ) );
		$this->assertNotSame( 'trash', get_post( $post )->post_status );
	}

	public function test_untrash_to_publish_under_force_draft_is_refused_as_status_coerced(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result = $this->call( 'aafm/trash-post', array( 'post_id' => $post ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		update_option( 'aafm_force_draft', '1' );

		$this->assertSame( 'aafm_undo_status_coerced', $this->undo_code( (int) $result['undo']['change_ids'][0] ) );
		$this->assertSame( 'trash', get_post( $post )->post_status );
	}

	public function test_31_refused_calls_with_guessed_ids_hit_the_limit(): void {
		$codes = array();
		for ( $i = 1; $i <= 31; ++$i ) {
			$codes[] = $this->undo_code( 900000 + $i );
		}

		$this->assertSame( 'aafm_undo_rate_limited', $codes[30] );
		$this->assertCount( 1, array_unique( array_slice( $codes, 0, 30 ) ), 'Calls 1 to 30 answer alike.' );
		$this->assertNotSame( 'aafm_undo_rate_limited', $codes[29] );
	}

	public function test_the_rate_limit_message_says_how_many_calls_and_over_what_window(): void {
		$message = aafm_undo_reason_message( 'rate_limited' );

		$this->assertStringContainsString( (string) AAFM_UNDO_AGENT_LIMIT, $message );
		$this->assertStringContainsString( 'hour', $message );
	}

	public function test_undo_change_refuses_and_writes_nothing_when_its_activity_row_could_not_be_opened(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->edit( $post );
		aafm_undo_current_row_id( null, true );
		$ability = wp_get_ability( 'aafm/undo-change' );
		// Called without the decorator, no activity row was opened, so no row id is on record.
		$result = aafm_exec_undo_change( array( 'change_id' => $change ) );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_not_logged', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertNotNull( $ability );
	}

	public function test_list_changes_shows_own_changes_with_field_names_only(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'SENTINEL-A' ) );
		$this->edit( $post, 'SENTINEL-B' );

		$result = $this->call(
			'aafm/list-changes',
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertCount( 1, $result['changes'] );
		$this->assertSame( array( 'post_title' ), $result['changes'][0]['fields'] );
		$this->assertTrue( $result['changes'][0]['undoable'] );
		$this->assertStringNotContainsString( 'SENTINEL', (string) wp_json_encode( $result ) );
	}

	public function test_list_changes_says_needs_owner_when_undo_change_is_not_reachable(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$this->edit( $post );
		update_option( 'aafm_enabled_abilities', array( 'aafm/list-changes' ) );

		$result = aafm_exec_list_changes(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);

		$this->assertFalse( $result['changes'][0]['undoable'] );
		$this->assertSame( 'needs_owner', $result['changes'][0]['reason'] );
	}

	public function test_list_changes_returns_nothing_for_another_connection(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$this->edit( $post );
		aafm_oauth_current_client_id( 'client-b' );

		$result = aafm_exec_list_changes(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);

		$this->assertSame( array(), $result['changes'] );
	}
}
