<?php
/**
 * Wire-level proof that a role/client allowlist override actually removes a tool from a real
 * tools/list response and refuses it on a real tools/call - not only the direct-PHP proof in
 * tests/AllowlistTest.php and tests/AllowlistSweepTest.php.
 *
 * Exercises aafm/update-post specifically (Amendment 14): it has a per-object permission branch
 * (aafm_ability_list_permission() returns non-null for it), which is exactly the shape a check
 * placed at the wrong seam would miss - a coarse-cap-only sample would not catch that class of
 * bug.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AllowlistWireTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_ability_allowlist_overrides' );
	}

	/**
	 * Builds a throwaway single-ability MCP server, mirroring
	 * ServerToolsListExposureTest::build_exposure_test_server().
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter   Adapter instance.
	 * @param string[]                $abilities Abilities the server carries.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter, array $abilities = array( 'aafm/update-post' ) ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-allowlist-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $abilities ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (allowlist wire test)',
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
			throw new \RuntimeException( 'Failed to build the test-only allowlist wire server.' );
		}
		return $server;
	}

	public function test_a_role_scoped_out_of_update_post_never_sees_or_can_call_it(): void {
		$this->register_enabled( array( 'aafm/update-post' ) );
		$post_id = self::factory()->post->create();

		// Build the server BEFORE the restriction exists and BEFORE any user is logged in: the
		// server's own belt-and-suspenders discover check in aafm_build_server_tools() only runs
		// when is_user_logged_in(), so building it now leaves the tool genuinely present in the
		// server's tool set - the state a persistent, long-lived MCP server is actually in when a
		// LATER request happens to be scoped out. That is the shape this test needs to exercise
		// the per-request tools/list filter and the decorated call-time permission check, rather
		// than the server-construction-time exclusion AllowlistSweepTest already covers directly.
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $server );

		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'administrator',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$this->acting_as( 'administrator' );

		// list_tools() itself returns the server's own static tool set - the per-request
		// visibility narrowing happens in aafm_filter_mcp_tools_list(), hooked to the
		// mcp_adapter_tools_list filter the adapter applies during real JSON-RPC dispatch
		// (ServerToolsListExposureTest's own docblock confirms list_tools() is unfiltered).
		// Apply that same filter directly here to prove the per-request narrowing, the same way a
		// real tools/list request would see it.
		$filtered_tools = aafm_filter_mcp_tools_list( $handler->list_tools()->getTools(), $server );
		$wire_names     = array_map(
			static fn( $tool ) => $tool->getName(),
			$filtered_tools
		);
		$this->assertNotContains(
			aafm_mcp_tool_name( 'aafm/update-post' ),
			$wire_names,
			'A scoped-out mapped per-object ability must vanish from tools/list, not merely fail at call time.'
		);

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/update-post' ),
				'arguments' => array(
					'post_id' => $post_id,
					'title'   => 'Should be refused',
				),
			),
			'req-allowlist-wire-1'
		);
		// The tool genuinely exists on this server (built before the restriction was applied),
		// so execute() runs its real internal permission re-check and denies via a WP_Error - the
		// MCP protocol reports a tool EXECUTION failure as a successful JSON-RPC response with
		// CallToolResult.isError=true, not a JSONRPCErrorResponse (that shape is reserved for
		// protocol-level errors such as an unknown tool name).
		$this->assertInstanceOf( \AAFM\Tests\Support\McpToolCallOutcome::class, $result );
		$this->assertTrue(
			$result->getIsError(),
			'A scoped-out ability must be refused on tools/call even though the underlying WordPress capability would allow it.'
		);

		$rows = aafm_query_activity( array( 'ability' => 'aafm/update-post' ) );
		$this->assertNotEmpty( $rows, 'The refused call must still be audited.' );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_a_client_restriction_denies_even_though_the_role_is_unrestricted(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => 'restricted-client',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$this->register_enabled( array( 'aafm/update-post' ) );
		$post_id = self::factory()->post->create();
		$this->acting_as( 'administrator' );
		aafm_oauth_current_client_id( 'restricted-client' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/update-post' ),
				'arguments' => array(
					'post_id' => $post_id,
					'title'   => 'Should be refused',
				),
			),
			'req-allowlist-wire-2'
		);
		$this->assertInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $result );
	}

	/**
	 * Put an OAuth grant with a preset in the request store, as the bearer resolver does.
	 *
	 * @param string $client_id Client id.
	 * @param string $preset    Preset slug, '' for Custom.
	 * @return void
	 */
	private function grant_with_preset( string $client_id, string $preset ): void {
		aafm_oauth_current_client_id( $client_id );
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'preset'    => $preset,
					'client_id' => $client_id,
					'user_id'   => get_current_user_id(),
				)
			)
		);
	}

	public function test_a_grant_with_a_preset_hides_tools_outside_it_in_tools_list_and_refuses_the_call(): void {
		$this->register_enabled( array( 'aafm/update-post', 'aafm/delete-post' ) );
		$post_id = self::factory()->post->create();
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_single_ability_server( $adapter, array( 'aafm/update-post', 'aafm/delete-post' ) );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $server );

		$this->acting_as( 'administrator' );
		$this->grant_with_preset( 'preset-client', 'content_editor' );

		$names = array_map( static fn( $tool ) => $tool->getName(), aafm_filter_mcp_tools_list( $handler->list_tools()->getTools(), $server ) );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/update-post' ), $names );
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/delete-post' ), $names );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/delete-post' ),
				'arguments' => array( 'post_id' => $post_id ),
			),
			'req-preset-wire-1'
		);
		$this->assertInstanceOf( \AAFM\Tests\Support\McpToolCallOutcome::class, $result );
		$this->assertTrue( $result->getIsError() );
		$this->assertNotNull( get_post( $post_id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/delete-post' ) );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertStringContainsString( 'Refused: scope_list', (string) $rows[0]['detail'] );
	}

	public function test_a_preset_cannot_widen_the_allowlist(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => 'narrow-client',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$admin = $this->acting_as( 'administrator' );
		$this->grant_with_preset( 'narrow-client', 'content_editor' );
		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/create-post', $admin, 'narrow-client' ) );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-posts', $admin, 'narrow-client' ) );
	}

	public function test_the_preset_does_not_apply_to_an_application_password_request(): void {
		$admin = $this->acting_as( 'administrator' );
		// A grant left in the store from another request, and no OAuth client on this one.
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'preset'    => 'read_only',
					'client_id' => 'other-client',
					'user_id'   => $admin,
				)
			)
		);
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $admin, null ) );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $admin, 'this-client' ), 'A grant for another client is ignored.' );
	}

	public function test_a_bridged_call_is_gated_by_the_preset_on_the_execute_path(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'preset-wire-things' ) ) {
					wp_register_ability_category(
						'preset-wire-things',
						array(
							'label'       => 'Preset wire',
							'description' => 'Fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				$base = array(
					'category'            => 'preset-wire-things',
					'description'         => 'Fixture.',
					'input_schema'        => array( 'type' => 'object' ),
					'execute_callback'    => static fn() => array( 'ran' => true ),
					'permission_callback' => '__return_true',
				);
				aafm_register_ability_with_log(
					'aafm-bridge/preset-wire-reader',
					$base + array(
						'label' => 'Reader',
						'meta'  => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				aafm_register_ability_with_log(
					'aafm-bridge/preset-wire-writer',
					$base + array(
						'label' => 'Writer',
						'meta'  => array( 'annotations' => array( 'readonly' => false ) ),
					)
				);
			}
		);
		$this->acting_as( 'administrator' );
		$run = static function ( string $name ): bool {
			$result = wp_get_ability( $name )->execute( array() );
			return ! is_wp_error( $result );
		};

		$expect = array(
			'content_editor' => array( false, false ),
			'read_only'      => array( true, false ),
			''               => array( true, true ),
		);
		foreach ( $expect as $preset => $outcome ) {
			$this->grant_with_preset( 'bridge-client', (string) $preset );
			$this->assertSame( $outcome, array( $run( 'aafm-bridge/preset-wire-reader' ), $run( 'aafm-bridge/preset-wire-writer' ) ), "preset '$preset'" );
		}
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm-bridge/preset-wire-writer',
				'status'  => 'denied',
			)
		);
		$this->assertNotEmpty( $rows );
		$this->assertStringContainsString( 'Refused: scope_list', (string) $rows[0]['detail'] );

		wp_unregister_ability( 'aafm-bridge/preset-wire-reader' );
		wp_unregister_ability( 'aafm-bridge/preset-wire-writer' );
	}
}
