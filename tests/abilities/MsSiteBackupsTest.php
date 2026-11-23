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

	public function test_verify_now_answers_with_an_error_on_a_network(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die     = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		$handler = static fn() => $die;
		add_filter( 'wp_die_ajax_handler', $handler );

		ob_start();
		try {
			aafm_ajax_backup_verify_now();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$json = json_decode( (string) ob_get_clean(), true );

		remove_filter( 'wp_die_ajax_handler', $handler );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( 'multisite network', (string) ( $json['data']['message'] ?? '' ) );
	}

	public function test_the_check_is_never_scheduled_on_a_network(): void {
		update_option( 'aafm_backup_gate', '1' );
		aafm_backup_verify_reconcile();
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'The gate is on, but the check does not run on a network.' );

		wp_schedule_event( time() + 60, 'hourly', 'aafm_backup_verify' );
		aafm_backup_verify_reconcile();
		$this->assertFalse( wp_next_scheduled( 'aafm_backup_verify' ), 'An event left from before is cleared.' );
	}
}
