<?php
/**
 * Tests for recording a consent without undoing the owner's per-connection settings.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Covers aafm_oauth_record_consent() as an upsert that touches only granted_at.
 */
class ConsentRecordTest extends TestCase {

	private const CLIENT = 'client_consent';

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		aafm_install_oauth_tables();
		parent::tear_down();
	}

	/**
	 * The raw consents row, or an empty array.
	 *
	 * @param int $user User id.
	 * @return array<string,mixed>
	 */
	private function row( int $user ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE wp_user_id = %d AND client_id = %s', $wpdb->prefix . 'aafm_oauth_consents', $user, self::CLIENT ), ARRAY_A );
	}

	public function test_a_repeat_approval_keeps_pause_rate_expiry_and_preset(): void {
		global $wpdb;
		$user = 11;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id'   => $user,
				'client_id'    => self::CLIENT,
				'granted_at'   => '2026-01-01 00:00:00',
				'paused_at'    => '2026-02-01 00:00:00',
				'expires_at'   => '2030-01-01 00:00:00',
				'last_used_at' => '2026-03-01 00:00:00',
				'last_used_ip' => '198.51.100.4',
				'preset'       => 'read_only',
				'rate_per_min' => 7,
			)
		);
		$before = $this->row( $user );

		$this->assertTrue( aafm_oauth_record_consent( $user, self::CLIENT ) );
		$this->assertTrue( aafm_oauth_record_consent( $user, self::CLIENT, 'shop_manager' ) );

		$after = $this->row( $user );
		foreach ( array( 'id', 'paused_at', 'expires_at', 'last_used_at', 'last_used_ip', 'preset', 'rate_per_min' ) as $col ) {
			$this->assertSame( $before[ $col ], $after[ $col ], "$col must survive a repeat approval." );
		}
		$this->assertNotSame( $before['granted_at'], $after['granted_at'] );
	}

	public function test_the_first_approval_stores_the_preset_and_certifies_it(): void {
		global $wpdb;
		$this->assertTrue( aafm_oauth_record_consent( 12, self::CLIENT, 'content_editor' ) );
		$this->assertSame( 'content_editor', aafm_oauth_grant_state( 12, self::CLIENT )['preset'] );

		$result = QueryFaultInjector::fail_query(
			'INSERT INTO `' . $wpdb->prefix . 'aafm_oauth_consents`',
			static fn() => aafm_oauth_record_consent( 13, self::CLIENT, 'content_editor' )
		);
		$this->assertFalse( $result );
	}

	public function test_custom_consent_works_on_a_v8_table(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		update_option( 'aafm_oauth_schema_version', '8' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		$inserts = array();
		$watch   = static function ( $query ) use ( &$inserts ) {
			if ( is_string( $query ) && false !== stripos( $query, 'INSERT INTO' ) && false !== strpos( $query, 'aafm_oauth_consents' ) ) {
				$inserts[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $watch );
		$result = QueryFaultInjector::fail_query( 'ALTER', static fn() => aafm_oauth_record_consent( 14, self::CLIENT ) );
		remove_filter( 'query', $watch );

		$this->assertTrue( $result );
		$this->assertTrue( aafm_oauth_has_consent( 14, self::CLIENT ) );
		$this->assertCount( 1, $inserts );
		foreach ( aafm_oauth_grant_columns() as $col ) {
			$this->assertStringNotContainsString( $col, $inserts[0] );
		}
	}

	public function test_a_restrictive_preset_that_cannot_be_stored_refuses_the_consent(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		update_option( 'aafm_oauth_schema_version', '8' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		$result = QueryFaultInjector::fail_query( 'ALTER', static fn() => aafm_oauth_record_consent( 15, self::CLIENT, 'read_only' ) );

		$this->assertFalse( $result );
		$this->assertFalse( aafm_oauth_has_consent( 15, self::CLIENT ) );
	}

	public function test_a_first_approval_that_loses_a_race_to_another_request_does_not_report_the_wrong_preset(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aafm_oauth_consents';
		$raced = false;
		// Another request lands its own approval (Custom) between the existence read and this INSERT.
		$race = static function ( $query ) use ( &$raced, $wpdb, $table ) {
			if ( ! $raced && is_string( $query ) && 0 === strpos( ltrim( $query ), 'INSERT INTO `' . $table . '`' ) ) {
				$raced = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (wp_user_id, client_id) VALUES (%d, %s)', $table, 16, self::CLIENT ) );
			}
			return $query;
		};
		add_filter( 'query', $race );
		$result = aafm_oauth_record_consent( 16, self::CLIENT, 'read_only' );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced );
		$this->assertFalse( $result, 'The stored preset is not the one asked for, so the approval must not certify.' );
	}
}
