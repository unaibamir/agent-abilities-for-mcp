<?php
/**
 * Tests for the OAuth token manager: hashed storage, validation, refresh
 * rotation, reuse detection, and revocation.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies access/refresh tokens are stored hashed, validate correctly, rotate
 * with parent chaining, trigger chain revocation on refresh-token replay, and
 * can be revoked individually.
 */
class TokensTest extends TestCase {

	/**
	 * A representative mint context. Override individual keys per test.
	 *
	 * @return array<string,mixed>
	 */
	private function ctx(): array {
		return array(
			'client_id'  => 'client_abc',
			'wp_user_id' => 42,
			'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
		);
	}

	/**
	 * Read a single token row by the SHA-256 hash of a raw access token.
	 *
	 * The WordPress test suite rewrites plugin `CREATE TABLE` to its `TEMPORARY`
	 * form, so each DB test must call aafm_install_oauth_tables() first and read
	 * the row back — the temporary table is invisible to `SHOW TABLES`.
	 *
	 * @param string $access_raw Raw access token.
	 * @return array<string,mixed>|null
	 */
	private function row_by_access( string $access_raw ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT * FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE token_hash = %s",
				hash( 'sha256', $access_raw )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read a single token row by the SHA-256 hash of a raw refresh token.
	 *
	 * @param string $refresh_raw Raw refresh token.
	 * @return array<string,mixed>|null
	 */
	private function row_by_refresh( string $refresh_raw ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT * FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE refresh_hash = %s",
				hash( 'sha256', $refresh_raw )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count rows whose token_hash or refresh_hash exactly equals the given value.
	 *
	 * @param string $value Value to match against either hash column.
	 * @return int
	 */
	private function count_by_either_hash( string $value ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE token_hash = %s OR refresh_hash = %s",
				$value,
				$value
			)
		);
	}

	/**
	 * Minting returns a prefixed access token plus a refresh token and stores
	 * only their SHA-256 hashes — never the raw values.
	 */
	public function test_mint_returns_tokens_and_stores_hashes_not_raw(): void {
		aafm_install_oauth_tables();

		$tokens = aafm_oauth_mint_tokens( $this->ctx() );

		$this->assertIsArray( $tokens );
		$this->assertStringStartsWith( 'aafm_oat_', $tokens['access_token'] );
		$this->assertNotEmpty( $tokens['refresh_token'] );
		$this->assertStringStartsNotWith( 'aafm_oat_', $tokens['refresh_token'] );

		// The hash of each raw token is stored.
		$this->assertNotNull( $this->row_by_access( $tokens['access_token'] ) );
		$this->assertNotNull( $this->row_by_refresh( $tokens['refresh_token'] ) );

		// Neither raw value is ever stored in clear in either hash column.
		$this->assertSame( 0, $this->count_by_either_hash( $tokens['access_token'] ) );
		$this->assertSame( 0, $this->count_by_either_hash( $tokens['refresh_token'] ) );
	}

