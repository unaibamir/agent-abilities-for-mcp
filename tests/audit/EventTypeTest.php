<?php
/**
 * The activity-log event-type vocabulary: the five literals every later caller binds to.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class EventTypeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
	}

	public function test_event_type_vocabulary_is_the_documented_five(): void {
		$this->assertSame(
			array( 'ability_call', 'ability_enabled', 'ability_disabled', 'setting_changed', 'log_cleared' ),
			aafm_activity_event_types()
		);
	}

	public function test_ability_call_is_first_so_it_reads_as_the_default(): void {
		$types = aafm_activity_event_types();
		$this->assertSame( 'ability_call', $types[0] );
	}

	public function test_schema_version_is_five(): void {
		$this->assertSame( '5', AAFM_ACTIVITY_LOG_SCHEMA_VERSION );
	}

	public function test_table_carries_both_new_columns(): void {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
		$by_name = array_column( $columns, null, 'Field' );

		$this->assertArrayHasKey( 'event_type', $by_name );
		$this->assertSame( 'NO', $by_name['event_type']['Null'] );
		$this->assertSame( 'ability_call', $by_name['event_type']['Default'] );

		$this->assertArrayHasKey( 'detail', $by_name );
		$this->assertSame( 'YES', $by_name['detail']['Null'] );
		$this->assertNull( $by_name['detail']['Default'] );
	}

	public function test_event_created_index_exists(): void {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$this->assertContains( 'event_created', array_column( $indexes, 'Key_name' ) );
	}
}
