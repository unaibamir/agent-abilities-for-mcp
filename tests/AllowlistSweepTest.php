<?php
/**
 * Sweep: EVERY registered ability - not a hand-picked sample - is actually gated by the
 * allowlist at both the decorated-closure chokepoint and the tools/list discovery chokepoint.
 *
 * Iterates the full live registry rather than a fixed list, per
 * [[sweep-completeness-needs-a-test]]: a check placed at the wrong seam (inside
 * aafm_user_can_call_ability() only, say) would still pass a hand-picked sample of coarse-cap
 * abilities while silently missing every per-object-mapped one
 * (aafm_ability_list_permission() short-circuits past that function for those) - this sweep
 * would catch that, a sample would not.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AllowlistSweepTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_ability_allowlist_overrides' );
	}

	public function test_a_role_scoped_to_nothing_can_discover_nothing_in_the_full_registry(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'subscriber',
					'allowed_abilities' => array(),
				),
			)
		);
		$this->register_enabled( array_keys( aafm_get_abilities_registry() ) );
		$this->acting_as( 'subscriber' );

		$names = array_keys( aafm_get_abilities_registry() );
		$this->assertNotEmpty( $names, 'The registry fixture returned nothing - the test setup is broken, not a real empty catalog.' );

		foreach ( $names as $name ) {
			$this->assertFalse(
				aafm_user_can_discover_ability( $name ),
				sprintf( 'Ability "%s" must be undiscoverable once the caller\'s role is scoped to an empty allowlist.', $name )
			);
		}
	}

	public function test_a_role_scoped_to_one_ability_can_discover_only_that_one(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'subscriber',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$this->register_enabled( array_keys( aafm_get_abilities_registry() ) );
		$this->acting_as( 'subscriber' );

		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-posts' ) );

		$others = array_diff( array_keys( aafm_get_abilities_registry() ), array( 'aafm/get-posts' ) );
		foreach ( $others as $name ) {
			$this->assertFalse( aafm_user_can_discover_ability( $name ), sprintf( 'Ability "%s" must stay hidden.', $name ) );
		}
	}
}
