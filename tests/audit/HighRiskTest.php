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

	/**
	 * Put a high-risk name into the LIVE registry for the duration of a test.
	 *
	 * The enabled-abilities reader intersects the stored option against the live registry, and that
	 * registry is host-gated: with WooCommerce inactive (which is the case under PHPUnit) every wc-*
	 * row is absent, so an assertion that a locked ability is missing from the enabled set would pass
	 * on the host gate alone and prove nothing about the floor. Registering the row here takes the
	 * host gate out of the picture, leaving the subtraction as the only thing that can still drop the
	 * name.
	 *
	 * @param string ...$names Ability names to register.
	 * @return void
	 */
	private function register_high_risk_fixture( string ...$names ): void {
		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ) use ( $names ): array {
				foreach ( $names as $name ) {
					$registry[ $name ] = array(
						'label' => 'High-risk fixture',
						'group' => 'writes',
					);
				}
				return $registry;
			}
		);
		aafm_flush_registry_cache();
	}

	public function test_a_locked_ability_is_never_registered_even_when_enabled(): void {
		$this->register_high_risk_fixture( 'aafm/wc-create-order-refund' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/wc-create-order-refund' ) );
		$enabled = aafm_get_enabled_abilities();
		$this->assertContains( 'aafm/get-posts', $enabled );
		$this->assertNotContains( 'aafm/wc-create-order-refund', $enabled );
	}

	public function test_unlocking_restores_it_to_the_registered_set(): void {
		$this->register_high_risk_fixture( 'aafm/wc-create-order-refund' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/wc-create-order-refund' ) );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertContains( 'aafm/wc-create-order-refund', aafm_get_enabled_abilities() );
	}

	public function test_the_stored_option_is_never_rewritten_by_the_subtraction(): void {
		$this->register_high_risk_fixture( 'aafm/wc-create-order-refund' );
		$stored = array( 'aafm/wc-create-order-refund' );
		update_option( 'aafm_enabled_abilities', $stored );
		aafm_get_enabled_abilities();
		$this->assertSame( $stored, get_option( 'aafm_enabled_abilities' ) );
	}

	/**
	 * The floor is decided twice, by two independent implementations of one rule:
	 * aafm_ability_is_locked() in includes/audit/high-risk.php, which the admin UI reads to draw the
	 * lock, and the inline re-derivation inside aafm_get_enabled_abilities(), which decides what
	 * actually registers. They agree today, but nothing structural forces that, and a third
	 * condition added to one and not the other fails in the dangerous direction: the screen shows a
	 * padlock while the ability is still reachable over MCP.
	 *
	 * So assert the two answers are the same answer, for every builtin, in both lock states. The
	 * "not registered" side doubles as proof the fixture registration held, since an ability missing
	 * from the registry for any other reason would report locked here and fail against an unlocked
	 * predicate.
	 */
	public function test_the_two_locked_predicates_never_disagree(): void {
		$builtins = aafm_high_risk_abilities_builtin();
		$this->register_high_risk_fixture( ...$builtins );

		foreach ( array( true, false ) as $unlocked ) {
			update_option( 'aafm_high_risk_abilities_unlocked', $unlocked );
			update_option( 'aafm_enabled_abilities', $builtins );
			$registered = aafm_get_enabled_abilities();

			foreach ( $builtins as $name ) {
				$this->assertSame(
					aafm_ability_is_locked( $name ),
					! in_array( $name, $registered, true ),
					sprintf(
						'%s: aafm_ability_is_locked() and the registration subtraction disagree with the category %s.',
						$name,
						$unlocked ? 'unlocked' : 'locked'
					)
				);
			}
		}
	}

	/**
	 * The subtraction has to honour a filter-added name, not just the builtins. This is the branch
	 * the "filters can only narrow" constraint rests on: the filter's whole purpose is letting an
	 * operator lock something we did not ship as high-risk, and that is worth nothing if the
	 * registration path only ever consults the builtin list.
	 */
	public function test_a_filter_added_high_risk_ability_is_subtracted_too(): void {
		add_filter( 'aafm_high_risk_abilities', static fn( array $e ): array => array_merge( $e, array( 'aafm/get-posts' ) ) );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		$this->assertNotContains( 'aafm/get-posts', aafm_get_enabled_abilities() );

		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertContains( 'aafm/get-posts', aafm_get_enabled_abilities() );
	}

	public function test_every_builtin_names_a_registered_ability(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( aafm_high_risk_abilities_builtin() as $name ) {
			$this->assertArrayHasKey( $name, $registry, "{$name} is in the high-risk set but is not a registered ability." );
		}
	}
}
