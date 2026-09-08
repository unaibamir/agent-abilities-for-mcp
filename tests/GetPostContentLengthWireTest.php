<?php
/**
 * Wire-level proof that content_length and the new include_content property on aafm/get-post
 * survive a real tools/call. Before this task's fix, get-post's input_schema had no
 * include_content property at all and additionalProperties is false, so a wire call carrying
 * include_content:false would have been rejected outright, not silently ignored - this test
 * would have caught that failure mode directly, which the plan's own drafted (never-run) test
 * would not have.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class GetPostContentLengthWireTest extends TestCase {

	/**
	 * Builds a throwaway single-ability MCP server carrying only aafm/get-post.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server (should
	 *                           never happen with a fresh, unused server ID).
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-get-post-content-length-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/get-post' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (get-post content_length wire test)',
					'Test-only server carrying only aafm/get-post.',
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
			throw new \RuntimeException( 'Failed to build the test-only get-post content_length wire server.' );
		}
		return $server;
	}

	public function test_include_content_false_is_accepted_and_content_length_present_on_the_real_wire(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'twelve bytes',
			)
		);

		$this->register_enabled( array( 'aafm/get-post' ) );
		$this->acting_as( 'author' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/get-post' ),
				'arguments' => array(
					'post_id'         => $post_id,
					'include_content' => false,
				),
			),
			'req-get-post-content-length-1'
		);

		$this->assertNotInstanceOf(
			\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class,
			$result,
			'include_content:false must be an accepted parameter on the real wire, not rejected by a closed schema.'
		);

		$structured = $result->getStructuredContent();
		$this->assertArrayNotHasKey( 'content', $structured['post'] ?? array() );
		$this->assertSame( 12, $structured['post']['content_length'] ?? null );
	}
}
