<?php
/**
 * Permission floor for aafm/wc-update-customer.
 *
 * The ability reads and overwrites any user's billing and shipping PII, so the flat
 * manage_woocommerce gate every other WooCommerce ability shares was the wrong floor for it: an
 * empty body is a documented no-op that returns the full customer shape, and it resolves any user
 * id, so manage_woocommerce alone was a read/write PII primitive over every account. These tests
 * pin the dedicated callback (edit_users floor plus per-object edit_user), the registry row that
 * has to point at it, and the discovery floor.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class WcUpdateCustomerPermissionTest extends TestCase {

	/**
	 * The permission_callback the registry row resolves to, via its args builder.
	 *
	 * @param string $name Ability name.
	 * @return mixed
	 */
	private function permission_callback_for( string $name ) {
		$args = call_user_func( 'aafm_args_wc_update_customer' );
		unset( $name );
		return $args['permission_callback'] ?? null;
	}

	public function test_the_registry_row_points_at_the_dedicated_callback(): void {
		$this->assertSame(
			'aafm_perm_wc_update_customer',
			$this->permission_callback_for( 'aafm/wc-update-customer' )
		);
	}

	/**
	 * The exact B2 attacker: manage_woocommerce, no user-management capability, a valid victim id
	 * in the body. Must be denied, closing both the PII read-through and the write.
	 */
	public function test_manage_woocommerce_without_edit_users_is_denied(): void {
		$victim = $this->factory->user->create( array( 'role' => 'customer' ) );

		$attacker = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $attacker )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $attacker );

		$this->assertFalse(
			aafm_perm_wc_update_customer( array( 'customer_id' => $victim ) ),
			'manage_woocommerce alone must not reach a customer PII record.'
		);
	}

	public function test_edit_users_without_the_woocommerce_floor_is_denied(): void {
		$victim = $this->factory->user->create( array( 'role' => 'customer' ) );

		$caller = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $caller )->add_cap( 'edit_users' );
		wp_set_current_user( $caller );

		$this->assertFalse( aafm_perm_wc_update_customer( array( 'customer_id' => $victim ) ) );
	}

	public function test_both_capabilities_together_are_allowed(): void {
		$victim = $this->factory->user->create( array( 'role' => 'customer' ) );

		$caller = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user   = get_user_by( 'id', $caller );
		$user->add_cap( 'manage_woocommerce' );
		$user->add_cap( 'edit_users' );
		wp_set_current_user( $caller );

		$this->assertTrue( aafm_perm_wc_update_customer( array( 'customer_id' => $victim ) ) );
	}

	/**
	 * The self short-circuit trap: edit_user($self) is true for every user against its own id, so a
	 * manage_woocommerce holder without edit_users must not reach its OWN account through the write.
	 */
	public function test_a_caller_cannot_reach_its_own_account_without_edit_users(): void {
		$caller = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $caller )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $caller );

		$this->assertFalse( aafm_perm_wc_update_customer( array( 'customer_id' => $caller ) ) );
	}

	public function test_empty_input_is_denied(): void {
		$caller = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user   = get_user_by( 'id', $caller );
		$user->add_cap( 'manage_woocommerce' );
		$user->add_cap( 'edit_users' );
		wp_set_current_user( $caller );

		$this->assertFalse( aafm_perm_wc_update_customer( array() ) );
	}

	public function test_a_logged_out_caller_is_denied(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( aafm_perm_wc_update_customer( array( 'customer_id' => 1 ) ) );
	}
}
