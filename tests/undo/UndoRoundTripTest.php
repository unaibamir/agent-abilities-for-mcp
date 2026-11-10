<?php
/**
 * A recorded change reverts to the exact stored bytes, family by family.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoRoundTripTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	/**
	 * Run a tool, return the change id its response names.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return int
	 */
	private function change_from( string $ability, array $input ): int {
		$result = $this->run_tool( $ability, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['undo']['undoable'], 'precondition: ' . wp_json_encode( $result['undo'] ) );
		return (int) $result['undo']['change_ids'][0];
	}

	/**
	 * Revert as the owner and assert a clean outcome.
	 *
	 * @param int    $change_id Change id.
	 * @param string $outcome   Expected outcome.
	 * @return array<string,mixed>
	 */
	private function revert( int $change_id, string $outcome = 'reverted' ): array {
		$result = aafm_undo_apply( $change_id, 'owner' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertSame( $outcome, $result['outcome'] );
		return $result;
	}

	/**
	 * Raw stored post columns.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string>
	 */
	private function post_row( int $post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT post_title, post_content, post_excerpt, post_name, post_status, post_date, post_date_gmt FROM %i WHERE ID = %d', $wpdb->posts, $post_id ), ARRAY_A );
	}

	/**
	 * Raw meta values of a key.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return list<string>
	 */
	private function raw_meta( int $post_id, string $key ): array {
		return aafm_meta_raw_snapshot( 'post', $post_id, array( $key ) )['by_key'][ $key ]['raw'];
	}

	public function test_revert_restores_the_exact_stored_bytes(): void {
		$post   = self::factory()->post->create(
			array(
				'post_title'   => 'Old',
				'post_content' => 'Old body',
				'post_excerpt' => 'Old ex',
			)
		);
		$before = $this->post_row( $post );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
				'content' => 'New body',
				'excerpt' => 'New ex',
			)
		);

		$this->revert( $change );

		$this->assertSame( $before, $this->post_row( $post ) );
		$this->assertSame( 'reverted', aafm_undo_get_view( $change )['row']['status'] );
	}

	public function test_a_backslash_and_block_json_survive_the_round_trip(): void {
		$content = '<!-- wp:paragraph {"content":"a \u003c b"} --><p>C:\Users\x</p><!-- /wp:paragraph -->';
		$post    = self::factory()->post->create(
			array(
				'post_title'   => 'C:\Users\x',
				'post_content' => wp_slash( $content ),
			)
		);
		$before  = $this->post_row( $post );
		$change  = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'Other',
				'content' => 'Other',
			)
		);

		$this->revert( $change );

		$this->assertSame( $before, $this->post_row( $post ) );
	}

	public function test_emoji_in_a_title_survives(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'Launch 🚀 day' ) );
		$before = $this->post_row( $post );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'Plain',
			)
		);

		$this->revert( $change );

		$this->assertSame( $before, $this->post_row( $post ) );
	}

	public function test_draft_to_publish_then_revert_restores_status_and_dates(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$before = $this->post_row( $post );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'status'  => 'publish',
			)
		);
		$this->assertSame( 'publish', get_post( $post )->post_status );

		$this->revert( $change );

		$this->assertSame( $before, $this->post_row( $post ) );
	}

	public function test_three_rows_under_one_meta_key_come_back_in_order(): void {
		$post = self::factory()->post->create();
		foreach ( array( 'a', 'b', 'c' ) as $v ) {
			add_post_meta( $post, 'aafm_note', $v );
		}
		$before = $this->raw_meta( $post, 'aafm_note' );
		$change = $this->change_from(
			'aafm/delete-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$this->assertSame( array(), $this->raw_meta( $post, 'aafm_note' ) );

		$this->revert( $change );

		$this->assertSame( $before, $this->raw_meta( $post, 'aafm_note' ) );
	}

	public function test_absent_versus_an_empty_string_is_kept(): void {
		$absent = self::factory()->post->create();
		$empty  = self::factory()->post->create();
		add_post_meta( $empty, 'aafm_note', '' );
		$c1 = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $absent,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'x',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		$c2 = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $empty,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'x',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.

		$this->revert( $c1 );
		$this->revert( $c2 );

		$this->assertSame( array(), $this->raw_meta( $absent, 'aafm_note' ) );
		$this->assertSame( array( '' ), $this->raw_meta( $empty, 'aafm_note' ) );
	}

	public function test_a_string_that_looks_serialized_stays_a_string(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_note', 'a:0:{}' );
		$before = $this->raw_meta( $post, 'aafm_note' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture query on a tiny table.
				'meta_key' => 'aafm_note',
				'value'    => 'other',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.

		$this->revert( $change );

		$this->assertSame( $before, $this->raw_meta( $post, 'aafm_note' ) );
	}

	public function test_add_post_terms_round_trips_the_term_ids(): void {
		$post = self::factory()->post->create();
		$one  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$two  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_post_terms( $post, array( $one ), 'category' );
		$change = $this->change_from(
			'aafm/add-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'category',
				'term_ids' => array( $two ),
			)
		);
		$this->assertEqualsCanonicalizing( array( $one, $two ), wp_get_post_terms( $post, 'category', array( 'fields' => 'ids' ) ) );

		$this->revert( $change );

		$this->assertSame( array( $one ), array_map( 'intval', wp_get_post_terms( $post, 'category', array( 'fields' => 'ids' ) ) ) );
	}

	public function test_update_term_round_trips_name_and_description(): void {
		$term   = self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Before',
				'description' => 'Desc',
			)
		);
		$change = $this->change_from(
			'aafm/update-term',
			array(
				'taxonomy'    => 'category',
				'term_id'     => $term,
				'name'        => 'After',
				'description' => 'Changed',
			)
		);

		$this->revert( $change );

		$this->assertSame( 'Before', get_term( $term, 'category' )->name );
		$this->assertSame( 'Desc', get_term( $term, 'category' )->description );
	}

	/**
	 * Core clears a draft's date to "now" on every save, so the forward edit moves post_date and an
	 * undo that did not ask core to keep the date reported a difference although the content was back.
	 */
	public function test_a_draft_edit_reverts_cleanly_with_its_floating_date_restored(): void {
		global $wpdb;
		$post = self::factory()->post->create(
			array(
				'post_title'  => 'Old',
				'post_status' => 'draft',
			)
		);
		// A draft's date floats: post_date_gmt stays zero and post_date is the last save time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a test fixture write.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => '2020-01-02 03:04:05',
				'post_date_gmt' => '0000-00-00 00:00:00',
			),
			array( 'ID' => $post )
		);
		clean_post_cache( $post );
		$before = $this->post_row( $post );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'New',
			)
		);
		$this->assertNotSame( $before['post_date'], $this->post_row( $post )['post_date'], 'Guard: core moved the draft date on save.' );

		$this->revert( $change );

		$after = $this->post_row( $post );
		$this->assertSame( 'Old', $after['post_title'] );
		$this->assertSame( $before['post_date'], $after['post_date'] );
		$this->assertSame( '0000-00-00 00:00:00', $after['post_date_gmt'] );
	}

	public function test_set_featured_image_reverts_to_no_image(): void {
		$post   = self::factory()->post->create();
		$image  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );
		$change = $this->change_from(
			'aafm/set-featured-image',
			array(
				'post_id'       => $post,
				'attachment_id' => $image,
			)
		);
		$this->assertSame( $image, (int) get_post_thumbnail_id( $post ) );

		$this->revert( $change );

		$this->assertSame( array(), $this->raw_meta( $post, '_thumbnail_id' ) );
	}

	/**
	 * A draft has an empty slug and a floating date. Trashing it makes core write "__trashed" and a real GMT
	 * date, and untrash used to leave both behind: the revert came back as reverted_with_differences.
	 */
	public function test_trash_of_a_slugless_draft_reverts_to_the_empty_slug_and_floating_date(): void {
		global $wpdb;
		$post = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_name'   => '',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a test fixture write.
		$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => '0000-00-00 00:00:00', 'post_name' => '' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		clean_post_cache( $post );
		$before = $this->post_row( $post );
		$change = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );
		$this->assertSame( '__trashed', $this->post_row( $post )['post_name'], 'Guard: core suffixed the slug.' );

		$this->revert( $change );

		$after = $this->post_row( $post );
		$this->assertSame( 'draft', $after['post_status'] );
		$this->assertSame( $before['post_name'], $after['post_name'] );
		$this->assertSame( $before['post_date'], $after['post_date'] );
		$this->assertSame( $before['post_date_gmt'], $after['post_date_gmt'] );
	}

	/**
	 * The inverse records of a trash revert must hold the dates too, or a second and third undo lose the draft's
	 * empty slug and floating date again.
	 */
	public function test_undoing_a_trash_three_times_keeps_the_slugless_drafts_slug_and_date(): void {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a test fixture write.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date_gmt' => '0000-00-00 00:00:00',
				'post_name'     => '',
			),
			array( 'ID' => $post )
		);
		clean_post_cache( $post );
		$before = $this->post_row( $post );
		$first  = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );

		$one = $this->revert( $first );
		$two = $this->revert( $one['new_change_id'] );
		$this->assertSame( 'trash', $this->post_row( $post )['post_status'], 'The second undo trashes it again.' );
		$this->revert( $two['new_change_id'] );

		$after = $this->post_row( $post );
		$this->assertSame( 'draft', $after['post_status'] );
		$this->assertSame( $before['post_name'], $after['post_name'] );
		$this->assertSame( $before['post_date'], $after['post_date'] );
		$this->assertSame( $before['post_date_gmt'], $after['post_date_gmt'] );
	}

	public function test_trash_of_a_published_post_reverts_to_published(): void {
		$post   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'keep-me',
			)
		);
		$before = $this->post_row( $post );
		$change = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );
		$this->assertSame( 'trash', get_post( $post )->post_status );

		$this->revert( $change );

		$this->assertSame( $before['post_status'], $this->post_row( $post )['post_status'] );
		$this->assertSame( $before['post_name'], $this->post_row( $post )['post_name'] );
	}

	public function test_undoing_a_create_trashes_it_and_the_revert_can_be_reverted(): void {
		$result = $this->run_tool(
			'aafm/create-post',
			array(
				'title'   => 'Fresh',
				'content' => 'Body',
			)
		);
		$post   = (int) $result['post']['id'];
		$change = (int) $result['undo']['change_ids'][0];

		$first = $this->revert( $change );
		$this->assertSame( 'trash', get_post( $post )->post_status );

		$this->revert( $first['new_change_id'] );
		$this->assertNotSame( 'trash', get_post( $post )->post_status );
	}

	public function test_replace_in_post_round_trips_content(): void {
		$post   = self::factory()->post->create( array( 'post_content' => 'one foo two' ) );
		$before = $this->post_row( $post );
		$change = $this->change_from(
			'aafm/replace-in-post',
			array(
				'post_id' => $post,
				'search'  => 'foo',
				'replace' => 'bar',
			)
		);

		$this->revert( $change );

		$this->assertSame( $before, $this->post_row( $post ) );
	}

	public function test_replace_sitewide_group_reverts_each_post(): void {
		$a      = self::factory()->post->create( array( 'post_content' => 'foo one' ) );
		$b      = self::factory()->post->create( array( 'post_content' => 'foo two' ) );
		$result = $this->run_tool(
			'aafm/replace-sitewide',
			array(
				'search'  => 'foo',
				'replace' => 'bar',
				'dry_run' => false,
			)
		);

		foreach ( $result['undo']['change_ids'] as $change ) {
			$this->revert( (int) $change );
		}

		$this->assertSame( 'foo one', get_post( $a )->post_content );
		$this->assertSame( 'foo two', get_post( $b )->post_content );
	}
}
