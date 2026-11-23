<?php
/**
 * A revert never claims more than it did: what did not land is a failure or a named difference.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\Support\QueryFaultInjector;

final class UndoHonestyTest extends UndoTestCase {

	use IntegrationStubs;

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

	public function test_a_plugin_rewriting_the_restored_row_as_it_is_added_is_never_reported_as_reverted(): void {
		global $wpdb;
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change  = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$rewrite = static function ( $meta_id ) use ( $wpdb ): void {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'HOSTILE' ), array( 'meta_id' => $meta_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture write.
		};
		add_action( 'added_post_meta', $rewrite );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_action( 'added_post_meta', $rewrite );
		wp_cache_delete( $post, 'post_meta' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'plugin', $result->get_error_message() );
		$this->assertSame( 'new', get_post_meta( $post, 'aafm_note', true ), 'The field is left as it was.' );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'], 'Nothing was reverted, so the change can be tried again.' );
	}

	public function test_a_plugin_rewriting_the_value_after_the_restore_is_reported_as_not_matching(): void {
		global $wpdb;
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'old' );
		$change  = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$rewrite = static function ( $meta_ids, $object_id, $meta_key ) use ( $wpdb, $post ): void {
			if ( $object_id === $post && 'aafm_note' === $meta_key ) {
				$where = array(
					'post_id'  => $post,
					'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture write.
				);
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'HOSTILE' ), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture write.
			}
		};
		add_action( 'deleted_post_meta', $rewrite, 10, 3 );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_action( 'deleted_post_meta', $rewrite, 10 );
		wp_cache_delete( $post, 'post_meta' );

		$this->assertWPError( $result, 'A restore that did not land exactly is never reported as reverted.' );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'aafm_note', $result->get_error_message() );
		$this->assertStringContainsString( 'plugin', $result->get_error_message() );
	}

	public function test_a_filter_changing_the_restored_dates_gives_a_difference_not_a_clean_revert(): void {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a test fixture write.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => '2020-01-02 03:04:05',
				'post_date_gmt' => '0000-00-00 00:00:00',
				'post_name'     => '',
			),
			array( 'ID' => $post )
		);
		clean_post_cache( $post );
		$change = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );
		$move   = static function ( array $data ): array {
			if ( '' === $data['post_name'] && 'draft' === $data['post_status'] ) {
				$data['post_date'] = '2001-01-01 00:00:00';
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $move );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'wp_insert_post_data', $move );

		$this->assertIsArray( $result );
		$this->assertSame( 'reverted_with_differences', $result['outcome'] );
		$this->assertContains( 'post_date', $result['differences'] );
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

	/**
	 * A companion title that is NULL in the table is left out of the save: sending it as '' made
	 * AIOSEO mirror an empty _aioseo_title meta row that was absent before the change.
	 */
	public function test_an_aioseo_restore_does_not_carry_a_null_companion_title(): void {
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post, null );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN description TEXT NULL', aafm_undo_aioseo_table() ) );
		$this->stub_aioseo();

		$this->assertTrue( aafm_undo_write_aioseo( $post, array( 'description' => 'first' ) ) );

		$calls = \AAFM\Tests\AioseoStubStore::$save_post_calls;
		$this->reset_integration_stubs();
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'description' => 'first' ), $calls[0]['data'] );
	}

	/**
	 * A title written over a NULL column with no _aioseo_title row: AIOSEO mirrored the new title into
	 * that row, and the restore's save of NULL mirrors '' into it again. The change records the shadow
	 * row, so the undo leaves it absent, as it was.
	 */
	public function test_an_aioseo_title_undo_restores_an_absent_shadow_meta_row(): void {
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post, null );
		$fields = aafm_undo_surfaces()['aafm/aioseo-update-post']['fields']( array() );
		$this->assertContains( 'meta:_aioseo_title', $fields, 'the change records the shadow row' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN description TEXT NULL', aafm_undo_aioseo_table() ) );

		$this->stub_aioseo();
		\AAFM\Tests\AioseoStubStore::$mirror_meta = true;
		$before                                   = aafm_undo_read_fields( 'post', $post, array( 'aioseo:title', 'meta:_aioseo_title' ) );
		update_post_meta( $post, '_aioseo_title', 'New title' ); // What the forward save mirrored.

		aafm_undo_write_fields( 'post', $post, $before['values'] );
		$saved = \AAFM\Tests\AioseoStubStore::$save_post_calls;
		$this->reset_integration_stubs();

		$this->assertCount( 1, $saved, 'precondition: the restore reached the AIOSEO save' );
		$this->assertSame( array(), aafm_meta_raw_snapshot( 'post', $post, array( '_aioseo_title' ) )['by_key']['_aioseo_title']['raw'] );
	}

	/**
	 * The og and twitter text keys reach AIOSEO's meta mirror untrimmed, so restoring a NULL column
	 * writes a NULL meta_value row. Every touched shadow row must still end absent, as before.
	 *
	 * @dataProvider aioseo_untrimmed_columns
	 *
	 * @param string $column AIOSEO column.
	 */
	public function test_an_aioseo_og_or_twitter_undo_leaves_no_null_shadow_row( string $column ): void {
		global $wpdb;
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post, null );
		// The description column too: the restore reads it as a companion, and without it nothing is saved.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN description TEXT NULL, ADD COLUMN %i TEXT NULL', aafm_undo_aioseo_table(), $column ) );

		$this->stub_aioseo();
		\AAFM\Tests\AioseoStubStore::$mirror_meta = true;
		$key                                      = '_aioseo_' . $column;
		$before                                   = aafm_undo_read_fields( 'post', $post, array( 'aioseo:' . $column, 'meta:' . $key ) );
		update_post_meta( $post, $key, 'New value' ); // What the forward save mirrored.

		aafm_undo_write_fields( 'post', $post, $before['values'] );
		$saved = \AAFM\Tests\AioseoStubStore::$save_post_calls;
		$this->reset_integration_stubs();
		$this->assertCount( 1, $saved, 'precondition: the restore reached the AIOSEO save, which mirrors a NULL row' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $post, $key ) );
		$this->assertSame( 0, $rows, "No {$key} row, not even a NULL one." );
	}

	/**
	 * The text columns AIOSEO mirrors without trimming.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function aioseo_untrimmed_columns(): array {
		return array(
			'og_title'            => array( 'og_title' ),
			'og_description'      => array( 'og_description' ),
			'twitter_title'       => array( 'twitter_title' ),
			'twitter_description' => array( 'twitter_description' ),
		);
	}

	public function test_aioseo_post_without_a_row_flags_every_field_as_no_vendor_row(): void {
		$post = self::factory()->post->create();
		$this->aioseo_table_with( $post + 1, 'Someone else' );

		$read = aafm_undo_read_fields( 'post', $post, array( 'aioseo:title' ) );

		$this->assertSame( array( 'aioseo:title' => 'no_vendor_row' ), $read['flags'] );
	}
}
