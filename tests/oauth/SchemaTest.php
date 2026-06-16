<?php
/**
 * Tests for the OAuth storage schema installer.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies the four OAuth tables install idempotently and record their schema version.
 */
class SchemaTest extends TestCase {

	/**
	 * Whether a plugin table exists for the current blog.
	 *
	 * The WordPress test suite rewrites every plugin `CREATE TABLE` / `DROP TABLE`
	 * to its `TEMPORARY` form so each test gets an isolated, rolled-back table.
	 * `SHOW TABLES` does not list temporary tables, so existence is probed with a
	 * trivial select instead, which sees the temporary table the same way the
	 * plugin's own queries do.
	 *
	 * @param string $suffix Unprefixed table suffix (e.g. 'aafm_oauth_clients').
	 * @return bool
	 */
	private function table_exists( string $suffix ): bool {
		global $wpdb;
		$table      = $wpdb->prefix . $suffix;
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );
		return '' === $error;
	}

	/**
	 * Whether a named index exists on a plugin table.
	 *
	 * SHOW INDEX works on the harness's TEMPORARY tables the same way it does on
	 * real ones, so this sees the index dbDelta applied during install.
	 *
	 * @param string $suffix   Unprefixed table suffix.
	 * @param string $key_name The index name to look for.
	 * @return bool
	 */
	private function index_exists( string $suffix, string $key_name ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		foreach ( (array) $rows as $row ) {
			// Key_name is MySQL's own column name from SHOW INDEX, not a plugin property.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( isset( $row->Key_name ) && $key_name === $row->Key_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Installing creates all four OAuth tables and records the schema version.
	 */
	public function test_install_creates_all_four_tables(): void {
		aafm_install_oauth_tables();

		$this->assertTrue( $this->table_exists( 'aafm_oauth_clients' ) );
		$this->assertTrue( $this->table_exists( 'aafm_oauth_codes' ) );
		$this->assertTrue( $this->table_exists( 'aafm_oauth_access_tokens' ) );
		$this->assertTrue( $this->table_exists( 'aafm_oauth_consents' ) );

		$this->assertNotEmpty( get_option( 'aafm_oauth_schema_version' ) );
	}

	/**
	 * The access-tokens table carries an index on refresh_parent_id.
	 *
	 * The refresh-chain reuse-detection and chain-revocation walks query
	 * WHERE refresh_parent_id = %d; without this index that is a full table scan on
	 * a hot, security-critical path. dbDelta adds the KEY on a fresh install and on
	 * re-run for existing v1 installs once the schema version bumps to '2'.
	 */
	public function test_access_tokens_indexes_refresh_parent_id(): void {
		aafm_install_oauth_tables();

		$this->assertTrue(
			$this->index_exists( 'aafm_oauth_access_tokens', 'refresh_parent_id' ),
			'Expected a refresh_parent_id index on the access-tokens table.'
		);
	}

	/**
	 * Install records schema version 2 (the refresh_parent_id index bump).
	 */
	public function test_install_records_schema_version_2(): void {
		aafm_install_oauth_tables();

		$this->assertSame( '2', get_option( 'aafm_oauth_schema_version' ) );
		$this->assertSame( '2', AAFM_OAUTH_SCHEMA_VERSION );
	}

	/**
	 * Installing twice is a no-op the second time (no error, tables still present).
	 */
	public function test_install_is_idempotent(): void {
		aafm_install_oauth_tables();
		aafm_install_oauth_tables();

		$this->assertTrue( $this->table_exists( 'aafm_oauth_clients' ) );
		$this->assertTrue( $this->table_exists( 'aafm_oauth_codes' ) );
		$this->assertTrue( $this->table_exists( 'aafm_oauth_access_tokens' ) );
		$this->assertTrue( $this->table_exists( 'aafm_oauth_consents' ) );
	}

	/**
	 * The upgrade runs the installer when the recorded schema version is missing.
	 */
	public function test_upgrade_runs_when_version_missing(): void {
		aafm_install_oauth_tables();
		delete_option( 'aafm_oauth_schema_version' );

		aafm_maybe_upgrade_oauth_tables();

		$this->assertSame(
			AAFM_OAUTH_SCHEMA_VERSION,
			get_option( 'aafm_oauth_schema_version' )
		);
	}

	/**
	 * The upgrade is a no-op when the recorded version already matches.
	 */
	public function test_upgrade_is_noop_when_current(): void {
		aafm_install_oauth_tables();

		aafm_maybe_upgrade_oauth_tables();

		$this->assertSame(
			AAFM_OAUTH_SCHEMA_VERSION,
			get_option( 'aafm_oauth_schema_version' )
		);
	}
}
