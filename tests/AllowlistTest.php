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

	/**
	 * R2-4 sibling (1.7.5 deferred, round 2): this is a live authorization read, the opposite
	 * direction from a migration's certification read - a query that itself fails must not be
	 * read the same as "no override rows", which permits every call below. Restrictive rows are
	 * in place; faulting the direct SELECT aafm_read_option_views() issues must deny rather than
	 * silently grant unrestricted access for the duration of the outage. Fails if
	 * aafm_ability_allowed_for_principal() stops checking db_error and falls through to treating
	 * the failed read as an empty (unrestricted) row set.
	 */
	public function test_a_failed_read_denies_rather_than_grants_unrestricted_access(): void {
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

		add_filter(
			'query',
			static function ( string $query ): string {
				return false !== strpos( $query, "option_name = 'aafm_ability_allowlist_overrides'" )
					? 'SELECT * FROM aafm_missing_table_for_test'
					: $query;
			}
		);

		// The broken query above makes wpdb print its own HTML error block (the WP test bootstrap
		// turns wpdb::$show_errors on), which PHPUnit's output-during-test strictness flags as
		// risky even though the assertion below passes. Mirrors
		// UpgradeMigrationTest::test_dcr_adoption_aborts_when_the_guard_read_fails(), which wraps
		// the same fault-injection pattern the same way.
		ob_start();
		$result = aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null );
		ob_end_clean();

		$this->assertFalse(
			$result,
			'A read that itself fails must deny the call, not fall through to "no restriction".'
		);
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

	/**
	 * Codex final round 7 LOW, per 228-allowlist-design.md section 6: a row naming an ability
	 * slug absent from the registry (a name from a since-removed/renamed integration - the admin
	 * save no longer accepts one, see AllowlistAdminTest) must skip the whole row, degrading to
	 * unrestricted, never to deny-everything. Before this fix aafm_allowlist_set_permits() denied
	 * every real ability for the role, because the stale name matched none of them.
	 */
	public function test_a_row_referencing_an_ability_absent_from_the_registry_is_skipped(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'author',
					'allowed_abilities' => array( 'aafm/get-postz-typo' ),
				),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $user_id, null ) );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-posts', $user_id, null ) );
	}
}
