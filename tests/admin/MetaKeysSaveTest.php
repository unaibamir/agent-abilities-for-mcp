<?php
/**
 * Exposed-meta-keys sanitizer coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class MetaKeysSaveTest extends TestCase {

	public function test_sanitize_parses_multiline_trims_dedupes_drops_blocked(): void {
		$out = aafm_sanitize_allowed_meta_keys_input(
			array( 'aafm_meta_keys' => "subtitle\n subtitle \n\n_edit_lock\nwp_capabilities\nfeatured_color" )
		);
		$this->assertSame( array( 'subtitle', 'featured_color' ), $out );
	}

	public function test_detected_keys_scopes_and_excludes_blocked(): void {
		delete_transient( 'aafm_detected_meta_keys' );
		$id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $id, 'subtitle', 'x' );
		update_post_meta( $id, '_edit_lock', '123' );
		$keys = aafm_detected_meta_keys();
		$this->assertContains( 'subtitle', $keys );
		$this->assertNotContains( '_edit_lock', $keys );
	}

	public function test_meta_selector_is_nested_form_safe(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'subtitle', 'x' );
		ob_start();
		aafm_render_abilities_tab();
		$html = ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertStringContainsString( 'id="aafm-meta-keys-form"', $html );
		$this->assertStringContainsString( 'name="aafm_meta_keys"', $html );
		$this->assertStringContainsString( 'id="aafm-meta-keys-save"', $html );
		$this->assertStringContainsString( 'aafm-meta-chip', $html );
		// Direction A presentation: the meta-keys selector lives in a card.
		$this->assertStringContainsString( 'aafm-card', $html );

		// The privacy warning now renders through the shared notice component (a <div> with an
		// inline SVG icon), not the old ad-hoc <p class="aafm-notice">. The <div> never opens a
		// form, so the one-form invariant above still holds.
		$this->assertStringContainsString( 'aafm-notice aafm-notice-warning', $html );
		$this->assertStringContainsString( 'aafm-notice-ic', $html );
		$this->assertStringContainsString( '<svg class="aafm-icon"', $html );
	}

	/**
	 * The Content meta selector renders BOTH the Exposed textarea (existing) and the new Deny
	 * textarea, with Exposed above Deny and the `*` hint on each. The detected-keys chips
	 * attach to Exposed only, and the selector never opens a nested form.
	 */
	public function test_content_selector_renders_exposed_and_deny_textareas(): void {
		ob_start();
		aafm_render_meta_keys_selector();
		$html = ob_get_clean();

		$exposed_pos = strpos( $html, 'name="aafm_meta_keys"' );
		$deny_pos    = strpos( $html, 'name="aafm_deny_meta_keys"' );

		$this->assertNotFalse( $exposed_pos, 'Exposed textarea must render.' );
		$this->assertNotFalse( $deny_pos, 'Deny textarea must render.' );
		$this->assertLessThan( $deny_pos, $exposed_pos, 'Exposed must render above Deny.' );

		// The `*` wildcard is documented on both controls.
		$this->assertGreaterThanOrEqual( 2, substr_count( $html, '*' ) );

		// The Save button keeps the primary style; the selector is not a nested form.
		$this->assertStringContainsString( 'aafm-btn aafm-btn-primary', $html );
		$this->assertSame( 0, substr_count( $html, '<form' ) );
	}

	/**
	 * The Users sub-tab renders its own exposed + denied user-meta textareas, mirroring the
	 * Content pair, and never opens a nested form.
	 */
	public function test_users_selector_renders_exposed_and_deny_textareas(): void {
		ob_start();
		aafm_render_user_meta_keys_selector();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="aafm_exposed_user_meta_keys"', $html );
		$this->assertStringContainsString( 'name="aafm_denied_user_meta_keys"', $html );
		$this->assertStringContainsString( 'aafm-btn', $html );
		$this->assertSame( 0, substr_count( $html, '<form' ) );
	}

	/**
	 * The Taxonomies sub-tab renders its own exposed + denied term-meta textareas, mirroring
	 * the Content pair, and never opens a nested form.
	 */
	public function test_taxonomies_selector_renders_exposed_and_deny_textareas(): void {
		ob_start();
		aafm_render_term_meta_keys_selector();
		$html = ob_get_clean();

		$exposed_pos = strpos( $html, 'name="aafm_exposed_term_meta_keys"' );
		$deny_pos    = strpos( $html, 'name="aafm_denied_term_meta_keys"' );

		$this->assertNotFalse( $exposed_pos, 'Exposed textarea must render.' );
		$this->assertNotFalse( $deny_pos, 'Deny textarea must render.' );
		$this->assertLessThan( $deny_pos, $exposed_pos, 'Exposed must render above Deny.' );
		$this->assertStringContainsString( 'aafm-btn aafm-btn-primary', $html );
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
		aafm_render_meta_keys_selector();
		$html = ob_get_clean();

		$this->assert_textarea_is_labelled( $html, 'aafm_meta_keys' );
		$this->assert_textarea_is_labelled( $html, 'aafm_deny_meta_keys' );
	}

	/**
	 * Both user meta-key textareas expose a programmatic label + description, resolving to ids
	 * that exist in the markup.
	 */
	public function test_users_selector_textareas_are_labelled_for_assistive_tech(): void {
		ob_start();
		aafm_render_user_meta_keys_selector();
		$html = ob_get_clean();

		$this->assert_textarea_is_labelled( $html, 'aafm_exposed_user_meta_keys' );
		$this->assert_textarea_is_labelled( $html, 'aafm_denied_user_meta_keys' );
	}

	/**
	 * Both term meta-key textareas expose a programmatic label + description, resolving to ids
	 * that exist in the markup.
	 */
	public function test_taxonomies_selector_textareas_are_labelled_for_assistive_tech(): void {
		ob_start();
		aafm_render_term_meta_keys_selector();
		$html = ob_get_clean();

		$this->assert_textarea_is_labelled( $html, 'aafm_exposed_term_meta_keys' );
		$this->assert_textarea_is_labelled( $html, 'aafm_denied_term_meta_keys' );
	}

	/**
	 * Return the inner text of the first <textarea name="$name"> in the markup.
	 *
	 * @param string $html Rendered markup.
	 * @param string $name The textarea name attribute to locate.
	 * @return string The (HTML-escaped) textarea contents.
	 */
	private function textarea_contents( string $html, string $name ): string {
		$this->assertSame(
			1,
			preg_match(
				'/<textarea[^>]*\bname="' . preg_quote( $name, '/' ) . '"[^>]*>(.*?)<\/textarea>/s',
				$html,
				$m
			),
			"Textarea {$name} must render."
		);
		return $m[1];
	}

	/**
	 * Regression: when the allow option holds the `*` wildcard, the exposed textarea must SHOW the
	 * `*` (the getters strip it). If it rendered empty the operator could not see allow-all was
	 * active and a re-save would silently wipe the wildcard. Covers all three selectors plus the
	 * deny-all path. Each option is snapshot/restored so live state is untouched.
	 *
	 * @dataProvider provide_wildcard_render_cases
	 *
	 * @param string   $option   Option name to seed with the wildcard.
	 * @param callable $renderer Selector render function.
	 * @param string   $name     Textarea name that must surface the `*`.
	 */
	public function test_wildcard_is_surfaced_in_textarea( string $option, callable $renderer, string $name ): void {
		$had  = array_key_exists( $option, wp_load_alloptions() );
		$prev = get_option( $option, null );

		update_option( $option, array( '*' ) );

		ob_start();
		$renderer();
		$html = ob_get_clean();

		// Restore the exact prior state before asserting.
		if ( $had ) {
			update_option( $option, $prev );
		} else {
			delete_option( $option );
		}

		$this->assertStringContainsString(
			'*',
			$this->textarea_contents( $html, $name ),
			"The {$name} textarea must surface the `*` wildcard from {$option}."
		);
	}

	/**
	 * The six exposed/denied textareas across the post, user, and term selectors.
	 *
	 * @return array<string, array{0:string,1:callable,2:string}>
	 */
	public function provide_wildcard_render_cases(): array {
		return array(
			'post exposed' => array( 'aafm_allowed_meta_keys', 'aafm_render_meta_keys_selector', 'aafm_meta_keys' ),
			'post denied'  => array( 'aafm_denied_meta_keys', 'aafm_render_meta_keys_selector', 'aafm_deny_meta_keys' ),
			'user exposed' => array( 'aafm_exposed_user_meta_keys', 'aafm_render_user_meta_keys_selector', 'aafm_exposed_user_meta_keys' ),
			'user denied'  => array( 'aafm_denied_user_meta_keys', 'aafm_render_user_meta_keys_selector', 'aafm_denied_user_meta_keys' ),
			'term exposed' => array( 'aafm_exposed_term_meta_keys', 'aafm_render_term_meta_keys_selector', 'aafm_exposed_term_meta_keys' ),
			'term denied'  => array( 'aafm_denied_term_meta_keys', 'aafm_render_term_meta_keys_selector', 'aafm_denied_term_meta_keys' ),
		);
	}

	/**
	 * The full abilities tab wires the Users AND Taxonomies selectors into their panels, still
	 * inside the single outer form (no second form opened by any meta selector).
	 */
	public function test_abilities_tab_wires_users_selector_in_single_form(): void {
		ob_start();
		aafm_render_abilities_tab();
		$html = ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertStringContainsString( 'name="aafm_exposed_user_meta_keys"', $html );
		$this->assertStringContainsString( 'name="aafm_denied_user_meta_keys"', $html );
		$this->assertStringContainsString( 'name="aafm_deny_meta_keys"', $html );
		$this->assertStringContainsString( 'name="aafm_exposed_term_meta_keys"', $html );
		$this->assertStringContainsString( 'name="aafm_denied_term_meta_keys"', $html );
	}
}
