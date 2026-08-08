<?php
/**
 * Multisite honesty contract for aafm/delete-user (finding B19).
 *
 * Core's wp_delete_user() on multisite does NOT delete the account: its multisite branch
 * only calls remove_user_from_blog() for the current site (wp-admin/includes/user.php),
 * so the network account, its other-site memberships, and its application passwords all
 * survive and keep authenticating. Returning {deleted:true} there is a lie an agent will
 * act on. The honest per-site contract is "removed from this site": deleted stays false
 * and removed_from_site says what actually happened. Escalating to wpmu_delete_user()
 * is deliberately NOT the fix - a per-site ability must not erase a network account.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it. The
 * single-site {"deleted":true} contract is pinned by UsersWriteTest.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_User;

final class MsDeleteUserContractTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		aafm_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	/**
	 * B19: on multisite the account survives the call, so the ability must not claim
	 * {deleted:true}. The exact wire body is pinned - deleted:false plus
	 * removed_from_site:true - and the survival of the network account is asserted
	 * alongside it, so the shape can never drift away from reality unnoticed.
	 */
	public function test_delete_user_reports_removed_from_site_not_deleted(): void {
		// On multisite only super admins hold delete_users (map_meta_cap sends everyone
		// else to do_not_allow), so the capable caller here is a super admin.
		$admin = $this->acting_as( 'administrator' );
		grant_super_admin( $admin );
		$victim   = self::factory()->user->create( array( 'role' => 'author' ) );
		$reassign = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( is_user_member_of_blog( $victim, get_current_blog_id() ), 'precondition: the victim is a member of this site.' );

		$res = wp_get_ability( 'aafm/delete-user' )->execute(
			array(
				'user_id'     => $victim,
				'reassign_to' => $reassign,
			)
		);

		$this->assertIsArray( $res );

		// What core actually did: removed from this site, account still on the network.
		$this->assertFalse( is_user_member_of_blog( $victim, get_current_blog_id() ), 'the user must be removed from the current site.' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $victim ), 'the network account survives wp_delete_user() on multisite.' );

		// The wire body must say exactly that.
		$this->assertSame(
			'{"deleted":false,"removed_from_site":true}',
			wp_json_encode( $res ),
			'on multisite the ability must report a site removal, never a deletion that did not happen.'
		);
	}

	/**
	 * The output schema must document removed_from_site, so the wire body above is a
	 * schema-described shape and not an undeclared extra an agent has no name for.
	 */
	public function test_delete_user_output_schema_documents_removed_from_site(): void {
		$args = aafm_args_delete_user();
		$this->assertArrayHasKey( 'removed_from_site', $args['output_schema']['properties'], 'output_schema must declare removed_from_site.' );
		$this->assertArrayHasKey( 'deleted', $args['output_schema']['properties'], 'output_schema must keep the single-site deleted property.' );
	}
}