	/**
	 * A fresh access token validates to its wp_user_id.
	 */
	public function test_validate_fresh_access_token_returns_user_id(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		$this->assertSame( (int) $ctx['wp_user_id'], aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	/**
	 * An unknown access token does not validate.
	 */
	public function test_validate_unknown_access_token_returns_false(): void {
		aafm_install_oauth_tables();

		$this->assertFalse( aafm_oauth_validate_access_token( 'aafm_oat_' . bin2hex( random_bytes( 32 ) ) ) );
	}

	/**
	 * An expired access token does not validate.
	 *
	 * Expiry is simulated by writing a past UTC timestamp directly onto the
	 * transaction-isolated temporary row — no sleeping.
	 */
	public function test_validate_expired_access_token_returns_false(): void {
		aafm_install_oauth_tables();

		$tokens = aafm_oauth_mint_tokens( $this->ctx() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'token_hash' => hash( 'sha256', $tokens['access_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);

		$this->assertFalse( aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	/**
	 * A revoked (inactive) access token does not validate.
	 */
	public function test_validate_revoked_access_token_returns_false(): void {
		aafm_install_oauth_tables();

		$tokens = aafm_oauth_mint_tokens( $this->ctx() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array( 'is_active' => 0 ),
			array( 'token_hash' => hash( 'sha256', $tokens['access_token'] ) ),
			array( '%d' ),
			array( '%s' )
		);

		$this->assertFalse( aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	/**
	 * Rotating a valid refresh token issues a new pair, deactivates the old
	 * refresh row, and chains the new row's refresh_parent_id to the old id.
	 */
	public function test_rotate_refresh_issues_new_pair_and_chains_parent(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		$old_row = $this->row_by_refresh( $tokens['refresh_token'] );
		$this->assertNotNull( $old_row );
		$old_id = (int) $old_row['id'];

		$rotated = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );

		$this->assertIsArray( $rotated );
		$this->assertNotSame( $tokens['access_token'], $rotated['access_token'] );
		$this->assertNotSame( $tokens['refresh_token'], $rotated['refresh_token'] );

		// The old refresh row is now inactive.
		$old_after = $this->row_by_refresh( $tokens['refresh_token'] );
		$this->assertNotNull( $old_after );
		$this->assertSame( 0, (int) $old_after['is_active'] );

		// The new row links back to the old one.
		$new_row = $this->row_by_refresh( $rotated['refresh_token'] );
		$this->assertNotNull( $new_row );
		$this->assertSame( $old_id, (int) $new_row['refresh_parent_id'] );

		// The new pair carries the same identity.
		$this->assertSame( (int) $ctx['wp_user_id'], aafm_oauth_validate_access_token( $rotated['access_token'] ) );
	}

	/**
	 * Rotating with the wrong client_id is rejected.
	 */
	public function test_rotate_refresh_wrong_client_returns_error(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		$res = aafm_oauth_rotate_refresh( $tokens['refresh_token'], 'other_client' );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Replaying a consumed refresh token is rejected AND revokes the whole
	 * lineage — the legitimate second-generation access token goes inactive.
	 */
	public function test_rotate_refresh_reuse_detection_revokes_chain(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		// First, legitimate rotation: the original refresh token is now consumed.
		$rotated = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $rotated );

		// The second-generation access token works at this point.
		$this->assertSame( (int) $ctx['wp_user_id'], aafm_oauth_validate_access_token( $rotated['access_token'] ) );

		// Replay the original (already consumed) refresh token.
		$replay = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
		$this->assertInstanceOf( WP_Error::class, $replay );

		// Reuse detection nuked the chain: the legit second-gen token is now dead.
		$this->assertFalse( aafm_oauth_validate_access_token( $rotated['access_token'] ) );

		$new_row = $this->row_by_access( $rotated['access_token'] );
		$this->assertNotNull( $new_row );
		$this->assertSame( 0, (int) $new_row['is_active'] );
	}

	/**
	 * Replaying an unknown refresh token is rejected.
	 */
	public function test_rotate_refresh_unknown_token_returns_error(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_rotate_refresh( bin2hex( random_bytes( 32 ) ), 'client_abc' );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Revoking an access token deactivates it: validation then fails.
	 */
	public function test_revoke_access_token_deactivates_it(): void {
		aafm_install_oauth_tables();

		$tokens = aafm_oauth_mint_tokens( $this->ctx() );

		$this->assertTrue( aafm_oauth_revoke_token( $tokens['access_token'] ) );
		$this->assertFalse( aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	/**
	 * Revoking a refresh token deactivates its row.
	 */
	public function test_revoke_refresh_token_deactivates_row(): void {
		aafm_install_oauth_tables();

		$tokens = aafm_oauth_mint_tokens( $this->ctx() );

		$this->assertTrue( aafm_oauth_revoke_token( $tokens['refresh_token'] ) );

		$row = $this->row_by_refresh( $tokens['refresh_token'] );
		$this->assertNotNull( $row );
		$this->assertSame( 0, (int) $row['is_active'] );
	}

	/**
	 * Revoking an unknown token returns false.
	 */
	public function test_revoke_unknown_token_returns_false(): void {
		aafm_install_oauth_tables();

		$this->assertFalse( aafm_oauth_revoke_token( bin2hex( random_bytes( 32 ) ) ) );
	}
}
