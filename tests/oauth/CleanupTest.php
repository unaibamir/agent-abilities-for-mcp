<?php
/**
 * Tests for the scheduled OAuth cleanup that prunes expired artifacts.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies aafm_oauth_cleanup() deletes expired authorization codes and dead
 * (inactive, past-grace) access-token rows while leaving live tokens — and rows
 * still inside the reuse-detection grace window — untouched.
 */
class CleanupTest extends TestCase {

	/**
	 * Insert an authorization code row with an explicit expiry.
	 *
	 * @param string $code_hash  Unique code hash.
	 * @param string $expires_at UTC `Y-m-d H:i:s` expiry.
	 * @return void
	 */
	private function seed_code( string $code_hash, string $expires_at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_codes',
			array(
				'code_hash'  => $code_hash,
				'client_id'  => 'client_abc',
				'wp_user_id' => 7,
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * Insert an access-token row with explicit lifecycle columns.
	 *
	 * @param string $token_hash         Unique token hash.
	 * @param int    $is_active          1 for live, 0 for inactive.
	 * @param string $expires_at         UTC `Y-m-d H:i:s` access expiry.
	 * @param string $refresh_expires_at UTC `Y-m-d H:i:s` refresh expiry.
	 * @return void
	 */
	private function seed_token( string $token_hash, int $is_active, string $expires_at, string $refresh_expires_at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'         => $token_hash,
				'refresh_hash'       => $token_hash . '_r',
				'client_id'          => 'client_abc',
				'wp_user_id'         => 7,
				'is_active'          => $is_active,
				'expires_at'         => $expires_at,
				'refresh_expires_at' => $refresh_expires_at,
			)
		);
	}

	/**
	 * Count code rows with the given hash.
	 *
	 * @param string $code_hash Code hash.
	 * @return int
	 */
	private function count_code( string $code_hash ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_codes WHERE code_hash = %s",
				$code_hash
			)
		);
	}

	/**
	 * Count token rows with the given token hash.
	 *
	 * @param string $token_hash Token hash.
	 * @return int
	 */
	private function count_token( string $token_hash ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE token_hash = %s",
				$token_hash
			)
		);
	}

	/**
	 * Expired codes and dead tokens are pruned; live tokens survive.
	 */
	public function test_cleanup_prunes_expired_code_and_dead_token_keeps_live(): void {
		aafm_install_oauth_tables();

		$now    = time();
		$past   = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$future = gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS );
		// Dead: inactive and well past refresh_expires_at + grace (grace defaults to one day).
		$well_past = gmdate( 'Y-m-d H:i:s', $now - ( 3 * DAY_IN_SECONDS ) );

		$this->seed_code( 'expired_code', $past );
		$this->seed_token( 'dead_token', 0, $past, $well_past );
		$this->seed_token( 'live_token', 1, $future, $future );

		aafm_oauth_cleanup();

		$this->assertSame( 0, $this->count_code( 'expired_code' ), 'Expired authorization code should be deleted.' );
		$this->assertSame( 0, $this->count_token( 'dead_token' ), 'Dead inactive token past grace should be deleted.' );
		$this->assertSame( 1, $this->count_token( 'live_token' ), 'Live active token should survive cleanup.' );
	}

	/**
	 * The grace window protects an inactive row whose refresh expiry is only
	 * slightly in the past, while a row past refresh_expires_at + grace is pruned.
	 */
	public function test_cleanup_respects_grace_window_both_ways(): void {
		aafm_install_oauth_tables();

		$now  = time();
		$past = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );

		// Inside grace: inactive, refresh expired only a minute ago (< one-day grace) → survives.
		$just_expired = gmdate( 'Y-m-d H:i:s', $now - MINUTE_IN_SECONDS );
		// Past grace: inactive, refresh expired more than a day ago → deleted.
		$beyond_grace = gmdate( 'Y-m-d H:i:s', $now - ( DAY_IN_SECONDS + HOUR_IN_SECONDS ) );

		$this->seed_token( 'inside_grace', 0, $past, $just_expired );
		$this->seed_token( 'beyond_grace', 0, $past, $beyond_grace );

		aafm_oauth_cleanup();

		$this->assertSame( 1, $this->count_token( 'inside_grace' ), 'Inactive token still inside the grace window should survive.' );
		$this->assertSame( 0, $this->count_token( 'beyond_grace' ), 'Inactive token past refresh_expires_at + grace should be deleted.' );
	}
}
