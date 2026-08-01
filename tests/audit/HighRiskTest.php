<?php
/**
 * The high-risk floor: the locked set, the two one-directional filters, and the predicates.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class HighRiskTest extends TestCase {

	/**
	 * The golden set. Membership IS the security decision this file encodes, so it is pinned in
	 * both directions: the eight signed-off names in order, and nothing else. A later edit that
	 * drops an entry, renames one, or folds in an ability that was deliberately left out has to
	 * change this assertion to pass, which is the point at which someone has to justify it.
	 *
	 * The aafm/wc-create-customer name is called out because it is the one most likely to be added
	 * back in good faith. It is handled at the capability layer instead, not here.
	 */
	public function test_the_builtin_set_is_exactly_the_signed_off_eight(): void {
		$this->assertSame(
			array(
				'aafm/wc-create-order-refund',
				'aafm/wc-update-order-status',
				'aafm/wc-update-order',
				'aafm/wc-update-payment-gateway',
				'aafm/wc-create-coupon',
				'aafm/wc-update-coupon',
				'aafm/wc-create-tax-rate',
				'aafm/wc-update-tax-rate',
			),
			aafm_high_risk_abilities_builtin()
		);
		$this->assertNotContains( 'aafm/wc-create-customer', aafm_high_risk_abilities_builtin() );
	}

	public function test_the_master_switch_is_off_by_default(): void {
		$this->assertFalse( aafm_high_risk_unlocked() );
	}

	public function test_a_high_risk_ability_is_locked_by_default(): void {
		$this->assertTrue( aafm_ability_is_locked( 'aafm/wc-create-order-refund' ) );
	}

	public function test_an_ordinary_ability_is_never_locked(): void {
		$this->assertFalse( aafm_ability_is_high_risk( 'aafm/get-posts' ) );
		$this->assertFalse( aafm_ability_is_locked( 'aafm/get-posts' ) );
	}

	public function test_unlocking_clears_the_lock_but_not_the_high_risk_flag(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertFalse( aafm_ability_is_locked( 'aafm/wc-create-order-refund' ) );
		$this->assertTrue( aafm_ability_is_high_risk( 'aafm/wc-create-order-refund' ) );
	}

	public function test_the_filter_can_add_an_ability(): void {
		add_filter( 'aafm_high_risk_abilities', static fn( array $e ): array => array_merge( $e, array( 'aafm/get-posts' ) ) );
		$this->assertTrue( aafm_ability_is_high_risk( 'aafm/get-posts' ) );
	}

	public function test_the_filter_cannot_remove_a_builtin(): void {
		add_filter( 'aafm_high_risk_abilities', static fn(): array => array() );
		$this->assertTrue( aafm_ability_is_high_risk( 'aafm/wc-create-order-refund' ) );
	}

	public function test_the_filter_cannot_remove_a_builtin_by_returning_junk(): void {
		add_filter( 'aafm_high_risk_abilities', static fn(): string => 'nonsense' );
		$this->assertTrue( aafm_ability_is_high_risk( 'aafm/wc-create-order-refund' ) );
	}

	public function test_the_force_filter_beats_the_unlocked_option(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		add_filter( 'aafm_force_block_high_risk_abilities', '__return_true' );
		$this->assertFalse( aafm_high_risk_unlocked() );
		$this->assertTrue( aafm_ability_is_locked( 'aafm/wc-create-order-refund' ) );
	}

	public function test_the_force_filter_cannot_unlock_when_the_option_is_off(): void {
		add_filter( 'aafm_force_block_high_risk_abilities', '__return_false' );
		$this->assertFalse( aafm_high_risk_unlocked() );
	}

	public function test_every_builtin_names_a_registered_ability(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( aafm_high_risk_abilities_builtin() as $name ) {
			$this->assertArrayHasKey( $name, $registry, "{$name} is in the high-risk set but is not a registered ability." );
		}
	}
}
