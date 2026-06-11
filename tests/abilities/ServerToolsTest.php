<?php
/**
 * Server $tools builder: only enabled AND currently-callable abilities are listed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ServerToolsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The audited registration wrapper logs to the custom table, so it must exist.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Contribute registry entries for the fixtures so aafm_get_enabled_abilities()
		// and the tools/list filter can map tool names back to abilities (the same way
		// real Phase 3/4 domain files do via the aafm_abilities_registry filter).
		add_filter( 'aafm_abilities_registry', array( $this, 'register_fixture_registry' ) );

		// Categories first, inside their gated init action (idempotent).
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );

		// Two fixtures: one any logged-in user can call, one admin-only. The Abilities
		// registry is a process-singleton, so guard against re-registration across tests.
		$this->in_action(
			'wp_abilities_api_init',
			function (): void {
				if ( ! wp_has_ability( 'aafm/pub-read' ) ) {
					aafm_register_ability_with_log(
						'aafm/pub-read',
						array(
							'label'               => 'Pub Read',
							'description'         => 'Anyone may read.',
							'category'            => 'aafm-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array(),
							'permission_callback' => '__return_true',
						)
					);
				}
				if ( ! wp_has_ability( 'aafm/admin-write' ) ) {
					aafm_register_ability_with_log(
						'aafm/admin-write',
						array(
							'label'               => 'Admin Write',
							'description'         => 'Admin only.',
							'category'            => 'aafm-writes',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array(),
							'permission_callback' => static fn() => current_user_can( 'manage_options' ),
						)
					);
				}
			}
		);

		update_option( 'aafm_enabled_abilities', array( 'aafm/pub-read', 'aafm/admin-write' ) );
	}

	/**
	 * Contribute the two fixtures to the static registry.
	 *
	 * @param array<string,array<string,mixed>> $registry Registry.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fixture_registry( array $registry ): array {
		$registry['aafm/pub-read']    = array(
			'label'        => 'Pub Read',
			'description'  => 'Anyone may read.',
			'group'        => 'reads',
			'risk'         => 'read',
			'args_builder' => '__return_empty_array',
		);
		$registry['aafm/admin-write'] = array(
			'label'        => 'Admin Write',
			'description'  => 'Admin only.',
			'group'        => 'writes',
			'risk'         => 'write',
			'args_builder' => '__return_empty_array',
		);
		return $registry;
	}

	/**
	 * Run a callback as if the named Abilities API init action were firing.
	 *
	 * Core gates wp_register_ability()/wp_register_ability_category() on doing_action();
	 * pushing the action name onto $wp_current_filter is the idiom core's own test trait uses.
	 *
	 * @param string   $action   Init action name.
	 * @param callable $callback Registration callback.
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	public function test_subscriber_sees_only_callable_tools(): void {
		$this->acting_as( 'subscriber' );
		$tools = aafm_build_server_tools( array( 'aafm/pub-read', 'aafm/admin-write' ) );
		$this->assertContains( 'aafm/pub-read', $tools );
		$this->assertNotContains( 'aafm/admin-write', $tools );
	}

	public function test_admin_sees_both_tools(): void {
		$this->acting_as( 'administrator' );
		$tools = aafm_build_server_tools( array( 'aafm/pub-read', 'aafm/admin-write' ) );
		$this->assertContains( 'aafm/pub-read', $tools );
		$this->assertContains( 'aafm/admin-write', $tools );
	}

	public function test_registering_the_server_does_not_error(): void {
		$this->acting_as( 'administrator' );
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		// The adapter gates create_server() on the mcp_adapter_init action, so simulate it.
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter ): void {
				aafm_register_mcp_server( $adapter );
			}
		);
		$this->assertTrue( true );
	}

	public function test_transport_gate_denies_anonymous(): void {
		wp_set_current_user( 0 );
		$result = aafm_transport_permission_callback( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_transport_gate_allows_authenticated(): void {
		$this->acting_as( 'subscriber' );
		$this->assertTrue( aafm_transport_permission_callback( new \WP_REST_Request() ) );
	}

	public function test_raw_permission_check_does_not_log_denials(): void {
		// aafm_user_can_call_ability uses the UNDECORATED callback, so a failing check
		// must NOT write a denied audit row (avoids flooding the log during tools/list).
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_call_ability( 'aafm/admin-write', array() ) );
		$this->assertTrue( aafm_user_can_call_ability( 'aafm/pub-read', array() ) );

		$denied = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 0, (array) $denied );
	}

	public function test_tools_list_filter_hides_uncallable_tools_for_subscriber(): void {
		$this->acting_as( 'subscriber' );
		$tools = array(
			$this->tool_dto( aafm_mcp_tool_name( 'aafm/pub-read' ) ),
			$this->tool_dto( aafm_mcp_tool_name( 'aafm/admin-write' ) ),
		);
		$names = $this->tool_names( aafm_filter_mcp_tools_list( $tools ) );
		$this->assertContains( 'aafm-pub-read', $names );
		$this->assertNotContains( 'aafm-admin-write', $names );
	}

	public function test_tools_list_filter_keeps_all_tools_for_admin(): void {
		$this->acting_as( 'administrator' );
		$tools = array(
			$this->tool_dto( aafm_mcp_tool_name( 'aafm/pub-read' ) ),
			$this->tool_dto( aafm_mcp_tool_name( 'aafm/admin-write' ) ),
		);
		$names = $this->tool_names( aafm_filter_mcp_tools_list( $tools ) );
		$this->assertContains( 'aafm-pub-read', $names );
		$this->assertContains( 'aafm-admin-write', $names );
	}

	public function test_tools_list_filter_leaves_unknown_tools_untouched(): void {
		$this->acting_as( 'subscriber' );
		$tools = array( $this->tool_dto( 'some-other-plugin-tool' ) );
		$names = $this->tool_names( aafm_filter_mcp_tools_list( $tools ) );
		$this->assertContains( 'some-other-plugin-tool', $names );
	}

	/**
	 * Minimal Tool DTO stub exposing getName(), matching the adapter's DTO contract.
	 *
	 * @param string $name Sanitized MCP tool name.
	 * @return object
	 */
	private function tool_dto( string $name ): object {
		return new class( $name ) {
			/**
			 * Stub Tool DTO.
			 *
			 * @param string $name Tool name.
			 */
			public function __construct( private string $name ) {}

			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter DTO accessor.
				return $this->name;
			}
		};
	}

	/**
	 * Pluck getName() from a list of Tool DTO stubs.
	 *
	 * @param mixed $tools Filtered tools.
	 * @return array<int,string>
	 */
	private function tool_names( $tools ): array {
		$names = array();
		foreach ( (array) $tools as $tool ) {
			$names[] = $tool->getName();
		}
		return $names;
	}
}
