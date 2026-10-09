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
	 * @param string            $extra   'skipmark' to leave out the WP_MCP_VERSION marker.
	 * @return array<string, mixed>
	 */
	private function run_loader( string $mode, array $active = array(), string $extra = '' ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterForeign/run.php' ) . ' '
			. escapeshellarg( $mode ) . ' '
			. escapeshellarg( rtrim( AAFM_PLUGIN_DIR, '/' ) ) . ' '
			. escapeshellarg( (string) wp_json_encode( $active ) ) . ' '
			. escapeshellarg( $extra ) . ' 2>&1';

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

	public function test_foreign_copy_gets_no_vendor_autoloader_and_its_gate_is_still_checked(): void {
		$result = $this->run_loader( 'foreign' );

		$this->assertFalse( $result['vendor_loaded'], 'Our Composer autoloader maps WP\\MCP\\ to our bundle, so it must stay unloaded.' );
		$this->assertSame( 0, $result['vendor_added'] );
		$this->assertTrue( $result['gate_present'], 'The gate check must resolve the handler through the other copy.' );
	}

	public function test_our_copy_loads_the_vendor_autoloader_and_passes_its_own_gate_check(): void {
		$result = $this->run_loader( 'none' );

		$this->assertTrue( $result['vendor_loaded'] );
		$this->assertGreaterThan( 0, $result['vendor_added'] );
		$this->assertTrue( $result['gate_present'] );
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

	public function test_without_the_marker_the_adapter_raises_its_bundled_copy_deprecation(): void {
		$result = $this->run_loader( 'none', array(), 'skipmark' );

		$this->assertSame( 'undefined', $result['const_after'] );
		$this->assertSame( array( 'WP\\MCP\\Core\\McpAdapter' ), $result['deprecations'] );
	}

	public function test_marking_our_copy_loaded_defines_the_constant_and_skips_the_deprecation(): void {
		$result = $this->run_loader( 'none' );

		$this->assertSame( 'undefined', $result['const_before'], 'Nothing may define the constant before the adapter is about to initialise.' );
		$this->assertSame( \WP\MCP\Core\McpAdapter::VERSION, $result['const_after'] );
		$this->assertSame( array(), $result['deprecations'], 'Query Monitor listens on the run action, so the adapter must not call _deprecated_function() at all.' );
	}

	public function test_marker_leaves_a_foreign_copy_alone(): void {
		$result = $this->run_loader( 'foreign' );

		$this->assertSame( 'undefined', $result['const_after'], 'The copy that is not ours decides for itself.' );
	}

	public function test_marker_runs_just_before_the_adapter_init_and_never_on_plain_requests(): void {
		$this->assertSame( 14, has_action( 'rest_api_init', 'aafm_mark_bundled_adapter_loaded' ), 'The adapter initialises on rest_api_init at 15.' );
		$this->assertFalse( has_action( 'init', 'aafm_mark_bundled_adapter_loaded' ), 'A web request must not define the constant on init, or plugins that read it see an adapter plugin on every page.' );
		$this->assertFalse( has_action( 'plugins_loaded', 'aafm_mark_bundled_adapter_loaded' ) );
	}
}
