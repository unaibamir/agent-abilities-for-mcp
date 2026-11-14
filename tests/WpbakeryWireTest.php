<?php
/**
 * Wire-level proof that aafm-wpbakery-get-page-content and aafm-wpbakery-replace-text round-trip
 * over a real tools/call, with the exact wire body asserted.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class WpbakeryWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * Builds a throwaway MCP server carrying only the two WPBakery abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_wpbakery_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-wpbakery-wire-test-' . $counter;

		$tools = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/wpbakery-get-page-content', 'aafm/wpbakery-replace-text' ) )
		);

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (WPBakery wire test)',
					'Test-only server carrying only the WPBakery abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only WPBakery wire server.' );
		}
		return $server;
	}

	public const PAGE = '[vc_row][vc_column][vc_column_text]<p>Hello world</p>[/vc_column_text][vc_custom_heading text="Heading bravo"][/vc_column][/vc_row]';

	/**
	 * A server, a handler and a WPBakery page the editor can edit.
	 *
	 * @return array{0:\AAFM\Tests\Support\McpToolsHandlerShim,1:int}
	 */
	private function wired(): array {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => self::PAGE,
			)
		);
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/wpbakery-get-page-content', 'aafm/wpbakery-replace-text' ) );
		$this->acting_as( 'editor' );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $this->build_wpbakery_server( \WP\MCP\Core\McpAdapter::instance() ) );
		return array( $handler, $id );
	}

	/**
	 * The structured content of a tool result, decoded the way a client sees it.
	 *
	 * @param mixed $result Tool result.
	 * @return array<string,mixed>
	 */
	private function wire( $result ): array {
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $result );
		return json_decode( (string) $result->getContent()[0]->getText(), true );
	}

	public function test_wpbakery_read_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();

		$plain = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-get-page-content' ),
					'arguments' => array( 'post_id' => $id ),
				),
				'req-wpb-get-1'
			)
		);
		$this->assertSame(
			array(
				'post_id'           => $id,
				'content'           => self::PAGE,
				'is_wpbakery_owned' => true,
			),
			$plain
		);

		$outline = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-get-page-content' ),
					'arguments' => array(
						'post_id'           => $id,
						'include_structure' => true,
					),
				),
				'req-wpb-get-2'
			)
		);
		$this->assertSame(
			array(
				'post_id'           => $id,
				'content'           => self::PAGE,
				'is_wpbakery_owned' => true,
				'structure_status'  => 'ok',
				'structure'         => array(
					array(
						'index'        => 0,
						'depth'        => 0,
						'tag'          => 'vc_row',
						'self_closing' => false,
						'has_closer'   => true,
						'atts'         => array(),
					),
					array(
						'index'        => 1,
						'depth'        => 1,
						'tag'          => 'vc_column',
						'self_closing' => false,
						'has_closer'   => true,
						'atts'         => array(),
					),
					array(
						'index'        => 2,
						'depth'        => 2,
						'tag'          => 'vc_column_text',
						'self_closing' => false,
						'has_closer'   => true,
						'atts'         => array(),
					),
					array(
						'index'        => 3,
						'depth'        => 2,
						'tag'          => 'vc_custom_heading',
						'self_closing' => false,
						'has_closer'   => false,
						'atts'         => array( 'text' => 'Heading bravo' ),
					),
				),
			),
			$outline
		);
	}
}
