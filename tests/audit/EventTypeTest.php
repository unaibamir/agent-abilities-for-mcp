<?php
/**
 * The activity-log event-type vocabulary: the six literals every later caller binds to.
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

	public function test_event_type_vocabulary_is_the_documented_six(): void {
		$this->assertSame(
			array( 'ability_call', 'ability_enabled', 'ability_disabled', 'ability_enable_blocked', 'setting_changed', 'log_cleared' ),
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

	public function test_detail_sanitiser_strips_markup_and_collapses_whitespace(): void {
		$this->assertSame(
			'Created page #482',
			aafm_sanitize_activity_detail( "  Created  <b>page</b>\n#482 " )
		);
	}

	public function test_detail_sanitiser_caps_length(): void {
		$this->assertSame( 255, strlen( aafm_sanitize_activity_detail( str_repeat( 'a', 400 ) ) ) );
	}

	public function test_a_caller_that_supplies_neither_key_gets_the_defaults(): void {
		$id  = aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$row = $this->fetch_row( $id );
		$this->assertSame( 'ability_call', $row['event_type'] );
		$this->assertNull( $row['detail'] );
	}

	public function test_an_unknown_event_type_falls_back_to_ability_call(): void {
		$id = aafm_log_activity(
			array(
				'ability'    => 'x',
				'status'     => 'success',
				'event_type' => 'nonsense',
			)
		);
		$this->assertSame( 'ability_call', $this->fetch_row( $id )['event_type'] );
	}

	public function test_a_known_event_type_and_detail_are_stored(): void {
		$id  = aafm_log_activity(
			array(
				'ability'    => 'aafm/wc-create-refund',
				'status'     => 'success',
				'event_type' => 'ability_enabled',
				'detail'     => 'Enabled wc-create-refund',
			)
		);
		$row = $this->fetch_row( $id );
		$this->assertSame( 'ability_enabled', $row['event_type'] );
		$this->assertSame( 'Enabled wc-create-refund', $row['detail'] );
	}

	public function test_update_status_without_detail_leaves_the_column_untouched(): void {
		$id = aafm_log_activity(
			array(
				'ability' => 'aafm/create-page',
				'status'  => 'started',
				'detail'  => 'seeded',
			)
		);
		aafm_update_activity_status( $id, 'success' );
		$this->assertSame( 'seeded', $this->fetch_row( $id )['detail'] );
	}

	public function test_update_status_writes_detail_when_supplied(): void {
		$id = aafm_log_activity(
			array(
				'ability' => 'aafm/create-page',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $id, 'success', null, 'Created page #482' );
		$this->assertSame( 'Created page #482', $this->fetch_row( $id )['detail'] );
	}

	/**
	 * Read a written row straight back out of the table.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>
	 */
	private function fetch_row( int $id ): array {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
	}
}
