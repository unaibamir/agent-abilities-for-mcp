<?php
/**
 * The Abilities tab has one Save button: markup, page script and strings.
 *
 * The script checks are static scans of admin.js (this repo has no JS runner); the browser
 * checklist covers the behaviour itself.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesTabSingleSaveTest extends TestCase {

	/**
	 * Render the Abilities tab as an administrator, with one eligible custom content type so
	 * every card renders.
	 *
	 * @param bool $with_cpt Register an eligible content type first.
	 * @return string
	 */
	private function render_tab( bool $with_cpt = true ): string {
		$this->acting_as( 'administrator' );
		if ( $with_cpt ) {
			register_post_type(
				'aafm_book',
				array(
					'public'          => true,
					'show_in_rest'    => true,
					'map_meta_cap'    => true,
					'capability_type' => 'post',
					'label'           => 'Books',
				)
			);
		}
		ob_start();
		aafm_render_abilities_tab();
		return (string) ob_get_clean();
	}

	/**
	 * Read admin.js.
	 *
	 * @return string
	 */
	private function js(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test, not a remote URL.
		return (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
	}

	/**
	 * The text of admin.js from one token up to the next.
	 *
	 * @param string $start First token.
	 * @param string $end   Token that closes the region.
	 * @return string
	 */
	private function region( string $start, string $end ): string {
		$js = $this->js();
		$a  = strpos( $js, $start );
		$b  = strpos( $js, $end );
		$this->assertNotFalse( $a, "$start not found in admin.js." );
		$this->assertNotFalse( $b, "$end not found in admin.js." );
		$this->assertGreaterThan( (int) $a, (int) $b, "$end must come after $start." );
		return substr( $js, (int) $a, (int) $b - (int) $a );
	}

	private function page_save_region(): string {
		return $this->region( '#bindSaveAbilities() {', '#bindSaveSettings() {' );
	}

	public function test_the_tab_has_exactly_one_submit_and_no_section_save_buttons(): void {
		$html = $this->render_tab();

		$this->assertSame( 1, substr_count( $html, 'type="submit"' ) );
		$this->assertSame( 0, preg_match_all( '/id="aafm-[a-z-]+-save"/', $html ) );
		$this->assertSame( 1, substr_count( $html, '<form' ) );
		foreach ( array( 'Save content types', 'Save meta keys', 'Save user meta keys', 'Save term meta keys' ) as $label ) {
			$this->assertStringNotContainsString( $label, $html );
		}
	}

	public function test_each_card_carries_its_section_key_and_a_hidden_result_line(): void {
		$html = $this->render_tab();

		foreach ( array( 'post_types', 'meta_keys', 'user_meta_keys', 'term_meta_keys' ) as $key ) {
			$this->assertSame( 1, substr_count( $html, 'data-aafm-section="' . $key . '"' ), $key );
		}
		$this->assertStringContainsString( '<p class="aafm-section-result aafm-post-types-status" hidden></p>', $html );
		$this->assertStringContainsString( '<p class="aafm-section-result aafm-meta-keys-status" hidden></p>', $html );
		$this->assertStringContainsString( '<p class="aafm-section-result aafm-user-meta-keys-status" hidden></p>', $html );
		$this->assertStringContainsString( '<p class="aafm-section-result aafm-term-meta-keys-status" hidden></p>', $html );
	}

	public function test_the_info_card_without_eligible_types_is_not_a_section(): void {
		$html = $this->render_tab( false );

		$this->assertStringNotContainsString( 'data-aafm-section="post_types"', $html );
		$this->assertStringNotContainsString( 'aafm-post-types-status', $html );
		$this->assertStringContainsString( 'No custom content types on this site are eligible to expose.', $html );
	}

	public function test_the_savebar_carries_the_hidden_unsaved_pill(): void {
		$html = $this->render_tab();

		$this->assertStringContainsString(
			'<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">Save changes</button> <span class="aafm-savebar-dirty" role="status"><span class="aafm-pill aafm-pill-warn" hidden>Unsaved changes</span></span> <span class="aafm-save-status" aria-live="polite"></span></div>',
			$html
		);
	}

	public function test_the_page_script_appends_every_section_field(): void {
		$region = $this->page_save_region();

		$calls = array(
			"body.append( 'action', 'aafm_save_abilities_page' );",
			"body.append( 'nonce', this.#nonce );",
			"body.append( 'aafm_sections[]', ",
			"body.append( 'aafm_abilities[]', '' );",
			"body.append( 'aafm_abilities[]', ",
			"body.append( 'aafm_scope[]', ",
			"body.append( 'aafm_post_types[]', '' );",
			"body.append( 'aafm_post_types[]', ",
			"body.append( 'aafm_meta_keys', ",
			"body.append( 'aafm_deny_meta_keys', ",
			"body.append( 'aafm_exposed_user_meta_keys', ",
			"body.append( 'aafm_denied_user_meta_keys', ",
			"body.append( 'aafm_exposed_term_meta_keys', ",
			"body.append( 'aafm_denied_term_meta_keys', ",
		);
		foreach ( $calls as $call ) {
			$this->assertStringContainsString( $call, $region, "The page save must contain: $call" );
		}
	}

	public function test_the_page_script_posts_no_old_section_action(): void {
		$js = $this->js();

		$old = "body.append( 'action', 'aafm_save_abilities' );";
		$this->assertSame( 1, substr_count( $js, $old ) );
		$this->assertStringContainsString(
			$old,
			$this->region( '#bindSaveIntegrations() {', '#bindSaveBridge() {' ),
			'The one remaining aafm_save_abilities post belongs to the Integrations tab.'
		);

		$new = "body.append( 'action', 'aafm_save_abilities_page' );";
		$this->assertSame( 1, substr_count( $js, $new ) );
		$this->assertStringContainsString( $new, $this->page_save_region() );

		foreach ( array(
			"'aafm_save_post_types'",
			"'aafm_save_meta_keys'",
			"'aafm_save_user_meta_keys'",
			"'aafm_save_term_meta_keys'",
			'#aafm-post-types-save',
			'#aafm-meta-keys-save',
			'#aafm-user-meta-keys-save',
			'#aafm-term-meta-keys-save',
		) as $gone ) {
			$this->assertStringNotContainsString( $gone, $js, "$gone must be gone from admin.js." );
		}
	}

	public function test_the_page_script_recomputes_dirty_from_the_controls(): void {
		$js = $this->js();
		$this->assertSame( 1, substr_count( $js, '#abilitiesDirty = false;' ) );

		$region = $this->page_save_region();
		foreach ( array( 'defaultChecked', 'defaultValue', "'pageshow'", "'beforeunload'", 'e.returnValue = true;', 'this.#abilitiesDirty =' ) as $token ) {
			$this->assertStringContainsString( $token, $region, "The page save must contain: $token" );
		}

		$this->assertStringContainsString(
			'savebar.hidden = 0 === matchCount && ! this.#abilitiesDirty;',
			$this->region( '#bindAbilitiesSearch() {', '#bindOsTabs() {' )
		);
		$this->assertStringNotContainsString( 'savebar.hidden = 0 === matchCount;', $js );
	}

	public function test_the_page_script_handles_expiry_cleaning_and_inflight_edits(): void {
		$region = $this->page_save_region();

		foreach ( array(
			'-1 === json || 0 === json',
			"'saveExpired'",
			"'savedCleaned'",
			"'savedWithNewerEdits'",
			'new Intl.ListFormat( undefined, { type: \'conjunction\' } )',
			'snapshot[ key ] = null;',
		) as $token ) {
			$this->assertStringContainsString( $token, $region, "The page save must contain: $token" );
		}
	}

	public function test_the_new_strings_are_localized(): void {
		$this->acting_as( 'administrator' );
		aafm_enqueue_admin_assets( 'toplevel_page_agent-abilities-for-mcp' );
		$data = wp_scripts()->get_data( 'aafm-admin', 'data' );
		$this->assertIsString( $data );

		foreach ( array(
			'unsavedChanges',
			'noChangesToSave',
			'notSavedSome',
			'notSavedAll',
			'sectionInTab',
			'notSavedSection',
			'notSavedSectionGeneric',
			'saveNetworkError',
			'saveExpired',
			'savedCleaned',
			'savedWithNewerEdits',
			'sectionAbilities',
			'sectionPostTypes',
			'sectionMetaKeys',
			'sectionUserKeys',
			'sectionTermKeys',
		) as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $data, "The i18n bag must carry $key." );
		}
	}

	public function test_the_pill_base_rule_hides_with_the_hidden_attribute(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test, not a remote URL.
		$css   = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.css' );
		$start = strpos( $css, "\t.aafm-pill {" );
		$this->assertNotFalse( $start, 'The .aafm-pill base rule must exist.' );
		// The base rule closes at the first tab-indented closing brace after it.
		$end = strpos( $css, "\n\t}", (int) $start );
		$this->assertNotFalse( $end );
		$block = substr( $css, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( '&[hidden] { display: none; }', $block );
	}
}
