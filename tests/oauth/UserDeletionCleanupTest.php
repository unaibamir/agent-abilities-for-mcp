<?php
/**
 * Regression test for S2 of the 1.7.4 security assessment: OAuth grants used to
 * survive deletion of the WordPress user who approved them, leaving orphaned rows
 * with a wp_user_id that no longer resolves to anyone.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class UserDeletionCleanupTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	/**
	 * Insert a client row, if it does not already exist.
	 *
	 * Two users can share the same client, so tests that seed grants for more than one
	 * user against the same client_id must not insert the client row twice.
	 *
	 * @param string $client_id Client id.
	 * @return void
	 */
	private function ensure_client( string $client_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_clients WHERE client_id = %s",
				$client_id
			)
		);
		if ( $exists > 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => $client_id,
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
	}

	/**
	 * Seed one client with a full grant (consent, active token, pending code) for a user.
	 *
	 * @param string $client_id Client id.
	 * @param int    $user_id   Grant owner.
	 * @return void
	 */
	private function seed_grant( string $client_id, int $user_id ): void {
		global $wpdb;
		$this->ensure_client( $client_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $user_id,
				'client_id'  => $client_id,
			),
			array( '%d', '%s' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => hash( 'sha256', $client_id . $user_id . wp_rand() ),
				'refresh_hash' => hash( 'sha256', 'r' . $client_id . $user_id . wp_rand() ),
				'client_id'    => $client_id,
				'wp_user_id'   => $user_id,
				'is_active'    => 1,
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_codes',
			array(
				'code_hash'  => hash( 'sha256', $client_id . $user_id . wp_rand() ),
				'client_id'  => $client_id,
				'wp_user_id' => $user_id,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Count every consent, token and code row still owned by a user, across every client.
	 *
	 * @param int $user_id User to check.
	 * @return array{consents:int,tokens:int,codes:int}
	 */
	private function counts( int $user_id ): array {
		global $wpdb;
		return array(
			'consents' => (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
					"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d",
					$user_id
				)
			),
			'tokens'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
					"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE wp_user_id = %d AND is_active = 1",
					$user_id
				)
			),
			'codes'    => (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
					"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_codes WHERE wp_user_id = %d",
					$user_id
				)
			),
		);
	}

	/**
	 * S2: deleting the user must clear their consent, active-token and pending-code rows
	 * across every client they ever approved, not just one.
	 */
	public function test_deleting_a_user_clears_their_oauth_grants_across_every_client(): void {
		$victim = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->seed_grant( 'client_one', $victim );
		$this->seed_grant( 'client_two', $victim );

		$before = $this->counts( $victim );
		$this->assertSame(
			array(
				'consents' => 2,
				'tokens'   => 2,
				'codes'    => 2,
			),
			$before,
			'The grants must exist before deletion.'
		);

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $victim );

		$after = $this->counts( $victim );
		$this->assertSame(
			array(
				'consents' => 0,
				'tokens'   => 0,
				'codes'    => 0,
			),
			$after,
			'Deleting the user must clear every grant they held, for every client.'
		);
	}

	/**
	 * The direct primitive this test targets, isolated from wp_delete_user()'s own
	 * behaviour, so a future change to core's deletion flow cannot mask a regression here.
	 */
	public function test_cleanup_function_clears_a_users_grants_directly(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->seed_grant( 'client_one', $user );

		aafm_oauth_cleanup_deleted_user( $user );

		$this->assertSame(
			array(
				'consents' => 0,
				'tokens'   => 0,
				'codes'    => 0,
			),
			$this->counts( $user )
		);
	}

	/**
	 * A grant belonging to a DIFFERENT user for the same client must survive: the cleanup
	 * is scoped to the deleted user, not the client.
	 */
	public function test_cleanup_does_not_touch_another_users_grant_for_the_same_client(): void {
		$victim    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$bystander = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->seed_grant( 'client_shared', $victim );
		$this->seed_grant( 'client_shared', $bystander );

		aafm_oauth_cleanup_deleted_user( $victim );

		$this->assertSame(
			array(
				'consents' => 0,
				'tokens'   => 0,
				'codes'    => 0,
			),
			$this->counts( $victim )
		);
		$this->assertSame(
			array(
				'consents' => 1,
				'tokens'   => 1,
				'codes'    => 1,
			),
			$this->counts( $bystander ),
			"The bystander's own grant for the same client must be untouched."
		);
	}

	/**
	 * Pins the -1 vs 0 return contract commit a67208a introduced: a genuine query failure
	 * (here, the table itself is gone) must read as -1, never as the same 0 a real no-op
	 * returns. Without the fix these all read 0, indistinguishable from "nothing to revoke".
	 *
	 * Extended to the four N1 siblings (aafm_oauth_revoke_client_tokens(),
	 * aafm_oauth_revoke_user_client_tokens(), aafm_oauth_revoke_client_codes(),
	 * aafm_oauth_revoke_user_client_codes()), brought onto the same shape.
	 */
	public function test_revoke_helpers_report_negative_one_when_the_table_is_missing(): void {
		aafm_drop_oauth_tables();

		$this->assertSame( -1, aafm_oauth_revoke_user_tokens( 1 ), 'aafm_oauth_revoke_user_tokens() must report -1, not 0, on a missing table.' );
		$this->assertSame( -1, aafm_oauth_revoke_user_codes( 1 ), 'aafm_oauth_revoke_user_codes() must report -1, not 0, on a missing table.' );
		$this->assertSame( -1, aafm_oauth_revoke_client_tokens( 'client_one' ), 'aafm_oauth_revoke_client_tokens() must report -1, not 0, on a missing table.' );
		$this->assertSame( -1, aafm_oauth_revoke_user_client_tokens( 1, 'client_one' ), 'aafm_oauth_revoke_user_client_tokens() must report -1, not 0, on a missing table.' );
		$this->assertSame( -1, aafm_oauth_revoke_client_codes( 'client_one' ), 'aafm_oauth_revoke_client_codes() must report -1, not 0, on a missing table.' );
		$this->assertSame( -1, aafm_oauth_revoke_user_client_codes( 1, 'client_one' ), 'aafm_oauth_revoke_user_client_codes() must report -1, not 0, on a missing table.' );

		// Recreate the tables so later tests in the same run (which do not call set_up()
		// again mid-test) are not left against a dropped schema.
		aafm_install_oauth_tables();
	}

	/**
	 * The other half of the same contract: with the tables present and nothing to revoke,
	 * every helper must report a real 0, not the -1 failure sentinel. A fix that returned
	 * -1 unconditionally would pass the test above and fail this one.
	 */
	public function test_revoke_helpers_report_zero_for_a_genuine_no_op(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertSame( 0, aafm_oauth_revoke_user_tokens( $user ) );
		$this->assertSame( 0, aafm_oauth_revoke_user_codes( $user ) );
		$this->assertSame( 0, aafm_oauth_revoke_client_tokens( 'no_such_client' ) );
		$this->assertSame( 0, aafm_oauth_revoke_user_client_tokens( $user, 'no_such_client' ) );
		$this->assertSame( 0, aafm_oauth_revoke_client_codes( 'no_such_client' ) );
		$this->assertSame( 0, aafm_oauth_revoke_user_client_codes( $user, 'no_such_client' ) );
	}
}
