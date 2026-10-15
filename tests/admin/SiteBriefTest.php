<?php
/**
 * Site Brief: the owner's standing rules, cleaned on save and on read, delivered to every agent
 * through the MCP server's start-up instructions, writable only by the owner.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class SiteBriefTest extends TestCase {

	use AjaxHarness;

	public function tear_down(): void {
		delete_option( 'aafm_site_brief' );
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	public function test_cleaner_strips_markup_nul_and_bidi_override_characters(): void {
		$raw = "British spelling.\x00<script>alert(1)</script>\u{202E}Never touch the pricing page.<b>Drafts</b> only.";
		$this->assertSame(
			'British spelling.Never touch the pricing page.Drafts only.',
			aafm_clean_site_brief( $raw )
		);
	}

	public function test_cleaner_caps_length_in_characters_not_bytes(): void {
		$clean = aafm_clean_site_brief( str_repeat( "\u{00E9}", 5000 ) );
		$this->assertSame( AAFM_SITE_BRIEF_MAX, mb_strlen( $clean ) );
	}

	public function test_cleaner_normalizes_line_endings_and_collapses_blank_runs(): void {
		$this->assertSame( "Line one\n\nLine two", aafm_clean_site_brief( "Line one\r\n\r\n\r\n\r\nLine two" ) );
	}

	public function test_reader_cleans_a_value_written_straight_to_the_database(): void {
		update_option( 'aafm_site_brief', "x\x00y" );
		$this->assertSame( 'xy', aafm_site_brief() );
	}

	public function test_reader_returns_empty_for_a_row_that_is_not_a_string(): void {
		update_option( 'aafm_site_brief', array( 'not', 'text' ) );
		$this->assertSame( '', aafm_site_brief() );
	}

	public function test_description_is_the_plain_sentence_without_a_brief(): void {
		$this->assertSame( 'Curated, governed WordPress abilities for AI agents.', aafm_server_description() );
	}

	public function test_description_appends_the_brief_after_the_sentence(): void {
		update_option( 'aafm_site_brief', "British spelling.\nDrafts only on Fridays." );
		$description = aafm_server_description();
		$this->assertStringStartsWith( 'Curated, governed WordPress abilities for AI agents.', $description );
		$this->assertStringEndsWith( "British spelling.\nDrafts only on Fridays.", $description );
	}

	public function test_save_stores_the_cleaned_brief_and_logs_one_row_without_the_text(): void {
		$this->post_as( 'administrator', array( 'aafm_site_brief' => "British spelling.\x00 Drafts only on Fridays." ) );

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( 'British spelling. Drafts only on Fridays.', get_option( 'aafm_site_brief' ) );
		$this->assertSame( 'British spelling. Drafts only on Fridays.', $json['data']['aafm_site_brief'] );

		$rows = aafm_query_activity( array( 'ability' => 'aafm/site-brief' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'setting_changed', $rows[0]['event_type'] );
		$this->assertStringNotContainsString( 'British', (string) wp_json_encode( $rows ) );
	}

	public function test_a_save_without_the_field_leaves_the_stored_brief_alone(): void {
		update_option( 'aafm_site_brief', 'Keep me.' );
		$this->post_as( 'administrator', array( 'aafm_rate_limit_per_min' => '0' ) );

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( 'Keep me.', get_option( 'aafm_site_brief' ) );
		$this->assertSame( 'Keep me.', $json['data']['aafm_site_brief'] );
		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'aafm/site-brief' ) ) );
	}

	public function test_a_save_that_changes_nothing_logs_nothing(): void {
		update_option( 'aafm_site_brief', 'Same text.' );
		$this->post_as( 'administrator', array( 'aafm_site_brief' => 'Same text.' ) );

		$this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'aafm/site-brief' ) ) );
	}

	public function test_an_empty_save_clears_the_brief_and_the_description_reverts(): void {
		update_option( 'aafm_site_brief', 'Old rules.' );
		$this->post_as( 'administrator', array( 'aafm_site_brief' => '' ) );

		$this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertSame( '', aafm_site_brief() );
		$this->assertSame( 'Curated, governed WordPress abilities for AI agents.', aafm_server_description() );
	}

	public function test_a_non_admin_cannot_save_the_brief(): void {
		$this->post_as( 'editor', array( 'aafm_site_brief' => 'Editor tries.' ) );

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( '', aafm_site_brief() );
	}

	public function test_brief_is_in_the_reset_list_and_a_reset_clears_it(): void {
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		$this->assertContains( 'aafm_site_brief', aafm_config_option_names() );
		update_option( 'aafm_site_brief', 'Rules.' );

		$this->assertTrue( aafm_reset_plugin() );

		$this->assertSame( '', aafm_site_brief() );
	}

	public function test_no_agent_ability_can_write_the_brief(): void {
		$this->assertNotContains( 'aafm_site_brief', aafm_allowed_site_settings() );
	}

	public function test_settings_tab_renders_the_textarea_with_the_escaped_current_text(): void {
		update_option( 'aafm_site_brief', 'Tom & Jerry rules.' );
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="aafm_site_brief"', $html );
		$this->assertStringContainsString( 'Tom &amp; Jerry rules.', $html );
		$this->assertStringContainsString( 'Every agent that connects to this plugin&#039;s endpoint gets this text', $html, "The brief reaches this plugin's endpoint only, not other plugins' servers." );
	}
}
