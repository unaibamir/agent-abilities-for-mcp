<?php
/**
 * Server $tools builder: only enabled AND currently-callable abilities are listed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Fixtures\HostileAbility;
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

	// =========================================================================
	// aafm_build_server_tools() -- ownership check hardened to object identity (Codex round 10
	// R10-4)
	//
	// R9-7's fix checked `instanceof AAFM_Rate_Limited_Ability`, which is forgeable: that class
	// is public and non-final, and wp_register_ability() accepts a caller-chosen ability_class,
	// so a foreign plugin can preclaim a name using AAFM's own subclass with its own permissive
	// callbacks and pass a class check that never proves the object came from this plugin's
	// chokepoint. These fixtures register the foreign object with
	// 'ability_class' => \AAFM_Rate_Limited_Ability::class explicitly - the exact hole a plain
	// WP_Ability fixture (the R9-7 tests above) cannot see.
	// =========================================================================

	/**
	 * Direct proof: a foreign object registered outside aafm_register_ability_with_log(), but
	 * using AAFM_Rate_Limited_Ability as its own ability_class, is still excluded and recorded.
	 * A class-only ownership check would pass this object; only object identity catches it.
	 */
	public function test_a_foreign_ability_using_our_own_subclass_is_excluded_and_recorded(): void {
		$this->acting_as( 'administrator' );

		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( ! wp_has_ability( 'aafm/foreign-subclass-claim' ) ) {
					wp_register_ability(
						'aafm/foreign-subclass-claim',
						array(
							'label'               => 'Foreign Subclass Claim',
							'description'         => 'Registered directly, naming AAFM_Rate_Limited_Ability as its own ability_class.',
							'category'            => 'aafm-reads',
							'ability_class'       => \AAFM_Rate_Limited_Ability::class,
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

		$ability = wp_get_ability( 'aafm/foreign-subclass-claim' );
		$this->assertInstanceOf(
			\AAFM_Rate_Limited_Ability::class,
			$ability,
			'The fixture must actually use our subclass, or this test proves nothing about the identity check.'
		);

		$omitted = array();
		$tools   = aafm_build_server_tools( array( 'aafm/pub-read', 'aafm/foreign-subclass-claim' ), $omitted );

		$this->assertContains( 'aafm/pub-read', $tools, 'A genuinely AAFM-registered ability must still be served.' );
		$this->assertNotContains(
			'aafm/foreign-subclass-claim',
			$tools,
			'An object of our own subclass that this plugin never registered must still never be served.'
		);
		$this->assertSame(
			'name_claimed',
			$omitted['aafm/foreign-subclass-claim'] ?? null,
			'The exclusion must be recorded so the operator can be told, not dropped silently.'
		);
	}

	/**
	 * End-to-end shape of R10-4: a foreign plugin claims a reserved, enabled name before this
	 * plugin's own registration pass runs, using AAFM's own subclass as its ability_class.
	 * aafm_register_enabled_abilities() sees the name already answered and skips re-registering
	 * it, so the object left standing under the name is the foreign one, an instance of the right
	 * class but the wrong object - yet the server tool set must still exclude it, and the foreign
	 * callbacks must never run through this plugin's chokepoint.
	 */
	public function test_a_name_preclaimed_with_our_subclass_before_registration_is_kept_out_of_the_server(): void {
		$this->acting_as( 'administrator' );
		$name = 'aafm/subclass-collision-probe';

		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ) use ( $name ): array {
				$registry[ $name ] = array(
					'label'        => 'Subclass Collision Probe',
					'description'  => 'Registry fixture for the R10-4 regression.',
					'group'        => 'reads',
					'risk'         => 'read',
					'args_builder' => static function () use ( $name ): array {
						return array(
							'label'               => 'Subclass Collision Probe',
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
							'description'         => 'A different plugin registered under this reserved name first, using our own subclass.',
							'category'            => 'aafm-reads',
							'ability_class'       => \AAFM_Rate_Limited_Ability::class,
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
		// answered and skips it - so aafm_register_ability_with_log() never runs for this name and
		// never records its object under aafm_remember_registered_ability().
		$this->register_enabled( array( $name ) );

		$ability = wp_get_ability( $name );
		$this->assertInstanceOf(
			\AAFM_Rate_Limited_Ability::class,
			$ability,
			'The foreign registration must have won the name using our own subclass - a class check alone would admit this object.'
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
			'A name this plugin never actually registered must never reach the server tool set, even when the winning object shares our own ability class.'
		);
		$this->assertSame( 'name_claimed', $omitted[ $name ] ?? null );
	}

	// =========================================================================
	// aafm_build_server_tools() -- the ownership record itself cannot be poisoned (Codex round 11
	// R11-3)
	//
	// R10-4 closed the class-forgery route by comparing object identity against whatever
	// aafm_register_ability_with_log() actually returned. But the record it compared against used
	// to be writable through a public, two-argument aafm_remember_registered_ability( $name,
	// $ability ) that trusted whatever object it was handed - so a foreign plugin could register a
	// name directly with wp_register_ability() (bypassing every AAFM decorator) and then call that
	// setter itself, making the record - and the identity check it feeds - believe its own
	// undecorated object was ours. The fixture below calls the function with that old two-argument
	// shape via call_user_func_array() (a direct call would now fail static analysis, since the
	// write parameter no longer exists in the signature - but nothing stops an attacker's own
	// compiled code from calling it that way at runtime, which is exactly the shape this proves is
	// now inert).
	// =========================================================================

	/**
	 * The literal R11-3 attack: register a name directly with core, then try to make the record
	 * believe that object is ours by calling the old two-argument write shape. Must be a no-op.
	 */
	public function test_poisoning_the_ownership_record_directly_no_longer_admits_a_foreign_ability(): void {
		$this->acting_as( 'administrator' );
		$name = 'aafm/poisoned-record-probe';

		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				if ( ! wp_has_ability( $name ) ) {
					wp_register_ability(
						$name,
						array(
							'label'               => 'Poisoned Record Probe',
							'description'         => 'Registered directly with core, with no AAFM decorators at all.',
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

		$foreign = wp_get_ability( $name );
		$this->assertInstanceOf(
			\WP_Ability::class,
			$foreign,
			'Fixture setup: the foreign registration must have actually landed.'
		);

		// The attack: try to make the record believe THIS foreign object is the one AAFM's own
		// registration produced, using the exact old write shape.
		call_user_func_array( 'aafm_remember_registered_ability', array( $name, $foreign ) );

		$this->assertNull(
			aafm_remember_registered_ability( $name ),
			'A name this plugin never registered itself must never be recorded as owned, no matter how the write is attempted.'
		);

		$omitted = array();
		$tools   = aafm_build_server_tools( array( $name ), $omitted );

		$this->assertSame(
			array(),
			$tools,
			'The poisoning attempt must not get the foreign, undecorated ability served through this plugin.'
		);
		$this->assertSame( 'name_claimed', $omitted[ $name ] ?? null );
	}

	// =========================================================================
	// AAFM_Registration_Authority::register() -- a caller-supplied ability_class is never honored
	// (Codex round 12 R12-1)
	//
	// aafm_register_ability_with_log() decorates whatever $args it is given before calling
	// register() - but it used to also honor whatever `ability_class` those $args carried. A
	// caller invoking either function directly with a hostile WP_Ability subclass of its own (one
	// that overrides execute() to discard the decoration) would still have that class registered
	// and recorded as this plugin's own. register() now forces its own trusted class on every call
	// it makes, discarding whatever $args passed, for both routes into it.
	// =========================================================================

	/**
	 * The chokepoint route: a hostile ability_class passed through aafm_register_ability_with_log()
	 * must not survive - the trusted class always wins.
	 */
	public function test_a_caller_supplied_ability_class_is_never_registered_or_recorded(): void {
		$this->acting_as( 'administrator' );
		$name = 'aafm/hostile-class-probe';

		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				if ( ! wp_has_ability( $name ) ) {
					aafm_register_ability_with_log(
						$name,
						array(
							'label'               => 'Hostile Class Probe',
							'description'         => 'Registered through the audited chokepoint with a hostile ability_class.',
							'category'            => 'aafm-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array( 'trusted' => true ),
							'permission_callback' => '__return_true',
							'ability_class'       => HostileAbility::class,
						)
					);
				}
			}
		);

		$ability = wp_get_ability( $name );
		$this->assertInstanceOf(
			\AAFM_Rate_Limited_Ability::class,
			$ability,
			'A caller-supplied ability_class must be discarded in favor of this plugin\'s own trusted class.'
		);
		$this->assertNotInstanceOf( HostileAbility::class, $ability );
		$this->assertSame(
			array( 'trusted' => true ),
			$ability->execute( array() ),
			'The registered object must run this plugin\'s own decorated execute path, not the hostile subclass.'
		);
		$this->assertSame(
			$ability,
			aafm_remember_registered_ability( $name ),
			'The recorded object must be the same forced-class instance the registration produced.'
		);
	}

	/**
	 * The direct route: even skipping aafm_register_ability_with_log() entirely and calling the
	 * authority itself, a hostile ability_class still does not survive. This is a narrower defense
	 * than the chokepoint gives - the permission_callback and execute_callback here are still
	 * undecorated, a known and documented limit of calling the authority directly - but the class
	 * substitution specifically is closed on both routes.
	 */
	public function test_authority_register_called_directly_still_forces_the_trusted_class(): void {
		$this->acting_as( 'administrator' );
		$name = 'aafm/hostile-class-direct-probe';

		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				if ( ! wp_has_ability( $name ) ) {
					\AAFM_Registration_Authority::register(
						$name,
						array(
							'label'               => 'Hostile Class Direct Probe',
							'description'         => 'Registered by calling the authority directly, bypassing the decorators entirely.',
							'category'            => 'aafm-reads',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'output_schema'       => array( 'type' => 'object' ),
							'execute_callback'    => static fn() => array( 'undecorated' => true ),
							'permission_callback' => '__return_true',
							'ability_class'       => HostileAbility::class,
						)
					);
				}
			}
		);

		$ability = wp_get_ability( $name );
		$this->assertInstanceOf(
			\AAFM_Rate_Limited_Ability::class,
			$ability,
			'Even a direct call to the authority must not honor a caller-supplied ability_class.'
		);
		$this->assertNotInstanceOf( HostileAbility::class, $ability );
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
