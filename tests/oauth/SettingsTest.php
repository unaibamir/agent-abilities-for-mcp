<?php
/**
 * Tests for the OAuth Settings surface: the "Enable OAuth" toggle (default off) and the
 * "Enable dynamic client registration" toggle (default on), both persisted through the
 * save path as '1'/'0' strings, their readers' defaults, the reset allowlist clearing
 * both, and a render check that both switches appear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Covers the OAuth toggle reader's default-off behaviour, the DCR reader's default-on
 * behaviour, the '1'/'0' string the save path persists for each, the reset allowlist
 * membership, and the render invariant (both switches present, DCR checked by default).
 */
class SettingsTest extends TestCase {

	/**
	 * With no stored rows, OAuth reads OFF (the public surface is an explicit opt-in) and
	 * DCR reads ON (registration is on by default so ChatGPT and Claude can connect once
	 * OAuth is switched on). The two toggles are independent.
	 */
	public function test_oauth_defaults_off_and_dcr_defaults_on_when_option_absent(): void {
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled() );
	}

	/**
	 * Present checkboxes sanitize to the string '1' for both toggles, and each reader
	 * reports enabled once the value is stored.
	 */
	public function test_save_with_both_present_persists_one_each(): void {
		$clean = aafm_sanitize_settings_input(
			array(
				'aafm_oauth_enabled'     => '1',
				'aafm_oauth_dcr_enabled' => '1',
			)
		);

		$this->assertSame( '1', $clean['aafm_oauth_enabled'] );
		$this->assertSame( '1', $clean['aafm_oauth_dcr_enabled'] );

		update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );
		update_option( 'aafm_oauth_dcr_enabled', $clean['aafm_oauth_dcr_enabled'] );

		$this->assertTrue( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled() );
	}

	/**
	 * An absent checkbox sanitizes to the string '0' for each toggle, so turning DCR off in
	 * the UI persists a real '0' the reader honours over its on-by-default default.
	 * Persisting '0' rather than a PHP bool false is what keeps a toggle from sticking on
	 * against a never-created option.
	 */
	public function test_save_with_both_absent_persists_zero_each(): void {
		$clean = aafm_sanitize_settings_input( array() );

		$this->assertSame( '0', $clean['aafm_oauth_enabled'] );
		$this->assertSame( '0', $clean['aafm_oauth_dcr_enabled'] );

		update_option( 'aafm_oauth_enabled', $clean['aafm_oauth_enabled'] );
		update_option( 'aafm_oauth_dcr_enabled', $clean['aafm_oauth_dcr_enabled'] );

		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'A stored 0 turns the DCR toggle off.' );
	}

	/**
	 * Both OAuth keys belong to the reset allowlist so a reset clears them too - after
	 * which each falls back to its own default (OAuth off, DCR on).
	 */
	public function test_config_option_names_includes_oauth_toggles(): void {
		$names = aafm_config_option_names();

		$this->assertContains( 'aafm_oauth_enabled', $names );
		$this->assertContains( 'aafm_oauth_dcr_enabled', $names );
	}

	/**
	 * The rendered Settings tab shows both the "Enable OAuth" switch and the "Enable
	 * dynamic client registration" switch, with DCR checked by default. Asserting the
	 * force-draft checkbox, the reset hook, and the danger card still render proves the
	 * markup around them is intact.
	 */
	public function test_settings_render_keeps_both_oauth_toggles(): void {
		delete_option( 'aafm_oauth_dcr_enabled' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		// The OAuth switch: checkbox of the right name, inside an .aafm-switch label.
		$this->assertMatchesRegularExpression(
			'/<label class="aafm-switch"><input type="checkbox"[^>]*name="aafm_oauth_enabled"/',
			$html
		);

		// The DCR switch renders again, and is checked by default (no stored row).
		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" id="aafm-oauth-dcr-enabled"[^>]*name="aafm_oauth_dcr_enabled"[^>]*checked="checked"/',
			$html,
			'The DCR toggle must render checked by default.'
		);

		// Accessibility tie-up on both switches.
		$this->assertStringContainsString( '<div class="aafm-set-label" id="aafm-oauth-enabled-title">', $html );
		$this->assertStringContainsString( '<label for="aafm-oauth-enabled" id="aafm-oauth-enabled-desc">', $html );
		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" id="aafm-oauth-dcr-enabled"[^>]*aria-labelledby="aafm-oauth-dcr-enabled-title aafm-oauth-dcr-enabled-desc"/',
			$html
		);

		// Existing controls untouched.
		$this->assertStringContainsString( 'name="aafm_force_draft"', $html );
		$this->assertStringContainsString( 'aafm-reset-plugin', $html );
		$this->assertStringContainsString( 'aafm-danger', $html );
	}
}
