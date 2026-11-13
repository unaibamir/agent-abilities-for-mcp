<?php
/**
 * Tests for aafm/avada-list-library.
 *
 * Library items are made the way Fusion_Builder_Library::create_layout() makes them (Avada Builder
 * 3.16.1): wp_insert_post(), add_post_meta() for _fusion_is_global, wp_set_object_terms() on
 * element_category. The post types and the taxonomy are registered with Avada's own arguments
 * that matter here (public false, capability_type post, map_meta_cap true).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class AvadaLibraryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		foreach ( array( 'fusion_element', 'fusion_template' ) as $type ) {
			register_post_type(
				$type,
				array(
					'public'          => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
				)
			);
		}
		register_taxonomy( 'element_category', array( 'fusion_element' ), array( 'hierarchical' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		unregister_taxonomy( 'element_category' );
		unregister_post_type( 'fusion_element' );
		unregister_post_type( 'fusion_template' );
		parent::tear_down();
	}

	/**
	 * One library item, as create_layout() makes it.
	 *
	 * @param string              $type    Post type.
	 * @param string              $title   Title.
	 * @param string              $term    element_category slug, or ''.
	 * @param array<string,mixed> $meta    Post meta.
	 * @param array<string,mixed> $postarr Extra post fields.
	 * @return int
	 */
	private function item( string $type, string $title, string $term = '', array $meta = array(), array $postarr = array() ): int {
		$id = self::factory()->post->create(
			$postarr + array(
				'post_type'    => $type,
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => '[fusion_text]' . $title . '[/fusion_text]',
			)
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}
		if ( '' !== $term ) {
			wp_set_object_terms( $id, $term, 'element_category' );
		}
		return $id;
	}

	/**
	 * Items keyed by id.
	 *
	 * @param array<string,mixed> $out Output.
	 * @return array<int,array<string,mixed>>
	 */
	private function by_id( array $out ): array {
		return array_column( $out['items'], null, 'id' );
	}

	public function test_it_is_a_registered_read_only_while_avada_is_active(): void {
		$this->assertSame( 'read', aafm_get_abilities_registry()['aafm/avada-list-library']['risk'] );

		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		add_filter( 'aafm_integration_active_avada', '__return_false' );
		aafm_flush_registry_cache();
		try {
			$this->assertArrayNotHasKey( 'aafm/avada-list-library', aafm_get_abilities_registry() );
		} finally {
			remove_filter( 'aafm_integration_active_avada', '__return_false' );
			add_filter( 'aafm_integration_active_avada', '__return_true' );
			aafm_flush_registry_cache();
		}
	}

	public function test_items_carry_kind_and_is_global_and_no_content_by_default(): void {
		$global   = $this->item( 'fusion_element', 'Shared promo', 'sections', array( '_fusion_is_global' => 'yes' ) );
		$button   = $this->item( 'fusion_element', 'Call button', 'elements' );
		$loose    = $this->item( 'fusion_element', 'No category' );
		$template = $this->item( 'fusion_template', 'Landing template' );

		$out   = aafm_exec_avada_list_library( array() );
		$items = $this->by_id( $out );

		$this->assertSame( 4, $out['total'] );
		$this->assertSame( 'sections', $items[ $global ]['kind'] );
		$this->assertTrue( $items[ $global ]['is_global'] );
		$this->assertSame( 'elements', $items[ $button ]['kind'] );
		$this->assertFalse( $items[ $button ]['is_global'] );
		$this->assertSame( 'uncategorized', $items[ $loose ]['kind'] );
		$this->assertSame( 'template', $items[ $template ]['kind'] );
		$this->assertSame( array( 'id', 'title', 'status', 'kind', 'is_global', 'modified_gmt' ), array_keys( $items[ $global ] ) );
		$this->assertSame( 'Shared promo', $items[ $global ]['title'] );
		$this->assertSame( 'publish', $items[ $global ]['status'] );
	}

	public function test_include_content_returns_the_raw_markup(): void {
		$id = $this->item( 'fusion_element', 'Call button', 'elements' );

		$items = $this->by_id( aafm_exec_avada_list_library( array( 'include_content' => true ) ) );

		$this->assertSame( '[fusion_text]Call button[/fusion_text]', $items[ $id ]['content'] );
	}

	public function test_kind_filters_by_category_or_template(): void {
		$section  = $this->item( 'fusion_element', 'A section', 'sections' );
		$column   = $this->item( 'fusion_element', 'A column', 'columns' );
		$card     = $this->item( 'fusion_element', 'A card', 'post_cards' );
		$template = $this->item( 'fusion_template', 'A template' );

		$this->assertSame( array( $section ), array_column( aafm_exec_avada_list_library( array( 'kind' => 'sections' ) )['items'], 'id' ) );
		$this->assertSame( array( $column ), array_column( aafm_exec_avada_list_library( array( 'kind' => 'columns' ) )['items'], 'id' ) );
		$this->assertSame( array( $card ), array_column( aafm_exec_avada_list_library( array( 'kind' => 'post_cards' ) )['items'], 'id' ) );
		$this->assertSame( array( $template ), array_column( aafm_exec_avada_list_library( array( 'kind' => 'templates' ) )['items'], 'id' ) );
		$this->assertSame( 0, aafm_exec_avada_list_library( array( 'kind' => 'elements' ) )['total'] );
	}

	public function test_search_and_paging(): void {
		$this->item( 'fusion_element', 'Promo one', 'sections' );
		$this->item( 'fusion_element', 'Promo two', 'sections' );
		$this->item( 'fusion_element', 'Footer bar', 'sections' );

		$found = aafm_exec_avada_list_library( array( 'search' => 'Promo' ) );
		$this->assertSame( 2, $found['total'] );

		$page = aafm_exec_avada_list_library(
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);
		$this->assertSame( 3, $page['total'] );
		$this->assertCount( 1, $page['items'] );
	}

	public function test_trashed_items_are_left_out(): void {
		$id = $this->item( 'fusion_element', 'Gone', 'elements' );
		wp_trash_post( $id );

		$this->assertSame( 0, aafm_exec_avada_list_library( array() )['total'] );
	}

	public function test_a_contributor_sees_only_their_own_items_and_total_discloses_the_rest(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		// create_layout() saves a contributor's item as pending (no publish_posts).
		$own = $this->item(
			'fusion_element',
			'Mine',
			'elements',
			array(),
			array(
				'post_author' => $contributor,
				'post_status' => 'pending',
			)
		);
		$this->item( 'fusion_element', 'Theirs', 'elements' );
		wp_set_current_user( $contributor );

		$out = aafm_exec_avada_list_library( array() );

		$this->assertSame( array( $own ), array_column( $out['items'], 'id' ) );
		$this->assertSame( 2, $out['total'] );
	}

	public function test_a_failed_meta_read_is_an_error_not_a_non_global_item(): void {
		$id = $this->item( 'fusion_element', 'Shared promo', 'sections', array( '_fusion_is_global' => 'yes' ) );
		wp_cache_delete( $id, 'post_meta' );
		QueryFaultInjector::reset_fired_count();

		$out = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT post_id, meta_key, meta_value FROM', 'postmeta' ),
			static function () {
				return aafm_exec_avada_list_library( array() );
			}
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $out, (string) wp_json_encode( $out ) );
	}

	public function test_a_subscriber_is_refused_and_a_contributor_allowed(): void {
		$args = aafm_args_avada_list_library();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertTrue( (bool) call_user_func( $args['permission_callback'], array() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( (bool) call_user_func( $args['permission_callback'], array() ) );
	}

	public function test_schema_and_annotations(): void {
		$args = aafm_args_avada_list_library();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'all', 'elements', 'columns', 'sections', 'post_cards', 'templates' ), $args['input_schema']['properties']['kind']['enum'] );
		$this->assertEqualsCanonicalizing( array( 'per_page', 'page', 'kind', 'search', 'include_content' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( array( 'items', 'total' ), array_keys( $args['output_schema']['properties'] ) );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		$this->assertSame( 'aafm-reads', $args['category'] );
	}
}
