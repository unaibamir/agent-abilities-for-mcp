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
		$this->assertSame( $revisions + 1, count( wp_get_post_revisions( $id ) ) );
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
}
