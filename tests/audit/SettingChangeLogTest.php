<?php
/**
 * Tests for the setting_changed row helper.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

/**
 * The setting_changed helper writes one row for the acting admin.
 */
final class SettingChangeLogTest extends TestCase {

	public function test_it_writes_one_row_with_the_admin_as_principal_and_returns_its_id(): void {
		$admin = $this->acting_as( 'administrator' );

		$ok  = aafm_log_setting_change( 'aafm_pause_all', 'Paused all access' );
		$err = aafm_log_setting_change( 'aafm_pause_all', 'Could not resume', false );

		$this->assertGreaterThan( 0, $ok );
		$this->assertGreaterThan( $ok, $err );
		$rows = aafm_query_activity( array( 'ability' => 'aafm_pause_all' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability filter, not a meta query.
		$this->assertCount( 2, $rows );
		$by_id = array_column( $rows, null, 'id' );
		$this->assertSame( 'setting_changed', $by_id[ $ok ]['event_type'] );
		$this->assertSame( 'success', $by_id[ $ok ]['status'] );
		$this->assertSame( 'error', $by_id[ $err ]['status'] );
		$this->assertSame( $admin, (int) $by_id[ $ok ]['principal_user_id'] );
		$this->assertSame( 'Paused all access', $by_id[ $ok ]['detail'] );
	}
}
