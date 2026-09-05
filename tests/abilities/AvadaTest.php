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
	 * Fixture per the Codex-review amendment: an attribute value containing a literal ']' - a
	 * known trap for a regex-based shortcode extractor. A plain-text edit OUTSIDE the affected
	 * shortcode must still be allowed; the guard's job is to not silently corrupt anything, not to
	 * perfectly parse a construct WordPress's own shortcode API cannot parse either.
	 */
	public function test_a_literal_bracket_in_an_attribute_value_does_not_block_an_unrelated_edit(): void {
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

		$this->assertIsArray( $out );
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
