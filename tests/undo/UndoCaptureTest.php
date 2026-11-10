<?php
/**
 * Capture around an agent write: what is recorded, what is not, and what the response says.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoCaptureTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	public function test_update_post_title_change_records_one_ready_row_with_only_the_title(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$result = $this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
			)
		);

		$this->assertIsArray( $result );
		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame(
			array(
				'undoable'   => true,
				'change_ids' => array( (int) $rows[0]['id'] ),
			),
			$result['undo']
		);
		$this->assertSame( 'ready', $rows[0]['status'] );
		$this->assertSame( 'restore', $rows[0]['undo_op'] );
		$this->assertSame( array( 'post:post_title' ), $this->row_fields( $rows[0] ) );
		$this->assertSame( '{"post:post_title":"Old"}', $rows[0]['before_values'] );
		$this->assertSame( hash( 'sha256', $rows[0]['before_values'] ), $rows[0]['before_sha256'] );
		$this->assertSame( array( 'post:post_title' ), array_keys( (array) json_decode( (string) $rows[0]['after_hashes'], true ) ) );
		$this->assertSame( 'agent', $rows[0]['source'] );
		$this->assertSame( get_current_user_id(), (int) $rows[0]['principal_user_id'] );
		$this->assertSame( (string) $post, (string) json_decode( (string) $rows[0]['perm_input'], true )['post_id'] );
	}

	public function test_a_no_op_update_leaves_no_row_and_says_no_change(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'Same' ) );
		$result = $this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'Same',
			)
		);

		$this->assertSame( array(), $this->change_rows() );
		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array(),
				'reason'     => 'no_change',
			),
			$result['undo']
		);
	}

	public function test_a_write_that_errors_after_landing_still_records_the_change(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$handle = aafm_undo_begin(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
			),
			0
		);
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'New',
			)
		);

		$result = aafm_undo_finish( $handle, new \WP_Error( 'aafm_late', 'late failure' ) );

		$this->assertWPError( $result );
		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ready', $rows[0]['status'] );
		$this->assertSame( '{"post:post_title":"Old"}', $rows[0]['before_values'] );
	}

	public function test_create_post_records_a_trash_row_with_the_new_id(): void {
		$result = $this->run_tool(
			'aafm/create-post',
			array(
				'title'   => 'Fresh',
				'content' => 'Body',
			)
		);

		$id   = (int) $result['post']['id'];
		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'trash', $rows[0]['undo_op'] );
		$this->assertSame( $id, (int) $rows[0]['object_id'] );
		$this->assertSame( 'ready', $rows[0]['status'] );
		$this->assertSame( '{}', $rows[0]['before_values'], 'A create has no before-values and is never NULL.' );
		$hashes = (array) json_decode( (string) $rows[0]['after_hashes'], true );
		$this->assertArrayHasKey( 'fp:object', $hashes );
		$this->assertArrayHasKey( 'post:post_title', $hashes );
		$this->assertSame(
			array(
				'undoable'   => true,
				'change_ids' => array( (int) $rows[0]['id'] ),
			),
			$result['undo']
		);
	}

	public function test_a_create_that_errors_after_insert_still_records_a_trash_row(): void {
		$handle = aafm_undo_begin( 'aafm/create-post', array( 'title' => 'Half' ), 0 );
		$id     = wp_insert_post(
			array(
				'post_title'  => 'Half',
				'post_status' => 'draft',
			)
		);

		aafm_undo_finish( $handle, new \WP_Error( 'aafm_late', 'late failure' ) );

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, (int) $rows[0]['object_id'] );
		$this->assertSame( 'ready', $rows[0]['status'] );
	}

	public function test_trash_post_records_status_and_slug(): void {
		$post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'keep-me',
			)
		);
		$this->run_tool( 'aafm/trash-post', array( 'post_id' => $post ) );

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'untrash', $rows[0]['undo_op'] );
		$this->assertSame( array( 'post:post_name', 'post:post_status' ), array_values( array_intersect( array( 'post:post_name', 'post:post_status' ), $this->row_fields( $rows[0] ) ) ) );
		$this->assertSame( 'publish', json_decode( (string) $rows[0]['before_values'], true )['post:post_status'] );
	}

	public function test_delete_block_records_an_untrash_row(): void {
		$block = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
			)
		);
		$this->run_tool( 'aafm/delete-block', array( 'block_id' => $block ) );

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'untrash', $rows[0]['undo_op'] );
		$this->assertSame( $block, (int) $rows[0]['object_id'] );
	}

	public function test_an_excluded_tool_answers_its_reason_and_writes_no_row(): void {
		$result = $this->run_tool(
			'aafm/create-term',
			array(
				'taxonomy' => 'category',
				'name'     => 'Fresh term',
			)
		);

		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array(),
				'reason'     => 'no_trash',
			),
			$result['undo']
		);
		$this->assertSame( array(), $this->change_rows() );
	}

	public function test_a_read_records_nothing(): void {
		$this->run_tool( 'aafm/get-posts', array() );
		$this->assertSame( array(), $this->change_rows() );
	}

	public function test_a_bridged_tool_records_nothing(): void {
		$this->assertNull( aafm_undo_begin( 'aafm-bridge/vendor-thing', array(), 0 ) );
	}

	public function test_replace_sitewide_records_one_row_per_post_under_one_group(): void {
		$a = self::factory()->post->create( array( 'post_content' => 'foo one' ) );
		$b = self::factory()->post->create( array( 'post_content' => 'foo two' ) );
		self::factory()->post->create( array( 'post_content' => 'untouched' ) );

		$result = $this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'foo',
				'replace' => 'bar',
				'dry_run' => false,
			)
		);

		$rows = $this->change_rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( 32, strlen( $rows[0]['group_id'] ) );
		$this->assertSame( $rows[0]['group_id'], $rows[1]['group_id'] );
		$this->assertSame( $rows[0]['log_row_id'], $rows[1]['log_row_id'] );
		$this->assertEqualsCanonicalizing( array( $a, $b ), array( (int) $rows[0]['object_id'], (int) $rows[1]['object_id'] ) );
		$this->assertSame( array( 'post:post_content' ), $this->row_fields( $rows[0] ) );
		$this->assertSame( array( (int) $rows[0]['id'], (int) $rows[1]['id'] ), $result['undo']['change_ids'] );
		$this->assertTrue( $result['undo']['undoable'] );
	}

	public function test_replace_sitewide_dry_run_records_nothing(): void {
		self::factory()->post->create( array( 'post_content' => 'foo one' ) );

		$result = $this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'foo',
				'replace' => 'bar',
				'dry_run' => true,
			)
		);

		$this->assertSame( array(), $this->change_rows() );
		$this->assertSame( 'no_change', $result['undo']['reason'] );
	}

	public function test_replace_sitewide_wp_error_leaves_no_pending_row(): void {
		$result = $this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => '',
				'replace' => 'x',
				'dry_run' => false,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->change_rows() );
	}

	public function test_write_responses_carry_the_undo_key_and_it_validates_against_the_output_schema(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$result = $this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
			)
		);

		$schema = wp_get_ability( 'aafm/update-post' )->get_output_schema();
		$this->assertSame( aafm_undo_response_schema(), $schema['properties']['undo'] );
		$this->assertArrayHasKey( 'undo', $result, 'A response that fails the output schema would have been an error, not a result.' );
	}

	public function test_capture_off_records_nothing_and_says_history_off(): void {
		update_option( 'aafm_undo_capture_off', true );
		$post   = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$result = $this->run_tool(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
			)
		);

		$this->assertSame( 'New', get_post( $post )->post_title );
		$this->assertSame( array(), $this->change_rows() );
		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array(),
				'reason'     => 'history_off',
			),
			$result['undo']
		);
	}

	public function test_history_off_never_overrides_a_refusal_reason(): void {
		update_option( 'aafm_undo_capture_off', true );
		$result = $this->run_tool(
			'aafm/create-term',
			array(
				'taxonomy' => 'category',
				'name'     => 'Other term',
			)
		);

		$this->assertSame( 'no_trash', $result['undo']['reason'] );
	}

	public function test_seo_capture_reads_all_keys_in_one_meta_query(): void {
		$post   = self::factory()->post->create();
		$fields = array_map( static fn( string $key ): string => 'meta:' . $key, aafm_yoast_meta_keys() );
		$count  = 0;
		$tap    = static function ( string $query ) use ( &$count ): string {
			if ( false !== strpos( $query, 'postmeta' ) && 0 === stripos( ltrim( $query ), 'SELECT' ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $tap );
		$read = aafm_undo_read_fields( 'post', $post, $fields );
		remove_filter( 'query', $tap );

		$this->assertNotNull( $read );
		$this->assertCount( 13, $read['values'] );
		$this->assertSame( 1, $count );
	}

	public function test_add_post_terms_records_the_term_ids_before(): void {
		$post = self::factory()->post->create();
		$one  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$two  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_post_terms( $post, array( $one ), 'category' );

		$this->run_tool(
			'aafm/add-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'category',
				'term_ids' => array( $two ),
			)
		);

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( $one ), json_decode( (string) $rows[0]['before_values'], true )['terms:category'] );
	}

	public function test_update_term_records_name_description_and_parent(): void {
		$term = self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Before',
				'description' => 'Desc',
			)
		);
		$this->run_tool(
			'aafm/update-term',
			array(
				'taxonomy' => 'category',
				'term_id'  => $term,
				'name'     => 'After',
			)
		);

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'term:name' ), $this->row_fields( $rows[0] ) );
		$this->assertSame( 'Before', json_decode( (string) $rows[0]['before_values'], true )['term:name'] );
	}

	public function test_update_post_meta_records_the_raw_rows_in_order(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'one' );
		$this->run_tool(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'two',
			)
		);

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'one' ), json_decode( (string) $rows[0]['before_values'], true )['meta:aafm_note'] );
	}
}
