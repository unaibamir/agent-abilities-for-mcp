<?php
/**
 * Storage, precedence, and fail-closed behavior for aafm_ability_allowed_for_principal().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AllowlistTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_ability_allowlist_overrides' );
	}

	public function test_no_override_rows_means_unrestricted_by_this_layer(): void {
		delete_option( 'aafm_ability_allowlist_overrides' );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', 1, null ) );
	}

	public function test_a_role_override_restricts_to_its_listed_abilities(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'author',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-posts', $user_id, null ) );
		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null ) );
	}

	public function test_a_role_the_user_does_not_hold_imposes_no_restriction(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'author',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null ) );
	}

	public function test_multi_role_user_gets_the_union_of_matching_role_rows(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'author',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/update-post' ),
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $user_id )->add_role( 'editor' );

		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-posts', $user_id, null ) );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/update-post', $user_id, null ) );
		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null ) );
	}

	public function test_intersection_both_role_and_client_must_permit(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => 'all',
				),
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => 'client-1',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Role is unrestricted ("all"), but the client row still narrows - both must permit.
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-posts', $user_id, 'client-1' ) );
		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, 'client-1' ) );
		// Without the client (a cookie session, say), only the role row (unrestricted) applies.
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null ) );
	}

	public function test_a_client_restriction_is_not_overridden_by_an_unrestricted_role(): void {
		// The exact fail-open shape the intersection design (Amendment 2) exists to prevent: a
		// role-level allow must never silently overrule a deliberately narrower client row.
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => 'restricted-client',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, 'restricted-client' ) );
	}

	public function test_a_malformed_row_referencing_a_non_array_non_all_value_fails_closed_not_open(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'author',
					'allowed_abilities' => 'not-an-array',
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		// The malformed row is skipped entirely, degrading to "no restriction from this scope",
		// never to "restriction lifted for every scope".
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null ) );
	}

	public function test_an_unrestricted_all_row_permits_everything_from_that_scope(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => 'trusted-client',
					'allowed_abilities' => 'all',
				),
			)
		);
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', 0, 'trusted-client' ) );
	}
}
