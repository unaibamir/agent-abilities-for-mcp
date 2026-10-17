<?php
/**
 * Tests for OAuth schema v9: the per-connection columns on the consents table.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * The v9 upgrade adds six nullable columns, keeps every existing row, stamps only when all six
 * landed, and issues no destructive SQL.
 */
class SchemaV9Test extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		delete_transient( 'aafm_oauth_upgrade_lock' );
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		self::restore_schema();
		parent::tear_down();
	}

	/**
	 * Put the v9 schema and stamp back after a test that took them away. Runs the installer only
	 * when something is missing: its DDL commits, which would let the test's own option and
	 * transient writes escape the per-test rollback.
	 *
	 * @return void
	 */
	public static function restore_schema(): void {
		if ( ! aafm_oauth_schema_verify() || AAFM_OAUTH_SCHEMA_VERSION !== get_option( 'aafm_oauth_schema_version' ) ) {
			aafm_install_oauth_tables();
		}
	}

	/**
	 * Recreate the consents table the way 1.9.0 shipped it (schema v8, none of the six columns).
	 *
	 * @return void
	 */
	public static function downgrade_consents_to_v8(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aafm_oauth_consents';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL on an internal table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				client_id VARCHAR(191) NOT NULL DEFAULT '',
				granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY user_client (wp_user_id, client_id)
			) ENGINE=InnoDB"
		);
		// phpcs:enable
	}

	public function test_a_v8_install_gains_the_nullable_columns_and_keeps_every_row(): void {
		global $wpdb;
		self::downgrade_consents_to_v8();
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => 7,
				'client_id'  => 'c1',
				'granted_at' => '2026-10-01 10:00:00',
			)
		);
		update_option( 'aafm_oauth_schema_version', '8' );

		aafm_maybe_upgrade_oauth_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}aafm_oauth_consents WHERE client_id = 'c1'", ARRAY_A );
		$this->assertSame( '7', $row['wp_user_id'] );
		$this->assertSame( '2026-10-01 10:00:00', $row['granted_at'] );
		foreach ( aafm_oauth_grant_columns() as $col ) {
			$this->assertArrayHasKey( $col, $row );
			$this->assertNull( $row[ $col ], "$col must be NULL on an upgraded row." );
		}
		$this->assertSame( '9', get_option( 'aafm_oauth_schema_version' ) );
	}

	public function test_the_stamp_is_withheld_when_any_one_of_the_six_columns_is_missing(): void {
		foreach ( aafm_oauth_grant_columns() as $missing ) {
			self::downgrade_consents_to_v8();
			update_option( 'aafm_oauth_schema_version', '8' );
			delete_transient( 'aafm_oauth_schema_error' );
			delete_transient( 'aafm_oauth_upgrade_lock' );
			$sabotage = static function ( $query ) use ( $missing ) {
				return is_string( $query ) && 1 === preg_match( '/^\s*ALTER\s+TABLE\s.*ADD\s+COLUMN\s+`?' . $missing . '\b/is', $query ) ? '' : $query;
			};
			add_filter( 'query', $sabotage, 1 );
			aafm_maybe_upgrade_oauth_tables();
			remove_filter( 'query', $sabotage, 1 );

			$this->assertSame( '8', get_option( 'aafm_oauth_schema_version' ), "The stamp must wait for $missing." );
			$this->assertNotFalse( get_transient( 'aafm_oauth_schema_error' ), "A missing $missing raises the schema notice." );
		}
	}

	public function test_the_upgrade_issues_no_destructive_sql(): void {
		global $wpdb;
		self::downgrade_consents_to_v8();
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => 3,
				'client_id'  => 'keep',
			)
		);
		update_option( 'aafm_oauth_schema_version', '8' );
		$seen   = array();
		$record = static function ( $query ) use ( &$seen ) {
			if ( is_string( $query ) ) {
				$seen[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record, 1 );
		aafm_maybe_upgrade_oauth_tables();
		remove_filter( 'query', $record, 1 );

		$this->assertSame( '9', get_option( 'aafm_oauth_schema_version' ) );
		$this->assertNotEmpty( $seen );
		foreach ( $seen as $query ) {
			// Option writes (the stamp, the lock and notice transients) are allowed; nothing touches an OAuth row.
			$this->assertDoesNotMatchRegularExpression( '/^\s*(DROP|TRUNCATE)\b/i', $query );
			$this->assertDoesNotMatchRegularExpression( '/^\s*(DELETE\s+FROM|UPDATE)\s+`?\S*aafm_oauth_(consents|access_tokens|clients|codes)\b/i', $query );
			if ( 1 === preg_match( '/^\s*ALTER\b/i', $query ) ) {
				$this->assertDoesNotMatchRegularExpression( '/\b(DROP|MODIFY)\b/i', $query );
				// dbDelta restates every BIGINT column on MariaDB (the int display width reads back as bigint(20)), the
				// same no-op it issued under 1.9.0. A CHANGE is allowed only as that identity restatement.
				if ( 1 === preg_match( '/\bCHANGE\b/i', $query ) ) {
					$this->assertMatchesRegularExpression( '/CHANGE COLUMN `(\w+)` \1 BIGINT UNSIGNED NOT NULL (AUTO_INCREMENT|DEFAULT 0)$/', $query );
				}
			}
		}
	}
}
