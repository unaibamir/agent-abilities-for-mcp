<?php
/**
 * Wire-level proof that the four GeoDirectory abilities round-trip over a real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class GeodirectoryWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_geodir_stub_activate();
		add_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		aafm_registry_cache_should_flush( true );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	/**
	 * Builds a throwaway MCP server carrying only the four GeoDirectory abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_geodirectory_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-geodirectory-wire-test-' . $counter;

		$tools = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached(
				array(
					'aafm/geodirectory-get-listings',
					'aafm/geodirectory-get-listing',
					'aafm/geodirectory-create-listing',
					'aafm/geodirectory-update-listing',
				)
			)
		);

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (GeoDirectory wire test)',
					'Test-only server carrying only the GeoDirectory abilities.',
					AAFM_VERSION,
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					$tools,
					array(),
					array(),
					'aafm_transport_permission_callback'
				);
			}
		);

		$server = $adapter->get_server( $server_id );
		if ( ! $server instanceof \WP\MCP\Core\McpServer ) {
			throw new \RuntimeException( 'Failed to build the test-only GeoDirectory wire server.' );
		}
		return $server;
	}

	public function test_geodirectory_abilities_round_trip_over_a_real_tools_call(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled(
			array(
				'aafm/geodirectory-get-listings',
				'aafm/geodirectory-get-listing',
				'aafm/geodirectory-create-listing',
				'aafm/geodirectory-update-listing',
			)
		);
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_geodirectory_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$create_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/geodirectory-create-listing' ),
				'arguments' => array(
					'title' => 'Wire Test Listing',
					'city'  => 'Wire City',
				),
			),
			'req-geodir-create-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $create_result );
		$listing_id = (int) ( $create_result->getStructuredContent()['listing_id'] ?? 0 );
		$this->assertGreaterThan( 0, $listing_id );

		$get_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/geodirectory-get-listing' ),
				'arguments' => array( 'listing_id' => $listing_id ),
			),
			'req-geodir-get-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $get_result );
		$this->assertSame( 'Wire City', $get_result->getStructuredContent()['city'] ?? null );

		$list_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/geodirectory-get-listings' ),
				'arguments' => array(),
			),
			'req-geodir-list-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $list_result );
		$ids = wp_list_pluck( $list_result->getStructuredContent()['listings'] ?? array(), 'listing_id' );
		$this->assertContains( $listing_id, $ids );

		$update_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/geodirectory-update-listing' ),
				'arguments' => array(
					'listing_id' => $listing_id,
					'title'      => 'Wire Test Listing Updated',
				),
			),
			'req-geodir-update-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $update_result );
		$this->assertSame( 'Wire Test Listing Updated', $update_result->getStructuredContent()['title'] ?? null );
	}
}
