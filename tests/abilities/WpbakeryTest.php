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

use AAFM\Tests\Support\QueryFaultInjector;
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

	/**
	 * Run the write with the given search and replacement.
	 *
	 * @param int    $id      Post id.
	 * @param string $search  Search text.
	 * @param string $replace Replacement text.
	 * @return array<string,mixed>|WP_Error
	 */
	private function replace( int $id, string $search, string $replace ) {
		return aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => $search,
				'replace' => $replace,
			)
		);
	}

	private function assert_refused( $out, string $code, int $id, string $before, ?int $status ): void {
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $code, $out->get_error_code() );
		$data = $out->get_error_data();
		$this->assertSame( $status, is_array( $data ) ? ( $data['status'] ?? null ) : null );
		$this->assertStringNotContainsString( ' - ', $out->get_error_message() );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post_field( 'post_content', $id, 'raw' ), 'Nothing was changed.' );
	}

	public function test_replace_changes_text_inside_vc_column_text(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );
		$revisions = count( wp_get_post_revisions( $id ) );

		$out = $this->replace( $id, 'Opening paragraph alpha.', 'Opening paragraph changed.' );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
		$this->assertSame( str_replace( 'Opening paragraph alpha.', 'Opening paragraph changed.', self::PAGE ), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( $revisions + 2, count( wp_get_post_revisions( $id ) ) );
	}

	public function test_replace_succeeds_on_a_page_with_bracketed_words_in_body_text(): void {
		$content = '[vc_row][vc_column][vc_column_text]<p>As written [sic] in note [1].</p><p>Change me.</p>[/vc_column_text][/vc_column][/vc_row]';
		$id      = $this->make_page( $content );
		$this->acting_as( 'editor' );

		$out = $this->replace( $id, 'Change me.', 'Changed.' );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
	}

	public function test_replace_refuses_text_that_is_a_heading_attribute(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$out = $this->replace( $id, 'Heading bravo', 'Heading changed' );

		$this->assert_refused( $out, 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
		$this->assertStringContainsString( 'heading', strtolower( $out->get_error_message() ) );
	}

	public function test_replace_refuses_part_of_a_tag_name(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'column_text', 'column_txt' ), 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
	}

	public function test_replace_refuses_a_replacement_that_adds_a_raw_html_element(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Opening paragraph alpha.', 'x[vc_raw_html]abc[/vc_raw_html]' ), 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
	}

	public function test_replace_refuses_adding_a_custom_onclick_attribute(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Button charlie"', 'Button charlie" custom_onclick="true"' ), 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
	}

	/**
	 * SEC-S9a-1 class: WPBakery's editor reads the page through TinyMCE, which decodes bracket entities, so a
	 * replacement spelled with them would become a real element on the next editor save. They are decoded first,
	 * so the structure check sees the element and refuses.
	 */
	public function test_replace_refuses_bracket_entities_that_spell_an_element(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		foreach ( array( 'x &#91;vc_raw_js&#93;PHNjcmlwdD4=&#91;/vc_raw_js&#93;', '&#x5b;vc_raw_html&#X5D;x&#0091;/vc_raw_html&#x5d;' ) as $replace ) {
			$this->assert_refused( $this->replace( $id, 'Opening paragraph alpha.', $replace ), 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
		}
	}

	public function test_replace_refuses_entities_inside_a_shortcode_name(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		foreach ( array( '[&#118;c_raw_js]PHNjcmlwdD4=[/&#118;c_raw_js]', 'x [&#47;vc_column_text] y' ) as $replace ) {
			$this->assert_refused( $this->replace( $id, 'Opening paragraph alpha.', $replace ), 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
		}
	}

	public function test_replace_with_a_bracket_needs_unfiltered_html(): void {
		$id     = $this->make_page( self::PAGE );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new \WP_User( $editor ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( 0 );
		wp_set_current_user( $editor );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		foreach ( array( '[<b></b>vc_raw_js]x[/<b></b>vc_raw_js]', '[&#118;c_raw_js]x[/&#118;c_raw_js]', 'Footnote [1]' ) as $replace ) {
			$out = $this->replace( $id, 'Opening paragraph alpha.', $replace );
			$this->assert_refused( $out, 'aafm_wpbakery_brackets_need_unfiltered_html', $id, self::PAGE, 400 );
		}
		$this->assertSame( 1, $this->replace( $id, 'Opening paragraph alpha.', 'Opening paragraph beta.' )['replacements'] );
	}

	public function test_replace_refuses_encoded_brackets_and_data_attributes_without_unfiltered_html(): void {
		$id     = $this->make_page( self::PAGE );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new \WP_User( $editor ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( 0 );
		wp_set_current_user( $editor );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$cases = array(
			'<p><img src="https://x/a.png" data-wp-preserve="%5Bvc_raw_js%5DPHNjcmlwdD4=%5B%2Fvc_raw_js%5D"></p>' => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p>Plain %5b text</p>'                        => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p>Spelled &#37;5D here</p>'                  => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p><img src="https://x/a.png" data-wp-media="x" /></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p><img src="https://x/a.png" data-wp-preserve="x" /></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<div data-wpview-text="x">v</div>'            => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p data-wpview-marker="x">v</p>'              => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p><span title="a>b" data-x="1">v</span></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p><IMG SRC="https://x/a.png" DATA-WP-PRESERVE="x"></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
		);
		foreach ( $cases as $replace => $code ) {
			$this->assert_refused( $this->replace( $id, 'Opening paragraph alpha.', $replace ), $code, $id, self::PAGE, 400 );
		}
		$this->assertSame( 1, $this->replace( $id, 'Opening paragraph alpha.', '<img src="https://x/a.png" alt="A view" /> <a href="https://x/">Link</a>' )['replacements'] );

		$this->acting_as( 'administrator' );
		foreach ( array_keys( $cases ) as $replace ) {
			$this->assertSame( 1, $this->replace( $id, 'Link', $replace . ' Link' )['replacements'], $replace );
		}
	}

	public function test_replace_keeps_quote_entities_inside_attributes(): void {
		$content = '[vc_row][vc_column][vc_column_text]<p>Swap me.</p>[/vc_column_text][/vc_column][/vc_row]';
		$id      = $this->make_page( $content );
		$this->acting_as( 'editor' );

		$this->assertSame( 1, $this->replace( $id, 'Swap me.', '<img src="x.jpg" alt="the &quot;best&quot; view" />' )['replacements'] );
		$this->assertStringContainsString( 'alt="the &quot;best&quot; view"', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_replace_refuses_removing_a_closing_tag(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, '[/vc_column_text]', '' ), 'aafm_replace_inside_shortcode', $id, self::PAGE, 400 );
	}

	public function test_replace_refuses_when_the_search_also_matches_an_attribute_value(): void {
		$content = '[vc_row][vc_column][vc_column_text]<p>Contact us today.</p>[/vc_column_text][vc_btn title="Contact us"][/vc_column][/vc_row]';
		$id      = $this->make_page( $content );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Contact us', 'Call us' ), 'aafm_replace_inside_shortcode', $id, $content, 400 );
	}

	public function test_replace_refuses_a_post_with_no_wpbakery_layout(): void {
		$id = $this->make_page( '<p>Plain text.</p>' );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Plain', 'Simple' ), 'aafm_not_wpbakery_owned', $id, '<p>Plain text.</p>', 409 );
	}

	public function test_replace_refuses_an_avada_owned_post(): void {
		$content = '[fusion_builder_container]Hello world[/fusion_builder_container]';
		$id      = $this->make_page( $content );
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Hello', 'Hi' ), 'aafm_not_wpbakery_owned', $id, $content, 409 );
	}

	public function test_replace_refuses_a_post_with_vc_row_and_an_elementor_marker(): void {
		$id = $this->make_page( self::PAGE );
		update_post_meta( $id, '_elementor_data', '[]' );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Opening paragraph alpha.', 'Changed.' ), 'aafm_not_wpbakery_owned', $id, self::PAGE, 409 );
	}

	public function test_replace_refuses_above_300_distinct_tag_names(): void {
		$content = '[vc_row][vc_column][vc_column_text]Change me.[/vc_column_text]';
		for ( $i = 1; $i <= 301; $i++ ) {
			$content .= '[t' . $i . ']';
		}
		$content .= '[/vc_column][/vc_row]';
		$id       = $this->make_page( $content );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Change me.', 'Changed.' ), 'aafm_wpbakery_unparseable', $id, $content, 409 );
	}

	public function test_zero_matches_writes_nothing(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );
		$modified  = get_post_field( 'post_modified', $id );
		$revisions = count( wp_get_post_revisions( $id ) );

		$out = $this->replace( $id, 'Not on the page', 'Anything' );

		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['replacements'] );
		$this->assertSame( $modified, get_post_field( 'post_modified', $id ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $id ) ) );
		$this->assertSame( self::PAGE, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_replace_refuses_when_the_save_would_strip_untouched_content(): void {
		$content = '[vc_row][vc_column][vc_column_text]<p>Change me.</p>[/vc_column_text][vc_column_text]<iframe></iframe>[/vc_column_text][/vc_column][/vc_row]';
		$this->acting_as( 'editor' ); // Created as a user with unfiltered_html, so the iframe is stored.
		$id = $this->make_page( $content );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		$strip = static fn( $value ) => str_replace( '<iframe></iframe>', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$out = $this->replace( $id, 'Change me.', 'Changed.' );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}

		$this->assert_refused( $out, 'aafm_wpbakery_save_would_change_page', $id, $content, 409 );
	}

	/**
	 * Models V10 as the S5-5 real-vendor run confirmed it: for a role whose WPBakery
	 * unfiltered_html access is off, WPBakery 9.1's content_save_pre filter deletes every
	 * vc_raw_html element on save.
	 */
	public function test_replace_refuses_when_the_save_would_delete_an_element(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );
		$v10 = static fn( $value ) => (string) preg_replace( '/\[vc_raw_html\].*?\[\/vc_raw_html\]/s', '', (string) $value );
		add_filter( 'content_save_pre', $v10 );
		try {
			$out = $this->replace( $id, 'Opening paragraph alpha.', 'Changed.' );
		} finally {
			remove_filter( 'content_save_pre', $v10 );
		}

		$this->assert_refused( $out, 'aafm_wpbakery_save_would_change_page', $id, self::PAGE, 409 );
	}

	public function test_replace_as_a_user_without_unfiltered_html_refuses_on_content_with_an_iframe(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$content = '[vc_row][vc_column][vc_column_text]<p>Hello there.</p><iframe src="https://example.com/embed"></iframe>[/vc_column_text][/vc_column][/vc_row]';
		$this->acting_as( 'administrator' ); // The iframe is stored by a user who may store it.
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_author'  => $author,
				'post_content' => $content,
			)
		);
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );

		$this->assert_refused( $this->replace( $id, 'Hello there.', 'Hi there.' ), 'aafm_wpbakery_save_would_change_page', $id, $content, 409 );
		$this->assertStringContainsString( '<iframe', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_replace_refuses_when_a_save_filter_deletes_an_element_once_the_new_text_is_present(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );
		$react = static function ( $value ) {
			$value = (string) $value;
			return false === strpos( $value, 'Trigger text' ) ? $value : (string) preg_replace( '/\[vc_custom_heading[^\]]*\]/', '', $value );
		};
		add_filter( 'content_save_pre', $react );
		try {
			$out = $this->replace( $id, 'Opening paragraph alpha.', 'Trigger text' );
		} finally {
			remove_filter( 'content_save_pre', $react );
		}

		$this->assert_refused( $out, 'aafm_wpbakery_save_would_change_layout', $id, self::PAGE, 409 );
	}

	public function test_replace_reports_unconfirmed_when_a_filter_alters_the_stored_content(): void {
		$id = $this->make_page( self::PAGE );
		$this->acting_as( 'editor' );
		$alter = static function ( $data ) {
			$data['post_content'] .= ' altered';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $alter );
		try {
			$out = $this->replace( $id, 'Opening paragraph alpha.', 'Changed.' );
		} finally {
			remove_filter( 'wp_insert_post_data', $alter );
		}

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_wpbakery_write_unconfirmed', $out->get_error_code() );
	}

	public function test_replace_is_reported_as_not_covered_by_undo(): void {
		$this->assertSame( 'not_covered', aafm_undo_refusals()['aafm/wpbakery-replace-text'] );
	}

	public function test_replace_returns_the_revision_wordpress_kept(): void {
		$content = self::PAGE;
		$search  = 'Opening paragraph alpha.';
		$replace = 'Opening paragraph changed.';

		$id = $this->make_page( $content );
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => $search,
				'replace' => $replace,
			)
		);
		$this->assertIsArray( $out );
		$this->assertIsInt( $out['restore_revision_id'] );
		$this->assertArrayNotHasKey( 'reversible_reason', $out );
		$revision = get_post( $out['restore_revision_id'] );
		$this->assertSame( 'revision', $revision->post_type );
		$this->assertSame( $id, (int) $revision->post_parent );
		$this->assertSame( $content, $revision->post_content );
		$properties = aafm_args_wpbakery_replace_text()['output_schema']['properties'];
		$this->assertSame( 'integer', $properties['restore_revision_id']['type'] );
		$this->assertSame( 'string', $properties['reversible_reason']['type'] );
		$this->assertStringContainsString( 'restore_revision_id', aafm_ability_description( 'aafm/wpbakery-replace-text' ) );
		$this->assertStringContainsString( 'reversible_reason', aafm_ability_description( 'aafm/wpbakery-replace-text' ) );
	}

	public function test_replace_without_a_restore_point_says_why(): void {
		$content = self::PAGE;
		$search  = 'Opening paragraph alpha.';
		$replace = 'Opening paragraph changed.';

		$id = $this->make_page( $content );
		$this->acting_as( 'editor' );
		$keep = static fn(): int => 0;
		add_filter( 'wp_revisions_to_keep', $keep );

		$out = aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => $search,
				'replace' => $replace,
			)
		);
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertIsArray( $out );
		$this->assertArrayNotHasKey( 'restore_revision_id', $out );
		$this->assertSame( aafm_builder_revisions_off_reason(), $out['reversible_reason'] );
	}

	/**
	 * With one revision kept, core prunes the restore point during the save; the id must not be returned.
	 */
	public function test_replace_never_returns_a_restore_point_the_save_pruned(): void {
		$content = self::PAGE;
		$search  = 'Opening paragraph alpha.';
		$replace = 'Opening paragraph changed.';

		$id = $this->make_page( $content );
		$this->acting_as( 'editor' );
		$keep = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $keep );

		$out    = aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => $search,
				'replace' => $replace,
			)
		);
		$reason = aafm_builder_pruned_reason( get_post( $id ) );
		remove_filter( 'wp_revisions_to_keep', $keep );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
		$this->assertArrayNotHasKey( 'restore_revision_id', $out );
		$this->assertSame( $reason, $out['reversible_reason'] );
	}

	public function test_replace_with_no_match_creates_no_revision(): void {
		$content = self::PAGE;
		$search  = 'Opening paragraph alpha.';
		$replace = 'Opening paragraph changed.';

		$id = $this->make_page( $content );
		$this->acting_as( 'editor' );
		$before = count( wp_get_post_revisions( $id ) );
		$out    = aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'text that is not on the page 7c1e',
				'replace' => 'x',
			)
		);
		$this->assertSame( array( 'post', 'replacements' ), array_keys( $out ) );
		$this->assertSame( 0, $out['replacements'] );
		$this->assertSame( $before, count( wp_get_post_revisions( $id ) ) );
	}

	public function test_replace_schema_is_closed_and_requires_all_three(): void {
		$args = aafm_args_wpbakery_replace_text();

		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id', 'search', 'replace' ), $args['input_schema']['required'] );
		$this->assertSame( 1, $args['input_schema']['properties']['search']['minLength'] );
		$this->assertSame( 1, $args['input_schema']['properties']['post_id']['minimum'] );
		$this->assertSame( 'aafm-writes', $args['category'] );
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
			),
			$args['meta']['annotations']
		);
	}

	public function test_write_description_says_what_the_design_requires(): void {
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-replace-text'];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'wpbakery', $row['subject'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-replace-text'] ) as $text ) {
			$this->assertStringContainsString( 'edit access', $text );
			$this->assertStringContainsString( 'revision', $text );
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'Visual Composer', 'Avada', 'Elementor' ) as $other ) {
				$this->assertStringNotContainsString( $other, $text );
			}
		}
		$this->assertStringContainsString( 'heading', $row['description'] );
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
		$this->assertStringContainsString( 'Only a user who may post unfiltered HTML can put square brackets, percent-encoded brackets or data-* attributes in body text, and even then text that would add an element is refused.', $row['description'] );
	}

	public function test_replace_contributor_is_refused_on_another_users_page_and_editor_allowed(): void {
		$id = $this->make_page( self::PAGE, self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->acting_as( 'contributor' );
		$this->assertFalse(
			aafm_perm_avada_post_object(
				array(
					'post_id' => $id,
					'search'  => 'a',
					'replace' => 'b',
				)
			)
		);
		$this->acting_as( 'editor' );
		$this->assertTrue(
			aafm_perm_avada_post_object(
				array(
					'post_id' => $id,
					'search'  => 'a',
					'replace' => 'b',
				)
			)
		);
	}

	public function test_discovery_shows_the_write_to_an_editor_and_hides_it_from_a_subscriber(): void {
		$check = aafm_ability_list_permission( 'aafm/wpbakery-replace-text' );
		$this->assertIsCallable( $check );

		$this->acting_as( 'editor' );
		$this->assertTrue( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
	}

	public function test_replace_refuses_a_revision_id_and_an_attachment_id(): void {
		$id = $this->make_page( self::PAGE );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => self::PAGE . ' ',
			)
		);
		$revision   = (int) array_key_first( wp_get_post_revisions( $id ) );
		$attachment = self::factory()->attachment->create( array( 'post_parent' => $id ) );
		$this->acting_as( 'administrator' );

		foreach ( array( $revision, $attachment ) as $target ) {
			$this->assertFalse(
				aafm_perm_avada_post_object(
					array(
						'post_id' => $target,
						'search'  => 'a',
						'replace' => 'b',
					)
				)
			);
		}
	}

	public function test_audit_detail_reads_ran_a_text_replace_on_wpbakery_page(): void {
		$this->assertStringContainsString(
			'Ran a text replace on WPBakery page #42',
			(string) aafm_build_activity_detail( 'aafm/wpbakery-replace-text', array( 'post_id' => 42 ) )
		);
	}

	public function test_discovery_lists_the_builder_page_tools_to_a_page_only_editor(): void {
		add_role(
			'aafm_page_only_editor',
			'Page only editor',
			array(
				'read'                 => true,
				'edit_pages'           => true,
				'edit_published_pages' => true,
			)
		);
		add_role( 'aafm_reader_only', 'Reader only', array( 'read' => true ) );
		try {
			$page_editor = self::factory()->user->create( array( 'role' => 'aafm_page_only_editor' ) );
			$reader      = self::factory()->user->create( array( 'role' => 'aafm_reader_only' ) );
			$page        = $this->make_page( self::PAGE, $page_editor );

			wp_set_current_user( $page_editor );
			$this->assertFalse( current_user_can( 'edit_posts' ) );
			$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $page ) ), 'The page only editor can call the tools on their page.' );
			foreach ( array( 'aafm/wpbakery-get-page-content', 'aafm/wpbakery-replace-text', 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) as $name ) {
				$check = aafm_ability_list_permission( $name );
				$this->assertIsCallable( $check );
				$this->assertTrue( $check(), $name . ' is listed for a user who can call it on a page.' );
			}

			wp_set_current_user( $reader );
			foreach ( array( 'aafm/wpbakery-get-page-content', 'aafm/wpbakery-replace-text', 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) as $name ) {
				$this->assertFalse( aafm_ability_list_permission( $name )(), $name . ' stays hidden from a user who can edit nothing.' );
			}
		} finally {
			remove_role( 'aafm_page_only_editor' );
			remove_role( 'aafm_reader_only' );
		}
	}

	public function test_replace_works_on_a_grid_item_without_a_row(): void {
		// WPBakery registers vc_grid_item as not public with capability type post (W16).
		register_post_type(
			'vc_grid_item',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'editor' ),
			)
		);
		$content = '[vc_gitem][vc_gitem_animated_block][vc_gitem_zone_a][vc_gitem_row position="top"][vc_gitem_col width="1/1"]Grid label[/vc_gitem_col][/vc_gitem_row][/vc_gitem_zone_a][/vc_gitem_animated_block][/vc_gitem]';
		$this->acting_as( 'editor' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'vc_grid_item',
				'post_content' => $content,
			)
		);
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id ) );

		$out = $this->replace( $id, 'Grid label', 'Grid title' );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
		$this->assertSame( str_replace( 'Grid label', 'Grid title', $content ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_replace_still_refuses_a_plain_page_without_a_row(): void {
		$content = '[vc_column_text]<p>Loose text.</p>[/vc_column_text]';
		$id      = $this->make_page( $content );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->replace( $id, 'Loose text.', 'Changed.' ), 'aafm_not_wpbakery_owned', $id, $content, 409 );
	}

	public function test_get_page_content_reports_a_grid_item_as_wpbakery_owned(): void {
		register_post_type(
			'vc_grid_item',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'editor' ),
			)
		);
		$this->acting_as( 'editor' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'vc_grid_item',
				'post_content' => '[vc_gitem][vc_gitem_animated_block][/vc_gitem_animated_block][/vc_gitem]',
			)
		);
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id, true ) );

		$this->assertTrue( $this->read( array( 'post_id' => $id ) )['is_wpbakery_owned'] );
	}
	/**
	 * The S8a fixture: a page WPBakery 9.1's own editor saved on the smoke site (scratchpad/1113/s8a-confirm.md).
	 *
	 * @return string
	 */
	private function elements_fixture(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Wpbakery/page-elements.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
	}

	/**
	 * The outline of the fixture page read as the given role.
	 *
	 * @param string $role Role.
	 * @return array<string,mixed>
	 */
	private function read_elements( string $role = 'editor' ): array {
		$id = $this->make_page( $this->elements_fixture() );
		$this->acting_as( $role );
		return $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);
	}

	/**
	 * The decoded attributes of the first outline item with a tag.
	 *
	 * @param array<string,mixed> $out Read output.
	 * @param string              $tag Tag.
	 * @return array<string,mixed>
	 */
	private function item( array $out, string $tag ): array {
		foreach ( $out['structure'] as $item ) {
			if ( $tag === $item['tag'] ) {
				return $item;
			}
		}
		$this->fail( 'No ' . $tag . ' in the outline.' );
	}

	public function test_outline_items_carry_a_fingerprint_that_resolves(): void {
		$content = $this->elements_fixture();
		$out     = $this->read_elements();
		$tags    = aafm_wpbakery_shortcode_tags( $content );

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertCount( 13, $out['structure'] );
		foreach ( $out['structure'] as $item ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $item['fingerprint'] );
			$this->assertIsArray(
				aafm_builder_resolve(
					$content,
					$tags,
					$item['index'],
					array(
						'tag'         => $item['tag'],
						'fingerprint' => $item['fingerprint'],
					)
				)
			);
		}
	}

	public function test_the_read_returns_the_page_fingerprint_with_the_outline(): void {
		$out = $this->read_elements();
		$this->assertSame( aafm_builder_page_fingerprint( $this->elements_fixture() ), $out['page_fingerprint'] );

		$plain = $this->read( array( 'post_id' => $out['post_id'] ) );
		$this->assertArrayNotHasKey( 'page_fingerprint', $plain );
	}

	public function test_decoded_heading_reverses_quotes_and_brackets(): void {
		$heading = $this->item( $this->read_elements(), 'vc_custom_heading' );

		$this->assertSame( 'Say "hello" to [S8a] heading', ( (array) $heading['atts_decoded'] )['text'] );
		$this->assertSame( 'Say ``hello`` to `{`S8a`}` heading', ( (array) $heading['atts'] )['text'] );
	}

	public function test_decoded_link_has_four_parts(): void {
		$button = $this->item( $this->read_elements(), 'wpb_btn' );

		$this->assertSame(
			array(
				'url'    => 'https://example.com/docs?a=1&b=2',
				'title'  => 'Docs: start here',
				'target' => '_blank',
				'rel'    => 'nofollow',
			),
			( (array) $button['atts_decoded'] )['link']
		);
		$this->assertSame( 'Read the docs', ( (array) $button['atts_decoded'] )['title'] );
	}

	public function test_decoded_param_group_is_rows(): void {
		$bar = $this->item( $this->read_elements(), 'vc_progress_bar' );

		$this->assertSame(
			array(
				array(
					'label' => 'Skill "A" [one]',
					'value' => '90',
				),
				array(
					'label' => 'Design',
					'value' => '80',
				),
				array(
					'label' => 'Marketing',
					'value' => '70',
				),
			),
			( (array) $bar['atts_decoded'] )['values']
		);
	}

	public function test_decoded_safe_text_reverses_the_e8_form(): void {
		$gallery = $this->item( $this->read_elements(), 'vc_gallery' );

		$this->assertSame( 'https://example.com/one,https://example.com/two', ( (array) $gallery['atts_decoded'] )['custom_links'] );
		$this->assertStringStartsWith( '#E-8_', ( (array) $gallery['atts'] )['custom_links'] );
	}

	public function test_decoded_raw_html_is_the_markup(): void {
		$out = $this->read_elements();

		$this->assertSame( '<div class="s8a-raw">Raw <strong>HTML</strong> &amp; "quotes"</div>', $this->item( $out, 'vc_raw_html' )['text'] );
		$this->assertSame( '<script>window.s8aRawJs = "ok";</script>', $this->item( $out, 'vc_raw_js' )['text'] );
		$this->assertNull( aafm_wpbakery_decode_raw( 'not base64 at all!' ) );
	}

	public function test_text_is_present_only_on_a_leaf(): void {
		$out = $this->read_elements();

		$this->assertArrayNotHasKey( 'text', $this->item( $out, 'vc_row' ) );
		$this->assertArrayNotHasKey( 'text', $this->item( $out, 'vc_column_inner' ) );
		$this->assertSame( "\r\n\r\nSeed text block.\r\n\r\n", $this->item( $out, 'vc_column_text' )['text'] );
		$this->assertSame( '', $this->item( $out, 'vc_custom_heading' )['text'] );
	}

	/**
	 * A fixture page with WPBakery's page metas set, read with include_page_settings as a user.
	 *
	 * @param int $user User id.
	 * @return array<string,mixed>
	 */
	private function read_settings_as( int $user ): array {
		$id = $this->make_page( $this->elements_fixture() );
		update_post_meta( $id, '_wpb_post_custom_css', '.s8a-page { color: #123456; }' );
		update_post_meta( $id, '_wpb_vc_js_status', 'true' );
		update_post_meta( $id, '_wpb_post_custom_js_header', 'window.s8aHeader = 1;' );
		update_post_meta( $id, '_wpb_post_custom_js_footer', 'window.s8aFooter = 2;' );
		wp_set_current_user( $user );
		return $this->read(
			array(
				'post_id'               => $id,
				'include_page_settings' => true,
			)
		);
	}

	public function test_page_settings_key_is_absent_without_the_flag(): void {
		$out = $this->read_elements();
		$this->assertArrayNotHasKey( 'page_settings', $out );
	}

	public function test_page_settings_returns_css_and_status(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$out    = $this->read_settings_as( $editor );

		$this->assertSame( '.s8a-page { color: #123456; }', $out['page_settings']['custom_css'] );
		$this->assertSame( 'true', $out['page_settings']['editor_status'] );
		$this->assertArrayNotHasKey( 'structure', $out );
	}

	public function test_page_js_keys_are_absent_without_unfiltered_html(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $editor )->add_cap( 'unfiltered_html', false );
		$this->assertFalse( user_can( $editor, 'unfiltered_html' ) );

		$this->assertSame( array( 'custom_css', 'editor_status' ), array_keys( $this->read_settings_as( $editor )['page_settings'] ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( user_can( $admin, 'unfiltered_html' ) );
		$settings = $this->read_settings_as( $admin )['page_settings'];
		$this->assertSame( 'window.s8aHeader = 1;', $settings['custom_js_header'] );
		$this->assertSame( 'window.s8aFooter = 2;', $settings['custom_js_footer'] );
	}

	public function test_a_failed_meta_read_is_an_error_not_an_empty_value(): void {
		$id = $this->make_page( $this->elements_fixture() );
		update_post_meta( $id, '_wpb_post_custom_css', '.s8a-page { color: #123456; }' );
		$this->acting_as( 'editor' );
		wp_cache_delete( $id, 'post_meta' );
		QueryFaultInjector::reset_fired_count();

		$out = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT post_id, meta_key, meta_value FROM', 'postmeta' ),
			static function () use ( $id ) {
				return aafm_exec_wpbakery_get_page_content(
					array(
						'post_id'               => $id,
						'include_page_settings' => true,
					)
				);
			}
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $out, (string) wp_json_encode( $out ) );
	}

	public function test_a_grid_item_is_readable_by_an_editor_and_refused_for_a_subscriber(): void {
		register_post_type(
			'vc_grid_item',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'editor' ),
			)
		);
		$content = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Wpbakery/grid-item.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
		$id      = self::factory()->post->create(
			array(
				'post_type'    => 'vc_grid_item',
				'post_content' => $content,
				'post_author'  => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			)
		);

		$this->acting_as( 'editor' );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);
		$this->assertSame( $content, $out['content'] );
		$this->assertTrue( $out['is_wpbakery_owned'] );
		$this->assertCount( 25, $out['structure'] );
		$this->assertSame( "\r\n\r\nGrid card label\r\n\r\n", $this->item( $out, 'vc_column_text' )['text'] );

		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_read_schema_declares_include_page_settings_and_stays_closed(): void {
		$args = aafm_args_wpbakery_get_page_content();

		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( 'boolean', $args['input_schema']['properties']['include_page_settings']['type'] );
		$this->assertFalse( $args['input_schema']['properties']['include_page_settings']['default'] );
		$this->assertSame( array( 'post_id', 'include_structure', 'include_page_settings' ), array_keys( $args['input_schema']['properties'] ) );
		foreach ( array( 'page_fingerprint', 'page_settings' ) as $key ) {
			$this->assertArrayHasKey( $key, $args['output_schema']['properties'] );
		}
		foreach ( array( 'fingerprint', 'atts_decoded', 'text' ) as $key ) {
			$this->assertArrayHasKey( $key, $args['output_schema']['properties']['structure']['items']['properties'] );
		}
	}

	public function test_read_description_names_only_wpbakery_and_has_no_spaced_hyphen(): void {
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-get-page-content'];
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-get-page-content'] ) as $text ) {
			$this->assertStringContainsString( 'WPBakery', $text );
			$this->assertStringContainsString( 'include_page_settings', $text );
			$this->assertStringContainsString( 'unfiltered HTML', $text );
			$this->assertStringContainsString( 'Role Manager', $text );
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'Visual Composer', 'Avada', 'Elementor', 'Fusion' ) as $other ) {
				$this->assertStringNotContainsString( $other, $text );
			}
		}
	}
	/**
	 * A vc_grid_item post with WPBakery's grid post type NOT registered, as in a REST request (W16:
	 * WPBakery registers it only inside is_admin()), and a seam stand-in that registers it the way
	 * Vc_Grid_Item_Editor::createPostType() does. Returns the post id and a call counter.
	 *
	 * @param bool $with_seam Whether the seam entry is present.
	 * @return array{0:int,1:\ArrayObject<string,int>}
	 */
	private function unregistered_grid_item( bool $with_seam ): array {
		unregister_post_type( 'vc_grid_item' );
		$this->assertFalse( post_type_exists( 'vc_grid_item' ) );
		$id    = (int) wp_insert_post(
			array(
				'post_type'    => 'vc_grid_item',
				'post_status'  => 'publish',
				'post_title'   => 'Grid',
				'post_content' => (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Wpbakery/grid-item.txt' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
				'post_author'  => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			)
		);
		$calls = new \ArrayObject( array( 'n' => 0 ) );
		$seam  = static function ( $resolved, string $entry ) use ( $calls, $with_seam ) {
			if ( 'wpbakery_grid_post_type' !== $entry ) {
				return $resolved;
			}
			return $with_seam ? static function () use ( $calls ): void {
				++$calls['n'];
				register_post_type(
					'vc_grid_item',
					array(
						'public'          => false,
						'capability_type' => 'post',
						'supports'        => array( 'title', 'editor' ),
					)
				);
			} : null;
		};
		add_filter( 'aafm_builder_vendor', $seam, 10, 2 );
		return array( $id, $calls );
	}

	public function test_a_grid_item_is_readable_through_the_seam_when_the_type_is_not_registered(): void {
		list( $id, $calls ) = $this->unregistered_grid_item( true );
		$this->acting_as( 'editor' );

		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertSame( 1, $calls['n'] );
		$this->assertTrue( post_type_exists( 'vc_grid_item' ) );
		$out = $this->read(
			array(
				'post_id'           => $id,
				'include_structure' => true,
			)
		);
		$this->assertTrue( $out['is_wpbakery_owned'] );
		$this->assertCount( 25, $out['structure'] );

		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertSame( 1, $calls['n'], 'Once registered, the seam is not called again.' );
	}

	public function test_a_grid_item_stays_refused_when_the_seam_entry_is_absent(): void {
		list( $id ) = $this->unregistered_grid_item( false );
		$this->acting_as( 'editor' );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertFalse( post_type_exists( 'vc_grid_item' ) );
	}

	public function test_a_user_without_edit_access_is_still_refused_after_the_seam_registers(): void {
		list( $id, $calls ) = $this->unregistered_grid_item( true );
		$this->acting_as( 'subscriber' );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertSame( 1, $calls['n'] );
		$this->acting_as( 'contributor' );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ), 'A contributor cannot edit an administrator\'s grid template.' );
	}

	public function test_the_seam_registers_nothing_for_another_type_or_with_wpbakery_inactive(): void {
		list( $grid, $calls ) = $this->unregistered_grid_item( true );
		unregister_post_type( 'fusion_element' );
		$other = (int) wp_insert_post(
			array(
				'post_type'    => 'fusion_element',
				'post_status'  => 'publish',
				'post_title'   => 'Element',
				'post_content' => '[fusion_text]x[/fusion_text]',
			)
		);
		$this->acting_as( 'editor' );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $other ) ) );
		$this->assertSame( 0, $calls['n'] );
		$this->assertFalse( post_type_exists( 'fusion_element' ) );

		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		add_filter( 'aafm_integration_active_wpbakery', '__return_false' );
		try {
			$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $grid ) ) );
			$this->assertSame( 0, $calls['n'] );
			$this->assertFalse( post_type_exists( 'vc_grid_item' ) );
		} finally {
			remove_filter( 'aafm_integration_active_wpbakery', '__return_false' );
			add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		}
	}
	/**
	 * Stand in for WPBakery's element map through the seam (Ruling B14): tag => param name => type.
	 *
	 * @param array<string,array<string,string>> $map Types.
	 * @return callable The filter, for removal.
	 */
	private function map_types( array $map ): callable {
		$filter = static function ( $resolved, string $entry ) use ( $map ) {
			if ( 'wpbakery_param_type' !== $entry ) {
				return $resolved;
			}
			return static function ( string $tag, string $name ) use ( $map ): ?string {
				return $map[ $tag ][ $name ] ?? null;
			};
		};
		add_filter( 'aafm_builder_vendor', $filter, 10, 2 );
		return $filter;
	}

	public function test_a_plain_text_that_starts_like_a_link_stays_a_string(): void {
		$atts = array(
			'text'  => 'url:https://example.com/docs',
			'title' => 'title: our services',
		);
		$this->assertSame( $atts, aafm_wpbakery_decode_atts( 'vc_custom_heading', $atts ), 'Without a map the strict shape rule keeps text.' );

		$filter = $this->map_types(
			array(
				'vc_custom_heading' => array(
					'text'  => 'textarea',
					'title' => 'textfield',
				),
			)
		);
		$this->assertSame( $atts, aafm_wpbakery_decode_atts( 'vc_custom_heading', $atts ), 'A mapped text setting is never a link.' );
		remove_filter( 'aafm_builder_vendor', $filter, 10 );
	}

	public function test_a_link_with_colons_and_a_port_keeps_its_full_url_with_and_without_the_map(): void {
		$atts   = array( 'link' => 'url:https%3A%2F%2Fexample.com%3A8080%2Fa%3Ab|title:Docs%3A%20start|target:_blank|rel:nofollow' );
		$expect = array(
			'link' => array(
				'url'    => 'https://example.com:8080/a:b',
				'title'  => 'Docs: start',
				'target' => '_blank',
				'rel'    => 'nofollow',
			),
		);
		$this->assertSame( $expect, aafm_wpbakery_decode_atts( 'wpb_btn', $atts ) );

		$filter = $this->map_types( array( 'wpb_btn' => array( 'link' => 'link' ) ) );
		$this->assertSame( $expect, aafm_wpbakery_decode_atts( 'wpb_btn', $atts ) );
		remove_filter( 'aafm_builder_vendor', $filter, 10 );

		$filter = $this->map_types( array( 'vc_custom_heading' => array( 'link' => 'vc_link' ) ) );
		$this->assertSame( $expect, aafm_wpbakery_decode_atts( 'vc_custom_heading', $atts ) );
		remove_filter( 'aafm_builder_vendor', $filter, 10 );
	}
	public function test_a_legacy_link_with_empty_segments_still_decodes(): void {
		$expect = array(
			'url'    => '#',
			'title'  => '',
			'target' => '',
			'rel'    => '',
		);
		$this->assertSame( array( 'link' => $expect ), aafm_wpbakery_decode_atts( 'vc_btn', array( 'link' => 'url:%23|||' ) ) );
		$this->assertSame( array( 'link' => $expect ), aafm_wpbakery_decode_atts( 'vc_btn', array( 'link' => 'url:%23|' ) ) );
		$this->assertSame( array( 'link' => 'url:%23|||plain' ), aafm_wpbakery_decode_atts( 'vc_btn', array( 'link' => 'url:%23|||plain' ) ), 'A segment that names no link key is not a link.' );
	}
	public function test_an_unmapped_link_with_an_apostrophe_and_parentheses_decodes(): void {
		$this->assertSame(
			array(
				'link' => array(
					'url'    => 'https://example.com/a_(b)!*',
					'title'  => "Bob's page",
					'target' => '',
					'rel'    => '',
				),
			),
			aafm_wpbakery_decode_atts( 'vc_btn', array( 'link' => "url:https%3A%2F%2Fexample.com%2Fa_(b)!*|title:Bob's%20page" ) )
		);
		$this->assertSame( array( 'text' => 'url:https://example.com/docs' ), aafm_wpbakery_decode_atts( 'vc_custom_heading', array( 'text' => 'url:https://example.com/docs' ) ) );
	}
	public function test_a_mapped_link_keeps_only_the_four_link_parts(): void {
		$filter = $this->map_types( array( 'vc_custom_heading' => array( 'link' => 'vc_link' ) ) );
		$this->assertSame(
			array(
				'link' => array(
					'url'    => '',
					'title'  => '',
					'target' => '',
					'rel'    => '',
				),
			),
			aafm_wpbakery_decode_atts( 'vc_custom_heading', array( 'link' => 'https://example.com/plain' ) )
		);
		remove_filter( 'aafm_builder_vendor', $filter, 10 );
	}
}
