<?php
/**
 * Plugin reset: clears every configuration option and the activity log, while leaving the
 * agent user and any agent-created content (posts, etc.) untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class ResetPluginTest extends TestCase {

	/**
	 * The canonical list must cover every configuration option the plugin stores, so a reset
	 * never silently leaves stale config behind when a new option is added.
	 */
	public function test_config_option_names_lists_every_known_config_option(): void {
		$names = oversio_config_option_names();
		foreach (
			array(
				'oversio_enabled_abilities',
				'oversio_allowed_post_types',
				'oversio_allowed_meta_keys',
				'oversio_rate_limit_per_min',
				'oversio_max_title_len',
				'oversio_log_retention_days',
				'oversio_force_draft',
				'oversio_ip_allowlist',
				'oversio_denied_meta_keys',
				'oversio_exposed_user_meta_keys',
				'oversio_denied_user_meta_keys',
				'oversio_exposed_term_meta_keys',
				'oversio_denied_term_meta_keys',
			) as $expected
		) {
			$this->assertContains( $expected, $names );
		}
	}

	/**
	 * Reset clears the three Slice C meta-governance options. They are covered automatically
	 * by the config-list loop in oversio_reset_plugin(); this pins each of the new ones explicitly
	 * so a future edit that drops one from oversio_config_option_names() is caught.
	 */
	public function test_reset_clears_meta_governance_options(): void {
		// Reset truncates the activity-log + OAuth tables; install them so it runs without
		// emitting "table doesn't exist" output (which marks the test risky).
		oversio_install_activity_log();
		oversio_install_oauth_tables();
		update_option( 'oversio_denied_meta_keys', array( 'secret_key' ) );
		update_option( 'oversio_exposed_user_meta_keys', array( 'profile_color' ) );
		update_option( 'oversio_denied_user_meta_keys', array( 'private_note' ) );
		update_option( 'oversio_exposed_term_meta_keys', array( 'seo_title' ) );
		update_option( 'oversio_denied_term_meta_keys', array( 'term_secret' ) );

		oversio_reset_plugin();

		$this->assertFalse( get_option( 'oversio_denied_meta_keys', false ) );
		$this->assertFalse( get_option( 'oversio_exposed_user_meta_keys', false ) );
		$this->assertFalse( get_option( 'oversio_denied_user_meta_keys', false ) );
		$this->assertFalse( get_option( 'oversio_exposed_term_meta_keys', false ) );
		$this->assertFalse( get_option( 'oversio_denied_term_meta_keys', false ) );
	}

	/**
	 * Reset wipes all configuration and empties the activity log, but must never delete the
	 * agent user or content the agent created — that is the whole contract of the feature.
	 */
	public function test_reset_clears_config_and_log_but_preserves_user_and_content(): void {
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-posts' ) );
		update_option( 'oversio_allowed_post_types', array( 'post' ) );
		update_option( 'oversio_allowed_meta_keys', array( 'featured_subtitle' ) );
		update_option( 'oversio_rate_limit_per_min', 30 );
		update_option( 'oversio_max_title_len', 80 );
		update_option( 'oversio_force_draft', true );
		update_option( 'oversio_ip_allowlist', array( '10.0.0.1' ) );

		oversio_install_activity_log();
		oversio_install_oauth_tables();
		$agent_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id  = self::factory()->post->create( array( 'post_author' => $agent_id ) );
		oversio_log_activity(
			array(
				'ability'           => 'oversio/get-posts',
				'principal_user_id' => $agent_id,
				'principal_login'   => 'mcp-agent',
				'status'            => 'success',
				'arg_keys'          => array( 'per_page' ),
			)
		);
		$this->assertGreaterThan( 0, oversio_activity_count(), 'Seed row should be present before reset.' );

		// Seed one row into each of the four OAuth data tables.
		$this->seed_oauth_rows( $agent_id );
		foreach ( oversio_oauth_table_suffixes() as $suffix ) {
			$this->assertSame( 1, $this->oauth_row_count( $suffix ), "OAuth table {$suffix} should hold a seed row before reset." );
		}

		oversio_reset_plugin();

		// Every configuration option is gone (default returned).
		foreach ( oversio_config_option_names() as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} should be deleted by reset." );
		}

		// Activity log emptied.
		$this->assertSame( 0, oversio_activity_count(), 'Activity log should be empty after reset.' );

		// Every OAuth data table emptied.
		foreach ( oversio_oauth_table_suffixes() as $suffix ) {
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
		$wpdb->insert( $wpdb->prefix . 'oversio_oauth_clients', array( 'client_id' => 'client-reset-test' ) );
		$wpdb->insert( $wpdb->prefix . 'oversio_oauth_codes', array( 'code_hash' => 'code-reset-test' ) );
		$wpdb->insert(
			$wpdb->prefix . 'oversio_oauth_access_tokens',
			array(
				'token_hash'   => 'token-reset-test',
				'refresh_hash' => 'refresh-reset-test',
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'oversio_oauth_consents',
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
		oversio_render_settings_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'oversio-reset-plugin', $html );
		$this->assertStringContainsString( 'oversio-danger', $html );
	}
}
