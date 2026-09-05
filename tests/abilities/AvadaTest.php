<?php
/**
 * Native Avada/Fusion Builder integration: avada-get-page-content, avada-replace-text.
 *
 * Fixture corpus per the design doc's own Codex-review requirement: a self-closing element, an
 * attribute value containing a literal ']', and nested columns using distinct "_inner" tag names.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

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

	/**
	 * Avada-replace-text must refuse a post that is not genuinely Avada-owned - including one
	 * owned by a DIFFERENT foreign builder, which carries no Fusion shortcodes at all and would
	 * otherwise pass the structural-signature check trivially (Codex round C finding 3).
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
	 * Codex final round 5 MEDIUM: counting each quote character independently (odd '"' OR odd
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
	 * tuple alone cannot tell the difference (Codex round C finding 2, bullet 2).
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
	 * fix for Codex round C finding 2, bullet 1.
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
}
