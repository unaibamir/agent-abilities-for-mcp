<?php
/**
 * Plugin reset: clears every configuration option and the activity log, while leaving the
 * agent user and any agent-created content (posts, etc.) untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ResetPluginTest extends TestCase {

	/**
	 * The canonical list must cover every configuration option the plugin stores, so a reset
	 * never silently leaves stale config behind when a new option is added.
	 */
	public function test_config_option_names_lists_every_known_config_option(): void {
		$names = aafm_config_option_names();
		foreach (
			array(
				'aafm_enabled_abilities',
				'aafm_allowed_post_types',
				'aafm_allowed_meta_keys',
				'aafm_rate_limit_per_min',
				'aafm_max_title_len',
				'aafm_force_draft',
				'aafm_ip_allowlist',
				'aafm_denied_meta_keys',
				'aafm_exposed_user_meta_keys',
				'aafm_denied_user_meta_keys',
			) as $expected
		) {
			$this->assertContains( $expected, $names );
		}
	}

	/**
	 * Reset clears the three Slice C meta-governance options. They are covered automatically
	 * by the config-list loop in aafm_reset_plugin(); this pins each of the new ones explicitly
	 * so a future edit that drops one from aafm_config_option_names() is caught.
	 */
	public function test_reset_clears_meta_governance_options(): void {
		// Reset truncates the activity-log + OAuth tables; install them so it runs without
		// emitting "table doesn't exist" output (which marks the test risky).
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		update_option( 'aafm_denied_meta_keys', array( 'secret_key' ) );
		update_option( 'aafm_exposed_user_meta_keys', array( 'profile_color' ) );
		update_option( 'aafm_denied_user_meta_keys', array( 'private_note' ) );

		aafm_reset_plugin();

		$this->assertFalse( get_option( 'aafm_denied_meta_keys', false ) );
		$this->assertFalse( get_option( 'aafm_exposed_user_meta_keys', false ) );
		$this->assertFalse( get_option( 'aafm_denied_user_meta_keys', false ) );
	}

	/**
	 * Reset wipes all configuration and empties the activity log, but must never delete the
	 * agent user or content the agent created — that is the whole contract of the feature.
	 */
	public function test_reset_clears_config_and_log_but_preserves_user_and_content(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_allowed_post_types', array( 'post' ) );
		update_option( 'aafm_allowed_meta_keys', array( 'featured_subtitle' ) );
		update_option( 'aafm_rate_limit_per_min', 30 );
		update_option( 'aafm_max_title_len', 80 );
		update_option( 'aafm_force_draft', true );
		update_option( 'aafm_ip_allowlist', array( '10.0.0.1' ) );

		aafm_install_activity_log();
		aafm_install_oauth_tables();
		$agent_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id  = self::factory()->post->create( array( 'post_author' => $agent_id ) );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'principal_user_id' => $agent_id,
				'principal_login'   => 'mcp-agent',
				'status'            => 'success',
				'arg_keys'          => array( 'per_page' ),
			)
		);
		$this->assertGreaterThan( 0, aafm_activity_count(), 'Seed row should be present before reset.' );

		// Seed one row into each of the four OAuth data tables.
		$this->seed_oauth_rows( $agent_id );
		foreach ( aafm_oauth_table_suffixes() as $suffix ) {
			$this->assertSame( 1, $this->oauth_row_count( $suffix ), "OAuth table {$suffix} should hold a seed row before reset." );
		}

		aafm_reset_plugin();

		// Every configuration option is gone (default returned).
		foreach ( aafm_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} should be deleted by reset." );
		}

		// Activity log emptied.
		$this->assertSame( 0, aafm_activity_count(), 'Activity log should be empty after reset.' );

		// Every OAuth data table emptied.
		foreach ( aafm_oauth_table_suffixes() as $suffix ) {
			$this->assertSame( 0, $this->oauth_row_count( $suffix ), "OAuth table {$suffix} should be empty after reset." );
		}

		// Agent user and agent-created content survive.
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $agent_id ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( $agent_id, (int) get_post( $post_id )->post_author );
	}

	/**
	 * Insert one minimal-but-valid row into each of the four OAuth data tables.
	 *
	 * @param int $agent_id The seeded agent user id, reused for the consent row.
	 * @return void
	 */
	private function seed_oauth_rows( int $agent_id ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'aafm_oauth_clients', array( 'client_id' => 'client-reset-test' ) );
		$wpdb->insert( $wpdb->prefix . 'aafm_oauth_codes', array( 'code_hash' => 'code-reset-test' ) );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => 'token-reset-test',
				'refresh_hash' => 'refresh-reset-test',
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $agent_id,
				'client_id'  => 'client-reset-test',
			)
		);
	}

	/**
	 * Count rows in one OAuth table by suffix, seeing the temporary fixture table the
	 * same way the plugin's own queries do.
	 *
	 * @param string $suffix Unprefixed OAuth table suffix.
	 * @return int
	 */
	private function oauth_row_count( string $suffix ): int {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The Settings tab must expose the destructive control with the JS hook id and a Danger zone.
	 */
	public function test_settings_render_exposes_reset_control(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-reset-plugin', $html );
		$this->assertStringContainsString( 'aafm-danger', $html );
	}
}
