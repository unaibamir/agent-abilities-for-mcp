<?php
/**
 * Dashboard read-only data helpers: agent candidates, counts, protocol version.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;
use WP_Application_Passwords;

final class DashboardTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_agent_user_candidates_flags_admins(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$sub   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => 'mcp-a' ) );
		WP_Application_Passwords::create_new_application_password( $sub, array( 'name' => 'mcp-b' ) );
		$cands = aafm_agent_user_candidates();
		$ids   = wp_list_pluck( $cands, 'id' );
		$this->assertContains( $admin, $ids );
		$this->assertContains( $sub, $ids );
		$admin_row = current( array_filter( $cands, static fn( $c ) => $c['id'] === $admin ) );
		$this->assertTrue( $admin_row['is_admin'] );
	}

	public function test_candidates_expose_no_pii(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		WP_Application_Passwords::create_new_application_password( $user, array( 'name' => 'mcp-c' ) );
		$cands = aafm_agent_user_candidates();
		$row   = current( array_filter( $cands, static fn( $c ) => $c['id'] === $user ) );
		$this->assertSame(
			array( 'id', 'login', 'roles', 'is_admin' ),
			array_keys( $row )
		);
	}

	public function test_enabled_count_and_protocol(): void {
		$this->assertIsInt( aafm_enabled_ability_count() );
		$this->assertIsInt( aafm_total_ability_count() );
		$this->assertNotEmpty( aafm_mcp_protocol_version() );
	}

	public function test_activity_count_reflects_rows(): void {
		$this->assertSame( 0, aafm_activity_count() );
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/trash-post',
				'status'  => 'denied',
			)
		);
		$count = aafm_activity_count();
		$this->assertIsInt( $count );
		$this->assertSame( 2, $count );
	}

	public function test_dashboard_renders_cards(): void {
		ob_start();
		aafm_render_dashboard_tab();
		$html = ob_get_clean();
		foreach ( array( 'Endpoint', 'PHP', 'abilities', 'Audit' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
	}

	public function test_dashboard_warns_when_no_abilities_enabled(): void {
		update_option( 'aafm_enabled_abilities', array() );
		ob_start();
		aafm_render_dashboard_tab();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'aafm-notice-warning', $html );
	}

	public function test_dashboard_warns_when_agent_user_can_manage_site(): void {
		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'aafm-admin-agent',
			)
		);
		WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => 'mcp-admin' ) );

		ob_start();
		aafm_render_dashboard_tab();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'aafm-notice-warning', $html );
		$this->assertStringContainsString( 'aafm-admin-agent', $html );
	}

	public function test_dashboard_renders_setup_checklist_and_stat_grid(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-setup', $html );
		$this->assertStringContainsString( 'aafm-step', $html );
		$this->assertStringContainsString( 'aafm-stat-grid', $html );
		$this->assertStringContainsString( 'Enabled abilities', $html );   // Content preserved.
	}

	public function test_dashboard_setup_checklist_emits_styling_classes_when_incomplete(): void {
		// Force a partial setup so the checklist (not the "all set" notice) renders
		// with BOTH row states reachable: an agent user with an app password makes
		// step 1 "done", while no enabled abilities keeps step 2 "to do". The
		// transaction fixture rolls every change back, so live state is untouched.
		$agent = self::factory()->user->create( array( 'role' => 'editor' ) );
		WP_Application_Passwords::create_new_application_password( $agent, array( 'name' => 'mcp-setup' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'aafm_enabled_abilities', array() );

		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		// Checklist container + header count, proving the markup path renders.
		$this->assertStringContainsString( 'aafm-setup', $html );
		$this->assertStringContainsString( 'aafm-setup-count', $html );
		// Both row states are reachable: at least one done step and the incomplete one.
		$this->assertStringContainsString( 'aafm-step-todo', $html );
		$this->assertStringContainsString( 'aafm-step-done', $html );
		// State pill text is present for the to-do step.
		$this->assertStringContainsString( 'aafm-step-state', $html );
		// The "all set" success notice must NOT short-circuit the checklist here.
		$this->assertStringNotContainsString( 'All set', $html );
	}

	public function test_setup_steps_reflect_real_state(): void {
		update_option( 'aafm_enabled_abilities', array() );
		$steps = aafm_setup_steps();
		$this->assertFalse( $steps[1]['done'] ); // No abilities enabled.
	}

	public function test_default_tab_is_dashboard(): void {
		$this->acting_as( 'administrator' );
		unset( $_GET['tab'] );

		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		// The dashboard wrapper renders, and the Dashboard nav tab is marked active.
		$this->assertStringContainsString( 'aafm-dashboard', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'tab=dashboard', $html );
	}
}
