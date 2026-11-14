<?php
/**
 * Saved selections on an inactive integration (H-14): the card counts the tools saved in the
 * stored allowlist and marks each one "Saved, currently unavailable", while its control stays
 * disabled and real access still comes only from aafm_get_enabled_abilities().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class IntegrationSavedStateTest extends TestCase {

	private const SAVED = array( 'aafm/wc-list-products', 'aafm/wc-get-product' );

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		update_option( 'aafm_enabled_abilities', array_merge( self::SAVED, array( 'aafm/get-post' ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		parent::tear_down();
	}

	/**
	 * The WooCommerce card's <details> block.
	 *
	 * @return string
	 */
	private function woocommerce_card(): string {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '#<details class="aafm-card aafm-integration-card aafm-integration-woocommerce[^"]*">.*?</details>#s', $html, $m ) );
		return $m[0];
	}

	public function test_saved_slugs_are_the_stored_names_in_that_cards_list(): void {
		$this->assertSame( self::SAVED, aafm_integration_saved_slugs( 'woocommerce' ) );
		$this->assertSame( array(), aafm_integration_saved_slugs( 'acf' ) );
		$this->assertSame( array(), aafm_integration_saved_slugs( 'no-such-card' ) );
	}

	public function test_inactive_integration_card_shows_saved_count_and_saved_unavailable_rows(): void {
		$card  = $this->woocommerce_card();
		$total = aafm_integration_manifest()['woocommerce']['total'];

		$this->assertStringContainsString( sprintf( '2 saved / %d', $total ), $card );
		$this->assertStringNotContainsString( 'aafm-enabled-num', $card, 'The live save patch must not overwrite the saved count with 0.' );
		$this->assertSame( 2, substr_count( $card, 'Saved, currently unavailable' ) );
		foreach ( self::SAVED as $name ) {
			$this->assertSame( 1, preg_match( '#value="' . preg_quote( $name, '#' ) . '"[^>]*disabled#', $card ), $name . ' keeps its disabled control.' );
		}
	}

	public function test_saved_state_does_not_enable_access(): void {
		$this->woocommerce_card();
		foreach ( self::SAVED as $name ) {
			$this->assertNotContains( $name, aafm_get_enabled_abilities() );
		}
	}

	public function test_saved_inactive_row_checkbox_is_described_by_its_pill(): void {
		$card = $this->woocommerce_card();
		foreach ( self::SAVED as $name ) {
			$title_id = 'aafm-int-ability-title-' . sanitize_key( $name );
			$this->assertSame( 1, preg_match( '#value="' . preg_quote( $name, '#' ) . '" aria-labelledby="' . $title_id . '" aria-describedby="' . $title_id . '-saved"#', $card ), $name );
			$this->assertStringContainsString( '<span class="aafm-pill aafm-pill-neutral" id="' . $title_id . '-saved">Saved, currently unavailable</span>', $card );
		}
	}

	public function test_no_other_ability_row_has_aria_describedby(): void {
		$card = $this->woocommerce_card();
		$this->assertSame( count( self::SAVED ), substr_count( $card, 'aria-describedby=' ) );
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();
		$this->assertSame( count( self::SAVED ), substr_count( $html, 'aria-describedby="aafm-int-ability-title-' ) );
	}
}
