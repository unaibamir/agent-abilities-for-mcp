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

	/**
	 * M11: the candidate scan used to fetch only the first 50 users ordered by ID, so an
	 * app-password holder created after the 50th user was invisible to the dashboard. Querying
	 * by the application-passwords meta key directly removes the arbitrary page bound.
	 */
	public function test_agent_user_candidates_finds_a_holder_past_the_old_fifty_user_cap(): void {
		for ( $i = 0; $i < 55; $i++ ) {
			self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}
		$late_holder = self::factory()->user->create( array( 'role' => 'editor' ) );
		WP_Application_Passwords::create_new_application_password( $late_holder, array( 'name' => 'mcp-late' ) );

		$ids = wp_list_pluck( aafm_agent_user_candidates(), 'id' );

		$this->assertContains( $late_holder, $ids );
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
		foreach ( array( 'MCP endpoint', 'PHP', 'abilities', 'Audit' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
	}

	public function test_dashboard_shows_abilities_off_state(): void {
		update_option( 'aafm_enabled_abilities', array() );
		ob_start();
		aafm_render_dashboard_tab();
		$html = ob_get_clean();
		// The compact stat treatment replaces the embedded notice: with nothing enabled the
		// Enabled-abilities card prompts the operator to turn some on.
		$this->assertStringContainsString( 'Turn some on to start', $html );
	}

	public function test_dashboard_warns_when_admin_holds_app_password(): void {
		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'aafm-admin-agent',
			)
		);
		WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => 'mcp-admin' ) );

		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		// The security signal is preserved as a warn pill plus the offending login, but reframed:
		// it is about an admin who can reach the API, not "your agent users".
		$this->assertStringContainsString( 'aafm-pill aafm-pill-warn', $html );
		$this->assertStringContainsString( 'Review access', $html );
		$this->assertStringContainsString( 'can reach the API and manage this site', $html );
		$this->assertStringContainsString( 'aafm-admin-agent', $html );
		// The old wording that called them agent users to replace is gone.
		$this->assertStringNotContainsString( 'Review role', $html );

		// This admin app-password holder is NOT a plugin-created agent, so the card value stays 0
		// and reads the empty state - the number never implies the admin is an agent user.
		$this->assertStringContainsString( 'No agent user yet', $html );
		$this->assertStringNotContainsString( 'Created by this plugin', $html );
	}

	/**
	 * The Agent Users card VALUE counts only agent users this plugin created (the marker). A bare
	 * app-password holder does not inflate it, while the separate admin-access caution still fires.
	 */
	public function test_agent_users_card_value_counts_only_marked_agents(): void {
		// One plugin-created agent (marked) + one unrelated admin holding an app password.
		aafm_create_agent_user( 'mcp-agent' );
		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'stray-admin',
			)
		);
		WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => 'stray' ) );

		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		// Card value reflects the single MARKED agent, not the two app-password holders.
		$this->assertStringContainsString( 'Created by this plugin', $html );
		$this->assertStringNotContainsString( 'No agent user yet', $html );
		// The admin-access caution still fires for the stray admin, separately from the value.
		$this->assertStringContainsString( 'Review access', $html );
		$this->assertStringContainsString( 'stray-admin', $html );
	}

	public function test_agent_users_card_empty_state_with_no_app_passwords_at_all(): void {
		// No app-password holders and no created agent: value 0, empty state, no caution pill.
		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No agent user yet', $html );
		$this->assertStringNotContainsString( 'Review access', $html );
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
		// with BOTH row states reachable: a plugin-created (marked) agent user makes
		// the connect step "done", while no enabled abilities keeps the abilities step
		// "to do". The transaction fixture rolls every change back, so live state is untouched.
		aafm_create_agent_user( 'mcp-agent' );
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

	public function test_dashboard_setup_collapses_when_every_step_is_complete(): void {
		// Drive all three steps done: a plugin-created (marked) agent user, at least one
		// enabled ability, and one logged call. The transaction fixture rolls it all back.
		aafm_create_agent_user( 'mcp-agent' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
			)
		);

		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		// Steps are always rendered now, inside a collapsible <details class="aafm-setup">.
		$this->assertStringContainsString( '<details class="aafm-setup"', $html );
		$this->assertStringContainsString( 'aafm-step', $html );
		// Complete → collapsed: the <details> carries no open attribute.
		$this->assertStringNotContainsString( 'class="aafm-setup" open', $html );
		// The summary reads the complete copy, not "Finish setting up".
		$this->assertStringContainsString( 'Setup complete', $html );
		$this->assertStringNotContainsString( 'Finish setting up', $html );
		// The standalone success notice is gone (no notice + gap under it).
		$this->assertStringNotContainsString( 'All set', $html );
		$this->assertStringNotContainsString( 'aafm-notice-success', $html );
	}

	public function test_dashboard_setup_stays_open_when_incomplete(): void {
		// Nothing enabled and no agent connected, so at least one step is still pending.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'aafm_enabled_abilities', array() );

		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		// Incomplete → expanded: the open attribute is present and the prompt copy + count show.
		$this->assertStringContainsString( 'class="aafm-setup" open', $html );
		$this->assertStringContainsString( 'Finish setting up', $html );
		$this->assertStringContainsString( 'aafm-setup-count', $html );
	}

	public function test_setup_steps_reflect_real_state(): void {
		// Fresh fixture: nothing enabled, no grant, no agent user, no activity.
		update_option( 'aafm_enabled_abilities', array() );
		$steps = aafm_setup_steps();
		$this->assertCount( 3, $steps );
		$this->assertFalse( $steps[0]['done'] ); // Abilities - none enabled.
		$this->assertFalse( $steps[1]['done'] ); // Connect - no grant, no agent user.
		$this->assertFalse( $steps[2]['done'] ); // First call - no activity.
	}

	public function test_connect_step_done_via_created_agent_user(): void {
		// A user THIS plugin created (carrying the marker) satisfies the connect step.
		aafm_create_agent_user( 'mcp-agent' );
		$steps = aafm_setup_steps();
		$this->assertTrue( $steps[1]['done'] );
	}

	/**
	 * The whole point of the fix: a bare application-password holder (Jetpack, a mobile app,
	 * some other REST integration) is NOT a connected agent, so it must not flip the connect
	 * step to done. Only the plugin's own marker or a live OAuth grant may.
	 */
	public function test_connect_step_not_done_from_bare_app_password_holder(): void {
		update_option( 'aafm_enabled_abilities', array() );
		$holder = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_Application_Passwords::create_new_application_password( $holder, array( 'name' => 'jetpack' ) );

		// It is a security-warning candidate...
		$candidate_ids = wp_list_pluck( aafm_agent_user_candidates(), 'id' );
		$this->assertContains( $holder, $candidate_ids );
		// ...but it carries no marker, so it is not a created agent user...
		$this->assertFalse( aafm_has_created_agent_user() );
		// ...and the connect step stays "To do".
		$steps = aafm_setup_steps();
		$this->assertFalse( $steps[1]['done'] );
	}

	public function test_created_agent_user_helpers_track_the_marker(): void {
		$this->assertFalse( aafm_has_created_agent_user() );
		$this->assertSame( array(), aafm_created_agent_users() );

		$result = aafm_create_agent_user( 'mcp-agent' );
		$this->assertIsArray( $result );

		$this->assertTrue( aafm_has_created_agent_user() );
		$this->assertContains( (int) $result['user_id'], aafm_created_agent_users() );
	}

	public function test_has_oauth_grant_false_without_grants(): void {
		$this->assertFalse( aafm_has_oauth_grant() );
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
