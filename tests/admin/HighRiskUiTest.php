<?php
/**
 * The locked ability row on the Abilities tab, and the master switch on the Settings tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class HighRiskUiTest extends TestCase {

	/**
	 * Render one ability row and return its markup.
	 *
	 * The entry comes from the FULL registry view so a WooCommerce ability still resolves on a
	 * bench with no WooCommerce installed - the host guard withholds those rows from the live
	 * registry, and every high-risk name in the built-in set is a WooCommerce one.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	private function render_row( string $name ): string {
		$registry = aafm_get_abilities_registry_full();
		$this->assertArrayHasKey( $name, $registry, "Unknown ability: $name" );

		ob_start();
		aafm_render_ability_row(
			array( 'name' => $name ) + $registry[ $name ],
			array(),
			aafm_ability_disclosures()
		);
		return (string) ob_get_clean();
	}

	public function test_a_locked_row_renders_no_checkbox(): void {
		$html = $this->render_row( 'aafm/wc-create-order-refund' );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'aafm-ability-locked', $html );
	}

	public function test_a_locked_row_points_at_the_setting(): void {
		$this->assertStringContainsString(
			'High-risk abilities',
			$this->render_row( 'aafm/wc-create-order-refund' )
		);
	}

	public function test_an_unlocked_high_risk_row_renders_a_checkbox_and_keeps_the_badge(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$html = $this->render_row( 'aafm/wc-create-order-refund' );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'aafm-badge-high-risk', $html );
	}

	public function test_an_ordinary_row_is_unchanged(): void {
		$html = $this->render_row( 'aafm/get-posts' );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringNotContainsString( 'aafm-badge-high-risk', $html );
		$this->assertStringNotContainsString( 'aafm-ability-locked', $html );
	}

	/**
	 * The locked state hides the checkbox but never the ability: an operator has to be able to
	 * see what the floor is holding back, or the category is indistinguishable from one that was
	 * never shipped.
	 */
	public function test_a_locked_row_still_shows_the_ability_and_its_high_risk_badge(): void {
		$html = $this->render_row( 'aafm/wc-create-order-refund' );
		$this->assertStringContainsString( 'aafm-badge-high-risk', $html );
		$this->assertStringContainsString( aafm_ability_label( 'aafm/wc-create-order-refund' ), $html );
	}

	/**
	 * The unlocked row's <input> contract is what the save handler binds to (page.php's
	 * aafm_sanitize_enabled_input()), so the exact name/value pair is pinned here rather than
	 * left to the looser "contains a checkbox" assertion above.
	 */
	public function test_an_unlocked_row_keeps_the_exact_input_contract(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertStringContainsString(
			'name="aafm_abilities[]" value="aafm/wc-create-order-refund"',
			$this->render_row( 'aafm/wc-create-order-refund' )
		);
	}

	/**
	 * Render the whole Settings tab and return its markup.
	 *
	 * @return string
	 */
	private function render_settings_tab(): string {
		ob_start();
		aafm_render_settings_tab();
		return (string) ob_get_clean();
	}

	/**
	 * Pull just the master-switch <input> tag out of the Settings markup.
	 *
	 * @return string
	 */
	private function high_risk_input(): string {
		$html = $this->render_settings_tab();
		$this->assertSame(
			1,
			preg_match( '/<input[^>]*name="aafm_high_risk_abilities_unlocked"[^>]*>/', $html, $m ),
			'The master-switch input is missing from the Settings tab.'
		);
		return $m[0];
	}

	public function test_the_section_is_its_own_card_not_the_danger_zone(): void {
		$html = $this->render_settings_tab();
		$this->assertStringContainsString( 'High-risk abilities', $html );

		$high_risk_at = strpos( $html, 'High-risk abilities' );
		$danger_at    = strpos( $html, 'Danger zone' );
		$this->assertLessThan( $danger_at, $high_risk_at, 'The high-risk card must not be inside or after the Danger zone card.' );
	}

	/**
	 * The card sitting before the Danger zone is not enough on its own: the Danger zone renders
	 * AFTER </form>, so a card that drifted into that gap would still satisfy the ordering check
	 * above while admin.js's form.querySelector() returned null, the field never reached the POST
	 * body, and the option went back to locked on every save. That is the exact bug this task
	 * exists to close, so the boundary that actually matters is pinned against the </form> tag.
	 */
	public function test_the_switch_sits_inside_the_settings_form(): void {
		$html      = $this->render_settings_tab();
		$form_end  = strpos( $html, '</form>' );
		$switch_at = strpos( $html, 'name="aafm_high_risk_abilities_unlocked"' );
		$this->assertNotFalse( $form_end, 'The settings form has no closing tag.' );
		$this->assertNotFalse( $switch_at, 'The master switch is missing from the Settings tab.' );
		$this->assertLessThan(
			$form_end,
			$switch_at,
			'The master switch must sit inside #aafm-settings-form or admin.js cannot read it.'
		);
	}

	public function test_the_switch_defaults_to_off_in_the_markup(): void {
		$this->assertStringNotContainsString(
			'name="aafm_high_risk_abilities_unlocked" checked',
			$this->render_settings_tab()
		);
	}

	/**
	 * The assertion above only catches a `checked` that lands immediately after the name
	 * attribute, and the house markup puts it after `value="1"`. These two read the input tag
	 * itself, so the default-off claim holds wherever checked() places the attribute.
	 */
	public function test_the_switch_input_carries_no_checked_attribute_by_default(): void {
		$this->assertStringNotContainsString( 'checked', $this->high_risk_input() );
	}

	public function test_the_switch_input_reflects_a_stored_unlock(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertStringContainsString( 'checked', $this->high_risk_input() );
	}

	/**
	 * Two things the card has to state outright, because an operator cannot infer either one.
	 * The floor covers native abilities only, and the enable and disable entries expire on the
	 * shared retention schedule rather than being kept indefinitely.
	 */
	public function test_the_card_says_bridged_abilities_are_not_covered(): void {
		$this->assertStringContainsString( 'bridged in from other plugins', $this->render_settings_tab() );
	}

	public function test_the_card_points_at_the_shared_log_retention(): void {
		$this->assertStringContainsString(
			'same number of days as everything else in the activity log',
			$this->render_settings_tab()
		);
	}
}
