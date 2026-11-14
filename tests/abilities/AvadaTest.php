<?php
/**
 * Native Avada/Fusion Builder integration: avada-get-page-content, avada-replace-text.
 *
 * Fixture corpus per the design doc's own requirement: a self-closing element, an
 * attribute value containing a literal ']', and nested columns using distinct "_inner" tag names.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class AvadaTest extends TestCase {

	private const NESTED_COLUMNS = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1/2"][fusion_builder_row_inner][fusion_builder_column_inner type="1/4"][fusion_text]Left[/fusion_text][/fusion_builder_column_inner][fusion_builder_column_inner type="1/4"][fusion_text]Right[/fusion_text][/fusion_builder_column_inner][/fusion_builder_row_inner][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		foreach ( array( 'fusion_global', 'fusion_title', 'fusion_button', 'fusion_imageframe', 'fusion_code', 'fusion_tabs', 'fusion_tab' ) as $tag ) {
			remove_shortcode( $tag );
		}
		parent::tear_down();
	}

	private function make_avada_post( string $content ): int {
		$id = self::factory()->post->create( array( 'post_content' => $content ) );
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		return $id;
	}

	public function test_both_abilities_are_in_the_registry(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/avada-get-page-content', $registry );
		$this->assertSame( 'read', $registry['aafm/avada-get-page-content']['risk'] );
		$this->assertArrayHasKey( 'aafm/avada-replace-text', $registry );
		$this->assertSame( 'write', $registry['aafm/avada-replace-text']['risk'] );
	}

	public function test_get_page_content_returns_the_raw_shortcode_markup_unchanged(): void {
		$id = $this->make_avada_post( self::NESTED_COLUMNS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_get_page_content( array( 'post_id' => $id ) );

		$this->assertSame( self::NESTED_COLUMNS, $out['content'] );
		$this->assertTrue( $out['is_avada_owned'] );
	}

	public function test_get_page_content_reports_a_plain_post_as_not_avada_owned(): void {
		$id = self::factory()->post->create( array( 'post_content' => 'ordinary text' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_get_page_content( array( 'post_id' => $id ) );

		$this->assertFalse( $out['is_avada_owned'] );
	}

	public function test_get_page_content_reports_an_avada_marker_supplied_only_by_a_read_filter(): void {
		$id     = self::factory()->post->create( array( 'post_content' => 'ordinary text' ) );
		$supply = static function ( $value, $object_id, $meta_key ) use ( $id ) {
			return ( $id === (int) $object_id && 'fusion_builder_status' === $meta_key ) ? 'active' : $value;
		};
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'get_post_metadata', $supply, 10, 3 );
		try {
			$out = aafm_exec_avada_get_page_content( array( 'post_id' => $id ) );
		} finally {
			remove_filter( 'get_post_metadata', $supply, 10 );
		}

		$this->assertTrue( $out['is_avada_owned'] );
	}

	public function test_get_page_content_reports_not_avada_owned_when_the_marker_read_fails(): void {
		$id = $this->make_avada_post( self::NESTED_COLUMNS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		aafm_exact_object( 'post', $id );
		wp_cache_delete( $id, 'post_meta' );
		QueryFaultInjector::reset_fired_count();

		$out = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT post_id, meta_key, meta_value FROM', 'postmeta' ),
			static function () use ( $id ) {
				return aafm_exec_avada_get_page_content( array( 'post_id' => $id ) );
			},
			1
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertIsArray( $out );
		$this->assertSame( self::NESTED_COLUMNS, $out['content'] );
		$this->assertFalse( $out['is_avada_owned'] );
	}

	/**
	 * Avada-replace-text must refuse a post that is not genuinely Avada-owned - including one
	 * owned by a DIFFERENT foreign builder, which carries no Fusion shortcodes at all and would
	 * otherwise pass the structural-signature check trivially.
	 */
	public function test_replace_text_refuses_a_post_owned_by_a_different_foreign_builder(): void {
		$id = self::factory()->post->create( array( 'post_content' => 'Elementor-owned body text' ) );
		update_post_meta( $id, '_elementor_data', '[]' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'body',
				'replace' => 'BODY',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_not_avada_owned', $out->get_error_code() );
	}

	public function test_replace_text_refuses_a_plain_unowned_post(): void {
		$id = self::factory()->post->create( array( 'post_content' => 'plain body text' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'plain',
				'replace' => 'PLAIN',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_not_avada_owned', $out->get_error_code() );
	}

	public function test_perm_requires_edit_access(): void {
		$id = $this->make_avada_post( self::NESTED_COLUMNS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_replace_text_allows_a_plain_text_edit_between_elements(): void {
		$content = '[fusion_builder_container]Hello world[fusion_separator /]More text[/fusion_builder_container]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'Hello world',
				'replace' => 'Greetings world',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
		$post = get_post( $id );
		$this->assertStringContainsString( 'Greetings world', $post->post_content );
		$this->assertStringContainsString( '[fusion_separator /]', $post->post_content );
	}

	/**
	 * The replacement count was reported straight from the pre-save string
	 * count, never re-validated against what actually landed - a wp_insert_post_data filter
	 * that reverts the content back to the original must surface as a structured error, not a
	 * success response claiming the replacement was saved.
	 */
	public function test_replace_text_returns_an_error_when_the_write_is_vetoed(): void {
		$content = '[fusion_builder_container]Hello world[/fusion_builder_container]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$veto = static function ( $data ) use ( $content ) {
			$data['post_content'] = $content;
			return $data;
		};
		add_filter( 'wp_insert_post_data', $veto );
		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'Hello world',
				'replace' => 'Greetings world',
			)
		);
		remove_filter( 'wp_insert_post_data', $veto );

		$this->assertInstanceOf(
			WP_Error::class,
			$out,
			'A vetoed content write must return an error, not a success claiming the replacement was saved.'
		);
	}

	/**
	 * Counting each quote character independently (odd '"' OR odd
	 * "'") false-positived on a perfectly ordinary attribute value containing an apostrophe
	 * INSIDE a double-quoted value - not a second delimiter, just a literal character. A safe,
	 * unrelated plain-text edit must not be refused because of it.
	 */
	public function test_replace_text_allows_an_apostrophe_inside_a_double_quoted_attribute(): void {
		$content = '[fusion_text title="Bob\'s title"]Hello[/fusion_text]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'Hello',
				'replace' => 'Hi',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
		$post = get_post( $id );
		$this->assertStringContainsString( 'Hi', $post->post_content );
		$this->assertStringContainsString( 'title="Bob\'s title"', $post->post_content );
	}

	public function test_replace_text_refuses_an_edit_that_would_alter_a_self_closing_element(): void {
		$content = '[fusion_builder_container]Text[fusion_separator /]More[/fusion_builder_container]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$before = get_post( $id )->post_content;
		$out    = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => '[fusion_separator /]',
				'replace' => '[fusion_separator]',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_replace_inside_shortcode', $out->get_error_code() );
		$this->assertSame( $before, get_post( $id )->post_content );
	}

	/**
	 * A literal ']' inside a quoted attribute value truncates get_shortcode_regex()'s own
	 * attribute-span capture mid-quote (content="a[1]" captures only content="a[1, an
	 * unterminated quote) - WordPress's own parser has lost track of the real boundary at that
	 * point, so this guard fails closed on the WHOLE post rather than trust a span it cannot
	 * verify, even for an edit that only touches unrelated plain text elsewhere in the document.
	 */
	public function test_a_literal_bracket_in_an_attribute_value_fails_closed_on_the_whole_post(): void {
		$content = '[fusion_text content="a[1]"]Some other plain text[/fusion_text]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'Some other plain text',
				'replace' => 'Some other plain text edited',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_replace_inside_shortcode', $out->get_error_code() );
	}

	/**
	 * Removing a closing tag changes the shortcode's real structure (its content is no longer
	 * INSIDE the element), and must be refused - even though the tag/self_closing/atts/depth
	 * tuple alone cannot tell the difference.
	 */
	public function test_replacing_away_a_closing_tag_is_refused(): void {
		$content = '[fusion_text]Hello[/fusion_text]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$before = get_post_field( 'post_content', $id, 'raw' );
		$out    = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => '[/fusion_text]',
				'replace' => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_replace_inside_shortcode', $out->get_error_code() );
		$this->assertSame( $before, get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * A Fusion shortcode NOT in the hardcoded baseline list (fusion_button) must still be
	 * protected when the site's real shortcode registry (WordPress's $shortcode_tags global,
	 * populated by Fusion Builder's own add_shortcode() calls) carries it - the dynamic-discovery
	 * fix.
	 */
	public function test_an_unlisted_but_registered_fusion_tag_is_still_protected(): void {
		add_shortcode( 'fusion_button', '__return_empty_string' );

		$content = '[fusion_button text="Click me"]Body[/fusion_button]';
		$id      = $this->make_avada_post( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$before = get_post_field( 'post_content', $id, 'raw' );
		$out    = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'text="Click me"',
				'replace' => 'text="Different"',
			)
		);

		remove_shortcode( 'fusion_button' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_replace_inside_shortcode', $out->get_error_code() );
		$this->assertSame( $before, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_nested_columns_with_distinct_inner_tags_are_a_stable_signature(): void {
		$id = $this->make_avada_post( self::NESTED_COLUMNS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// No search term present - a no-op, but must not error on the nested-column fixture.
		$out = aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'nonexistent-term-xyz',
				'replace' => 'anything',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['replacements'] );
	}

	/**
	 * Read a page as an editor, with or without the element outline.
	 *
	 * @param int       $id      Post id.
	 * @param bool|null $outline include_structure, or null to leave it out.
	 * @return array<string,mixed>
	 */
	private function read_page( int $id, ?bool $outline ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$input = array( 'post_id' => $id );
		if ( null !== $outline ) {
			$input['include_structure'] = $outline;
		}
		$out = aafm_exec_avada_get_page_content( $input );
		$this->assertIsArray( $out );
		return $out;
	}

	public function test_structure_lists_the_elements_in_document_order_with_their_depth(): void {
		$out = $this->read_page( $this->make_avada_post( self::NESTED_COLUMNS ), true );

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertSame( range( 0, 7 ), array_column( $out['structure'], 'index' ) );
		$this->assertSame(
			array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_builder_row_inner', 'fusion_builder_column_inner', 'fusion_text', 'fusion_builder_column_inner', 'fusion_text' ),
			array_column( $out['structure'], 'tag' )
		);
		$this->assertSame( array( 0, 1, 2, 3, 4, 5, 4, 5 ), array_column( $out['structure'], 'depth' ) );
		$this->assertSame( array( 'type' => '1/2' ), (array) $out['structure'][2]['atts'] );
		$this->assertFalse( $out['structure'][5]['self_closing'] );
		$this->assertTrue( $out['structure'][5]['has_closer'] );
		$this->assertArrayNotHasKey( 'global_id', $out['structure'][0] );
		$this->assertSame( self::NESTED_COLUMNS, $out['content'], 'The raw content is still returned.' );
	}

	public function test_structure_keys_are_absent_unless_asked_for(): void {
		$id = $this->make_avada_post( self::NESTED_COLUMNS );
		foreach ( array( null, false ) as $flag ) {
			$out = $this->read_page( $id, $flag );
			$this->assertArrayNotHasKey( 'structure', $out );
			$this->assertArrayNotHasKey( 'structure_status', $out );
		}
	}

	public function test_structure_is_unparseable_with_no_structure_key_when_the_walk_fails(): void {
		$out = $this->read_page( $this->make_avada_post( '[fusion_text content="a[1]"]Some other plain text[/fusion_text]' ), true );

		$this->assertSame( 'unparseable', $out['structure_status'] );
		$this->assertArrayNotHasKey( 'structure', $out, 'No empty list standing in for a failed parse.' );
	}

	public function test_structure_is_truncated_at_two_thousand_elements(): void {
		$out = $this->read_page( $this->make_avada_post( str_repeat( '[fusion_separator /]', 2001 ) ), true );

		$this->assertSame( 'truncated', $out['structure_status'] );
		$this->assertCount( 2000, $out['structure'] );
		$this->assertSame( 1999, $out['structure'][1999]['index'] );
		$this->assertTrue( $out['structure'][0]['self_closing'] );
	}

	public function test_structure_names_the_library_item_behind_a_global_element(): void {
		// Avada registers fusion_global itself (fusion-builder/shortcodes/fusion-global.php); PHPUnit does not load Avada.
		add_shortcode( 'fusion_global', '__return_empty_string' );
		// A global is stored as a bare opener with no closer, as page 22 held it on the S4-7 smoke site
		// ([fusion_global id="19"]); update_global_elements() collapses an expanded one back to that on save.
		$content = '[fusion_global id="12"][fusion_builder_container fusion_global="34"][fusion_text]Shared[/fusion_text][/fusion_builder_container]';

		$out = $this->read_page( $this->make_avada_post( $content ), true );

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertSame( 'fusion_global', $out['structure'][0]['tag'] );
		$this->assertFalse( $out['structure'][0]['has_closer'] );
		$this->assertSame( 12, $out['structure'][0]['global_id'] );
		$this->assertSame( 'fusion_builder_container', $out['structure'][1]['tag'] );
		$this->assertSame( 0, $out['structure'][1]['depth'], 'The bare opener does not swallow its sibling.' );
		$this->assertSame( 34, $out['structure'][1]['global_id'] );
		$this->assertArrayNotHasKey( 'global_id', $out['structure'][2] );
	}

	public function test_structure_of_a_page_without_fusion_elements_is_an_empty_ok_list(): void {
		$id  = self::factory()->post->create( array( 'post_content' => 'ordinary text' ) );
		$out = $this->read_page( $id, true );

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertSame( array(), $out['structure'] );
		$this->assertFalse( $out['is_avada_owned'] );
	}

	public function test_get_page_content_schema_declares_include_structure(): void {
		$args = aafm_args_avada_get_page_content();
		$this->assertSame( 'boolean', $args['input_schema']['properties']['include_structure']['type'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'ok', 'unparseable', 'truncated' ), $args['output_schema']['properties']['structure_status']['enum'] );
		$this->assertSame( 'array', $args['output_schema']['properties']['structure']['type'] );
	}

	private const AV1_PAGE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]<p>Change me.</p>[/fusion_text][fusion_text]<iframe></iframe>[/fusion_text][fusion_title size="2"]Kept title[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private function av1_replace( int $id, string $search, string $replace ) {
		return aafm_exec_avada_replace_text(
			array(
				'post_id' => $id,
				'search'  => $search,
				'replace' => $replace,
			)
		);
	}

	private function assert_av1_refused( $out, string $code, int $id, string $before ): void {
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $code, $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertStringNotContainsString( ' - ', $out->get_error_message() );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post_field( 'post_content', $id, 'raw' ), 'Nothing was changed.' );
	}

	/**
	 * Register one of Avada's own types the way fusion-builder does (A8): not public, capability type post.
	 *
	 * @param string $type         Post type.
	 * @param bool   $map_meta_cap Whether core maps meta capabilities for it.
	 */
	private function register_avada_type( string $type, bool $map_meta_cap = true ): void {
		register_post_type(
			$type,
			array(
				'public'          => false,
				'capability_type' => 'post',
				'map_meta_cap'    => $map_meta_cap,
				'supports'        => array( 'title', 'editor', 'revisions' ),
			)
		);
	}

	public function test_replace_refuses_when_the_save_would_strip_untouched_content(): void {
		$this->acting_as( 'editor' ); // Created as a user with unfiltered_html, so the iframe is stored.
		$id = $this->make_avada_post( self::AV1_PAGE );
		$this->assertSame( self::AV1_PAGE, get_post_field( 'post_content', $id, 'raw' ) );
		$strip = static fn( $value ) => str_replace( '<iframe></iframe>', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$out = $this->av1_replace( $id, 'Change me.', 'Changed.' );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}

		$this->assert_av1_refused( $out, 'aafm_avada_save_would_change_page', $id, self::AV1_PAGE );
	}

	public function test_replace_refuses_when_a_save_filter_deletes_an_element_once_the_new_text_is_present(): void {
		$this->acting_as( 'editor' );
		$id    = $this->make_avada_post( self::AV1_PAGE );
		$react = static function ( $value ) {
			$value = (string) $value;
			return false === strpos( $value, 'Trigger text' ) ? $value : str_replace( '[fusion_text]<iframe></iframe>[/fusion_text]', '', $value );
		};
		add_filter( 'content_save_pre', $react );
		try {
			$out = $this->av1_replace( $id, 'Change me.', 'Trigger text' );
		} finally {
			remove_filter( 'content_save_pre', $react );
		}

		$this->assert_av1_refused( $out, 'aafm_avada_save_would_change_layout', $id, self::AV1_PAGE );
	}

	public function test_replace_as_a_user_without_unfiltered_html_refuses_on_content_with_an_iframe(): void {
		$content = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/page-s7.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as( 'administrator' ); // The iframe is stored by a user who may store it.
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_author'  => $author,
				'post_content' => $content,
			)
		);
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertStringContainsString( '<iframe', $content );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );

		$this->assert_av1_refused( $this->av1_replace( $id, 'Paragraph bravo.', 'Paragraph changed.' ), 'aafm_avada_save_would_change_page', $id, $content );
		$this->assertStringContainsString( '<iframe', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_replace_works_on_a_layout_section_for_an_administrator(): void {
		$this->register_avada_type( 'fusion_tb_section' );
		$this->acting_as( 'administrator' );
		$content = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]Footer note.[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$id      = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_tb_section',
				'post_content' => $content,
			)
		);
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id ) );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );

		$out = $this->av1_replace( $id, 'Footer note.', 'New footer note.' );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['replacements'] );
		$this->assertSame( str_replace( 'Footer note.', 'New footer note.', $content ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_a_section_is_refused_without_manage_options(): void {
		$this->register_avada_type( 'fusion_tb_section' );
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create( array( 'post_type' => 'fusion_tb_section' ) );
		$this->acting_as( 'editor' );

		$this->assertTrue( current_user_can( 'edit_post', $id ) );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_a_library_element_is_editable_by_an_editor(): void {
		$this->register_avada_type( 'fusion_element' );
		$this->acting_as( 'editor' );
		$id = self::factory()->post->create( array( 'post_type' => 'fusion_element' ) );

		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_an_own_type_that_is_not_registered_is_refused(): void {
		$this->acting_as( 'administrator' );
		$this->assertNull( get_post_type_object( 'fusion_form' ) );
		$id = self::factory()->post->create( array( 'post_type' => 'fusion_form' ) );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_an_own_type_without_map_meta_cap_is_refused(): void {
		$this->register_avada_type( 'awb_off_canvas', false );
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create( array( 'post_type' => 'awb_off_canvas' ) );

		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
	}

	public function test_permission_refuses_a_revision_id_and_an_attachment_id(): void {
		$this->register_avada_type( 'fusion_tb_section' );
		$this->acting_as( 'administrator' );
		$page    = self::factory()->post->create( array( 'post_content' => 'v1' ) );
		$section = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_tb_section',
				'post_content' => 'v1',
			)
		);
		foreach ( array( $page, $section ) as $id ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => 'v2',
				)
			);
		}
		$page_rev    = (int) array_key_first( wp_get_post_revisions( $page ) );
		$section_rev = (int) array_key_first( wp_get_post_revisions( $section ) );
		$attachment  = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertGreaterThan( 0, $page_rev );
		$this->assertGreaterThan( 0, $section_rev );

		foreach ( array( $page_rev, $section_rev, $attachment ) as $id ) {
			$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ), (string) $id );
		}
	}

	public function test_get_page_content_reports_a_layout_section_as_avada_owned(): void {
		$this->register_avada_type( 'fusion_tb_section' );
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_tb_section',
				'post_content' => '[fusion_builder_container][/fusion_builder_container]',
			)
		);
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id, true ) );

		$this->assertTrue( aafm_exec_avada_get_page_content( array( 'post_id' => $id ) )['is_avada_owned'] );
	}

	// S8b: the decoded outline, page options and affects (design AV-9, AV-2).

	/**
	 * The page fixture, with the Avada elements it holds registered the way Avada registers them,
	 * so the walker and the outline see them (PHPUnit loads no Avada code).
	 *
	 * @return string
	 */
	private function s8b_page(): string {
		foreach ( array( 'fusion_title', 'fusion_button', 'fusion_imageframe', 'fusion_code', 'fusion_tabs', 'fusion_tab', 'fusion_global' ) as $tag ) {
			add_shortcode( $tag, '__return_empty_string' );
		}
		return (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/page-elements.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	/**
	 * The page fixture's stored _fusion meta, as Avada saved it.
	 *
	 * @return array<string,mixed>
	 */
	private function s8b_page_options(): array {
		return (array) json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/page-options.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	/**
	 * Force the theme seam entry present (a closure standing in for Avada::get_option_name()) or absent.
	 *
	 * @param bool $present Whether Avada's theme counts as active.
	 */
	private function force_avada_theme( bool $present ): void {
		remove_all_filters( 'aafm_builder_vendor' );
		add_filter(
			'aafm_builder_vendor',
			static function ( $resolved, string $entry ) use ( $present ) {
				if ( 'avada_option_name' !== $entry ) {
					return $resolved;
				}
				return $present ? static fn(): string => 'fusion_options' : null;
			},
			10,
			2
		);
	}

	/**
	 * A post holding the fixture's page options and page CSS.
	 *
	 * @param array<string,mixed> $args Post args.
	 * @return int
	 */
	private function post_with_page_options( array $args ): int {
		$id = self::factory()->post->create( $args );
		update_post_meta( $id, '_fusion', $this->s8b_page_options() );
		update_post_meta( $id, '_fusion_builder_custom_css', '.s8b-page { color: #123456; }' );
		return $id;
	}

	/**
	 * Read with include_page_options as an administrator.
	 *
	 * @param int $id Post id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read_page_options( int $id ) {
		$this->acting_as( 'administrator' );
		return aafm_exec_avada_get_page_content(
			array(
				'post_id'              => $id,
				'include_page_options' => true,
			)
		);
	}

	public function test_outline_items_carry_a_fingerprint_that_resolves(): void {
		$content = $this->s8b_page();
		$out     = $this->read_page( $this->make_avada_post( $content ), true );

		$this->assertSame( 'ok', $out['structure_status'] );
		$this->assertCount( 13, $out['structure'] );
		foreach ( $out['structure'] as $item ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $item['fingerprint'] );
			$element = aafm_builder_resolve(
				$content,
				aafm_fusion_shortcode_tags(),
				$item['index'],
				array(
					'tag'         => $item['tag'],
					'fingerprint' => $item['fingerprint'],
				)
			);
			$this->assertIsArray( $element, (string) $item['index'] );
			$this->assertSame( $item['index'], $element['index'] );
		}
	}

	public function test_the_read_returns_the_page_fingerprint_with_the_outline(): void {
		$content = $this->s8b_page();
		$id      = $this->make_avada_post( $content );

		$this->assertSame( aafm_builder_page_fingerprint( $content ), $this->read_page( $id, true )['page_fingerprint'] );
		foreach ( array( null, false ) as $flag ) {
			$this->assertArrayNotHasKey( 'page_fingerprint', $this->read_page( $id, $flag ) );
		}
	}

	public function test_decoded_text_reverses_the_six_entities(): void {
		$out    = $this->read_page( $this->make_avada_post( $this->s8b_page() ), true );
		$button = $out['structure'][5];

		$this->assertSame( 'fusion_button', $button['tag'] );
		$this->assertSame( 'Docs &amp; &quot;guides&quot; &lt;start&gt; it&#x27;s &#x60;now&#x60;', ( (array) $button['atts'] )['title'], 'atts stays as stored.' );
		$decoded = (array) $button['atts_decoded'];
		$this->assertSame( 'Docs & "guides" <start> it\'s `now`', $decoded['title'] );
		$this->assertSame( 'data-track=\'cta\' data-note="a & b"', $decoded['link_attributes'] );
		$this->assertSame( 'https://example.com/docs', $decoded['link'] );
		$this->assertSame( '[]', $decoded['html_attributes'], 'A repeater value is base64 JSON (A3).' );
		$once = aafm_avada_decode_atts(
			'fusion_title',
			array(
				'title' => 'a &amp;lt; b',
				'id'    => '&amp;amp;',
			)
		);
		$this->assertSame(
			array(
				'title' => 'a &lt; b',
				'id'    => '&amp;',
			),
			$once,
			'One pass: an escaped entity stays an entity.'
		);
	}

	public function test_decoded_code_block_is_decoded_only_when_it_round_trips(): void {
		$out  = $this->read_page( $this->make_avada_post( $this->s8b_page() ), true );
		$code = $out['structure'][8];
		$this->assertSame( 'fusion_code', $code['tag'] );
		$this->assertSame( '<div class="s8b-code">Code block output</div>', $code['text'] );

		$plain = $this->read_page( $this->make_avada_post( '[fusion_code]<p>Not base64, kept as stored.</p>[/fusion_code]' ), true );
		$this->assertSame( '<p>Not base64, kept as stored.</p>', $plain['structure'][0]['text'] );

		$this->assertSame(
			array(
				'title'           => 'dGVzdA==',
				'dynamic_params'  => '{"a":1}',
				'html_attributes' => 'W10',
				'pattern'         => 'a&b',
			),
			aafm_avada_decode_atts(
				'fusion_form_email',
				array(
					'title'           => 'dGVzdA==', // Base64 shaped, but Avada never decodes this setting: stays as stored.
					'dynamic_params'  => base64_encode( '{"a":1}' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds Avada's stored form.
					'html_attributes' => 'W10', // Does not round trip (no padding): stays as stored.
					'pattern'         => base64_encode( 'a&amp;b' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a raw_text field is escaped, then encoded.
				)
			)
		);
	}

	public function test_text_is_present_only_on_a_leaf(): void {
		$out  = $this->read_page( $this->make_avada_post( $this->s8b_page() ), true );
		$tags = array_column( $out['structure'], 'tag' );

		foreach ( $out['structure'] as $item ) {
			$parent = in_array( $item['tag'], array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_tabs' ), true );
			$this->assertSame( ! $parent, array_key_exists( 'text', $item ), $item['tag'] );
		}
		$this->assertSame( 'First tab body.', $out['structure'][ array_search( 'fusion_tab', $tags, true ) ]['text'] );
		$this->assertSame( 'S8b page title', $out['structure'][3]['text'] );
		$this->assertSame( '', $out['structure'][12]['text'], 'A global element with no content.' );
	}

	public function test_page_option_keys_is_pinned(): void {
		$this->assertSame(
			array(
				'after_inactivity'                    => 'radio-buttonset',
				'after_x_page_views'                  => 'radio-buttonset',
				'after_x_sessions'                    => 'radio-buttonset',
				'align_content'                       => 'radio-buttonset',
				'aspect_ratio'                        => 'text',
				'author_info'                         => 'radio-buttonset',
				'auto_close_after_time'               => 'text',
				'autoplay_video'                      => 'radio-buttonset',
				'avada_rev_styles'                    => 'radio-buttonset',
				'awb_header_bg_color'                 => 'color-alpha',
				'background_blend_mode'               => 'select',
				'background_color'                    => 'color-alpha',
				'background_custom_size'              => 'dimensions',
				'background_image'                    => 'upload',
				'background_position'                 => 'select',
				'background_repeat'                   => 'select',
				'background_size'                     => 'select',
				'bbpress_sidebar_position'            => 'radio-buttonset',
				'between_steps_size'                  => 'slider',
				'bg_color'                            => 'color-alpha',
				'bg_full'                             => 'radio-buttonset',
				'bg_image'                            => 'media',
				'bg_repeat'                           => 'select',
				'blog_comments'                       => 'radio-buttonset',
				'blog_page_title_bar'                 => 'select',
				'blog_pn_nav'                         => 'radio-buttonset',
				'blog_show_page_title_bar'            => 'select',
				'blog_sidebar_position'               => 'radio-buttonset',
				'blog_width_100'                      => 'radio-buttonset',
				'border_color'                        => 'color-alpha',
				'border_radius'                       => 'dimensions',
				'border_width'                        => 'dimensions',
				'box_shadow'                          => 'radio-buttonset',
				'box_shadow_blur'                     => 'slider',
				'box_shadow_color'                    => 'color-alpha',
				'box_shadow_position'                 => 'dimensions',
				'box_shadow_spread'                   => 'slider',
				'button_1'                            => 'textarea',
				'button_2'                            => 'textarea',
				'caption'                             => 'textarea',
				'caption_1'                           => 'text',
				'caption_2'                           => 'text',
				'caption_bg'                          => 'radio-buttonset',
				'caption_bg_color'                    => 'color',
				'caption_color'                       => 'color',
				'caption_font_size'                   => 'text',
				'caption_separator'                   => 'select',
				'caption_size'                        => 'radio-buttonset',
				'close_button'                        => 'radio-buttonset',
				'close_button_color'                  => 'color-alpha',
				'close_button_color_hover'            => 'color-alpha',
				'close_button_custom_icon'            => 'iconpicker',
				'close_button_margin'                 => 'dimensions',
				'close_button_position'               => 'radio-buttonset',
				'close_icon_size'                     => 'slider',
				'close_on_anchor_click'               => 'radio-buttonset',
				'close_on_esc'                        => 'radio-buttonset',
				'conditions_enabled'                  => 'radio-buttonset',
				'container_hundred_percent_animation' => 'select',
				'container_hundred_percent_animation_speed' => 'slider',
				'container_hundred_percent_dots_navigation' => 'radio-buttonset',
				'content_alignment'                   => 'radio-buttonset',
				'content_bg_color'                    => 'color-alpha',
				'content_bg_full'                     => 'radio-buttonset',
				'content_bg_image'                    => 'media',
				'content_bg_repeat'                   => 'select',
				'content_layout'                      => 'radio-buttonset',
				'content_wrap'                        => 'radio-buttonset',
				'copy_url'                            => 'text',
				'copy_url_text'                       => 'text',
				'css_class'                           => 'text',
				'css_id'                              => 'text',
				'custom_height'                       => 'text',
				'default_sidebar_pos'                 => 'radio-buttonset',
				'device'                              => 'multiple_select',
				'display_header'                      => 'radio-buttonset',
				'displayed_menu'                      => 'select',
				'dynamic_content_preview_type'        => 'select',
				'ec_sidebar'                          => 'select',
				'ec_sidebar_2'                        => 'select',
				'ec_sidebar_bg_color'                 => 'color-alpha',
				'ec_sidebar_pos'                      => 'radio-buttonset',
				'elasticslider'                       => 'select',
				'enter_animation'                     => 'select',
				'enter_animation_direction'           => 'select',
				'enter_animation_speed'               => 'slider',
				'enter_animation_timing'              => 'select',
				'events_social_sharing_box'           => 'radio-buttonset',
				'exit_animation'                      => 'select',
				'exit_animation_direction'            => 'select',
				'exit_animation_speed'                => 'slider',
				'exit_animation_timing'               => 'select',
				'exit_intent'                         => 'radio-buttonset',
				'fallback'                            => 'media',
				'field_margin'                        => 'dimensions',
				'fimg'                                => 'dimensions',
				'footer_100_width'                    => 'radio-buttonset',
				'footer_copyright'                    => 'radio-buttonset',
				'footer_widgets'                      => 'radio-buttonset',
				'form_bg_color'                       => 'color-alpha',
				'form_border_color'                   => 'color-alpha',
				'form_border_radius'                  => 'slider',
				'form_border_width'                   => 'dimensions',
				'form_confirmation_type'              => 'multiple_select',
				'form_focus_border_color'             => 'color-alpha',
				'form_font_size'                      => 'text',
				'form_input_height'                   => 'text',
				'form_label_color'                    => 'color-alpha',
				'form_placeholder_color'              => 'color-alpha',
				'form_placeholder_focus_animation'    => 'radio-buttonset',
				'form_placeholder_focus_shrink'       => 'radio-buttonset',
				'form_text_color'                     => 'color-alpha',
				'frequency'                           => 'select',
				'frequency_xdays'                     => 'text',
				'frequency_xtimes'                    => 'text',
				'fusion_tb_section_width_100'         => 'radio-buttonset',
				'header_100_width'                    => 'radio-buttonset',
				'header_bg_color'                     => 'color-alpha',
				'header_bg_full'                      => 'radio-buttonset',
				'header_bg_image'                     => 'media',
				'header_bg_repeat'                    => 'select',
				'header_breakpoint'                   => 'radio-buttonset',
				'header_custom_breakpoint'            => 'slider',
				'heading'                             => 'textarea',
				'heading_bg'                          => 'radio-buttonset',
				'heading_bg_color'                    => 'color',
				'heading_color'                       => 'color',
				'heading_font_size'                   => 'text',
				'heading_separator'                   => 'select',
				'heading_size'                        => 'radio-buttonset',
				'height'                              => 'select',
				'hide_video_controls'                 => 'radio-buttonset',
				'horizontal_position'                 => 'radio-buttonset',
				'hundredp_padding'                    => 'text',
				'image_rollover_icons'                => 'select',
				'inactivity_duration'                 => 'text',
				'label_font_size'                     => 'text',
				'label_position'                      => 'radio-buttonset',
				'label_typo'                          => 'typography',
				'layout'                              => 'radio-buttonset',
				'layout_conditions'                   => 'layout_conditions',
				'link_icon_url'                       => 'text',
				'link_type'                           => 'radio-buttonset',
				'live_footer'                         => 'radio-buttonset',
				'live_header'                         => 'radio-buttonset',
				'live_ptb'                            => 'radio-buttonset',
				'loop_video'                          => 'radio-buttonset',
				'main_padding'                        => 'dimensions',
				'margin'                              => 'dimensions',
				'megamenu_wrapper_max_width'          => 'slider',
				'megamenu_wrapper_width'              => 'radio-buttonset',
				'meta_description'                    => 'textarea',
				'meta_og_image'                       => 'media',
				'mobile_header_bg_color'              => 'color-alpha',
				'mp4'                                 => 'upload',
				'mute_video'                          => 'radio-buttonset',
				'number_of_page_views'                => 'text',
				'number_of_sessions'                  => 'text',
				'number_of_times'                     => 'text',
				'oc_scrollbar'                        => 'radio-buttonset',
				'oc_scrollbar_background'             => 'color-alpha',
				'oc_scrollbar_handle_color'           => 'color-alpha',
				'off_canvas_state'                    => 'radio-buttonset',
				'ogv'                                 => 'upload',
				'on_add_to_cart'                      => 'radio-buttonset',
				'on_click'                            => 'radio-buttonset',
				'on_click_element'                    => 'text',
				'on_page_load'                        => 'radio-buttonset',
				'on_scroll'                           => 'radio-buttonset',
				'overlay'                             => 'radio-buttonset',
				'overlay_background_blend_mode'       => 'select',
				'overlay_background_color'            => 'color-alpha',
				'overlay_background_custom_size'      => 'dimensions',
				'overlay_background_image'            => 'upload',
				'overlay_background_position'         => 'select',
				'overlay_background_repeat'           => 'select',
				'overlay_background_size'             => 'select',
				'overlay_close_on_click'              => 'radio-buttonset',
				'overlay_page_scrollbar'              => 'radio-buttonset',
				'overlay_z_index'                     => 'text',
				'padding'                             => 'dimensions',
				'page_title_100_width'                => 'radio-buttonset',
				'page_title_alignment'                => 'radio-buttonset',
				'page_title_bar'                      => 'select',
				'page_title_bar_bs'                   => 'radio-buttonset',
				'page_title_bar_text'                 => 'radio-buttonset',
				'page_title_bg'                       => 'media',
				'page_title_bg_color'                 => 'color-alpha',
				'page_title_bg_full'                  => 'radio-buttonset',
				'page_title_bg_parallax'              => 'radio-buttonset',
				'page_title_bg_retina'                => 'media',
				'page_title_border_color'             => 'color-alpha',
				'page_title_color'                    => 'color-alpha',
				'page_title_custom_subheader'         => 'textarea',
				'page_title_custom_text'              => 'textarea',
				'page_title_font_size'                => 'text',
				'page_title_height'                   => 'text',
				'page_title_line_height'              => 'text',
				'page_title_mobile_height'            => 'text',
				'page_title_subheader_color'          => 'color-alpha',
				'page_title_subheader_font_size'      => 'text',
				'pages_sidebar'                       => 'select',
				'pages_sidebar_2'                     => 'select',
				'portfolio_author'                    => 'radio-buttonset',
				'portfolio_comments'                  => 'radio-buttonset',
				'portfolio_featured_image_width'      => 'radio-buttonset',
				'portfolio_link_icon_target'          => 'radio-buttonset',
				'portfolio_pn_nav'                    => 'radio-buttonset',
				'portfolio_project_desc_title'        => 'radio-buttonset',
				'portfolio_project_details'           => 'radio-buttonset',
				'portfolio_related_posts'             => 'radio-buttonset',
				'portfolio_sidebar'                   => 'select',
				'portfolio_sidebar_2'                 => 'select',
				'portfolio_sidebar_position'          => 'radio-buttonset',
				'portfolio_social_sharing_box'        => 'radio-buttonset',
				'portfolio_width_100'                 => 'radio-buttonset',
				'position'                            => 'radio-buttonset',
				'post_links_target'                   => 'radio-buttonset',
				'post_meta'                           => 'radio-buttonset',
				'posts_sidebar'                       => 'select',
				'posts_sidebar_2'                     => 'select',
				'ppbress_sidebar'                     => 'select',
				'ppbress_sidebar_2'                   => 'select',
				'preview_archives'                    => 'select',
				'preview_background_color'            => 'color-alpha',
				'preview_image'                       => 'upload',
				'preview_term'                        => 'select',
				'preview_width'                       => 'slider',
				'product_width_100'                   => 'radio-buttonset',
				'project_url'                         => 'text',
				'project_url_text'                    => 'text',
				'publish_date_after'                  => 'date_time_picker',
				'publish_date_until'                  => 'date_time_picker',
				'redirect_timeout'                    => 'slider',
				'redirect_url'                        => 'link',
				'related_posts'                       => 'radio-buttonset',
				'required_field_symbol_decoration'    => 'radio-buttonset',
				'responsive_sidebar_order'            => 'sortable',
				'revslider'                           => 'select',
				'sb_enter_animation'                  => 'select',
				'sb_enter_animation_speed'            => 'slider',
				'sb_enter_animation_timing'           => 'select',
				'sb_exit_animation'                   => 'select',
				'sb_exit_animation_speed'             => 'slider',
				'sb_exit_animation_timing'            => 'select',
				'sb_height'                           => 'text',
				'scroll_direction'                    => 'radio-buttonset',
				'scroll_element'                      => 'text',
				'scroll_position'                     => 'text',
				'scroll_to'                           => 'radio-buttonset',
				'seo_follow'                          => 'radio-buttonset',
				'seo_index'                           => 'radio-buttonset',
				'seo_title'                           => 'text',
				'show_close_button_after_time'        => 'text',
				'show_first_featured_image'           => 'radio-buttonset',
				'show_wc_shop_loop'                   => 'radio-buttonset',
				'side_header_width'                   => 'slider',
				'sidebar_bg_color'                    => 'color-alpha',
				'sidebar_sticky'                      => 'select',
				'slide_link'                          => 'text',
				'slide_target'                        => 'radio-buttonset',
				'slider'                              => 'select',
				'slider_position'                     => 'radio-buttonset',
				'slider_type'                         => 'select',
				'slider_visibility'                   => 'checkbox-buttonset',
				'social_sharing_box'                  => 'radio-buttonset',
				'special_effect'                      => 'radio-buttonset',
				'step_icon_bg'                        => 'radio-buttonset',
				'step_icon_bg_color'                  => 'color-alpha',
				'step_icon_bor_color'                 => 'color-alpha',
				'step_icon_bor_type'                  => 'select',
				'step_icon_bor_width'                 => 'slider',
				'step_icon_border_radius'             => 'dimensions',
				'step_icon_color'                     => 'color-alpha',
				'step_icon_padding'                   => 'slider',
				'step_icon_size'                      => 'slider',
				'step_icon_title_gap'                 => 'text',
				'step_pb_alignment'                   => 'radio-buttonset',
				'step_pb_animated_stripes'            => 'radio-buttonset',
				'step_pb_border_radius'               => 'dimensions',
				'step_pb_dimension'                   => 'text',
				'step_pb_filled_border_color'         => 'color-alpha',
				'step_pb_filled_border_size'          => 'slider',
				'step_pb_filled_color'                => 'color-alpha',
				'step_pb_percentage'                  => 'radio-buttonset',
				'step_pb_striped'                     => 'radio-buttonset',
				'step_pb_typo'                        => 'typography',
				'step_pb_typo_color'                  => 'color-alpha',
				'step_pb_unfilled_color'              => 'color-alpha',
				'step_sep_margin'                     => 'dimensions',
				'step_type'                           => 'radio-buttonset',
				'step_typo'                           => 'typography',
				'steps_bg_color'                      => 'color-alpha',
				'steps_bor_color'                     => 'color-alpha',
				'steps_bor_type'                      => 'select',
				'steps_bor_width'                     => 'slider',
				'steps_border_radius'                 => 'dimensions',
				'steps_nav'                           => 'select',
				'steps_number_icon'                   => 'radio-buttonset',
				'steps_padding'                       => 'dimensions',
				'steps_sep_color'                     => 'color-alpha',
				'steps_sep_type'                      => 'select',
				'steps_sep_width'                     => 'slider',
				'steps_spacing'                       => 'select',
				'steps_title'                         => 'radio-buttonset',
				'steps_title_color'                   => 'color-alpha',
				'steps_title_position'                => 'select',
				'steps_wrapper_margin'                => 'dimensions',
				'template_sidebar'                    => 'select',
				'template_sidebar_2'                  => 'select',
				'template_sidebar_position'           => 'radio-buttonset',
				'time_on_page'                        => 'radio-buttonset',
				'time_on_page_duration'               => 'text',
				'tooltip_background_color'            => 'color-alpha',
				'tooltip_text_color'                  => 'color-alpha',
				'transition'                          => 'radio-buttonset',
				'type'                                => 'radio-buttonset',
				'until_consent_interaction'           => 'radio-buttonset',
				'users'                               => 'radio-buttonset',
				'users_roles'                         => 'multiple_select',
				'valign_content'                      => 'radio-buttonset',
				'vertical_position'                   => 'radio-buttonset',
				'video'                               => 'textarea',
				'video_bg_color'                      => 'color',
				'video_display'                       => 'select',
				'video_url'                           => 'text',
				'vimeo_id'                            => 'text',
				'webm'                                => 'upload',
				'when_arriving_from'                  => 'multiple_select',
				'width'                               => 'text',
				'woo_sidebar'                         => 'select',
				'woo_sidebar_2'                       => 'select',
				'woo_sidebar_position'                => 'radio-buttonset',
				'wooslider'                           => 'select',
				'youtube_id'                          => 'text',
			),
			aafm_avada_page_option_keys()
		);
	}

	public function test_no_excluded_tab_id_is_in_the_allowlist(): void {
		// Every id defined in tab_form_notifications, tab_form_submission, tab_form_privacy, tab_code_fields,
		// tab_studio, tab_form_general, tab_avada_page_options and tab_studio_tools (Avada 7.16.1,
		// scratchpad/1113/s8b-page-option-ids.md).
		$excluded = array(
			'action',
			'code_fields',
			'color1_overwrite',
			'color2_overwrite',
			'color3_overwrite',
			'color4_overwrite',
			'color5_overwrite',
			'color6_overwrite',
			'color7_overwrite',
			'color8_overwrite',
			'conditional_logic',
			'custom_attachment',
			'custom_headers',
			'email',
			'email_attachments',
			'email_bcc',
			'email_cc',
			'email_from',
			'email_from_id',
			'email_label_wordwrap',
			'email_message',
			'email_placeholders',
			'email_reply_to',
			'email_subject',
			'email_subject_encode',
			'entries_notice',
			'exclude_form_studio',
			'form_actions',
			'form_general',
			'form_notifications',
			'form_privacy',
			'form_submission',
			'form_type',
			'form_url_options',
			'form_views',
			'form_views_counting',
			'h1_size',
			'h2_size',
			'h3_size',
			'h4_size',
			'h5_size',
			'h6_size',
			'header_key',
			'header_value',
			'label',
			'member_only_form',
			'method',
			'nonce_method',
			'notification_status',
			'notifications',
			'notifications_enabled',
			'notifications_hidden',
			'off_canvas',
			'off_canvases',
			'placeholder',
			'post_method_note',
			'post_method_url',
			'preview_off_canvas',
			'privacy_expiration_action',
			'privacy_expiration_interval',
			'privacy_store_ip_ua',
			'setup_content',
			'space_body_close',
			'space_body_open',
			'space_head_close',
			'studio',
			'studio_tools',
			'submission_url_target',
			'tracking_code',
			'typography1_overwrite',
			'typography2_overwrite',
			'typography3_overwrite',
			'typography4_overwrite',
			'typography5_overwrite',
			'url_method',
			'user_roles',

		);
		$this->assertSame( array(), array_values( array_intersect( $excluded, array_keys( aafm_avada_page_option_keys() ) ) ) );
		foreach ( aafm_avada_page_option_keys() as $id => $type ) {
			$this->assertNotContains( $type, array( 'code', 'raw_textarea', 'raw_text', 'custom', 'button', 'hidden' ), $id );
		}
	}

	public function test_no_allowlisted_id_names_a_recipient_webhook_key_or_privacy_setting(): void {
		$allowed = array(); // No allowlisted id matches the pattern; an exception would be listed here with its reason.
		$named   = preg_grep( '/mail|recipient|notif|webhook|hook_url|api|secret|token|key|privacy|submission|entries|member_only|user_roles|nonce/', array_keys( aafm_avada_page_option_keys() ) );
		$this->assertSame( array(), array_values( array_diff( (array) $named, array_keys( $allowed ) ) ) );
	}

	public function test_the_ruled_form_seo_and_template_ids_are_in(): void {
		$keys = aafm_avada_page_option_keys();
		foreach ( array( 'form_confirmation_type', 'redirect_url', 'seo_title', 'meta_description', 'live_header' ) as $id ) {
			$this->assertArrayHasKey( $id, $keys );
		}
		$this->assertSame( 'link', $keys['redirect_url'] );
		foreach ( array( 'messages', 'member_only_form', 'user_roles', 'nonce_method' ) as $id ) {
			$this->assertArrayNotHasKey( $id, $keys );
		}
	}

	public function test_page_options_are_filtered_to_the_allowlist(): void {
		$this->force_avada_theme( true );
		$id   = $this->make_avada_post( '[fusion_builder_container][/fusion_builder_container]' );
		$meta = $this->s8b_page_options();
		update_post_meta( $id, '_fusion', array_merge( $meta, array( 'form_notification_email' => 'owner@example.invalid' ) ) );
		update_post_meta( $id, '_fusion_builder_custom_css', '.s8b-page { color: #123456; }' );

		$out = $this->read_page_options( $id );

		$this->assertIsArray( $out );
		$this->assertSame( $meta, (array) $out['page_options'] );
		$this->assertSame( 'no', ( (array) $out['page_options'] )['page_title_bar'] );
		$this->assertSame( 'Footer Widget Area 1', ( (array) $out['page_options'] )['pages_sidebar'] );
		$this->assertStringNotContainsString( 'owner@example.invalid', (string) wp_json_encode( $out ) );
		$this->assertSame( '.s8b-page { color: #123456; }', $out['custom_css'] );
	}

	public function test_page_options_keys_are_absent_without_the_flag(): void {
		$this->force_avada_theme( true );
		$id = $this->post_with_page_options( array( 'post_type' => 'page' ) );
		$this->acting_as( 'administrator' );
		foreach ( array( array(), array( 'include_page_options' => false ) ) as $flag ) {
			$out = aafm_exec_avada_get_page_content( array_merge( array( 'post_id' => $id ), $flag ) );
			$this->assertArrayNotHasKey( 'page_options', $out );
			$this->assertArrayNotHasKey( 'custom_css', $out );
		}
	}

	public function test_page_options_need_the_avada_theme(): void {
		$this->force_avada_theme( false );
		$out = $this->read_page_options( $this->post_with_page_options( array( 'post_type' => 'page' ) ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_avada_theme_inactive', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertStringNotContainsString( ' - ', $out->get_error_message() );
		$this->assertFalse( aafm_avada_theme_active() );
	}

	public function test_page_options_work_on_a_classic_page(): void {
		$this->force_avada_theme( true );
		$id = $this->post_with_page_options(
			array(
				'post_type'    => 'page',
				'post_content' => 'A classic page with no builder.',
			)
		);
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id, true ) );

		$out = $this->read_page_options( $id );

		$this->assertIsArray( $out );
		$this->assertSame( $this->s8b_page_options(), (array) $out['page_options'] );
		$this->assertFalse( $out['is_avada_owned'] );
		$this->assertTrue( aafm_avada_theme_active() );
	}

	public function test_page_options_are_refused_on_a_slide_until_the_type_is_allowed(): void {
		$this->force_avada_theme( true );
		register_post_type( 'slide', array( 'public' => true ) );
		$id = $this->post_with_page_options( array( 'post_type' => 'slide' ) );

		$out = $this->read_page_options( $id );
		unregister_post_type( 'slide' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_not_avada_owned', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertArrayHasKey( 'caption_1', aafm_avada_page_option_keys(), 'The slide ids stay in the list, unreachable (R3-PR2-13).' );
	}

	public function test_page_options_are_refused_on_an_elementor_page(): void {
		$this->force_avada_theme( true );
		$id = $this->post_with_page_options( array( 'post_type' => 'page' ) );
		update_post_meta( $id, '_elementor_data', '[]' );

		$out = $this->read_page_options( $id );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_not_avada_owned', $out->get_error_code() );
		$post = get_post( $id );
		$this->assertFalse( aafm_avada_page_options_post_allowed( $post, 'elementor' ) );
		$this->assertFalse( aafm_avada_page_options_post_allowed( $post, 'unknown' ) );
		$this->assertTrue( aafm_avada_page_options_post_allowed( $post, 'avada' ) );
		$this->assertTrue( aafm_avada_page_options_post_allowed( $post, false ) );
	}

	public function test_page_options_read_never_calls_avadas_getter(): void {
		$this->force_avada_theme( true );
		$id     = $this->post_with_page_options( array( 'post_type' => 'page' ) );
		$before = get_post_meta( $id, '_fusion', false );
		$reads  = 0;
		$writes = 0;
		$count  = static function ( $value, $object_id, $key ) use ( $id, &$reads ) {
			if ( (int) $object_id === $id && '_fusion' === $key ) {
				++$reads;
			}
			return $value;
		};
		$block  = static function ( $check, $object_id, $key ) use ( $id, &$writes ) {
			if ( (int) $object_id === $id && '_fusion' === $key ) {
				++$writes;
			}
			return $check;
		};
		add_filter( 'get_post_metadata', $count, 10, 3 );
		add_filter( 'update_post_metadata', $block, 10, 3 );

		$out = $this->read_page_options( $id );
		remove_filter( 'get_post_metadata', $count, 10 );
		remove_filter( 'update_post_metadata', $block, 10 );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $reads, 'One read through core metadata.' );
		$this->assertSame( 0, $writes );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post_meta( $id, '_fusion', false ) );
	}

	public function test_page_options_fail_when_the_meta_read_fails(): void {
		$this->force_avada_theme( true );
		$id = $this->post_with_page_options( array( 'post_type' => 'page' ) );
		$this->acting_as( 'administrator' );
		aafm_exact_object( 'post', $id );
		wp_cache_delete( $id, 'post_meta' );
		QueryFaultInjector::reset_fired_count();

		$out = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT post_id, meta_key, meta_value FROM', 'postmeta' ),
			static function () use ( $id ) {
				return aafm_exec_avada_get_page_content(
					array(
						'post_id'              => $id,
						'include_page_options' => true,
					)
				);
			},
			1
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertInstanceOf( WP_Error::class, $out, 'Never an empty value standing in for a failed read.' );
	}

	public function test_affects_is_present_for_a_section_and_a_global_item_only(): void {
		$this->register_avada_type( 'fusion_tb_section' );
		$this->register_avada_type( 'fusion_element' );
		$this->acting_as( 'administrator' );
		$body    = '[fusion_builder_container][fusion_text]Shared words.[/fusion_text][/fusion_builder_container]';
		$section = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_tb_section',
				'post_content' => $body,
			)
		);
		$global  = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_element',
				'post_content' => $body,
			)
		);
		update_post_meta( $global, '_fusion_is_global', 'yes' );
		$local = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_element',
				'post_content' => $body,
			)
		);
		$page  = $this->make_avada_post( $body );

		$expect = array(
			$section => 'every page that uses this section',
			$global  => 'every page that shows this global element',
			$local   => null,
			$page    => null,
		);
		foreach ( $expect as $id => $affects ) {
			$read    = aafm_exec_avada_get_page_content( array( 'post_id' => $id ) );
			$replace = $this->av1_replace( $id, 'Shared words.', 'Shared words, edited.' );
			$this->assertIsArray( $replace, (string) $id );
			$this->assertSame( 1, $replace['replacements'] );
			if ( null === $affects ) {
				$this->assertArrayNotHasKey( 'affects', $read, (string) $id );
				$this->assertArrayNotHasKey( 'affects', $replace, (string) $id );
			} else {
				$this->assertSame( $affects, $read['affects'], (string) $id );
				$this->assertSame( $affects, $replace['affects'], (string) $id );
				$this->assertSame( $affects, aafm_avada_affects( get_post( $id ) ) );
			}
		}
	}

	public function test_replace_output_schema_declares_affects(): void {
		$this->assertSame( 'string', aafm_args_avada_replace_text()['output_schema']['properties']['affects']['type'] );
	}
}
