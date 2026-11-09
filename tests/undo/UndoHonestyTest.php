<?php
/**
 * A revert never claims more than it did: what did not land is a failure or a named difference.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\Support\QueryFaultInjector;

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

	public function test_a_meta_short_circuit_gives_failed_and_changes_nothing(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$before = count( $this->change_rows() );
		add_filter( 'add_post_metadata', '__return_false' );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'add_post_metadata', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'new', get_post_meta( $post, 'aafm_note', true ) );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'] );
		$this->assertCount( $before, $this->change_rows() );
	}

	public function test_a_delete_short_circuit_is_refused_before_any_write(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		add_filter( 'delete_post_metadata_by_mid', '__return_false' );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'delete_post_metadata_by_mid', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_meta_filtered', $result->get_error_code() );
		$this->assertSame( 'new', get_post_meta( $post, 'aafm_note', true ) );
	}

	public function test_a_sanitizer_that_rewrites_the_value_is_refused_before_any_write(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$upper  = static fn( $value ) => strtoupper( (string) $value );
		add_filter( 'sanitize_post_meta_aafm_note', $upper );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'sanitize_post_meta_aafm_note', $upper );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_meta_filtered', $result->get_error_code() );
		$this->assertSame( 'new', get_post_meta( $post, 'aafm_note', true ) );
	}

	public function test_a_sanitizer_that_keeps_the_value_lets_the_revert_through(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$keep   = static fn( $value ) => trim( (string) $value );
		add_filter( 'sanitize_post_meta_aafm_note', $keep );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'sanitize_post_meta_aafm_note', $keep );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'old', get_post_meta( $post, 'aafm_note', true ) );
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

	/**
	 * A one-column stand-in for AIOSEO's table, so the certification can be driven without the plugin.
	 *
	 * @param int         $post_id Post id.
	 * @param string|null $title   Stored title.
	 * @return void
	 */
	private function aioseo_table_with( int $post_id, ?string $title ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', aafm_undo_aioseo_table() ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( post_id BIGINT UNSIGNED NOT NULL, title TEXT NULL )', aafm_undo_aioseo_table() ) );
		$wpdb->insert(
			aafm_undo_aioseo_table(),
			array(
				'post_id' => $post_id,
				'title'   => $title,
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function test_aioseo_normalised_value_gives_reverted_with_differences(): void {
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post, 'Kept ' );

		$exact = aafm_undo_certify( 'post', $post, array( 'aioseo:title' => 'Kept ' ) );
		$this->assertSame( array( 'aioseo:title' ), $exact['certified'] );

		$trimmed = aafm_undo_certify( 'post', $post, array( 'aioseo:title' => 'Kept' ) );
		$this->assertSame( array(), $trimmed['missed'], 'a trimmed value is a difference, not a miss' );
		$this->assertSame( array( 'aioseo:title' ), $trimmed['differences'] );

		$other = aafm_undo_certify( 'post', $post, array( 'aioseo:title' => 'Something else' ) );
		$this->assertSame( array( 'aioseo:title' ), $other['missed'] );
	}

	public function test_an_aioseo_restore_writes_nothing_when_a_companion_value_cannot_be_read(): void {
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post, 'Keep me' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN robots_noindex TINYINT NULL, ADD COLUMN description TEXT NULL', aafm_undo_aioseo_table() ) );

		$wrote = QueryFaultInjector::fail_query( 'FROM `' . aafm_undo_aioseo_table() . '`', static fn() => aafm_undo_write_aioseo( $post, array( 'robots_noindex' => '1' ) ) );

		$this->assertFalse( $wrote, 'A failed companion read aborts before the vendor save, which would otherwise write a guessed title.' );
	}

	public function test_aioseo_post_without_a_row_flags_every_field_as_no_vendor_row(): void {
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post + 1, 'Someone else' );

		$read = aafm_undo_read_fields( 'post', $post, array( 'aioseo:title' ) );

		$this->assertSame( array( 'aioseo:title' => 'no_vendor_row' ), $read['flags'] );
	}
}
