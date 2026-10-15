<?php
/**
 * The hard guard: none of this plugin's tools can be listed, described or run on the adapter's
 * default server, whatever the abilities' metadata says.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class DefaultServerGuardTest extends TestCase {

	/**
	 * The args filter a test added, so tear_down removes only that callback.
	 *
	 * @var callable|null
	 */
	private $stamp_filter = null;

	public function tear_down(): void {
		if ( null !== $this->stamp_filter ) {
			remove_filter( 'wp_register_ability_args', $this->stamp_filter, PHP_INT_MAX );
			$this->stamp_filter = null;
		}
		foreach ( array( 'aafm/guard-fixture', 'aafm-x/ping' ) as $slug ) {
			if ( wp_has_ability( $slug ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	private function server( string $id ): object {
		return new class( $id ) {
			/**
			 * Held value.
			 *
			 * @var string
			 */
			private $id;

			public function __construct( string $id ) {
				$this->id = $id;
			}

			public function get_server_id(): string {
				return $this->id;
			}
		};
	}

	private function tool( string $name ): object {
		return new class( $name ) {
			/**
			 * Held value.
			 *
			 * @var string
			 */
			private $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's tool DTO.
				return $this->name;
			}
		};
	}

	/**
	 * Tool names, in order.
	 *
	 * @param array<int,object> $tools Tools.
	 * @return array<int,string>
	 */
	private function names( array $tools ): array {
		return array_map( static fn( $t ): string => $t->getName(), $tools );
	}

	public function test_tools_list_drops_our_tools_on_the_default_server_only(): void {
		$tools = array(
			$this->tool( 'aafm-get-posts' ),
			$this->tool( 'aafm-bridge-demo-echo' ),
			$this->tool( 'acme-ping' ),
			$this->tool( 'mcp-adapter-discover-abilities' ),
		);

		$on_default = aafm_guard_default_server_tools_list( $tools, $this->server( 'mcp-adapter-default-server' ) );
		$this->assertSame( array( 'acme-ping', 'mcp-adapter-discover-abilities' ), $this->names( $on_default ) );

		$this->assertSame( $tools, aafm_guard_default_server_tools_list( $tools, $this->server( 'aafm-server' ) ) );
		$this->assertSame( $tools, aafm_guard_default_server_tools_list( $tools, null ) );
		$this->assertSame( 'x', aafm_guard_default_server_tools_list( 'x', $this->server( 'mcp-adapter-default-server' ) ) );
	}

	public function test_a_call_naming_our_ability_is_refused_on_the_default_server_and_logged(): void {
		$default = $this->server( 'mcp-adapter-default-server' );

		foreach ( array( 'aafm/get-posts', 'aafm-bridge/demo-echo' ) as $name ) {
			$result = aafm_guard_default_server_tool_call( array( 'ability_name' => $name ), 'mcp-adapter-execute-ability', null, $default );
			$this->assertWPError( $result );
			$this->assertSame( 'aafm_not_on_default_server', $result->get_error_code() );
		}

		$denied = aafm_query_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'denied',
			)
		);
		$this->assertCount( 1, $denied );
	}

	/**
	 * Final review I1: any authenticated caller can trigger the refusal, so its log rows are
	 * bounded per IP while the refusal itself is not.
	 */
	public function test_refusal_rows_stop_at_the_per_ip_cap(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.93';
		$default                = $this->server( 'mcp-adapter-default-server' );

		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 3; $i++ ) {
			$result = aafm_guard_default_server_tool_call( array( 'ability_name' => 'aafm/get-posts' ), 'mcp-adapter-execute-ability', null, $default );
			$this->assertWPError( $result );
		}

		$this->assertCount(
			AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW,
			aafm_query_activity(
				array(
					'ability'  => 'aafm/get-posts',
					'per_page' => 50,
				)
			)
		);
	}

	public function test_other_calls_and_our_own_server_pass_through(): void {
		$default = $this->server( 'mcp-adapter-default-server' );
		$args    = array( 'ability_name' => 'acme/ping' );

		$this->assertSame( $args, aafm_guard_default_server_tool_call( $args, 'mcp-adapter-execute-ability', null, $default ) );
		$ours = array( 'ability_name' => 'aafm/get-posts' );
		$this->assertSame( $ours, aafm_guard_default_server_tool_call( $ours, 'mcp-adapter-execute-ability', null, $this->server( 'aafm-server' ) ) );
		$this->assertSame( array( 'x' => 1 ), aafm_guard_default_server_tool_call( array( 'x' => 1 ), 'mcp-adapter-discover-abilities', null, $default ) );
	}

	public function test_discover_results_lose_our_entries_on_the_default_server_only(): void {
		$result = array(
			'abilities' => array(
				array(
					'name'        => 'aafm/get-posts',
					'label'       => 'a',
					'description' => 'a',
				),
				array(
					'name'        => 'aafm-bridge/demo-echo',
					'label'       => 'b',
					'description' => 'b',
				),
				array(
					'name'        => 'acme/ping',
					'label'       => 'c',
					'description' => 'c',
				),
			),
		);

		$filtered = aafm_guard_default_server_call_result( $result, array(), 'mcp-adapter-discover-abilities', null, $this->server( 'mcp-adapter-default-server' ) );
		$this->assertSame( array( 'acme/ping' ), array_column( $filtered['abilities'], 'name' ) );

		$this->assertSame( $result, aafm_guard_default_server_call_result( $result, array(), 'mcp-adapter-discover-abilities', null, $this->server( 'aafm-server' ) ) );
		$this->assertSame( 'text', aafm_guard_default_server_call_result( 'text', array(), 'x', null, $this->server( 'mcp-adapter-default-server' ) ) );
	}

	public function test_the_three_guards_are_registered_ahead_of_the_existing_filters(): void {
		$this->assertSame( 1, has_filter( 'mcp_adapter_tools_list', 'aafm_guard_default_server_tools_list' ) );
		$this->assertSame( 1, has_filter( 'mcp_adapter_pre_tool_call', 'aafm_guard_default_server_tool_call' ) );
		$this->assertSame( 1, has_filter( 'mcp_adapter_tool_call_result', 'aafm_guard_default_server_call_result' ) );
	}

	/**
	 * The adapter's own discovery, called directly: with the stamp flipped to false our registered
	 * abilities are not listed and not executable through the default server's meta tools.
	 */
	public function test_the_adapters_discovery_and_execute_checks_do_not_see_our_abilities(): void {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		$this->assertNotNull( wp_get_ability( 'aafm/get-posts' ) );

		$listed = \WP\MCP\Abilities\DiscoverAbilitiesAbility::execute( array() );
		$this->assertNotContains( 'aafm/get-posts', array_column( $listed['abilities'], 'name' ) );

		$this->acting_as( 'administrator' );
		$permission = \WP\MCP\Abilities\ExecuteAbilityAbility::check_permission( array( 'ability_name' => 'aafm/get-posts' ) );
		$this->assertWPError( $permission );
		$this->assertSame( 'ability_not_public_mcp', $permission->get_error_code() );
	}

	/**
	 * The guard must hold on its own: force our stamp back to public and the adapter DOES list the
	 * ability (proving the stamp alone is what hides it), then the guard strips it from the result
	 * and refuses the execute call.
	 */
	public function test_the_guard_holds_even_when_a_filter_stamps_our_ability_public(): void {
		$this->stamp_filter = static function ( $args, $name ) {
			if ( 0 === strpos( (string) $name, 'aafm/' ) ) {
				$args['meta']['mcp']['public'] = true;
			}
			return $args;
		};
		add_filter( 'wp_register_ability_args', $this->stamp_filter, PHP_INT_MAX, 2 );
		$this->in_action(
			'wp_abilities_api_init',
			function (): void {
				aafm_register_ability_with_log(
					'aafm/guard-fixture',
					array(
						'label'               => 'Guard fixture',
						'description'         => 'Fixture.',
						'category'            => 'aafm-reads',
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$listed = \WP\MCP\Abilities\DiscoverAbilitiesAbility::execute( array() );
		$this->assertContains( 'aafm/guard-fixture', array_column( $listed['abilities'], 'name' ), 'Precondition: with the stamp forced public the adapter lists it.' );

		$default  = $this->server( 'mcp-adapter-default-server' );
		$filtered = aafm_guard_default_server_call_result( $listed, array(), 'mcp-adapter-discover-abilities', null, $default );
		$this->assertNotContains( 'aafm/guard-fixture', array_column( $filtered['abilities'], 'name' ) );

		$call = aafm_guard_default_server_tool_call( array( 'ability_name' => 'aafm/guard-fixture' ), 'mcp-adapter-execute-ability', null, $default );
		$this->assertWPError( $call );
	}

	/**
	 * M1 (Task 1 review): the governed server is created with the Site Brief text as its
	 * description. Drives aafm_register_mcp_server() with a recording adapter, so reverting
	 * server.php to a literal description makes this fail.
	 */
	public function test_the_governed_server_is_created_with_the_site_brief_description(): void {
		update_option( 'aafm_site_brief', 'Never publish without asking.' );
		$recorder = new class() {
			/**
			 * Held value.
			 *
			 * @var array<int,mixed>
			 */
			public $args = array();

			public function get_server( string $id ) {
				unset( $id );
				return null;
			}

			public function create_server( ...$args ) {
				$this->args = $args;
				return null;
			}
		};

		aafm_register_mcp_server( $recorder );

		$this->assertSame( 'aafm-server', $recorder->args[0] ?? null );
		$this->assertSame( aafm_server_description(), $recorder->args[4] ?? null );
		$this->assertStringContainsString( 'Never publish without asking.', (string) ( $recorder->args[4] ?? '' ) );
	}

	/**
	 * Register a foreign ability whose namespace merely starts with "aafm-".
	 *
	 * @return void
	 */
	private function register_lookalike(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'smokefix' ) ) {
					wp_register_ability_category(
						'smokefix',
						array(
							'label'       => 'Smoke fixture',
							'description' => 'Fixture.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'aafm-x/ping',
					array(
						'label'               => 'Ping',
						'description'         => 'A foreign ability in a namespace that only starts with aafm-.',
						'category'            => 'smokefix',
						'execute_callback'    => static fn() => array( 'pong' => true ),
						'permission_callback' => '__return_true',
						'meta'                => array( 'mcp' => array( 'public' => true ) ),
					)
				);
			}
		);
	}

	public function test_a_foreign_aafm_prefixed_namespace_is_not_refused(): void {
		$this->register_lookalike();
		$default = $this->server( 'mcp-adapter-default-server' );

		$tools = array( $this->tool( 'aafm-x-ping' ), $this->tool( 'aafm-get-posts' ) );
		$this->assertSame( array( 'aafm-x-ping' ), $this->names( aafm_guard_default_server_tools_list( $tools, $default ) ) );

		$direct = aafm_guard_default_server_tool_call( array( 'x' => 1 ), 'aafm-x-ping', null, $default );
		$this->assertSame( array( 'x' => 1 ), $direct );
		$named = array( 'ability_name' => 'aafm-x/ping' );
		$this->assertSame( $named, aafm_guard_default_server_tool_call( $named, 'mcp-adapter-execute-ability', null, $default ) );

		$result = array( 'abilities' => array( array( 'name' => 'aafm-x/ping' ) ) );
		$this->assertSame( $result, aafm_guard_default_server_call_result( $result, array(), 'mcp-adapter-discover-abilities', null, $default ) );

		$refused = aafm_guard_default_server_tool_call( array(), 'aafm-get-posts', null, $default );
		$this->assertWPError( $refused, 'A tool that is ours is still refused.' );
	}

	public function test_the_guards_apply_to_every_server_but_ours(): void {
		$tools  = array( $this->tool( 'aafm-get-posts' ), $this->tool( 'acme-ping' ) );
		$custom = $this->server( 'acme-custom-server' );

		$this->assertSame( array( 'acme-ping' ), $this->names( aafm_guard_default_server_tools_list( $tools, $custom ) ) );
		$this->assertWPError( aafm_guard_default_server_tool_call( array( 'ability_name' => 'aafm/get-posts' ), 'x', null, $custom ) );
		$filtered = aafm_guard_default_server_call_result( array( 'abilities' => array( array( 'name' => 'aafm/get-posts' ) ) ), array(), 'x', null, $custom );
		$this->assertSame( array(), $filtered['abilities'] );
		$this->assertSame( $tools, aafm_guard_default_server_tools_list( $tools, $this->server( 'aafm-server' ) ) );
	}
}
