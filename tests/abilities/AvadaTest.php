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
		remove_shortcode( 'fusion_global' );
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

	/* ---------------------------------------------------------------- S7-3: AV-1 and the extended permission */

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
		$content = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/page-s7.txt' );
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
}
