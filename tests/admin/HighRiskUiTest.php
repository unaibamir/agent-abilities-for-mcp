<?php
/**
 * The locked ability row on the Abilities and Integrations tabs, and the master switch on the
 * Settings tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class HighRiskUiTest extends TestCase {

	/**
	 * Render one Abilities-tab ability row and return its markup.
	 *
	 * INVALID UNTIL 2026-08-01: this file used to call aafm_render_ability_row() with
	 * 'aafm/wc-create-order-refund'. That pairing can never happen in production. Every built-in
	 * high-risk ability is subject=woocommerce, and aafm_render_abilities_tab() only ever passes
	 * this renderer a row whose subject is one of the six aafm_abilities_subjects() lists
	 * (content, taxonomies, comments, users, media, site) - it filters $by_subject down to
	 * exactly that list at page.php:1289-1294 before this renderer is ever called. So no built-in
	 * high-risk ability has ever actually reached this renderer's lock branch: it rendered, it
	 * passed, and it proved nothing. See test_no_builtin_high_risk_ability_belongs_to_an_abilities_tab_subject()
	 * below, which pins the fact that made the old test invalid.
	 *
	 * The lock branch is still real, reachable code, though: aafm_high_risk_abilities is a public
	 * filter (spec 146 §4.3/§10.3), the operator's own escape hatch for locking an ability we did
	 * not ship as high-risk - including an ordinary content/user/media ability that genuinely does
	 * render here. These tests exercise that path instead of the impossible one: mark an ordinary
	 * six-subject ability high-risk for the duration of the test via make_high_risk(), then render
	 * it through the real renderer. The registry read also switched from the FULL view back to the
	 * live one, since the fixture abilities are always registered regardless of WooCommerce.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	private function render_row( string $name ): string {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( $name, $registry, "Unknown ability: $name" );

		ob_start();
		aafm_render_ability_row(
			array( 'name' => $name ) + $registry[ $name ],
			array(),
			aafm_ability_disclosures()
		);
		return (string) ob_get_clean();
	}

	/**
	 * Mark an already-registered, ordinary ability high-risk for the duration of one test, via the
	 * same aafm_high_risk_abilities filter an operator would use to lock something this plugin
	 * did not ship as high-risk. Mirrors HighRiskTest::test_the_filter_can_add_an_ability().
	 *
	 * @param string $name Ability name already present in the live registry.
	 * @return void
	 */
	private function make_high_risk( string $name ): void {
		add_filter(
			'aafm_high_risk_abilities',
			static fn( array $e ): array => array_merge( $e, array( $name ) )
		);
	}

	/**
	 * Codifies the fact that made the old formulation of these tests invalid: none of the eight
	 * built-in high-risk abilities has a subject the Abilities tab ever renders, so
	 * aafm_render_ability_row()'s lock branch has never actually fired for any ability this
	 * plugin ships - only the Integrations-tab renderer (HighRiskUiTest's
	 * render_integration_row() tests below) has. If this ever fails, a built-in high-risk ability
	 * just became reachable from the Abilities tab and the fixture-based tests above need
	 * re-examining, since they would then be standing in for a real combination instead of a
	 * hypothetical one.
	 */
	public function test_no_builtin_high_risk_ability_belongs_to_an_abilities_tab_subject(): void {
		$abilities_tab_subjects = array_keys( aafm_abilities_subjects() );
		$registry               = aafm_get_abilities_registry_full();
		foreach ( aafm_high_risk_abilities_builtin() as $name ) {
			$subject = (string) ( $registry[ $name ]['subject'] ?? '' );
			$this->assertNotContains(
				$subject,
				$abilities_tab_subjects,
				"{$name}'s subject '{$subject}' would make it reachable via aafm_render_ability_row(), contradicting this file's tests."
			);
		}
	}

	public function test_a_locked_row_renders_no_checkbox(): void {
		$this->make_high_risk( 'aafm/create-page' );
		$html = $this->render_row( 'aafm/create-page' );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'aafm-ability-locked', $html );
	}

	public function test_a_locked_row_points_at_the_setting(): void {
		$this->make_high_risk( 'aafm/create-page' );
		$this->assertStringContainsString(
			'High-risk abilities',
			$this->render_row( 'aafm/create-page' )
		);
	}

	public function test_an_unlocked_high_risk_row_renders_a_checkbox_and_keeps_the_badge(): void {
		$this->make_high_risk( 'aafm/create-page' );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$html = $this->render_row( 'aafm/create-page' );
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
		$this->make_high_risk( 'aafm/create-page' );
		$html = $this->render_row( 'aafm/create-page' );
		$this->assertStringContainsString( 'aafm-badge-high-risk', $html );
		$this->assertStringContainsString( aafm_ability_label( 'aafm/create-page' ), $html );
	}

	/**
	 * The unlocked row's <input> contract is what the save handler binds to (page.php's
	 * aafm_sanitize_enabled_input()), so the exact name/value pair is pinned here rather than
	 * left to the looser "contains a checkbox" assertion above.
	 */
	public function test_an_unlocked_row_keeps_the_exact_input_contract(): void {
		$this->make_high_risk( 'aafm/create-page' );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertStringContainsString(
			'name="aafm_abilities[]" value="aafm/create-page"',
			$this->render_row( 'aafm/create-page' )
		);
	}

	/**
	 * Render one Integrations-tab ability row and return its markup.
	 *
	 * This is THE real path for every built-in high-risk ability, not a secondary case: all eight
	 * are subject=woocommerce, and subject=woocommerce never reaches aafm_render_ability_row()
	 * (see render_row()'s docblock above and test_no_builtin_high_risk_ability_belongs_to_an_abilities_tab_subject()).
	 * aafm_render_integration_ability_row() shipped without a lock branch at all, so a locked
	 * ability's checkbox rendered live from a card whose master switch was off - the actual bug
	 * an operator hit. It now shares the lock treatment with the Abilities-tab renderer via
	 * aafm_render_ability_toggle_control(); these tests exercise the renderer that matters.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	private function render_integration_row( string $name ): string {
		$registry = aafm_get_abilities_registry_full();
		$this->assertArrayHasKey( $name, $registry, "Unknown ability: $name" );

		ob_start();
		aafm_render_integration_ability_row(
			array( 'name' => $name ) + $registry[ $name ],
			array(),
			aafm_ability_disclosures()
		);
		return (string) ob_get_clean();
	}

	public function test_an_integrations_tab_locked_row_renders_no_checkbox(): void {
		$html = $this->render_integration_row( 'aafm/wc-create-order-refund' );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'aafm-ability-locked', $html );
	}

	public function test_an_integrations_tab_locked_row_shows_the_high_risk_badge_and_note(): void {
		$html = $this->render_integration_row( 'aafm/wc-create-order-refund' );
		$this->assertStringContainsString( 'aafm-badge-high-risk', $html );
		$this->assertStringContainsString( 'High-risk abilities', $html );
	}

	public function test_an_integrations_tab_unlocked_high_risk_row_still_renders_a_checkbox(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$html = $this->render_integration_row( 'aafm/wc-create-order-refund' );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString(
			'name="aafm_abilities[]" value="aafm/wc-create-order-refund"',
			$html
		);
		$this->assertStringContainsString( 'aafm-badge-high-risk', $html, 'The high-risk badge must persist once unlocked, or the row is indistinguishable from an ordinary write ability.' );
	}

	public function test_an_integrations_tab_ordinary_row_is_unchanged(): void {
		$html = $this->render_integration_row( 'aafm/get-posts' );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringNotContainsString( 'aafm-badge-high-risk', $html );
		$this->assertStringNotContainsString( 'aafm-ability-locked', $html );
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

	/**
	 * The switch is a row of Safety controls now, not a card of its own, but the thing that check
	 * always protected is unchanged: it is an ordinary setting an operator reaches on purpose, so
	 * it must never end up inside or after the Danger zone, where a destructive action lives.
	 */
	public function test_the_switch_is_a_safety_controls_row_not_the_danger_zone(): void {
		$html = $this->render_settings_tab();

		$safety_at    = strpos( $html, 'Safety controls' );
		$high_risk_at = strpos( $html, 'name="aafm_high_risk_abilities_unlocked"' );
		$danger_at    = strpos( $html, 'Danger zone' );

		$this->assertNotFalse( $safety_at, 'The Safety controls card is missing from the Settings tab.' );
		$this->assertNotFalse( $high_risk_at, 'The master switch is missing from the Settings tab.' );
		$this->assertNotFalse( $danger_at );
		$this->assertLessThan( $high_risk_at, $safety_at, 'The switch must render inside the Safety controls card.' );
		$this->assertLessThan( $danger_at, $high_risk_at, 'The switch must not be inside or after the Danger zone card.' );
	}

	/**
	 * The standalone card is gone on purpose - two one-row cards broke the rhythm of the tab. Pin
	 * its absence so it cannot quietly come back alongside the row and give the operator two
	 * switches for one option.
	 */
	public function test_the_standalone_high_risk_card_is_gone(): void {
		$this->assertStringNotContainsString(
			'aafm-card-head-title">High-risk abilities',
			$this->render_settings_tab()
		);
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
