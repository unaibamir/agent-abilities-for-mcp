<?php
/**
 * The shared builder engine (includes/builder-shortcodes.php): outline with byte offsets, tree,
 * fingerprints, resolve, serializer, own post types, the ownership rule, the vendor seam, the
 * expected outline, the anchor point and the guarded commit.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use WP_Error;
use WP_Post;

final class BuilderShortcodesTest extends TestCase {

	/**
	 * Non-page data files already in tests/Fixtures/Avada/ (serialized options and layouts).
	 */
	private const NOT_PAGES = array( 'fusion-options.txt', 'layout-blog.txt', 'layout-events.txt', 'layout-shop.txt', 'tb-layout-default.txt' );

	private const TREE = '[vc_row][vc_column width="1/2"][vc_column_text]<p>One.</p>[/vc_column_text][vc_custom_heading text="Two"][/vc_custom_heading][vc_btn title="Three"][/vc_btn][/vc_column][vc_column width="1/2"][/vc_column][/vc_row]';

	private const TAGS = array( 'vc_row', 'vc_column', 'vc_column_text', 'vc_custom_heading', 'vc_btn' );

	/**
	 * The five tuple keys the walker emits, in its own key order.
	 *
	 * @param array<string,mixed> $e Element.
	 * @return array<string,mixed>
	 */
	private function tuple( array $e ): array {
		return array(
			'tag'          => $e['tag'],
			'self_closing' => $e['self_closing'],
			'has_closer'   => $e['has_closer'],
			'atts'         => $e['atts'],
			'depth'        => $e['depth'],
		);
	}

	private function cut( string $content, array $e ): string {
		return substr( $content, $e['start'], $e['end'] - $e['start'] );
	}

	public function test_elements_equal_the_walker_on_every_page_fixture(): void {
		$read = 0;
		foreach ( array( 'Wpbakery', 'Avada' ) as $folder ) {
			foreach ( (array) glob( __DIR__ . '/Fixtures/' . $folder . '/*.txt' ) as $file ) {
				if ( 'Avada' === $folder && in_array( basename( (string) $file ), self::NOT_PAGES, true ) ) {
					continue;
				}
				$content = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
				$tags    = 'Wpbakery' === $folder ? aafm_wpbakery_shortcode_tags( $content ) : null;
				$walk    = aafm_fusion_shortcode_walk( $content, 0, $tags );
				$els     = aafm_builder_elements( $content, $tags ?? aafm_fusion_shortcode_tags() );
				$this->assertNotNull( $walk, (string) $file );
				$this->assertNotNull( $els, (string) $file );
				$this->assertNotEmpty( $walk, (string) $file );
				$this->assertSame( $walk, array_map( array( $this, 'tuple' ), $els ), (string) $file );
				++$read;
			}
		}
		$this->assertGreaterThanOrEqual( 2, $read );
	}

	public function test_offsets_cut_out_each_elements_exact_source(): void {
		$els = aafm_builder_elements( self::TREE, self::TAGS );
		$this->assertCount( 6, $els );
		foreach ( $els as $i => $e ) {
			$this->assertSame( $i, $e['index'] );
			$src = $this->cut( self::TREE, $e );
			$this->assertStringStartsWith( '[' . $e['tag'], $src );
			if ( $e['has_closer'] ) {
				$this->assertStringEndsWith( '[/' . $e['tag'] . ']', $src );
			}
			$this->assertSame( ']', self::TREE[ $e['open_end'] - 1 ] );
		}
		$this->assertNull( $els[0]['parent'] );
		$this->assertSame( 0, $els[1]['parent'] );
		$this->assertSame( 1, $els[2]['parent'] );
		$this->assertSame( '<p>One.</p>', substr( self::TREE, $els[2]['open_end'], $els[2]['inner_end'] - $els[2]['open_end'] ) );
		$this->assertSame( $els[5]['open_end'], $els[5]['inner_end'] );
	}

	public function test_unparseable_content_is_null(): void {
		$this->assertNull( aafm_builder_elements( '[vc_row][vc_column_text el_class="a[1]"]x[/vc_column_text][/vc_row]', array( 'vc_row', 'vc_column_text' ) ) );
	}

	public function test_empty_tag_list_returns_no_elements(): void {
		$this->assertSame( array(), aafm_builder_elements( self::TREE, array() ) );
	}

	public function test_fingerprint_is_16_hex_and_changes_with_a_child(): void {
		$els     = aafm_builder_elements( self::TREE, self::TAGS );
		$changed = str_replace( '<p>One.</p>', '<p>Onf.</p>', self::TREE );
		$after   = aafm_builder_elements( $changed, self::TAGS );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', aafm_builder_fingerprint( self::TREE, $els[0] ) );
		$this->assertNotSame( aafm_builder_fingerprint( self::TREE, $els[1] ), aafm_builder_fingerprint( $changed, $after[1] ) );
		$this->assertSame( aafm_builder_fingerprint( self::TREE, $els[5] ), aafm_builder_fingerprint( $changed, $after[5] ) );
	}

	public function test_identical_twins_share_a_fingerprint_and_differ_by_offset(): void {
		$content = '[vc_column][vc_btn title="Learn more"][/vc_btn][vc_btn title="Learn more"][/vc_btn][/vc_column]';
		$els     = aafm_builder_elements( $content, array( 'vc_column', 'vc_btn' ) );
		$fp      = aafm_builder_fingerprint( $content, $els[1] );

		$this->assertSame( $fp, aafm_builder_fingerprint( $content, $els[2] ) );
		$this->assertNotSame( $els[1]['start'], $els[2]['start'] );
		$got = aafm_builder_resolve(
			$content,
			array( 'vc_column', 'vc_btn' ),
			2,
			array(
				'tag'         => 'vc_btn',
				'fingerprint' => $fp,
			)
		);
		$this->assertIsArray( $got );
		$this->assertSame( $els[2]['start'], $got['start'] );
		$this->assertSame( $els[2]['end'], $got['end'] );
	}

	public function test_offsets_are_bytes_when_earlier_text_is_multibyte(): void {
		$content = '[vc_column][vc_custom_heading text="مرحبا 👋"][/vc_custom_heading][vc_column_text]<p>Café &amp; Crème</p>[/vc_column_text][/vc_column]';
		$els     = aafm_builder_elements( $content, array( 'vc_column', 'vc_custom_heading', 'vc_column_text' ) );

		$this->assertSame( '[vc_column_text]<p>Café &amp; Crème</p>[/vc_column_text]', $this->cut( $content, $els[2] ) );
	}

	public function test_tree_nests_children_and_keeps_each_nodes_exact_source(): void {
		$content = '[vc_row][vc_column width="1/2"][vc_column_text]A[/vc_column_text][/vc_column][vc_column width="1/2"][/vc_column][/vc_row][vc_row][/vc_row]';
		$tree    = aafm_builder_tree( $content, array( 'vc_row', 'vc_column', 'vc_column_text' ) );

		$this->assertCount( 2, $tree );
		$this->assertCount( 2, $tree[0]['children'] );
		$this->assertNull( $tree[0]['text'] );
		$this->assertSame( 'A', $tree[0]['children'][0]['children'][0]['text'] );
		$this->assertSame( array( 'width' => '1/2' ), $tree[0]['children'][0]['atts'] );
		$this->assertSame( $content, implode( '', array_column( $tree, 'source' ) ) );
		$this->assertNull( aafm_builder_tree( '[vc_row el_class="a[1]"][/vc_row]', array( 'vc_row' ) ) );
	}

	public function test_resolve_refuses_a_wrong_tag_a_stale_fingerprint_a_missing_index_and_index_2000(): void {
		$els = aafm_builder_elements( self::TREE, self::TAGS );
		$fp  = aafm_builder_fingerprint( self::TREE, $els[3] );
		$big = str_repeat( '[vc_btn /]', 2001 );
		$bfp = aafm_builder_fingerprint( $big, aafm_builder_elements( $big, array( 'vc_btn' ) )[2000] );

		foreach (
			array(
				aafm_builder_resolve(
					self::TREE,
					self::TAGS,
					3,
					array(
						'tag'         => 'vc_btn',
						'fingerprint' => $fp,
					)
				),
				aafm_builder_resolve(
					self::TREE,
					self::TAGS,
					3,
					array(
						'tag'         => 'vc_custom_heading',
						'fingerprint' => '0000000000000000',
					)
				),
				aafm_builder_resolve(
					self::TREE,
					self::TAGS,
					6,
					array(
						'tag'         => 'vc_btn',
						'fingerprint' => $fp,
					)
				),
				aafm_builder_resolve(
					$big,
					array( 'vc_btn' ),
					2000,
					array(
						'tag'         => 'vc_btn',
						'fingerprint' => $bfp,
					)
				),
			) as $out
		) {
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 'aafm_builder_element_mismatch', $out->get_error_code() );
			$this->assertSame( 409, $out->get_error_data()['status'] );
		}
		$this->assertIsArray(
			aafm_builder_resolve(
				self::TREE,
				self::TAGS,
				3,
				array(
					'tag'         => 'vc_custom_heading',
					'fingerprint' => $fp,
				)
			)
		);
		$this->assertSame( 'aafm_builder_unparseable', aafm_builder_resolve( '[vc_btn title="a[1]"]', array( 'vc_btn' ), 0, array() )->get_error_code() );
	}

	public function test_page_fingerprint_is_16_hex_over_the_whole_content(): void {
		$this->assertSame( substr( hash( 'sha256', self::TREE ), 0, 16 ), aafm_builder_page_fingerprint( self::TREE ) );
	}

	public function test_page_check_refuses_content_that_changed(): void {
		$expect = aafm_builder_page_fingerprint( self::TREE );
		$this->assertTrue( aafm_builder_page_check( self::TREE, $expect ) );

		$changed = str_replace( '[/vc_column][/vc_row]', '[vc_btn title="New"][/vc_btn][/vc_column][/vc_row]', self::TREE );
		$els     = aafm_builder_elements( self::TREE, self::TAGS );
		$after   = aafm_builder_elements( $changed, self::TAGS );
		$this->assertSame( aafm_builder_fingerprint( self::TREE, $els[3] ), aafm_builder_fingerprint( $changed, $after[3] ) );
		$out = aafm_builder_page_check( $changed, $expect );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_builder_page_changed', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
	}

	/**
	 * Ruling R-S9a-0 / R-S9a-2: WPBakery's editor writes a leaf with no content param without a closer
	 * (tests/Fixtures/Wpbakery/page-elements.txt), so null inner content writes none and '' an empty pair.
	 */
	public function test_serialize_wpbakery_writes_a_closer_only_when_inner_content_is_given(): void {
		$this->assertSame( '[vc_btn title="Go"]', aafm_builder_serialize( 'vc_btn', array( 'title' => 'Go' ), null, 'wpbakery' ) );
		$this->assertSame( '[vc_column][/vc_column]', aafm_builder_serialize( 'vc_column', array(), '', 'wpbakery' ) );
		$this->assertSame( '[vc_column_text css=""]<p>x</p>[/vc_column_text]', aafm_builder_serialize( 'vc_column_text', array( 'css' => '' ), '<p>x</p>', 'wpbakery' ) );
	}

	public function test_serialize_avada_keeps_empty_attributes_unless_the_site_removes_them(): void {
		delete_option( 'fusion_builder_settings' );
		$atts = array(
			'link'   => '',
			'target' => '_self',
		);
		$this->assertSame( '[fusion_button link="" target="_self" /]', aafm_builder_serialize( 'fusion_button', $atts, null, 'avada_void' ) );

		update_option( 'fusion_builder_settings', array( 'remove_empty_attributes' => 'on' ) );
		$this->assertSame( '[fusion_button target="_self" /]', aafm_builder_serialize( 'fusion_button', $atts, null, 'avada_void' ) );
		delete_option( 'fusion_builder_settings' );
	}

	public function test_serialize_avada_closed_writes_a_closing_tag_for_empty_content(): void {
		$this->assertSame( '[fusion_text][/fusion_text]', aafm_builder_serialize( 'fusion_text', array(), '', 'avada_closed' ) );
		$this->assertSame( '[fusion_text][/fusion_text]', aafm_builder_serialize( 'fusion_text', array(), null, 'avada_closed' ) );
	}

	public function test_own_post_types_are_the_closed_lists(): void {
		$this->assertSame( array( 'fusion_tb_section', 'fusion_template', 'fusion_element', 'fusion_form', 'awb_off_canvas' ), aafm_builder_own_post_types( 'avada' ) );
		$this->assertSame( array( 'vc_grid_item', 'templatera' ), aafm_builder_own_post_types( 'wpbakery' ) );
		$this->assertSame( array(), aafm_builder_own_post_types( 'elementor' ) );
	}

	public function test_ownership_allows_only_the_three_cases(): void {
		$page    = new WP_Post(
			(object) array(
				'post_type'    => 'page',
				'post_content' => 'x',
			)
		);
		$empty   = new WP_Post(
			(object) array(
				'post_type'    => 'page',
				'post_content' => "  \n",
			)
		);
		$section = new WP_Post(
			(object) array(
				'post_type'    => 'fusion_tb_section',
				'post_content' => 'x',
			)
		);

		$this->assertTrue( aafm_builder_ownership_allows( 'avada', 'avada', $page ) );
		$this->assertTrue( aafm_builder_ownership_allows( 'avada', false, $section ) );
		$this->assertTrue( aafm_builder_ownership_allows( 'avada', false, $empty, true ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'avada', 'elementor', $section ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'avada', 'unknown', $page ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'avada', false, $page ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'avada', false, $page, true ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'avada', false, $empty ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'wpbakery', false, $section ) );
		$this->assertFalse( aafm_builder_ownership_allows( 'avada', 'unknown', $empty, true ) );
	}

	public function test_vendor_entry_can_be_forced_absent_and_present(): void {
		$absent = static fn() => null;
		add_filter( 'aafm_builder_vendor', $absent );
		$this->assertNull( aafm_builder_vendor( 'wpbakery_css_rebuild' ) );
		remove_filter( 'aafm_builder_vendor', $absent );

		$present = static fn() => 'strlen';
		add_filter( 'aafm_builder_vendor', $present );
		$this->assertIsCallable( aafm_builder_vendor( 'wpbakery_css_rebuild' ) );
		remove_filter( 'aafm_builder_vendor', $present );

		$this->assertNull( aafm_builder_vendor( 'no_such_entry' ) );
	}

	public function test_every_seam_row_is_a_callable_shape(): void {
		$rows = aafm_builder_vendor_rows();
		$this->assertSame( array( 'wpbakery_css_rebuild', 'wpbakery_grid_post_type', 'wpbakery_param_type', 'wpbakery_map_all', 'wpbakery_map_one', 'wpbakery_grid_mapper', 'wpbakery_editor_post_types', 'wpbakery_user_access', 'wpbakery_settings', 'wpbakery_modules', 'avada_cache_reset', 'avada_template_builder', 'avada_studio', 'avada_studio_import', 'avada_studio_registered', 'avada_option_name', 'avada_map_loader', 'avada_map' ), array_keys( $rows ) );
		$this->assertSame( 'awb_maybe_init_element_maps', $rows['avada_map_loader']['target'] );
		$this->assertSame( 'awb_maybe_init_element_maps', $rows['avada_map_loader']['requires'] );
		$this->assertSame( 'awb_maybe_init_element_maps', $rows['avada_map']['requires'] );
		foreach ( array( 'avada_map_loader', 'avada_map' ) as $absent ) {
			$this->assertNull( aafm_builder_vendor( $absent ), $absent . ' is absent without Avada Builder loaded.' );
		}
		$this->assertSame( 'vc_editor_post_types', $rows['wpbakery_editor_post_types']['target'] );
		$this->assertSame( 'vc_editor_post_types', $rows['wpbakery_editor_post_types']['requires'] );
		$this->assertSame( 'vc_user_access', $rows['wpbakery_user_access']['target'] );
		$this->assertSame( 'vc_user_access', $rows['wpbakery_user_access']['requires'] );
		$this->assertSame( 'vc_map', $rows['wpbakery_map_all']['requires'] );
		$this->assertSame( 'vc_map', $rows['wpbakery_map_one']['requires'] );
		$this->assertSame( 'vc_grid_item_map_shortcodes', $rows['wpbakery_grid_mapper']['target'] );
		$this->assertSame( 'vc_grid_item_map_shortcodes', $rows['wpbakery_grid_mapper']['requires'] );
		foreach ( array( 'wpbakery_map_all', 'wpbakery_map_one', 'wpbakery_grid_mapper', 'wpbakery_editor_post_types', 'wpbakery_user_access' ) as $absent ) {
			$this->assertNull( aafm_builder_vendor( $absent ), $absent . ' is absent without WPBakery loaded.' );
		}
		foreach ( array(
			'wpbakery_settings'   => 'vc_settings',
			'wpbakery_modules'    => 'vc_modules_manager',
			'avada_cache_reset'   => 'fusion_reset_all_caches',
			'avada_studio'        => 'AWB_Studio',
			'avada_studio_import' => 'AWB_Studio_Import',
		) as $entry => $function ) {
			$this->assertSame( $function, $rows[ $entry ]['target'], $entry );
			$this->assertSame( $function, $rows[ $entry ]['requires'], $entry );
			$this->assertNull( aafm_builder_vendor( $entry ), $entry . ' is absent without the vendor loaded.' );
		}
		$this->assertSame( array( 'Fusion_Template_Builder', 'get_instance' ), $rows['avada_template_builder']['target'] );
		$this->assertNull( aafm_builder_vendor( 'avada_template_builder' ), 'Absent without Avada Builder loaded.' );
		$this->assertInstanceOf( \Closure::class, $rows['avada_studio_registered']['target'] );
		$this->assertSame( 'Avada', $rows['avada_studio_registered']['requires'] );
		$this->assertNull( aafm_builder_vendor( 'avada_studio_registered' ), 'Absent without the theme\'s Avada() function.' );
		$this->assertSame( array( 'Avada', 'get_option_name' ), $rows['avada_option_name']['target'] );
		$this->assertSame( 'Avada', $rows['avada_option_name']['requires'], 'The theme\'s own Avada() function, so a loaded stand-in class alone never makes the row present.' );
		foreach ( $rows as $entry => $row ) {
			$target = $row['target'];
			$ok     = $target instanceof \Closure
				|| ( is_string( $target ) && '' !== $target && ! class_exists( $target, false ) )
				|| ( is_array( $target ) && 2 === count( $target ) && is_string( $target[0] ) && is_string( $target[1] ) );
			$this->assertTrue( $ok, $entry );
		}
	}

	/**
	 * The avada_map row runs the part of fusion_builder_map()'s editor pass that names settings: Avada's own params,
	 * responsive and states filters, in its order, so a REST request sees the tablet, phone and hover settings the
	 * editor writes (s10b-report.md, probe B: without them the editor's next save adds margin_top_small to a title).
	 */
	public function test_avada_map_row_adds_the_editors_responsive_and_state_settings(): void {
		$before                                 = $GLOBALS['all_fusion_builder_elements'] ?? null;
		$GLOBALS['all_fusion_builder_elements'] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a stand-in for Avada's map global, restored below.
			'fusion_title' => array(
				'name'   => 'Title',
				'params' => array(
					array(
						'type'       => 'radio_button_set',
						'param_name' => 'content_align',
						'default'    => 'left',
						'responsive' => array( 'state' => 'large' ),
					),
					array(
						'type'       => 'colorpickeralpha',
						'param_name' => 'link_color',
						'states'     => array( 'hover' => array( 'default' => '#fff' ) ),
					),
					array( 'heading' => 'A group with no setting name' ),
				),
			),
		);
		$seen                                   = array();
		$responsive                             = static function ( $atts, $params, $tag ) use ( &$seen ) {
			$seen[] = array( 'responsive', $tag, array_column( $atts, 'name' ), array_keys( $params ) );
			$out    = array();
			foreach ( $params as $name => $param ) {
				if ( 'content_align' === $name ) {
					$out['content_align_medium'] = array( 'param_name' => 'content_align_medium' ) + $param;
				}
				$out[ $name ] = $param;
			}
			return $out;
		};
		$states                                 = static function ( $atts, $params, $tag ) use ( &$seen ) {
			$seen[] = array( 'states', $tag, array_column( $atts, 'name' ), array_keys( $params ) );
			return $params + array(
				'link_hover_color' => array(
					'param_name' => 'link_hover_color',
					'default'    => '#fff',
				),
			);
		};
		add_filter( 'fusion_builder_responsive_params', $responsive, 10, 3 );
		add_filter( 'fusion_builder_states_params', $states, 10, 3 );
		$target = aafm_builder_vendor_rows()['avada_map']['target'];
		$map    = $target();
		remove_filter( 'fusion_builder_responsive_params', $responsive, 10 );
		remove_filter( 'fusion_builder_states_params', $states, 10 );
		$GLOBALS['all_fusion_builder_elements'] = $before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the global.

		$this->assertSame( array( 'content_align_medium', 'content_align', 'link_color', 'link_hover_color' ), array_keys( $map['fusion_title']['params'] ) );
		$this->assertSame(
			array(
				array( 'responsive', 'fusion_title', array( 'content_align' ), array( 'content_align', 'link_color' ) ),
				array( 'states', 'fusion_title', array( 'link_color' ), array( 'content_align_medium', 'content_align', 'link_color' ) ),
			),
			$seen,
			'Responsive first, then states, each with params keyed by setting name, as fusion_builder_map() calls them.'
		);
	}

	public function test_expected_walk_for_update_insert_move_and_remove(): void {
		$walk = aafm_fusion_shortcode_walk( self::TREE, 0, self::TAGS );
		$t    = static fn( string $tag, int $depth, array $atts = array() ) => array(
			'tag'          => $tag,
			'self_closing' => false,
			'has_closer'   => true,
			'atts'         => $atts,
			'depth'        => $depth,
		);
		$half = array( 'width' => '1/2' );

		$update = aafm_builder_expected_walk(
			$walk,
			array(
				'op'    => 'update',
				'index' => 4,
				'atts'  => array( 'title' => 'Four' ),
			)
		);
		$this->assertSame( array( $t( 'vc_row', 0 ), $t( 'vc_column', 1, $half ), $t( 'vc_column_text', 2 ), $t( 'vc_custom_heading', 2, array( 'text' => 'Two' ) ), $t( 'vc_btn', 2, array( 'title' => 'Four' ) ), $t( 'vc_column', 1, $half ) ), $update );

		$insert = aafm_builder_expected_walk(
			$walk,
			array(
				'op'     => 'insert',
				'at'     => 6,
				'depth'  => 2,
				'tuples' => array( $t( 'vc_btn', 0, array( 'title' => 'New' ) ) ),
			)
		);
		$this->assertSame( array_merge( $walk, array( $t( 'vc_btn', 2, array( 'title' => 'New' ) ) ) ), $insert );

		$move = aafm_builder_expected_walk(
			$walk,
			array(
				'op'    => 'move',
				'index' => 1,
				'at'    => 0,
				'depth' => 0,
			)
		);
		$this->assertSame( array( $t( 'vc_column', 0, $half ), $t( 'vc_column_text', 1 ), $t( 'vc_custom_heading', 1, array( 'text' => 'Two' ) ), $t( 'vc_btn', 1, array( 'title' => 'Three' ) ), $t( 'vc_row', 0 ), $t( 'vc_column', 1, $half ) ), $move );

		$remove = aafm_builder_expected_walk(
			$walk,
			array(
				'op'    => 'remove',
				'index' => 1,
			)
		);
		$this->assertSame( array( $t( 'vc_row', 0 ), $t( 'vc_column', 1, $half ) ), $remove );
	}

	public function test_anchor_point_for_each_placement(): void {
		$content = '[vc_column][vc_column_text]A[/vc_column_text][vc_btn title="x" /][/vc_column][vc_column][/vc_column]';
		$els     = aafm_builder_elements( $content, array( 'vc_column', 'vc_column_text', 'vc_btn' ) );
		$col     = $els[0];

		$this->assertSame(
			array(
				'offset' => 0,
				'at'     => 0,
				'depth'  => 0,
				'parent' => null,
			),
			aafm_builder_anchor_point( $els, $col, 'before' )
		);
		$this->assertSame(
			array(
				'offset' => $col['end'],
				'at'     => 3,
				'depth'  => 0,
				'parent' => null,
			),
			aafm_builder_anchor_point( $els, $col, 'after' )
		);
		$this->assertSame(
			array(
				'offset' => $col['open_end'],
				'at'     => 1,
				'depth'  => 1,
				'parent' => 0,
			),
			aafm_builder_anchor_point( $els, $col, 'inside_start' )
		);
		$this->assertSame(
			array(
				'offset' => $col['inner_end'],
				'at'     => 3,
				'depth'  => 1,
				'parent' => 0,
			),
			aafm_builder_anchor_point( $els, $col, 'inside_end' )
		);
		foreach ( array( 'inside_start', 'inside_end' ) as $placement ) {
			$out = aafm_builder_anchor_point( $els, $els[2], $placement );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 'aafm_builder_invalid_nesting', $out->get_error_code() );
		}
	}

	private const PAGE = '[vc_row][vc_column][vc_column_text]<p>Old text.</p>[/vc_column_text][/vc_column][/vc_row]';

	private function page( string $content = self::PAGE ): WP_Post {
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		return get_post( $id );
	}

	/**
	 * Commit a text change on PAGE.
	 *
	 * @param WP_Post       $post  Post.
	 * @param string        $content New content.
	 * @param callable|null $after   After step.
	 * @return array<string,mixed>|WP_Error
	 */
	private function commit_text( WP_Post $post, string $content, ?callable $after = null ) {
		$old = (string) $post->post_content;
		return aafm_builder_commit( $post, $old, $content, (array) aafm_wpbakery_shortcode_tags( $old ), (array) aafm_fusion_shortcode_walk( $old, 0, aafm_wpbakery_shortcode_tags( $old ) ), $after, 'wpbakery' );
	}

	private function assert_untouched( $out, string $code, WP_Post $post, ?int $status ): void {
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $code, $out->get_error_code() );
		if ( null !== $status ) {
			$this->assertSame( $status, $out->get_error_data()['status'] );
		}
		$this->assertSame( $post->post_content, get_post_field( 'post_content', $post->ID, 'raw' ) );
	}

	public function test_commit_with_identical_content_saves_nothing(): void {
		$post      = $this->page();
		$modified  = get_post_field( 'post_modified', $post->ID );
		$revisions = count( wp_get_post_revisions( $post->ID ) );

		$out = $this->commit_text( $post, self::PAGE );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['unchanged'] );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertTrue( $out['reversible'] );
		$this->assertSame( aafm_builder_page_fingerprint( self::PAGE ), $out['page_fingerprint'] );
		$this->assertSame( $modified, get_post_field( 'post_modified', $post->ID ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post->ID ) ) );
	}

	public function test_commit_accepts_an_insert_of_a_tag_the_page_did_not_have(): void {
		$post   = $this->page();
		$old    = self::PAGE;
		$new    = str_replace( '[/vc_column][/vc_row]', '[vc_custom_heading text="Hi"][/vc_custom_heading][/vc_column][/vc_row]', $old );
		$tags   = (array) aafm_wpbakery_shortcode_tags( $old );
		$expect = aafm_builder_expected_walk(
			(array) aafm_fusion_shortcode_walk( $old, 0, $tags ),
			array(
				'op'     => 'insert',
				'at'     => 3,
				'depth'  => 2,
				'tuples' => array(
					array(
						'tag'          => 'vc_custom_heading',
						'self_closing' => false,
						'has_closer'   => true,
						'atts'         => array( 'text' => 'Hi' ),
						'depth'        => 0,
					),
				),
			)
		);

		$out = aafm_builder_commit( $post, $old, $new, $tags, $expect, null, 'wpbakery' );

		$this->assertIsArray( $out );
		$this->assertFalse( $out['unchanged'] );
		$this->assertSame( $new, get_post_field( 'post_content', $post->ID, 'raw' ) );
	}

	public function test_commit_returns_the_page_fingerprint_of_the_stored_content(): void {
		$post = $this->page();
		$new  = str_replace( 'Old text.', 'New text.', self::PAGE );

		$out = $this->commit_text( $post, $new );

		$this->assertIsArray( $out );
		$this->assertSame( aafm_builder_page_fingerprint( get_post_field( 'post_content', $post->ID, 'raw' ) ), $out['page_fingerprint'] );
		$this->assertInstanceOf( WP_Post::class, $out['post'] );
	}

	public function test_commit_refuses_content_whose_outline_is_not_the_prediction(): void {
		$post = $this->page();
		$new  = str_replace( 'Old text.', 'Old [vc_raw_html]eA==[/vc_raw_html]', self::PAGE );

		$this->assert_untouched( $this->commit_text( $post, $new ), 'aafm_builder_structure_changed', $post, 400 );
	}

	public function test_commit_refuses_unparseable_content(): void {
		$post = $this->page();
		$new  = str_replace( '[vc_column_text]', '[vc_column_text el_class="a[1]"]', self::PAGE );

		$this->assert_untouched( $this->commit_text( $post, $new ), 'aafm_builder_unparseable', $post, 409 );
	}

	public function test_commit_refuses_a_page_its_own_save_would_change(): void {
		$post  = $this->page( self::PAGE . '<iframe></iframe>' );
		$strip = static fn( $value ) => str_replace( '<iframe></iframe>', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$out = $this->commit_text( $post, str_replace( 'Old text.', 'New text.', (string) $post->post_content ) );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}

		$this->assert_untouched( $out, 'aafm_wpbakery_save_would_change_page', $post, 409 );
	}

	public function test_commit_refuses_when_the_save_would_change_the_layout(): void {
		$post  = $this->page();
		$react = static fn( $value ) => false === strpos( (string) $value, 'Trigger' ) ? $value : (string) preg_replace( '/\[vc_column_text\].*?\[\/vc_column_text\]/s', '', (string) $value );
		add_filter( 'content_save_pre', $react );
		try {
			$out = $this->commit_text( $post, str_replace( 'Old text.', 'Trigger.', self::PAGE ) );
		} finally {
			remove_filter( 'content_save_pre', $react );
		}

		$this->assert_untouched( $out, 'aafm_wpbakery_save_would_change_layout', $post, 409 );
	}

	public function test_commit_returns_the_revision_that_holds_the_old_content(): void {
		$post = $this->page();

		$out = $this->commit_text( $post, str_replace( 'Old text.', 'New text.', self::PAGE ) );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['reversible'] );
		$this->assertIsInt( $out['restore_revision_id'] );
		$this->assertSame( self::PAGE, get_post( $out['restore_revision_id'] )->post_content );
		$this->assertSame( $post->ID, (int) get_post( $out['restore_revision_id'] )->post_parent );
	}

	/**
	 * A page saved once holds its stored content in a revision already, so core saves no new one and
	 * the commit returns that revision.
	 */
	public function test_commit_returns_the_existing_revision_when_the_page_was_updated_once(): void {
		$post = $this->page( 'seed' );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => self::PAGE,
				)
			)
		);
		$revisions = wp_get_post_revisions( $post->ID );
		$existing  = (int) array_key_first( $revisions );
		$this->assertSame( self::PAGE, $revisions[ $existing ]->post_content );

		$out = $this->commit_text( get_post( $post->ID ), str_replace( 'Old text.', 'New text.', self::PAGE ) );

		$this->assertIsArray( $out );
		$this->assertSame( $existing, $out['restore_revision_id'] );
		$this->assertTrue( $out['reversible'] );
	}

	/**
	 * An abandoned autosave newer than the revision that holds the stored content must not hide it.
	 */
	public function test_commit_skips_an_autosave_when_it_looks_for_the_existing_revision(): void {
		$post = $this->page( 'seed' );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => self::PAGE,
				)
			)
		);
		$existing = (int) array_key_first( wp_get_post_revisions( $post->ID ) );
		$draft    = get_post( $post->ID, ARRAY_A );

		$draft['post_content'] = str_replace( 'Old text.', 'Unsaved draft.', self::PAGE );
		$autosave              = _wp_put_post_revision( $draft, true );
		$this->assertIsInt( $autosave );
		$this->assertGreaterThan( $existing, $autosave );
		$this->assertNotFalse( wp_is_post_autosave( $autosave ) );

		$out = $this->commit_text( get_post( $post->ID ), str_replace( 'Old text.', 'New text.', self::PAGE ) );

		$this->assertIsArray( $out );
		$this->assertSame( $existing, $out['restore_revision_id'] );
		$this->assertTrue( $out['reversible'] );
		$this->assertSame( self::PAGE, get_post( $out['restore_revision_id'] )->post_content );
	}

	/**
	 * Core compares a post with its newest revision after normalize_whitespace(), so stored CRLF content whose newest
	 * revision is the LF form gets no new revision and no revision holds the stored bytes. The commit forces one.
	 */
	public function test_commit_keeps_a_revision_of_crlf_content_whose_newest_revision_is_lf(): void {
		global $wpdb;
		$post = $this->page( 'seed' );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => str_replace( 'Old text.', "Old\ntext.", self::PAGE ),
				)
			)
		);
		$crlf = str_replace( 'Old text.', "Old\r\ntext.", self::PAGE );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $crlf ), array( 'ID' => $post->ID ) );
		clean_post_cache( $post->ID );
		$stored = get_post( $post->ID );
		$this->assertSame( $crlf, $stored->post_content );

		$out = $this->commit_text( $stored, str_replace( "Old\r\ntext.", 'New text.', $crlf ) );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['reversible'] );
		$this->assertIsInt( $out['restore_revision_id'] );
		$this->assertSame( $crlf, get_post( $out['restore_revision_id'] )->post_content );
		$this->assertFalse( has_filter( 'wp_save_post_revision_check_for_changes' ) );
	}

	/**
	 * INFO-1: the forced revision adds and removes only the plugin's own callback, so a site's identical
	 * __return_false on the hook stays.
	 */
	public function test_forcing_a_revision_keeps_a_sites_own_callback_on_the_hook(): void {
		global $wpdb;
		$post = $this->page( 'seed' );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => str_replace( 'Old text.', "Old\ntext.", self::PAGE ),
				)
			)
		);
		$crlf = str_replace( 'Old text.', "Old\r\ntext.", self::PAGE );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $crlf ), array( 'ID' => $post->ID ) );
		clean_post_cache( $post->ID );
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_true', 20 );

		$out  = $this->commit_text( get_post( $post->ID ), str_replace( "Old\r\ntext.", 'New text.', $crlf ) );
		$kept = has_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_true', 20 );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['reversible'] );
		$this->assertSame( $crlf, get_post( $out['restore_revision_id'] )->post_content );
		$this->assertSame( 10, $kept );
	}

	/**
	 * R2-1: core prunes revisions down to wp_revisions_to_keep() inside the update, oldest first. With one kept, the
	 * revision saved before the write is deleted, so the commit reports no way back and names the limit; with two or
	 * more (including a post already at its limit) it is the second newest and survives; -1 keeps all.
	 *
	 * @return array<string,array{0:int,1:bool}>
	 */
	public function revision_limits(): array {
		return array(
			'one kept'       => array( 1, false ),
			'two kept'       => array( 2, true ),
			'three kept'     => array( 3, true ),
			'unlimited (-1)' => array( -1, true ),
		);
	}

	/**
	 * The restore point a commit returns still exists after the save, or none is returned.
	 *
	 * @dataProvider revision_limits
	 *
	 * @param int  $keep       Revisions kept.
	 * @param bool $reversible Expected.
	 */
	public function test_commit_never_returns_a_revision_core_pruned( int $keep, bool $reversible ): void {
		$post = $this->page( 'seed' );
		foreach ( array( 'one', 'two', 'three' ) as $step ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => str_replace( 'Old text.', 'Old ' . $step . '.', self::PAGE ),
					)
				)
			);
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => self::PAGE,
				)
			)
		);
		$limit = static fn(): int => $keep;
		add_filter( 'wp_revisions_to_keep', $limit );
		$out   = $this->commit_text( get_post( $post->ID ), str_replace( 'Old text.', 'New text.', self::PAGE ) );
		remove_filter( 'wp_revisions_to_keep', $limit );

		$this->assertIsArray( $out );
		$this->assertSame( $reversible, $out['reversible'] );
		if ( $reversible ) {
			$this->assertIsInt( $out['restore_revision_id'] );
			$this->assertSame( self::PAGE, get_post( $out['restore_revision_id'] )->post_content );
			$this->assertArrayNotHasKey( 'reversible_reason', $out );
		} else {
			$this->assertNull( $out['restore_revision_id'] );
			$this->assertStringContainsString( 'no longer there after the save', $out['reversible_reason'] );
			$this->assertStringContainsString( 'keeps 1 revision', $out['reversible_reason'] );
		}
	}

	/**
	 * SEC-S9b-R2-2: a callback that throws during the forced revision does not leave the plugin's closure on the hook.
	 */
	public function test_the_forced_revision_hook_comes_off_when_a_callback_throws(): void {
		global $wpdb;
		$post = $this->page( 'seed' );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => str_replace( 'Old text.', "Old\ntext.", self::PAGE ),
				)
			)
		);
		$crlf = str_replace( 'Old text.', "Old\r\ntext.", self::PAGE );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $crlf ), array( 'ID' => $post->ID ) );
		clean_post_cache( $post->ID );
		$calls = 0;
		$throw = static function ( $check ) use ( &$calls ) {
			++$calls;
			if ( 2 === $calls ) {
				throw new \RuntimeException( 'site callback failed' );
			}
			return $check;
		};
		add_filter( 'wp_save_post_revision_check_for_changes', $throw );
		$thrown = null;
		try {
			$this->commit_text( get_post( $post->ID ), str_replace( "Old\r\ntext.", 'New text.', $crlf ) );
		} catch ( \RuntimeException $e ) {
			$thrown = $e->getMessage();
		}
		$left = array_keys( $GLOBALS['wp_filter']['wp_save_post_revision_check_for_changes']->callbacks ?? array() );
		remove_filter( 'wp_save_post_revision_check_for_changes', $throw );

		$this->assertSame( 'site callback failed', $thrown );
		$this->assertSame( array( 10 ), $left, 'Only the site callback is left on the hook.' );
		$this->assertSame( $crlf, get_post_field( 'post_content', $post->ID, 'raw' ) );
	}

	public function test_commit_runs_without_a_way_back_when_revisions_are_off(): void {
		$post = $this->page();
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		try {
			$out = $this->commit_text( $post, str_replace( 'Old text.', 'New text.', self::PAGE ) );
		} finally {
			remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		}

		$this->assertIsArray( $out );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
		$this->assertStringContainsString( 'New text.', get_post_field( 'post_content', $post->ID, 'raw' ) );
	}

	public function test_commit_refuses_before_saving_when_the_after_step_is_unavailable(): void {
		$post      = $this->page();
		$revisions = count( wp_get_post_revisions( $post->ID ) );
		$after     = static fn(): bool => false;

		$this->assert_untouched( $this->commit_text( $post, str_replace( 'Old text.', 'New text.', self::PAGE ), $after ), 'aafm_wpbakery_css_rebuild_unavailable', $post, 409 );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post->ID ) ) );
	}

	public function test_commit_reports_stale_css_when_the_after_step_fails(): void {
		$post  = $this->page();
		$calls = array();
		$after = static function ( int $id, bool $probe ) use ( &$calls ): bool {
			$calls[] = $probe;
			return $probe;
		};

		$out = $this->commit_text( $post, str_replace( 'Old text.', 'New text.', self::PAGE ), $after );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_wpbakery_css_stale', $out->get_error_code() );
		$this->assertSame( array( true, false ), $calls );
		$this->assertStringContainsString( 'New text.', get_post_field( 'post_content', $post->ID, 'raw' ) );
		$this->assertIsInt( $out->get_error_data()['restore_revision_id'] );
	}

	public function test_commit_reports_unconfirmed_when_a_filter_alters_the_stored_content(): void {
		$post  = $this->page();
		$alter = static function ( $data ) {
			$data['post_content'] = str_replace( 'New text.', 'Other text.', (string) $data['post_content'] );
			return $data;
		};
		add_filter( 'wp_insert_post_data', $alter );
		try {
			$out = $this->commit_text( $post, str_replace( 'Old text.', 'New text.', self::PAGE ) );
		} finally {
			remove_filter( 'wp_insert_post_data', $alter );
		}

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_wpbakery_write_unconfirmed', $out->get_error_code() );
	}

	public function test_replay_check_refuses_an_exact_replay_that_differs(): void {
		$post  = $this->page();
		$new   = str_replace( 'Old text.', 'Old <iframe></iframe>', self::PAGE );
		$tags  = (array) aafm_wpbakery_shortcode_tags( self::PAGE );
		$walk  = (array) aafm_fusion_shortcode_walk( self::PAGE, 0, $tags );
		$strip = static fn( $value ) => str_replace( '<iframe></iframe>', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$loose = aafm_builder_replay_check( 'avada', $post->ID, self::PAGE, $new, $tags, $walk );
			$exact = aafm_builder_replay_check( 'avada', $post->ID, self::PAGE, $new, $tags, $walk, true );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}

		$this->assertTrue( $loose );
		$this->assertInstanceOf( WP_Error::class, $exact );
		$this->assertSame( 'aafm_avada_save_would_change_page', $exact->get_error_code() );
	}

	public function test_rebuild_css_calls_default_then_custom_once_each(): void {
		$calls = array();
		$seam  = static function ( $resolved, string $entry ) use ( &$calls ) {
			return 'wpbakery_css_rebuild' !== $entry ? $resolved : static function ( int $id, string $type ) use ( &$calls ): void {
				$calls[] = array( $id, $type );
			};
		};
		add_filter( 'aafm_builder_vendor', $seam, 10, 2 );
		try {
			$this->assertTrue( aafm_wpbakery_rebuild_css( 42 ) );
		} finally {
			remove_filter( 'aafm_builder_vendor', $seam, 10 );
		}

		$this->assertSame( array( array( 42, 'default' ), array( 42, 'custom' ) ), $calls );
	}

	public function test_rebuild_css_is_false_without_the_vendor(): void {
		$absent = static fn() => null;
		add_filter( 'aafm_builder_vendor', $absent );
		try {
			$this->assertFalse( aafm_wpbakery_rebuild_css( 42 ) );
			$this->assertFalse( aafm_wpbakery_rebuild_css( 42, true ) );
		} finally {
			remove_filter( 'aafm_builder_vendor', $absent );
		}

		$throws = static function () {
			return static function (): void {
				throw new \RuntimeException( 'boom' );
			};
		};
		add_filter( 'aafm_builder_vendor', $throws );
		try {
			$this->assertFalse( aafm_wpbakery_rebuild_css( 42 ) );
		} finally {
			remove_filter( 'aafm_builder_vendor', $throws );
		}
	}

	public function test_rebuild_css_probe_changes_nothing(): void {
		$calls = 0;
		$seam  = static function () use ( &$calls ) {
			return static function () use ( &$calls ): void {
				++$calls;
			};
		};
		add_filter( 'aafm_builder_vendor', $seam );
		try {
			$this->assertTrue( aafm_wpbakery_rebuild_css( 42, true ) );
		} finally {
			remove_filter( 'aafm_builder_vendor', $seam );
		}

		$this->assertSame( 0, $calls );
	}
	public function test_the_vendor_class_loader_refuses_a_missing_file_or_class_without_a_fatal(): void {
		$this->assertFalse( aafm_builder_load_vendor_class( '/nonexistent/vc_grid_item/editor/class-vc-grid-item-editor.php', 'Aafm_No_Such_Vendor_Class', 'createPostType' ) );

		$file = get_temp_dir() . 'aafm-empty-vendor-file-' . wp_generate_password( 8, false ) . '.php';
		file_put_contents( $file, "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a throwaway temp file for this test.
		try {
			$this->assertFalse( aafm_builder_load_vendor_class( $file, 'Aafm_No_Such_Vendor_Class', 'createPostType' ), 'A file that no longer defines the class is a refusal.' );
		} finally {
			wp_delete_file( $file );
		}

		$this->assertTrue( aafm_builder_load_vendor_class( '/nonexistent.php', \WP_Post::class, 'get_instance' ), 'A class already loaded needs no file.' );
		$this->assertFalse( aafm_builder_load_vendor_class( '/nonexistent.php', \WP_Post::class, 'aafm_no_such_method' ) );
	}
}
