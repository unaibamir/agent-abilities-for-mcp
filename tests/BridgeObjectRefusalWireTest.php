<?php
/**
 * The one bridge security guard with no real wire-level test: a bridged ability whose result
 * hides a raw PHP object anywhere in it must come back over a REAL tools/call as an MCP error,
 * not merely be refused by the filter function in isolation.
 *
 * The filter callback, aafm_filter_bridged_tool_call_result() (includes/bridge.php), already has
 * unit coverage that calls it directly (tests/abilities/BridgeToolCallResultFilterTest.php). This
 * test instead drives the real production entry point - WP\MCP\Handlers\Tools\ToolsHandler::call_tool(),
 * the same method the adapter's REST/streaming transports call - against a throwaway,
 * independently-constructed WP\MCP\Core\McpServer + ToolsHandler pair carrying one bridged
 * fixture ability. Both classes are plain-constructible (see McpServer::__construct()); this
 * test never touches the plugin's own registered "aafm-server" singleton.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;

final class BridgeObjectRefusalWireTest extends TestCase {

	private const FIXTURE_ABILITY  = 'aafm-bridge/leaky-fixture';
	private const FIXTURE_CATEGORY = 'bridge-wire-fixture';

	public function tear_down(): void {
		if ( wp_has_ability( self::FIXTURE_ABILITY ) ) {
			wp_unregister_ability( self::FIXTURE_ABILITY );
		}
		if ( wp_has_ability_category( self::FIXTURE_CATEGORY ) ) {
			wp_unregister_ability_category( self::FIXTURE_CATEGORY );
		}
		remove_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10 );
		parent::tear_down();
	}

	/**
	 * Register a fixture "bridged" ability under the aafm-bridge/ namespace whose execute
	 * callback returns a raw stdClass nested inside an array - the exact shape
	 * aafm_bridge_result_hides_an_object() exists to refuse. No output_schema is declared, so
	 * core's WP_Ability::execute() -> validate_output() short-circuits true without checking
	 * anything, exactly mirroring how a real bridged, schema-less foreign ability behaves
	 * (see BridgeToolCallResultFilterTest's own fixture for the same pattern).
	 */
	private function register_leaky_fixture_ability(): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		if ( ! wp_has_ability_category( self::FIXTURE_CATEGORY ) ) {
			wp_register_ability_category(
				self::FIXTURE_CATEGORY,
				array(
					'label'       => 'Bridge wire fixture',
					'description' => 'Throwaway fixture for the bridge object-refusal wire test.',
				)
			);
		}
		array_pop( $wp_current_filter );

		$wp_current_filter[] = 'wp_abilities_api_init';
		wp_register_ability(
			self::FIXTURE_ABILITY,
			array(
				'label'               => 'Leaky bridged fixture',
				'description'         => 'Returns a raw object nested in its result, simulating a foreign ability whose result cannot be safely relayed.',
				'category'            => self::FIXTURE_CATEGORY,
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(
					'items' => array(
						(object) array( 'unexpected' => 'leak' ),
					),
				),
				'permission_callback' => '__return_true',
			)
		);
		array_pop( $wp_current_filter );

		$this->assertTrue( wp_has_ability( self::FIXTURE_ABILITY ), 'Fixture ability must register.' );
	}

	public function test_real_tools_call_refuses_a_bridged_result_that_hides_an_object(): void {
		$this->register_leaky_fixture_ability();

		// Attach the REAL production filter callback, exactly as aafm_register_mcp_server()
		// does on mcp_adapter_init (includes/server.php:978) - that hook does not fire in the
		// PHPUnit bootstrap, so it is wired here directly rather than faked.
		add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );

		$server = new McpServer(
			'aafm-wire-test-server',
			'aafm-wire-test/v1',
			'aafm-wire-test',
			'AAFM wire test server',
			'Throwaway server for the bridge object-refusal wire test.',
			'0.0.0',
			array(), // No transports needed for a direct call_tool() invocation.
			null,    // Default NullMcpErrorHandler.
			null,    // Default NullMcpObservabilityHandler.
			array( self::FIXTURE_ABILITY )
		);

		$tools = $server->get_tools();
		$this->assertNotEmpty( $tools, 'The fixture ability must resolve to at least one registered MCP tool.' );
		$wire_tool_name = (string) array_key_first( $tools );

		$handler  = new ToolsHandler( $server );
		$response = $handler->call_tool(
			array(
				'name'      => $wire_tool_name,
				'arguments' => array(),
			)
		);

		$this->assertInstanceOf(
			CallToolResult::class,
			$response,
			'A real tools/call against a leaking-object result must still produce a CallToolResult (a tool execution error), not a protocol-level error.'
		);
		$this->assertTrue(
			$response->getIsError(),
			'The wire response must be flagged as an error - a leaking object must never reach structuredContent.'
		);

		$content = $response->getContent();
		$this->assertNotEmpty( $content, 'An error result must still carry explanatory content.' );
		$this->assertStringContainsString(
			'cannot be safely relayed over MCP',
			$content[0]->getText(),
			'The real wire error text must be the bridge guard\'s own refusal message, proving aafm_filter_bridged_tool_call_result() actually ran on this call.'
		);

		$structured = $response->getStructuredContent();
		$this->assertNull(
			$structured,
			'The leaking object must never reach structuredContent, on the real wire, under any circumstance.'
		);
	}
}
