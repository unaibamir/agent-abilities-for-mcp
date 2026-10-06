<?php
/**
 * Real tools/call coverage for aafm_filter_bridged_tool_call_result(), supplementing the direct-
 * call tests in tests/abilities/BridgeToolCallResultFilterTest.php (doc 216 item 1: those tests
 * prove the filter's logic but never that it runs on a real wire call). Follows the exact pattern
 * BridgeObjectRefusalWireTest.php established: a throwaway, independently-constructed
 * WP\MCP\Core\McpServer + ToolsHandler pair carrying one fixture ability per case, never the
 * plugin's own registered "aafm-server" singleton.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use WP\MCP\Core\McpServer;
use AAFM\Tests\Support\McpToolsHandlerShim;
use AAFM\Tests\Support\McpToolCallOutcome;

final class BridgeToolCallResultWireTest extends TestCase {

	private const LIST_ABILITY    = 'aafm-bridge/wire-list-fixture';
	private const ERROR_ABILITY   = 'aafm-bridge/wire-error-fixture';
	private const NATIVE_ABILITY  = 'aafm/wire-native-fixture';
	private const RENAMED_ABILITY = 'aafm-bridge/wire-renamed-fixture';
	private const CATEGORY        = 'bridge-wire-shape-fixture';

	public function tear_down(): void {
		foreach ( array( self::LIST_ABILITY, self::ERROR_ABILITY, self::NATIVE_ABILITY, self::RENAMED_ABILITY ) as $slug ) {
			if ( wp_has_ability( $slug ) ) {
				wp_unregister_ability( $slug );
			}
		}
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			wp_unregister_ability_category( self::CATEGORY );
		}
		remove_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10 );
		remove_filter( 'mcp_adapter_tool_name', array( $this, 'rename_wire_tool_out_of_bridge_prefix' ), 10 );
		parent::tear_down();
	}

	private function register_fixture_category(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		if ( ! wp_has_ability_category( self::CATEGORY ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => 'Bridge wire shape fixture',
					'description' => 'Throwaway fixtures for the bridged tools/call wire shape tests.',
				)
			);
		}
		array_pop( $wp_current_filter );
	}

	private function register_ability( string $name, callable $execute ): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		wp_register_ability(
			$name,
			array(
				'label'               => 'Wire shape fixture ability',
				'description'         => 'Throwaway fixture for the bridged tools/call wire shape test.',
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => $execute,
				'permission_callback' => '__return_true',
			)
		);
		array_pop( $wp_current_filter );
		$this->assertTrue( wp_has_ability( $name ), "Fixture ability {$name} must register." );
	}

	/**
	 * Build a throwaway single-ability MCP server and drive a real tools/call against it.
	 *
	 * @param string $ability_name The fixture ability's registered name.
	 * @return array{0:McpToolCallOutcome,1:string}
	 */
	private function call_it_for_real( string $ability_name ): array {
		$server = new McpServer(
			'aafm-wire-shape-test-server',
			'aafm-wire-shape-test/v1',
			'aafm-wire-shape-test',
			'AAFM wire shape test server',
			'Throwaway server for the bridged tools/call wire shape tests.',
			'0.0.0',
			array(),
			null,
			null,
			array( $ability_name )
		);
		$tools  = $server->get_tools( \AAFM\Tests\Support\McpToolsHandlerShim::schema() );
		$this->assertNotEmpty( $tools, "The fixture ability {$ability_name} must resolve to at least one registered MCP tool." );
		$wire_tool_name = (string) array_key_first( $tools );

		$handler = new McpToolsHandlerShim( $server );
		return array(
			$handler->call_tool(
				array(
					'name'      => $wire_tool_name,
					'arguments' => array(),
				)
			),
			$wire_tool_name,
		);
	}

	/**
	 * The control: prove the wire path is genuinely reachable BEFORE trusting any assertion
	 * below. Without add_filter(), a bridged bare-list result reaches the adapter UNWRAPPED, and
	 * adapter 0.7.0 cannot project a list as structuredContent (it must be an object), so building
	 * the result record throws. If this test stops throwing, the wrapping tests below are not
	 * proving what they claim.
	 */
	public function test_control_a_bare_list_is_rejected_by_the_adapter_when_the_filter_is_not_registered(): void {
		$this->register_fixture_category();
		$this->register_ability( self::LIST_ABILITY, static fn() => array( 'a', 'b', 'c' ) );
		// Deliberately NOT registering aafm_filter_bridged_tool_call_result here.

		$this->expectException( \WP\McpSchema\Exception\ValidationException::class );
		$this->expectExceptionMessage( 'structuredContent' );

		$this->call_it_for_real( self::LIST_ABILITY );
	}

	public function test_a_bare_top_level_list_is_wrapped_under_data_on_the_real_wire(): void {
		add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );
		$this->register_fixture_category();
		$this->register_ability( self::LIST_ABILITY, static fn() => array( 'a', 'b', 'c' ) );

		list( $response, ) = $this->call_it_for_real( self::LIST_ABILITY );

		$this->assertInstanceOf( McpToolCallOutcome::class, $response );
		$this->assertFalse( $response->getIsError() );
		$this->assertSame( array( 'data' => array( 'a', 'b', 'c' ) ), $response->getStructuredContent() );
	}

	public function test_a_native_tools_bare_list_result_is_left_unwrapped_on_the_real_wire(): void {
		add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );
		$this->register_fixture_category();
		$this->register_ability( self::NATIVE_ABILITY, static fn() => array( 1, 2, 3 ) );

		// The wire tool name is resolved first so the test proves the fixture is native.
		$server         = new McpServer(
			'aafm-wire-shape-test-server',
			'aafm-wire-shape-test/v1',
			'aafm-wire-shape-test',
			'AAFM wire shape test server',
			'Throwaway server for the bridged tools/call wire shape tests.',
			'0.0.0',
			array(),
			null,
			null,
			array( self::NATIVE_ABILITY )
		);
		$wire_tool_name = (string) array_key_first( $server->get_tools( McpToolsHandlerShim::schema() ) );
		$this->assertStringStartsNotWith(
			'aafm-bridge-',
			$wire_tool_name,
			'This fixture must resolve to a native (non-bridge-prefixed) wire tool name for the test to prove what it claims.'
		);

		// A wrapped list would build a valid result. Left alone, the adapter rejects the bare list,
		// which is how the test shows the bridge filter did not touch a native ability's result.
		$this->expectException( \WP\McpSchema\Exception\ValidationException::class );
		$this->expectExceptionMessage( 'structuredContent' );

		( new McpToolsHandlerShim( $server ) )->call_tool(
			array(
				'name'      => $wire_tool_name,
				'arguments' => array(),
			)
		);
	}

	public function test_a_wp_error_result_passes_through_untouched_on_the_real_wire(): void {
		add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );
		$this->register_fixture_category();
		$this->register_ability(
			self::ERROR_ABILITY,
			static fn() => new \WP_Error( 'aafm_wire_shape_fixture_error', 'A real vendor failure.' )
		);

		list( $response, ) = $this->call_it_for_real( self::ERROR_ABILITY );

		$this->assertInstanceOf( McpToolCallOutcome::class, $response );
		$this->assertTrue( $response->getIsError(), 'A WP_Error execute() result must surface as a tool-call error on the real wire.' );

		// "Passes through untouched" means the ORIGINAL vendor message survives, not merely that
		// some error surfaces - a bridge filter that swapped it for a generic message would still
		// pass the two assertions above (Codex review, plan 226 round 1).
		$content = $response->getContent();
		$this->assertNotEmpty( $content, 'An error result must still carry explanatory content.' );
		$this->assertStringContainsString(
			'A real vendor failure.',
			$content[0]->getText(),
			'The original WP_Error message must reach the wire untouched, not be replaced by the bridge filter.'
		);
		$this->assertNull(
			$response->getStructuredContent(),
			'A WP_Error result must never carry structuredContent on the real wire.'
		);
	}

	/**
	 * The renamed-tool identity-classification case, at the real wire: a bridged ability renamed
	 * OUT of the aafm-bridge- wire prefix via the adapter's public mcp_adapter_tool_name filter
	 * must still be inspected by the bridge guard, not skipped because its wire name no longer
	 * carries the prefix.
	 *
	 * @param string $name The wire tool name as the adapter would emit it before this filter.
	 */
	public function rename_wire_tool_out_of_bridge_prefix( string $name ): string {
		return str_starts_with( $name, 'aafm-bridge-' )
			? 'site_renamed_' . substr( $name, strlen( 'aafm-bridge-' ) )
			: $name;
	}

	public function test_a_renamed_bridged_tool_still_gets_its_bare_list_wrapped_on_the_real_wire(): void {
		add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );
		add_filter( 'mcp_adapter_tool_name', array( $this, 'rename_wire_tool_out_of_bridge_prefix' ), 10, 1 );
		$this->register_fixture_category();
		$this->register_ability( self::RENAMED_ABILITY, static fn() => array( 'x', 'y' ) );

		list( $response, $wire_tool_name ) = $this->call_it_for_real( self::RENAMED_ABILITY );

		$this->assertStringStartsWith(
			'site_renamed_',
			$wire_tool_name,
			'The rename filter must actually have applied for this test to prove what it claims.'
		);
		$this->assertInstanceOf( McpToolCallOutcome::class, $response );
		$this->assertFalse( $response->getIsError() );
		$this->assertSame(
			array( 'data' => array( 'x', 'y' ) ),
			$response->getStructuredContent(),
			'A bridged ability renamed out of the aafm-bridge- wire prefix must still be classified as bridged and get its bare list wrapped.'
		);
	}
}
