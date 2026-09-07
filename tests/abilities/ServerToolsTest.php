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

	// =========================================================================
	// aafm_build_server_tools() -- ownership check (Codex round 9 R9-7)
	//
	// A name AAFM enables must never be served from an object AAFM itself never registered.
	// aafm_register_enabled_abilities() (register.php) treats an already-registered name as an
	// idempotent re-fire and skips it - correct for a real re-fire, wrong when a DIFFERENT
	// plugin's ability claimed the name first: wp_get_ability() then resolves to the foreign
	// object, which used to be admitted into the server with none of this plugin's permission,
	// allowlist, rate-limit, or audit chokepoints behind it.
	// =========================================================================

	/**
	 * Direct proof of the ownership check itself: an ability object registered outside this
	 * plugin's chokepoint (aafm_register_ability_with_log()) is excluded from the server tool
	 * set and recorded as omitted, even though it answers under an aafm/ name and resolves as a
	 * real WP_Ability.
	 */
	public function test_a_foreign_ability_object_under_an_aafm_name_is_excluded_and_recorded(): void {
		$this->acting_as( 'administrator' );

		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( ! wp_has_ability( 'aafm/foreign-claim' ) ) {
					// Registered with a bare wp_register_ability() call, exactly like a third-party
					// plugin would - never through aafm_register_ability_with_log(), so this object
					// carries none of this plugin's audit/rate-limit/permission decoration.
					wp_register_ability(
						'aafm/foreign-claim',
						array(
							'label'               => 'Foreign Claim',
							'description'         => 'Registered directly, not through this plugin\'s chokepoint.',
							'category'            => 'aafm-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array( 'foreign' => true ),
							'permission_callback' => '__return_true',
						)
					);
				}
			}
		);

		$omitted = array();
		$tools   = aafm_build_server_tools( array( 'aafm/pub-read', 'aafm/foreign-claim' ), $omitted );

		$this->assertContains( 'aafm/pub-read', $tools, 'A genuinely AAFM-registered ability must still be served.' );
		$this->assertNotContains( 'aafm/foreign-claim', $tools, 'An ability object this plugin never registered must never be served under an aafm/ name.' );
		$this->assertSame(
			'name_claimed',
			$omitted['aafm/foreign-claim'] ?? null,
			'The exclusion must be recorded so the operator can be told, not dropped silently.'
		);
	}

	/**
	 * The end-to-end shape of the real defect: a foreign plugin claims a reserved, enabled name
	 * before this plugin's own registration pass runs, aafm_register_enabled_abilities() sees the
	 * name already answered and skips re-registering it (the idempotent-reentry guard doing
	 * exactly what it is for), and the object left standing under the name is the foreign one -
	 * yet the server tool set must still exclude it.
	 */
	public function test_a_name_preclaimed_before_registration_is_kept_out_of_the_server(): void {
		$this->acting_as( 'administrator' );
		$name = 'aafm/name-collision-probe';

		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ) use ( $name ): array {
				$registry[ $name ] = array(
					'label'        => 'Name Collision Probe',
					'description'  => 'Registry fixture for the R9-7 regression.',
					'group'        => 'reads',
					'risk'         => 'read',
					'args_builder' => static function () use ( $name ): array {
						return array(
							'label'               => 'Name Collision Probe',
							'description'         => 'Fixture ability this plugin would register if the name were free.',
							'category'            => 'aafm-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array( 'native' => true ),
							'permission_callback' => '__return_true',
						);
					},
				);
				return $registry;
			}
		);

		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				if ( ! wp_has_ability( $name ) ) {
					wp_register_ability(
						$name,
						array(
							'label'               => 'Foreign Claimant',
							'description'         => 'A different plugin registered under this reserved name first.',
							'category'            => 'aafm-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array( 'foreign' => true ),
							'permission_callback' => '__return_true',
						)
					);
				}
			}
		);

		// This plugin's own real registration pass now runs and, per
		// aafm_register_enabled_abilities()'s idempotent-reentry guard, finds the name already
		// answered and skips it - the exact mechanism the real bug exploits.
		$this->register_enabled( array( $name ) );

		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->assertNotInstanceOf(
			\AAFM_Rate_Limited_Ability::class,
			$ability,
			'The foreign registration must have won the name - this plugin never re-registered over it.'
		);
		$this->assertSame(
			array( 'foreign' => true ),
			$ability->execute( array() ),
			'The live object under this name must be the foreign one, proving the collision actually happened.'
		);

		$omitted = array();
		$tools   = aafm_build_server_tools( array( $name ), $omitted );

		$this->assertSame(
			array(),
			$tools,
			'A name this plugin never actually registered must never reach the server tool set, however it came to be enabled.'
		);
		$this->assertSame( 'name_claimed', $omitted[ $name ] ?? null );
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
			 * Tool name.
			 *
			 * @var string
			 */
			private string $name;

			/**
			 * Stub Tool DTO.
			 *
			 * @param string $name Tool name.
			 */
			public function __construct( string $name ) {
				$this->name = $name;
			}

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
