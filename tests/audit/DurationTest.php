<?php
/**
 * Call duration: schema v6, the resolve write, the missing-column fallback, and what is measured.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class DurationTest extends TestCase {

	public function test_schema_is_version_six_and_verify_requires_the_duration_column(): void {
		$this->assertSame( '6', AAFM_ACTIVITY_LOG_SCHEMA_VERSION );
		$this->assertTrue( aafm_activity_log_has_column( aafm_activity_log_table(), 'duration_ms' ) );
		$this->assertTrue( aafm_activity_log_schema_verify() );
	}

	public function test_verify_fails_when_the_duration_column_is_missing_and_the_stamp_does_not_advance(): void {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN duration_ms', $table ) );
		update_option( 'aafm_activity_log_schema_version', '5' );

		$this->assertFalse( aafm_activity_log_schema_verify(), 'A table without duration_ms is not v6.' );
		aafm_activity_log_finalize_schema();
		$this->assertSame( '5', get_option( 'aafm_activity_log_schema_version' ), 'The stamp must not advance over a missing column.' );

		// Put the healthy table back for later tests on this connection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );
		aafm_install_activity_log();
		$this->assertTrue( aafm_activity_log_schema_verify() );
		$this->assertSame( '6', get_option( 'aafm_activity_log_schema_version' ) );
	}

	public function test_a_successful_call_records_a_duration(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/get-taxonomies' ) );

		wp_get_ability( 'aafm/get-taxonomies' )->execute( array() );

		$rows = aafm_query_activity( array( 'ability' => 'aafm/get-taxonomies' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertNotNull( $rows[0]['duration_ms'], 'A finished call has a measured duration.' );
		$this->assertGreaterThanOrEqual( 0, (int) $rows[0]['duration_ms'] );
	}

	public function test_a_denied_call_has_no_duration(): void {
		$this->acting_as( 'subscriber' );
		$this->register_enabled( array( 'aafm/get-activity-log' ) );

		wp_get_ability( 'aafm/get-activity-log' )->execute( array() );

		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/get-activity-log',
				'status'  => 'denied',
			)
		);
		$this->assertNotEmpty( $rows );
		$this->assertNull( $rows[0]['duration_ms'], 'A call that never executed was not measured.' );
	}

	public function test_the_writer_clamps_the_duration(): void {
		$id = aafm_log_activity(
			array(
				'ability' => 'aafm/x',
				'status'  => 'started',
			)
		);
		$this->assertTrue( aafm_update_activity_status( $id, 'success', null, null, -5 ) );
		$this->assertSame( 0, (int) aafm_query_activity( array( 'ability' => 'aafm/x' ) )[0]['duration_ms'] );

		$id2 = aafm_log_activity(
			array(
				'ability' => 'aafm/y',
				'status'  => 'started',
			)
		);
		$this->assertTrue( aafm_update_activity_status( $id2, 'success', null, null, PHP_INT_MAX ) );
		$this->assertSame( 4294967295, (int) aafm_query_activity( array( 'ability' => 'aafm/y' ) )[0]['duration_ms'] );
	}

	public function test_a_resolve_without_a_duration_leaves_the_column_null(): void {
		$id = aafm_log_activity(
			array(
				'ability' => 'aafm/z',
				'status'  => 'started',
			)
		);
		$this->assertTrue( aafm_update_activity_status( $id, 'success' ) );
		$this->assertNull( aafm_query_activity( array( 'ability' => 'aafm/z' ) )[0]['duration_ms'] );
	}

	/**
	 * Review Focus 1: a table that has not been upgraded yet makes the UPDATE that names
	 * duration_ms fail. The status must still resolve, nothing may print, and only the duration is lost.
	 */
	public function test_a_missing_duration_column_never_loses_the_status(): void {
		global $wpdb;
		$id = aafm_log_activity(
			array(
				'ability' => 'aafm/old-table',
				'status'  => 'started',
			)
		);

		// Arm the real-error filter by hand. QueryFaultInjector::break_query_with_real_error() calls
		// $wpdb->suppress_errors( true ) itself, which would hide a missing suppress_errors() in the
		// production code and keep this test green. Here only the production code can silence the error.
		QueryFaultInjector::reset_fired_count();
		$filter     = QueryFaultInjector::real_error_filter( 'duration_ms', 1 );
		$shown      = $wpdb->show_errors( true );
		$suppressed = $wpdb->suppress_errors( false );
		add_filter( 'query', $filter );
		ob_start();
		try {
			$written = aafm_update_activity_status( $id, 'success', 3, null, 42 );
		} finally {
			$printed = (string) ob_get_clean();
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppressed );
			$wpdb->show_errors( $shown );
		}

		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'The fault must have fired, or this test proves nothing.' );
		$this->assertSame( '', $printed, 'A failed duration write must print nothing, even with errors shown.' );
		$this->assertTrue( $written, 'The retry without the column must land.' );
		$row = aafm_query_activity( array( 'ability' => 'aafm/old-table' ) )[0];
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( '3', (string) $row['result_count'] );
		$this->assertNull( $row['duration_ms'] );
	}
}
