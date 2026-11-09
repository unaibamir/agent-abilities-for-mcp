<?php
/**
 * A revert never overwrites a newer edit, never half-applies, and writes nothing when it refuses.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\Support\QueryFaultInjector;

final class UndoConflictTest extends UndoTestCase {

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
		return (int) ( $result['undo']['change_ids'][0] ?? 0 );
	}

	/**
	 * Assert a revert is refused with a reason, the object is untouched and the row is still ready.
	 *
	 * @param int    $change_id Change id.
	 * @param string $reason    Expected reason code.
	 * @param string $source    owner or agent.
	 * @return \WP_Error
	 */
	private function assert_refused( int $change_id, string $reason, string $source = 'owner' ): \WP_Error {
		$result = aafm_undo_apply( $change_id, $source );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_' . $reason, $result->get_error_code() );
		$this->assertSame( 'ready', aafm_undo_get_view( $change_id )['row']['status'], 'A refusal puts the row back.' );
		return $result;
	}

	public function test_newest_first_on_one_field(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$one  = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$two  = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'C',
			)
		);

		$this->assertIsArray( aafm_undo_apply( $two, 'owner' ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertIsArray( aafm_undo_apply( $one, 'owner' ) );
		$this->assertSame( 'A', get_post( $post )->post_title );
	}

	public function test_older_change_refuses_while_a_newer_one_stands(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$one  = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'C',
			)
		);

		$this->assert_refused( $one, 'changed_since' );
		$this->assertSame( 'C', get_post( $post )->post_title );
	}

	public function test_disjoint_fields_revert_in_any_order(): void {
		$post  = self::factory()->post->create(
			array(
				'post_title'   => 'T',
				'post_excerpt' => 'E',
			)
		);
		$title = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'T2',
			)
		);
		$ex    = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'excerpt' => 'E2',
			)
		);

		$this->assertIsArray( aafm_undo_apply( $title, 'owner' ) );
		$this->assertIsArray( aafm_undo_apply( $ex, 'owner' ) );

		$this->assertSame( 'T', get_post( $post )->post_title );
		$this->assertSame( 'E', get_post( $post )->post_excerpt );
	}

	public function test_one_field_changed_since_refuses_the_whole_change_and_writes_nothing(): void {
		$post   = self::factory()->post->create(
			array(
				'post_title'   => 'T',
				'post_excerpt' => 'E',
			)
		);
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'T2',
				'excerpt' => 'E2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post,
				'post_excerpt' => 'Human edit',
			)
		);

		$this->assert_refused( $change, 'changed_since' );

		$this->assertSame( 'T2', get_post( $post )->post_title, 'The other field was not reverted either.' );
		$this->assertSame( 'Human edit', get_post( $post )->post_excerpt );
	}

	public function test_a_failed_conflict_read_refuses_and_writes_nothing(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);

		ob_start();
		$result = QueryFaultInjector::break_query_with_real_error( 'SELECT `post_title` FROM', fn() => aafm_undo_apply( $change, 'owner' ) );
		ob_end_clean();

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_read_failed', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'] );
	}

	public function test_an_edit_between_the_conflict_read_and_the_record_read_is_caught(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$seen   = 0;
		$edit   = static function ( string $query ) use ( &$seen, $post ): string {
			if ( false !== strpos( $query, 'SELECT `post_title` FROM' ) && 2 === ++$seen ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->posts, array( 'post_title' => 'Raced' ), array( 'ID' => $post ) );
			}
			return $query;
		};
		add_filter( 'query', $edit );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'query', $edit );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_changed_since', $result->get_error_code() );
		$this->assertSame( 'Raced', get_post( $post )->post_title );
		$this->assertCount( 1, $this->change_rows(), 'The record row for the failed attempt was removed.' );
	}

	public function test_a_lost_claim_gives_interrupted_and_writes_nothing(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$flip   = static function ( string $query ) use ( $change ): string {
			if ( false !== strpos( $query, "SET `status` = 'undoing'" ) || ( false !== strpos( $query, 'UPDATE' ) && false !== strpos( $query, "'undoing'" ) ) ) {
				global $wpdb;
				remove_all_filters( 'query' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'undoing' WHERE id = %d", aafm_changes_table(), $change ) );
			}
			return $query;
		};
		add_filter( 'query', $flip );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'query', $flip );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_interrupted', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertCount( 1, $this->change_rows() );
	}

	public function test_owner_can_revert_a_revert_and_with_history_off(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		update_option( 'aafm_undo_capture_off', true );

		$first = aafm_undo_apply( $change, 'owner' );
		$this->assertIsArray( $first );
		$this->assertSame( 'A', get_post( $post )->post_title );
		$second = aafm_undo_apply( $first['new_change_id'], 'owner' );
		$this->assertIsArray( $second );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_content_grown_past_the_limit_since_is_refused_as_changed_since(): void {
		$post   = self::factory()->post->create( array( 'post_content' => 'small' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'content' => 'tiny',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => str_repeat( 'x', AAFM_UNDO_MAX_CHANGE_BYTES + 5 ),
			)
		);

		$this->assert_refused( $change, 'changed_since' );
	}

	public function test_title_revert_on_a_now_builder_owned_post_is_refused(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		update_post_meta( $post, '_elementor_data', '[{"id":"el1"}]' );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );

		$this->assert_refused( $change, 'builder_owned' );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_create_revert_refuses_after_any_later_change_to_the_post(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		$change = (int) $result['undo']['change_ids'][0];
		$term   = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_object_terms( $post, array( $term ), 'category' );

		$this->assert_refused( $change, 'changed_since' );
		$this->assertNotSame( 'trash', get_post( $post )->post_status );
	}

	public function test_create_revert_is_allowed_after_cron_pings_the_post(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( 'pinged' => "http://example.test/ping\n" ), array( 'ID' => $post ) );

		$this->assertIsArray( aafm_undo_apply( (int) $result['undo']['change_ids'][0], 'owner' ) );
		$this->assertSame( 'trash', get_post( $post )->post_status );
	}

	public function test_create_revert_refuses_after_a_comment_arrives(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$this->assert_refused( (int) $result['undo']['change_ids'][0], 'changed_since' );
	}

	public function test_untrash_refuses_when_the_post_is_no_longer_in_the_trash(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$change = $this->change_from( 'aafm/trash-post', array( 'post_id' => $post ) );
		wp_untrash_post( $post );

		$this->assert_refused( $change, 'not_in_trash' );
	}

	public function test_a_deleted_thumbnail_gives_dependency_gone(): void {
		$post  = self::factory()->post->create();
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );
		$other = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );
		set_post_thumbnail( $post, $image );
		$change = $this->change_from(
			'aafm/set-featured-image',
			array(
				'post_id'       => $post,
				'attachment_id' => $other,
			)
		);
		wp_delete_attachment( $image, true );
		set_post_thumbnail( $post, $other );

		$this->assert_refused( $change, 'dependency_gone' );
	}

	public function test_tampered_before_values_refuse_on_hash_mismatch(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		aafm_undo_update( $change, array( 'before_values' => '{"post:post_title":"Evil"}' ) );

		$this->assert_refused( $change, 'read_failed' );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_a_pending_row_answers_interrupted_then_did_not_finish(): void {
		$id = aafm_undo_insert(
			array(
				'ability'     => 'aafm/update-post',
				'object_type' => 'post',
				'object_id'   => 1,
				'status'      => 'pending',
			)
		);
		$this->assertSame( 'interrupted', aafm_undo_row_state( aafm_undo_get_view( $id )['row'], 'owner' )['reason'] );
		aafm_undo_update( $id, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) );
		$this->assertSame( 'did_not_finish', aafm_undo_row_state( aafm_undo_get_view( $id )['row'], 'owner' )['reason'] );
	}

	public function test_owner_revert_of_a_row_older_than_thirty_days_is_refused_as_expired(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		aafm_undo_update( $change, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ) );

		$result = aafm_undo_apply( $change, 'owner' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_expired', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_a_missing_change_answers_the_generic_error(): void {
		$result = aafm_undo_apply( 999999, 'owner' );
		$this->assertWPError( $result );
		$this->assertSame( aafm_generic_error()->get_error_code(), $result->get_error_code() );
	}

	public function test_trash_off_refuses_a_create_revert(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		add_filter( 'aafm_trash_is_enabled', '__return_false' );
		$refusal = $this->assert_refused( (int) $result['undo']['change_ids'][0], 'trash_off' );
		remove_filter( 'aafm_trash_is_enabled', '__return_false' );

		$this->assertNotSame( 'trash', get_post( $post )->post_status );
		$this->assertSame( 'aafm_undo_trash_off', $refusal->get_error_code() );
	}

	public function test_term_parent_revert_refuses_a_cycle(): void {
		$parent = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$child  = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'parent'   => $parent,
			)
		);
		$change = $this->change_from(
			'aafm/update-term',
			array(
				'taxonomy' => 'category',
				'term_id'  => $child,
				'parent'   => 0,
			)
		);
		// The old parent now sits below the child: putting it back would close a loop.
		wp_update_term( $parent, 'category', array( 'parent' => $child ) );

		$this->assert_refused( $change, 'not_allowed' );
		$this->assertSame( 0, (int) get_term( $child, 'category' )->parent );
	}

	public function test_a_write_that_errors_after_the_claim_returns_the_row_to_ready(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$before = count( $this->change_rows() );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'] );
		$this->assertCount( $before, $this->change_rows(), 'The pending revert row is removed again.' );
	}

	public function test_a_failed_new_row_insert_writes_nothing_and_returns_the_row_to_ready(): void {
		global $wpdb;
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$before = count( $this->change_rows() );
		ob_start();
		$result = QueryFaultInjector::break_query_with_real_error( 'INSERT INTO `' . aafm_changes_table() . '`', fn() => aafm_undo_apply( $change, 'owner' ) );
		ob_end_clean();

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_read_failed', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'ready', aafm_undo_get_view( $change )['row']['status'] );
		$this->assertCount( $before, $this->change_rows() );
		$this->assertNotEmpty( $wpdb->prefix );
	}

	public function test_create_revert_on_a_post_with_more_comments_than_the_bound_is_refused(): void {
		global $wpdb;
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		$values = array();
		for ( $i = 0; $i < 1001; ++$i ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s, %s)', $post, 'c' . $i, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- fixture rows built from prepared tuples.
		$wpdb->query( 'INSERT INTO ' . $wpdb->comments . ' (comment_post_ID, comment_content, comment_date, comment_date_gmt) VALUES ' . implode( ',', $values ) );

		$this->assert_refused( (int) $result['undo']['change_ids'][0], 'read_failed' );
		$this->assertNotSame( 'trash', get_post( $post )->post_status );
	}

	public function test_terms_revert_is_exact_with_a_stale_object_terms_cache(): void {
		$post = self::factory()->post->create();
		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		// Prime the term query cache while the post has only its default category, and note its version.
		$args   = array(
			'fields'                 => 'tt_ids',
			'orderby'                => 'none',
			'update_term_meta_cache' => false,
		);
		$stale  = wp_get_object_terms( $post, 'category', $args );
		$stamp  = wp_cache_get( 'last_changed', 'terms' );
		$change = $this->change_from(
			'aafm/add-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'category',
				'term_ids' => array( $term ),
			)
		);
		// Put the version back so the cached, now wrong, answer is served again.
		wp_cache_set( 'last_changed', $stamp, 'terms' );
		$this->assertSame( $stale, wp_get_object_terms( $post, 'category', $args ), 'precondition: the cache is stale' );

		$result = aafm_undo_apply( $change, 'owner' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		wp_cache_flush();
		$this->assertNotContains( $term, array_map( 'intval', wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) ) ) );
	}

	public function test_term_description_with_an_iframe_is_refused_before_writing_for_a_site_admin(): void {
		$term = (int) self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'description' => 'x',
			)
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->term_taxonomy, array( 'description' => '<iframe src="https://example.test/x"></iframe>' ), array( 'term_id' => $term ) );
		clean_term_cache( $term, 'category' );
		$change = $this->change_from(
			'aafm/update-term',
			array(
				'taxonomy'    => 'category',
				'term_id'     => $term,
				'description' => 'plain',
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
		$this->assertSame( 'plain', get_term( $term, 'category' )->description );
	}
}
