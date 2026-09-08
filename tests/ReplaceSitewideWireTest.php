<?php
/**
 * Wire-level proof that aafm-replace-sitewide round-trips over a real tools/call, not only the
 * direct PHP calls asserted in tests/abilities/ReplaceSitewideTest.php.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class ReplaceSitewideWireTest extends TestCase {

	/**
	 * Builds a throwaway single-ability MCP server carrying only aafm/replace-sitewide.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server (should
	 *                           never happen with a fresh, unused server ID).
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-replace-sitewide-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/replace-sitewide' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (replace-sitewide wire test)',
					'Test-only server carrying only aafm/replace-sitewide.',
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
			throw new \RuntimeException( 'Failed to build the test-only replace-sitewide wire server.' );
		}
		return $server;
	}

	public function test_dry_run_preview_then_apply_round_trip_over_a_real_tools_call(): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => 'the quick fox' ) );

		$this->register_enabled( array( 'aafm/replace-sitewide' ) );
		$this->acting_as( 'editor' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$preview = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/replace-sitewide' ),
				'arguments' => array(
					'search'  => 'quick',
					'replace' => 'slow',
				),
			),
			'req-replace-sitewide-preview-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $preview );
		$preview_shape = $preview->getStructuredContent();
		$this->assertTrue( $preview_shape['dry_run'] );
		$this->assertSame( 'the quick fox', get_post( $post->ID )->post_content, 'A dry-run preview must never write.' );

		$apply = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/replace-sitewide' ),
				'arguments' => array(
					'search'  => 'quick',
					'replace' => 'slow',
					'dry_run' => false,
				),
			),
			'req-replace-sitewide-apply-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $apply );
		$this->assertSame( 'the slow fox', get_post( $post->ID )->post_content );
	}
}
