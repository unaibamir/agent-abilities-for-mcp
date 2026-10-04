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

	public function tear_down(): void {
		foreach ( array( 'aafm-bridge/round3-renamed-vendor', 'aafm/round3-native-thing' ) as $slug ) {
			if ( wp_has_ability( $slug ) ) {
				wp_unregister_ability( $slug );
			}
		}
		if ( wp_has_ability_category( 'round3-demo' ) ) {
			wp_unregister_ability_category( 'round3-demo' );
		}
		parent::tear_down();
	}

	/**
	 * Register a real ability and build the real McpTool the adapter would construct for it, so
	 * the round 3 identity-classification tests exercise McpTool::get_observability_context() as
	 * the bundled adapter actually shapes it, not a hand-rolled stand-in.
	 *
	 * @param string $name Ability name (e.g. 'aafm-bridge/round3-renamed-vendor').
	 * @return \WP\MCP\Domain\Tools\McpTool
	 */
	private function build_mcp_tool_for_ability( string $name ): \WP\MCP\Domain\Tools\McpTool {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		if ( ! wp_has_ability_category( 'round3-demo' ) ) {
			wp_register_ability_category(
				'round3-demo',
				array(
					'label'       => 'Round 3 demo',
					'description' => 'Demo fixture category for the identity-classification tests.',
				)
			);
		}
		array_pop( $wp_current_filter );

		$wp_current_filter[] = 'wp_abilities_api_init';
		wp_register_ability(
			$name,
			array(
				'label'               => 'Round 3 demo ability',
				'description'         => 'Demo fixture ability for the identity-classification tests.',
				'category'            => 'round3-demo',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
			)
		);
		array_pop( $wp_current_filter );

		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( \WP_Ability::class, $ability, "Fixture ability {$name} must register." );

		$tool = \WP\MCP\Domain\Tools\McpTool::fromAbility( $ability );
		$this->assertInstanceOf( \WP\MCP\Domain\Tools\McpTool::class, $tool, 'McpTool::fromAbility() must succeed for this well-formed fixture.' );

		return $tool;
	}

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
	 * An empty array from a bridged tool must still be wrapped, matching every other bare list
	 * this filter handles. A prior revision special-cased array() as "never wrapped" on the
	 * reasoning that a declared {type:object, additionalProperties:false} schema forbids the
	 * extra `data` key - but that exclusion ran BEFORE any schema lookup, so it also caught the
	 * ordinary no-schema case: a bridged tool with no output_schema at all returning "nothing
	 * found" reached the wire as a bare [], which is exactly the top-level-array shape this
	 * filter exists to prevent (upstream mcp-adapter#253). {"data":[]} is the correct wire shape
	 * here, matching what 1.6.0 sent and what every other non-empty list gets.
	 *
	 * KNOWN LIMITATION, deliberately not fixed in 1.6.1 (the schema-fidelity plan's MEDIUM
	 * finding M2), and a characterization pin, not an aspiration: the wrap is unconditional for
	 * ANY empty bridged result. A reasoned consequence - not a state this test constructs, since
	 * the filter never sees a schema - is that a foreign ability declaring {type:object,
	 * additionalProperties:false} and returning array() gets a {"data":[]} its own schema
	 * technically forbids. The filter has no way to know at this point whether a declared schema
	 * exists or what it says (it runs post-execute on the raw PHP value only; see the function's
	 * own docblock for why a schema-aware gate was removed as unreachable dead code). The
	 * alternative - leaving array() unwrapped as a bare [] - is strictly worse: a bare top-level
	 * JSON array in structuredContent violates the MCP spec's object requirement for EVERY
	 * consumer, not just the one foreign ability whose schema gets contradicted.
	 *
	 * IF A FUTURE CHANGE MAKES THIS TEST FAIL, THAT MAY BE AN IMPROVEMENT - read this docblock
	 * before "fixing" it back. A fix would need some way to know the foreign schema forbids extra
	 * keys AND leave array() genuinely unwrapped WITHOUT reintroducing the array()===$result
	 * exclusion this project already tried and reverted (see FIX A of the 1.6.1 final review:
	 * that exclusion ran before any schema check and broke the ordinary no-schema case instead).
	 */
	public function test_bridged_empty_list_result_is_wrapped_like_any_other_list(): void {
		$result = aafm_filter_bridged_tool_call_result( array(), array(), 'aafm-bridge-vendor-empty' );

		$this->assertSame(
			array( 'data' => array() ),
			$result,
			'An empty array must be wrapped the same as any other bare list - a bare [] in structuredContent violates the MCP spec regardless of whether the array happens to be empty.'
		);
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
	 * The pre-8.1 compatibility path, proven rather than assumed: aafm_bridge_is_list() (this
	 * plugin's floor is PHP 7.4; array_is_list() needs 8.1) is a hand-rolled sequential-key
	 * check that never calls the native function at all, so it needs no version branching and
	 * behaves identically regardless of which PHP version runs it. Exercised directly against
	 * the shapes that matter: a genuine list, a gap-keyed array (not a list despite being
	 * numeric), a string-keyed array, and the empty-array edge case.
	 */
	public function test_the_list_check_works_without_the_native_array_is_list(): void {
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

	/**
	 * A genuine WP_Error from a bridged ability passes through the filter unchanged.
	 */
	public function test_a_bridged_wp_error_result_passes_through_the_filter(): void {
		$error = new WP_Error( 'vendor_error', 'A real vendor failure.' );

		$result = aafm_filter_bridged_tool_call_result(
			$error,
			array(),
			'aafm-bridge-vendor-tool'
		);

		$this->assertSame( $error, $result );
	}

	/**
	 * Fix round 1, correctness F1: a zero-property stdClass carries nothing to leak. This codebase
	 * itself uses (object) array() in several places for the "empty map serialises as {} not []"
	 * idiom, so a bridged ability following the same convention must pass through unchanged.
	 */
	public function test_a_bridged_empty_object_result_passes_through_unchanged(): void {
		$empty = new \stdClass();

		$result = aafm_filter_bridged_tool_call_result(
			$empty,
			array(),
			'aafm-bridge-vendor-returns-an-empty-object'
		);

		$this->assertSame( $empty, $result );
	}

	/**
	 * The house idiom (object) array() can legitimately sit at any depth, not only under the
	 * adapter's own 'result' key - e.g. a bridged ability's own {"items":[...],"meta":{}} shape.
	 * The filter leaves an exact empty stdClass nested this deeply alone.
	 */
	public function test_an_empty_object_nested_two_levels_deep_is_accepted(): void {
		$original = array(
			'items' => array( 'a', 'b' ),
			'meta'  => (object) array(),
		);

		$result = aafm_filter_bridged_tool_call_result(
			$original,
			array(),
			'aafm-bridge-vendor-returns-a-nested-empty-object'
		);

		$this->assertSame( $original, $result, 'A nested exact empty stdClass must be accepted at any depth, not only at the root.' );
	}

	/**
	 * The wrapped companion to the bare empty-object case:
	 * an exact empty stdClass under the adapter's own 'result' key is accepted, matching the
	 * unwrapped case (test_a_bridged_empty_object_result_passes_through_unchanged) at this shape.
	 */
	public function test_an_empty_object_wrapped_in_the_adapters_result_key_is_accepted(): void {
		$original = array( 'result' => (object) array() );

		$result = aafm_filter_bridged_tool_call_result(
			$original,
			array(),
			'aafm-bridge-vendor-returns-an-empty-object-wrapped'
		);

		$this->assertSame( $original, $result );
	}

	/**
	 * Regression safety for the walk itself: a plain, deeply-nested array carrying no objects at
	 * all must still pass through unchanged, proving the filter does not alter ordinary bridged output.
	 */
	public function test_a_deeply_nested_plain_array_with_no_objects_is_unchanged(): void {
		$original = array(
			'result' => array(
				'items' => array(
					array( 'name' => 'first' ),
					array( 'name' => 'second' ),
				),
				'count' => 2,
			),
		);

		$result = aafm_filter_bridged_tool_call_result(
			$original,
			array(),
			'aafm-bridge-vendor-returns-plain-nested-data'
		);

		$this->assertSame( $original, $result );
	}

	/**
	 * The filter shapes lists and nothing else. Whether an object may be relayed is decided once,
	 * inside the bridged wrapper (BridgeWrapperTest, BridgeObjectRefusalWireTest), so it shows up in
	 * the Activity Log row. A filter that also refused would be a second verdict.
	 */
	public function test_the_filter_leaves_an_object_result_alone_because_the_wrapper_owns_that_verdict(): void {
		$user = new \WP_User( self::factory()->user->create() );
		$mcp  = $this->build_mcp_tool_for_ability( 'aafm-bridge/round3-renamed-vendor' );

		foreach ( array( array( 'result' => $user ), array( 'result' => (object) array( 'a' => 1 ) ) ) as $result ) {
			$this->assertSame( $result, aafm_filter_bridged_tool_call_result( $result, array(), 'aafm-bridge-vendor-x' ) );
			$this->assertSame( $result, aafm_filter_bridged_tool_call_result( $result, array(), 'site_renamed_wire_name_without_the_prefix', $mcp ) );
		}
	}

	// IDENTITY CLASSIFICATION, final gate round 3. The wire tool-name prefix test alone is
	// bypassable: the adapter's PUBLIC mcp_adapter_tool_name filter can rename a tool's wire name
	// after the ability name is sanitized, in either direction. These tests drive the actual
	// McpTool instance the adapter passes (accepted_args=4), built via the real
	// McpTool::fromAbility() the same way SafetyEnforcementTest.php already does elsewhere in this
	// suite, so the classification is proven against the bundled adapter's real
	// get_observability_context() shape, not a hand-rolled stand-in.

	/**
	 * A renamed bridged tool must still get the bare-list
	 * `data` wrap even when the site renamed its wire name.
	 */
	public function test_a_renamed_bridged_tool_still_shapes_a_bare_list(): void {
		$mcp_tool = $this->build_mcp_tool_for_ability( 'aafm-bridge/round3-renamed-vendor' );

		$result = aafm_filter_bridged_tool_call_result(
			array( 'a', 'b', 'c' ),
			array(),
			'site_renamed_wire_name_without_the_prefix',
			$mcp_tool
		);

		$this->assertSame( array( 'data' => array( 'a', 'b', 'c' ) ), $result );
	}

	/**
	 * The opposite-direction case, and the one most likely to be forgotten: a NATIVE tool renamed
	 * INTO an aafm-bridge-* wire name must NOT be treated as bridged. A wrapped object here is this
	 * plugin's OWN native output, already asserted object-shaped by WireShapeTest against the same
	 * execute() call - bridge shaping must not touch it, and a bare list must not be wrapped either.
	 */
	public function test_a_native_tool_renamed_into_the_bridge_prefix_is_not_treated_as_bridged(): void {
		$mcp_tool = $this->build_mcp_tool_for_ability( 'aafm/round3-native-thing' );

		$bare_list = array( 1, 2, 3 );

		$result = aafm_filter_bridged_tool_call_result(
			$bare_list,
			array(),
			'aafm-bridge-a-native-tool-renamed-into-the-prefix',
			$mcp_tool
		);

		$this->assertSame( $bare_list, $result, 'A native ability renamed into the aafm-bridge- wire prefix must not receive bridge list-wrapping.' );
	}
}
