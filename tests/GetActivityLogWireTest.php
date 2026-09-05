<?php
/**
 * Wire-level proof that aafm/get-activity-log's new is_agent_identity field survives a real
 * tools/call, not only the direct PHP return value asserted in
 * tests/abilities/ActivityLogAgentIdentityTest.php.
 *
 * Builds a fresh, uniquely-ID'd MCP server carrying only this one ability, the same pattern
 * ServerToolsListExposureTest.php uses to avoid the frozen process-wide 'aafm-server' singleton.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class GetActivityLogWireTest extends TestCase {

	/**
	 * Builds a throwaway single-ability MCP server via the exact same production code path
	 * aafm_register_mcp_server() uses, mirroring ServerToolsListExposureTest::build_exposure_test_server().
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server (should
	 *                           never happen with a fresh, unused server ID).
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-activity-log-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/get-activity-log' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (activity-log wire test)',
					'Test-only server carrying only aafm/get-activity-log.',
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
			throw new \RuntimeException( 'Failed to build the test-only activity-log wire server.' );
		}
		return $server;
	}

	public function test_is_agent_identity_survives_a_real_tools_call(): void {
		$agent_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $agent_user, aafm_agent_user_marker_meta_key(), 1 );

		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => $agent_user,
				'principal_login'   => (string) get_userdata( $agent_user )->user_login,
			)
		);

		$this->register_enabled( array( 'aafm/get-activity-log' ) );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/get-activity-log' ),
				// Filtered by ability, not assumed to be entry 0: the production wrapper logs
				// its own row for THIS call before the callback runs, so the inserted fixture
				// row is not reliably the most recent one.
				'arguments' => array( 'ability' => 'aafm/get-posts' ),
			),
			'req-activity-log-agent-identity-1'
		);

		$this->assertNotInstanceOf(
			\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class,
			$result,
			'aafm-get-activity-log must succeed for an authorized administrator over a real tools/call.'
		);

		$structured = $result->getStructuredContent();
		$this->assertIsArray( $structured['entries'] ?? null );
		$this->assertNotEmpty( $structured['entries'] );
		$this->assertArrayHasKey( 'is_agent_identity', $structured['entries'][0] );
		$this->assertTrue( $structured['entries'][0]['is_agent_identity'] );
	}
}
