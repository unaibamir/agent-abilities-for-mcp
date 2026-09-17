<?php
/**
 * Tests for the OAuth token manager: hashed storage, validation, refresh
 * rotation, reuse detection, and revocation.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies access/refresh tokens are stored hashed, validate correctly, rotate
 * with parent chaining, trigger chain revocation on refresh-token replay, and
 * can be revoked individually.
 */
class TokensTest extends TestCase {

	/**
	 * Installs the OAuth tables and seeds the 'client_abc' row every test in this file
	 * mints tokens under, so aafm_oauth_client_is_deactivated() resolves a confirmed active
	 * row rather than denying a client_id it has never seen.
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => 'client_abc',
				'client_name' => 'Test',
				'is_active'   => 1,
			),
			array( '%s', '%s', '%d' )
		);
	}

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
	 * the row back - the temporary table is invisible to `SHOW TABLES`.
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
	 * Count rows whose refresh_parent_id equals a given row id - used to prove a rotation attempt
	 * against an already-deactivated node left no successor row behind at all.
	 *
	 * @param int $parent_id The refresh_parent_id to match.
	 * @return int
	 */
	private function count_children_of( int $parent_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE refresh_parent_id = %d",
				$parent_id
			)
		);
	}

	/**
	 * When the row insert fails, mint returns a WP_Error rather than phantom tokens -
	 * a client must never get a successful token response for a grant that was never stored.
	 */
	public function test_mint_returns_error_when_insert_fails(): void {
		aafm_install_oauth_tables();

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}aafm_oauth_access_tokens" );

		$result = aafm_oauth_mint_tokens( $this->ctx() );

		$wpdb->suppress_errors( $suppress );

		$this->assertInstanceOf( WP_Error::class, $result, 'A failed insert must surface as an error, not phantom tokens.' );
	}

	/**
	 * Minting returns a prefixed access token plus a refresh token and stores
	 * only their SHA-256 hashes - never the raw values.
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
	 * A valid, unexpired access token whose owning OAuth client has been deactivated must stop
	 * validating - matching the live REST path, which re-checks client deactivation so disabling a
	 * compromised client kills its already-issued access tokens immediately.
	 */
	public function test_validate_fails_when_owning_client_is_deactivated(): void {
		aafm_install_oauth_tables();

		$client = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://app.example/callback' ) )
		);
		$this->assertIsArray( $client );

		$ctx    = array(
			'client_id'  => $client['client_id'],
			'wp_user_id' => 42,
			'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
		);
		$tokens = aafm_oauth_mint_tokens( $ctx );

		// Active client: the token validates to its user.
		$this->assertSame( 42, aafm_oauth_validate_access_token( $tokens['access_token'] ) );

		// Deactivating the client must immediately invalidate its live access token.
		$this->assertTrue( aafm_oauth_deactivate_client( $client['client_id'] ) );
		$this->assertFalse( aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	/**
	 * Aafm_oauth_client_is_deactivated() must not cast a failed SELECT to false ("not   * deactivated"), or a live bearer token whose owning client cannot actually be checked would
	 * keep validating for the duration of a transient database failure. The client here is
	 * genuinely ACTIVE and the token is genuinely fresh; only the deactivation-check read fails.
	 * The token must still be refused, not accepted, because the live gate cannot tell "confirmed
	 * active" apart from "could not check."
	 */
	public function test_validate_fails_closed_when_the_deactivation_read_fails(): void {
		aafm_install_oauth_tables();

		$client = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://app.example/callback' ) )
		);
		$this->assertIsArray( $client );

		$tokens = aafm_oauth_mint_tokens(
			array(
				'client_id'  => $client['client_id'],
				'wp_user_id' => 42,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);

		global $wpdb;
		$result = QueryFaultInjector::break_query_with_real_error(
			'SELECT is_active FROM `' . $wpdb->prefix . 'aafm_oauth_clients`',
			static function () use ( $tokens ) {
				return aafm_oauth_validate_access_token( $tokens['access_token'] );
			}
		);

		$this->assertFalse( $result, 'An unreadable clients table must refuse the token, not accept it.' );
	}

	/**
	 * Aafm_oauth_client_is_deactivated() must not read a missing client row as "not deactivated"    * (the same as a confirmed-active row), or a token whose owning client row was later deleted
	 * - a partial table clear, a manual repair, or a race with the abandoned-client reaper - would
	 * keep validating indefinitely. The token here is genuinely fresh; only the client row is
	 * gone. The live gate must require a positively confirmed active row, not merely the absence
	 * of a "deactivated" one.
	 */
	public function test_validate_fails_when_owning_client_row_is_deleted(): void {
		aafm_install_oauth_tables();

		$client = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://app.example/callback' ) )
		);
		$this->assertIsArray( $client );

		$tokens = aafm_oauth_mint_tokens(
			array(
				'client_id'  => $client['client_id'],
				'wp_user_id' => 42,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);

		// Active client, fresh token: validates.
		$this->assertSame( 42, aafm_oauth_validate_access_token( $tokens['access_token'] ) );

		// The client row disappears entirely - not deactivated, just gone.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'aafm_oauth_clients', array( 'client_id' => $client['client_id'] ), array( '%s' ) );

		$this->assertFalse(
			aafm_oauth_validate_access_token( $tokens['access_token'] ),
			'A token whose owning client row was deleted must not keep validating.'
		);
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
	 * transaction-isolated temporary row - no sleeping.
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
	 * lineage - the legitimate second-generation access token goes inactive.
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
	 * An expired refresh token is rejected and does not mint a successor.
	 *
	 * Expiry is simulated by writing a past UTC refresh_expires_at directly onto
	 * the transaction-isolated temporary row - no sleeping. The single minted row
	 * must remain the only one, proving rotation bailed before minting.
	 */
	public function test_rotate_refresh_expired_token_returns_error_and_does_not_mint(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		global $wpdb;
		$table = $wpdb->prefix . 'aafm_oauth_access_tokens';

		// Push refresh_expires_at into the past.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'refresh_hash' => hash( 'sha256', $tokens['refresh_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT COUNT(*) FROM {$table}"
		);

		$res = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
		$this->assertInstanceOf( WP_Error::class, $res );

		// No successor row was minted: the table still holds exactly one row, and
		// the original refresh row was left untouched (still active).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"SELECT COUNT(*) FROM {$table}"
		);
		$this->assertSame( $before, $after );

		$row = $this->row_by_refresh( $tokens['refresh_token'] );
		$this->assertNotNull( $row );
		$this->assertSame( 1, (int) $row['is_active'] );
	}

	/**
	 * Deactivating a client blocks its refresh rotation, even for a token issued while the
	 * client was still active.
	 */
	public function test_rotate_refresh_rejected_for_deactivated_client(): void {
		aafm_install_oauth_tables();

		$client = aafm_oauth_register_client( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) );
		$this->assertIsArray( $client );
		$client_id = (string) $client['client_id'];

		$tokens = aafm_oauth_mint_tokens(
			array(
				'client_id'  => $client_id,
				'wp_user_id' => 42,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);
		$this->assertIsArray( $tokens );

		// While active, rotation works.
		$ok = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $client_id );
		$this->assertIsArray( $ok, 'rotation should succeed while the client is active' );

		// Deactivate the client, then a rotation of the fresh successor must be rejected.
		$this->deactivate_client( $client_id );
		$rejected = aafm_oauth_rotate_refresh( $ok['refresh_token'], $client_id );
		$this->assertInstanceOf( WP_Error::class, $rejected, 'a deactivated client must not rotate its refresh token' );
	}

	/**
	 * The same fail-open shape as test_validate_fails_closed_when_the_deactivation_read_fails()
	 * above, but at the refresh-rotation gate. The client is genuinely ACTIVE; only the
	 * deactivation-check read fails. Rotation must still be rejected, not granted a fresh pair.
	 */
	public function test_rotate_refresh_rejected_when_the_deactivation_read_fails(): void {
		aafm_install_oauth_tables();

		$client = aafm_oauth_register_client( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) );
		$this->assertIsArray( $client );
		$client_id = (string) $client['client_id'];

		$tokens = aafm_oauth_mint_tokens(
			array(
				'client_id'  => $client_id,
				'wp_user_id' => 42,
				'resource'   => 'https://site.example/wp-json/aafm/v1/mcp',
			)
		);
		$this->assertIsArray( $tokens );

		global $wpdb;
		$rejected = QueryFaultInjector::break_query_with_real_error(
			'SELECT is_active FROM `' . $wpdb->prefix . 'aafm_oauth_clients`',
			static function () use ( $tokens, $client_id ) {
				return aafm_oauth_rotate_refresh( $tokens['refresh_token'], $client_id );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $rejected, 'an unreadable clients table must refuse rotation, not grant it' );
	}

	/**
	 * A failed START TRANSACTION must refuse the rotation rather than run the consume+mint pair
	 * unwrapped and report success anyway.
	 */
	public function test_rotate_refresh_returns_error_when_start_transaction_fails(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		$rejected = QueryFaultInjector::break_query_with_real_error(
			'START TRANSACTION',
			static function () use ( $tokens, $ctx ) {
				return aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
			},
			0,
			true
		);

		$this->assertInstanceOf( WP_Error::class, $rejected, 'a failed START TRANSACTION must refuse rotation, not run it unwrapped' );

		// The old refresh row must still be active: nothing was consumed.
		$old_after = $this->row_by_refresh( $tokens['refresh_token'] );
		$this->assertNotNull( $old_after );
		$this->assertSame( 1, (int) $old_after['is_active'], 'a refused rotation must not consume the old refresh row' );
	}

	/**
	 * A failed COMMIT must not report the minted tokens as issued - this function cannot confirm
	 * the consumption and the new pair actually persisted together.
	 */
	public function test_rotate_refresh_returns_error_when_commit_fails(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		$rejected = QueryFaultInjector::break_query_with_real_error(
			'COMMIT',
			static function () use ( $tokens, $ctx ) {
				return aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
			},
			0,
			true
		);

		$this->assertInstanceOf( WP_Error::class, $rejected, 'a failed COMMIT must not be reported as a successful rotation' );
	}

	/**
	 * Mark a registered client inactive (is_active = 0) on its transaction-isolated row.
	 *
	 * @param string $client_id The client to deactivate.
	 */
	private function deactivate_client( string $client_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_clients',
			array( 'is_active' => 0 ),
			array( 'client_id' => $client_id ),
			array( '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Rotating the SAME refresh token twice: the second rotate is rejected.
	 *
	 * After a successful rotation the old row is consumed (inactive), so a second
	 * presentation of the same raw refresh token trips reuse detection and returns
	 * a WP_Error rather than minting a second successor. This pins the
	 * single-winner property of the atomic consume.
	 */
	public function test_rotate_refresh_same_token_twice_second_is_rejected(): void {
		aafm_install_oauth_tables();

		$ctx    = $this->ctx();
		$tokens = aafm_oauth_mint_tokens( $ctx );

		$first = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $first );

		$second = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
		$this->assertInstanceOf( WP_Error::class, $second );
	}

	/**
	 * Replaying a consumed MIDDLE refresh token revokes the lineage in BOTH
	 * directions.
	 *
	 * Build a three-generation chain gen0 -> gen1 -> gen2 (each linked by
	 * refresh_parent_id). Replay the gen1 (middle) refresh token, which is already
	 * consumed. Reuse detection must walk UP to gen0 and DOWN to gen2 and
	 * deactivate every generation's access token.
	 */
	public function test_rotate_refresh_mid_lineage_replay_revokes_whole_chain(): void {
		aafm_install_oauth_tables();

		$ctx = $this->ctx();

		// gen0: fresh mint.
		$gen0 = aafm_oauth_mint_tokens( $ctx );

		// gen0 -> gen1.
		$gen1 = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $gen1 );

		// gen1 -> gen2.
		$gen2 = aafm_oauth_rotate_refresh( $gen1['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $gen2 );

		// Only the newest generation's row is still active - each rotation consumes
		// the row it rotated from, deactivating that generation's access token too.
		// So before the replay, gen2 is live while gen0 and gen1 are already
		// inactive (their rows were consumed). The replay must still kill gen2.
		$this->assertSame( (int) $ctx['wp_user_id'], aafm_oauth_validate_access_token( $gen2['access_token'] ) );

		// Replay the MIDDLE (gen1) refresh token - already consumed by the gen2 rotation.
		$replay = aafm_oauth_rotate_refresh( $gen1['refresh_token'], $ctx['client_id'] );
		$this->assertInstanceOf( WP_Error::class, $replay );

		// The whole lineage is dead: up to gen0 and down to gen2.
		$this->assertFalse( aafm_oauth_validate_access_token( $gen0['access_token'] ) );
		$this->assertFalse( aafm_oauth_validate_access_token( $gen1['access_token'] ) );
		$this->assertFalse( aafm_oauth_validate_access_token( $gen2['access_token'] ) );

		// Confirm at the row level that every generation is now inactive.
		$gen0_row = $this->row_by_access( $gen0['access_token'] );
		$gen1_row = $this->row_by_access( $gen1['access_token'] );
		$gen2_row = $this->row_by_access( $gen2['access_token'] );
		$this->assertNotNull( $gen0_row );
		$this->assertNotNull( $gen1_row );
		$this->assertNotNull( $gen2_row );
		$this->assertSame( 0, (int) $gen0_row['is_active'] );
		$this->assertSame( 0, (int) $gen1_row['is_active'] );
		$this->assertSame( 0, (int) $gen2_row['is_active'] );
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

		// Revoking an already-revoked token affects no rows: idempotent, returns false.
		$this->assertFalse( aafm_oauth_revoke_token( $tokens['access_token'] ) );
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

	/**
	 * A genuine query failure must return null, not the same false a legitimate "no matching
	 * token" gets - the REST caller uses this to avoid reporting a database failure as an
	 * ordinary 200 revocation no-op.
	 */
	public function test_revoke_token_returns_null_when_the_update_query_fails(): void {
		aafm_install_oauth_tables();

		$tokens = aafm_oauth_mint_tokens( $this->ctx() );

		global $wpdb;
		$result = QueryFaultInjector::break_query_with_real_error(
			array( 'UPDATE `' . $wpdb->prefix . 'aafm_oauth_access_tokens`', 'is_active = 0' ),
			static function () use ( $tokens ) {
				return aafm_oauth_revoke_token( $tokens['access_token'] );
			}
		);

		$this->assertNull( $result, 'a failed revoke query must be distinguishable from "no matching token"' );

		// The token must still validate: nothing was actually revoked.
		$this->assertIsInt( aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	/**
	 * When the chain-revocation traversal cannot complete (a read fails partway through), the
	 * reuse-detection error must not claim the chain was revoked.
	 */
	public function test_rotate_refresh_replay_does_not_overclaim_when_chain_traversal_fails(): void {
		aafm_install_oauth_tables();

		$ctx     = $this->ctx();
		$tokens  = aafm_oauth_mint_tokens( $ctx );
		$rotated = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $rotated );

		// Replay the now-consumed original refresh token: reuse detection fires and walks the
		// chain. Force the upward-walk read to fail so aafm_oauth_revoke_chain() cannot certify
		// the traversal completed.
		global $wpdb;
		add_filter(
			'query',
			static function ( string $query ) use ( $wpdb ): string {
				$is_parent_walk = false !== strpos( $query, 'SELECT refresh_parent_id FROM `' . $wpdb->prefix . 'aafm_oauth_access_tokens`' );
				return $is_parent_walk ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		$suppressed = $wpdb->suppress_errors( true );

		$replayed = aafm_oauth_rotate_refresh( $tokens['refresh_token'], $ctx['client_id'] );

		$wpdb->suppress_errors( $suppressed );
		remove_all_filters( 'query' );

		$this->assertInstanceOf( WP_Error::class, $replayed );
		$this->assertSame(
			'The refresh token has already been used.',
			$replayed->get_error_message(),
			'the message must not claim the chain was revoked when traversal could not be certified'
		);
	}

	/**
	 * The revocation walk must deactivate every node BEFORE its children are read, not collect
	 * the whole lineage first and deactivate it in one UPDATE at the end - that ordering would let
	 * a successor minted after the last read but before that final UPDATE go invisible, and the
	 * function would still report success. Deactivating first means a concurrent rotation attempt
	 * aimed at a node already visited by this walk finds that node already inactive and cannot
	 * mint at all - there is no successor for a later read to miss.
	 *
	 * This proves that shape directly: the injected rotation fires in the gap right after gen1 is
	 * deactivated (and before its children are read), and must fail outright rather than merely
	 * get caught and revoked afterward.
	 */
	public function test_rotate_refresh_replay_revokes_a_successor_minted_mid_walk(): void {
		aafm_install_oauth_tables();

		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );
		$gen1 = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $gen1 );

		$gen1_row = $this->row_by_refresh( $gen1['refresh_token'] );
		$this->assertNotNull( $gen1_row );
		$gen1_id = (int) $gen1_row['id'];

		// Arms on the query that deactivates gen1 (letting it run normally), then fires on the
		// VERY NEXT query - the read of gen1's children - injecting a concurrent rotation attempt
		// for gen1 into that exact gap. Simulates a concurrent request racing to rotate gen1 right
		// as this walk finishes deactivating it.
		$marker   = 'is_active = 0 WHERE id = ' . $gen1_id;
		$armed    = false;
		$fired    = false;
		$injected = null;

		add_filter(
			'query',
			function ( string $query ) use ( $marker, $gen1, $ctx, &$armed, &$fired, &$injected ): string {
				if ( $armed ) {
					$armed = false;
					$fired = true;
					remove_all_filters( 'query' );
					$injected = aafm_oauth_rotate_refresh( $gen1['refresh_token'], $ctx['client_id'] );
					return $query;
				}
				if ( false !== strpos( $query, $marker ) ) {
					$armed = true;
				}
				return $query;
			}
		);

		try {
			// Replaying gen0 (already consumed by its own rotation into gen1) triggers reuse
			// detection, which revokes the whole lineage the walk can find starting at gen0.
			$replay = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		} finally {
			remove_all_filters( 'query' );
		}

		$this->assertTrue( $fired, 'the injected mid-walk rotation never ran; this test did not exercise the race' );

		// The injected rotation must fail outright - gen1 is already inactive by the time it
		// runs, so its own single-winner gate matches zero rows and no successor is ever minted.
		$this->assertInstanceOf( WP_Error::class, $injected, 'a rotation attempt against an already-deactivated node must not succeed' );
		$this->assertSame( 'invalid_grant', $injected->get_error_code() );
		$this->assertSame( 0, $this->count_children_of( $gen1_id ), 'no successor row may exist for a rotation the revocation walk already closed the window on' );

		$this->assertInstanceOf( WP_Error::class, $replay );
		$this->assertSame(
			'The refresh token has already been used; the token chain has been revoked.',
			$replay->get_error_message(),
			'gen1 had no surviving descendant, so the walk should report the chain fully revoked'
		);
	}

	/**
	 * The per-node deactivate-before-read guarantee the test above proves for the first hop off
	 * the seed must hold at any depth, not just one hop in. This builds a real three-generation
	 * chain (gen0 -> gen1 -> gen2, all pre-existing, not injected) and injects a concurrent
	 * rotation attempt against gen2 - the deepest node - in the same gap: right after the walk
	 * deactivates it, right before it reads gen2's children. It must fail the same way.
	 */
	public function test_rotate_refresh_replay_closes_the_window_at_any_depth(): void {
		aafm_install_oauth_tables();

		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );
		$gen1 = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $gen1 );
		$gen2 = aafm_oauth_rotate_refresh( $gen1['refresh_token'], $ctx['client_id'] );
		$this->assertIsArray( $gen2 );

		$gen2_row = $this->row_by_refresh( $gen2['refresh_token'] );
		$this->assertNotNull( $gen2_row );
		$gen2_id = (int) $gen2_row['id'];

		$marker   = 'is_active = 0 WHERE id = ' . $gen2_id;
		$armed    = false;
		$fired    = false;
		$injected = null;

		add_filter(
			'query',
			function ( string $query ) use ( $marker, $gen2, $ctx, &$armed, &$fired, &$injected ): string {
				if ( $armed ) {
					$armed = false;
					$fired = true;
					remove_all_filters( 'query' );
					$injected = aafm_oauth_rotate_refresh( $gen2['refresh_token'], $ctx['client_id'] );
					return $query;
				}
				if ( false !== strpos( $query, $marker ) ) {
					$armed = true;
				}
				return $query;
			}
		);

		try {
			// Replaying gen0 triggers reuse detection; the walk must reach gen2 (two hops down)
			// before this test's assertions mean anything.
			$replay = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		} finally {
			remove_all_filters( 'query' );
		}

		$this->assertTrue( $fired, 'the injected rotation never ran; the walk did not reach gen2' );
		$this->assertInstanceOf( WP_Error::class, $injected, 'a rotation attempt against an already-deactivated node must not succeed, at any depth' );
		$this->assertSame( 'invalid_grant', $injected->get_error_code() );
		$this->assertSame( 0, $this->count_children_of( $gen2_id ), 'no successor row may exist for gen2 once the walk has deactivated it' );

		$this->assertInstanceOf( WP_Error::class, $replay );
		$this->assertSame(
			'The refresh token has already been used; the token chain has been revoked.',
			$replay->get_error_message()
		);

		// Every real row in the lineage must actually be inactive, not merely reported as such.
		// Re-read both rows - the copies captured above predate the revocation.
		$gen1_row = $this->row_by_refresh( $gen1['refresh_token'] );
		$this->assertNotNull( $gen1_row );
		$this->assertSame( 0, (int) $gen1_row['is_active'] );
		$gen2_row = $this->row_by_refresh( $gen2['refresh_token'] );
		$this->assertNotNull( $gen2_row );
		$this->assertSame( 0, (int) $gen2_row['is_active'] );
	}

	/**
	 * A failed refresh-token lookup must not read exactly like an unknown token, both reported as
	 * invalid_grant. A database fault is this pipeline's own fault, not evidence the presented
	 * token is bad.
	 */
	public function test_rotate_refresh_reports_server_error_when_the_lookup_query_fails(): void {
		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );

		$result = QueryFaultInjector::break_query_with_real_error(
			'refresh_hash = ',
			static function () use ( $gen0, $ctx ) {
				return aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'server_error', $result->get_error_code(), 'a failed lookup is this pipeline\'s own fault, not an invalid grant' );
	}

	/**
	 * The single-winner consumption UPDATE returns false on a genuine query failure and an
	 * integer (0 on a lost race) on success; these must not both report invalid_grant. Only the
	 * race-loss case is a real grant-validity answer.
	 */
	public function test_rotate_refresh_reports_server_error_when_the_consuming_update_fails(): void {
		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );

		add_filter(
			'query',
			static function ( string $query ): string {
				$is_consuming_update = 0 === strpos( trim( $query ), 'UPDATE' )
					&& false !== strpos( $query, 'aafm_oauth_access_tokens' );
				return $is_consuming_update ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$result = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_all_filters( 'query' );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'server_error', $result->get_error_code(), 'a failed consuming UPDATE is this pipeline\'s own fault, not an invalid grant' );
	}

	/**
	 * Aafm_oauth_client_is_deactivated() correctly fails closed on an unreadable clients table,     * but the caller must not always report "the client is no longer active" for that: that
	 * message is true only when the client was genuinely confirmed inactive, not when this
	 * pipeline simply could not check.
	 */
	public function test_rotate_refresh_reports_server_error_when_the_client_check_fails(): void {
		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );

		add_filter(
			'query',
			static function ( string $query ): string {
				$is_client_check = 0 === strpos( trim( $query ), 'SELECT' )
					&& false !== strpos( $query, 'aafm_oauth_clients' );
				return $is_client_check ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$result = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_all_filters( 'query' );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'server_error', $result->get_error_code(), 'an unreadable clients table is this pipeline\'s own fault, not a confirmed deactivation' );
	}

	/**
	 * The client check must be a single read, not two separate queries -
	 * aafm_oauth_client_is_deactivated() then, only when that returned true,
	 * aafm_oauth_client_lookup_failed() - because a failed FIRST query followed by a SUCCESSFUL
	 * second query could otherwise still read the client as genuinely deactivated rather than as
	 * a fault. Fails only the first client-select query and lets any later one through, then
	 * asserts both the correct result AND that only one such query ever ran - proving there is no
	 * second read for a two-query shape to fall back on.
	 */
	public function test_rotate_refresh_client_check_is_a_single_read_when_that_read_fails(): void {
		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );

		$occurrences = 0;
		add_filter(
			'query',
			function ( string $query ) use ( &$occurrences ): string {
				$is_client_check = 0 === strpos( trim( $query ), 'SELECT' )
					&& false !== strpos( $query, 'aafm_oauth_clients' );
				if ( ! $is_client_check ) {
					return $query;
				}
				++$occurrences;
				return 1 === $occurrences ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$result = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_all_filters( 'query' );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'server_error', $result->get_error_code(), 'a failed single read must be reported as a fault, never silently answered by a second query' );
		$this->assertSame( 1, $occurrences, 'a second client-select query means the old two-query shape has come back' );
	}

	/**
	 * The opposite direction: a genuinely deactivated client found by the first (and only)
	 * client-select query must report invalid_grant even though a SECOND such query - an
	 * aafm_oauth_client_lookup_failed()-style re-probe - would have failed. Deactivates the
	 * client for real, then fails only a second occurrence of the client-select query (the first
	 * is left to run normally); this function never issues that second query, so the genuine
	 * deactivation must still be reported correctly.
	 */
	public function test_rotate_refresh_client_check_reports_genuine_deactivation_even_if_a_second_read_would_fail(): void {
		$ctx  = $this->ctx();
		$gen0 = aafm_oauth_mint_tokens( $ctx );
		$this->assertTrue( aafm_oauth_deactivate_client( $ctx['client_id'] ) );

		$occurrences = 0;
		add_filter(
			'query',
			function ( string $query ) use ( &$occurrences ): string {
				$is_client_check = 0 === strpos( trim( $query ), 'SELECT' )
					&& false !== strpos( $query, 'aafm_oauth_clients' );
				if ( ! $is_client_check ) {
					return $query;
				}
				++$occurrences;
				return 2 === $occurrences ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$result = aafm_oauth_rotate_refresh( $gen0['refresh_token'], $ctx['client_id'] );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_all_filters( 'query' );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_grant', $result->get_error_code(), 'a genuine deactivation found by the one real read must not be overridden by a second query that never runs' );
		$this->assertSame( 1, $occurrences, 'a second client-select query means the old two-query shape has come back' );
	}
}
