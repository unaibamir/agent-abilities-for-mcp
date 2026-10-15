<?php
/**
 * The default-server switch through the Settings save.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class DefaultServerSaveTest extends TestCase {

	use AjaxHarness;

	public function tear_down(): void {
		delete_option( 'aafm_default_server_mode' );
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	public function test_save_stores_the_mode_and_logs_one_row(): void {
		$this->post_as( 'administrator', array( 'aafm_default_server_mode' => 'on' ) );

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( 'on', get_option( 'aafm_default_server_mode' ) );
		$this->assertSame( 'on', $json['data']['aafm_default_server_mode'] );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/default-server-mode' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'setting_changed', $rows[0]['event_type'] );
		$this->assertStringContainsString( 'on', (string) $rows[0]['detail'] );
	}

	public function test_an_unknown_value_is_stored_as_auto(): void {
		$this->post_as( 'administrator', array( 'aafm_default_server_mode' => 'sideways' ) );

		$this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertSame( 'auto', aafm_default_server_mode() );
	}

	public function test_a_save_without_the_field_leaves_the_mode_alone(): void {
		update_option( 'aafm_default_server_mode', 'off' );
		$this->post_as( 'administrator', array( 'aafm_rate_limit_per_min' => '0' ) );

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertSame( 'off', get_option( 'aafm_default_server_mode' ) );
		$this->assertSame( 'off', $json['data']['aafm_default_server_mode'] );
		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'aafm/default-server-mode' ) ) );
	}

	public function test_a_non_admin_cannot_change_the_mode(): void {
		update_option( 'aafm_default_server_mode', 'off' );
		$this->post_as( 'editor', array( 'aafm_default_server_mode' => 'on' ) );

		$this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertSame( 'off', aafm_default_server_mode() );
	}

	public function test_settings_tab_renders_the_select_with_the_current_mode_and_the_honest_note(): void {
		update_option( 'aafm_default_server_mode', 'on' );
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="aafm_default_server_mode"', $html );
		$this->assertMatchesRegularExpression( '/<option value="on"[^>]*selected/', $html );
		$this->assertStringContainsString( 'not governed by this plugin', $html );
	}

	/**
	 * M2 (Task 1 review): a non-scalar posted brief is "not posted", so the stored brief survives.
	 */
	public function test_a_non_scalar_site_brief_leaves_the_stored_brief_alone(): void {
		update_option( 'aafm_site_brief', 'Keep this.' );
		$this->post_as( 'administrator', array( 'aafm_site_brief' => array( 'not', 'text' ) ) );

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertSame( 'Keep this.', get_option( 'aafm_site_brief' ) );
		$this->assertSame( 'Keep this.', $json['data']['aafm_site_brief'] );
		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'aafm/site-brief' ) ) );
	}

	/**
	 * M3 (Task 1 review): the brief option is never autoloaded.
	 */
	public function test_the_site_brief_is_stored_without_autoload(): void {
		global $wpdb;
		delete_option( 'aafm_site_brief' );
		$this->post_as( 'administrator', array( 'aafm_site_brief' => 'Be brief.' ) );

		$this->run_handler( 'aafm_ajax_save_settings' );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'aafm_site_brief' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading the stored autoload flag.
		$this->assertContains( $autoload, array( 'off', 'no' ), 'The brief must not be autoloaded.' );
	}
}
