<?php
/**
 * Tests for the tolerant per-connection state reader.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Defaults only for a missing row or positively absent columns; any other failed read is null.
 */
class GrantStateTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	public function tear_down(): void {
		SchemaV9Test::restore_schema();
		parent::tear_down();
	}

	/**
	 * Insert a consent row.
	 *
	 * @param array<string,mixed> $extra Extra columns.
	 * @return void
	 */
	private function consent( array $extra = array() ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array_merge(
				array(
					'wp_user_id' => 5,
					'client_id'  => 'c1',
				),
				$extra
			)
		);
	}

	public function test_no_consent_row_reads_as_the_defaults(): void {
		$this->assertSame( aafm_oauth_grant_defaults(), aafm_oauth_grant_state( 99, 'nobody' ) );
	}

	public function test_a_row_with_every_new_column_null_reads_as_the_defaults(): void {
		$this->consent();
		$this->assertSame( aafm_oauth_grant_defaults(), aafm_oauth_grant_state( 5, 'c1' ) );
	}

	public function test_a_stored_pause_end_date_preset_and_rate_are_read_back(): void {
		$this->consent(
			array(
				'paused_at'    => '2026-10-01 10:00:00',
				'expires_at'   => '2020-01-01 00:00:00',
				'preset'       => 'read_only',
				'rate_per_min' => 30,
				'last_used_ip' => '203.0.113.9',
			)
		);
		$state = aafm_oauth_grant_state( 5, 'c1' );
		$this->assertTrue( $state['paused'] );
		$this->assertTrue( $state['expired'] );
		$this->assertSame( '2020-01-01 00:00:00', $state['expires_at'] );
		$this->assertSame( 'read_only', $state['preset'] );
		$this->assertSame( 30, $state['rate'] );
		$this->assertSame( '203.0.113.9', $state['last_used_ip'] );
	}

	public function test_the_reader_returns_defaults_when_the_columns_are_positively_absent(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		$this->consent();
		ob_start();
		$state = aafm_oauth_grant_state( 5, 'c1' );
		$out   = (string) ob_get_clean();
		$this->assertSame( aafm_oauth_grant_defaults(), $state );
		$this->assertSame( '', $out, 'The failed read must print nothing.' );
	}

	public function test_columns_present_with_an_old_version_stamp_and_a_failed_read_denies(): void {
		global $wpdb;
		$this->consent( array( 'paused_at' => '2026-10-01 10:00:00' ) );
		update_option( 'aafm_oauth_schema_version', '8' );
		$state = QueryFaultInjector::fail_query(
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents`',
			static fn() => aafm_oauth_grant_state( 5, 'c1' )
		);
		$this->assertNull( $state, 'A stored pause must never read as "not paused" because a stamp is old.' );
	}

	public function test_a_partial_column_set_denies(): void {
		global $wpdb;
		$this->consent();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}aafm_oauth_consents DROP COLUMN rate_per_min" );
		ob_start();
		$state = aafm_oauth_grant_state( 5, 'c1' );
		ob_end_clean();
		$this->assertNull( $state );
	}

	public function test_a_failed_read_on_a_current_schema_returns_null(): void {
		global $wpdb;
		$this->consent();
		$this->assertSame( '9', get_option( 'aafm_oauth_schema_version' ) );
		$state = QueryFaultInjector::fail_query(
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents`',
			static fn() => aafm_oauth_grant_state( 5, 'c1' )
		);
		$this->assertNull( $state );
	}

	public function test_the_defaults_shape_is_json_safe(): void {
		$this->assertSame( aafm_oauth_grant_defaults(), json_decode( (string) wp_json_encode( aafm_oauth_grant_defaults() ), true ) );
	}
}
