<?php
/**
 * Wire-level proof that aafm-tec-get-tickets round-trips through a real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class TecTicketsWireTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_tec();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
		add_filter( 'aafm_integration_active_event_tickets', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		remove_filter( 'aafm_integration_active_event_tickets', '__return_true' );
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
		$server_id = 'aafm-server-tec-tickets-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/tec-get-tickets' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (TEC tickets wire test)',
					'Test-only server carrying only aafm/tec-get-tickets.',
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
			throw new \RuntimeException( 'Failed to build the test-only TEC tickets wire server.' );
		}
		return $server;
	}

	public function test_get_tickets_round_trips_over_a_real_tools_call(): void {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Main::POSTTYPE,
				'post_status' => 'publish',
			)
		);
		$this->stub_add_ticket( $event_id, 'Wire GA', 15.0, 50 );

		$this->register_enabled( array( 'aafm/tec-get-tickets' ) );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-get-tickets' ),
				'arguments' => array( 'event_id' => $event_id ),
			),
			'req-tec-tickets-wire-1'
		);

		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
		$structured = $result->getStructuredContent();
		$this->assertCount( 1, $structured['tickets'] );
		$this->assertSame( 'Wire GA', $structured['tickets'][0]['name'] );
	}
}
