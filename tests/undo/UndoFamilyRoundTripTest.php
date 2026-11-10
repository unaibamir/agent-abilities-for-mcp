<?php
/**
 * The covered tools UndoRoundTripTest does not reach: page and custom post type edits, blocks,
 * term meta, and the SEO plugin families on their stubs. Each reverts to the exact stored bytes.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\IntegrationStubs;

final class UndoFamilyRoundTripTest extends UndoTestCase {

	use IntegrationStubs;

	private const PAGE = "<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->";

	public function set_up(): void {
		parent::set_up();
		$this->force_integration( 'yoast' );
		$this->force_integration( 'rankmath' );
		$this->force_integration( 'slim_seo' );
		$this->stub_yoast();
		$this->stub_rankmath();
		aafm_flush_registry_cache();
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		aafm_flush_registry_cache();
		parent::tear_down();
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
	 * @param int $change_id Change id.
	 * @return void
	 */
	private function revert( int $change_id ): void {
		$result = aafm_undo_apply( $change_id, 'owner' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertSame( 'reverted', $result['outcome'] );
	}

	/**
	 * Raw stored rows of a post meta key.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return list<string>
	 */
	private function raw( int $post_id, string $key ): array {
		return aafm_meta_raw_snapshot( 'post', $post_id, array( $key ) )['by_key'][ $key ]['raw'];
	}

	public function test_update_page_round_trips_title_and_content(): void {
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Old page',
				'post_content' => wp_slash( 'Old body \\ with a backslash' ),
			)
		);
		$old    = get_post( $page )->post_content;
		$change = $this->change_from(
			'aafm/update-page',
			array(
				'page_id' => $page,
				'title'   => 'New page',
				'content' => 'New body',
			)
		);
		$this->revert( $change );
		$stored = get_post( $page );
		$this->assertSame( 'Old page', $stored->post_title );
		$this->assertSame( $old, $stored->post_content );
	}

	public function test_update_cpt_item_round_trips_the_title(): void {
		register_post_type(
			'aafm_thing',
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_thing' ) );
		$item   = self::factory()->post->create(
			array(
				'post_type'  => 'aafm_thing',
				'post_title' => 'Old thing',
			)
		);
		$result = $this->run_tool(
			'aafm/update-cpt-item',
			array(
				'post_id' => $item,
				'title'   => 'New thing',
			)
		);
		delete_option( 'aafm_allowed_post_types' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['undo']['undoable'], wp_json_encode( $result['undo'] ) );
		$this->revert( (int) $result['undo']['change_ids'][0] );
		$this->assertSame( 'Old thing', get_post( $item )->post_title );
		unregister_post_type( 'aafm_thing' );
	}

	public function test_replace_block_round_trips_the_content(): void {
		$post   = self::factory()->post->create( array( 'post_content' => self::PAGE ) );
		$change = $this->change_from(
			'aafm/replace-block',
			array(
				'post_id' => $post,
				'path'    => array( 1 ),
				'markup'  => "<!-- wp:paragraph -->\n<p>Replaced</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assertStringContainsString( 'Replaced', get_post( $post )->post_content );
		$this->revert( $change );
		$this->assertSame( self::PAGE, get_post( $post )->post_content );
	}

	public function test_delete_block_reverts_to_the_published_block(): void {
		$block  = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern',
				'post_content' => self::PAGE,
			)
		);
		$change = $this->change_from( 'aafm/delete-block', array( 'block_id' => $block ) );
		$this->assertSame( 'trash', get_post( $block )->post_status );
		$this->revert( $change );
		$this->assertSame( 'publish', get_post( $block )->post_status );
		$this->assertSame( 'pattern', get_post( $block )->post_name );
	}

	public function test_update_term_meta_round_trips_the_raw_value(): void {
		update_option( 'aafm_exposed_term_meta_keys', array( 'aafm_note' ) );
		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-term-meta',
			array(
				'taxonomy' => 'category',
				'term_id'  => $term,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'new',
			)
		);
		$this->assertSame( 'new', get_term_meta( $term, 'aafm_note', true ) );
		$this->revert( $change );
		$this->assertSame( 'old', get_term_meta( $term, 'aafm_note', true ) );
		delete_option( 'aafm_exposed_term_meta_keys' );
	}

	public function test_delete_term_meta_restores_the_deleted_row(): void {
		update_option( 'aafm_exposed_term_meta_keys', array( 'aafm_note' ) );
		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term, 'aafm_note', 'kept' );
		$change = $this->change_from(
			'aafm/delete-term-meta',
			array(
				'taxonomy' => 'category',
				'term_id'  => $term,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
			)
		);
		$this->assertSame( '', get_term_meta( $term, 'aafm_note', true ) );
		$this->revert( $change );
		$this->assertSame( 'kept', get_term_meta( $term, 'aafm_note', true ) );
		delete_option( 'aafm_exposed_term_meta_keys' );
	}

	public function test_yoast_fields_round_trip_on_the_stub(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_yoast_wpseo_title', 'Old SEO title' );
		$before_desc = $this->raw( $post, '_yoast_wpseo_metadesc' );
		$change      = $this->change_from(
			'aafm/yoast-update-post',
			array(
				'post_id'     => $post,
				'title'       => 'New SEO title',
				'description' => 'New description',
			)
		);
		$this->assertSame( array( 'New SEO title' ), $this->raw( $post, '_yoast_wpseo_title' ) );
		$this->revert( $change );
		$this->assertSame( array( 'Old SEO title' ), $this->raw( $post, '_yoast_wpseo_title' ) );
		$this->assertSame( $before_desc, $this->raw( $post, '_yoast_wpseo_metadesc' ) );
	}

	/**
	 * Install what The Events Calendar installs: a delete_post_metadata callback at priority 15 that only answers
	 * for a key the post does not have, plus pass-through callbacks on the other short-circuit hooks the restore runs.
	 *
	 * @return callable The remover.
	 */
	private function arm_harmless_meta_callbacks(): callable {
		$delete = static function ( $check, $object_id, $meta_key ) {
			return metadata_exists( 'post', (int) $object_id, (string) $meta_key ) ? $check : true;
		};
		$pass   = static fn( $check ) => $check;
		add_filter( 'delete_post_metadata', $delete, 15, 3 );
		add_filter( 'delete_post_metadata_by_mid', $pass );
		add_filter( 'add_post_metadata', $pass );
		return static function () use ( $delete, $pass ): void {
			remove_filter( 'delete_post_metadata', $delete, 15 );
			remove_filter( 'delete_post_metadata_by_mid', $pass );
			remove_filter( 'add_post_metadata', $pass );
		};
	}

	public function test_a_featured_image_revert_succeeds_beside_a_harmless_meta_delete_callback(): void {
		$post   = self::factory()->post->create();
		$image  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );
		$change = $this->change_from(
			'aafm/set-featured-image',
			array(
				'post_id'       => $post,
				'attachment_id' => $image,
			)
		);
		$remove = $this->arm_harmless_meta_callbacks();
		try {
			$this->revert( $change );
		} finally {
			$remove();
		}
		$this->assertSame( array(), $this->raw( $post, '_thumbnail_id' ) );
	}

	public function test_an_seo_field_revert_succeeds_beside_a_harmless_meta_delete_callback(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_yoast_wpseo_title', 'Old SEO title' );
		$change = $this->change_from(
			'aafm/yoast-update-post',
			array(
				'post_id' => $post,
				'title'   => 'New SEO title',
			)
		);
		$remove = $this->arm_harmless_meta_callbacks();
		try {
			$this->revert( $change );
		} finally {
			$remove();
		}
		$this->assertSame( array( 'Old SEO title' ), $this->raw( $post, '_yoast_wpseo_title' ) );
	}

	public function test_rank_math_robots_round_trip_keeps_the_serialized_row(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, 'rank_math_robots', array( 'index' ) );
		$before = $this->raw( $post, 'rank_math_robots' );
		$change = $this->change_from(
			'aafm/rankmath-update-post',
			array(
				'post_id' => $post,
				'robots'  => 'noindex,nofollow',
			)
		);
		$this->assertNotSame( $before, $this->raw( $post, 'rank_math_robots' ) );
		$this->revert( $change );
		$this->assertSame( $before, $this->raw( $post, 'rank_math_robots' ) );
	}

	public function test_rank_math_schema_round_trips(): void {
		$post   = self::factory()->post->create();
		$before = $this->raw( $post, 'rank_math_schema_Article' );
		$change = $this->change_from(
			'aafm/rankmath-update-schema',
			array(
				'post_id' => $post,
				'type'    => 'Article',
				'schema'  => array( 'headline' => 'H' ),
			)
		);
		$this->assertNotSame( $before, $this->raw( $post, 'rank_math_schema_Article' ) );
		$this->revert( $change );
		$this->assertSame( $before, $this->raw( $post, 'rank_math_schema_Article' ) );
	}

	public function test_slim_seo_round_trips_the_array_and_keeps_untouched_fields(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, 'slim_seo', array( 'description' => 'keep me' ) );
		$before = $this->raw( $post, 'slim_seo' );
		$change = $this->change_from(
			'aafm/slim-seo-update-post',
			array(
				'post_id' => $post,
				'title'   => 'Slim title',
			)
		);
		$this->assertNotSame( $before, $this->raw( $post, 'slim_seo' ) );
		$this->revert( $change );
		$this->assertSame( $before, $this->raw( $post, 'slim_seo' ) );
	}

	public function test_rank_math_robots_revert_invalidates_the_sitemap_entry(): void {
		$post   = self::factory()->post->create();
		$change = $this->change_from(
			'aafm/rankmath-update-post',
			array(
				'post_id' => $post,
				'robots'  => 'noindex',
			)
		);
		\RankMath\Sitemap\Cache_Watcher::$invalidated_post_ids = array();
		$this->revert( $change );
		$this->assertContains( $post, \RankMath\Sitemap\Cache_Watcher::$invalidated_post_ids );
	}

	public function test_a_title_only_rank_math_revert_leaves_the_sitemap_alone(): void {
		$post   = self::factory()->post->create();
		$change = $this->change_from(
			'aafm/rankmath-update-post',
			array(
				'post_id' => $post,
				'title'   => 'Only a title',
			)
		);
		\RankMath\Sitemap\Cache_Watcher::$invalidated_post_ids = array();
		$this->revert( $change );
		$this->assertSame( array(), \RankMath\Sitemap\Cache_Watcher::$invalidated_post_ids );
	}

	public function test_owner_meta_revert_refreshes_page_caches(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $post, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'new',
			)
		);
		$seen   = array();
		$spy    = static function ( $kind, $id ) use ( &$seen ): void {
			$seen[] = array( $kind, (int) $id );
		};
		add_action( 'aafm_agent_meta_written', $spy, 10, 2 );
		$this->revert( $change );
		remove_action( 'aafm_agent_meta_written', $spy, 10 );
		$this->assertContains( array( 'post', $post ), $seen );
	}
}
