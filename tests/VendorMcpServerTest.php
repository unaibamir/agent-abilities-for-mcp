<?php
/**
 * The Connection Doctor row for another plugin's MCP server on the same site (WP Rocket, Rank Math).
 *
 * The row is a disclosure. It makes no request and switches nothing off. A declared class cannot
 * be removed, so this file runs at the very end of the suite and its first test covers the site
 * without that class.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class VendorMcpServerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_oauth_enabled' );
	}

	public function tear_down(): void {
		delete_option( 'aafm_oauth_enabled' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_no_row_on_a_site_without_the_other_server(): void {
		if ( class_exists( 'WPMedia\MCP\OAuth\Bootstrap', false ) ) {
			$this->markTestSkipped( 'Another test already declared the stub class in this process. The acceptance run covers a site without WP Rocket.' );
		}
		update_option( 'aafm_oauth_enabled', '1' );
		$this->assertNull( aafm_doctor_check_vendor_mcp() );
	}

	public function test_warns_when_both_plugins_answer_the_discovery_addresses(): void {
		aafm_siteops_stub_wp_rocket_mcp_activate();
		update_option( 'aafm_oauth_enabled', '1' );
		$this->assertTrue( aafm_oauth_enabled() );

		$row = aafm_doctor_check_vendor_mcp();
		$this->assertIsArray( $row );
		$this->assertSame( 'vendor_mcp', $row['id'] );
		$this->assertSame( 'Other MCP servers', $row['label'] );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'other', $row['who'], 'Another plugin\'s MCP server is not a security or membership plugin.' );
		$this->assertStringContainsString( 'this plugin answers them first', $row['detail'] );
		$this->assertStringContainsString( 'This plugin has not switched anything off.', $row['detail'] );
		$this->assertStringNotContainsString( 'not affected', $row['detail'], 'No connection was tested, so the row must not promise one.' );
	}

	public function test_passes_when_sign_in_through_this_plugin_is_off(): void {
		aafm_siteops_stub_wp_rocket_mcp_activate();
		$this->assertFalse( aafm_oauth_enabled() );

		$row = aafm_doctor_check_vendor_mcp();
		$this->assertIsArray( $row );
		$this->assertSame( 'vendor_mcp', $row['id'] );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( '', $row['who'], 'A pass row names no one to fix it, so the support report prints no label.' );
		$this->assertSame( 'Another plugin on this site, such as WP Rocket or Rank Math, also runs an MCP server. It answers its own discovery addresses.', $row['detail'] );
	}

	public function test_the_rows_do_not_name_wp_rocket_alone(): void {
		// Rank Math 1.0.282 bundles and boots the same WPMedia\MCP\OAuth\Bootstrap (rank-math.php:268), so the
		// class alone cannot say which plugin runs the server.
		aafm_siteops_stub_wp_rocket_mcp_activate();
		update_option( 'aafm_oauth_enabled', '1' );

		$row = aafm_doctor_check_vendor_mcp();
		$this->assertIsArray( $row );
		$this->assertStringStartsWith( 'Another plugin on this site, such as WP Rocket or Rank Math, also runs an MCP server.', $row['detail'] );
		$this->assertStringNotContainsString( "WP Rocket's server", $row['detail'] );

		require_once AAFM_PLUGIN_DIR . 'includes/siteops/cache/status-only.php';
		$status = array_values( array_filter( aafm_site_cache_status_only(), static fn( array $r ): bool => 'vendor_mcp_server' === $r['kind'] ) );
		$this->assertCount( 1, $status );
		$this->assertSame( 'MCP server from another plugin', $status[0]['label'] );
	}

	public function test_the_row_is_part_of_a_doctor_run_and_sends_nothing_of_its_own(): void {
		aafm_siteops_stub_wp_rocket_mcp_activate();
		$this->acting_as( 'administrator' );
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				++$requests;
				return new \WP_Error( 'http_request_failed', 'blocked by the test' );
			}
		);
		$before = $requests;
		aafm_doctor_check_vendor_mcp();
		$this->assertSame( $before, $requests, 'The row makes no request.' );

		$ids = array_column( aafm_doctor_run_checks(), 'id' );
		$this->assertContains( 'vendor_mcp', $ids );
	}

	public function test_the_other_who_line_names_another_plugin_not_a_security_plugin(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$page = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/page.php' );
		$this->assertMatchesRegularExpression( "/other:\\s*this\\.#t\\(\\s*'doctorWhoOther',\\s*'Another plugin on this site may be the cause'/", $js );
		$this->assertMatchesRegularExpression( "/'doctorWhoOther'\\s*=>\\s*__\\(\\s*'Another plugin on this site may be the cause'/", $page );
	}
}
