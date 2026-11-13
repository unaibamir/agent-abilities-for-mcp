<?php
/**
 * Tests for aafm/avada-list-layouts.
 *
 * Every layout body and the Global Layout option below are byte copies of what Avada Builder
 * 3.16.1 stored on a disposable site through Fusion_Template_Builder::update_layout_content()
 * (tests/Fixtures/Avada/). Only the section ids 13 and 14 are swapped for this test's own posts.
 * The "Shop's layout" body holds a condition label with an apostrophe, which Avada's own decode
 * (get_conditions(), set_overrides()) cannot read: get_conditions() returned false for it there.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class AvadaLayoutsTest extends TestCase {

	/**
	 * The published header section.
	 *
	 * @var int
	 */
	private $header;

	/**
	 * The draft footer section.
	 *
	 * @var int
	 */
	private $footer;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		register_post_type( 'fusion_tb_layout', array( 'public' => false ) );
		register_post_type( 'fusion_tb_section', array( 'public' => false ) );
		$this->header = self::factory()->post->create(
			array(
				'post_type'   => 'fusion_tb_section',
				'post_title'  => 'Main header',
				'post_status' => 'publish',
			)
		);
		$this->footer = self::factory()->post->create(
			array(
				'post_type'   => 'fusion_tb_section',
				'post_title'  => 'Shop footer',
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		unregister_post_type( 'fusion_tb_layout' );
		unregister_post_type( 'fusion_tb_section' );
		parent::tear_down();
	}

	private function fixture( string $name ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$raw = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/' . $name );
		return strtr(
			$raw,
			array(
				':13' => ':' . $this->header,
				':14' => ':' . $this->footer,
			)
		);
	}

	private function layout( string $title, string $status, string $fixture, string $date ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'fusion_tb_layout',
				'post_title'  => $title,
				'post_status' => $status,
				'post_date'   => $date,
			)
		);
		// Stored exactly as Avada stored it: a direct row update, since wp_update_post() would unslash.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $this->fixture( $fixture ) ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * Three layouts, the Global Layout and the order, as Avada stored them.
	 *
	 * @return array{shop:int,blog:int,events:int}
	 */
	private function seed(): array {
		$ids = array(
			'shop'   => $this->layout( "Shop's layout", 'publish', 'layout-shop.txt', '2026-01-01 10:00:00' ),
			'blog'   => $this->layout( 'Blog layout', 'publish', 'layout-blog.txt', '2026-01-02 10:00:00' ),
			'events' => $this->layout( 'Events layout', 'draft', 'layout-events.txt', '2026-01-03 10:00:00' ),
		);
		update_option( 'fusion_tb_layout_default', $this->fixture( 'tb-layout-default.txt' ) );
		update_option( 'fusion_builder_settings', array( 'awb_layout_order' => 'global,' . $ids['blog'] . ',' . $ids['shop'] ) );
		return $ids;
	}

	/**
	 * Layouts keyed by id.
	 *
	 * @param array<string,mixed> $out Output.
	 * @return array<int|string,array<string,mixed>> Layouts keyed by id.
	 */
	private function by_id( array $out ): array {
		$keyed = array();
		foreach ( $out['layouts'] as $layout ) {
			$keyed[ $layout['id'] ] = $layout;
		}
		return $keyed;
	}

	public function test_it_is_a_registered_read_only_while_avada_is_active(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/avada-list-layouts', $registry );
		$this->assertSame( 'read', $registry['aafm/avada-list-layouts']['risk'] );
		$this->assertSame( 'avada', $registry['aafm/avada-list-layouts']['subject'] );

		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		add_filter( 'aafm_integration_active_avada', '__return_false' );
		aafm_flush_registry_cache();
		try {
			$this->assertArrayNotHasKey( 'aafm/avada-list-layouts', aafm_get_abilities_registry() );
		} finally {
			remove_filter( 'aafm_integration_active_avada', '__return_false' );
			add_filter( 'aafm_integration_active_avada', '__return_true' );
			aafm_flush_registry_cache();
		}
	}

	public function test_layouts_follow_awb_layout_order_and_the_global_layout_comes_last(): void {
		$ids = $this->seed();

		$out = aafm_exec_avada_list_layouts( array() );

		$this->assertSame( array( $ids['blog'], $ids['shop'], $ids['events'], 'global' ), array_column( $out['layouts'], 'id' ) );
		$this->assertSame( array( 0, 1, 2, 3 ), array_column( $out['layouts'], 'order' ) );
		$this->assertTrue( $out['global_stored'] );
		$this->assertFalse( $out['truncated'] );
		$global = $this->by_id( $out )['global'];
		$this->assertSame( 'publish', $global['status'] );
		$this->assertSame( 'ok', $global['parse_status'] );
		$this->assertSame( array(), $global['conditions'] );
	}

	public function test_without_a_stored_order_layouts_come_in_query_order(): void {
		$ids = $this->seed();
		delete_option( 'fusion_builder_settings' );

		$out = aafm_exec_avada_list_layouts( array() );

		// WP_Query's default order, newest first, as Avada's own query.
		$this->assertSame( array( $ids['events'], $ids['blog'], $ids['shop'], 'global' ), array_column( $out['layouts'], 'id' ) );
	}

	public function test_missing_and_draft_sections_are_reported_as_such(): void {
		$ids = $this->seed();

		$layouts = $this->by_id( aafm_exec_avada_list_layouts( array() ) );

		$blog = $layouts[ $ids['blog'] ];
		$this->assertSame( 'ok', $blog['parse_status'] );
		$this->assertSame(
			array(
				'id'     => $this->header,
				'exists' => true,
				'title'  => 'Main header',
				'status' => 'publish',
			),
			$blog['sections']->header
		);
		$this->assertSame(
			array(
				'id'     => 999999,
				'exists' => false,
			),
			$blog['sections']->content
		);
		$global = $layouts['global'];
		$this->assertSame( 'draft', $global['sections']->footer['status'] );
		$this->assertSame( 'Shop footer', $global['sections']->footer['title'] );
	}

	public function test_conditions_are_returned_as_stored_including_a_child_condition(): void {
		$ids = $this->seed();

		$events = $this->by_id( aafm_exec_avada_list_layouts( array() ) )[ $ids['events'] ];

		$this->assertSame( 'draft', $events['status'] );
		$this->assertSame(
			array(
				array(
					'label'    => 'All Posts',
					'type'     => 'singular',
					'mode'     => 'include',
					'singular' => 'singular_post',
				),
				array(
					'label'    => 'Hello world',
					'type'     => 'singular',
					'mode'     => 'exclude',
					'singular' => 'post_5',
					'parent'   => 'singular_post',
				),
			),
			$events['conditions']
		);
		$this->assertSame( array( 'footer' ), array_keys( (array) $events['sections'] ) );
	}

	public function test_a_layout_avada_cannot_decode_is_unparseable_not_empty(): void {
		$ids = $this->seed();

		$shop = $this->by_id( aafm_exec_avada_list_layouts( array() ) )[ $ids['shop'] ];

		$this->assertSame( "Shop's layout", $shop['title'] );
		$this->assertSame( 'unparseable', $shop['parse_status'] );
		$this->assertArrayNotHasKey( 'sections', $shop );
		$this->assertArrayNotHasKey( 'conditions', $shop );
	}

	public function test_a_section_that_fails_to_load_is_an_error_not_a_missing_section(): void {
		$this->seed();
		clean_post_cache( $this->header );
		QueryFaultInjector::reset_fired_count();

		$out = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT', 'posts', 'ID = ' . $this->header ),
			static function () {
				return aafm_exec_avada_list_layouts( array() );
			}
		);

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_include_conditions_false_omits_conditions(): void {
		$ids = $this->seed();

		$layouts = $this->by_id( aafm_exec_avada_list_layouts( array( 'include_conditions' => false ) ) );

		$this->assertArrayNotHasKey( 'conditions', $layouts[ $ids['events'] ] );
		$this->assertArrayNotHasKey( 'conditions', $layouts['global'] );
		$this->assertArrayHasKey( 'sections', $layouts[ $ids['events'] ] );
	}

	public function test_global_stored_is_false_and_no_global_row_without_the_option(): void {
		$ids = $this->seed();
		delete_option( 'fusion_tb_layout_default' );

		$out = aafm_exec_avada_list_layouts( array() );

		$this->assertFalse( $out['global_stored'] );
		$this->assertNotContains( 'global', array_column( $out['layouts'], 'id' ) );
		$this->assertCount( 3, $out['layouts'] );
		$this->assertArrayHasKey( $ids['blog'], $this->by_id( $out ) );
	}

	public function test_trashed_layouts_are_left_out(): void {
		$ids = $this->seed();
		wp_trash_post( $ids['blog'] );

		$out = aafm_exec_avada_list_layouts( array() );

		$this->assertNotContains( $ids['blog'], array_column( $out['layouts'], 'id' ) );
	}

	public function test_more_than_two_hundred_layouts_are_truncated(): void {
		self::factory()->post->create_many(
			201,
			array(
				'post_type'    => 'fusion_tb_layout',
				'post_status'  => 'publish',
				'post_content' => '{"conditions":[],"template_terms":[]}',
			)
		);
		update_option( 'fusion_tb_layout_default', $this->fixture( 'tb-layout-default.txt' ) );

		$out = aafm_exec_avada_list_layouts( array() );

		$this->assertTrue( $out['truncated'] );
		$this->assertCount( 201, $out['layouts'], '200 layouts plus the Global Layout.' );
		$this->assertSame( 'global', end( $out['layouts'] )['id'] );
	}

	public function test_an_editor_is_refused_and_an_admin_allowed(): void {
		$args = aafm_args_avada_list_layouts();
		$this->assertTrue( (bool) call_user_func( $args['permission_callback'], array() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( (bool) call_user_func( $args['permission_callback'], array() ) );
	}

	public function test_schema_and_annotations(): void {
		$args = aafm_args_avada_list_layouts();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'include_conditions' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( array( 'layouts', 'global_stored', 'truncated' ), array_keys( $args['output_schema']['properties'] ) );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		$this->assertFalse( $args['meta']['annotations']['destructive'] );
		$this->assertTrue( $args['meta']['annotations']['idempotent'] );
		$this->assertSame( 'aafm-reads', $args['category'] );
	}

	/**
	 * Avada's get_override() loops over every layout with no break, so the last match wins
	 * (fusion-builder/inc/class-fusion-template-builder.php, get_override() and get_search_override()).
	 */
	public function test_the_descriptions_say_the_last_matching_layout_wins(): void {
		$order = aafm_args_avada_list_layouts()['output_schema']['properties']['layouts']['items']['properties']['order']['description'];
		$this->assertStringContainsString( 'last layout whose conditions match', $order );
		$this->assertStringNotContainsString( 'first layout', $order );
		$this->assertStringContainsString( 'last layout whose conditions match', aafm_ability_description( 'aafm/avada-list-layouts' ) );
		$this->assertStringContainsString( 'last match', aafm_ability_disclosures()['aafm/avada-list-layouts'] );
	}
}
