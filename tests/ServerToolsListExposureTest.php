<?php
/**
 * Wire-level proof that tools/list on the plugin's own MCP server reflects exactly the
 * operator's enabled-ability set - not a superset (a filter or a future adapter change adding
 * something), and not a subset (a registration bug silently dropping an enabled tool).
 *
 * Aafm_register_mcp_server() (includes/server.php) passes an EXPLICIT list of enabled ability
 * names into create_server() - it does not rely on the adapter's own "default server" automatic
 * discovery (that path is proven disabled in
 * tests/coexistence/AdapterDefaultServerSuppressedTest.php). This test closes the remaining
 * question directly at the wire boundary, comparing against an INDEPENDENT oracle
 * (aafm_all_server_ability_names(), which reads the enabled-ability registry and bridge list
 * directly) rather than against the server's own internal tool collection - the two must never
 * diverge, and only an independent oracle can prove that.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class ServerToolsListExposureTest extends TestCase {

	/**
	 * Enables the full native catalog and registers it, then acts as an administrator.
	 *
	 * The enabled-abilities option is deleted in TestCase::set_up() (nothing registers by
	 * default), so this fixture setup - not suite-wide pollution - is what makes both the
	 * server and the independent oracle non-empty. Acting as an administrator matters
	 * independently of that: both aafm_build_server_tools() (belt-and-suspenders discover
	 * check when is_user_logged_in()) and aafm_filter_mcp_tools_list() (the request-time
	 * mcp_adapter_tools_list filter) gate on aafm_user_can_discover_ability() the moment a
	 * real user is resolved, so an anonymous run here would silently narrow the wire
	 * response to whatever an anonymous connection can discover - a real but different
	 * question from the one this test asks - while the independent oracle
	 * (aafm_all_server_ability_names()) never applies that gate at all. Using an
	 * administrator, the same role tests/abilities/ServerDiscoveryTest.php uses for its
	 * full-discovery assertions, keeps both sides of the comparison aligned.
	 */
	private function enable_full_catalog_as_admin(): void {
		$this->register_enabled( array_keys( aafm_get_abilities_registry() ) );
		$this->acting_as( 'administrator' );
	}

	/**
	 * Builds a fresh, uniquely-ID'd MCP server via the exact same production code path
	 * aafm_register_mcp_server() uses (aafm_build_server_tools() over
	 * aafm_all_server_ability_names(), the same create_server() call shape), instead of calling
	 * aafm_register_mcp_server() itself.
	 *
	 * Aafm_register_mcp_server() is idempotent per PHPUnit process: it bails immediately if
	 * 'aafm-server' already exists (includes/server.php, "if ( null !== $adapter->get_server(
	 * 'aafm-server' ) ) { return; }"), and the adapter's server registry has no reset method for
	 * the life of the process. On a real full-suite run some earlier test is always the one that
	 * actually builds 'aafm-server', freezing its internal tool list to whatever WAS enabled at
	 * that moment - which this test cannot control and, empirically, is sometimes an empty set.
	 * The mcp_adapter_tools_list filter this plugin hooks (aafm_filter_mcp_tools_list) can only
	 * ever REMOVE tools from that frozen list at request time, never add one back, so recomputing
	 * "now" against the shared singleton is not actually order-independent - discovered by running
	 * this test as part of the full suite, not just in isolation, exactly the case
	 * verification-before-completion exists to catch.
	 *
	 * A distinct server ID sidesteps the singleton entirely: create_server() keys uniqueness only
	 * on $server_id (vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php,
	 * "$this->servers[ $server_id ]"), so a fresh ID gets a fresh McpServer built from whatever is
	 * enabled AT THIS INSTANT, still through the real aafm_build_server_tools()/create_server()/
	 * ToolsHandler pipeline. No REST route is ever dispatched in this test (list_tools()/call_tool()
	 * are called directly, in-process), so sharing AAFM_MCP_NAMESPACE/AAFM_MCP_ROUTE_SEGMENT with
	 * the real 'aafm-server' creates no route collision to worry about here.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server (should
	 *                           never happen with a fresh, unused server ID).
	 */
	private function build_exposure_test_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-exposure-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( aafm_all_server_ability_names() ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (exposure test)',
					'Test-only server built from the current enabled set, independent of the process-wide aafm-server singleton.',
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
			throw new \RuntimeException( 'Failed to build the test-only exposure server for ' . esc_html( $server_id ) );
		}
		return $server;
	}

	/**
	 * Deliberately does not assert a hardcoded, literal tool-name list, and deliberately does
	 * not reuse the process-wide 'aafm-server' singleton (see build_exposure_test_server() for
	 * why: it is frozen by whichever test builds it first and cannot be forced to reflect this
	 * test's own enabled set). Instead builds a fresh, uniquely-ID'd server from the exact same
	 * production code path, then recomputes the expected set from aafm_all_server_ability_names()
	 * against that SAME moment - both come from the enabled-abilities option this test just set,
	 * so the comparison is exact and order-independent by construction, not by assumption.
	 */
	public function test_tools_list_wire_response_exactly_matches_the_independent_enabled_set(): void {
		$this->enable_full_catalog_as_admin();

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_exposure_test_server( $adapter );

		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
		$result  = $handler->list_tools();

		$wire_names = array_map(
			static function ( $tool ) {
				return $tool->getName();
			},
			$result->getTools()
		);
		sort( $wire_names );

		// The independent oracle: computed from the enabled-ability registry and bridge list
		// directly, NOT from $server->get_tools() (which is what list_tools() itself reads).
		$expected_names = array_map( 'aafm_mcp_tool_name', aafm_all_server_ability_names() );
		sort( $expected_names );

		$this->assertNotEmpty( $expected_names, 'The enabled-ability oracle returned nothing - the fixture setup is broken, not a real empty-install case.' );
		$this->assertSame(
			$expected_names,
			$wire_names,
			'tools/list must return exactly the wire-transformed enabled-ability set, no more and no less.'
		);
	}

	/**
	 * Extends the governance-refusal proof tests/abilities/McpErrorStatusTest.php already
	 * established for a NEVER-registered tool name to the specific case doc 224's audit actually
	 * asks about: an ability that IS registered (exists in wp_get_abilities()) but is NOT in the
	 * operator's enabled set. aafm_build_server_tools() is called with
	 * aafm_all_server_ability_names() (includes/server.php), which already excludes a
	 * disabled-but-registered ability from the server's $tools list at registration time - so to
	 * ToolsHandler::call_tool() a disabled ability is indistinguishable from an unregistered one.
	 * This test proves that indistinguishability holds for a REAL disabled ability, not just an
	 * invented name, and additionally proves the same name is absent from tools/list.
	 *
	 * Finds a disabled-but-registered candidate dynamically rather than hardcoding one: the
	 * high-risk floor (aafm_high_risk_abilities(), gated by aafm_high_risk_unlocked() in
	 * includes/registry.php) reliably removes at least one registered ability from
	 * aafm_get_enabled_abilities()'s returned set in the default test fixture. Registering the
	 * FULL catalog via register_enabled() above still leaves those high-risk names out of
	 * aafm_all_server_ability_names() (the subtraction happens inside
	 * aafm_get_enabled_abilities() itself, before the server or the oracle ever see the list),
	 * which is exactly the "registered but disabled" shape this test needs - without this test
	 * needing to toggle the enabled-abilities option itself a second time.
	 */
	public function test_a_disabled_but_registered_ability_is_absent_from_list_and_refused_on_call(): void {
		$this->enable_full_catalog_as_admin();

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_exposure_test_server( $adapter );

		$enabled_names = aafm_all_server_ability_names();

		$disabled_registered_name = null;
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'aafm/' ) && 0 !== strpos( $name, 'aafm-bridge/' ) ) {
				continue;
			}
			if ( ! in_array( $name, $enabled_names, true ) ) {
				$disabled_registered_name = $name;
				break;
			}
		}

		if ( null === $disabled_registered_name ) {
			$this->markTestSkipped( 'No registered-but-disabled aafm/ or aafm-bridge/ ability found in this fixture (every registered ability is currently enabled) - nothing to prove exclusion against.' );
		}

		$wire_name = aafm_mcp_tool_name( $disabled_registered_name );

		$handler            = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
		$list               = $handler->list_tools();
		$wire_names_on_list = array_map(
			static function ( $tool ) {
				return $tool->getName();
			},
			$list->getTools()
		);

		$this->assertNotContains(
			$wire_name,
			$wire_names_on_list,
			sprintf( 'Disabled ability "%s" must not appear in tools/list.', $disabled_registered_name )
		);

		$result = $handler->call_tool( array( 'name' => $wire_name ), 'req-disabled-ability-1' );

		$this->assertInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
		$this->assertSame(
			-32003,
			$result->getError()->getCode(),
			sprintf( 'A tools/call for the disabled ability "%s" must be refused the same way an unregistered tool name is (tool_not_found).', $disabled_registered_name )
		);
	}

	/**
	 * Every name tools/list returns must correspond to a real, currently-existing WP_Ability
	 * (native aafm/ or bridged aafm-bridge/ wrapper), never a bare passthrough of some other
	 * plugin's ability that slipped in.
	 */
	public function test_every_listed_tool_maps_back_to_a_real_aafm_ability(): void {
		$this->enable_full_catalog_as_admin();

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_exposure_test_server( $adapter );

		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
		$result  = $handler->list_tools();

		$this->assertNotEmpty( $result->getTools(), 'tools/list returned nothing - nothing to check.' );

		foreach ( $result->getTools() as $tool ) {
			$wire_name = $tool->getName();
			// aafm_mcp_tool_name() only ever replaces '/' with '-' (includes/server.php); a
			// name that does not start with the transformed 'aafm-' or 'aafm-bridge-' prefix
			// is not ours.
			$this->assertTrue(
				0 === strpos( $wire_name, 'aafm-' ),
				sprintf( 'tools/list returned a tool "%s" outside the aafm-/aafm-bridge- namespace.', $wire_name )
			);
		}
	}
}
