<?php
/**
 * Tests for the two OAuth Settings toggles ("Enable OAuth" and "Enable dynamic
 * client registration"): their persistence through the settings save path, their
 * presence in the reset allowlist, and an additive-only render of the Settings tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\OAuth;

use Oversio\Tests\TestCase;

/**
 * Covers the OAuth toggle readers' default-on behaviour, the '1'/'0' string the
 * save path persists, the reset allowlist membership, and the frozen-invariant
 * render check (new switches added, existing rows untouched).
 */
class SettingsTest extends TestCase {

	/**
	 * Each OAuth toggle reader defaults to true when its option was never stored,
	 * matching the fail-on default of oversio_oauth_option_is_on().
	 */
	public function test_oauth_toggles_default_on_when_option_absent(): void {
		delete_option( 'oversio_oauth_enabled' );
		delete_option( 'oversio_oauth_dcr_enabled' );

		$this->assertTrue( oversio_oauth_enabled() );
		$this->assertTrue( oversio_oauth_dcr_enabled() );
	}

	/**
	 * A present checkbox sanitizes to the string '1' for both keys, and the readers
	 * report enabled.
	 */
	public function test_save_with_toggles_present_persists_one_and_reads_true(): void {
		$clean = oversio_sanitize_settings_input(
			array(
				'oversio_oauth_enabled'     => '1',
				'oversio_oauth_dcr_enabled' => '1',
			)
		);

		$this->assertSame( '1', $clean['oversio_oauth_enabled'] );
		$this->assertSame( '1', $clean['oversio_oauth_dcr_enabled'] );

		update_option( 'oversio_oauth_enabled', $clean['oversio_oauth_enabled'] );
		update_option( 'oversio_oauth_dcr_enabled', $clean['oversio_oauth_dcr_enabled'] );

		$this->assertTrue( oversio_oauth_enabled() );
		$this->assertTrue( oversio_oauth_dcr_enabled() );
	}

	/**
	 * An absent checkbox (unchecked checkboxes never reach $_POST) sanitizes to the
	 * string '0' for both keys, persists a falsy-stored value, and the readers report
	 * disabled. Persisting '0' rather than a PHP bool false is what keeps the toggle
	 * from sticking on against a never-created option.
	 */
	public function test_save_with_toggles_absent_persists_zero_and_reads_false(): void {
		$clean = oversio_sanitize_settings_input( array() );

		$this->assertSame( '0', $clean['oversio_oauth_enabled'] );
		$this->assertSame( '0', $clean['oversio_oauth_dcr_enabled'] );

		update_option( 'oversio_oauth_enabled', $clean['oversio_oauth_enabled'] );
		update_option( 'oversio_oauth_dcr_enabled', $clean['oversio_oauth_dcr_enabled'] );

		$this->assertSame( '0', get_option( 'oversio_oauth_enabled' ) );
		$this->assertSame( '0', get_option( 'oversio_oauth_dcr_enabled' ) );
		$this->assertFalse( oversio_oauth_enabled() );
		$this->assertFalse( oversio_oauth_dcr_enabled() );
	}

	/**
	 * Both OAuth keys belong to the reset allowlist so a reset clears them too.
	 */
	public function test_config_option_names_includes_oauth_toggles(): void {
		$names = oversio_config_option_names();

		$this->assertContains( 'oversio_oauth_enabled', $names );
		$this->assertContains( 'oversio_oauth_dcr_enabled', $names );
	}

	/**
	 * The rendered Settings tab gains the two OAuth switch rows — each a checkbox of
	 * the right name inside an .oversio-switch label — without disturbing the existing
	 * controls. Asserting the force-draft checkbox, the reset hook, and the danger
	 * card still render proves the additive change left the prior markup intact.
	 */
	public function test_settings_render_adds_oauth_toggles_and_keeps_existing_rows(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		oversio_render_settings_tab();
		$html = (string) ob_get_clean();

		// New OAuth switches: checkbox of the right name, inside an .oversio-switch label.
		$this->assertMatchesRegularExpression(
			'/<label class="oversio-switch"><input type="checkbox"[^>]*name="oversio_oauth_enabled"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<label class="oversio-switch"><input type="checkbox"[^>]*name="oversio_oauth_dcr_enabled"/',
			$html
		);

		// Accessibility tie-up: the row title and the descriptive sentence label each carry
		// an id, and the matching checkbox names BOTH ids via aria-labelledby, so its
		// accessible name is the title plus the sentence (not the terse title alone).
		$this->assertStringContainsString( '<div class="oversio-set-label" id="oversio-oauth-enabled-title">', $html );
		$this->assertStringContainsString( '<div class="oversio-set-label" id="oversio-oauth-dcr-enabled-title">', $html );
		$this->assertStringContainsString( '<label for="oversio-oauth-enabled" id="oversio-oauth-enabled-desc">', $html );
		$this->assertStringContainsString( '<label for="oversio-oauth-dcr-enabled" id="oversio-oauth-dcr-enabled-desc">', $html );
		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" id="oversio-oauth-enabled"[^>]*aria-labelledby="oversio-oauth-enabled-title oversio-oauth-enabled-desc"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" id="oversio-oauth-dcr-enabled"[^>]*aria-labelledby="oversio-oauth-dcr-enabled-title oversio-oauth-dcr-enabled-desc"/',
			$html
		);

		// Existing controls untouched.
		$this->assertStringContainsString( 'name="oversio_force_draft"', $html );
		$this->assertStringContainsString( 'oversio-reset-plugin', $html );
		$this->assertStringContainsString( 'oversio-danger', $html );
	}
}
