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

	/**
	 * An empty-string default_role option (get_option's fallback only fires when the option
	 * is ABSENT) must still floor to subscriber, never create a roleless user. The option
	 * change lives inside the test transaction - the suite rolls it back.
	 */
	public function test_create_user_floors_empty_default_role_to_subscriber(): void {
		$this->acting_as( 'administrator' );
		update_option( 'default_role', '' );
		$res = wp_get_ability( 'aafm/create-user' )->execute(
			array(
				'username' => 'empty_default',
				'email'    => 'empty_default@example.com',
			)
		);
		$this->assertIsArray( $res );
		$new = get_user_by( 'login', 'empty_default' );
		$this->assertInstanceOf( \WP_User::class, $new );
		$this->assertContains( 'subscriber', (array) $new->roles, 'an empty default_role must floor to subscriber, never roleless.' );
	}

	/**
	 * The invariant "an agent can never mint an admin" must hold even when the site's
	 * default_role option is itself elevated. A misconfigured (or maliciously set)
	 * default_role of 'administrator' must NOT pass straight to wp_insert_user - the
	 * resolved role floors to subscriber. The option change lives inside the test
	 * transaction, which the suite rolls back.
	 */
	public function test_create_user_floors_administrator_default_role_to_subscriber(): void {
		$this->acting_as( 'administrator' );
		update_option( 'default_role', 'administrator' );
		$res = wp_get_ability( 'aafm/create-user' )->execute(
			array(
				'username' => 'admin_default',
				'email'    => 'admin_default@example.com',
			)
		);
		$this->assertIsArray( $res );
		$new = get_user_by( 'login', 'admin_default' );
		$this->assertInstanceOf( \WP_User::class, $new );
		$this->assertNotContains( 'administrator', (array) $new->roles, 'an administrator default_role must never mint an admin.' );
		$this->assertContains( 'subscriber', (array) $new->roles, 'an elevated default_role must floor to subscriber.' );
	}

	/**
	 * The aafm/create-user ability is an agent minting an arbitrary WordPress user (content),
	 * NOT the operator's dedicated-agent-user onboarding flow. It must never stamp the
	 * plugin-created marker, or an agent could forge the "an agent user exists" onboarding
	 * signal. Only aafm_create_agent_user() stamps.
	 */
	public function test_create_user_ability_does_not_stamp_the_agent_marker(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/create-user' )->execute(
			array(
				'username' => 'content_user',
				'email'    => 'content_user@example.com',
			)
		);
		$this->assertIsArray( $res );
		$new = get_user_by( 'login', 'content_user' );
		$this->assertInstanceOf( \WP_User::class, $new );
		$this->assertSame( '', (string) get_user_meta( (int) $new->ID, aafm_agent_user_marker_meta_key(), true ) );
		$this->assertFalse( aafm_has_created_agent_user() );
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

	/**
	 * Role escalation guard: promote_users alone is not enough. WP core (the REST users
	 * controller and wp-admin) also requires the target role to be in get_editable_roles(),
	 * which the editable_roles filter can prune. A capable administrator whose editable_roles
	 * has had 'administrator' removed must be refused when assigning that role - proving the
	 * ability honors the same delegation boundary core does, not just the global cap.
	 */
	public function test_update_user_rejects_role_excluded_by_editable_roles(): void {
		$this->acting_as( 'administrator' );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$drop_admin = static function ( array $roles ): array {
			unset( $roles['administrator'] );
			return $roles;
		};
		add_filter( 'editable_roles', $drop_admin );
		try {
			$res = wp_get_ability( 'aafm/update-user' )->execute(
				array(
					'user_id' => $author,
					'role'    => 'administrator',
				)
			);
		} finally {
			remove_filter( 'editable_roles', $drop_admin );
		}

		$this->assertInstanceOf( WP_Error::class, $res, 'a role pruned from editable_roles must be refused even for an admin.' );
		$this->assertContains( 'author', (array) get_userdata( $author )->roles, 'the role must be untouched.' );
		$this->assertNotContains( 'administrator', (array) get_userdata( $author )->roles, 'the forbidden role must never be assigned.' );
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
		// admin count to exactly one - the acting admin - before the demotion attempt.
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

		// Demoting the sole remaining admin to editor would leave the site with no admin - refuse it.
		$res = wp_get_ability( 'aafm/update-user' )->execute(
			array(
				'user_id' => $admin,
				'role'    => 'editor',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'demoting the sole admin must be refused.' );
		$this->assertContains( 'administrator', (array) get_userdata( $admin )->roles, 'sole admin must stay an admin.' );
	}

	/**
	 * T3-5: with more than one administrator, demoting one IS allowed and runs through the
	 * last-admin critical section without breaking the happy path.
	 */
	public function test_update_user_demote_allowed_when_other_admins_remain(): void {
		$this->acting_as( 'administrator' );
		$victim = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->assertGreaterThanOrEqual( 2, aafm_count_administrators(), 'fixture needs two or more admins.' );

		$res = wp_get_ability( 'aafm/update-user' )->execute(
			array(
				'user_id' => $victim,
				'role'    => 'editor',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'a demote with other admins present must succeed.' );
		$this->assertContains( 'editor', (array) get_userdata( $victim )->roles );
		$this->assertNotContains( 'administrator', (array) get_userdata( $victim )->roles );
	}

	/**
	 * T3-5: the named-lock critical-section helper runs its callback and returns its value
	 * (and releases the lock so a second acquisition succeeds in the same process).
	 */
	public function test_named_lock_runs_callback_and_releases(): void {
		$ran = false;
		$out = aafm_with_named_lock(
			'last_admin',
			static function () use ( &$ran ) {
				$ran = true;
				return 'done';
			}
		);
		$this->assertTrue( $ran, 'the critical section must run.' );
		$this->assertSame( 'done', $out, 'the helper must return the callback value.' );

		// A second acquisition must still succeed (the first lock was released).
		$out2 = aafm_with_named_lock( 'last_admin', static fn() => 'again' );
		$this->assertSame( 'again', $out2 );
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
	 * victim - exercising the last-admin branch, never the self branch.
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

	/**
	 * Every sibling write slice proves a denied call writes a 'denied' audit row - the
	 * wrapper's denial logging is the accountability contract. Pin it for the three user
	 * writes: a subscriber (no create_users/edit_user/delete_user) is refused at the
	 * permission layer and each refusal lands in the activity log as 'denied'.
	 */
	public function test_user_writes_denial_is_audited(): void {
		$target = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as( 'subscriber' );
		aafm_clear_activity_log();

		$this->assertFalse(
			wp_get_ability( 'aafm/create-user' )->check_permissions(
				array(
					'username' => 'denied_user',
					'email'    => 'denied_user@example.com',
				)
			)
		);
		$this->assertFalse(
			wp_get_ability( 'aafm/update-user' )->check_permissions(
				array(
					'user_id'      => $target,
					'display_name' => 'Nope',
				)
			)
		);
		$this->assertFalse(
			wp_get_ability( 'aafm/delete-user' )->check_permissions(
				array(
					'user_id'     => $target,
					'reassign_to' => 1,
				)
			)
		);

		$denied    = aafm_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 200,
			)
		);
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/create-user', $abilities, 'a denied create-user must write a denied audit row.' );
		$this->assertContains( 'aafm/update-user', $abilities, 'a denied update-user must write a denied audit row.' );
		$this->assertContains( 'aafm/delete-user', $abilities, 'a denied delete-user must write a denied audit row.' );
	}

	/**
	 * A low-privileged user must not reach update-user on its OWN account. Core's edit_user
	 * meta cap is true for self (every user may edit their own profile), so gating on that
	 * alone let a subscriber change its own email through wp_update_user() and bypass the
	 * pending-email confirmation flow. The gate now also requires the object-independent
	 * edit_users cap, matching create-user and delete-user.
	 */
	public function test_update_user_denied_for_low_priv_self_target(): void {
		$self = $this->acting_as( 'subscriber' );

		// The exact hole: a subscriber CAN edit its own profile but holds no edit_users cap.
		$this->assertTrue( current_user_can( 'edit_user', $self ), 'self edit_user is true - the pre-fix bypass.' );
		$this->assertFalse( current_user_can( 'edit_users' ) );

		$this->assertFalse(
			aafm_perm_update_user( array( 'user_id' => $self ) ),
			'a subscriber must not reach update-user on its own account.'
		);
		$this->assertFalse(
			wp_get_ability( 'aafm/update-user' )->check_permissions(
				array(
					'user_id' => $self,
					'email'   => 'agent_self@example.com',
				)
			),
			'the update-user ability must deny a low-priv self target.'
		);
	}

	/**
	 * A legitimate edit_users holder is unaffected: an administrator may update both another
	 * account and its own through the ability.
	 */
	public function test_update_user_allowed_for_edit_users_holder(): void {
		$self   = $this->acting_as( 'administrator' );
		$target = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertTrue(
			aafm_perm_update_user( array( 'user_id' => $target ) ),
			'an edit_users holder may update another account.'
		);
		$this->assertTrue(
			aafm_perm_update_user( array( 'user_id' => $self ) ),
			'an edit_users holder may update its own account.'
		);
	}

	/**
	 * The headline guarantee: create-user can never mint a privileged account. Even when a
	 * full administrator smuggles a role => 'administrator' field, the closed schema strips
	 * the unknown key (or rejects it) and the new user is forced to the site default role.
	 * The created account must NEVER come back an administrator.
	 */
	public function test_create_user_cannot_mint_an_administrator_via_smuggled_role(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/create-user' )->execute(
			array(
				'username' => 'smuggle_attempt',
				'email'    => 'smuggle_attempt@example.com',
				'role'     => 'administrator',
			)
		);

		// Either the closed schema rejected the smuggled field, or it was stripped and the
		// user was created at the forced default. Either way: never an administrator.
		if ( $res instanceof WP_Error ) {
			$this->assertFalse( get_user_by( 'login', 'smuggle_attempt' ), 'a rejected create must not leave a user behind.' );
			return;
		}

		$new = get_user_by( 'login', 'smuggle_attempt' );
		$this->assertInstanceOf( \WP_User::class, $new );
		$this->assertNotContains( 'administrator', (array) $new->roles, 'a smuggled role must never mint an administrator.' );
		$this->assertContains( 'subscriber', (array) $new->roles, 'the forced default role must win.' );
	}

	public function test_user_writes_discoverable_by_capable_admin_only(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/create-user' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-user' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-user' ) );

		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/create-user' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-user' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/delete-user' ) );
	}
}
