<?php
/**
 * Wire-level proof that aafm-avada-get-page-content and aafm-avada-replace-text round-trip over
 * a real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AvadaWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * Builds a throwaway MCP server carrying only the two Avada abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_avada_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-avada-wire-test-' . $counter;

		$tools = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) )
		);

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (Avada wire test)',
					'Test-only server carrying only the Avada abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only Avada wire server.' );
		}
		return $server;
	}

	public function test_avada_abilities_round_trip_over_a_real_tools_call(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '[fusion_builder_container]Hello world[/fusion_builder_container]' )
		);
		update_post_meta( $post->ID, 'fusion_builder_status', 'active' );

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) );
		$this->acting_as( 'editor' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_avada_server( $adapter );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $server );

		$get_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-get-page-content' ),
				'arguments' => array( 'post_id' => $post->ID ),
			),
			'req-avada-get-1'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $get_result );
		$this->assertTrue( $get_result->getStructuredContent()['is_avada_owned'] ?? false );
		$this->assertArrayNotHasKey( 'structure', $get_result->getStructuredContent(), 'No outline unless asked for.' );
		$this->assertArrayNotHasKey( 'structure_status', $get_result->getStructuredContent() );

		$outline = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-get-page-content' ),
				'arguments' => array(
					'post_id'           => $post->ID,
					'include_structure' => true,
				),
			),
			'req-avada-get-2'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $outline );
		$wire = json_decode( (string) wp_json_encode( $outline->getStructuredContent() ), true );
		$this->assertSame( 'ok', $wire['structure_status'] );
		$this->assertSame( 'fusion_builder_container', $wire['structure'][0]['tag'] );
		$this->assertStringContainsString( '"atts":{}', $outline->getContent()[0]->getText(), 'Attributes travel as a JSON object, even when empty.' );

		$replace_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-replace-text' ),
				'arguments' => array(
					'post_id' => $post->ID,
					'search'  => 'Hello world',
					'replace' => 'Greetings world',
				),
			),
			'req-avada-replace-1'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $replace_result );
		$this->assertSame( 1, $replace_result->getStructuredContent()['replacements'] ?? null );

		$stored = get_post_field( 'post_content', $post->ID, 'raw' );
		$this->assertStringContainsString( 'Greetings world', $stored );
	}

	public function test_avada_read_with_the_outline_and_page_options_round_trips_over_a_real_tools_call(): void {
		$inner   = '[fusion_text]Hi &amp; you[/fusion_text]';
		$content = '[fusion_builder_container]' . $inner . '[/fusion_builder_container]';
		$post    = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
		update_post_meta( $post->ID, 'fusion_builder_status', 'active' );
		update_post_meta(
			$post->ID,
			'_fusion',
			array(
				'page_title_bar'          => 'no',
				'pages_sidebar'           => 'Footer Widget Area 1',
				'form_notification_email' => 'owner@example.invalid',
			)
		);
		update_post_meta( $post->ID, '_fusion_builder_custom_css', '.s8b-page { color: #123456; }' );
		add_filter(
			'aafm_builder_vendor',
			static fn( $resolved, string $entry ) => 'avada_option_name' === $entry ? static fn(): string => 'fusion_options' : $resolved,
			10,
			2
		);

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/avada-get-page-content', 'aafm/avada-replace-text' ) );
		$this->acting_as( 'editor' );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $this->build_avada_server( \WP\MCP\Core\McpAdapter::instance() ) );
		$call    = static function ( array $arguments, string $req ) use ( $handler, $post ) {
			return $handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/avada-get-page-content' ),
					'arguments' => array_merge( array( 'post_id' => $post->ID ), $arguments ),
				),
				$req
			);
		};

		$both = $call(
			array(
				'include_structure'    => true,
				'include_page_options' => true,
			),
			'req-avada-s8b-1'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $both );
		$fp = static fn( string $source ): string => substr( hash( 'sha256', $source ), 0, 16 );
		$this->assertSame(
			array(
				'post_id'          => $post->ID,
				'content'          => $content,
				'is_avada_owned'   => true,
				'structure_status' => 'ok',
				'structure'        => array(
					array(
						'index'        => 0,
						'depth'        => 0,
						'tag'          => 'fusion_builder_container',
						'self_closing' => false,
						'has_closer'   => true,
						'atts'         => array(),
						'fingerprint'  => $fp( $content ),
						'atts_decoded' => array(),
					),
					array(
						'index'        => 1,
						'depth'        => 1,
						'tag'          => 'fusion_text',
						'self_closing' => false,
						'has_closer'   => true,
						'atts'         => array(),
						'fingerprint'  => $fp( $inner ),
						'atts_decoded' => array(),
						'text'         => 'Hi &amp; you',
					),
				),
				'page_fingerprint' => $fp( $content ),
				'custom_css'       => '.s8b-page { color: #123456; }',
				'page_options'     => array(
					'page_title_bar' => 'no',
					'pages_sidebar'  => 'Footer Widget Area 1',
				),
			),
			json_decode( (string) wp_json_encode( $both->getStructuredContent() ), true )
		);
		$this->assertStringContainsString( '"atts_decoded":{}', $both->getContent()[0]->getText(), 'Decoded attributes travel as a JSON object.' );
		$this->assertStringNotContainsString( 'owner@example.invalid', $both->getContent()[0]->getText() );

		$plain = $call( array(), 'req-avada-s8b-2' );
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $plain );
		$this->assertSame( array( 'post_id', 'content', 'is_avada_owned' ), array_keys( $plain->getStructuredContent() ) );
	}
}
