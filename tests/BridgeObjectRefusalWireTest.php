<?php
/**
 * Bridged results through the real wrapper and a REAL tools/call: a result holding a raw object
 * that can hide state comes back as an MCP error with a static message, and plain data, a
 * populated stdClass included, is relayed.
 *
 * The verdict is made inside the bridged wrapper (includes/bridge.php), so the Activity Log row
 * agrees with what the client is told. This test drives the real production entry point -
 * WP\MCP\Handlers\Tools\ToolsHandler::call_tool(), the same method the adapter's REST/streaming
 * transports call - against a throwaway, independently-constructed WP\MCP\Core\McpServer +
 * ToolsHandler pair carrying one bridged fixture ability. Both classes are plain-constructible
 * (see McpServer::__construct()); this test never touches the plugin's own registered
 * "aafm-server" singleton.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;

final class BridgeObjectRefusalWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		delete_option( 'aafm_enabled_bridged_abilities' );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'wirefix/', 8 ) || 0 === strncmp( $slug, 'aafm-bridge/wirefix-', 20 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		if ( wp_has_ability_category( 'bridge-wire-fixture' ) ) {
			wp_unregister_ability_category( 'bridge-wire-fixture' );
		}
		remove_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10 );
		parent::tear_down();
	}

	/**
	 * Register a foreign ability and bridge it through the REAL wrapper path.
	 *
	 * @param string   $slug     Foreign slug, "wirefix/<name>".
	 * @param callable $execute  The foreign execute callback.
	 * @param bool     $is_read_only Whether the foreign ability declares itself read-only.
	 * @return string The wrapper ability name.
	 */
	private function bridge_foreign( string $slug, callable $execute, bool $is_read_only = false ): string {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'bridge-wire-fixture' ) ) {
					wp_register_ability_category(
						'bridge-wire-fixture',
						array(
							'label'       => 'Bridge wire fixture',
							'description' => 'Throwaway fixture for the bridge object wire test.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $slug, $execute, $is_read_only ): void {
				wp_register_ability(
					$slug,
					array(
						'label'               => $slug,
						'description'         => 'Bridge wire fixture.',
						'category'            => 'bridge-wire-fixture',
						'input_schema'        => array( 'type' => 'object' ),
						'execute_callback'    => $execute,
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => $is_read_only ) ),
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( $slug ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
		return aafm_bridge_tool_name( $slug );
	}

	/**
	 * Call the wrapper through the real ToolsHandler::call_tool(), on a throwaway server.
	 *
	 * @param string $wrapper Wrapper ability name.
	 * @return CallToolResult
	 */
	private function call_wrapper( string $wrapper ): CallToolResult {
		// The production list-shaping filter, wired as aafm_register_mcp_server() wires it.
		add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );

		$server = new McpServer(
			'aafm-wire-test-server',
			'aafm-wire-test/v1',
			'aafm-wire-test',
			'AAFM wire test server',
			'Throwaway server for the bridge object wire test.',
			'0.0.0',
			array(),
			null,
			null,
			array( $wrapper )
		);

		$tools = $server->get_tools();
		$this->assertNotEmpty( $tools, 'The wrapper must resolve to a registered MCP tool.' );
		$response = ( new ToolsHandler( $server ) )->call_tool(
			array(
				'name'      => (string) array_key_first( $tools ),
				'arguments' => array(),
			)
		);
		$this->assertInstanceOf( CallToolResult::class, $response );
		return $response;
	}

	public function test_a_wp_user_result_is_refused_with_a_static_message_and_no_structured_content(): void {
		$user    = new \WP_User( self::factory()->user->create() );
		$wrapper = $this->bridge_foreign( 'wirefix/user', static fn(): array => array( 'user' => $user ), true );

		$response = $this->call_wrapper( $wrapper );

		$this->assertTrue( $response->getIsError() );
		$text = $response->getContent()[0]->getText();
		$this->assertStringContainsString( 'cannot be safely relayed over MCP', $text );
		foreach ( array( 'user_pass', 'WP_User', 'user_login', $user->user_login ) as $needle ) {
			$this->assertStringNotContainsString( (string) $needle, $text );
		}
		$this->assertNull( $response->getStructuredContent(), 'A refused object must never reach structuredContent.' );
	}

	public function test_a_populated_stdclass_result_is_relayed_as_a_json_object(): void {
		$wrapper = $this->bridge_foreign(
			'wirefix/object',
			static fn(): array => array(
				'saved'   => true,
				'changed' => (object) array(
					'title' => 'x',
					'size'  => 3,
				),
			),
			true
		);

		$response = $this->call_wrapper( $wrapper );

		$this->assertFalse( $response->getIsError() );
		$text = $response->getContent()[0]->getText();
		$this->assertStringContainsString( '"changed":{"title":"x","size":3}', $text, 'An object, not a list.' );
		$this->assertStringNotContainsString( 'cannot be safely relayed', $text );
		$structured = $response->getStructuredContent();
		$this->assertNotNull( $structured );
		$this->assertStringContainsString( '"changed":{', (string) wp_json_encode( $structured ) );
	}

	public function test_a_write_that_lands_and_is_then_refused_runs_once_and_logs_an_error_row(): void {
		$user    = new \WP_User( self::factory()->user->create() );
		$calls   = 0;
		$wrapper = $this->bridge_foreign(
			'wirefix/write',
			static function () use ( &$calls, $user ): array {
				++$calls;
				return array( 'user' => $user );
			}
		);

		$response = $this->call_wrapper( $wrapper );

		$this->assertTrue( $response->getIsError() );
		$this->assertSame( 1, $calls, 'The write ran exactly once.' );
		$rows = aafm_query_activity( array( 'ability' => $wrapper ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', (string) $rows[0]['status'], 'The log must not say success for a call the client was told failed.' );
	}

	public function test_the_wrapper_refuses_whatever_the_wire_tool_is_called(): void {
		add_filter(
			'mcp_adapter_tool_name',
			static fn(): string => 'site_renamed_wire_name',
			10,
			2
		);
		$user    = new \WP_User( self::factory()->user->create() );
		$wrapper = $this->bridge_foreign( 'wirefix/renamed', static fn(): array => array( 'user' => $user ), true );

		$response = $this->call_wrapper( $wrapper );

		$this->assertTrue( $response->getIsError(), 'Renaming the wire tool must not skip the verdict.' );
		$this->assertStringContainsString( 'cannot be safely relayed over MCP', $response->getContent()[0]->getText() );
	}

	/**
	 * A float that is NaN or infinite cannot be JSON encoded, so it is refused like any other value the
	 * bridge cannot relay: the same static message, and an error row.
	 *
	 * @dataProvider non_finite_provider
	 *
	 * @param float $value The non-finite float.
	 */
	public function test_a_non_finite_float_result_is_refused_with_the_static_message_and_an_error_row( float $value ): void {
		$wrapper = $this->bridge_foreign( 'wirefix/float', static fn(): array => array( 'ratio' => $value ), true );

		$response = $this->call_wrapper( $wrapper );

		$this->assertTrue( $response->getIsError() );
		$this->assertStringContainsString( 'cannot be safely relayed over MCP', $response->getContent()[0]->getText() );
		$rows = aafm_query_activity( array( 'ability' => $wrapper ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', (string) $rows[0]['status'] );
		$this->assertSame( 'aafm_bridge_unsupported_result_shape', $rows[0]['detail'] );
	}

	/**
	 * Floats the wrapper refuses.
	 *
	 * @return array<string,array{0:float}>
	 */
	public function non_finite_provider(): array {
		return array(
			'NaN'          => array( NAN ),
			'INF'          => array( INF ),
			'negative INF' => array( -INF ),
		);
	}

	/**
	 * The adapter turns a result of exactly { success: false, error: "<text>" } into an error for the
	 * client, so the Activity Log row says error too, and the client gets the text unchanged.
	 */
	public function test_a_success_false_result_with_an_error_string_logs_an_error_row_and_relays_the_text(): void {
		$wrapper = $this->bridge_foreign(
			'wirefix/reported',
			static fn(): array => array(
				'success' => false,
				'error'   => 'Quota exceeded.',
			),
			true
		);

		$response = $this->call_wrapper( $wrapper );

		$this->assertTrue( $response->getIsError() );
		$this->assertSame( 'Quota exceeded.', $response->getContent()[0]->getText() );
		$rows = aafm_query_activity( array( 'ability' => $wrapper ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', (string) $rows[0]['status'], 'The log must agree with what the client was told.' );
		$this->assertSame( 'aafm_bridge_reported_failure', $rows[0]['detail'] );
	}

	/**
	 * Anything wider than that exact shape is data. The adapter relays it as a success, so the row says
	 * success too.
	 *
	 * @dataProvider success_false_but_data_provider
	 *
	 * @param array<string,mixed> $result The foreign result.
	 */
	public function test_a_result_that_is_not_the_exact_success_false_error_shape_is_relayed_as_success( array $result ): void {
		$wrapper = $this->bridge_foreign( 'wirefix/data', static fn(): array => $result, true );

		$response = $this->call_wrapper( $wrapper );

		$this->assertFalse( $response->getIsError() );
		$rows = aafm_query_activity( array( 'ability' => $wrapper ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'success', (string) $rows[0]['status'] );
	}

	/**
	 * Results that look like a failure but are not the adapter's exact error shape.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function success_false_but_data_provider(): array {
		return array(
			'success false alone'        => array( array( 'success' => false ) ),
			'success false, empty error' => array(
				array(
					'success' => false,
					'error'   => '  ',
				),
			),
			'success false, non-string'  => array(
				array(
					'success' => false,
					'error'   => array( 'code' => 5 ),
				),
			),
			'success true with an error' => array(
				array(
					'success' => true,
					'error'   => 'ignored',
				),
			),
			'success 0 with an error'    => array(
				array(
					'success' => 0,
					'error'   => 'not a boolean false',
				),
			),
			'the shape nested one level' => array(
				array(
					'data' => array(
						'success' => false,
						'error'   => 'nested',
					),
				),
			),
		);
	}
}
