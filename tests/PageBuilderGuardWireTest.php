<?php
/**
 * Wire-level proof that the page-builder ownership guard refuses a real tools/call, not only the
 * direct-PHP proof in tests/PageBuilderGuardTest.php and tests/PageBuilderGuardSweepTest.php.
 *
 * Codex round-b finding 9: the sweep only ever calls the execute callbacks directly, so a guard
 * wired into the wrong seam (e.g. only the permission_callback, which the adapter's own decorated
 * closure could short-circuit before reaching aafm_exec_update_post() at all) would still pass
 * every existing test.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class PageBuilderGuardWireTest extends TestCase {

	/**
	 * Builds a throwaway single-ability MCP server carrying only aafm/update-post, mirroring
	 * AllowlistWireTest::build_single_ability_server().
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter       Adapter instance.
	 * @param array<int,string>       $ability_names Ability names the server should carry.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter, array $ability_names = array( 'aafm/update-post' ) ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-page-builder-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $ability_names ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (page builder guard wire test)',
					'Test-only server carrying only aafm/update-post.',
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
			throw new \RuntimeException( 'Failed to build the test-only page builder guard wire server.' );
		}
		return $server;
	}

	public function test_a_real_tools_call_refuses_a_builder_owned_post(): void {
		$this->register_enabled( array( 'aafm/update-post' ) );
		$post_id        = self::factory()->post->create();
		$original_title = get_post( $post_id )->post_title;
		update_post_meta( $post_id, '_elementor_data', '[]' );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/update-post' ),
				'arguments' => array(
					'post_id' => $post_id,
					'title'   => 'Should be refused',
				),
			),
			'req-page-builder-wire-1'
		);

		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\DTO\CallToolResult::class, $result );
		$this->assertTrue(
			$result->getIsError(),
			'A builder-owned post must be refused on a real tools/call, even though the caller holds edit_posts.'
		);
		$content = $result->getContent();
		$this->assertNotEmpty( $content, 'The refusal must still carry explanatory content.' );
		$this->assertStringContainsString(
			'Elementor',
			$content[0]->getText(),
			'The real page-builder-owned error message must reach the wire untouched.'
		);

		$this->assertSame( $original_title, get_post( $post_id )->post_title, 'The builder-owned post must be left untouched.' );
	}

	/**
	 * Codex final round 7 HIGH: a real two-tool sequence proving the marker can no longer be
	 * cleared through update-post-meta, so the ownership guard on the SEPARATE update-post call
	 * afterward still refuses - not two isolated checks, the actual attack sequence end to end.
	 */
	public function test_clearing_the_marker_via_update_post_meta_is_refused_and_the_post_stays_guarded(): void {
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		$this->register_enabled( array( 'aafm/update-post', 'aafm/update-post-meta' ) );
		$post_id        = self::factory()->post->create();
		$original_title = get_post( $post_id )->post_title;
		update_post_meta( $post_id, 'fusion_builder_status', 'active' );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter, array( 'aafm/update-post', 'aafm/update-post-meta' ) );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$clear = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/update-post-meta' ),
				'arguments' => array(
					'post_id'  => $post_id,
					'meta_key' => 'fusion_builder_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => '',
				),
			),
			'req-page-builder-wire-2a'
		);
		$this->assertTrue( $clear->getIsError(), 'Clearing the marker must be refused, not merely re-checked later.' );
		$this->assertSame( 'active', get_post_meta( $post_id, 'fusion_builder_status', true ), 'The marker must survive on the wire, not just via a direct call.' );

		$update = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/update-post' ),
				'arguments' => array(
					'post_id' => $post_id,
					'title'   => 'Should still be refused',
				),
			),
			'req-page-builder-wire-2b'
		);
		$this->assertTrue( $update->getIsError(), 'The content write must still be refused after the failed clear attempt.' );
		$this->assertStringContainsString( 'Avada', $update->getContent()[0]->getText() );
		$this->assertSame( $original_title, get_post( $post_id )->post_title, 'The post must be untouched end to end.' );
	}
}
