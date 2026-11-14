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

	private function make_page( string $content, int $author = 0 ): int {
		$args = array(
			'post_type'    => 'page',
			'post_content' => $content,
		);
		if ( $author ) {
			$args['post_author'] = $author;
		}
		return self::factory()->post->create( $args );
	}

	private function read( array $input ): array {
		$out = aafm_exec_wpbakery_get_page_content( $input );
		$this->assertIsArray( $out );
		return $out;
	}

	public function test_wpbakery_active_follows_the_constant_and_the_filter(): void {
		$this->assertSame( defined( 'WPB_VC_VERSION' ), aafm_wpbakery_active() );
		add_filter( 'aafm_wpbakery_active', '__return_true' );
		$this->assertTrue( aafm_wpbakery_active() );
		remove_filter( 'aafm_wpbakery_active', '__return_true' );
		add_filter( 'aafm_wpbakery_active', '__return_false' );
		$this->assertFalse( aafm_wpbakery_active() );
		remove_filter( 'aafm_wpbakery_active', '__return_false' );
	}

	public function test_read_registers_only_while_the_integration_is_active(): void {
		$this->assertArrayHasKey( 'aafm/wpbakery-get-page-content', aafm_get_abilities_registry() );
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		add_filter( 'aafm_integration_active_wpbakery', '__return_false' );
		aafm_flush_registry_cache();
		try {
			$this->assertArrayNotHasKey( 'aafm/wpbakery-get-page-content', aafm_get_abilities_registry() );
			$this->assertArrayHasKey( 'aafm/wpbakery-get-page-content', aafm_get_abilities_registry_full() );
		} finally {
			remove_filter( 'aafm_integration_active_wpbakery', '__return_false' );
			aafm_flush_registry_cache();
		}
	}

	public function test_read_returns_the_raw_content_and_ownership_for_a_vc_row_page(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$out = $this->read( array( 'post_id' => $id ) );

		$this->assertSame( $id, $out['post_id'] );
		$this->assertSame( self::PAGE, $out['content'] );
		$this->assertTrue( $out['is_wpbakery_owned'] );
	}

	public function test_read_reports_a_plain_post_as_not_wpbakery_owned(): void {
		$id = $this->make_page( 'ordinary text' );
		$this->acting_as( 'editor' );

		$out = $this->read( array( 'post_id' => $id ) );

		$this->assertFalse( $out['is_wpbakery_owned'] );
		$this->assertSame( 'ordinary text', $out['content'] );
	}

	public function test_structure_keys_are_absent_when_the_flag_is_false_or_missing(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		foreach ( array(
			array( 'post_id' => $id ),
			array(
				'post_id'           => $id,
				'include_structure' => false,
			),
		) as $input ) {
			$out = $this->read( $input );
			$this->assertArrayNotHasKey( 'structure', $out );
			$this->assertArrayNotHasKey( 'structure_status', $out );
		}
	}

	public function test_structure_lists_row_column_inner_row_and_text_in_order_with_depth(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);

		$this->assertSame( 'ok', $out['structure_status'] );
		$outline = array_map(
			static fn( array $item ): string => $item['depth'] . ':' . $item['tag'],
			$out['structure']
		);
		$this->assertSame(
			array( '0:vc_row', '1:vc_column', '2:vc_column_text', '2:vc_custom_heading', '1:vc_column', '2:vc_row_inner', '3:vc_column_inner', '4:vc_btn', '4:vc_raw_html' ),
			$outline
		);
		foreach ( $out['structure'] as $index => $item ) {
			$this->assertSame( $index, $item['index'] );
			$this->assertArrayNotHasKey( 'global_id', $item );
		}
		$this->assertSame( 'Heading bravo', ( (array) $out['structure'][3]['atts'] )['text'] );
	}

	public function test_structure_shows_an_addon_tag_without_a_vc_prefix(): void {
		$id = $this->make_page( '[vc_row][vc_column][acme_box title="x"]a[/acme_box][/vc_column][/vc_row]' );
		$this->acting_as( 'editor' );

		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);

		$this->assertContains( 'acme_box', array_column( $out['structure'], 'tag' ) );
	}

	public function test_structure_of_a_page_without_shortcodes_is_an_empty_ok_list(): void {
		$id = $this->make_page( '<p>No shortcodes here.</p>' );
		$this->acting_as( 'editor' );

		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertSame( array(), $out['structure'] );
	}

	public function test_structure_is_unparseable_for_a_bracket_inside_a_quoted_attribute(): void {
		$id = $this->make_page( '[vc_row][vc_column_text el_class="a[1]"]x[/vc_column_text][/vc_row]' );
		$this->acting_as( 'editor' );

		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);

		$this->assertSame( 'unparseable', $out['structure_status'] );
		$this->assertArrayNotHasKey( 'structure', $out );
	}

	public function test_structure_is_unparseable_above_300_distinct_tag_names(): void {
		$content = '[vc_row]';
		for ( $i = 1; $i <= 301; $i++ ) {
			$content .= '[t' . $i . ']';
		}
		$id = $this->make_page( $content . '[/vc_row]' );
		$this->acting_as( 'editor' );

		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);

		$this->assertSame( 'unparseable', $out['structure_status'] );
		$this->assertArrayNotHasKey( 'structure', $out );
	}

	public function test_structure_is_truncated_at_two_thousand_elements(): void {
		$id = $this->make_page( str_repeat( '[vc_empty_space /]', 2001 ) );
		$this->acting_as( 'editor' );

		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);

		$this->assertSame( 'truncated', $out['structure_status'] );
		$this->assertCount( 2000, $out['structure'] );
	}

	public function test_read_schema_is_closed_and_declares_include_structure(): void {
		$args = aafm_args_wpbakery_get_page_content();

		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id' ), $args['input_schema']['required'] );
		$this->assertSame( 'boolean', $args['input_schema']['properties']['include_structure']['type'] );
		$this->assertFalse( $args['input_schema']['properties']['include_structure']['default'] );
		$this->assertSame( 1, $args['input_schema']['properties']['post_id']['minimum'] );
		$this->assertSame( 'aafm-reads', $args['category'] );
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
		$this->assertArrayNotHasKey( 'global_id', $args['output_schema']['properties']['structure']['items']['properties'] );
	}

	public function test_read_description_says_what_the_design_requires(): void {
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-get-page-content'];
		$this->assertSame( 'reads', $row['group'] );
		$this->assertSame( 'read', $row['risk'] );
		$this->assertSame( 'wpbakery', $row['subject'] );
		$this->assertStringContainsString( 'is_wpbakery_owned', $row['description'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-get-page-content'] ) as $text ) {
			$this->assertStringContainsString( 'include_structure', $text );
			$this->assertStringContainsString( 'Role Manager', $text );
			$this->assertStringContainsString( 'edit access', $text );
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'Visual Composer', 'Avada', 'Elementor' ) as $other ) {
				$this->assertStringNotContainsString( $other, $text );
			}
		}
	}

	public function test_contributor_is_refused_on_another_users_page(): void {
		$id = $this->make_page( self::PAGE, self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->acting_as( 'contributor' );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_editor_is_allowed(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_discovery_shows_the_read_to_an_editor_and_hides_it_from_a_subscriber(): void {
		$check = aafm_ability_list_permission( 'aafm/wpbakery-get-page-content' );
		$this->assertIsCallable( $check );

		$this->acting_as( 'editor' );
		$this->assertTrue( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
	}

	public function test_read_refuses_a_revision_id_and_an_attachment_id(): void {
		$id = $this->make_page( self::PAGE );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => self::PAGE . ' ',
			)
		);
		$revisions  = wp_get_post_revisions( $id );
		$attachment = self::factory()->attachment->create( array( 'post_parent' => $id ) );
		$this->acting_as( 'administrator' );

		$this->assertNotEmpty( $revisions );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => (int) array_key_first( $revisions ) ) ) );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $attachment ) ) );
	}
}
