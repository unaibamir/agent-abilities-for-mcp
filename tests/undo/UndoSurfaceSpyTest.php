<?php
/**
 * A tool's declared surface contains every write the tool actually makes.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoSurfaceSpyTest extends UndoTestCase {

	/**
	 * Meta keys core and this plugin write on their own around a post write; they are not the tool's surface.
	 */
	private const CORE_META = array( '_edit_last', '_edit_lock', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug', '_encloseme', '_pingme', '_aafm_agent_tool', '_aafm_agent_user', '_aafm_agent_client' );

	/**
	 * Run a tool while recording every meta key and taxonomy it writes.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return array{meta: list<string>, terms: list<string>}
	 */
	private function spy( string $ability, array $input ): array {
		$meta     = array();
		$terms    = array();
		$on_meta  = static function ( $mid, $object_id, $key ) use ( &$meta ): void {
			$meta[] = (string) $key;
		};
		$on_terms = static function ( $object_id, $names, $tt_ids, $taxonomy ) use ( &$terms ): void {
			$terms[] = (string) $taxonomy;
		};
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, $on_meta, 10, 3 );
		}
		add_action( 'set_object_terms', $on_terms, 10, 4 );
		$this->run_tool( $ability, $input );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			remove_action( $hook, $on_meta, 10 );
		}
		remove_action( 'set_object_terms', $on_terms, 10 );
		return array(
			'meta'  => array_values( array_diff( array_unique( $meta ), self::CORE_META ) ),
			'terms' => array_values( array_unique( $terms ) ),
		);
	}

	public function test_declared_surface_contains_every_actual_write(): void {
		$this->acting_as( 'administrator' );
		$post  = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );
		$term  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$input = array(
			'post_id'        => $post,
			'title'          => 'New',
			'content'        => 'Body',
			'excerpt'        => 'Ex',
			'status'         => 'draft',
			'slug'           => 'spy-slug',
			'featured_media' => $image,
			'terms'          => array( 'category' => array( $term ) ),
			'meta'           => array( 'aafm_note' => 'n' ),
		);

		$touched  = $this->spy( 'aafm/update-post', $input );
		$declared = aafm_undo_surfaces()['aafm/update-post']['fields']( $input );

		foreach ( $touched['meta'] as $key ) {
			$this->assertContains( 'meta:' . $key, $declared, "update-post wrote meta {$key} outside its declared surface." );
		}
		foreach ( $touched['terms'] as $taxonomy ) {
			$this->assertContains( 'terms:' . $taxonomy, $declared, "update-post wrote taxonomy {$taxonomy} outside its declared surface." );
		}
		$this->assertContains( 'meta:aafm_note', $touched['meta'] ? array_map( static fn( $k ) => 'meta:' . $k, $touched['meta'] ) : array(), 'precondition: the spy saw the meta write.' );
	}

	/**
	 * Tools beside update-post whose meta and term writes the spy can see without a vendor plugin.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_other_post_tools(): array {
		return array(
			'update-page'        => array( 'aafm/update-page' ),
			'update-post-meta'   => array( 'aafm/update-post-meta' ),
			'delete-post-meta'   => array( 'aafm/delete-post-meta' ),
			'add-post-terms'     => array( 'aafm/add-post-terms' ),
			'set-featured-image' => array( 'aafm/set-featured-image' ),
		);
	}

	/**
	 * Every meta key and taxonomy one of these tools writes sits inside what its surface declares.
	 *
	 * @dataProvider data_other_post_tools
	 *
	 * @param string $ability Ability name.
	 */
	public function test_other_tools_declare_every_actual_write( string $ability ): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$post  = self::factory()->post->create(
			array(
				'post_type'  => 'aafm/update-page' === $ability ? 'page' : 'post',
				'post_title' => 'Old',
			)
		);
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );
		$term  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		add_post_meta( $post, 'aafm_note', 'before' );
		$inputs = array(
			'aafm/update-page'        => array(
				'page_id' => $post,
				'title'   => 'New',
				'meta'    => array( 'aafm_note' => 'n' ),
			),
			'aafm/update-post-meta'   => array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'meta_key' => 'aafm_note',
				'value'    => 'n',
			),
			'aafm/delete-post-meta'   => array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'meta_key' => 'aafm_note',
			),
			'aafm/add-post-terms'     => array(
				'post_id'  => $post,
				'taxonomy' => 'category',
				'term_ids' => array( $term ),
			),
			'aafm/set-featured-image' => array(
				'post_id'       => $post,
				'attachment_id' => $image,
			),
		);
		$input  = $inputs[ $ability ];

		$touched  = $this->spy( $ability, $input );
		$declared = aafm_undo_surfaces()[ $ability ]['fields']( $input );

		$this->assertNotSame( array(), array_merge( $touched['meta'], $touched['terms'] ), 'precondition: the spy saw a write.' );
		foreach ( $touched['meta'] as $key ) {
			$this->assertContains( 'meta:' . $key, $declared, "{$ability} wrote meta {$key} outside its declared surface." );
		}
		foreach ( $touched['terms'] as $taxonomy ) {
			$this->assertContains( 'terms:' . $taxonomy, $declared, "{$ability} wrote taxonomy {$taxonomy} outside its declared surface." );
		}
	}

	public function test_trash_post_writes_only_core_trash_meta_beyond_its_declared_fields(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$touched = $this->spy( 'aafm/trash-post', array( 'post_id' => $post ) );

		$this->assertSame( array(), $touched['meta'], 'Trash meta is core bookkeeping; nothing else is written.' );
		// Core re-sets the default category on every post update, a no-op by value that still fires the hook.
		$this->assertSame( array(), array_diff( $touched['terms'], array( 'category' ) ) );
	}
}
