<?php
/**
 * Wire-level proof that aafm-tec-create-venue and aafm-tec-create-organizer round-trip through a
 * real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class TecVenuesOrganizersWireTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_tec();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Builds a throwaway single-purpose MCP server, mirroring GetActivityLogWireTest's pattern.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-tec-venues-organizers-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/tec-create-venue', 'aafm/tec-create-organizer' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (TEC venues/organizers wire test)',
					'Test-only server carrying only the TEC venue/organizer create abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only TEC venues/organizers wire server.' );
		}
		return $server;
	}

	public function test_create_venue_and_organizer_round_trip_over_a_real_tools_call(): void {
		$this->register_enabled( array( 'aafm/tec-create-venue', 'aafm/tec-create-organizer' ) );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$venue_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-create-venue' ),
				'arguments' => array( 'title' => 'Wire Test Venue' ),
			),
			'req-tec-vo-wire-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $venue_result );
		$this->assertSame( 'Wire Test Venue', $venue_result->getStructuredContent()['venue']['title'] );

		$organizer_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-create-organizer' ),
				'arguments' => array( 'title' => 'Wire Test Organizer' ),
			),
			'req-tec-vo-wire-2'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $organizer_result );
		$this->assertSame( 'Wire Test Organizer', $organizer_result->getStructuredContent()['organizer']['title'] );
	}
}
