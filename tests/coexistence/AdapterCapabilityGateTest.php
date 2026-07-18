<?php
/**
 * Coexistence: the request-time capability-gate assertion (L5 / pentest F3).
 *
 * The version floor only proves the loaded adapter copy REPORTS an in-range version. A sibling that
 * pre-declares an in-range copy with the mcp_adapter_tools_list filter stripped would clear the floor
 * yet silently disable the per-connection capability gate. These tests cover the source-level
 * assertion that catches that, and the fail-safe that refuses to serve when the gate is absent.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class AdapterCapabilityGateTest extends TestCase {

	private function fixture( string $variant ): string {
		return AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterCapabilityGate/' . $variant . '/ToolsHandler.php';
	}

	public function test_scanner_detects_present_capability_filter(): void {
		$this->assertTrue(
			aafm_adapter_file_applies_tools_list_filter( $this->fixture( 'with-gate' ) ),
			'A handler that applies mcp_adapter_tools_list must be reported as carrying the gate.'
		);
	}

	public function test_scanner_detects_stripped_capability_filter(): void {
		// An in-range copy whose apply_filters() call was removed must read as gate-absent.
		$this->assertFalse(
			aafm_adapter_file_applies_tools_list_filter( $this->fixture( 'stripped' ) ),
			'A handler missing the apply_filters call must be reported as gate-absent.'
		);
	}

	public function test_scanner_fails_safe_on_unreadable_file(): void {
		$this->assertFalse(
			aafm_adapter_file_applies_tools_list_filter( AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterCapabilityGate/does-not-exist.php' )
		);
		$this->assertFalse( aafm_adapter_file_applies_tools_list_filter( '' ) );
	}

	public function test_real_loaded_adapter_carries_the_gate(): void {
		// The bundled 0.5.0 ToolsHandler our eager load committed must pass - the assertion must not
		// false-reject the legitimate copy and disable the plugin on a normal install.
		$this->assertTrue(
			class_exists( 'WP\\MCP\\Handlers\\Tools\\ToolsHandler', false ),
			'The adapter tools/list handler should be declared after the eager load.'
		);
		$this->assertTrue(
			aafm_adapter_capability_gate_present(),
			'The loaded bundled adapter copy still applies the mcp_adapter_tools_list filter.'
		);
	}

	public function test_guard_refuses_to_serve_when_gate_absent(): void {
		// Simulate server.php's pending registration, then apply the fail-safe for an absent gate.
		add_action( 'mcp_adapter_init', 'aafm_register_mcp_server' );
		$this->assertNotFalse( has_action( 'mcp_adapter_init', 'aafm_register_mcp_server' ) );

		aafm_apply_adapter_capability_gate_guard( false );

		$this->assertFalse(
			has_action( 'mcp_adapter_init', 'aafm_register_mcp_server' ),
			'An absent capability gate must unhook create_server so the /mcp route never registers.'
		);
		$this->assertNotFalse(
			has_action( 'admin_notices', 'aafm_notice_adapter_capability_gate_missing' ),
			'An absent capability gate must surface the fail-safe admin notice.'
		);
	}

	public function test_guard_is_noop_when_gate_present(): void {
		add_action( 'mcp_adapter_init', 'aafm_register_mcp_server' );

		aafm_apply_adapter_capability_gate_guard( true );

		$this->assertNotFalse(
			has_action( 'mcp_adapter_init', 'aafm_register_mcp_server' ),
			'A present capability gate must leave server registration untouched.'
		);
		$this->assertFalse(
			has_action( 'admin_notices', 'aafm_notice_adapter_capability_gate_missing' ),
			'A present capability gate must not raise the fail-safe notice.'
		);
	}
}
