<?php
/**
 * The mcp_adapter_tool_call_result safety net for bridged results with no output_schema.
 *
 * When a bridged third-party ability declares no output_schema, aafm_bridge_output_schema()
 * deliberately omits output_schema from our wrapper's registration too (see includes/bridge.php,
 * aafm_register_enabled_bridged_abilities()), so core's WP_Ability::execute() -> validate_output()
 * short-circuits true without checking anything. Whatever the foreign ability returns then flows
 * straight through to structuredContent under our tool name. If that is a bare top-level JSON
 * array, strict MCP clients reject the response - upstream issue WordPress/mcp-adapter#253,
 * reproduced there against real WooCommerce REST list/report endpoints, which we bridge.
 *
 * aafm_filter_bridged_tool_call_result() hooks the adapter's mcp_adapter_tool_call_result filter
 * (fired after $mcp_tool->execute(), see ToolsHandler::handle_tool_call()) and wraps a bare
 * top-level list under a `data` key so the wire shape is always an object.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class BridgeToolCallResultFilterTest extends TestCase {

	/**
	 * The one defect this filter exists to close: a bridged ability's bare top-level list
	 * result must become an object on the wire.
	 */
	public function test_bridged_top_level_list_result_is_wrapped_in_a_data_object(): void {
		$result = aafm_filter_bridged_tool_call_result(
			array( 'a', 'b', 'c' ),
			array(),
			'aafm-bridge-vendor-lies-about-its-shape'
		);

		$this->assertSame( array( 'data' => array( 'a', 'b', 'c' ) ), $result );
	}

	/**
	 * An empty array is still a list (array_is_list(array()) === true; it JSON-encodes as [],
	 * the exact defect this filter guards against) and must be wrapped the same way a
	 * populated list is - there is no size threshold below which the wire shape stops mattering.
	 */
	public function test_bridged_empty_list_result_is_also_wrapped(): void {
		$result = aafm_filter_bridged_tool_call_result( array(), array(), 'aafm-bridge-vendor-empty' );

		$this->assertSame( array( 'data' => array() ), $result );
	}

	/**
	 * The negative case that proves this is a real check, not a blanket rewrap: a bridged
	 * ability that already returns a proper associative array passes through byte-for-byte
	 * unchanged, no `data` wrapper added.
	 */
	public function test_bridged_associative_array_result_passes_through_unchanged(): void {
		$original = array(
			'count' => 7,
			'items' => array( 'x', 'y' ),
		);

		$result = aafm_filter_bridged_tool_call_result( $original, array(), 'aafm-bridge-vendor-honest' );

		$this->assertSame( $original, $result );
	}

	/**
	 * A WP_Error result passes through untouched - the filter runs after execute() but must
	 * never turn a real error into a fabricated `data` payload.
	 */
	public function test_wp_error_result_passes_through_untouched(): void {
		$error = new WP_Error( 'aafm_bridge_missing', 'The bridged ability is no longer available.' );

		$result = aafm_filter_bridged_tool_call_result( $error, array(), 'aafm-bridge-vendor-gone' );

		$this->assertSame( $error, $result );
	}

	/**
	 * Scoping decision, proven rather than assumed: a NATIVE tool name (no aafm-bridge-
	 * prefix) is never touched, even when its result happens to be a bare list. Every native
	 * ability's real output is already asserted object-shaped by WireShapeTest against the
	 * exact same execute() call this filter would otherwise see, so a native list-shape defect
	 * is a bug to fix at the source. Wrapping it here would produce a `{data: [...]}` shape
	 * that matches neither the ability's documented output_schema nor the buggy shape,
	 * masking the defect instead of surfacing it.
	 */
	public function test_a_native_tool_names_result_is_never_touched_even_if_it_is_a_list(): void {
		$bare_list = array( 1, 2, 3 );

		$result = aafm_filter_bridged_tool_call_result( $bare_list, array(), 'aafm-get-posts' );

		$this->assertSame( $bare_list, $result );
	}

	/**
	 * The filter cannot double-wrap. Once a list has been wrapped under `data`, the result
	 * carries a string key and is no longer a list itself, so a second pass through the same
	 * filter (e.g. if some future refactor calls it twice) sees a non-list array and leaves it
	 * alone - idempotent by construction, not by an extra flag.
	 */
	public function test_wrapping_is_idempotent_across_a_second_pass(): void {
		$once  = aafm_filter_bridged_tool_call_result( array( 'a', 'b' ), array(), 'aafm-bridge-vendor-x' );
		$twice = aafm_filter_bridged_tool_call_result( $once, array(), 'aafm-bridge-vendor-x' );

		$this->assertSame( $once, $twice );
		$this->assertSame( array( 'data' => array( 'a', 'b' ) ), $twice );
	}

	/**
	 * The PHP 8.0 compatibility path, proven rather than assumed: aafm_bridge_is_list() (this
	 * plugin's floor is PHP 8.0; array_is_list() needs 8.1) is a hand-rolled sequential-key
	 * check that never calls the native function at all, so it needs no version branching and
	 * behaves identically regardless of which PHP version runs it. Exercised directly against
	 * the shapes that matter: a genuine list, a gap-keyed array (not a list despite being
	 * numeric), a string-keyed array, and the empty-array edge case.
	 */
	public function test_the_list_check_is_php_80_safe_by_construction(): void {
		$this->assertTrue( aafm_bridge_is_list( array( 'a', 'b', 'c' ) ) );
		$this->assertTrue( aafm_bridge_is_list( array() ) );
		$this->assertFalse(
			aafm_bridge_is_list(
				array(
					0 => 'a',
					2 => 'b',
				)
			)
		);
		$this->assertFalse( aafm_bridge_is_list( array( 'key' => 'value' ) ) );
		$this->assertFalse(
			aafm_bridge_is_list(
				array(
					1 => 'a',
					2 => 'b',
				)
			)
		);
	}

	/**
	 * Every test above calls aafm_filter_bridged_tool_call_result() directly, which proves its
	 * logic but not that it is actually reachable from a real tools/call. This pins the
	 * registration itself: an accidental deregistration (e.g. a refactor that moves the
	 * add_filter call, or drops it during a merge) would leave every test above passing while
	 * the real fix silently stopped running, exactly the gap McpErrorStatusTest's own
	 * registration pin closes for its sibling filter.
	 *
	 * A live end-to-end wire check (real initialize -> tools/call against a bridged fixture
	 * ability with no output_schema, run on the DDEV bench) confirmed the full path once:
	 * a bare-list result came back as structuredContent {"data":[...]} and an honest
	 * associative result passed through unchanged. That manual pass is not repeatable in CI
	 * (the vendored McpServer/ToolsHandler is one-instance-per-process with no reset method,
	 * so a fresh bridged ability added mid-suite is not reliably visible to it - the same
	 * constraint McpErrorStatusTest's own real-adapter test documents), so this registration
	 * pin is the permanent, CI-safe substitute: it proves the filter is still wired with the
	 * accepted-arg count ToolsHandler::call_tool() actually calls it with (3, matching this
	 * function's signature; the adapter itself passes 5, and WordPress silently drops the
	 * extra ones the callback did not ask for).
	 */
	public function test_filter_is_registered_on_mcp_adapter_tool_call_result(): void {
		// The add_filter call lives inside aafm_register_mcp_server(), itself hooked on
		// mcp_adapter_init - so it must be fired explicitly here (matching
		// ServerToolsTest.php / McpErrorStatusTest.php) rather than assumed to have already
		// run earlier in the suite. The registration is idempotent (aafm_register_mcp_server()
		// no-ops once 'aafm-server' exists), so calling it again is always safe.
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter ): void {
				aafm_register_mcp_server( $adapter );
			}
		);

		$priority = has_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result' );

		$this->assertSame(
			10,
			$priority,
			'aafm_filter_bridged_tool_call_result must be hooked on mcp_adapter_tool_call_result at priority 10.'
		);
	}
}
