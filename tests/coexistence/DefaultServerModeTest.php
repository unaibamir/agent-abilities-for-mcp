<?php
/**
 * The default-server switch: mode reading, what counts as another plugin publishing tools there,
 * and the create-or-not decision.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class DefaultServerModeTest extends TestCase {

	/**
	 * The callback a test put on the adapter's config filter, so tear_down removes only that.
	 *
	 * @var callable|null
	 */
	private $config_probe = null;

	/**
	 * The wp_abilities_api_init count before a test forced "registries finished", or null.
	 *
	 * @var int|null
	 */
	private $saved_init_count = null;

	/**
	 * Make the Abilities API registries read as finished initialising. The suite builds registered
	 * abilities by hand and never fires the real actions, so the count is set directly.
	 */
	private function registries_finished(): void {
		global $wp_actions;
		if ( null === $this->saved_init_count ) {
			$this->saved_init_count = (int) ( $wp_actions['wp_abilities_api_init'] ?? 0 );
		}
		$wp_actions['wp_abilities_api_init'] = max( 1, $this->saved_init_count ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
	}

	public function tear_down(): void {
		global $wp_actions;
		if ( null !== $this->saved_init_count ) {
			if ( 0 === $this->saved_init_count ) {
				unset( $wp_actions['wp_abilities_api_init'] );
			} else {
				$wp_actions['wp_abilities_api_init'] = $this->saved_init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
			}
			$this->saved_init_count = null;
		}
		delete_option( 'aafm_default_server_mode' );
		delete_option( 'aafm_default_server_auto_noted' );
		remove_action( 'mcp_adapter_init', array( 'WP\\MCP\\Servers\\DefaultServerFactory', 'create' ) );
		if ( null !== $this->config_probe ) {
			remove_filter( 'mcp_adapter_default_server_config', $this->config_probe );
			$this->config_probe = null;
		}
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			if ( 0 === strncmp( (string) $slug, 'smokefix/', 9 ) ) {
				wp_unregister_ability( (string) $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Build an ability object without registering it.
	 *
	 * @param string              $name Ability name.
	 * @param array<string,mixed> $meta Ability meta.
	 * @return \WP_Ability
	 */
	private function ability( string $name, array $meta ): \WP_Ability {
		return new \WP_Ability(
			$name,
			array(
				'label'               => 'x',
				'description'         => 'x',
				'category'            => 'site',
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);
	}

	public function test_mode_defaults_to_auto_and_ignores_garbage(): void {
		$this->assertSame( 'auto', aafm_default_server_mode() );
		update_option( 'aafm_default_server_mode', 'maybe' );
		$this->assertSame( 'auto', aafm_default_server_mode() );
		update_option( 'aafm_default_server_mode', array( 'on' ) );
		$this->assertSame( 'auto', aafm_default_server_mode() );
	}

	public function test_mode_reads_each_stored_value(): void {
		foreach ( array( 'auto', 'on', 'off' ) as $mode ) {
			update_option( 'aafm_default_server_mode', $mode );
			$this->assertSame( $mode, aafm_default_server_mode() );
		}
	}

	public function test_core_adapter_and_own_abilities_are_not_publishers(): void {
		$abilities = array(
			$this->ability( 'core/get-site-info', array( 'public' => true ) ),
			$this->ability( 'mcp-adapter/discover-abilities', array( 'mcp' => array( 'public' => true ) ) ),
			$this->ability( 'aafm/get-posts', array( 'mcp' => array( 'public' => true ) ) ),
			$this->ability( 'aafm-bridge/demo-echo', array( 'mcp' => array( 'public' => true ) ) ),
		);

		$this->assertSame( array(), aafm_default_server_publishers( $abilities ) );
	}

	public function test_a_foreign_ability_publishes_only_when_its_effective_exposure_is_public(): void {
		$abilities = array(
			$this->ability( 'acme/explicit', array( 'mcp' => array( 'public' => true ) ) ),
			$this->ability( 'acme/inherits', array( 'public' => true ) ),
			$this->ability( 'acme/private', array( 'mcp' => array( 'public' => false ) ) ),
			$this->ability(
				'acme/override',
				array(
					'public' => true,
					'mcp'    => array( 'public' => false ),
				)
			),
			$this->ability( 'acme/malformed', array( 'mcp' => 'yes' ) ),
			$this->ability( 'acme/none', array() ),
		);

		$this->assertSame( array( 'acme/explicit', 'acme/inherits' ), aafm_default_server_publishers( $abilities ) );
	}

	/**
	 * Final review I2: an older adapter copy from another plugin has no exposure resolver. The
	 * Settings state line and the Automatic decision must answer "nothing publishes", not fatal.
	 */
	public function test_an_adapter_without_the_exposure_resolver_publishes_nothing_and_does_not_fatal(): void {
		add_filter( 'aafm_adapter_exposure_resolver_available', '__return_false' );
		$abilities = array( $this->ability( 'acme/explicit', array( 'mcp' => array( 'public' => true ) ) ) );

		$this->assertSame( array(), aafm_default_server_publishers( $abilities ) );

		update_option( 'aafm_default_server_mode', 'auto' );
		$this->assertSame( 'Currently not running', aafm_default_server_state_label() );
	}

	public function test_a_callback_on_the_default_server_config_filter_counts_as_a_publisher(): void {
		$this->config_probe = static fn( $config ) => $config;
		add_filter( 'mcp_adapter_default_server_config', $this->config_probe ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$this->assertTrue( aafm_default_server_has_publisher() );
	}

	/**
	 * Mode, filter value so far, publisher present, expected decision.
	 *
	 * @return array<string,array{0:string,1:bool,2:bool,3:bool}>
	 */
	public function decision_table(): array {
		return array(
			'off beats everything'           => array( 'off', true, true, false ),
			'on beats an earlier false'      => array( 'on', false, false, true ),
			'on with nothing else'           => array( 'on', true, false, true ),
			'auto with a publisher'          => array( 'auto', true, true, true ),
			'auto without a publisher'       => array( 'auto', true, false, false ),
			'auto respects an earlier false' => array( 'auto', false, true, false ),
		);
	}

	/**
	 * The create-or-not decision for each row of the table.
	 *
	 * @dataProvider decision_table
	 * @param string $mode          Mode.
	 * @param bool   $create        Filter value so far.
	 * @param bool   $has_publisher Whether another plugin publishes there.
	 * @param bool   $expected      Expected decision.
	 */
	public function test_decision( string $mode, bool $create, bool $has_publisher, bool $expected ): void {
		$this->assertSame( $expected, aafm_default_server_decision( $mode, $create, static fn(): bool => $has_publisher ) );
	}

	public function test_auto_does_not_look_for_publishers_when_it_cannot_matter(): void {
		$asked = false;
		$probe = static function () use ( &$asked ): bool {
			$asked = true;
			return true;
		};
		aafm_default_server_decision( 'off', true, $probe );
		aafm_default_server_decision( 'on', true, $probe );
		aafm_default_server_decision( 'auto', false, $probe );
		$this->assertFalse( $asked );
	}

	public function test_the_filter_is_wired_to_the_adapter_hook_and_follows_the_mode(): void {
		$this->assertNotFalse( has_filter( 'mcp_adapter_create_default_server', 'aafm_filter_default_server_enabled' ) );
		$this->assertFalse( has_filter( 'mcp_adapter_create_default_server', '__return_false' ), 'The unconditional off switch must be gone.' );

		update_option( 'aafm_default_server_mode', 'off' );
		$this->assertFalse( apply_filters( 'mcp_adapter_create_default_server', true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		update_option( 'aafm_default_server_mode', 'on' );
		$this->assertTrue( apply_filters( 'mcp_adapter_create_default_server', true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
	}

	public function test_a_registered_foreign_public_ability_turns_auto_on(): void {
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
					'smokefix/ping',
					array(
						'label'               => 'Ping',
						'description'         => 'Foreign plugin ability that publishes itself to the default server.',
						'category'            => 'smokefix',
						'execute_callback'    => static fn() => array( 'pong' => true ),
						'permission_callback' => '__return_true',
						'meta'                => array( 'mcp' => array( 'public' => true ) ),
					)
				);
			}
		);

		$this->assertContains( 'smokefix/ping', aafm_default_server_publishers( wp_get_abilities() ) );
		$this->assertTrue( aafm_default_server_has_publisher() );
		$this->registries_finished();
		$this->assertTrue( aafm_filter_default_server_enabled( true ) );
	}

	/**
	 * The adapter asks while it registers its category and abilities. Looking up abilities then would
	 * start the registries out of order, so Automatic answers with the value so far and does not
	 * consult publishers; outside registration the same call decides (no publisher here, so false).
	 */
	public function test_auto_answers_with_the_value_so_far_while_the_registries_register(): void {
		delete_option( 'aafm_default_server_mode' );
		$answers = array();
		foreach ( array( 'wp_abilities_api_categories_init', 'wp_abilities_api_init' ) as $action ) {
			$this->in_action(
				$action,
				static function () use ( &$answers, $action ): void {
					$answers[ $action ] = array( aafm_filter_default_server_enabled( true ), aafm_filter_default_server_enabled( false ) );
				}
			);
		}

		$this->assertSame( array( true, false ), $answers['wp_abilities_api_categories_init'] );
		$this->assertSame( array( true, false ), $answers['wp_abilities_api_init'] );
		$this->assertTrue( aafm_filter_default_server_enabled( true ), 'Before the registries have finished Automatic keeps the value so far.' );

		$this->registries_finished();
		$this->assertFalse( aafm_filter_default_server_enabled( true ), 'Once they have finished Automatic decides, and nobody publishes here.' );
	}

	/**
	 * Automatic said maybe while registering, so the adapter's category and meta abilities exist; a
	 * final no removes them, leaving what Always off leaves.
	 */
	public function test_a_final_no_in_auto_removes_the_adapters_meta_abilities(): void {
		delete_option( 'aafm_default_server_mode' );
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'mcp-adapter' ) ) {
					wp_register_ability_category(
						'mcp-adapter',
						array(
							'label'       => 'MCP Adapter',
							'description' => 'Fixture.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( wp_has_ability( 'mcp-adapter/discover-abilities' ) ) {
					return;
				}
				wp_register_ability(
					'mcp-adapter/discover-abilities',
					array(
						'label'               => 'Discover',
						'description'         => 'Fixture for the adapter meta ability.',
						'category'            => 'mcp-adapter',
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		$this->assertTrue( wp_has_ability( 'mcp-adapter/discover-abilities' ) );

		$this->registries_finished();
		$this->assertFalse( aafm_filter_default_server_enabled( true ) );

		$this->assertFalse( wp_has_ability( 'mcp-adapter/discover-abilities' ) );
		$this->assertFalse( wp_has_ability_category( 'mcp-adapter' ) );
	}

	/**
	 * The adapter's one create callback, as the adapter adds it before it fires mcp_adapter_init.
	 *
	 * @return array{0:string,1:string}
	 */
	private function adapter_create_callback(): array {
		return array( 'WP\\MCP\\Servers\\DefaultServerFactory', 'create' );
	}

	public function test_the_veto_takes_the_adapters_create_callback_off_when_nobody_publishes(): void {
		delete_option( 'aafm_default_server_mode' );
		$this->assertNotFalse( has_action( 'mcp_adapter_init', 'aafm_veto_default_server_without_publisher' ) );
		add_action( 'mcp_adapter_init', $this->adapter_create_callback() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.

		aafm_veto_default_server_without_publisher();

		$this->assertFalse( has_action( 'mcp_adapter_init', $this->adapter_create_callback() ) );
	}

	public function test_the_veto_leaves_the_create_callback_when_another_plugin_publishes(): void {
		delete_option( 'aafm_default_server_mode' );
		$this->config_probe = static fn( $config ) => $config;
		add_filter( 'mcp_adapter_default_server_config', $this->config_probe ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		add_action( 'mcp_adapter_init', $this->adapter_create_callback() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.

		aafm_veto_default_server_without_publisher();

		$this->assertNotFalse( has_action( 'mcp_adapter_init', $this->adapter_create_callback() ) );
		remove_action( 'mcp_adapter_init', $this->adapter_create_callback() );
	}

	public function test_the_veto_only_acts_in_auto_mode(): void {
		update_option( 'aafm_default_server_mode', 'on' );
		add_action( 'mcp_adapter_init', $this->adapter_create_callback() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.

		aafm_veto_default_server_without_publisher();

		$this->assertNotFalse( has_action( 'mcp_adapter_init', $this->adapter_create_callback() ) );
		remove_action( 'mcp_adapter_init', $this->adapter_create_callback() );
	}

	public function test_the_mode_option_is_in_the_reset_list(): void {
		$this->assertContains( 'aafm_default_server_mode', aafm_config_option_names() );
	}

	/**
	 * Off mode must resolve the chain to false whatever the incoming value; this is the order-
	 * independent half of "no default server exists": McpAdapter::init() runs once per process, so
	 * the registered-server state cannot be asserted reliably from here.
	 */
	public function test_off_mode_wins_over_an_earlier_true_through_the_real_filter_chain(): void {
		update_option( 'aafm_default_server_mode', 'off' );
		$this->assertFalse( (bool) apply_filters( 'mcp_adapter_create_default_server', true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
	}

	public function test_a_later_filter_cannot_override_always_off_or_always_on(): void {
		$this->assertGreaterThan( 100, has_filter( 'mcp_adapter_create_default_server', 'aafm_filter_default_server_enabled' ), 'The mode runs late.' );

		update_option( 'aafm_default_server_mode', 'off' );
		add_filter( 'mcp_adapter_create_default_server', '__return_true', 100 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$off = apply_filters( 'mcp_adapter_create_default_server', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		remove_filter( 'mcp_adapter_create_default_server', '__return_true', 100 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$this->assertFalse( $off );

		update_option( 'aafm_default_server_mode', 'on' );
		add_filter( 'mcp_adapter_create_default_server', '__return_false', 100 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$on = apply_filters( 'mcp_adapter_create_default_server', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		remove_filter( 'mcp_adapter_create_default_server', '__return_false', 100 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$this->assertTrue( $on );
	}

	/**
	 * The guards must be registered before aafm_init_mcp() can return early on a missing or
	 * incompatible adapter, because they need no adapter classes.
	 */
	public function test_the_guards_are_registered_before_the_adapter_compatibility_returns(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a test.
		$start  = (int) strpos( $source, 'function aafm_init_mcp(): bool {' );
		$guards = (int) strpos( $source, 'aafm_register_default_server_guards();', $start );
		$early  = (int) strpos( $source, 'return false;', $start );

		$this->assertGreaterThan( $start, $guards );
		$this->assertLessThan( $early, $guards, 'The guards are registered after an early return.' );
	}

	public function test_the_state_line_follows_the_mode(): void {
		update_option( 'aafm_default_server_mode', 'off' );
		$this->assertFalse( aafm_default_server_running() );
		$this->assertSame( 'Currently not running', aafm_default_server_state_label() );

		update_option( 'aafm_default_server_mode', 'on' );
		$this->assertTrue( aafm_default_server_running() );
		$this->assertSame( 'Currently running', aafm_default_server_state_label() );

		update_option( 'aafm_default_server_mode', 'auto' );
		$this->config_probe = static fn( $config ) => $config;
		add_filter( 'mcp_adapter_default_server_config', $this->config_probe ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$this->assertSame( 'Currently running', aafm_default_server_state_label() );
	}

	/**
	 * A recording adapter that says whether the default server exists.
	 *
	 * @param bool $has_default Whether get_server() finds the default server.
	 * @return object
	 */
	private function adapter( bool $has_default ): object {
		return new class( $has_default ) {
			/**
			 * Whether the default server exists.
			 *
			 * @var bool
			 */
			private $has_default;

			public function __construct( bool $has_default ) {
				$this->has_default = $has_default;
			}

			public function get_server( string $id ) {
				return ( $this->has_default && 'mcp-adapter-default-server' === $id ) ? new \stdClass() : null;
			}
		};
	}

	public function test_automatic_start_is_logged_once_and_only_when_the_server_exists(): void {
		$this->config_probe = static fn( $config ) => $config;
		add_filter( 'mcp_adapter_default_server_config', $this->config_probe ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
		$query              = array( 'ability' => 'aafm/default-server-mode' );

		aafm_note_automatic_default_server( $this->adapter( false ) );
		$this->assertSame( array(), aafm_query_activity( $query ), 'No server, no row.' );

		aafm_note_automatic_default_server( $this->adapter( true ) );
		aafm_note_automatic_default_server( $this->adapter( true ) );
		$rows = aafm_query_activity( $query );
		$this->assertCount( 1, $rows, 'Logged once, not per request.' );
		$this->assertStringContainsString( 'automatically', (string) $rows[0]['detail'] );
	}

	public function test_explicit_modes_do_not_log_an_automatic_start(): void {
		foreach ( array( 'on', 'off' ) as $mode ) {
			update_option( 'aafm_default_server_mode', $mode );
			aafm_note_automatic_default_server( $this->adapter( true ) );
		}

		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'aafm/default-server-mode' ) ) );
		$this->assertFalse( get_option( 'aafm_default_server_auto_noted' ) );
	}

	public function test_the_automatic_start_marker_is_in_the_reset_list(): void {
		$this->assertContains( 'aafm_default_server_auto_noted', aafm_config_option_names() );
	}
}
