<?php
/**
 * Faults inside the change history never break or change a write, and no value leaks.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\Support\QueryFaultInjector;

final class UndoCaptureFailureTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	public function test_insert_fault_lets_the_write_land_and_says_capture_failed(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$this->register_enabled( array( 'aafm/update-post' ) );

		$result = QueryFaultInjector::break_query_with_real_error(
			array( 'INSERT INTO', 'aafm_changes' ),
			fn() => wp_get_ability( 'aafm/update-post' )->execute(
				array(
					'post_id' => $post,
					'title'   => 'New',
				)
			)
		);

		$this->assertSame( 'New', get_post( $post )->post_title );
		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array(),
				'reason'     => 'capture_failed',
			),
			$result['undo']
		);
	}

	public function test_read_fault_lets_the_write_land_and_says_capture_failed(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$this->register_enabled( array( 'aafm/update-post' ) );

		$result = QueryFaultInjector::break_query_with_real_error(
			array( '`post_date_gmt` FROM' ),
			fn() => wp_get_ability( 'aafm/update-post' )->execute(
				array(
					'post_id' => $post,
					'title'   => 'New',
				)
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'New', get_post( $post )->post_title );
		$this->assertSame( 'capture_failed', $result['undo']['reason'] );
		$this->assertSame( array(), $this->change_rows() );
	}

	public function test_a_failed_term_read_gives_capture_failed_not_an_empty_list(): void {
		$post = self::factory()->post->create();
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->register_enabled( array( 'aafm/add-post-terms' ) );

		ob_start();
		$result = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT tt.taxonomy, tt.term_id', 'term_relationships' ),
			fn() => wp_get_ability( 'aafm/add-post-terms' )->execute(
				array(
					'post_id'  => $post,
					'taxonomy' => 'category',
					'term_ids' => array( $term ),
				)
			),
			1
		);
		ob_end_clean();

		$this->assertSame( 'capture_failed', $result['undo']['reason'] );
		$this->assertSame( array(), $this->change_rows(), 'No row holds an empty list in place of a failed read.' );
	}

	public function test_stale_relationship_cache_does_not_change_the_captured_terms(): void {
		$post = self::factory()->post->create();
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_post_terms( $post, array( $term ), 'category' );
		wp_cache_set( $post, array(), 'category_relationships' );

		$read = aafm_undo_read_fields( 'post', $post, array( 'terms:category' ) );

		$this->assertSame( array( $term ), $read['values']['terms:category'] );
	}

	public function test_a_throw_inside_begin_does_not_change_the_write_and_is_logged(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$post = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		add_filter(
			'pre_option_aafm_undo_capture_off',
			static function (): void {
				throw new \RuntimeException( 'engine fault SENTINEL' );
			}
		);
		$this->register_enabled( array( 'aafm/update-post' ) );

		$result = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post,
				'title'   => 'New',
			)
		);

		$this->assertSame( 'New', get_post( $post )->post_title );
		$this->assertSame( 'capture_failed', $result['undo']['reason'] );
		$call = aafm_query_activity( array( 'ability' => 'aafm/update-post' ) );
		$this->assertSame( 'success', $call[0]['status'] );
		$fault = aafm_query_activity( array( 'ability' => 'aafm/undo-capture' ) );
		$this->assertCount( 1, $fault );
		$this->assertSame( 'error', $fault[0]['status'] );
		$this->assertStringNotContainsString( 'SENTINEL', (string) $fault[0]['detail'] );
	}

	public function test_engine_throw_rethrows_by_default_under_wp_debug(): void {
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'precondition: the test library defines WP_DEBUG.' );
		$post = self::factory()->post->create();
		add_filter(
			'pre_option_aafm_undo_capture_off',
			static function (): void {
				throw new \RuntimeException( 'engine fault' );
			}
		);

		$this->expectException( \RuntimeException::class );
		aafm_undo_begin(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'x',
			),
			0
		);
	}

	public function test_a_nested_insert_during_create_does_not_steal_the_recorded_id(): void {
		$nested = static function ( $post_id, $post ): void {
			if ( 'Outer' === $post->post_title ) {
				wp_insert_post(
					array(
						'post_title'  => 'Nested',
						'post_status' => 'draft',
					)
				);
			}
		};
		add_action( 'save_post', $nested, 10, 2 );
		$handle = aafm_undo_begin( 'aafm/create-post', array( 'title' => 'Outer' ), 0 );
		$outer  = wp_insert_post(
			array(
				'post_title'  => 'Outer',
				'post_status' => 'draft',
			)
		);
		remove_action( 'save_post', $nested, 10 );

		aafm_undo_finish( $handle, new \WP_Error( 'aafm_late', 'late failure' ) );

		$this->assertSame( $outer, (int) $this->change_rows()[0]['object_id'] );
	}

	/**
	 * Keys that look like secrets.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_secret_keys(): array {
		return array(
			'api_key'     => array( 'api_key' ),
			'api-key'     => array( 'api-key' ),
			'myApiKey'    => array( 'myApiKey' ),
			'private_key' => array( 'private_key' ),
			'license_key' => array( 'license_key' ),
			'pwd'         => array( 'pwd' ),
		);
	}

	/**
	 * A secret-shaped key is recognised whatever its spelling.
	 *
	 * @dataProvider data_secret_keys
	 *
	 * @param string $key Meta key.
	 */
	public function test_secret_shaped_key_stores_no_value( string $key ): void {
		$this->assertTrue( aafm_undo_secret_shaped_key( $key ) );
	}

	public function test_a_secret_shaped_meta_write_stores_no_value_and_is_not_undoable(): void {
		$sentinel = 'SENTINEL_' . wp_generate_password( 10, false );
		$post     = self::factory()->post->create();
		add_post_meta( $post, 'api_key', $sentinel );

		$this->run_tool(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				'meta_key' => 'api_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'changed',
			)
		);

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'not_undoable', $rows[0]['status'] );
		$this->assertSame( 'secret_shaped', $rows[0]['reason'] );
		$this->assertStringNotContainsString( $sentinel, wp_json_encode( $rows ) );
	}

	public function test_a_filter_added_secret_pattern_is_honoured(): void {
		$this->assertFalse( aafm_undo_secret_shaped_key( 'custom_vault' ) );
		add_filter( 'aafm_undo_secret_key_patterns', static fn( array $p ): array => array_merge( $p, array( '/vault/i' ) ) );
		$this->assertTrue( aafm_undo_secret_shaped_key( 'custom_vault' ) );
	}

	public function test_author_key_is_not_secret_shaped(): void {
		$this->assertFalse( aafm_undo_secret_shaped_key( 'author' ) );
		$this->assertFalse( aafm_undo_secret_shaped_key( 'aafm_note' ) );
	}

	public function test_the_secret_filter_cannot_remove_a_default_pattern(): void {
		add_filter( 'aafm_undo_secret_key_patterns', '__return_empty_array' );
		$this->assertTrue( aafm_undo_secret_shaped_key( 'api_key' ) );
	}

	public function test_a_broken_secret_pattern_refuses_capture(): void {
		add_filter( 'aafm_undo_secret_key_patterns', static fn( array $p ): array => array_merge( $p, array( '/(unclosed' ) ) );
		$this->assertTrue( aafm_undo_secret_shaped_key( 'aafm_note' ), 'A pattern that does not compile counts as a match.' );
	}

	public function test_an_object_in_meta_is_refused_as_holds_object(): void {
		global $wpdb;
		$post = self::factory()->post->create();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post,
				'meta_key'   => 'aafm_obj', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'O:8:"stdClass":0:{}', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$this->run_tool(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				'meta_key' => 'aafm_obj', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'plain',
			)
		);

		$rows = $this->change_rows();
		$this->assertSame( 'not_undoable', $rows[0]['status'] );
		$this->assertSame( 'holds_object', $rows[0]['reason'] );
		$this->assertNull( $rows[0]['before_values'] );
	}

	public function test_invalid_utf8_is_refused_as_not_utf8(): void {
		$this->assertSame( 'not_utf8', aafm_undo_field_flag( 'meta:aafm_note', array( "\xE9t\xE9" ) ) );
		$this->assertSame( 'not_utf8', aafm_undo_field_flag( 'post:post_title', "\xE9t\xE9" ) );
		$this->assertNull( aafm_undo_field_flag( 'post:post_title', 'Café' ) );
	}

	public function test_an_aliased_key_is_flagged_not_exact_at_read(): void {
		global $wpdb;
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'lower' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post,
				'meta_key'   => 'AAFM_NOTE', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'upper', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$read = aafm_undo_read_fields( 'post', $post, array( 'meta:aafm_note' ) );

		$this->assertSame( array( 'meta:aafm_note' => 'not_exact' ), $read['flags'] );
	}

	public function test_title_change_on_an_oversized_post_is_undoable(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Old',
				'post_content' => str_repeat( 'x', AAFM_UNDO_MAX_CHANGE_BYTES + 10 ),
			)
		);

		$this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
			)
		);

		$rows = $this->change_rows();
		$this->assertSame( 'ready', $rows[0]['status'] );
		$this->assertSame( array( 'post:post_title' ), $this->row_fields( $rows[0] ) );
	}

	public function test_a_flagged_field_that_changed_makes_the_change_not_undoable(): void {
		$post = self::factory()->post->create( array( 'post_content' => str_repeat( 'x', AAFM_UNDO_MAX_CHANGE_BYTES + 10 ) ) );

		$this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'content' => 'short now',
			)
		);

		$rows = $this->change_rows();
		$this->assertSame( 'not_undoable', $rows[0]['status'] );
		$this->assertSame( 'too_large', $rows[0]['reason'] );
		$this->assertNull( $rows[0]['before_values'] );
	}

	public function test_a_meta_value_over_the_size_bound_is_refused_before_it_is_decoded(): void {
		// A malformed serialized string would read as not_exact if it were decoded first; its length decides first.
		$raw = 'a:1:{' . str_repeat( 'x', AAFM_UNDO_MAX_CHANGE_BYTES + 10 ) . '}';

		$this->assertSame( 'too_large', aafm_undo_field_flag( 'meta:aafm_note', array( $raw ) ) );
	}

	public function test_a_hard_blocked_key_never_reaches_the_table(): void {
		$fields = aafm_undo_fields_post_write(
			array(
				'meta' => array(
					'_edit_lock' => 'x',
					'aafm_note'  => 'y',
				),
			)
		);

		$this->assertNotContains( 'meta:_edit_lock', $fields );
		$this->assertContains( 'meta:aafm_note', $fields );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
		$this->assertSame( array(), aafm_undo_fields_post_meta_key( array( 'meta_key' => '_edit_lock' ) ) );
	}
}
