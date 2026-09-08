<?php
/**
 * Wire-level proof that aafm-slim-seo-get-post and aafm-slim-seo-update-post round-trip over a
 * real tools/call, not only the direct PHP calls asserted in tests/abilities/SlimSeoTest.php.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class SlimSeoWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		// The registry is memoized (includes/registry.php static $cache); without this flush a
		// prior test's slim-seo-inactive registry snapshot survives and the ability never
		// actually registers here, so a real tools/call reports "tool not found" even though
		// the direct-call SlimSeoTest suite (which never touches the registry cache) passes.
		aafm_registry_cache_should_flush( true );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	/**
	 * Builds a throwaway MCP server carrying only the two Slim SEO abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server (should
	 *                           never happen with a fresh, unused server ID).
	 */
	private function build_slim_seo_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-slim-seo-wire-test-' . $counter;

		$tools = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/slim-seo-get-post', 'aafm/slim-seo-update-post' ) )
		);

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (Slim SEO wire test)',
					'Test-only server carrying only the Slim SEO abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only Slim SEO wire server.' );
		}
		return $server;
	}

	public function test_slim_seo_abilities_round_trip_over_a_real_tools_call(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'slim_seo', array( 'title' => 'Old title' ) );

		// wp_register_ability() silently no-ops (via _doing_it_wrong()) unless the ability's
		// declared category is already registered - explicit here so this test does not depend
		// on some earlier test in the process having registered it as a side effect.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/slim-seo-get-post', 'aafm/slim-seo-update-post' ) );
		$this->acting_as( 'editor' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_slim_seo_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$get_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/slim-seo-get-post' ),
				'arguments' => array( 'post_id' => $post->ID ),
			),
			'req-slim-seo-get-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $get_result );
		$this->assertSame( 'Old title', $get_result->getStructuredContent()['title'] ?? null );

		$update_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/slim-seo-update-post' ),
				'arguments' => array(
					'post_id' => $post->ID,
					'title'   => 'New title',
				),
			),
			'req-slim-seo-update-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $update_result );
		$this->assertSame( 'New title', $update_result->getStructuredContent()['title'] ?? null );

		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertSame( 'New title', $stored['title'] );
	}
}
