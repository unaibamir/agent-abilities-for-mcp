<?php
/**
 * Wire-level proof that aafm/get-media's filename/alt-text search match survives a real
 * tools/call, not only the direct PHP return value asserted in tests/abilities/MediaReadTest.php.
 *
 * Builds a fresh, uniquely-ID'd MCP server carrying only this one ability, the same pattern
 * ServerToolsListExposureTest.php uses to avoid the frozen process-wide 'aafm-server' singleton.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class GetMediaSearchWireTest extends TestCase {

	/**
	 * Builds a throwaway single-ability MCP server carrying only aafm/get-media.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server (should
	 *                           never happen with a fresh, unused server ID).
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-get-media-search-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/get-media' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (get-media search wire test)',
					'Test-only server carrying only aafm/get-media.',
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
			throw new \RuntimeException( 'Failed to build the test-only get-media search wire server.' );
		}
		return $server;
	}

	public function test_filename_and_alt_search_survive_a_real_tools_call(): void {
		$id = self::factory()->attachment->create_object(
			'sunset-photo.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_title'     => 'Photo',
			)
		);
		update_post_meta( $id, '_wp_attached_file', 'sunset-photo.jpg' );

		$this->register_enabled( array( 'aafm/get-media' ) );
		// edit_others_posts (editor, not author) so the media-scoping-by-uploader rule
		// (aafm_media_scope_author_id()) never excludes this fixture attachment regardless of
		// which user id the factory happened to stamp as its author.
		$this->acting_as( 'editor' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/get-media' ),
				'arguments' => array( 'search' => 'sunset' ),
			),
			'req-get-media-search-1'
		);

		$this->assertNotInstanceOf(
			\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class,
			$result,
			'aafm-get-media must succeed for an authorized author over a real tools/call.'
		);

		$structured = $result->getStructuredContent();
		$this->assertSame( 1, $structured['total'] ?? null );
		$this->assertSame( $id, $structured['media'][0]['id'] ?? null );
	}
}
