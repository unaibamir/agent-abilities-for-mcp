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

	/**
	 * Builds a throwaway MCP server carrying only the four redirect tools.
	 *
	 * @return \AAFM\Tests\Support\McpToolsHandlerShim
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function redirect_wire_handler(): \AAFM\Tests\Support\McpToolsHandlerShim {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-site-redirects-wire-test-' . $counter;
		$names     = array( 'aafm/site-list-redirects', 'aafm/site-get-redirect', 'aafm/site-list-404s', 'aafm/site-test-redirect' );

		add_filter( 'aafm_integration_active_site_redirects', '__return_true' );
		aafm_flush_registry_cache();
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
					'Agent Abilities for MCP (redirect wire test)',
					'Test-only server carrying only the redirect tools.',
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
			throw new \RuntimeException( 'Failed to build the test-only redirect wire server.' );
		}
		return new \AAFM\Tests\Support\McpToolsHandlerShim( $server );
	}

	public function test_redirect_tools_are_listed_and_answer_over_a_real_tools_call(): void {
		\Aafm_Siteops_Redirect_Stub::reset();
		add_filter(
			'aafm_site_redirect_adapters',
			static function (): array {
				return array(
					'fake_a' => \Aafm_Siteops_Redirect_Stub::entry( 'Fake A' ),
					'fake_b' => \Aafm_Siteops_Redirect_Stub::entry( 'Fake B' ),
				);
			}
		);
		\Aafm_Siteops_Redirect_Stub::$fake['fake_a']['rows']   = array( \Aafm_Siteops_Redirect_Stub::rule( '1', '/old-a', 'https://user:secret@example.org/new/?key=abc' ) );
		\Aafm_Siteops_Redirect_Stub::$fake['fake_a']['log404'] = array(
			array(
				'path'      => '/acc%20miss?token=abc',
				'count'     => 2,
				'last_seen' => 1700000000,
			),
		);

		$handler = $this->redirect_wire_handler();

		$listed = array();
		foreach ( $handler->list_tools()->getTools() as $tool ) {
			$listed[] = $tool->getName();
		}
		foreach ( array( 'aafm/site-list-redirects', 'aafm/site-get-redirect', 'aafm/site-list-404s', 'aafm/site-test-redirect' ) as $name ) {
			$this->assertContains( aafm_mcp_tool_name( $name ), $listed );
		}

		$list = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/site-list-redirects' ),
				'arguments' => array( 'status' => 'enabled' ),
			),
			'req-site-redirects-1'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $list );
		$this->assertFalse( $list->getIsError(), $list->getContent()[0]->getText() );
		$body = $list->getStructuredContent();
		$this->assertSame( array( 'redirects', 'total', 'page', 'per_page', 'truncated', 'dropped', 'providers' ), array_keys( $body ) );
		$this->assertSame( array( 'provider', 'id', 'source', 'target', 'target_trimmed', 'code', 'enabled', 'kind', 'hits' ), array_keys( $body['redirects'][0] ), 'No editable key and no regex key on the wire.' );
		$this->assertSame( 'https://example.org/new/', $body['redirects'][0]['target'] );
		$this->assertNull( $body['redirects'][0]['hits'] );

		$named = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/site-list-redirects' ),
				'arguments' => array( 'provider' => 'not_a_supported_plugin' ),
			),
			'req-site-redirects-1b'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $named );
		$this->assertTrue( $named->getIsError(), 'A provider outside the five slugs is refused by the input schema.' );

		$get = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/site-get-redirect' ),
				'arguments' => array( 'id' => '1' ),
			),
			'req-site-redirects-2'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $get );
		$this->assertTrue( $get->getIsError(), 'Two ready providers and no name must be refused.' );
		$this->assertStringContainsString( 'fake_a, fake_b', $get->getContent()[0]->getText() );

		$log = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/site-list-404s' ),
				'arguments' => array(),
			),
			'req-site-redirects-3'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $log );
		$this->assertFalse( $log->getIsError(), $log->getContent()[0]->getText() );
		$body = $log->getStructuredContent();
		$this->assertSame( 'untrusted_content_notice', array_keys( $body )[0], 'The notice is the first key of the response.' );
		$this->assertSame(
			array(
				'provider'       => 'fake_a',
				'untrusted_path' => '/acc%20miss',
				'count'          => 2,
				'last_seen'      => gmdate( 'c', 1700000000 ),
			),
			$body['entries'][0]
		);

		$test = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/site-test-redirect' ),
				'arguments' => array( 'url' => '/old-a' ),
			),
			'req-site-redirects-4'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $test );
		$this->assertFalse( $test->getIsError(), $test->getContent()[0]->getText() );
		$body = $test->getStructuredContent();
		$this->assertSame( array( 'path', 'first_match', 'evaluated', 'chain', 'chain_ends', 'loop_detected', 'approximate', 'unsupported_detected', 'protected_path', 'complete' ), array_keys( $body ) );
		$this->assertSame( 'https://example.org/new/', $body['first_match']['target'] );
		$this->assertFalse( $body['complete'] );

		$bad = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/site-test-redirect' ),
				'arguments' => array( 'url' => 'https://other.example/x' ),
			),
			'req-site-redirects-5'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $bad );
		$this->assertTrue( $bad->getIsError(), 'A URL on another host must be refused.' );

		remove_filter( 'aafm_integration_active_site_redirects', '__return_true' );
		aafm_flush_registry_cache();
	}
}
