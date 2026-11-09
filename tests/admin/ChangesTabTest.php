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

	public function test_an_empty_tab_says_so(): void {
		$this->assertStringContainsString( 'No changes recorded yet', $this->render() );
	}
}
