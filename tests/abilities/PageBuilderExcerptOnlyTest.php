<?php
/**
 * Excerpt-only edits on a page-builder-owned post: allowed through update-post, update-page and
 * update-cpt-item, with the stored body left byte for byte as it was.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class PageBuilderExcerptOnlyTest extends TestCase {

	/**
	 * Fallback markup that KSES would strip for a user without unfiltered_html.
	 */
	private const BODY = '<div class="fl-builder-content" data-x="1">Fallback<script>keep()</script></div>';

	/**
	 * The map_meta_cap filter that takes unfiltered_html away, so core re-filters the body on save.
	 *
	 * @var callable|null
	 */
	private $revoke = null;

	public function tear_down(): void {
		if ( null !== $this->revoke ) {
			remove_filter( 'map_meta_cap', $this->revoke, 10 );
			$this->revoke = null;
		}
		kses_remove_filters();
		parent::tear_down();
	}

	/**
	 * Act as an Editor who cannot post unfiltered HTML, with KSES active on save.
	 */
	private function act_as_filtered_editor(): void {
		$this->revoke = static function ( array $caps, string $cap ): array {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $this->revoke, 10, 2 );
		$this->acting_as( 'editor' ); // set_current_user runs kses_init(), which now adds the filters.
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'precondition: KSES filters the body on save' );
	}

	/**
	 * A post stored with raw fallback markup and the given builder's marker.
	 *
	 * @param string              $type    Post type.
	 * @param string              $body    Stored post_content.
	 * @param array<string,mixed> $markers Meta key => value.
	 * @return int
	 */
	private function builder_post( string $type, string $body, array $markers ): int {
		kses_remove_filters();
		$id = self::factory()->post->create(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_content' => $body,
				'post_excerpt' => 'Old excerpt',
			)
		);
		foreach ( $markers as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$this->assertSame( $body, get_post( $id )->post_content, 'precondition: raw body stored' );
		return $id;
	}

	/**
	 * One marker fixture per builder the shipped guard covers.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public static function builders(): array {
		return array(
			'elementor'       => array( 'elementor', self::BODY, array( '_elementor_data' => '[{"id":"el1","elType":"section"}]' ) ),
			'beaver-builder'  => array( 'beaver-builder', self::BODY, array( '_fl_builder_data' => array( 'node1' => array( 'type' => 'row' ) ) ) ),
			'divi'            => array( 'divi', self::BODY, array( 'et_pb_use_builder' => 'on' ) ),
			'avada'           => array( 'avada', self::BODY, array( 'fusion_builder_status' => 'active' ) ),
			'visual-composer' => array( 'visual-composer', self::BODY, array( 'vcv-pageContent' => '{"elements":{}}' ) ),
			'oxygen'          => array( 'oxygen', self::BODY, array( '_ct_builder_shortcodes' => '[ct_section ct_options=\'{"ct_id":2}\'][/ct_section]' ) ),
			'breakdance'      => array( 'breakdance', self::BODY, array( '_breakdance_data' => '{"tree_json_string":"{\"root\":{}}"}' ) ),
			'brizy'           => array( 'brizy', self::BODY, array( 'brizy_enabled' => '1' ) ),
			'bricks'          => array(
				'bricks',
				self::BODY,
				array(
					'_bricks_page_content_2' => array(
						array(
							'id'   => 'abc',
							'name' => 'section',
						),
					),
					'_bricks_editor_mode'    => 'bricks',
				),
			),
			'wpbakery'        => array( 'wpbakery', '[vc_row][vc_column]<script>keep()</script>Hi[/vc_column][/vc_row]', array() ),
		);
	}

	/**
	 * Each builder's post takes an excerpt-only edit and keeps its body and data.
	 *
	 * @dataProvider builders
	 *
	 * @param string              $builder Expected owner.
	 * @param string              $body    Stored body.
	 * @param array<string,mixed> $markers Marker meta.
	 */
	public function test_an_excerpt_only_update_post_lands_and_leaves_the_builder_data_alone( string $builder, string $body, array $markers ): void {
		$id = $this->builder_post( 'post', $body, $markers );
		$this->assertSame( $builder, aafm_post_has_foreign_builder_ownership( $id ) );
		$meta_before = get_post_meta( $id );
		$this->act_as_filtered_editor();

		$result = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'excerpt' => 'New <b>excerpt</b>',
			)
		);

		$this->assertIsArray( $result, $builder );
		clean_post_cache( $id );
		$this->assertSame( 'New excerpt', get_post( $id )->post_excerpt, 'The excerpt still goes through the plain-text sanitizer.' );
		$this->assertSame( $body, get_post( $id )->post_content, 'The body must stay byte for byte as stored.' );
		$this->assertSame( $meta_before, get_post_meta( $id ), 'The builder data must stay as it was.' );
		$this->assertSame( $builder, aafm_post_has_foreign_builder_ownership( $id ), 'The post is still owned by the same builder.' );
	}

	public function test_update_page_accepts_an_excerpt_only_edit_on_a_builder_page(): void {
		$id = $this->builder_post( 'page', self::BODY, array( '_fl_builder_data' => array( 'n' => array( 'type' => 'row' ) ) ) );
		$this->act_as_filtered_editor();

		$result = aafm_exec_update_page(
			array(
				'page_id' => $id,
				'excerpt' => 'Page excerpt',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Page excerpt', get_post( $id )->post_excerpt );
		$this->assertSame( self::BODY, get_post( $id )->post_content );
	}

	public function test_update_cpt_item_accepts_an_excerpt_only_edit_on_a_builder_post(): void {
		$id = $this->builder_post( 'post', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		$this->act_as_filtered_editor();

		$result = aafm_exec_update_cpt_item(
			array(
				'post_id' => $id,
				'excerpt' => 'Item excerpt',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Item excerpt', get_post( $id )->post_excerpt );
		$this->assertSame( self::BODY, get_post( $id )->post_content );
	}

	public function test_a_revision_saved_by_the_excerpt_edit_keeps_the_stored_body(): void {
		$id = $this->builder_post( 'post', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		$this->act_as_filtered_editor();

		$this->assertIsArray(
			aafm_exec_update_post(
				array(
					'post_id' => $id,
					'excerpt' => 'Revised excerpt',
				)
			)
		);

		$revisions = wp_get_post_revisions( $id );
		$this->assertNotEmpty( $revisions, 'precondition: the edit saved a revision' );
		$latest = reset( $revisions );
		$this->assertSame( self::BODY, $latest->post_content );
		$this->assertSame( 'Revised excerpt', $latest->post_excerpt );
	}

	/**
	 * Every other field update-post takes, each sent beside an excerpt.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function other_keys(): array {
		return array(
			'title'          => array( 'title', 'New title' ),
			'content'        => array( 'content', '<p>New body</p>' ),
			'status'         => array( 'status', 'draft' ),
			'slug'           => array( 'slug', 'new-slug' ),
			'featured_media' => array( 'featured_media', 123 ),
			'terms'          => array( 'terms', array( 'category' => array( 1 ) ) ),
			'meta'           => array( 'meta', array( 'custom_field' => 'v' ) ),
			'unknown'        => array( 'something_new', 'x' ),
		);
	}

	/**
	 * An excerpt sent with any other key is refused by all three tools.
	 *
	 * @dataProvider other_keys
	 *
	 * @param string $key   Extra key.
	 * @param mixed  $value Its value.
	 */
	public function test_an_excerpt_beside_any_other_key_is_refused_on_every_tool( string $key, $value ): void {
		$post = $this->builder_post( 'post', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		$page = $this->builder_post( 'page', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		$this->acting_as( 'administrator' );

		$calls = array(
			'update-post'     => array( 'aafm_exec_update_post', 'post_id', $post ),
			'update-page'     => array( 'aafm_exec_update_page', 'page_id', $page ),
			'update-cpt-item' => array( 'aafm_exec_update_cpt_item', 'post_id', $post ),
		);
		foreach ( $calls as $tool => list( $exec, $id_key, $id ) ) {
			$result = $exec(
				array(
					$id_key   => $id,
					'excerpt' => 'Should not land',
					$key      => $value,
				)
			);
			$this->assertInstanceOf( \WP_Error::class, $result, $tool );
			$this->assertSame( 'aafm_page_builder_owned', $result->get_error_code(), $tool );
			$this->assertSame( 'Old excerpt', get_post( $id )->post_excerpt, $tool );
			$this->assertSame( self::BODY, get_post( $id )->post_content, $tool );
		}
	}

	public function test_an_update_with_no_excerpt_is_still_refused(): void {
		$id = $this->builder_post( 'post', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		$this->acting_as( 'administrator' );

		$result = aafm_exec_update_post( array( 'post_id' => $id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_page_builder_owned', $result->get_error_code() );
	}

	public function test_an_excerpt_only_edit_is_refused_when_ownership_is_unknown(): void {
		$id = $this->builder_post(
			'post',
			self::BODY,
			array(
				'_elementor_data'  => '[{"id":"e"}]',
				'_fl_builder_data' => array( 'n' => array( 'type' => 'row' ) ),
			)
		);
		$this->assertSame( AAFM_BUILDER_OWNERSHIP_UNKNOWN, aafm_post_has_foreign_builder_ownership( $id ) );
		$this->acting_as( 'administrator' );

		$result = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'excerpt' => 'Should not land',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_page_builder_owned', $result->get_error_code() );
		$this->assertSame( 'Old excerpt', get_post( $id )->post_excerpt );
	}

	public function test_a_cached_body_that_differs_from_the_stored_row_is_refused_before_writing(): void {
		global $wpdb;
		$id = $this->builder_post( 'post', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		get_post( $id ); // Prime the cache, then change the row under it.
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'changed underneath' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the test needs a stale cache.
		$this->assertSame( self::BODY, get_post( $id )->post_content, 'precondition: the cache is stale' );
		$this->acting_as( 'administrator' );

		$result = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'excerpt' => 'Should not land',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_page_builder_owned', $result->get_error_code() );
		clean_post_cache( $id );
		$this->assertSame( 'Old excerpt', get_post( $id )->post_excerpt );
		$this->assertSame( 'changed underneath', get_post( $id )->post_content );
	}

	public function test_a_body_changed_during_the_write_is_rolled_back_and_reported_as_no_change(): void {
		global $wpdb;
		$id       = $this->builder_post( 'post', self::BODY, array( '_elementor_data' => '[{"id":"e"}]' ) );
		$modified = get_post( $id )->post_modified;
		$before   = wp_get_post_revisions( $id, array( 'fields' => 'ids' ) );
		$tamper   = static function ( int $post_id ) use ( $id, $wpdb ): void {
			if ( $post_id === $id ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => 'rewritten by another plugin' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- simulates a plugin rewriting the body on save.
				clean_post_cache( $id );
			}
		};
		add_action( 'save_post', $tamper );
		$this->act_as_filtered_editor();

		try {
			$result = aafm_exec_update_post(
				array(
					'post_id' => $id,
					'excerpt' => 'Should be undone',
				)
			);
		} finally {
			remove_action( 'save_post', $tamper );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_page_builder_content_changed', $result->get_error_code() );
		$this->assertStringContainsString( 'Nothing was changed', $result->get_error_message() );
		clean_post_cache( $id );
		$after = get_post( $id );
		$this->assertSame( self::BODY, $after->post_content );
		$this->assertSame( 'Old excerpt', $after->post_excerpt );
		$this->assertSame( $modified, $after->post_modified );
		$this->assertSame( $before, wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ), 'The revision saved by the undone edit is removed.' );
	}

	public function test_a_plain_post_still_uses_the_ordinary_path(): void {
		$id = self::factory()->post->create( array( 'post_content' => '<p>Plain</p>' ) );
		$this->acting_as( 'administrator' );

		$result = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'excerpt' => 'Plain excerpt',
				'title'   => 'Plain title',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Plain excerpt', get_post( $id )->post_excerpt );
	}
}
