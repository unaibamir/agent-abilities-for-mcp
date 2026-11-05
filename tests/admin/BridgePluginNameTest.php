<?php
/**
 * Bridged tools name the plugin behind them, and never present a guess as a plugin name.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\TestCase;

final class BridgePluginNameTest extends TestCase {

	use ForeignAbilities;

	public function tear_down(): void {
		$this->unregister_foreign_abilities();
		parent::tear_down();
	}

	/**
	 * Make a set of plugins look installed and active.
	 *
	 * @param array<string,array<string,string>> $plugins Plugin file => header data.
	 * @return void
	 */
	private function seed_active_plugins( array $plugins ): void {
		wp_cache_set( 'plugins', array( '' => $plugins ), 'plugins' );
		update_option( 'active_plugins', array_keys( $plugins ) );
	}

	public function test_a_known_namespace_keeps_its_label(): void {
		$this->assertSame( 'Yoast SEO', aafm_bridge_display_label( 'wordpress-seo' ) );
		$this->assertSame( 'Yoast SEO', aafm_bridge_provider_label( 'wordpress-seo' ) );
	}

	public function test_an_integration_card_label_is_used(): void {
		$this->assertSame( 'WooCommerce', aafm_bridge_provider_label( 'woocommerce' ) );
	}

	public function test_an_active_plugin_folder_match_gives_the_header_name(): void {
		$this->seed_active_plugins(
			array(
				'wpforms-lite/wpforms.php' => array(
					'Name'       => 'WPForms Lite',
					'TextDomain' => 'wpforms-lite',
				),
			)
		);

		$this->assertSame( 'WPForms Lite', aafm_bridge_active_plugin_name( 'wpforms-lite' ), 'The folder matches.' );
		$this->assertSame( 'WPForms Lite', aafm_bridge_display_label( 'wpforms' ), 'The main-file name matches.' );
	}

	public function test_a_text_domain_match_gives_the_header_name(): void {
		$this->seed_active_plugins(
			array(
				'some-folder/loader.php' => array(
					'Name'       => 'Domain Plugin',
					'TextDomain' => 'domain-plugin',
				),
			)
		);

		$this->assertSame( 'Domain Plugin', aafm_bridge_provider_label( 'domain-plugin' ) );
	}

	public function test_an_inactive_plugin_is_not_matched(): void {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'inactive-thing/inactive-thing.php' => array(
						'Name'       => 'Inactive Thing',
						'TextDomain' => 'inactive-thing',
					),
				),
			),
			'plugins'
		);
		update_option( 'active_plugins', array() );

		$this->assertSame( '', aafm_bridge_provider_label( 'inactive-thing' ) );
		$this->assertSame( 'Inactive Thing', aafm_bridge_display_label( 'inactive-thing' ), 'The Title Case guess only happens to match; the heading is just a label.' );
	}

	public function test_an_unmatched_namespace_falls_back_to_title_case(): void {
		$this->assertSame( 'Events Manager', aafm_bridge_display_label( 'events-manager' ) );
		$this->assertSame( '', aafm_bridge_provider_label( 'events-manager' ) );
	}

	public function test_an_unresolved_namespace_is_called_a_namespace_not_a_plugin_name(): void {
		$this->seed_active_plugins(
			array(
				'acme-writer/acme-writer.php' => array(
					'Name'       => 'Acme Writer',
					'TextDomain' => 'acme-writer',
				),
			)
		);

		$message = aafm_bridge_attribute_error( 'writer/draft-summary', new \WP_Error( 'x_failed', 'Quota exceeded.' ) )->get_error_message();

		$this->assertStringContainsString( 'registers tools under the "writer" namespace', $message );
		$this->assertStringContainsString( 'Quota exceeded.', $message );
		$this->assertStringNotContainsString( 'The Writer plugin', $message );
	}

	public function test_a_hostile_header_name_is_cleaned(): void {
		$hostile = "<b>x</b>\n" . str_repeat( 'a', 200 );
		$clean   = aafm_bridge_clean_plugin_name( $hostile );

		$this->assertStringNotContainsString( '<', $clean );
		$this->assertStringNotContainsString( "\n", $clean );
		$this->assertLessThanOrEqual( 60, strlen( $clean ) );
		$this->assertSame( 'x ' . str_repeat( 'a', 58 ), $clean );
		$this->assertSame( '', aafm_bridge_clean_plugin_name( "<i></i>\n\t " ), 'Nothing left is an empty string.' );

		$this->seed_active_plugins(
			array(
				'hostile/hostile.php' => array(
					'Name'       => $hostile,
					'TextDomain' => 'hostile',
				),
			)
		);
		$message = aafm_bridge_attribute_error( 'hostile/run', new \WP_Error( 'x_failed', 'Nope.' ) )->get_error_message();
		$this->assertSame( 'The ' . $clean . ' plugin returned an error: Nope.', $message, 'The agent reads the cleaned, capped name.' );
	}

	/**
	 * The error path on the real MCP route, in a process booted the way a REST request boots.
	 *
	 * A plain PHP child, not @runInSeparateProcess: the route only serves tools from the process-wide
	 * server, which is built once, and the WP test library loads the whole admin API at bootstrap.
	 * WordPress itself has required wp-admin/includes/plugin.php from wp-settings.php since 6.7, on
	 * every supported version, so a "get_plugins() is undefined" start is not reachable; the guard the
	 * next test pins is defence for a plugin that runs this before wp-settings.php gets there.
	 */
	public function test_a_bridged_error_over_the_real_mcp_route_names_the_plugin(): void {
		// Let go of this test's own row locks, or the child's option writes wait on them and fail.
		$GLOBALS['wpdb']->query( 'ROLLBACK' );

		$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
		$command   = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( AAFM_PLUGIN_DIR . 'tests/Fixtures/bridged-error-over-mcp-route.php' ) . ' ' . escapeshellarg( $tests_dir ) . ' 2>&1';
		$output    = (string) shell_exec( $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- launching the test's own child process.

		$marker = strrpos( $output, 'AAFM_CHILD_RESULT:' );
		$this->assertNotFalse( $marker, 'The child reports a result. Its output: ' . $output );
		$child = json_decode( trim( substr( $output, $marker + strlen( 'AAFM_CHILD_RESULT:' ) ) ), true );
		$this->assertIsArray( $child );

		$this->assertTrue( $child['session'], 'The handshake gave a session.' );
		$this->assertStringContainsString( 'The WPForms Lite plugin returned an error: Quota exceeded.', $child['wire'] );
	}

	public function test_the_resolver_requires_the_admin_plugin_file_before_calling_get_plugins(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/bridge-labels.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file.

		$guard = strpos( $source, "if ( ! function_exists( 'get_plugins' ) ) {" );
		$load  = strpos( $source, "require_once ABSPATH . 'wp-admin/includes/plugin.php';" );
		$call  = strpos( $source, '= get_plugins();' );

		$this->assertNotFalse( $guard );
		$this->assertNotFalse( $load );
		$this->assertNotFalse( $call );
		$this->assertLessThan( $load, $guard );
		$this->assertLessThan( $call, $load );
	}
}
