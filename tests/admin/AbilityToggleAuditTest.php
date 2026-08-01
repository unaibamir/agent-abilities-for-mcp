<?php
/**
 * Enable/disable toggles leave an audit row, one per ability that actually changed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilityToggleAuditTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_a_newly_enabled_ability_writes_one_enabled_row(): void {
		$written = aafm_log_ability_toggle_diff( array( 'aafm/get-posts' ), array( 'aafm/get-posts', 'aafm/create-page' ) );
		$this->assertSame( 1, $written );

		$rows = aafm_query_activity( array( 'per_page' => 5 ) );
		$this->assertSame( 'ability_enabled', $rows[0]['event_type'] );
		$this->assertSame( 'aafm/create-page', $rows[0]['ability'] );
		$this->assertSame( 'Enabled aafm/create-page', $rows[0]['detail'] );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	public function test_a_disabled_ability_writes_one_disabled_row(): void {
		$written = aafm_log_ability_toggle_diff( array( 'aafm/create-page' ), array() );
		$this->assertSame( 1, $written );
		$rows = aafm_query_activity( array( 'per_page' => 5 ) );
		$this->assertSame( 'ability_disabled', $rows[0]['event_type'] );
		$this->assertSame( 'Disabled aafm/create-page', $rows[0]['detail'] );
	}

	public function test_an_unchanged_save_writes_nothing(): void {
		$this->assertSame( 0, aafm_log_ability_toggle_diff( array( 'aafm/get-posts' ), array( 'aafm/get-posts' ) ) );
	}

	public function test_order_alone_is_not_a_change(): void {
		$this->assertSame( 0, aafm_log_ability_toggle_diff( array( 'a/b', 'c/d' ), array( 'c/d', 'a/b' ) ) );
	}

	public function test_a_bulk_enable_writes_one_row_each(): void {
		$after = array( 'a/one', 'a/two', 'a/three' );
		$this->assertSame( 3, aafm_log_ability_toggle_diff( array(), $after ) );
	}

	public function test_the_acting_user_is_recorded(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		aafm_log_ability_toggle_diff( array(), array( 'a/one' ) );
		$rows = aafm_query_activity( array( 'per_page' => 1 ) );
		$this->assertSame( $user_id, (int) $rows[0]['principal_user_id'] );
	}

	public function test_flipping_the_master_switch_writes_a_setting_changed_row(): void {
		aafm_log_high_risk_switch_change( false, true );
		$rows = aafm_query_activity( array( 'per_page' => 1 ) );
		$this->assertSame( 'setting_changed', $rows[0]['event_type'] );
		// The Event column (both the initial render and the AJAX-paginated one) puts the raw
		// `ability` value straight into the cell with no fallback to event_type, so a blank
		// ability here used to mean a blank Event cell for every master-switch row. This synthetic,
		// ability-like name is what makes the row readable in the log, the same way
		// aafm/activity-log-cleared does for the log-cleared marker.
		$this->assertSame( 'aafm/high-risk-abilities-unlocked', $rows[0]['ability'] );
		$this->assertSame( 'High-risk abilities unlocked', $rows[0]['detail'] );
	}

	public function test_locking_it_again_writes_the_opposite_row(): void {
		aafm_log_high_risk_switch_change( true, false );
		$rows = aafm_query_activity( array( 'per_page' => 1 ) );
		$this->assertSame( 'High-risk abilities locked', $rows[0]['detail'] );
	}

	public function test_saving_without_changing_it_writes_nothing(): void {
		$before = aafm_activity_count_filtered();
		aafm_log_high_risk_switch_change( true, true );
		$this->assertSame( $before, aafm_activity_count_filtered() );
	}
}
