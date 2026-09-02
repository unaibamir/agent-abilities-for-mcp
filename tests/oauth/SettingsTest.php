<?php
/**
 * Tests for the OAuth Settings surface after DCR-follows-OAuth: the single "Enable
 * OAuth" toggle's persistence through the save path, the DCR helper following that
 * toggle, the reset allowlist still clearing the legacy DCR option, and a render check
 * that the OAuth toggle is present while the old DCR toggle is gone (replaced by a note).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Covers the OAuth toggle reader's default-off behaviour, the '1'/'0' string the save
 * path persists, DCR following OAuth, the reset allowlist membership, and the render
 * invariant (OAuth switch present, DCR switch removed, informational note shown).
 */
class SettingsTest extends TestCase {

	/**
	 * The OAuth toggle reader defaults to FALSE when its option was never stored: the
	 * public OAuth surface is an explicit opt-in, so a fresh install (no stored row)
	 * reads off. DCR follows OAuth, so it is off too.
	 */
	public function test_oauth_defaults_off_and_dcr_follows_when_option_absent(): void {
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertFalse( aafm_oauth_dcr_enabled() );
	}

	/**
	 * A present OAuth checkbox sanitizes to the string '1', the reader reports enabled,
	 * and DCR follows it on - with no DCR key in the sanitized output any more.
	 */
	public function test_save_with_oauth_present_persists_one_and_dcr_follows(): void {
		$clean = aafm_sanitize_settings_input(
			array(
				'aafm_oauth_enabled' => '1',
			)
		);

		$this->assertSame( '1', $clean['aafm_oauth_enabled'] );
		$this->assertArrayNotHasKey( 'aafm_oauth_dcr_enabled', $clean, 'DCR is no longer a sanitized settings field.' );

		update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );

		$this->assertTrue( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'DCR follows OAuth on.' );
	}

	/**
	 * An absent OAuth checkbox sanitizes to the string '0', persists a falsy-stored value,
	 * and the reader reports disabled - with DCR following it off. Persisting '0' rather
	 * than a PHP bool false is what keeps the toggle from sticking on against a
	 * never-created option.
	 */
	public function test_save_with_oauth_absent_persists_zero_and_dcr_follows(): void {
		$clean = aafm_sanitize_settings_input( array() );

		$this->assertSame( '0', $clean['aafm_oauth_enabled'] );

		update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'DCR follows OAuth off.' );
	}

	/**
	 * Both OAuth keys belong to the reset allowlist so a reset clears them too - the DCR
	 * key stays listed as a legacy option a reset should still wipe.
	 */
	public function test_config_option_names_includes_oauth_toggles(): void {
		$names = aafm_config_option_names();

		$this->assertContains( 'aafm_oauth_enabled', $names );
		$this->assertContains( 'aafm_oauth_dcr_enabled', $names );
	}

	/**
	 * The rendered Settings tab keeps the "Enable OAuth" switch but no longer renders a
	 * separate DCR toggle - it shows an informational note that DCR follows OAuth instead.
	 * Asserting the force-draft checkbox, the reset hook, and the danger card still render
	 * proves the change left the prior markup intact.
	 */
	public function test_settings_render_keeps_oauth_toggle_and_drops_dcr_toggle(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		// The OAuth switch: checkbox of the right name, inside an .aafm-switch label.
		$this->assertMatchesRegularExpression(
			'/<label class="aafm-switch"><input type="checkbox"[^>]*name="aafm_oauth_enabled"/',
			$html
		);

		// The DCR toggle is gone: no checkbox named aafm_oauth_dcr_enabled anywhere.
		$this->assertDoesNotMatchRegularExpression(
			'/name="aafm_oauth_dcr_enabled"/',
			$html,
			'The separate DCR toggle must no longer render.'
		);

		// The informational note that replaces it points at the filter escape hatch.
		$this->assertStringContainsString( 'aafm_oauth_dcr_enabled filter', $html );

		// Accessibility tie-up on the surviving OAuth switch.
		$this->assertStringContainsString( '<div class="aafm-set-label" id="aafm-oauth-enabled-title">', $html );
		$this->assertStringContainsString( '<label for="aafm-oauth-enabled" id="aafm-oauth-enabled-desc">', $html );
		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" id="aafm-oauth-enabled"[^>]*aria-labelledby="aafm-oauth-enabled-title aafm-oauth-enabled-desc"/',
			$html
		);

		// Existing controls untouched.
		$this->assertStringContainsString( 'name="aafm_force_draft"', $html );
		$this->assertStringContainsString( 'aafm-reset-plugin', $html );
		$this->assertStringContainsString( 'aafm-danger', $html );
	}
}
