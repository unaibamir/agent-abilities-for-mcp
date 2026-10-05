<?php
/**
 * Coexistence: our loader stays out of the way when another copy of McpAdapter is already declared.
 *
 * A plugin that loads before ours (the standalone mcp-adapter plugin, or a sibling that touches the
 * adapter at include time) commits PHP to its own WP\MCP\Core\McpAdapter. Declaring our classes
 * under it would mix two versions, so the loader declares nothing and registers no autoloader.
 * Each case runs in a clean PHP process because the real McpAdapter is already declared in this
 * one.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class AdapterForeignCopyTest extends TestCase {

	/**
	 * Run the loader fixture in its own PHP process and decode what it reported.
	 *
	 * @param string            $mode    'foreign' to declare another copy first, 'none' for a clean process.
	 * @param array<int,string> $active  Plugin files the process reports as active.
	 * @return array<string, mixed>
	 */
	private function run_loader( string $mode, array $active = array() ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterForeign/run.php' ) . ' '
			. escapeshellarg( $mode ) . ' '
			. escapeshellarg( rtrim( AAFM_PLUGIN_DIR, '/' ) ) . ' '
			. escapeshellarg( (string) wp_json_encode( $active ) ) . ' 2>&1';

		$output = (string) shell_exec( $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- test-only subprocess to isolate class declarations.
		$data   = json_decode( $output, true );

		$this->assertIsArray( $data, 'The loader fixture must print JSON, got: ' . $output );

		return $data;
	}

	public function test_foreign_copy_declared_first_means_we_declare_nothing(): void {
		$result = $this->run_loader( 'foreign' );

		$this->assertFalse( $result['loaded'], 'Our loader must skip when another copy already owns McpAdapter.' );
		$this->assertSame( 0, $result['autoloaders'], 'No WP\\MCP\\ autoloader may be registered.' );
		$this->assertFalse( $result['tools_handler'], 'None of our classes may be declared under a foreign McpAdapter.' );
		$this->assertFalse( $result['schema_loaded'] );
		$this->assertSame( '0.7.1', $result['version'], 'The foreign McpAdapter stays the one in use.' );
	}

	public function test_foreign_copy_still_gets_the_capability_gate_guard(): void {
		$result = $this->run_loader( 'foreign' );

		$this->assertContains(
			'mcp_adapter_init:aafm_guard_adapter_capability_gate',
			$result['actions'],
			'The per-connection gate guard must still check whichever copy is loaded.'
		);
	}

	public function test_no_foreign_copy_loads_our_bundle_unchanged(): void {
		$result = $this->run_loader( 'none' );

		$this->assertTrue( $result['loaded'] );
		$this->assertSame( 1, $result['autoloaders'], 'Exactly one prepended autoloader is registered.' );
		$this->assertTrue( $result['tools_handler'], 'The eager load declares every runtime class.' );
		$this->assertSame( \WP\MCP\Core\McpAdapter::VERSION, $result['version'] );
		$this->assertContains( 'mcp_adapter_init:aafm_guard_adapter_capability_gate', $result['actions'] );
	}

	public function test_declared_elsewhere_ignores_our_own_copy(): void {
		// The McpAdapter declared in this process came from our bundle, so it is not foreign.
		$this->assertFalse( aafm_adapter_declared_elsewhere() );
		$this->assertFalse( aafm_adapter_declared_elsewhere( 'WP\\MCP\\Core\\NotDeclaredAnywhere' ) );
	}

	public function test_declared_elsewhere_flags_a_class_outside_our_bundle(): void {
		$this->assertTrue( aafm_adapter_declared_elsewhere( self::class ) );
	}

	public function test_standalone_plugin_active_turns_off_its_autoloader_when_our_copy_loads(): void {
		$result = $this->run_loader( 'none', array( 'akismet/akismet.php', 'mcp-adapter/mcp-adapter.php' ) );

		$this->assertTrue( $result['loaded'] );
		$this->assertFalse( $result['autoload_const'], 'WP_MCP_AUTOLOAD must be false so the standalone plugin shows no conflict notice.' );
	}

	public function test_no_standalone_plugin_leaves_its_constant_alone(): void {
		$result = $this->run_loader( 'none', array( 'akismet/akismet.php' ) );

		$this->assertTrue( $result['loaded'] );
		$this->assertSame( 'undefined', $result['autoload_const'] );
	}

	public function test_standalone_plugin_loaded_first_is_not_touched(): void {
		$result = $this->run_loader( 'foreign', array( 'mcp-adapter/mcp-adapter.php' ) );

		$this->assertFalse( $result['loaded'] );
		$this->assertSame( 'undefined', $result['autoload_const'], 'The standalone copy is the one loaded, so its autoloader runs as normal.' );
	}

	public function test_standalone_plugin_detection_covers_site_and_network_activation(): void {
		$file = 'mcp-adapter/mcp-adapter.php';

		$this->assertTrue( aafm_standalone_adapter_plugin_active( array( 'a/a.php', $file ), array() ) );
		$this->assertTrue( aafm_standalone_adapter_plugin_active( array(), array( $file => 1700000000 ) ) );
		$this->assertFalse( aafm_standalone_adapter_plugin_active( array( 'a/a.php' ), array( 'b/b.php' => 1 ) ) );
		$this->assertFalse( aafm_standalone_adapter_plugin_active( array( 'mcp-adapter/other.php', 'x/mcp-adapter/mcp-adapter.php' ), array() ) );
		$this->assertFalse( aafm_standalone_adapter_plugin_active( array(), array() ) );
	}

	public function test_deprecation_filter_is_only_hooked_when_our_copy_loads(): void {
		$ours    = $this->run_loader( 'none' );
		$foreign = $this->run_loader( 'foreign' );

		$this->assertContains( 'deprecated_function_run:aafm_quiet_bundled_adapter_deprecation', $ours['actions'] );
		$this->assertNotContains( 'deprecated_function_run:aafm_quiet_bundled_adapter_deprecation', $foreign['actions'] );
	}

	public function test_adapter_deprecation_trigger_is_silenced_once_and_only_for_the_adapter(): void {
		$hook = 'deprecated_function_trigger_error';

		// A different deprecated function is left alone.
		aafm_quiet_bundled_adapter_deprecation( 'some_other_function' );
		$this->assertFalse( has_filter( $hook, 'aafm_suppress_one_deprecation_trigger' ) );

		// The adapter's own notice for our copy turns off the one trigger that follows.
		aafm_quiet_bundled_adapter_deprecation( \WP\MCP\Core\McpAdapter::class );
		$this->assertNotFalse( has_filter( $hook, 'aafm_suppress_one_deprecation_trigger' ) );

		remove_all_filters( $hook );
		aafm_quiet_bundled_adapter_deprecation( \WP\MCP\Core\McpAdapter::class );
		$this->assertFalse( apply_filters( $hook, true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core hook, held in a variable.
		$this->assertFalse( has_filter( $hook, 'aafm_suppress_one_deprecation_trigger' ), 'The one-shot filter must remove itself.' );
		$this->assertTrue( apply_filters( $hook, true ), 'The next deprecation is not silenced.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core hook, held in a variable.
	}
}
