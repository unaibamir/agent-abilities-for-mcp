<?php
/**
 * Tests for the abandoned Dynamic-Client-Registration reaper's delete predicate.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Codex round 8, R8-3: the reaper's DELETEs used to trust the candidate list its own
 * SELECT scan produced, with no re-check at the deletion boundary - a client approved
 * in the window between the scan and the delete was removed anyway. The fix folds the
 * scan's exact predicate into the DELETE's own WHERE clause, so the database decides
 * from current data at delete time rather than from a stale PHP-side snapshot.
 *
 * A single, synchronous PHPUnit process cannot open a real window between this
 * function's internal scan and its internal delete - there is no in-process hook a
 * test can use to pause it (its own scan is a plain SELECT, which never fires a
 * trigger, and the fix deliberately leaves no SQL statement between the scan and the
 * delete for anything else to land in). These tests therefore prove the DELETE's
 * predicate is correct and matches the scan's - not that the race is closed. That was
 * instead proven empirically with two genuinely concurrent connections; see
 * .scratch/1-7-5-deferred/r83-race-harness/ (not committed - environment-specific).
 */
class ReapAbandonedClientsTest extends TestCase {

	/**
	 * Insert a DCR client row with an explicit created_at.
	 *
	 * @param string $client_id  Public client identifier.
	 * @param string $created_at UTC `Y-m-d H:i:s`.
	 * @return void
	 */
	private function seed_client( string $client_id, string $created_at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => $client_id,
				'client_name' => 'Test client',
				'created_at'  => $created_at,
				'is_active'   => 1,
			)
		);
	}

	/**
	 * Insert a consent row for a client.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $user_id   WP user ID.
	 * @return void
	 */
	private function seed_consent( string $client_id, int $user_id = 1 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $user_id,
				'client_id'  => $client_id,
			)
		);
	}

	/**
	 * Insert an access-token row for a client.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $is_active 1 for active, 0 for inactive (a revoked-but-historical token).
	 * @return void
	 */
	private function seed_token( string $client_id, int $is_active = 1 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => 'th_' . $client_id . '_' . $is_active,
				'refresh_hash' => 'rh_' . $client_id . '_' . $is_active,
				'client_id'    => $client_id,
				'is_active'    => $is_active,
			)
		);
	}

	/**
	 * Insert a stray, already-expired authorization code for a client.
	 *
	 * @param string $client_id Client identifier.
	 * @return void
	 */
	private function seed_code( string $client_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_codes',
			array(
				'code_hash'  => 'ch_' . $client_id,
				'client_id'  => $client_id,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);
	}

	/**
	 * Whether a client row still exists.
	 *
	 * @param string $client_id Client identifier.
	 * @return bool
	 */
	private function client_exists( string $client_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT client_id FROM {$wpdb->prefix}aafm_oauth_clients WHERE client_id = %s",
				$client_id
			)
		);
	}

	/**
	 * Whether a stray code row still exists for a client.
	 *
	 * @param string $client_id Client identifier.
	 * @return bool
	 */
	private function code_exists( string $client_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT id FROM {$wpdb->prefix}aafm_oauth_codes WHERE client_id = %s",
				$client_id
			)
		);
	}

	/**
	 * A genuinely abandoned client (old, no consent, no token) and its stray code are
	 * both reaped - the ordinary, non-raced path the function exists for.
	 */
	public function test_reaps_genuinely_abandoned_client_and_its_stray_code(): void {
		aafm_install_oauth_tables();

		$old = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
		$this->seed_client( 'abandoned', $old );
		$this->seed_code( 'abandoned' );

		$deleted = aafm_oauth_reap_abandoned_clients();

		$this->assertSame( 1, $deleted, 'Reap should report exactly one deleted client.' );
		$this->assertFalse( $this->client_exists( 'abandoned' ), 'Genuinely abandoned client should be reaped.' );
		$this->assertFalse( $this->code_exists( 'abandoned' ), 'Its stray code should be cleaned up too.' );
	}

	/**
	 * A client old enough to clear the TTL, but with an existing consent row, is kept -
	 * and so is a stray code it happens to still hold.
	 */
	public function test_keeps_old_client_with_existing_consent(): void {
		aafm_install_oauth_tables();

		$old = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
		$this->seed_client( 'consented', $old );
		$this->seed_consent( 'consented' );
		$this->seed_code( 'consented' );

		$deleted = aafm_oauth_reap_abandoned_clients();

		$this->assertSame( 0, $deleted, 'A consented client must not be counted as reaped.' );
		$this->assertTrue( $this->client_exists( 'consented' ), 'A client with consent must never be reaped, however old.' );
		$this->assertTrue( $this->code_exists( 'consented' ), 'Its code must not be swept up as a stray.' );
	}

	/**
	 * A client old enough to clear the TTL, but holding a token row (even an inactive,
	 * revoked-but-historical one), is kept.
	 */
	public function test_keeps_old_client_with_existing_token(): void {
		aafm_install_oauth_tables();

		$old = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
		$this->seed_client( 'tokened', $old );
		$this->seed_token( 'tokened', 0 );

		$deleted = aafm_oauth_reap_abandoned_clients();

		$this->assertSame( 0, $deleted );
		$this->assertTrue( $this->client_exists( 'tokened' ), 'A client with any token row, even inactive, must never be reaped.' );
	}

	/**
	 * A client inside the TTL window is never a candidate, consent or no consent.
	 */
	public function test_keeps_recent_client(): void {
		aafm_install_oauth_tables();

		$recent = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$this->seed_client( 'recent', $recent );

		$deleted = aafm_oauth_reap_abandoned_clients();

		$this->assertSame( 0, $deleted );
		$this->assertTrue( $this->client_exists( 'recent' ), 'A client inside the TTL must survive regardless of consent/token state.' );
	}

	/**
	 * The DELETE's re-check predicate must match the scan's exactly at the TTL
	 * boundary: strictly-older-than-cutoff is reaped, exactly-at-cutoff is kept - in
	 * both the SELECT and the DELETE, the same way.
	 */
	public function test_delete_predicate_matches_scan_predicate_at_ttl_boundary(): void {
		aafm_install_oauth_tables();

		add_filter(
			'aafm_oauth_client_reap_ttl',
			static function () {
				return DAY_IN_SECONDS;
			}
		);

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->seed_client( 'at_cutoff', $cutoff );
		$this->seed_client( 'past_cutoff', gmdate( 'Y-m-d H:i:s', strtotime( $cutoff ) - 1 ) );

		$deleted = aafm_oauth_reap_abandoned_clients();

		$this->assertSame( 1, $deleted, 'Only the strictly-older-than-cutoff client should be reaped.' );
		$this->assertTrue( $this->client_exists( 'at_cutoff' ), 'A client created exactly at the cutoff is kept (created_at < cutoff, not <=).' );
		$this->assertFalse( $this->client_exists( 'past_cutoff' ), 'A client older than the cutoff is reaped.' );
	}

	/**
	 * Two candidates in the same reap pass: only the one still genuinely abandoned at
	 * delete time loses its client row and its stray code. The survivor's code -
	 * scoped by the same NOT EXISTS(client) check the fix added to the codes delete -
	 * is left alone. Both start identically abandoned by the scan's own criteria; this
	 * isolates the DELETE clauses' own correctness from the SELECT's.
	 */
	public function test_codes_deletion_is_scoped_to_clients_actually_reaped(): void {
		aafm_install_oauth_tables();

		$old = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
		$this->seed_client( 'reaped', $old );
		$this->seed_code( 'reaped' );
		$this->seed_client( 'kept', $old );
		$this->seed_consent( 'kept' );
		$this->seed_code( 'kept' );

		$deleted = aafm_oauth_reap_abandoned_clients();

		$this->assertSame( 1, $deleted );
		$this->assertFalse( $this->client_exists( 'reaped' ) );
		$this->assertFalse( $this->code_exists( 'reaped' ) );
		$this->assertTrue( $this->client_exists( 'kept' ), 'A consented client is never reaped.' );
		$this->assertTrue( $this->code_exists( 'kept' ), 'Its code must not be deleted merely for sharing a reap pass with a real candidate.' );
	}
}
