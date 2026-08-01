<?php
/**
 * The locked ability row on the Abilities tab.
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
}
