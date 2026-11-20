<?php
/**
 * On a multisite network the two backup tools are refused and the gate has no backup to count.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

/**
 * The backup tools on a multisite network.
 *
 * @group ms-required
 */
final class MsSiteBackupsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		aafm_updraft_stub_activate();
		while ( null !== aafm_executing_ability() ) {
			aafm_executing_ability_pop();
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'aafm_backup_gate_vouch' );
		aafm_updraft_stub_deactivate();
		parent::tear_down();
	}

	public function test_both_backup_tools_are_refused_on_a_network(): void {
		$status = aafm_exec_site_backup_status();
		$this->assertWPError( $status );
		$this->assertSame( 'aafm_site_multisite', $status->get_error_code() );

		$list = aafm_exec_site_list_backups( array() );
		$this->assertWPError( $list );
		$this->assertSame( 'aafm_site_multisite', $list->get_error_code() );
	}

	public function test_the_gate_refuses_on_a_network_unless_a_backup_is_vouched_for(): void {
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		update_option( 'aafm_backup_gate', '1' );
		update_option( 'aafm_backup_gate_abilities', array( 'aafm/delete-post' ) );

		$state = aafm_backup_gate_state();
		$this->assertSame( 'multisite', $state['provider'] );
		$this->assertSame( 'multisite', $state['reason'] );
		$this->assertFalse( $state['satisfied'] );
		$this->assertSame( 'backup_gate', aafm_backup_gate_refusal( 'aafm/delete-post' ) );

		add_filter( 'aafm_backup_gate_vouch', '__return_true' );
		$this->assertSame( '', aafm_backup_gate_refusal( 'aafm/delete-post' ) );
	}
}
