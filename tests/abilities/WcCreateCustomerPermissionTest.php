<?php
/**
 * Permission floor for aafm/wc-create-customer.
 *
 * The ability creates a real WordPress user account through wc_create_new_customer(), so the flat
 * manage_woocommerce gate every other WooCommerce ability shares is the wrong floor for it. These
 * tests pin the dedicated callback, the registry row that has to point at it, and the sibling rows
 * that must keep the shared floor untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class WcCreateCustomerPermissionTest extends TestCase {

	/**
	 * The permission_callback the registry row resolves to, via its args builder.
	 *
	 * The registry row carries label/description/group/risk/subject/args_builder; the callback
	 * itself lives in the args builder's output (includes/register.php:253-256 is what reads it),
	 * so that is where the assertion has to look.
	 *
	 * @param string $name Ability name.
	 * @return mixed
	 */
	private function permission_callback_for( string $name ) {
		$registry = aafm_get_abilities_registry_full();
		if ( empty( $registry[ $name ]['args_builder'] ) || ! is_callable( $registry[ $name ]['args_builder'] ) ) {
			return null;
		}
		$args = call_user_func( $registry[ $name ]['args_builder'] );

		return $args['permission_callback'] ?? null;
	}

	public function test_manage_woocommerce_alone_is_not_enough(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		$this->assertFalse(
			aafm_perm_wc_create_customer(),
			'manage_woocommerce must no longer be sufficient to create a WordPress account.'
		);
	}

	public function test_create_users_alone_is_not_enough(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'create_users' );
		wp_set_current_user( $user_id );

		$this->assertFalse( aafm_perm_wc_create_customer() );
	}

	public function test_both_capabilities_together_are_allowed(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_woocommerce' );
		$user->add_cap( 'create_users' );
		wp_set_current_user( $user_id );

		$this->assertTrue( aafm_perm_wc_create_customer() );
	}

	public function test_an_administrator_is_unaffected(): void {
		// WooCommerce grants administrators manage_woocommerce on activation; the stock WP
		// administrator role does not carry it, so mirror that here (same reasoning as
		// IntegrationStubs::stub_woocommerce). The role write is rolled back by the
		// transaction-isolated fixture.
		$admin_role = get_role( 'administrator' );
		if ( null !== $admin_role && ! $admin_role->has_cap( 'manage_woocommerce' ) ) {
			$admin_role->add_cap( 'manage_woocommerce' );
		}

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( aafm_perm_wc_create_customer() );
	}

	public function test_a_logged_out_caller_is_denied(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( aafm_perm_wc_create_customer() );
	}

	public function test_the_registry_row_actually_uses_the_new_callback(): void {
		$this->assertSame(
			'aafm_perm_wc_create_customer',
			$this->permission_callback_for( 'aafm/wc-create-customer' ),
			'The fix is only real if the registry row points at it.'
		);
	}

	public function test_no_other_woocommerce_ability_was_changed(): void {
		foreach ( array( 'aafm/wc-list-orders', 'aafm/wc-update-customer' ) as $name ) {
			$this->assertSame(
				'aafm_wc_perm',
				$this->permission_callback_for( $name ),
				"{$name} must keep the shared WooCommerce permission floor."
			);
		}
	}

	/**
	 * These two are a later, separate fix from a PII-exposure audit finding, not this file's
	 * create_users gap: manage_woocommerce alone let a caller read PII for any
	 * WordPress user, not just customers. Both now require list_users on top, via the shared
	 * aafm_perm_wc_customer_pii_read() (customers.php) - pinned here, not in the "unchanged" list
	 * above, because they deliberately did change.
	 */
	public function test_customer_pii_reads_use_the_list_users_gate(): void {
		foreach ( array( 'aafm/wc-list-customers', 'aafm/wc-get-customer' ) as $name ) {
			$this->assertSame(
				'aafm_perm_wc_customer_pii_read',
				$this->permission_callback_for( $name ),
				"{$name} must use the PII-read floor (manage_woocommerce + list_users)."
			);
		}
	}
}
