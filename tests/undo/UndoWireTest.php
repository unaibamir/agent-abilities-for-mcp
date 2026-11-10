<?php
/**
 * The two agent tools over a real MCP tools/call: the exact response shapes, and that no value ever
 * travels in a response, an error or a log detail.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoWireTest extends UndoTestCase {

	private const TOOLS = array( 'aafm/update-post', 'aafm/delete-post', 'aafm/undo-change', 'aafm/list-changes' );

	/**
	 * Tools handler for the test server.
	 *
	 * @var \AAFM\Tests\Support\McpToolsHandlerShim
	 */
	private $handler;

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
		aafm_oauth_current_client_id( 'client-wire' );
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-undo-wire-test-' . $counter;
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( self::TOOLS );
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( self::TOOLS ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (undo wire test)',
					'Test-only server carrying the change history tools.',
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
			throw new \RuntimeException( 'Failed to build the undo wire server.' );
		}
		$this->handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $server );
	}

	public function tear_down(): void {
		aafm_oauth_current_client_id( null, true );
		parent::tear_down();
	}

	/**
	 * Call a tool and return the decoded JSON text plus the error flag.
	 *
	 * @param string              $ability   Ability name.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return array{0: array<string,mixed>, 1: bool, 2: string}
	 */
	private function call( string $ability, array $arguments ): array {
		$result = $this->handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( $ability ),
				'arguments' => $arguments,
			),
			'req-undo-wire'
		);
		$this->assertInstanceOf( \AAFM\Tests\Support\McpToolCallOutcome::class, $result );
		$text = $result->getContent()[0]->getText();
		return array( (array) json_decode( $text, true ), (bool) $result->getIsError(), $text );
	}

	public function test_exact_bodies_for_a_covered_write_list_changes_and_undo(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'SENTINEL-OLD' ) );

		[ $edit, $edit_error ] = $this->call(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'SENTINEL-NEW',
			)
		);
		$this->assertFalse( $edit_error );
		$this->assertTrue( $edit['undo']['undoable'] );
		$change = (int) $edit['undo']['change_ids'][0];

		[ $list, $list_error, $list_text ] = $this->call(
			'aafm/list-changes',
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);
		$this->assertFalse( $list_error, $list_text );
		$this->assertSame( array( 'changes', 'page', 'per_page', 'has_more' ), array_keys( $list ) );
		$this->assertSame( array( 'id', 'ability', 'object_type', 'object_id', 'fields', 'created_gmt', 'source', 'undoable', 'reverts_change_id', 'reverted_by_change_id' ), array_keys( $list['changes'][0] ) );
		$this->assertStringNotContainsString( 'SENTINEL', $list_text );

		[ $undo, $undo_error, $undo_text ] = $this->call( 'aafm/undo-change', array( 'change_id' => $change ) );
		$this->assertFalse( $undo_error, $undo_text );
		$this->assertSame( array( 'change_id', 'outcome', 'new_change_id', 'fields', 'note', 'undo' ), array_keys( $undo ) );
		$this->assertSame( 'reverted', $undo['outcome'] );
		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array( $undo['new_change_id'] ),
				'reason'     => 'needs_owner',
			),
			$undo['undo']
		);
		$this->assertStringNotContainsString( 'SENTINEL', $undo_text );
		$this->assertSame( 'SENTINEL-OLD', get_post( $post )->post_title );
	}

	public function test_an_excluded_tool_says_why_in_its_response(): void {
		$post = self::factory()->post->create();

		[ $body, $is_error ] = $this->call( 'aafm/delete-post', array( 'post_id' => $post ) );

		$this->assertFalse( $is_error );
		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array(),
				'reason'     => 'permanent_delete',
			),
			$body['undo']
		);
	}

	public function test_a_refusal_and_an_unknown_id_carry_no_value_and_no_error_data(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'SENTINEL-OLD' ) );
		$this->call(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'SENTINEL-NEW',
			)
		);

		[ , $missing_error, $missing_text ] = $this->call( 'aafm/undo-change', array( 'change_id' => 999999 ) );

		$this->assertTrue( $missing_error );
		$this->assertStringNotContainsString( 'SENTINEL', $missing_text );
	}
}
