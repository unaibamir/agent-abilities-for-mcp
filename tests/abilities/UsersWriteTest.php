<?php
/**
 * User writes CRUD (Wave 2 Slice 2): create-user, update-user, delete-user.
 *
 * The most security-sensitive slice in Wave 2. These tests pin the privilege rails:
 * create forces the site default role (never a caller-chosen admin), update gates any
 * role change behind promote_users (with a last-admin demotion floor), and delete
 * requires a reassign target while refusing self-deletion and last-admin removal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class UsersWriteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
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

	public function test_create_user_requires_create_users_cap(): void {
		$this->acting_as( 'editor' ); // editor lacks create_users on single-site.
		$this->assertNotTrue(
			wp_get_ability( 'aafm/create-user' )->check_permissions( array() ),
			'create-user must require create_users.'
		);
	}

	public function test_create_user_creates_a_subscriber_by_default(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/create-user' )->execute(
			array(
				'username' => 'agent_new',
				'email'    => 'agent_new@example.com',
			)
		);
		$this->assertIsArray( $res );
		$new = get_user_by( 'login', 'agent_new' );
		$this->assertInstanceOf( \WP_User::class, $new );
		$this->assertContains( 'subscriber', (array) $new->roles, 'default role must be subscriber, not caller-chosen.' );
	}

	public function test_create_user_is_destructive_and_closed_schema(): void {
		$ability = wp_get_ability( 'aafm/create-user' );
		$ann     = $ability->get_meta_item( 'annotations' );
		$this->assertTrue( $ann['destructive'] );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
	}

	public function test_update_user_edits_profile_fields(): void {
		$this->acting_as( 'administrator' );
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$res = wp_get_ability( 'aafm/update-user' )->execute(
			array(
				'user_id'      => $uid,
				'display_name' => 'Renamed',
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 'Renamed', get_userdata( $uid )->display_name );
	}

	public function test_update_user_role_change_requires_promote_users(): void {
		// An editor can edit_user on lower users but must NOT promote roles (promote_users is admin).
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/update-user' )->execute(
			array(
				'user_id' => $author,
				'role'    => 'administrator',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'a non-admin must not change a role.' );
		$this->assertContains( 'author', (array) get_userdata( $author )->roles, 'role must be untouched.' );
	}

	public function test_update_user_is_not_destructive(): void {
		$ann = wp_get_ability( 'aafm/update-user' )->get_meta_item( 'annotations' );
		$this->assertFalse( $ann['destructive'], 'update-user is a recoverable edit, not destructive.' );
	}

	/**
	 * Reviewer note M2: refuse to demote the SOLE remaining administrator to a
	 * non-admin role. promote_users gates the role change itself; this floor sits on
	 * top so a capable admin can't lock the site out of administration by demoting
	 * the last admin (the mirror image of the delete-user last-admin guard).
	 */
	public function test_update_user_cannot_demote_the_last_administrator(): void {
		$admin = $this->acting_as( 'administrator' );
		// The WP test fixture seeds its own administrator (user 1), so reduce the
		// admin count to exactly one — the acting admin — before the demotion attempt.
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		) as $other_admin ) {
			if ( (int) $other_admin !== $admin ) {
				wp_update_user(
					array(
						'ID'   => (int) $other_admin,
						'role' => 'subscriber',
					)
				);
			}
		}
		$this->assertCount(
			1,
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			),
			'fixture must leave exactly one admin.'
		);

		// Demoting the sole remaining admin to editor would leave the site with no admin — refuse it.
		$res = wp_get_ability( 'aafm/update-user' )->execute(
			array(
				'user_id' => $admin,
				'role'    => 'editor',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'demoting the sole admin must be refused.' );
		$this->assertContains( 'administrator', (array) get_userdata( $admin )->roles, 'sole admin must stay an admin.' );
	}

	public function test_delete_user_requires_delete_users_and_reassign(): void {
		$this->acting_as( 'administrator' );
		$victim   = self::factory()->user->create( array( 'role' => 'author' ) );
		$reassign = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Missing reassign target → refused (orphaned-content guard), NOT a schema rejection.
		$res = wp_get_ability( 'aafm/delete-user' )->execute( array( 'user_id' => $victim ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'delete-user must require a reassign target.' );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $victim ), 'victim must survive the missing-reassign refusal.' );

		// With a reassign target → deleted.
		$res = wp_get_ability( 'aafm/delete-user' )->execute(
			array(
				'user_id'     => $victim,
				'reassign_to' => $reassign,
			)
		);
		$this->assertIsArray( $res );
		$this->assertFalse( get_userdata( $victim ), 'victim must be gone.' );
	}

	public function test_delete_user_cannot_delete_self(): void {
		$admin = $this->acting_as( 'administrator' );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		$res   = wp_get_ability( 'aafm/delete-user' )->execute(
			array(
				'user_id'     => $admin,
				'reassign_to' => $other,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'must never delete self.' );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $admin ) );
	}

	public function test_delete_user_is_destructive(): void {
		$ann = wp_get_ability( 'aafm/delete-user' )->get_meta_item( 'annotations' );
		$this->assertTrue( $ann['destructive'], 'delete-user is a permanent removal.' );
	}

	/**
	 * Reviewer note M1: prove the last-admin guard in ISOLATION from the self-guard.
	 *
	 * The actor must be capable (delete_users + delete_user) but must NOT be the victim,
	 * and the victim must be the sole remaining administrator. We grant the actor
	 * delete_users on a non-admin role so the administrator-role count sees only the
	 * victim — exercising the last-admin branch, never the self branch.
	 */
	public function test_delete_user_cannot_delete_the_sole_remaining_admin_when_actor_is_not_the_victim(): void {
		// Normalize the fixture to exactly one administrator: the victim.
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		) as $existing_admin ) {
			wp_update_user(
				array(
					'ID'   => (int) $existing_admin,
					'role' => 'subscriber',
				)
			);
		}
		$victim_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$reassign     = self::factory()->user->create( array( 'role' => 'editor' ) );

		// A SEPARATE actor (not the victim) who can delete users but is not an administrator,
		// so the administrator-role count below is exactly one (the victim).
		$actor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$actor_user = get_userdata( $actor );
		$actor_user->add_cap( 'delete_users' );
		$actor_user->add_cap( 'delete_user' );
		wp_set_current_user( $actor );

		$this->assertCount(
			1,
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			),
			'fixture must leave the victim as the only administrator.'
		);
		$this->assertNotSame( $actor, $victim_admin, 'actor must differ from the victim (isolate the last-admin branch).' );

		$res = wp_get_ability( 'aafm/delete-user' )->execute(
			array(
				'user_id'     => $victim_admin,
				'reassign_to' => $reassign,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'deleting the sole remaining admin must be refused even by another actor.' );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $victim_admin ), 'the last admin must survive.' );
	}
}
