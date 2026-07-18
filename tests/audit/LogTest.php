<?php
/**
 * Audit log table + writer/query/clear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class LogTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
	}

	public function test_table_exists_after_install(): void {
		$this->assertTrue( $this->activity_log_table_exists() );
	}

	public function test_write_then_query_returns_row_with_arg_keys_only(): void {
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'principal_user_id' => 7,
				'principal_login'   => 'agent',
				'status'            => 'success',
				'arg_keys'          => array( 'post_type', 'status' ),
			)
		);

		$rows = aafm_query_activity( array( 'per_page' => 10 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aafm/get-posts', $rows[0]['ability'] );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'post_type,status', $rows[0]['arg_keys'] );
		$this->assertSame( 7, (int) $rows[0]['principal_user_id'] );
	}

	public function test_denied_status_is_persisted(): void {
		aafm_log_activity(
			array(
				'ability'  => 'aafm/trash-post',
				'status'   => 'denied',
				'arg_keys' => array( 'post_id' ),
			)
		);
		$rows = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_clear_empties_the_table(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		aafm_clear_activity_log();
		$this->assertCount( 0, aafm_query_activity( array() ) );
	}

	public function test_started_row_is_updated_in_place_not_duplicated(): void {
		$id = aafm_log_activity(
			array(
				'ability'  => 'aafm/get-posts',
				'status'   => 'started',
				'arg_keys' => array( 'post_type' ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		aafm_update_activity_status( $id, 'success' );

		$rows = aafm_query_activity( array() );
		$this->assertCount( 1, $rows ); // one row per call, updated in place.
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'post_type', $rows[0]['arg_keys'] );
	}

	/**
	 * M16: the activity log carries a client_id column so an OAuth-attributed call can be
	 * traced back to the client that made it. Additive dbDelta migration, mirroring the pattern
	 * proven in tests/oauth/SchemaTest.php's test_scope_column_present_on_codes_and_tokens().
	 */
	public function test_client_id_column_exists_after_install(): void {
		$this->assertGreaterThanOrEqual( 1, $this->column_length( 'client_id' ), 'client_id column must exist.' );
	}

	/**
	 * A row written without a client_id (the Application Password / non-OAuth path, which is
	 * every call before this migration and every non-OAuth call after it) stores '' rather than
	 * NULL, so existing rows and existing aafm_log_activity() callers are unaffected.
	 */
	public function test_client_id_defaults_to_empty_string_when_not_supplied(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$rows = aafm_query_activity( array() );
		$this->assertSame( '', $rows[0]['client_id'] );
	}

	/**
	 * A supplied client_id round-trips through write and query.
	 */
	public function test_client_id_is_persisted_when_supplied(): void {
		aafm_log_activity(
			array(
				'ability'   => 'aafm/get-posts',
				'status'    => 'success',
				'client_id' => 'oauth-client-42',
			)
		);
		$rows = aafm_query_activity( array() );
		$this->assertSame( 'oauth-client-42', $rows[0]['client_id'] );
	}

	/**
	 * A stored schema version behind the current constant self-heals via the existing
	 * admin_init-hooked aafm_maybe_upgrade_activity_log() - no reactivation needed, an
	 * existing install picks up the new column the next time an admin page loads.
	 */
	public function test_upgrade_adds_client_id_on_existing_install(): void {
		update_option( 'aafm_activity_log_schema_version', '2' );

		aafm_maybe_upgrade_activity_log();

		$this->assertSame( AAFM_ACTIVITY_LOG_SCHEMA_VERSION, get_option( 'aafm_activity_log_schema_version' ) );
		$this->assertGreaterThanOrEqual( 1, $this->column_length( 'client_id' ) );
	}

	/**
	 * The declared length of a VARCHAR column on the activity-log table, or 0 when not found.
	 *
	 * @param string $column Column name.
	 * @return int
	 */
	private function column_length( string $column ): int {
		global $wpdb;
		$table = aafm_activity_log_table();

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
}
