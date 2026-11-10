<?php
/**
 * The Changes tab renders field names and never a value, groups a replace-sitewide call as one row,
 * and hides calls still in flight.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Undo\UndoTestCase;

final class ChangesTabTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	/**
	 * Render the tab.
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	private function render( int $page = 1 ): string {
		$_GET['paged'] = (string) $page;
		ob_start();
		aafm_render_changes_tab();
		$html = (string) ob_get_clean();
		unset( $_GET['paged'] );
		return $html;
	}

	public function test_tab_renders_field_names_and_never_a_value(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'SENTINEL-OLD-TITLE',
				'post_content' => 'SENTINEL-OLD-BODY',
			)
		);
		$this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'new',
				'content' => 'new body',
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'post_title', $html );
		$this->assertStringContainsString( 'post_content', $html );
		$this->assertStringNotContainsString( 'SENTINEL', $html );
		$this->assertStringContainsString( 'data-undo-revert=', $html );
	}

	public function test_replace_sitewide_rows_render_as_one_group(): void {
		foreach ( array( 'a', 'b', 'c' ) as $suffix ) {
			self::factory()->post->create( array( 'post_content' => 'needle ' . $suffix ) );
		}
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'data-undo-revert-group=' ) );
		$this->assertSame( 0, substr_count( $html, 'data-undo-revert="' ) );
		$this->assertStringContainsString( '3 posts changed', $html );
	}

	/**
	 * The WordPress test suite turns CREATE TABLE into a TEMPORARY table, and MySQL cannot open one twice in
	 * one statement ("Can't reopen table", error 1137), so the lookup of a group's rows is a separate query.
	 * MariaDB tolerates it, so this checks the statements rather than the engine: no query may name the
	 * changes table twice.
	 */
	public function test_no_changes_tab_query_names_the_changes_table_twice(): void {
		foreach ( array( 'a', 'b' ) as $suffix ) {
			self::factory()->post->create( array( 'post_content' => 'needle ' . $suffix ) );
		}
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$table = aafm_changes_table();
		$twice = array();
		$watch = static function ( string $query ) use ( $table, &$twice ): string {
			if ( substr_count( $query, '`' . $table . '`' ) + substr_count( $query, ' ' . $table . ' ' ) > 1 ) {
				$twice[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $watch );
		$html = $this->render();
		remove_filter( 'query', $watch );

		$this->assertSame( array(), $twice );
		$this->assertStringContainsString( '2 posts changed', $html, 'The group still renders as one row with both members.' );
	}

	public function test_rows_from_two_calls_are_never_grouped_even_after_a_log_clear_mid_call(): void {
		$post = self::factory()->post->create( array( 'post_content' => 'needle one' ) );
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		aafm_clear_activity_log();
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'needle two',
			)
		);
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'string',
				'dry_run' => false,
			)
		);
		$groups = array_unique( array_column( $this->change_rows(), 'group_id' ) );

		$this->assertCount( 2, $groups );
		$this->assertSame( 2, substr_count( $this->render(), 'data-undo-revert-group=' ) );
	}

	public function test_young_pending_rows_are_hidden_and_old_ones_say_did_not_finish(): void {
		global $wpdb;
		$young = aafm_undo_insert(
			array(
				'ability'     => 'aafm/update-post',
				'object_type' => 'post',
				'object_id'   => 1,
				'undo_op'     => 'restore',
				'status'      => 'pending',
				'fields'      => '["post:post_title"]',
			)
		);
		$old   = aafm_undo_insert(
			array(
				'ability'     => 'aafm/update-post',
				'object_type' => 'post',
				'object_id'   => 2,
				'undo_op'     => 'restore',
				'status'      => 'pending',
				'fields'      => '["post:post_title"]',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_changes_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $old ) );
		$html = $this->render();

		$this->assertStringNotContainsString( 'data-change-id="' . $young . '"', $html );
		$this->assertStringContainsString( 'data-change-id="' . $old . '"', $html );
		$this->assertStringContainsString( aafm_undo_reason_message( 'did_not_finish' ), $html );
	}

	public function test_a_sitewide_group_counts_as_one_row_in_the_pager(): void {
		foreach ( range( 1, 30 ) as $i ) {
			self::factory()->post->create( array( 'post_content' => 'needle ' . $i ) );
		}
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$listing = aafm_undo_list_for_tab( 1, 20 );

		$this->assertSame( 1, $listing['total'] );
		$this->assertGreaterThanOrEqual( 30, count( $listing['rows'] ), 'Every member of the group comes with its lead.' );
	}

	public function test_a_hostile_tool_name_or_field_never_reaches_the_page_as_markup(): void {
		aafm_undo_insert(
			array(
				'ability'       => 'aafm/"><img src=x onerror=alert(1)>',
				'object_type'   => 'post',
				'object_id'     => 5,
				'undo_op'       => 'restore',
				'status'        => 'ready',
				'fields'        => (string) wp_json_encode( array( 'meta:"><img src=x onerror=alert(2)>' ) ),
				'before_values' => '{}',
				'before_sha256' => hash( 'sha256', '{}' ),
			)
		);
		$html = $this->render();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'onerror=alert(1)>', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;img', $html );
	}

	public function test_each_revert_button_names_its_change_and_item(): void {
		$post   = self::factory()->post->create();
		$result = $this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'new',
			)
		);
		$change = (int) $result['undo']['change_ids'][0];

		$this->assertStringContainsString( 'aria-label="Revert change ' . $change . ' to Post ' . $post . '"', $this->render() );
	}

	public function test_a_create_row_does_not_show_the_fingerprint_as_a_field(): void {
		$this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'post_title', $html );
		$this->assertStringNotContainsString( 'fp:object', $html );
	}

	public function test_the_tab_says_so_when_history_is_off(): void {
		update_option( 'aafm_undo_capture_off', true );

		$this->assertStringContainsString( 'Change history is turned off', $this->render() );
	}

	public function test_the_upgrade_notice_prints_inside_the_page_wrapper_and_not_on_admin_notices(): void {
		$this->assertFalse( has_action( 'admin_notices', 'aafm_render_undo_notice' ) );

		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		$wrap   = strpos( $html, 'aafm-wrap' );
		$anchor = strpos( $html, 'wp-header-end' );
		$notice = strpos( $html, 'id="aafm-undo-notice"' );
		$nav    = strpos( $html, 'nav-tab-wrapper' );
		$this->assertNotFalse( $notice );
		$this->assertTrue( $wrap < $anchor && $anchor < $notice && $notice < $nav, 'The notice sits inside the wrapper, under the page head.' );
	}

	public function test_each_group_member_carries_its_change_id_for_the_page_to_update(): void {
		$post = self::factory()->post->create( array( 'post_content' => 'needle one' ) );
		$this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$member = (int) $this->change_rows()[0]['id'];

		$this->assertStringContainsString( '<li data-change-id="' . $member . '">', $this->render() );
		$this->assertNotSame( 0, $post );
	}

	public function test_an_empty_tab_says_so(): void {
		$this->assertStringContainsString( 'No changes recorded yet', $this->render() );
	}
}
