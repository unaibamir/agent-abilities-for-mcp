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

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class UsersWriteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
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

	/**
	 * Demote every administrator, then make a sole administrator victim, a reassign target and a
	 * separate editor actor who can delete users, and act as that editor.
	 *
	 * @return array{0:int,1:int} The victim and the reassign target.
	 */
	private function sole_admin_victim(): array {
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
		$victim   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$reassign = self::factory()->user->create( array( 'role' => 'editor' ) );
		$actor    = get_userdata( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$actor->add_cap( 'delete_users' );
		$actor->add_cap( 'delete_user' );
		wp_set_current_user( $actor->ID );
		$this->assertSame( 1, aafm_count_administrators(), 'fixture must leave the victim as the only administrator.' );
		return array( $victim, $reassign );
	}

	/**
	 * The target's roles, read from a fresh load after every fault is gone.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	private function stored_roles( int $user_id ): array {
		wp_cache_delete( $user_id, 'user_meta' );
		return (array) get_userdata( $user_id )->roles;
	}

	/**
	 * W2-T3 (step 14, row U1): the update-user target load runs inside the checked-read scope. When
	 * the target's caps load fails after the permission gate, the call refuses instead of reading
	 * the sole administrator as holding no role and demoting them. The gate's own scoped read is
	 * served from the cache; the target's meta entry is dropped as the gate finishes, the shape a
	 * cache that did not keep the gate's rows leaves behind.
	 */
	public function test_update_user_refuses_when_the_targets_load_faults_after_the_gate(): void {
		global $wpdb;
		$admin = $this->acting_as( 'administrator' );
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
		$this->assertSame( 1, aafm_count_administrators(), 'fixture must leave exactly one admin.' );
		get_userdata( $admin );

		$drop = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $admin ): array {
			if ( 'promote_user' === $cap && isset( $args[0] ) && $admin === (int) $args[0] ) {
				wp_cache_delete( $admin, 'user_meta' );
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $drop, 10, 4 );
		QueryFaultInjector::reset_fired_count();
		try {
			$res = QueryFaultInjector::break_query_with_real_error(
				array( $wpdb->usermeta, "user_id IN ({$admin})" ),
				static fn() => aafm_exec_update_user(
					array(
						'user_id' => $admin,
						'role'    => 'editor',
					)
				)
			);
		} finally {
			remove_filter( 'map_meta_cap', $drop, 10 );
		}

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count(), 'the target load must have faulted.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertContains( 'administrator', $this->stored_roles( $admin ), 'the sole admin must stay an admin.' );
	}

	/**
	 * W2-T4 (step 14, row U2): the delete-user victim load runs inside the checked-read scope. When
	 * the victim's caps load fails at exec time, the call refuses instead of reading the sole
	 * administrator as holding no role and deleting them.
	 */
	public function test_delete_user_refuses_when_the_victims_load_faults(): void {
		global $wpdb;
		list( $victim, $reassign ) = $this->sole_admin_victim();
		wp_cache_delete( $victim, 'user_meta' );

		QueryFaultInjector::reset_fired_count();
		$res = QueryFaultInjector::break_query_with_real_error(
			array( $wpdb->usermeta, "user_id IN ({$victim})" ),
			static fn() => aafm_exec_delete_user(
				array(
					'user_id'     => $victim,
					'reassign_to' => $reassign,
				)
			)
		);

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count(), 'the victim load must have faulted.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertContains( 'administrator', $this->stored_roles( $victim ), 'the last admin must survive.' );
	}

	/**
	 * W2-T8 (step 14, row U5): a healthy delete of the only administrator keeps its exact refusal.
	 */
	public function test_delete_user_of_the_only_administrator_keeps_its_exact_refusal(): void {
		list( $victim, $reassign ) = $this->sole_admin_victim();

		$res = wp_get_ability( 'aafm/delete-user' )->execute(
			array(
				'user_id'     => $victim,
				'reassign_to' => $reassign,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'The request could not be completed.', $res->get_error_message() );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $victim ), 'the last admin must survive.' );
	}

	/**
	 * R-T1 (U-R row R6, ledger b5huntb-2): default_role is out of the runtime alloptions and
	 * per-option copies, and get_option()'s own SELECT fails, so notoptions sits over a found row.
	 * create-user refuses rather than create a subscriber the stored row does not name.
	 */
	public function test_create_user_refuses_when_the_default_role_read_fails(): void {
		$this->with_restricted_default_role(
			function () {
				$this->drop_runtime_default_role();
				$res = QueryFaultInjector::break_query_with_real_error(
					"option_name = 'default_role'",
					fn() => $this->create_default_user( 'rt_one' )
				);
				$this->assertGreaterThan( 0, QueryFaultInjector::fired_count(), "get_option()'s SELECT must have faulted." );
				$this->assert_refused( $res, 'rt_one' );
			}
		);
	}

	/**
	 * R-T2 (R7): every default_role read fails, get_option()'s and the batched one. The failed
	 * batch also fails the permission check closed, so this calls the exec body directly to reach
	 * the role read.
	 */
	public function test_create_user_refuses_when_every_default_role_read_fails(): void {
		$this->with_restricted_default_role(
			function () {
				$this->drop_runtime_default_role();
				$res = QueryFaultInjector::break_query_with_real_error(
					array( 'SELECT', "'default_role'" ),
					static fn() => aafm_exec_create_user(
						array(
							'username' => 'rt_two',
							'email'    => 'rt_two@example.com',
						)
					)
				);
				$this->assertGreaterThan( 0, QueryFaultInjector::fired_count(), 'the default_role reads must have faulted.' );
				$this->assert_refused( $res, 'rt_two' );
			}
		);
	}

	/**
	 * R-T3 (R8): a stale runtime alloptions 'subscriber' over the stored restricted role.
	 */
	public function test_create_user_refuses_a_stale_cached_default_role(): void {
		$this->with_restricted_default_role(
			function () {
				$this->plant_runtime_default_role( 'subscriber' );
				$this->assert_refused( $this->create_default_user( 'rt_three' ), 'rt_three' );
			}
		);
	}

	/**
	 * R-T7 (R9): a stale alloptions 'author', not the default, over the stored restricted role.
	 */
	public function test_create_user_refuses_a_stale_non_default_role(): void {
		$this->with_restricted_default_role(
			function () {
				$this->plant_runtime_default_role( 'author' );
				$this->assert_refused( $this->create_default_user( 'rt_seven' ), 'rt_seven' );
			}
		);
	}

	/**
	 * R-T4 (R5): an option_default_role filter still decides the role on a healthy database; the
	 * stored row never supplies it.
	 */
	public function test_a_default_role_filter_still_decides_the_role(): void {
		$this->use_batched_policy_path();
		$this->acting_as( 'administrator' );
		update_option( 'default_role', 'editor' );
		$filter = static fn() => 'subscriber';
		add_filter( 'option_default_role', $filter );
		$res    = $this->create_default_user( 'rt_four' );
		remove_filter( 'option_default_role', $filter );

		$this->assertIsArray( $res );
		$this->assertSame( array( 'subscriber' ), $this->stored_roles( (int) get_user_by( 'login', 'rt_four' )->ID ) );
	}

	/**
	 * R-T5 (R1): a healthy stored default role, with alloptions agreeing, is the role created.
	 */
	public function test_create_user_uses_a_healthy_stored_default_role(): void {
		$this->use_batched_policy_path();
		$this->acting_as( 'administrator' );
		update_option( 'default_role', 'author' );
		$res = $this->create_default_user( 'rt_five' );

		$this->assertIsArray( $res );
		$this->assertSame( array( 'author' ), $this->stored_roles( (int) get_user_by( 'login', 'rt_five' )->ID ) );
	}

	/**
	 * R-T6 (R4): no default_role row and nothing cached creates a subscriber.
	 */
	public function test_create_user_with_no_default_role_row_creates_a_subscriber(): void {
		$this->use_batched_policy_path();
		$this->acting_as( 'administrator' );
		delete_option( 'default_role' );
		$res = $this->create_default_user( 'rt_six' );

		$this->assertIsArray( $res );
		$this->assertSame( array( 'subscriber' ), $this->stored_roles( (int) get_user_by( 'login', 'rt_six' )->ID ) );
	}

	/**
	 * R-T8 (R11): a filter answering 'administrator' still floors to subscriber.
	 */
	public function test_a_default_role_filter_answering_administrator_still_floors_to_subscriber(): void {
		$this->use_batched_policy_path();
		$this->acting_as( 'administrator' );
		update_option( 'default_role', 'author' );
		$filter = static fn() => 'administrator';
		add_filter( 'option_default_role', $filter );
		$res    = $this->create_default_user( 'rt_eight' );
		remove_filter( 'option_default_role', $filter );

		$this->assertIsArray( $res );
		$this->assertSame( array( 'subscriber' ), $this->stored_roles( (int) get_user_by( 'login', 'rt_eight' )->ID ) );
	}

	/**
	 * Run $test as an MCP REST request with default_role stored as an editable role that carries
	 * no capabilities, removed again after.
	 *
	 * @param callable $test The test body.
	 */
	private function with_restricted_default_role( callable $test ): void {
		add_role( 'aafm_s14_restricted', 'Restricted', array() );
		try {
			$this->use_batched_policy_path();
			$this->acting_as( 'administrator' );
			update_option( 'default_role', 'aafm_s14_restricted' );
			QueryFaultInjector::reset_fired_count();
			$test();
		} finally {
			remove_role( 'aafm_s14_restricted' );
		}
	}

	/**
	 * Policy reads take the batched path, as on an MCP REST request.
	 */
	private function use_batched_policy_path(): void {
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		aafm_policy_reset_request_state();
	}

	/**
	 * Remove default_role from the runtime alloptions and per-option copies, so get_option() reads
	 * the row itself.
	 */
	private function drop_runtime_default_role(): void {
		$all = wp_load_alloptions();
		unset( $all['default_role'] );
		wp_cache_set( 'alloptions', $all, 'options' );
		wp_cache_delete( 'default_role', 'options' );
	}

	/**
	 * Put $role in the runtime alloptions copy of default_role, leaving the row alone.
	 *
	 * @param string $role The stale role.
	 */
	private function plant_runtime_default_role( string $role ): void {
		$all                 = wp_load_alloptions();
		$all['default_role'] = $role;
		wp_cache_set( 'alloptions', $all, 'options' );
	}

	/**
	 * Run create-user with no role in the input.
	 *
	 * @param string $login Username.
	 * @return mixed
	 */
	private function create_default_user( string $login ) {
		return wp_get_ability( 'aafm/create-user' )->execute(
			array(
				'username' => $login,
				'email'    => $login . '@example.com',
			)
		);
	}

	/**
	 * The call refused with the generic error and created nobody.
	 *
	 * @param mixed  $res   The ability result.
	 * @param string $login The username it would have created.
	 */
	private function assert_refused( $res, string $login ): void {
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertFalse( username_exists( $login ), 'no user may be created.' );
	}
}
