<?php
/**
 * Tests for the per-connection writers, the last-used stamp, the expiry gate and the request store.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Covers aafm_oauth_grant_update(), aafm_oauth_grant_touch(), aafm_oauth_grant_gate() and
 * aafm_oauth_current_grant().
 */
class GrantWriteTest extends TestCase {

	private const CLIENT = 'client_grant';

	/**
	 * Approving user.
	 *
	 * @var int
	 */
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Grant test',
				'is_active'   => 1,
			)
		);
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		SchemaV9Test::restore_schema();
		parent::tear_down();
	}

	/**
	 * Mint a token pair for the fixture grant.
	 *
	 * @return array{access_token:string,refresh_token:string,expires_in:int}
	 */
	private function mint(): array {
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		return $tokens;
	}

	/**
	 * The raw consents row.
	 *
	 * @return array<string,mixed>
	 */
	private function row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE wp_user_id = %d AND client_id = %s', $wpdb->prefix . 'aafm_oauth_consents', $this->user, self::CLIENT ), ARRAY_A );
	}

	public function test_update_pauses_and_resumes_without_touching_tokens(): void {
		$tokens = $this->mint();
		$before = aafm_oauth_get_access_token_row( $tokens['access_token'] );

		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => true ) ) );
		$this->assertTrue( aafm_oauth_grant_state( $this->user, self::CLIENT )['paused'] );
		$after = aafm_oauth_get_access_token_row( $tokens['access_token'] );
		$this->assertSame( '1', (string) $after['is_active'] );
		$this->assertSame( $before['token_hash'], $after['token_hash'] );

		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => false ) ) );
		$this->assertNull( $this->row()['paused_at'] );
	}

	public function test_update_sets_and_clears_each_field_and_certifies(): void {
		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$this->assertTrue(
			aafm_oauth_grant_update(
				$this->user,
				self::CLIENT,
				array(
					'preset'     => 'read_only',
					'expires_at' => $future,
					'rate'       => 12,
				)
			)
		);
		$state = aafm_oauth_grant_state( $this->user, self::CLIENT );
		$this->assertSame( 'read_only', $state['preset'] );
		$this->assertSame( $future, $state['expires_at'] );
		$this->assertSame( 12, $state['rate'] );

		$this->assertTrue(
			aafm_oauth_grant_update(
				$this->user,
				self::CLIENT,
				array(
					'preset'     => '',
					'expires_at' => null,
					'rate'       => 0,
				)
			)
		);
		$this->assertSame( aafm_oauth_grant_defaults(), aafm_oauth_grant_state( $this->user, self::CLIENT ) );
	}

	public function test_update_returns_false_when_the_write_does_not_take(): void {
		global $wpdb;
		$result = QueryFaultInjector::fail_query(
			'UPDATE `' . $wpdb->prefix . 'aafm_oauth_consents`',
			fn() => aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => true ) )
		);
		$this->assertFalse( $result );
		$this->assertFalse( aafm_oauth_grant_state( $this->user, self::CLIENT )['paused'] );
	}

	public function test_update_fails_when_the_grant_row_is_gone(): void {
		global $wpdb;
		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => true ) ) );
		$wpdb->delete( $wpdb->prefix . 'aafm_oauth_consents', array( 'client_id' => self::CLIENT ) );

		$this->assertFalse( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => false ) ), 'Resuming a deleted grant must not report success.' );
	}

	public function test_update_refuses_an_unknown_key_and_an_empty_change_set(): void {
		$this->assertFalse( aafm_oauth_grant_update( $this->user, self::CLIENT, array() ) );
		$this->assertFalse( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'x' => 1 ) ) );
		$this->assertSame( aafm_oauth_grant_defaults(), aafm_oauth_grant_state( $this->user, self::CLIENT ) );
	}

	public function test_update_runs_the_installer_first(): void {
		global $wpdb;
		SchemaV9Test::downgrade_consents_to_v8();
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
			)
		);
		update_option( 'aafm_oauth_schema_version', '8' );
		delete_transient( 'aafm_oauth_upgrade_lock' );

		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'paused' => true ) ) );
		$this->assertArrayHasKey( 'paused_at', $this->row() );
	}

	public function test_touch_writes_once_inside_the_window(): void {
		global $wpdb;
		$updates = 0;
		$count   = static function ( $query ) use ( &$updates ) {
			if ( is_string( $query ) && false !== strpos( $query, 'aafm_oauth_consents` SET last_used_at = ' ) ) {
				++$updates;
			}
			return $query;
		};
		add_filter( 'query', $count );

		aafm_oauth_grant_touch( $this->user, self::CLIENT, null );
		$row = $this->row();
		$this->assertNotNull( $row['last_used_at'] );
		$this->assertNotNull( $row['last_used_ip'] );
		$this->assertSame( 1, $updates );

		aafm_oauth_grant_touch( $this->user, self::CLIENT, (string) $row['last_used_at'] );
		$this->assertSame( 1, $updates, 'A fresh stamp writes nothing on the hot path.' );

		$old = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'last_used_at' => $old ), array( 'client_id' => self::CLIENT ) );
		aafm_oauth_grant_touch( $this->user, self::CLIENT, $old );
		remove_filter( 'query', $count );
		$this->assertSame( 2, $updates );
		$this->assertGreaterThan( $old, $this->row()['last_used_at'] );
	}

	public function test_touch_never_fails_the_caller_when_the_columns_are_missing(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		ob_start();
		aafm_oauth_grant_touch( $this->user, self::CLIENT, null );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_gate_reports_ok_expired_and_unknown(): void {
		global $wpdb;
		$this->assertSame( 'ok', aafm_oauth_grant_gate( 4242, 'nobody' ) );
		$this->assertSame( 'ok', aafm_oauth_grant_gate( $this->user, self::CLIENT ) );
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'client_id' => self::CLIENT ) );
		$this->assertSame( 'expired', aafm_oauth_grant_gate( $this->user, self::CLIENT ) );
		$gate = QueryFaultInjector::fail_query(
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents`',
			fn() => aafm_oauth_grant_gate( $this->user, self::CLIENT )
		);
		$this->assertSame( 'unknown', $gate );
	}

	public function test_the_store_is_per_request_and_cleared_at_shutdown(): void {
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'client_id' => 'x',
					'user_id'   => 3,
				)
			)
		);
		$this->assertSame( 'x', aafm_oauth_current_grant()['client_id'] );
		// Firing shutdown itself would also flush PHPUnit's output buffer, so call what the hook runs.
		$this->assertNotFalse( has_action( 'shutdown', 'aafm_oauth_reset_current_client_id' ) );
		aafm_oauth_reset_current_client_id();
		$this->assertSame( '', aafm_oauth_current_grant()['client_id'] );
		$this->assertSame( 0, aafm_oauth_current_grant()['user_id'] );
	}

	public function test_update_renews_an_expired_grant_only_after_revoking_the_old_tokens(): void {
		global $wpdb;
		foreach ( array( null, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) as $new_end ) {
			$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'client_id' => self::CLIENT ) );
			$old = $this->mint();

			$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'expires_at' => $new_end ) ) );
			$this->assertFalse( aafm_oauth_validate_access_token( $old['access_token'] ), 'The old access token stays dead.' );
			$this->assertWPError( aafm_oauth_rotate_refresh( $old['refresh_token'], self::CLIENT ), 'The old refresh token stays dead.' );

			$fresh = $this->mint();
			$this->assertSame( $this->user, aafm_oauth_validate_access_token( $fresh['access_token'] ) );
		}
	}

	public function test_update_that_cannot_revoke_leaves_the_grant_expired(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'client_id' => self::CLIENT ) );
		$this->mint();
		$result = QueryFaultInjector::fail_query(
			'UPDATE `' . $wpdb->prefix . 'aafm_oauth_access_tokens`',
			fn() => aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'expires_at' => null ) )
		);
		$this->assertFalse( $result );
		$this->assertTrue( aafm_oauth_grant_state( $this->user, self::CLIENT )['expired'] );
	}

	public function test_extending_a_live_grant_does_not_touch_its_tokens(): void {
		$tokens = $this->mint();
		$this->assertTrue( aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) ) );
		$this->assertSame( $this->user, aafm_oauth_validate_access_token( $tokens['access_token'] ) );
	}

	public function test_the_store_is_set_for_the_next_test(): void {
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'client_id' => 'leak',
					'user_id'   => 9,
				)
			)
		);
		$this->assertSame( 'leak', aafm_oauth_current_grant()['client_id'] );
	}

	/**
	 * Runs after the test above: the base class must have cleared the store.
	 *
	 * @depends test_the_store_is_set_for_the_next_test
	 */
	public function test_the_store_is_reset_by_the_test_base_class(): void {
		$this->assertSame( '', aafm_oauth_current_grant()['client_id'] );
	}
}
