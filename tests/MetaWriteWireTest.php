<?php
/**
 * Wire-level bodies of the meta write abilities: the exact structured content a real tools/call
 * returns for each outcome, with the keys that must be absent, and the exact error text.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class MetaWriteWireTest extends TestCase {

	private const ABILITIES = array(
		'aafm/get-post-meta',
		'aafm/update-post-meta',
		'aafm/delete-post-meta',
	);

	/**
	 * Build a throwaway MCP server over the given abilities and return its tools handler.
	 *
	 * @param string[] $abilities Ability names.
	 * @return \WP\MCP\Handlers\Tools\ToolsHandler
	 * @throws \RuntimeException When the adapter refuses to build the server.
	 */
	private function handler( array $abilities ): \WP\MCP\Handlers\Tools\ToolsHandler {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-meta-write-wire-test-' . $counter;

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( $abilities );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $abilities ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (meta write wire test)',
					'Test-only server carrying the meta write abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only meta write wire server.' );
		}
		return new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
	}

	/**
	 * Call one tool and return the result DTO.
	 *
	 * @param \WP\MCP\Handlers\Tools\ToolsHandler $handler   Handler.
	 * @param string                              $ability   Ability name.
	 * @param array<string,mixed>                 $arguments Arguments.
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult
	 */
	private function call( \WP\MCP\Handlers\Tools\ToolsHandler $handler, string $ability, array $arguments ) {
		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( $ability ),
				'arguments' => $arguments,
			),
			'req-meta-write-wire'
		);
		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\DTO\CallToolResult::class, $result );
		return $result;
	}

	/**
	 * An editor's post with `aafm_note` allowlisted.
	 *
	 * @return int Post id.
	 */
	private function note_post(): int {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'editor' );
		return self::factory()->post->create();
	}

	public function test_post_meta_bodies_on_the_wire(): void {
		$id      = $this->note_post();
		$handler = $this->handler( self::ABILITIES );
		update_post_meta( $id, 'aafm_note', 'old' );

		$args = array(
			'post_id'  => $id,
			'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument, not a meta query.
		);

		$written = $this->call( $handler, 'aafm/update-post-meta', $args + array( 'value' => 'new' ) );
		$this->assertFalse( $written->getIsError(), $written->getContent()[0]->getText() );
		$this->assertSame(
			array(
				'post_id'      => $id,
				'meta_key'     => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'        => 'new',
				'status'       => 'written',
				'previous'     => 'old',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => true,
					'count'  => 1,
				),
			),
			$written->getStructuredContent()
		);

		$unchanged = $this->call( $handler, 'aafm/update-post-meta', $args + array( 'value' => 'new' ) );
		$this->assertSame(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'    => 'new',
				'status'   => 'unchanged',
				'previous' => 'new',
			),
			$unchanged->getStructuredContent()
		);

		$read = $this->call( $handler, 'aafm/get-post-meta', $args );
		$this->assertSame(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'    => 'new',
			),
			$read->getStructuredContent()
		);

		$deleted = $this->call( $handler, 'aafm/delete-post-meta', $args );
		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'new',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			$deleted->getStructuredContent()
		);

		$absent = $this->call( $handler, 'aafm/delete-post-meta', $args );
		$this->assertSame(
			array(
				'deleted' => true,
				'status'  => 'absent',
			),
			$absent->getStructuredContent()
		);
	}

	public function test_a_refused_post_meta_write_is_an_error_with_the_status_message_on_the_wire(): void {
		$id      = $this->note_post();
		$handler = $this->handler( self::ABILITIES );
		update_post_meta( $id, 'aafm_note', 'old' );

		add_filter( 'update_post_metadata', '__return_false' );
		$refused = $this->call(
			$handler,
			'aafm/update-post-meta',
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'new',
			)
		);
		remove_filter( 'update_post_metadata', '__return_false' );

		$this->assertTrue( $refused->getIsError() );
		$this->assertNull( $refused->getStructuredContent() );
		$this->assertSame( 'The site refused or failed the write; read the key to see its current state.', $refused->getContent()[0]->getText() );
	}

	public function test_a_meta_write_error_carries_identifiers_only(): void {
		$id = $this->note_post();
		update_post_meta( $id, 'aafm_note', 'secret-old-value' );

		add_filter( 'update_post_metadata', '__return_false' );
		$error = aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability input.
				'value'    => 'secret-new-value',
			)
		);
		remove_filter( 'update_post_metadata', '__return_false' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$data = $error->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( array( 'status', 'kind', 'object_id', 'key' ), array_keys( $data ) );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $error->get_error_data() ) );
	}
}
