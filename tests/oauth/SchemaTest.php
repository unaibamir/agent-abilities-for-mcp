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
	 * The declared length of a VARCHAR column, or 0 when not found.
	 *
	 * @param string $suffix Unprefixed table suffix.
	 * @param string $column Column name.
	 * @return int
	 */
	private function varchar_length( string $suffix, string $column ): int {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		foreach ( (array) $rows as $row ) {
			// Field/Type are MySQL's own SHOW COLUMNS column names.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( isset( $row->Field ) && $column === $row->Field && isset( $row->Type ) && preg_match( '/varchar\((\d+)\)/i', (string) $row->Type, $m ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return (int) $m[1];
			}
		}
		return 0;
	}

	/**
	 * T3-6: the resource (audience) column must be wide enough for long endpoint URLs so an
	 * audience match never fails on a truncated value.
	 */
	public function test_resource_column_holds_long_urls(): void {
		aafm_install_oauth_tables();

		$this->assertGreaterThanOrEqual( 512, $this->varchar_length( 'aafm_oauth_codes', 'resource' ), 'codes.resource must not truncate long URLs.' );
		$this->assertGreaterThanOrEqual( 512, $this->varchar_length( 'aafm_oauth_access_tokens', 'resource' ), 'access_tokens.resource must not truncate long URLs.' );

		// Round-trip a long resource through the token mint and read it back intact.
		$long_resource = 'https://' . str_repeat( 'sub.', 60 ) . 'example.com/wp-json/agent-abilities-for-mcp/mcp';
		$this->assertGreaterThan( 191, strlen( $long_resource ), 'fixture: the resource must exceed the old 191 cap.' );

		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => 7,
				'client_id'  => 'c',
				'resource'   => $long_resource,
			)
		);
		$this->assertIsArray( $tokens );

		$row = aafm_oauth_get_access_token_row( $tokens['access_token'] );
		$this->assertIsArray( $row );
		$this->assertSame( $long_resource, $row['resource'], 'A long resource must round-trip without truncation.' );
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
	 * The access-tokens table carries an index on client_id.
	 *
	 * The admin client listing's grouped token count and the revoke-by-client queries filter
	 * WHERE client_id = ...; without this index those are full table scans. dbDelta adds the KEY
	 * on a fresh install and on re-run for existing installs once the schema version bumps to '4'.
	 */
	public function test_access_tokens_indexes_client_id(): void {
		aafm_install_oauth_tables();

		$this->assertTrue(
			$this->index_exists( 'aafm_oauth_access_tokens', 'client_id' ),
			'Expected a client_id index on the access-tokens table.'
		);
	}

	/**
	 * Install records the current schema version. Asserted against the constant so a deliberate
	 * bump (v6 adds the persisted `scope` column on codes + access-tokens) does not require editing
	 * a literal here, only confirming the stored option tracks the constant.
	 */
	public function test_install_records_schema_version(): void {
		aafm_install_oauth_tables();

		$this->assertSame( AAFM_OAUTH_SCHEMA_VERSION, get_option( 'aafm_oauth_schema_version' ) );
		$this->assertSame( '7', AAFM_OAUTH_SCHEMA_VERSION );
	}

	/**
	 * The codes and access-tokens tables carry the persisted `scope` column (v6). The requested
	 * OAuth scope is recorded through code -> token so it is auditable and available to the
	 * aafm_oauth_token_capabilities narrowing seam; the column defaults to '' so existing rows
	 * and behaviour are unchanged.
	 */
	public function test_scope_column_present_on_codes_and_tokens(): void {
		aafm_install_oauth_tables();

		$this->assertGreaterThanOrEqual( 1, $this->varchar_length( 'aafm_oauth_codes', 'scope' ), 'codes.scope column must exist.' );
		$this->assertGreaterThanOrEqual( 1, $this->varchar_length( 'aafm_oauth_access_tokens', 'scope' ), 'access_tokens.scope column must exist.' );
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
	 * B5 sibling: the OAuth schema self-heal is hooked on admin_init only in 1.6.1, so a
	 * headless site whose plugin auto-updates over cron never upgrades its OAuth tables while
	 * bearer traffic keeps hitting them. Same cheap option-version gate, hooked on the REST
	 * path too.
	 */
	public function test_oauth_schema_self_heals_on_rest_traffic_not_only_admin(): void {
		$this->assertNotFalse(
			has_action( 'rest_api_init', 'aafm_maybe_upgrade_oauth_tables' ),
			'A REST-only site must self-heal the OAuth schema without an admin page load.'
		);

		aafm_install_oauth_tables();
		update_option( 'aafm_oauth_schema_version', '1' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately fire core's own REST init to prove the heal rides it.
		do_action( 'rest_api_init' );
		$this->assertSame(
			AAFM_OAUTH_SCHEMA_VERSION,
			get_option( 'aafm_oauth_schema_version' ),
			'Firing rest_api_init must bring a stale OAuth schema current.'
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

	/**
	 * A healthy install stamps the version and clears any prior failure flag.
	 */
	public function test_finalize_stamps_and_clears_error_on_healthy_schema(): void {
		set_transient( 'aafm_oauth_schema_error', time(), DAY_IN_SECONDS );

		aafm_install_oauth_tables();

		$this->assertSame( AAFM_OAUTH_SCHEMA_VERSION, get_option( 'aafm_oauth_schema_version' ) );
		$this->assertFalse( get_transient( 'aafm_oauth_schema_error' ), 'A healthy verify must clear the error flag.' );
	}

	/**
	 * The verify predicate reports the real state of the tables.
	 */
	public function test_schema_verify_true_when_installed_false_when_a_table_is_missing(): void {
		global $wpdb;

		aafm_install_oauth_tables();
		$this->assertTrue( aafm_oauth_schema_verify(), 'A fully installed schema must verify.' );

		// Drop one table (harness TEMPORARY form) so the schema is genuinely incomplete.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}aafm_oauth_codes" );
		$this->assertFalse( aafm_oauth_schema_verify(), 'A missing table must fail verify.' );

		// Restore the healthy schema for any later assertions on this connection.
		aafm_install_oauth_tables();
	}

	/**
	 * The core of F1: a failed migration must NOT advance the version, and must flag a bounded error,
	 * so the admin_init / rest_api_init self-heal keeps retrying instead of early-returning forever.
	 */
	public function test_finalize_does_not_advance_version_on_broken_schema(): void {
		global $wpdb;

		aafm_install_oauth_tables();

		// Pretend the install is still on an older version, and that no error is outstanding.
		update_option( 'aafm_oauth_schema_version', '1' );
		delete_transient( 'aafm_oauth_schema_error' );

		// Break the schema the way a refused ALTER would, then run only the finalize step (no dbDelta
		// re-heal) so the stamp decision sees the broken state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}aafm_oauth_codes" );

		aafm_oauth_finalize_schema();

		$this->assertSame( '1', get_option( 'aafm_oauth_schema_version' ), 'A failed verify must leave the prior version so the self-heal retries.' );
		$this->assertNotFalse( get_transient( 'aafm_oauth_schema_error' ), 'A failed verify must raise the bounded error flag.' );

		// Restore the healthy schema and version.
		aafm_install_oauth_tables();
		$this->assertSame( AAFM_OAUTH_SCHEMA_VERSION, get_option( 'aafm_oauth_schema_version' ) );
	}

	/**
	 * The CREATE statement (F3) of the two lifecycle tables declares InnoDB, so a fresh install is
	 * transactional. SHOW CREATE TABLE reveals the engine even for the harness's TEMPORARY tables.
	 *
	 * @param string $suffix Lifecycle table suffix.
	 * @return string The table's CREATE statement.
	 */
	private function show_create( string $suffix ): string {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );
		return is_array( $row ) && isset( $row[1] ) ? (string) $row[1] : '';
	}

	/**
	 * F3: new installs put the lifecycle tables on InnoDB so consume+mint can actually roll back.
	 */
	public function test_lifecycle_tables_are_created_on_innodb(): void {
		aafm_install_oauth_tables();

		foreach ( array( 'aafm_oauth_codes', 'aafm_oauth_access_tokens' ) as $suffix ) {
			$this->assertMatchesRegularExpression(
				'/ENGINE=InnoDB/i',
				$this->show_create( $suffix ),
				"{$suffix} must be created on InnoDB for the token transactions to roll back."
			);
		}
	}

	/**
	 * Enforcement is a safe no-op after a normal install: the lifecycle table is already InnoDB
	 * (some servers list it in information_schema even in its TEMPORARY form, others report the
	 * engine as unreadable), so either way there is nothing to ALTER and no warning to raise.
	 */
	public function test_engine_enforce_is_a_safe_noop_after_install(): void {
		global $wpdb;
		aafm_install_oauth_tables();

		$engine = aafm_oauth_table_engine( $wpdb->prefix . 'aafm_oauth_access_tokens' );
		$this->assertTrue(
			'' === $engine || 0 === strcasecmp( $engine, 'InnoDB' ),
			'A freshly installed lifecycle table must be InnoDB (or its engine unreadable), never a non-transactional engine.'
		);

		delete_transient( 'aafm_oauth_engine_warning' );
		aafm_oauth_enforce_lifecycle_engine();
		$this->assertFalse( get_transient( 'aafm_oauth_engine_warning' ), 'Enforcement must not warn for an InnoDB (or unreadable) table.' );
	}

	/**
	 * F3: a pre-existing non-InnoDB lifecycle table is converted by the guarded one-time ALTER that
	 * dbDelta cannot do. Uses a real (non-TEMPORARY) throwaway table, since information_schema does
	 * not list temporary tables; the harness's temporary-table rewrite is lifted only for this
	 * probe and always restored, and the throwaway table is always dropped.
	 */
	public function test_non_innodb_table_is_converted_to_innodb(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aafm_engine_probe';

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$created = $wpdb->query( "CREATE TABLE {$table} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=MyISAM" );

		try {
			if ( false === $created || 'MyISAM' !== aafm_oauth_table_engine( $table ) ) {
				$this->markTestSkipped( 'This server would not create a MyISAM table to convert.' );
			}

			$this->assertTrue( aafm_oauth_convert_table_to_innodb( $table ), 'The guarded ALTER must land the table on InnoDB.' );
			$this->assertSame( 'InnoDB', aafm_oauth_table_engine( $table ) );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}
}
