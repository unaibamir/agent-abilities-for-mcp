<?php
/**
 * Exposed-meta-keys sanitizer coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class MetaKeysSaveTest extends TestCase {

	public function test_sanitize_parses_multiline_trims_dedupes_drops_blocked(): void {
		$out = oversio_sanitize_allowed_meta_keys_input(
			array( 'oversio_meta_keys' => "subtitle\n subtitle \n\n_edit_lock\nwp_capabilities\nfeatured_color" )
		);
		$this->assertSame( array( 'subtitle', 'featured_color' ), $out );
	}

	public function test_detected_keys_scopes_and_excludes_blocked(): void {
		delete_transient( 'oversio_detected_meta_keys' );
		$id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $id, 'subtitle', 'x' );
		update_post_meta( $id, '_edit_lock', '123' );
		$keys = oversio_detected_meta_keys();
		$this->assertContains( 'subtitle', $keys );
		$this->assertNotContains( '_edit_lock', $keys );
	}

	public function test_meta_selector_is_nested_form_safe(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'subtitle', 'x' );
		ob_start();
		oversio_render_abilities_tab();
		$html = ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertStringContainsString( 'id="oversio-meta-keys-form"', $html );
		$this->assertStringContainsString( 'name="oversio_meta_keys"', $html );
		$this->assertStringContainsString( 'id="oversio-meta-keys-save"', $html );
		$this->assertStringContainsString( 'oversio-meta-chip', $html );
		// Direction A presentation: the meta-keys selector lives in a card.
		$this->assertStringContainsString( 'oversio-card', $html );

		// The privacy warning now renders through the shared notice component (a <div> with an
		// inline SVG icon), not the old ad-hoc <p class="oversio-notice">. The <div> never opens a
		// form, so the one-form invariant above still holds.
		$this->assertStringContainsString( 'oversio-notice oversio-notice-warning', $html );
		$this->assertStringContainsString( 'oversio-notice-ic', $html );
		$this->assertStringContainsString( '<svg class="oversio-icon"', $html );
	}

	/**
	 * The Content meta selector renders BOTH the Exposed textarea (existing) and the new Deny
	 * textarea, with Exposed above Deny and the `*` hint on each. The detected-keys chips
	 * attach to Exposed only, and the selector never opens a nested form.
	 */
	public function test_content_selector_renders_exposed_and_deny_textareas(): void {
		ob_start();
		oversio_render_meta_keys_selector();
		$html = ob_get_clean();

		$exposed_pos = strpos( $html, 'name="oversio_meta_keys"' );
		$deny_pos    = strpos( $html, 'name="oversio_deny_meta_keys"' );

		$this->assertNotFalse( $exposed_pos, 'Exposed textarea must render.' );
		$this->assertNotFalse( $deny_pos, 'Deny textarea must render.' );
		$this->assertLessThan( $deny_pos, $exposed_pos, 'Exposed must render above Deny.' );

		// The `*` wildcard is documented on both controls.
		$this->assertGreaterThanOrEqual( 2, substr_count( $html, '*' ) );

		// The Save button keeps the primary style; the selector is not a nested form.
		$this->assertStringContainsString( 'oversio-btn oversio-btn-primary', $html );
		$this->assertSame( 0, substr_count( $html, '<form' ) );
	}

	/**
	 * The Users sub-tab renders its own exposed + denied user-meta textareas, mirroring the
	 * Content pair, and never opens a nested form.
	 */
	public function test_users_selector_renders_exposed_and_deny_textareas(): void {
		ob_start();
		oversio_render_user_meta_keys_selector();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="oversio_exposed_user_meta_keys"', $html );
		$this->assertStringContainsString( 'name="oversio_denied_user_meta_keys"', $html );
		$this->assertStringContainsString( 'oversio-btn', $html );
		$this->assertSame( 0, substr_count( $html, '<form' ) );
	}

	/**
	 * The Taxonomies sub-tab renders its own exposed + denied term-meta textareas, mirroring
	 * the Content pair, and never opens a nested form.
	 */
	public function test_taxonomies_selector_renders_exposed_and_deny_textareas(): void {
		ob_start();
		oversio_render_term_meta_keys_selector();
		$html = ob_get_clean();

		$exposed_pos = strpos( $html, 'name="oversio_exposed_term_meta_keys"' );
		$deny_pos    = strpos( $html, 'name="oversio_denied_term_meta_keys"' );

		$this->assertNotFalse( $exposed_pos, 'Exposed textarea must render.' );
		$this->assertNotFalse( $deny_pos, 'Deny textarea must render.' );
		$this->assertLessThan( $deny_pos, $exposed_pos, 'Exposed must render above Deny.' );
		$this->assertStringContainsString( 'oversio-btn oversio-btn-primary', $html );
		$this->assertSame( 0, substr_count( $html, '<form' ) );
	}

	/**
	 * Assert a textarea has non-empty aria-labelledby + aria-describedby, and that each
	 * referenced id is actually present as an id="" in the markup (so the accessible name and
	 * description resolve). WCAG 1.3.1 / 3.3.2 / 4.1.2.
	 *
	 * @param string $html      Rendered markup.
	 * @param string $name      The textarea name attribute to locate.
	 * @return void
	 */
	private function assert_textarea_is_labelled( string $html, string $name ): void {
		$this->assertMatchesRegularExpression(
			'/<textarea[^>]*\bname="' . preg_quote( $name, '/' ) . '"[^>]*>/',
			$html,
			"Textarea {$name} must render."
		);

		// Pull the single opening <textarea ... name="$name" ...> tag.
		$this->assertSame(
			1,
			preg_match( '/<textarea[^>]*\bname="' . preg_quote( $name, '/' ) . '"[^>]*>/', $html, $tag ),
			"Textarea {$name} tag must be matchable."
		);
		$open = $tag[0];

		$this->assertSame( 1, preg_match( '/\baria-labelledby="([^"]+)"/', $open, $lbl ), "Textarea {$name} needs aria-labelledby." );
		$this->assertSame( 1, preg_match( '/\baria-describedby="([^"]+)"/', $open, $desc ), "Textarea {$name} needs aria-describedby." );
		$this->assertNotSame( '', $lbl[1], "aria-labelledby on {$name} must be non-empty." );
		$this->assertNotSame( '', $desc[1], "aria-describedby on {$name} must be non-empty." );

		$this->assertStringContainsString( 'id="' . $lbl[1] . '"', $html, "Referenced label id {$lbl[1]} must exist." );
		$this->assertStringContainsString( 'id="' . $desc[1] . '"', $html, "Referenced hint id {$desc[1]} must exist." );
	}

	/**
	 * Both content meta-key textareas expose a programmatic label + description, resolving to
	 * ids that exist in the markup (assistive tech reads the visible h3 and hint).
	 */
	public function test_content_selector_textareas_are_labelled_for_assistive_tech(): void {
		ob_start();
		oversio_render_meta_keys_selector();
		$html = ob_get_clean();

		$this->assert_textarea_is_labelled( $html, 'oversio_meta_keys' );
		$this->assert_textarea_is_labelled( $html, 'oversio_deny_meta_keys' );
	}

	/**
	 * Both user meta-key textareas expose a programmatic label + description, resolving to ids
	 * that exist in the markup.
	 */
	public function test_users_selector_textareas_are_labelled_for_assistive_tech(): void {
		ob_start();
		oversio_render_user_meta_keys_selector();
		$html = ob_get_clean();

		$this->assert_textarea_is_labelled( $html, 'oversio_exposed_user_meta_keys' );
		$this->assert_textarea_is_labelled( $html, 'oversio_denied_user_meta_keys' );
	}

	/**
	 * Both term meta-key textareas expose a programmatic label + description, resolving to ids
	 * that exist in the markup.
	 */
	public function test_taxonomies_selector_textareas_are_labelled_for_assistive_tech(): void {
		ob_start();
		oversio_render_term_meta_keys_selector();
		$html = ob_get_clean();

		$this->assert_textarea_is_labelled( $html, 'oversio_exposed_term_meta_keys' );
		$this->assert_textarea_is_labelled( $html, 'oversio_denied_term_meta_keys' );
	}

	/**
	 * The full abilities tab wires the Users AND Taxonomies selectors into their panels, still
	 * inside the single outer form (no second form opened by any meta selector).
	 */
	public function test_abilities_tab_wires_users_selector_in_single_form(): void {
		ob_start();
		oversio_render_abilities_tab();
		$html = ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertStringContainsString( 'name="oversio_exposed_user_meta_keys"', $html );
		$this->assertStringContainsString( 'name="oversio_denied_user_meta_keys"', $html );
		$this->assertStringContainsString( 'name="oversio_deny_meta_keys"', $html );
		$this->assertStringContainsString( 'name="oversio_exposed_term_meta_keys"', $html );
		$this->assertStringContainsString( 'name="oversio_denied_term_meta_keys"', $html );
	}
}
