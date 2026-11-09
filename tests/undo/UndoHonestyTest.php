<?php
/**
 * A revert never claims more than it did: what did not land is a failure or a named difference.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoHonestyTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	/**
	 * Run a tool and return its change id.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return int
	 */
	private function change_from( string $ability, array $input ): int {
		$result = $this->run_tool( $ability, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return (int) $result['undo']['change_ids'][0];
	}

	public function test_a_title_filter_rewriting_the_restore_gives_failed(): void {
		$post    = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change  = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$rewrite = static function ( array $data ): array {
			$data['post_title'] = 'Rewritten';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $rewrite );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'wp_insert_post_data', $rewrite );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'post_title', $result->get_error_message() );
		$this->assertSame( 'reverted', aafm_undo_get_view( $change )['row']['status'] );
		$rows = $this->change_rows();
		$this->assertSame( 'ready', end( $rows )['status'], 'The partial state is recorded and can be reverted.' );
	}

	public function test_a_meta_short_circuit_is_refused_before_any_write(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		add_filter( 'add_post_metadata', '__return_false' );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'add_post_metadata', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_meta_filtered', $result->get_error_code() );
		$this->assertSame( 'new', get_post_meta( $post, 'aafm_note', true ) );
	}

	public function test_slug_taken_since_gives_reverted_with_differences_naming_post_name(): void {
		$post   = self::factory()->post->create(
			array(
				'post_title'  => 'Slug',
				'post_name'   => 'orig-slug',
				'post_status' => 'publish',
			)
		);
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'slug'    => 'new-slug',
			)
		);
		self::factory()->post->create(
			array(
				'post_name'   => 'orig-slug',
				'post_status' => 'publish',
			)
		);

		$result = aafm_undo_apply( $change, 'owner' );

		$this->assertIsArray( $result );
		$this->assertSame( 'reverted_with_differences', $result['outcome'] );
		$this->assertSame( array( 'post_name' ), $result['differences'] );
		$this->assertSame( 'orig-slug-2', get_post( $post )->post_name );
	}

	public function test_untrash_restores_the_captured_status_not_the_trash_meta(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$change = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );
		update_post_meta( $post, '_wp_trash_meta_status', 'pending' );

		$this->assertIsArray( aafm_undo_apply( $change, 'owner' ) );

		$this->assertSame( 'publish', get_post( $post )->post_status );
	}

	public function test_a_vetoed_untrash_gives_failed_and_keeps_the_row_ready(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$change = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );
		add_filter( 'pre_untrash_post', '__return_false' );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'pre_untrash_post', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'trash', get_post( $post )->post_status );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'] );
	}

	public function test_excerpt_revert_on_a_builder_owned_post_keeps_content_byte_equal(): void {
		$post   = self::factory()->post->create(
			array(
				'post_content' => 'Builder content',
				'post_excerpt' => 'Old ex',
			)
		);
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'excerpt' => 'New ex',
			)
		);
		update_post_meta( $post, '_elementor_data', '[{"id":"el1"}]' );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );
		$rewrite = static function ( array $data ): array {
			$data['post_content'] = 'REWRITTEN BY A SAVE FILTER';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $rewrite, 5 );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'wp_insert_post_data', $rewrite, 5 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 'Builder content', get_post( $post )->post_content );
		$this->assertSame( 'Old ex', get_post( $post )->post_excerpt );
	}

	public function test_content_with_an_iframe_is_refused_before_writing_when_the_user_cannot_post_unfiltered_html(): void {
		$post   = self::factory()->post->create( array( 'post_content' => '<p>keep</p><iframe src="https://example.test/x"></iframe>' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'content' => 'plain',
			)
		);
		$strip  = static function ( array $caps ): array {
			unset( $caps['unfiltered_html'] );
			return $caps;
		};
		add_filter( 'user_has_cap', $strip );
		kses_init();
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'user_has_cap', $strip );
		kses_init();

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_would_change', $result->get_error_code() );
		$this->assertSame( 'plain', get_post( $post )->post_content );
	}

	public function test_failed_revert_leaves_a_revertable_change_row(): void {
		$post    = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change  = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$rewrite = static fn( array $data ): array => array_merge( $data, array( 'post_title' => 'Partial' ) );
		add_filter( 'wp_insert_post_data', $rewrite );
		aafm_undo_apply( $change, 'owner' );
		remove_filter( 'wp_insert_post_data', $rewrite );
		$rows = $this->change_rows();
		$new  = (int) end( $rows )['id'];

		$this->assertIsArray( aafm_undo_apply( $new, 'owner' ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}
}
