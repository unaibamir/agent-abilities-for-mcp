<?php
/**
 * On a multisite network the three health reads need a super admin, as core's Site Health and
 * update screens do. Runs only under tests/multisite.xml.dist.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

/**
 * The health reads on a multisite network.
 *
 * @group ms-required
 */
final class MsSiteHealthTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/site-health-status', 'aafm/site-list-updates', 'aafm/site-cron-status' ) );
	}

	/**
	 * A site administrator who is not a super admin is refused, before and after the grant.
	 *
	 * @param string $name Ability name.
	 * @return void
	 */
	private function assert_super_admin_only( string $name ): void {
		$user_id = $this->acting_as( 'administrator' );
		$this->assertTrue( current_user_can( 'manage_options' ), $name );
		$this->assertFalse( is_super_admin( $user_id ), $name );
		$this->assertNotTrue( wp_get_ability( $name )->check_permissions( array() ), $name );

		grant_super_admin( $user_id );
		$this->assertTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		revoke_super_admin( $user_id );
	}

	public function test_health_status_needs_a_super_admin_on_a_network(): void {
		$this->assert_super_admin_only( 'aafm/site-health-status' );
	}

	public function test_list_updates_needs_a_super_admin_on_a_network(): void {
		$this->assert_super_admin_only( 'aafm/site-list-updates' );
	}

	public function test_cron_status_needs_a_super_admin_on_a_network(): void {
		$this->assert_super_admin_only( 'aafm/site-cron-status' );
	}
}
