<?php
/**
 * Block markup fidelity: what an agent writes inside block attributes is what gets stored.
 *
 * Covers the shared sanitizer aafm_kses_block_markup(). For a user who may post unfiltered HTML,
 * plain attribute text (no HTML tag in it) is stored exactly as sent, the way the block editor
 * stores it for that user. Attribute values that can open a tag, innerHTML, and every write by a
 * user without unfiltered_html keep exactly the wp_kses_post() result. <style> and <script>
 * elements are removed with their contents, and invalid delimiter JSON is reported.
 *
 * Assertions read parse_blocks() attrs, never the raw stored bytes: on the restore path
 * serialize_block_attributes() JSON-escapes &, <, > and -- as unicode escapes.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BlockMarkupFidelityTest extends TestCase {

	private function admin(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'Fixture: an administrator posts unfiltered HTML.' );
	}

	private function author(): void {
		$this->acting_as( 'author' );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'Fixture: an author does not post unfiltered HTML.' );
	}

	public function test_a_value_that_can_open_a_tag_is_still_cleaned(): void {
		$this->admin();
		$raw    = '<!-- wp:rosh/x {"plain":"A & B","img":"<img src=x onerror=alert(1)>hi","comment":"<!-- x -->y","closer":"</p>z"} /-->';
		$result = aafm_kses_block_markup( $raw );
		$attrs  = parse_blocks( $result['content'] )[0]['attrs'] ?? array();
		$kses   = parse_blocks( wp_kses_post( serialize_blocks( parse_blocks( $raw ) ) ) )[0]['attrs'];

		$this->assertSame( 'A & B', $attrs['plain'] ?? null, 'A plain value is restored.' );
		foreach ( array( 'img', 'comment', 'closer' ) as $key ) {
			$this->assertSame( $kses[ $key ], $attrs[ $key ] ?? null, "$key keeps the kses value." );
		}
		$this->assertStringNotContainsString( 'onerror', (string) $attrs['img'] );
	}

	public function test_a_restored_value_cannot_close_the_block_comment(): void {
		$this->admin();
		$raw    = '<!-- wp:rosh/x {"t":"a --> b"} /--><!-- wp:paragraph --><p>after</p><!-- /wp:paragraph -->';
		$result = aafm_kses_block_markup( $raw );
		$blocks = array_values(
			array_filter(
				parse_blocks( $result['content'] ),
				static fn( array $b ): bool => null !== $b['blockName']
			)
		);

		$this->assertCount( 2, $blocks, 'The restored value did not close the comment early.' );
		$this->assertSame( 'a --> b', $blocks[0]['attrs']['t'] ?? null );
		$this->assertSame( 'core/paragraph', $blocks[1]['blockName'] );
	}

	public function test_a_user_without_unfiltered_html_gets_exactly_wp_kses_post(): void {
		$this->author();
		$raw    = '<!-- wp:rosh/x {"u":"java&#9script:","t":"a & b","b":"a > b < c"} /--><!-- wp:paragraph --><p>A & B</p><!-- /wp:paragraph -->';
		$result = aafm_kses_block_markup( $raw );

		$this->assertSame( wp_kses_post( $raw ), $result['content'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Markup the sanitizer finds nothing to restore in comes back byte for byte as wp_kses_post().
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_nothing_to_restore(): array {
		return array(
			'core blocks with inline styles' => array( 'administrator', '<!-- wp:group {"style":{"color":{"background":"#000000"}}} --><div class="wp-block-group has-background" style="background-color:#000000"><!-- wp:paragraph {"style":{"color":{"text":"#ffffff"}}} --><p class="has-text-color" style="color:#ffffff">Hi</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ),
			'spaced delimiter JSON'          => array( 'administrator', '<!-- wp:heading { "level" : 3 } --><h3 class="wp-block-heading">Spaced</h3><!-- /wp:heading -->' ),
			'tab-indented delimiter JSON'    => array( 'administrator', "<!-- wp:heading {\n\t\"level\": 3\n} --><h3 class=\"wp-block-heading\">Tabbed</h3><!-- /wp:heading -->" ),
			'two spaces after the opener'    => array( 'administrator', '<!--  wp:paragraph --><p>Two spaces</p><!-- /wp:paragraph -->' ),
			'freeform HTML between blocks'   => array( 'administrator', "<p>Free & loose</p>\n<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->\n<div>tail</div>" ),
			'kses changes innerHTML only'    => array( 'administrator', '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center" onclick="x()">A & B</p><!-- /wp:paragraph -->' ),
			'empty string'                   => array( 'administrator', '' ),
			'no blocks'                      => array( 'administrator', '<p>Just <strong>HTML</strong> & text</p>' ),
			'limited user with & in attrs'   => array( 'author', '<!-- wp:rosh/x {"data":{"lead":"S & S"}} /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ),
		);
	}

	/**
	 * The as-sent markup comes back as wp_kses_post() of it, byte for byte.
	 *
	 * @dataProvider data_nothing_to_restore
	 *
	 * @param string $role Role to act as.
	 * @param string $raw  Markup.
	 */
	public function test_markup_with_nothing_to_restore_is_byte_identical_to_wp_kses_post( string $role, string $raw ): void {
		$this->acting_as( $role );
		$this->assertSame( wp_kses_post( $raw ), aafm_kses_block_markup( $raw )['content'] );
	}

	/**
	 * When kses changes the block tree's shape, nothing is restored and the kses output is returned
	 * as is, never a mix of the two trees. kses collapses "--" inside an HTML comment, so a block
	 * name with a double dash (valid for the block parser) comes back renamed.
	 */
	public function test_a_tree_mismatch_falls_back_to_kses_output(): void {
		$this->admin();
		$raw = '<!-- wp:rosh/x {"t":"S & S"} /--><!-- wp:acme--x/y --><p>x</p><!-- /wp:acme--x/y -->';

		$this->assertSame(
			array( 'rosh/x', 'acme-x/y' ),
			wp_list_pluck( parse_blocks( wp_kses_post( $raw ) ), 'blockName' ),
			'Fixture: kses must rename the second block.'
		);
		$this->assertSame( wp_kses_post( $raw ), aafm_kses_block_markup( $raw )['content'] );
	}

	public function test_inner_html_is_never_restored(): void {
		$this->admin();
		$raw    = '<!-- wp:paragraph {"t":"S & S"} --><p onclick="alert(1)">A &amp; B</p><!-- /wp:paragraph -->';
		$result = aafm_kses_block_markup( $raw );
		$block  = parse_blocks( $result['content'] )[0];
		$kses   = parse_blocks( wp_kses_post( $raw ) )[0];

		$this->assertSame( 'S & S', $block['attrs']['t'] ?? null, 'The attribute is restored.' );
		$this->assertSame( $kses['innerHTML'], $block['innerHTML'] );
		$this->assertSame( $kses['innerContent'], $block['innerContent'] );
		$this->assertStringNotContainsString( 'onclick', $result['content'] );
	}

	public function test_style_and_script_elements_are_removed_with_their_contents(): void {
		$this->admin();
		$result = aafm_kses_block_markup( '<!-- wp:html --><style>.a{color:red}</style><script>alert(1)</script><p>keep</p><!-- /wp:html -->' );

		$this->assertStringNotContainsString( 'color:red', $result['content'] );
		$this->assertStringNotContainsString( 'alert(1)', $result['content'] );
		$this->assertStringContainsString( '<p>keep</p>', $result['content'] );
		$this->assertSame( array( 'removed_element', 'removed_element' ), wp_list_pluck( $result['warnings'], 'code' ) );
		$this->assertSame( array( '', '' ), wp_list_pluck( $result['warnings'], 'block' ) );
	}

	public function test_a_style_closer_with_a_space_still_closes_the_element(): void {
		$this->admin();
		$result = aafm_kses_block_markup( '<!-- wp:html --><style>.a{color:red}</style ><p>keep</p><!-- /wp:html -->' );

		$this->assertStringNotContainsString( 'color:red', $result['content'] );
		$this->assertStringContainsString( '<p>keep</p>', $result['content'] );
		$this->assertSame( array( 'removed_element' ), wp_list_pluck( $result['warnings'], 'code' ) );
	}

	public function test_a_style_tag_inside_a_quoted_attribute_is_not_an_element(): void {
		$this->admin();
		$raw    = '<!-- wp:html --><p title="<style>x</style>">t</p><!-- /wp:html -->';
		$result = aafm_kses_block_markup( $raw );

		$this->assertSame( wp_kses_post( $raw ), $result['content'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_a_script_tag_inside_delimiter_json_is_left_to_the_attribute_rules(): void {
		$this->admin();
		$raw    = '<!-- wp:rosh/x {"c":"<script>alert(1)</script>ok"} /-->';
		$result = aafm_kses_block_markup( $raw );

		// Not an element, so nothing is removed; the value can open a tag, so nothing is restored
		// and the result is wp_kses_post() exactly.
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( wp_kses_post( $raw ), $result['content'] );
		$this->assertStringNotContainsString( '<script', $result['content'] );
	}

	public function test_an_unclosed_style_element_is_left_for_kses(): void {
		$this->admin();
		$raw    = '<p>keep</p><style>.a{color:red}';
		$result = aafm_kses_block_markup( $raw );

		$this->assertSame( wp_kses_post( $raw ), $result['content'] );
		$this->assertSame( array(), $result['warnings'] );
	}
}
