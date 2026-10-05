<?php
/**
 * Drives the adapter's real ToolsHandler the way the 0.7.0 wire path does.
 *
 * 0.7.0 takes a validated request record and a request context, and returns logical arrays that
 * the wire orchestrator turns into result records. The wire tests were written against 0.6.1,
 * which took plain arrays and returned result objects. This builds the request, calls the real
 * handler, and projects its answer through the same schema calls the orchestrator makes, so a
 * test still reads a result object, or a protocol error object when the handler refused the call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\CallToolResult;
use WP\McpSchema\Record\ListToolsRequest;
use WP\McpSchema\Record\ListToolsResult;
use WP\McpSchema\Schema;
use WP\McpSchema\Schemas;

final class McpToolsHandlerShim {

	/**
	 * The adapter's real handler.
	 *
	 * @var ToolsHandler
	 */
	private ToolsHandler $handler;

	/**
	 * The schema revision every call is made under.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * The request context handed to the handler.
	 *
	 * @var McpRequestContext
	 */
	private McpRequestContext $context;

	/**
	 * Build a handler for a server.
	 *
	 * @param McpServer $server Server whose tools the handler serves.
	 */
	public function __construct( McpServer $server ) {
		$this->handler = new ToolsHandler( $server );
		$this->schema  = self::schema();
		$this->context = new McpRequestContext( $this->schema, new \stdClass(), null, 'http' );
	}

	/**
	 * The schema revision the shim and the tests use.
	 *
	 * @return Schema
	 */
	public static function schema(): Schema {
		return Schemas::create()->forVersion( '2025-11-25' );
	}

	/**
	 * Call one tool.
	 *
	 * @param array<string, mixed> $params Name and arguments, as a tools/call request carries them.
	 * @param string|int           $id     Request id (optional).
	 * @return McpToolCallOutcome|McpProtocolError The result, or the protocol error the handler returned.
	 */
	public function call_tool( array $params, $id = 1 ) {
		$request = $this->schema->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => 'tools/call',
				'params'  => $params,
			)
		);

		$data = $this->handler->call_tool( $request, $this->context );

		if ( isset( $data['error'] ) ) {
			return new McpProtocolError( $data );
		}

		return new McpToolCallOutcome( $this->schema->fromArray( CallToolResult::class, $data ) );
	}

	/**
	 * List the server's tools.
	 *
	 * @return ListToolsResult
	 */
	public function list_tools(): ListToolsResult {
		$request = $this->schema->fromArray(
			ListToolsRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);

		return $this->schema->fromArray( ListToolsResult::class, $this->handler->list_tools( $request, $this->context ) );
	}
}
