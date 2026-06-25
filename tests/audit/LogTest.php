<?php
/**
 * Audit log table + writer/query/clear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Audit;

use Oversio\Tests\TestCase;

final class LogTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
	}

	public function test_table_exists_after_install(): void {
		$this->assertTrue( $this->activity_log_table_exists() );
	}

	public function test_write_then_query_returns_row_with_arg_keys_only(): void {
		oversio_log_activity(
			array(
				'ability'           => 'oversio/get-posts',
				'principal_user_id' => 7,
				'principal_login'   => 'agent',
				'status'            => 'success',
				'arg_keys'          => array( 'post_type', 'status' ),
			)
		);

		$rows = oversio_query_activity( array( 'per_page' => 10 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'oversio/get-posts', $rows[0]['ability'] );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'post_type,status', $rows[0]['arg_keys'] );
		$this->assertSame( 7, (int) $rows[0]['principal_user_id'] );
	}

	public function test_denied_status_is_persisted(): void {
		oversio_log_activity(
			array(
				'ability'  => 'oversio/trash-post',
				'status'   => 'denied',
				'arg_keys' => array( 'post_id' ),
			)
		);
		$rows = oversio_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_clear_empties_the_table(): void {
		oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'success',
			)
		);
		oversio_clear_activity_log();
		$this->assertCount( 0, oversio_query_activity( array() ) );
	}

	public function test_started_row_is_updated_in_place_not_duplicated(): void {
		$id = oversio_log_activity(
			array(
				'ability'  => 'oversio/get-posts',
				'status'   => 'started',
				'arg_keys' => array( 'post_type' ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		oversio_update_activity_status( $id, 'success' );

		$rows = oversio_query_activity( array() );
		$this->assertCount( 1, $rows ); // one row per call, updated in place.
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'post_type', $rows[0]['arg_keys'] );
	}
}
