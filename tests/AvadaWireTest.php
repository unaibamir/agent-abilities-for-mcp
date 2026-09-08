<?php
/**
 * Wire-level proof that aafm-avada-get-page-content and aafm-avada-replace-text round-trip over
 * a real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AvadaWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_registry_cache_should_flush( true );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	/**
	 * Builds a throwaway MCP server carrying only the two Avada abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_avada_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-avada-wire-test-' . $counter;

		$tools = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) )
		);

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (Avada wire test)',
					'Test-only server carrying only the Avada abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only Avada wire server.' );
		}
		return $server;
	}

	public function test_avada_abilities_round_trip_over_a_real_tools_call(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '[fusion_builder_container]Hello world[/fusion_builder_container]' )
		);
		update_post_meta( $post->ID, 'fusion_builder_status', 'active' );

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) );
		$this->acting_as( 'editor' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_avada_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$get_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-get-page-content' ),
				'arguments' => array( 'post_id' => $post->ID ),
			),
			'req-avada-get-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $get_result );
		$this->assertTrue( $get_result->getStructuredContent()['is_avada_owned'] ?? false );

		$replace_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-replace-text' ),
				'arguments' => array(
					'post_id' => $post->ID,
					'search'  => 'Hello world',
					'replace' => 'Greetings world',
				),
			),
			'req-avada-replace-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $replace_result );
		$this->assertSame( 1, $replace_result->getStructuredContent()['replacements'] ?? null );

		$stored = get_post_field( 'post_content', $post->ID, 'raw' );
		$this->assertStringContainsString( 'Greetings world', $stored );
	}
}
