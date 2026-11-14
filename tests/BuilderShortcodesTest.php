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
				$content = (string) file_get_contents( (string) $file );
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
		$got = aafm_builder_resolve( $content, array( 'vc_column', 'vc_btn' ), 2, array( 'tag' => 'vc_btn', 'fingerprint' => $fp ) );
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
				aafm_builder_resolve( self::TREE, self::TAGS, 3, array( 'tag' => 'vc_btn', 'fingerprint' => $fp ) ),
				aafm_builder_resolve( self::TREE, self::TAGS, 3, array( 'tag' => 'vc_custom_heading', 'fingerprint' => '0000000000000000' ) ),
				aafm_builder_resolve( self::TREE, self::TAGS, 6, array( 'tag' => 'vc_btn', 'fingerprint' => $fp ) ),
				aafm_builder_resolve( $big, array( 'vc_btn' ), 2000, array( 'tag' => 'vc_btn', 'fingerprint' => $bfp ) ),
			) as $out
		) {
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 'aafm_builder_element_mismatch', $out->get_error_code() );
			$this->assertSame( 409, $out->get_error_data()['status'] );
		}
		$this->assertIsArray( aafm_builder_resolve( self::TREE, self::TAGS, 3, array( 'tag' => 'vc_custom_heading', 'fingerprint' => $fp ) ) );
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

	public function test_serialize_wpbakery_always_writes_a_closing_tag(): void {
		$this->assertSame( '[vc_btn title="Go"][/vc_btn]', aafm_builder_serialize( 'vc_btn', array( 'title' => 'Go' ), null, 'wpbakery' ) );
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
		$this->assertSame( array( 'vc_grid_item' ), aafm_builder_own_post_types( 'wpbakery' ) );
		$this->assertSame( array(), aafm_builder_own_post_types( 'elementor' ) );
		$this->assertNotContains( 'templatera', aafm_builder_own_post_types( 'wpbakery' ) );
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

		$present = static fn() => static function (): void {};
		add_filter( 'aafm_builder_vendor', $present );
		$this->assertIsCallable( aafm_builder_vendor( 'wpbakery_css_rebuild' ) );
		remove_filter( 'aafm_builder_vendor', $present );

		$this->assertNull( aafm_builder_vendor( 'no_such_entry' ) );
	}

	public function test_every_seam_row_is_a_callable_shape(): void {
		$rows = aafm_builder_vendor_rows();
		$this->assertSame( array( 'wpbakery_css_rebuild' ), array_keys( $rows ) );
		foreach ( $rows as $entry => $row ) {
			$target = $row['target'];
			$ok     = $target instanceof \Closure
				|| ( is_string( $target ) && '' !== $target && ! class_exists( $target, false ) )
				|| ( is_array( $target ) && 2 === count( $target ) && is_string( $target[0] ) && is_string( $target[1] ) );
			$this->assertTrue( $ok, $entry );
		}
	}
}
