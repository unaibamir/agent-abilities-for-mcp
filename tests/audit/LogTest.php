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
		global $wpdb;
		$table = $wpdb->prefix . 'aafm_activity_log';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
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
}
