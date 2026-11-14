<?php
/**
 * Native WPBakery Page Builder integration: wpbakery-get-page-content, wpbakery-replace-text,
 * and the content tag scanner both tools share.
 *
 * PAGE is a byte copy of a page WPBakery 9.1 saved itself on the sim site (S5-1 capture,
 * scratchpad/1113/s5-vendor/page-content.txt, sha256 231ecd01...): row, two columns, a text
 * block with a Design Options value, a custom heading, an inner row, a button and a raw HTML
 * element.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class WpbakeryTest extends TestCase {

	private const PAGE = <<<'WPB'
[vc_row][vc_column width="1/2"][vc_column_text css=".vc_custom_1731571200001{margin-top: 10px !important;}"]<p>Opening paragraph alpha.</p>[/vc_column_text][vc_custom_heading text="Heading bravo"][/vc_column][vc_column width="1/2"][vc_row_inner][vc_column_inner][vc_btn title="Button charlie"][vc_raw_html]JTNDcCUzRVJhdyUyMGRlbHRhJTNDJTJGcCUzRQ==[/vc_raw_html][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]
WPB;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		parent::tear_down();
	}

	public function test_tag_scan_finds_addon_tags_closers_and_the_escaped_form(): void {
		$tags = aafm_wpbakery_shortcode_tags( '[vc_row][vc_column][acme_box title="x"]a[/acme_box][/vc_column][/vc_row] [[vc_note]] [/vc_orphan]' );

		foreach ( array( 'vc_row', 'vc_column', 'acme_box', 'vc_note', 'vc_orphan' ) as $tag ) {
			$this->assertContains( $tag, $tags );
		}
	}

	public function test_tag_scan_merges_several_strings(): void {
		$tags = aafm_wpbakery_shortcode_tags( '[vc_row]', 'text [acme_box] more' );

		$this->assertContains( 'vc_row', $tags );
		$this->assertContains( 'acme_box', $tags );
	}

	public function test_tag_scan_of_text_without_brackets_is_an_empty_list(): void {
		$this->assertSame( array(), aafm_wpbakery_shortcode_tags( 'plain text, no shortcodes' ) );
	}

	public function test_tag_scan_returns_null_above_300_distinct_names(): void {
		$three_hundred = '';
		for ( $i = 1; $i <= 300; $i++ ) {
			$three_hundred .= '[t' . $i . ']';
		}

		$this->assertCount( 300, aafm_wpbakery_shortcode_tags( $three_hundred ) );
		$this->assertNull( aafm_wpbakery_shortcode_tags( $three_hundred . '[t301]' ) );
	}

	public function test_walk_with_an_empty_tag_list_returns_no_elements(): void {
		$this->assertSame( array(), aafm_fusion_shortcode_walk( '[gallery ids="1"]', 0, array() ) );
	}

	public function test_walk_with_a_passed_tag_list_ignores_fusion_tags(): void {
		$walk = aafm_fusion_shortcode_walk( '[fusion_text]a[/fusion_text][vc_column_text]b[/vc_column_text]', 0, array( 'vc_column_text' ) );

		$this->assertCount( 1, $walk );
		$this->assertSame( 'vc_column_text', $walk[0]['tag'] );
	}

	public function test_structure_with_a_passed_tag_list_has_no_global_id(): void {
		$out = aafm_avada_structure( '[vc_row fusion_global="12"][/vc_row]', array( 'vc_row' ) );

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertArrayNotHasKey( 'global_id', $out['structure'][0] );
	}
}
