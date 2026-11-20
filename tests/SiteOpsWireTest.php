<?php
/**
 * Wire-level proof for the Site Ops abilities: each one is listed and answers over a real
 * tools/call, with exactly the keys its schema names.
 *
 * Each batch that registers abilities adds its names and a test method here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class SiteOpsWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_site_cache', '__return_true' );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_site_cache', '__return_true' );
		\SiteopsCacheStubStore::reset();
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * A throwaway MCP server carrying only the named abilities, behind the test handler.
	 *
	 * @param string[] $names Ability names.
	 * @return \AAFM\Tests\Support\McpToolsHandlerShim
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function server( array $names ): \AAFM\Tests\Support\McpToolsHandlerShim {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-site-ops-wire-test-' . $counter;

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( $names );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $names ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (Site Ops wire test)',
					'Test-only server carrying only the Site Ops abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only Site Ops wire server.' );
		}
		return new \AAFM\Tests\Support\McpToolsHandlerShim( $server );
	}

	/**
	 * Call one tool over the wire and return the top-level keys of its structured answer.
	 *
	 * @param \AAFM\Tests\Support\McpToolsHandlerShim $handler   Handler.
	 * @param string                                  $ability   Ability name.
	 * @param array<string,mixed>                     $arguments Tool arguments.
	 * @return string[]
	 */
	private function keys_of_call( \AAFM\Tests\Support\McpToolsHandlerShim $handler, string $ability, array $arguments ): array {
		static $request = 0;
		++$request;
		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( $ability ),
				'arguments' => $arguments,
			),
			'req-site-ops-' . $request
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $result, $ability );
		$this->assertFalse( $result->getIsError(), $ability . ': ' . $result->getContent()[0]->getText() );
		$body = $result->getStructuredContent();
		$this->assertIsArray( $body );
		return array_keys( $body );
	}

	public function test_the_health_tools_are_listed_and_answer_with_exactly_their_keys(): void {
		$names   = array( 'aafm/site-health-status', 'aafm/site-list-updates', 'aafm/site-cron-status' );
		$handler = $this->server( $names );

		$listed = array();
		foreach ( $handler->list_tools()->getTools() as $tool ) {
			$listed[] = $tool->getName();
		}
		foreach ( $names as $name ) {
			$this->assertContains( aafm_mcp_tool_name( $name ), $listed );
		}

		$this->assertEqualsCanonicalizing(
			array( 'tests', 'counts', 'debug_mode', 'debug_log_exists', 'recovery_mode', 'extensions_paused' ),
			$this->keys_of_call( $handler, 'aafm/site-health-status', array() )
		);
		$this->assertEqualsCanonicalizing(
			array( 'core', 'plugins', 'themes', 'last_checked' ),
			$this->keys_of_call( $handler, 'aafm/site-list-updates', array() )
		);
		$this->assertEqualsCanonicalizing(
			array( 'wp_cron_disabled', 'alternate_cron', 'total_events', 'due_now', 'overdue', 'oldest_overdue_seconds', 'hooks' ),
			$this->keys_of_call( $handler, 'aafm/site-cron-status', array() )
		);
	}

	public function test_the_cache_tools_are_listed_and_answer_with_exactly_their_keys(): void {
		$names   = array( 'aafm/site-cache-status', 'aafm/site-purge-post-cache', 'aafm/site-purge-url-cache', 'aafm/site-purge-site-cache' );
		$handler = $this->server( $names );
		\SiteopsCacheStubStore::register( array( 'fake_a' => \SiteopsCacheStubStore::entry( 'Fake A' ) ) );
		update_option( 'home', 'https://example.com' );
		$this->set_permalink_structure( '/%postname%/' );

		$listed = array();
		foreach ( $handler->list_tools()->getTools() as $tool ) {
			$listed[] = $tool->getName();
		}
		foreach ( $names as $name ) {
			$this->assertContains( aafm_mcp_tool_name( $name ), $listed );
		}

		$this->assertEqualsCanonicalizing(
			array( 'providers', 'status_only', 'object_cache', 'budget', 'multisite', 'request_host_differs', 'purge_supported' ),
			$this->keys_of_call( $handler, 'aafm/site-cache-status', array() )
		);

		if ( is_multisite() ) {
			return;
		}

		$id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'wire-post',
			)
		);
		// A write whose output is an object also carries the undo key the call path adds.
		$this->assertEqualsCanonicalizing(
			array( 'post_id', 'url', 'results', 'summary', 'budget_remaining', 'undo' ),
			$this->keys_of_call( $handler, 'aafm/site-purge-post-cache', array( 'post_id' => $id ) )
		);
		$this->assertEqualsCanonicalizing(
			array( 'url', 'results', 'summary', 'budget_remaining', 'undo' ),
			$this->keys_of_call( $handler, 'aafm/site-purge-url-cache', array( 'url' => '/wire-post/' ) )
		);
		$this->assertEqualsCanonicalizing(
			array( 'results', 'summary', 'budget_remaining', 'undo' ),
			$this->keys_of_call( $handler, 'aafm/site-purge-site-cache', array() )
		);
	}
}
