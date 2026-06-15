<?php
/**
 * Tests for OAuth authorization codes: hashed storage, short TTL, one-time use.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies that authorization codes are stored hashed, expire in 60 seconds, and
 * can be redeemed exactly once against the matching client and redirect URI.
 */
class CodesTest extends TestCase {

	/**
	 * A representative mint context. Override individual keys per test.
	 *
	 * @return array<string,mixed>
	 */
	private function ctx(): array {
		return array(
			'client_id'      => 'client_abc',
			'wp_user_id'     => 42,
			'redirect_uri'   => 'https://app.example/cb',
			'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			'resource'       => 'https://site.example/wp-json/aafm/v1/mcp',
		);
	}

	/**
	 * Count rows whose code_hash exactly equals the given value.
	 *
	 * The WordPress test suite rewrites plugin `CREATE TABLE` to its `TEMPORARY`
	 * form, so each DB test must call aafm_install_oauth_tables() first and read
	 * the row back — the temporary table is invisible to `SHOW TABLES`.
	 *
	 * @param string $code_hash Value to match against the code_hash column.
	 * @return int
	 */
	private function count_by_hash( string $code_hash ): int {
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
	 * Minting returns a 64-hex raw code and stores only its SHA-256 hash.
	 */
	public function test_mint_returns_hex_and_stores_hash_not_raw(): void {
		aafm_install_oauth_tables();

		$raw = aafm_oauth_mint_code( $this->ctx() );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $raw );

		// The hash of the raw is stored.
		$this->assertSame( 1, $this->count_by_hash( hash( 'sha256', $raw ) ) );
		// The raw itself is never stored in clear.
		$this->assertSame( 0, $this->count_by_hash( $raw ) );
	}

	/**
	 * A fresh code redeemed with the right client and redirect returns the row.
	 */
	public function test_redeem_fresh_code_returns_row(): void {
		aafm_install_oauth_tables();

		$ctx = $this->ctx();
		$raw = aafm_oauth_mint_code( $ctx );

		$row = aafm_oauth_redeem_code( $raw, $ctx['client_id'], $ctx['redirect_uri'] );

		$this->assertIsArray( $row );
		$this->assertSame( (int) $ctx['wp_user_id'], (int) $row['wp_user_id'] );
		$this->assertSame( $ctx['client_id'], $row['client_id'] );
		$this->assertSame( $ctx['code_challenge'], $row['code_challenge'] );
		$this->assertSame( $ctx['resource'], $row['resource'] );
	}

	/**
	 * The same code cannot be redeemed twice (atomic one-time use).
	 */
	public function test_redeem_is_one_time_use(): void {
		aafm_install_oauth_tables();

		$ctx = $this->ctx();
		$raw = aafm_oauth_mint_code( $ctx );

		$first = aafm_oauth_redeem_code( $raw, $ctx['client_id'], $ctx['redirect_uri'] );
		$this->assertIsArray( $first );

		$second = aafm_oauth_redeem_code( $raw, $ctx['client_id'], $ctx['redirect_uri'] );
		$this->assertInstanceOf( WP_Error::class, $second );
	}

	/**
	 * An expired code cannot be redeemed.
	 *
	 * Expiry is simulated by writing a past UTC timestamp directly onto the
	 * transaction-isolated temporary row — no sleeping.
	 */
	public function test_redeem_expired_code_fails(): void {
		aafm_install_oauth_tables();

		$ctx  = $this->ctx();
		$raw  = aafm_oauth_mint_code( $ctx );
		$hash = hash( 'sha256', $raw );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_codes',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'code_hash' => $hash ),
			array( '%s' ),
			array( '%s' )
		);

		$res = aafm_oauth_redeem_code( $raw, $ctx['client_id'], $ctx['redirect_uri'] );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Redeeming with the wrong client_id fails.
	 */
	public function test_redeem_wrong_client_fails(): void {
		aafm_install_oauth_tables();

		$ctx = $this->ctx();
		$raw = aafm_oauth_mint_code( $ctx );

		$res = aafm_oauth_redeem_code( $raw, 'other_client', $ctx['redirect_uri'] );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Redeeming with the wrong redirect_uri fails.
	 */
	public function test_redeem_wrong_redirect_fails(): void {
		aafm_install_oauth_tables();

		$ctx = $this->ctx();
		$raw = aafm_oauth_mint_code( $ctx );

		$res = aafm_oauth_redeem_code( $raw, $ctx['client_id'], 'https://app.example/other' );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Redeeming a code that was never minted fails.
	 */
	public function test_redeem_unknown_code_fails(): void {
		aafm_install_oauth_tables();

		$ctx = $this->ctx();
		$raw = bin2hex( random_bytes( 32 ) );

		$res = aafm_oauth_redeem_code( $raw, $ctx['client_id'], $ctx['redirect_uri'] );
		$this->assertInstanceOf( WP_Error::class, $res );
	}
}
