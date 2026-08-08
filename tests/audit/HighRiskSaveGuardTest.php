<?php
/**
 * The high-risk floor's persistence guard: aafm_set_enabled_abilities() strips a locked ability
 * before it can ever reach the stored option, and logs an explicit refusal for every name that
 * was genuinely attempted rather than merely carried forward as stale data.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class HighRiskSaveGuardTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Put WooCommerce-subject rows into the LIVE registry for the duration of a test, so the
	 * high-risk builtins resolve without WooCommerce actually being installed under PHPUnit.
	 * Mirrors HighRiskTest::register_woocommerce_fixture().
	 *
	 * @param string ...$names Ability names to register.
	 * @return void
	 */
	private function register_woocommerce_fixture( string ...$names ): void {
		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ) use ( $names ): array {
				foreach ( $names as $name ) {
					$registry[ $name ] = array(
						'label'   => 'WooCommerce fixture',
						'group'   => 'writes',
						'subject' => 'woocommerce',
					);
				}
				return $registry;
			}
		);
		aafm_flush_registry_cache();
	}

	/**
	 * The exact bug: the Integrations tab used to render a live checkbox for a locked ability, so
	 * it reached $_POST, and aafm_resolve_scoped_enabled_input() has no lock check on the branch
	 * that takes a name straight from the POST - that is the "missing case" the diagnosis found.
	 * Reproduce the posted name directly (bypassing the renderer entirely) and prove the setter,
	 * not the resolver, is what actually stops it from persisting.
	 */
	public function test_a_posted_locked_ability_never_persists(): void {
		$this->register_woocommerce_fixture( 'aafm/wc-create-order-refund' );
		update_option( 'aafm_enabled_abilities', array() );

		$resolved = aafm_resolve_scoped_enabled_input(
			array(
				'aafm_scope'     => array( 'woocommerce' ),
				'aafm_abilities' => array( 'aafm/wc-create-order-refund' ),
			)
		);
		// The resolver alone still lets it through - proving that first is what makes the
		// setter's strip meaningful, rather than redundant with a check that already happened.
		$this->assertContains( 'aafm/wc-create-order-refund', $resolved );

		$persisted = aafm_set_enabled_abilities( $resolved );

		$this->assertNotContains( 'aafm/wc-create-order-refund', $persisted );
		$this->assertNotContains( 'aafm/wc-create-order-refund', get_option( 'aafm_enabled_abilities' ) );
	}

	/**
	 * The same blocked attempt must not read as a real enable: zero ability_enabled rows, exactly
	 * one ability_enable_blocked row, denied status, the ability named in the detail. This is what
	 * closes the original bug's third symptom - the log said "Enabled aafm/wc-create-order-refund"
	 * for something that was never actually reachable.
	 */
	public function test_a_blocked_enable_is_logged_as_a_refusal_not_an_enable(): void {
		$this->register_woocommerce_fixture( 'aafm/wc-create-order-refund' );
		update_option( 'aafm_enabled_abilities', array() );

		aafm_set_enabled_abilities( array( 'aafm/wc-create-order-refund' ) );

		$rows        = aafm_query_activity( array( 'per_page' => 10 ) );
		$event_types = array_column( $rows, 'event_type' );

		$this->assertNotContains( 'ability_enabled', $event_types, 'A locked ability must never produce an ability_enabled row.' );

		$blocked = array_values( array_filter( $rows, static fn( array $r ): bool => 'ability_enable_blocked' === $r['event_type'] ) );
		$this->assertCount( 1, $blocked );
		$this->assertSame( 'aafm/wc-create-order-refund', $blocked[0]['ability'] );
		$this->assertSame( 'denied', $blocked[0]['status'] );
		$this->assertStringContainsString( 'aafm/wc-create-order-refund', $blocked[0]['detail'] );
		// B37: with read-only mode off, the cause really is the high-risk lock, and the detail
		// says so (the read-only counterpart is pinned in ReadOnlyModeTest).
		$this->assertStringContainsString( 'high-risk', $blocked[0]['detail'] );
	}

	/**
	 * B10 (flipped from the 1.6.1 pin, deliberately): a locked ability already sitting in the
	 * option is the operator's preserved choice, not stale data to clean up. The registration
	 * floor (aafm_get_enabled_abilities()) keeps it out of tools/list while locked, so carrying
	 * it in the option is inert - and stripping it, as 1.6.1 did, meant re-locking the category
	 * wiped the stored selection on the very next save, so unlocking later handed back a blank
	 * slate instead of what was ticked. The read-only floor has always had this carve-out; the
	 * high-risk floor now behaves the same way. Nobody tried to enable anything here, so there is
	 * still no ability_enable_blocked row, and no ability_disabled row either - the carry is not
	 * a toggle.
	 */
	public function test_a_stored_locked_ability_is_carried_forward_inert_not_stripped(): void {
		$this->register_woocommerce_fixture( 'aafm/wc-create-order-refund', 'aafm/wc-list-orders' );
		// Already persisted (enabled while the category was unlocked); its checkbox no longer
		// renders, so nothing in this request restates it.
		update_option( 'aafm_enabled_abilities', array( 'aafm/wc-create-order-refund', 'aafm/wc-list-orders' ) );

		$resolved = aafm_resolve_scoped_enabled_input(
			array(
				'aafm_scope'     => array( 'woocommerce' ),
				'aafm_abilities' => array( 'aafm/wc-list-orders' ), // the locked name has no checkbox to post.
			)
		);
		$this->assertContains( 'aafm/wc-create-order-refund', $resolved, 'The preserve branch should still carry it forward.' );

		$persisted = aafm_set_enabled_abilities( $resolved );

		$this->assertContains( 'aafm/wc-create-order-refund', $persisted, 'A stored locked selection must survive the save.' );
		$this->assertContains( 'aafm/wc-create-order-refund', get_option( 'aafm_enabled_abilities' ) );

		$rows = aafm_query_activity( array( 'per_page' => 10 ) );
		$this->assertEmpty(
			array_filter( $rows, static fn( array $r ): bool => 'ability_enable_blocked' === $r['event_type'] ),
			'A carried-forward stored selection is not a new blocked attempt.'
		);
	}

	/**
	 * B10 end to end: unlock, enable, re-lock, save, unlock again - the operator's selection has
	 * to come back, because that is the exact promise the floor's own docs make ("unlocking the
	 * category restores exactly what was ticked before"). While locked, the stored name stays
	 * inert: the floored reader refuses to register it.
	 */
	public function test_relocking_then_saving_does_not_wipe_the_selection_unlocking_restores_it(): void {
		$this->register_woocommerce_fixture( 'aafm/wc-create-order-refund', 'aafm/wc-list-orders' );

		// Enabled while unlocked, then the category is re-locked.
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_enabled_abilities', array( 'aafm/wc-create-order-refund', 'aafm/wc-list-orders' ) );
		delete_option( 'aafm_high_risk_abilities_unlocked' );

		// The next FULL-REPLACE save (the Abilities tab posts no scope): the locked row rendered
		// no checkbox, so only the read is posted.
		$resolved  = aafm_resolve_scoped_enabled_input( array( 'aafm_abilities' => array( 'aafm/wc-list-orders' ) ) );
		$persisted = aafm_set_enabled_abilities( $resolved );

		$this->assertContains( 'aafm/wc-create-order-refund', $persisted, 'Re-locking must not wipe the stored selection on the next save.' );

		// Inert while locked: the registration floor still keeps it out.
		$this->assertNotContains( 'aafm/wc-create-order-refund', aafm_get_enabled_abilities() );

		// Unlocking restores the operator's prior selection, as the floor promises.
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertContains( 'aafm/wc-create-order-refund', aafm_get_enabled_abilities() );
	}

	/**
	 * The bulk "Enable all" scenario: every built-in locked ability posted at once - the shape a
	 * forged POST, or the pre-fix Integrations-tab bulk toggle, would send. None persist; every one
	 * is logged as its own distinct refusal.
	 */
	public function test_a_bulk_post_of_every_locked_ability_persists_none_and_logs_all(): void {
		$builtins = aafm_high_risk_abilities_builtin();
		$this->register_woocommerce_fixture( ...$builtins );
		update_option( 'aafm_enabled_abilities', array() );

		$persisted = aafm_set_enabled_abilities( $builtins );

		$this->assertSame( array(), $persisted, 'None of the locked abilities may persist.' );
		$this->assertSame( array(), get_option( 'aafm_enabled_abilities' ) );

		$rows    = aafm_query_activity( array( 'per_page' => 20 ) );
		$blocked = array_values( array_filter( $rows, static fn( array $r ): bool => 'ability_enable_blocked' === $r['event_type'] ) );
		$this->assertCount( count( $builtins ), $blocked );
		$this->assertEmpty( array_filter( $rows, static fn( array $r ): bool => 'ability_enabled' === $r['event_type'] ) );

		$logged_names = array_column( $blocked, 'ability' );
		sort( $logged_names );
		$sorted_builtins = $builtins;
		sort( $sorted_builtins );
		$this->assertSame( $sorted_builtins, $logged_names );
	}
}
