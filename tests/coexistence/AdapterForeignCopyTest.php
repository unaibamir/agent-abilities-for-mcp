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
	 * @param string $mode 'foreign' to declare another copy first, 'none' for a clean process.
	 * @return array<string, mixed>
	 */
	private function run_loader( string $mode ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterForeign/run.php' ) . ' '
			. escapeshellarg( $mode ) . ' '
			. escapeshellarg( rtrim( AAFM_PLUGIN_DIR, '/' ) ) . ' 2>&1';

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
}
