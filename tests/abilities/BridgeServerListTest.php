<?php
/**
 * Bridged wrappers join the combined native + bridged server ability source.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BridgeServerListTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	}

	public function tear_down(): void {
		delete_option( 'aafm_enabled_bridged_abilities' );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) || 0 === strncmp( $slug, 'aafm-bridge/', 12 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	public function test_enabled_bridged_wrapper_in_server_tools(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'demo/echo',
					array(
						'label'               => 'Echo',
						'description'         => 'e',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );

		$names = aafm_all_server_ability_names();
		$this->assertContains( 'aafm-bridge/demo-echo', $names );
	}

	public function test_native_only_when_no_bridged(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_enabled_bridged_abilities', array() );
		$names = aafm_all_server_ability_names();
		$this->assertContains( 'aafm/get-posts', $names );
		$this->assertNotContains( 'aafm-bridge/demo-echo', $names );
	}

	/**
	 * Register a foreign ability whose permission gates on manage_options, then bridge it.
	 *
	 * @return void
	 */
	private function bridge_capgated_foreign(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'demo/echo',
					array(
						'label'               => 'Echo',
						'description'         => 'e',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
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

	public function test_bridged_wrapper_present_for_capable_connection(): void {
		$this->bridge_capgated_foreign();
		$this->acting_as( 'administrator' );

		$tools   = array( $this->tool_dto( aafm_mcp_tool_name( 'aafm-bridge/demo-echo' ) ) );
		$visible = aafm_filter_mcp_tools_list( $tools );

		$this->assertCount( 1, (array) $visible, 'A capable connection must see the bridged wrapper.' );
	}

	public function test_bridged_wrapper_absent_for_incapable_connection(): void {
		$this->bridge_capgated_foreign();
		$this->acting_as( 'subscriber' );

		$tools   = array( $this->tool_dto( aafm_mcp_tool_name( 'aafm-bridge/demo-echo' ) ) );
		$visible = aafm_filter_mcp_tools_list( $tools );

		$this->assertCount( 0, (array) $visible, 'An incapable connection must NOT see the bridged wrapper.' );
	}

	public function test_disabled_bridge_not_in_server_names(): void {
		$this->bridge_capgated_foreign();
		update_option( 'aafm_enabled_bridged_abilities', array() );

		$this->assertNotContains( 'aafm-bridge/demo-echo', aafm_all_server_ability_names() );
	}

	private const OBJECT_ID_SLUGS = array(
		'demo/shape-false',
		'demo/shape-error',
		'demo/shape-zero',
		'demo/shape-exists',
	);

	/**
	 * Four real shapes of a per-object permission callback that answers "no" to an empty probe:
	 * false on a missing id (Meta Box, Premium Addons), a WP_Error on a missing id (SEOPress,
	 * WPForms), edit_post against id 0 (SiteOrigin), and a post-exists check first.
	 *
	 * @param array<string,callable> $extra Extra slug => permission callback fixtures.
	 * @return void
	 */
	private function register_object_id_foreigners( array $extra = array() ): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$shapes = array(
			'demo/shape-false'  => static fn( $input = null ): bool => ! empty( $input['post_id'] ) && current_user_can( 'edit_post', (int) $input['post_id'] ),
			'demo/shape-error'  => static function ( $input = null ) {
				if ( empty( $input['post_id'] ) ) {
					return new \WP_Error( 'demo_invalid_post_id', 'A post id is required.' );
				}
				return current_user_can( 'edit_post', (int) $input['post_id'] );
			},
			'demo/shape-zero'   => static fn( $input = null ): bool => current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ),
			'demo/shape-exists' => static fn( $input = null ): bool => ! empty( $input['post_id'] ) && get_post( (int) $input['post_id'] ) instanceof \WP_Post && current_user_can( 'edit_post', (int) $input['post_id'] ),
		) + $extra;
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $shapes ): void {
				foreach ( $shapes as $slug => $permission ) {
					wp_register_ability(
						$slug,
						array(
							'label'               => $slug,
							'description'         => 'Per-object permission fixture.',
							'category'            => 'demo-things',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
							),
							'execute_callback'    => static fn() => array(),
							'permission_callback' => $permission,
						)
					);
				}
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array_keys( $shapes ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
	}

	/**
	 * Wrapper names, from the given slugs, that the current user may discover.
	 *
	 * @param array<int,string> $slugs Foreign slugs.
	 * @return array<int,string>
	 */
	private function discoverable( array $slugs ): array {
		$wrappers = array_map( 'aafm_bridge_tool_name', $slugs );
		return array_values(
			array_filter(
				$wrappers,
				static fn( string $wrapper ): bool => aafm_user_can_discover_ability( $wrapper )
			)
		);
	}

	public function test_bridged_tool_needing_an_object_id_is_listed_for_an_editor_and_an_admin(): void {
		$this->register_object_id_foreigners();
		$all = array_map( 'aafm_bridge_tool_name', self::OBJECT_ID_SLUGS );

		foreach ( array( 'editor', 'administrator' ) as $role ) {
			$this->acting_as( $role );
			$this->assertSame( $all, $this->discoverable( self::OBJECT_ID_SLUGS ), "A {$role} must discover every per-object bridged tool." );

			$tools   = array_map( fn( string $wrapper ) => $this->tool_dto( aafm_mcp_tool_name( $wrapper ) ), $all );
			$visible = (array) aafm_filter_mcp_tools_list( $tools );
			$this->assertCount( count( $all ), $visible, "The tools/list filter must keep every per-object tool for a {$role}." );
		}

		$this->acting_as( 'subscriber' );
		$this->assertSame( array(), $this->discoverable( self::OBJECT_ID_SLUGS ), 'A subscriber must not discover any of them.' );
	}

	public function test_a_principal_outside_the_allowlist_never_sees_a_floor_listed_tool(): void {
		$this->register_object_id_foreigners();
		$this->acting_as( 'administrator' );
		$this->assertContains( 'aafm-bridge/demo-shape-false', $this->discoverable( self::OBJECT_ID_SLUGS ), 'Guard on the guard: without a restriction the admin sees the tool.' );

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
		aafm_policy_reset_request_state();

		$this->assertSame( array(), $this->discoverable( self::OBJECT_ID_SLUGS ), 'The allowlist scope check runs before the floor.' );
		delete_option( 'aafm_ability_allowlist_overrides' );
	}

	/**
	 * A pin, green on the base commit by design: tools that already pass with empty input list for
	 * exactly the same roles as before.
	 */
	public function test_bridged_tool_that_passes_with_empty_input_is_listed_exactly_as_before(): void {
		$this->register_object_id_foreigners(
			array(
				'demo/open'     => '__return_true',
				'demo/edit-cap' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
		$pass = array( 'demo/open', 'demo/edit-cap' );

		$this->acting_as( 'administrator' );
		$this->assertSame( array_map( 'aafm_bridge_tool_name', $pass ), $this->discoverable( $pass ) );
		$this->acting_as( 'editor' );
		$this->assertSame( array_map( 'aafm_bridge_tool_name', $pass ), $this->discoverable( $pass ) );
		$this->acting_as( 'subscriber' );
		$this->assertSame( array( 'aafm-bridge/demo-open' ), $this->discoverable( $pass ), 'Only the open tool, as before.' );
	}

	/**
	 * A pin, green on the base commit by design, and version dependent underneath: on WP 7.1 core
	 * turns the throw into a WP_Error, on 6.9 it escapes to aafm_deny_crashed_permission_check().
	 * Both must leave the tool hidden for everyone and the healthy tool listed.
	 */
	public function test_bridged_tool_with_a_throwing_permission_callback_stays_hidden(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_object_id_foreigners(
			array(
				'demo/throws'  => static function () {
					throw new \RuntimeException( 'permission boom' );
				},
				'demo/healthy' => '__return_true',
			)
		);

		foreach ( array( 'editor', 'administrator' ) as $role ) {
			$this->acting_as( $role );
			$this->assertNotContains( 'aafm-bridge/demo-throws', $this->discoverable( array( 'demo/throws', 'demo/healthy' ) ), "A crashing check must hide the tool from a {$role}." );
			$this->assertContains( 'aafm-bridge/demo-healthy', $this->discoverable( array( 'demo/throws', 'demo/healthy' ) ) );

			$tools   = array(
				$this->tool_dto( aafm_mcp_tool_name( 'aafm-bridge/demo-throws' ) ),
				$this->tool_dto( aafm_mcp_tool_name( 'aafm-bridge/demo-healthy' ) ),
			);
			$visible = (array) aafm_filter_mcp_tools_list( $tools );
			$this->assertCount( 1, $visible, 'The listing must survive the crash and still return the healthy tool.' );
		}
	}

	/**
	 * Core's own catch of a throw words its error "Ability "<slug>" callback threw an exception: ...".
	 * A permission callback that returns the same code with its own text is an ordinary "no": it gets the
	 * discovery floor like any other denial instead of the fail-closed treatment a crash gets.
	 */
	public function test_a_permission_callback_returning_the_core_exception_code_with_its_own_text_is_denied_not_crashed(): void {
		$this->register_object_id_foreigners(
			array(
				'demo/says-no' => static fn() => new \WP_Error( 'ability_callback_exception', 'The vendor refuses this one.' ),
			)
		);

		$this->assertSame( 'deny', aafm_bridge_permission_state( 'demo/says-no', array() ) );
		$this->acting_as( 'editor' );
		$this->assertContains( 'aafm-bridge/demo-says-no', $this->discoverable( array( 'demo/says-no' ) ), 'A denial lists under the floor.' );
	}

	/**
	 * A permission callback that throws is a crash on a core that catches it, and an exception on one
	 * that does not. Either way the state is never "deny" or "allow".
	 */
	public function test_a_throwing_permission_callback_is_never_read_as_a_denial(): void {
		$this->register_object_id_foreigners(
			array(
				'demo/throws-again' => static function () {
					throw new \RuntimeException( 'permission boom' );
				},
			)
		);

		try {
			$state = aafm_bridge_permission_state( 'demo/throws-again', array() );
		} catch ( \RuntimeException $e ) {
			$state = 'thrown';
		}
		$this->assertContains( $state, array( 'crash', 'thrown' ) );
	}

	public function test_discovery_floor_filter_is_honoured(): void {
		add_filter(
			'aafm_bridge_discovery_capability',
			static fn(): string => 'manage_options'
		);
		$this->register_object_id_foreigners();

		$this->acting_as( 'editor' );
		$this->assertSame( array(), $this->discoverable( self::OBJECT_ID_SLUGS ), 'An editor lacks the filtered floor.' );
		$this->acting_as( 'administrator' );
		$this->assertCount( count( self::OBJECT_ID_SLUGS ), $this->discoverable( self::OBJECT_ID_SLUGS ), 'An administrator has it.' );
	}

	public function test_a_role_gated_tool_with_no_id_is_listed_to_an_editor_by_the_floor_and_refused_on_call(): void {
		$this->register_object_id_foreigners(
			array(
				'demo/admin-only' => static fn( $input = null ): bool => ! empty( $input['post_id'] ) && current_user_can( 'manage_options' ),
			)
		);
		$wrapper = 'aafm-bridge/demo-admin-only';

		$this->acting_as( 'subscriber' );
		$this->assertNotContains( $wrapper, $this->discoverable( array( 'demo/admin-only' ) ), 'A subscriber is below the floor.' );

		$this->acting_as( 'editor' );
		$this->assertContains( $wrapper, $this->discoverable( array( 'demo/admin-only' ) ), 'The floor cannot tell a missing id from a wrong role, so an editor sees it.' );
		$this->assertNotTrue( wp_get_ability( $wrapper )->check_permissions( array( 'post_id' => 1 ) ), 'But the call is refused.' );

		$denied = aafm_query_activity(
			array(
				'ability' => $wrapper,
				'status'  => 'denied',
			)
		);
		$this->assertNotEmpty( $denied, 'The refusal writes a denied row.' );
	}
}
