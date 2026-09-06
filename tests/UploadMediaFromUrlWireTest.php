<?php
/**
 * Wire-level proof that aafm-upload-media-from-url round-trips over a real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class UploadMediaFromUrlWireTest extends TestCase {

	// 1x1 transparent PNG.
	private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Absolute paths written by upload tests, cleaned up in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $written_files = array();

	public function set_up(): void {
		parent::set_up();
		// example.test never resolves in this container (RFC 2606) and some DNS setups sinkhole
		// an unresolvable name to a private/loopback address - pin a public IP.
		add_filter( 'aafm_resolve_hostname_to_ip', static fn(): string => '203.0.113.10' );
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		remove_all_filters( 'aafm_media_fetch_pre_fetch_result' );
		remove_all_filters( 'aafm_resolve_hostname_to_ip' );
		parent::tear_down();
	}

	/**
	 * Builds a throwaway MCP server carrying only aafm/upload-media-from-url.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-upload-media-from-url-wire-test-' . $counter;

		$tools = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/upload-media-from-url' ) )
		);

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (upload-media-from-url wire test)',
					'Test-only server carrying only aafm/upload-media-from-url.',
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
			throw new \RuntimeException( 'Failed to build the test-only upload-media-from-url wire server.' );
		}
		return $server;
	}

	public function test_upload_media_from_url_round_trips_over_a_real_tools_call(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png = base64_decode( self::PNG_B64, true );
		add_filter(
			'aafm_media_fetch_pre_fetch_result',
			static fn() => array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => $png,
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
			)
		);

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/upload-media-from-url' ) );
		$this->acting_as( 'author' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/upload-media-from-url' ),
				'arguments' => array(
					'url'      => 'https://example.test/pixel.png',
					'filename' => 'pixel.png',
				),
			),
			'req-upload-media-from-url-1'
		);

		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
		$attachment_id = (int) ( $result->getStructuredContent()['attachment_id'] ?? 0 );
		$this->assertGreaterThan( 0, $attachment_id );

		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
	}
}
