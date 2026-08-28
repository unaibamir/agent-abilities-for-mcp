<?php
/**
 * Bridge wrappers: an enabled foreign ability becomes a governed aafm-bridge/* wrapper that
 * delegates permission + execute to the live foreign ability.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BridgeWrapperTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->ensure_categories();
	}

	public function tear_down(): void {
		delete_option( 'aafm_enabled_bridged_abilities' );
		// The abilities registry persists across tests, so drop the demo/vendor fixtures and
		// any wrappers this case registered to keep the next test (and the next data-provider
		// iteration of the SAME test) isolated. Without 'vendor/' here, a scalar-result test
		// re-registering the same foreign slug across data-provider runs trips core's
		// "already registered" incorrect-usage notice and keeps executing the FIRST run's
		// closure instead of the new one.
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) || 0 === strncmp( $slug, 'vendor/', 7 ) || 0 === strncmp( $slug, 'aafm-bridge/', 12 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register the plugin's own categories (aafm-reads / aafm-writes) the wrappers use.
	 *
	 * @return void
	 */
	private function ensure_categories(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	}

	/**
	 * Register a demo category + a foreign ability inside the gated init actions.
	 *
	 * @param bool $allow Whether the foreign ability's permission callback allows.
	 * @return void
	 */
	private function register_foreign( bool $allow = true ): void {
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
			static function () use ( $allow ): void {
				wp_register_ability(
					'demo/echo',
					array(
						'label'               => 'Echo',
						'description'         => 'Echoes value.',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'v' => array( 'type' => 'string' ) ),
						),
						'execute_callback'    => static fn( $i ) => array( 'echoed' => $i['v'] ?? null ),
						'permission_callback' => $allow ? '__return_true' : '__return_false',
					)
				);
			}
		);
	}

	/**
	 * Run the wrapper registration pass inside a simulated abilities-init action.
	 *
	 * @return void
	 */
	private function register_wrappers(): void {
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
	}

	public function test_enabled_foreign_ability_becomes_wrapper_and_executes(): void {
		$this->register_foreign( true );
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->register_wrappers();

		$this->assertTrue( wp_has_ability( 'aafm-bridge/demo-echo' ) );
		$ability = wp_get_ability( 'aafm-bridge/demo-echo' );
		$this->assertTrue( true === $ability->check_permissions( array( 'v' => 'x' ) ) );
		$this->assertSame( 'x', $ability->execute( array( 'v' => 'x' ) )['echoed'] );
	}

	/**
	 * Register a foreign ability with NO input schema (like core/get-user-info).
	 *
	 * @return void
	 */
	private function register_foreign_no_schema(): void {
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
					'demo/noschema',
					array(
						'label'               => 'No schema',
						'description'         => 'Declares no input schema, like core/get-user-info.',
						'category'            => 'demo-things',
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * B1: a bridged foreign ability that declares no input schema must EXECUTE, not fail.
	 *
	 * Core's WP_Ability::validate_input() accepts only null for an empty schema. The wrapper used
	 * to forward array(), so every call returned ability_missing_input_schema. The forwarder now
	 * passes null for an empty foreign schema. Asserted at the returned-shape layer (a WP_Error vs
	 * the real result object), which is what a strict MCP client actually receives.
	 */
	public function test_bridged_no_input_schema_ability_executes(): void {
		$this->register_foreign_no_schema();
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/noschema' ) );
		$this->register_wrappers();

		$ability = wp_get_ability( 'aafm-bridge/demo-noschema' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->assertTrue( true === $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( array( 'ok' => true ), $result );
	}

	/**
	 * B1 second class: a foreign ability with a non-object (scalar) input schema must receive the
	 * caller's scalar argument, not have it discarded and replaced with array().
	 */
	public function test_bridged_scalar_input_schema_forwards_the_argument(): void {
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
					'demo/scalar',
					array(
						'label'               => 'Scalar in',
						'description'         => 'Declares a non-object input schema.',
						'category'            => 'demo-things',
						'input_schema'        => array( 'type' => 'string' ),
						'execute_callback'    => static fn( $i ) => array( 'got' => $i ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/scalar' ) );
		$this->register_wrappers();

		$ability = wp_get_ability( 'aafm-bridge/demo-scalar' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );

		$result = $ability->execute( 'hello' );
		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( array( 'got' => 'hello' ), $result );
	}

	public function test_disabled_foreign_ability_not_registered(): void {
		$this->register_foreign( true );
		update_option( 'aafm_enabled_bridged_abilities', array() );
		$this->register_wrappers();

		$this->assertFalse( wp_has_ability( 'aafm-bridge/demo-echo' ) );
	}

	/**
	 * An enabled bridged slug whose host plugin is currently inactive is an ordinary state, not a
	 * misuse - wp_get_ability() on it must never raise _doing_it_wrong(). The sibling read-only-mode
	 * accessor (aafm_get_enabled_bridged_abilities()) already carries this guard; this pins the
	 * registration walk, which reaches wp_get_ability() on every enabled slug when read-only mode
	 * is off (the read-only accessor's own guard is bypassed entirely in that case).
	 */
	public function test_registration_walk_never_raises_doing_it_wrong_for_an_inactive_host(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/host-plugin-now-inactive' ) );

		// No fixture registers this slug: it is enabled, but the "host plugin" is gone.
		$this->register_wrappers();

		$this->assertFalse(
			wp_has_ability( 'aafm-bridge/demo-host-plugin-now-inactive' ),
			'An inactive host plugin must never end up with a registered wrapper.'
		);
	}

	public function test_foreign_permission_denial_is_enforced(): void {
		$this->register_foreign( false );
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->register_wrappers();

		$ability = wp_get_ability( 'aafm-bridge/demo-echo' );
		$this->assertNotTrue( $ability->check_permissions( array( 'v' => 'x' ) ) );
	}

	public function test_enabled_slugs_accessor_sanitizes(): void {
		// A polluted option must yield ONLY valid foreign strings, never fatal, and never let a
		// native aafm/* or aafm-bridge/* slug through (which would bridge ourselves).
		update_option(
			'aafm_enabled_bridged_abilities',
			array(
				'demo/echo',
				'demo/echo',        // Duplicate.
				'',                 // Empty.
				42,                 // Non-string scalar.
				array( 'x' ),       // Array.
				new \stdClass(),    // Object without __toString - would fatal strval().
				'aafm/get-posts',   // Our own namespace.
				'aafm-bridge/demo-echo', // Our wrapper namespace.
			)
		);
		$this->assertSame( array( 'demo/echo' ), aafm_get_enabled_bridged_abilities() );
	}

	public function test_registration_never_bridges_a_native_namespace(): void {
		// Even a polluted option must never register an aafm-bridge/aafm-* wrapper.
		$this->register_foreign( true );
		update_option(
			'aafm_enabled_bridged_abilities',
			array( 'demo/echo', 'aafm/get-posts', 'aafm-bridge/demo-echo' )
		);
		$this->register_wrappers();

		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$this->assertStringStartsNotWith(
				'aafm-bridge/aafm',
				(string) $slug,
				'A native namespace must never be bridged.'
			);
		}
	}

	public function test_bridge_pass_is_hooked_after_late_foreign_registrations(): void {
		// A foreign plugin may register its ability at a later-than-native priority (e.g. 20).
		// The bridge pass must run AFTER those, so the whole foreign registry exists when it
		// walks it. Prove the wired priority is later than a typical late foreign registration.
		$priority = has_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
		$this->assertNotFalse( $priority, 'The bridge pass must be hooked on wp_abilities_api_init.' );
		$this->assertGreaterThan(
			20,
			$priority,
			'The bridge pass must run after late (priority 20) foreign registrations.'
		);

		// Behavioral proof: a foreign ability that becomes available only once the late pass has
		// run is still bridged when our pass executes after it.
		$this->register_foreign( true );
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->register_wrappers();
		$this->assertTrue( wp_has_ability( 'aafm-bridge/demo-echo' ) );
	}

	public function test_bridged_execute_writes_activity_rows(): void {
		$this->acting_as( 'administrator' );
		$this->register_foreign( true );
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->register_wrappers();

		wp_get_ability( 'aafm-bridge/demo-echo' )->execute( array( 'v' => 'hi' ) );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-bridge/demo-echo' ) );
		$this->assertNotEmpty( $rows, 'A bridged execute must be audited like any native ability.' );
		$this->assertSame( 'success', (string) $rows[0]['status'], 'The outcome row records success.' );
	}

	public function test_denied_bridged_permission_writes_denied_row(): void {
		$this->acting_as( 'administrator' );
		$this->register_foreign( false ); // Foreign permission denies.
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->register_wrappers();

		$this->assertNotTrue( wp_get_ability( 'aafm-bridge/demo-echo' )->check_permissions( array( 'v' => 'x' ) ) );

		$denied = aafm_query_activity(
			array(
				'ability' => 'aafm-bridge/demo-echo',
				'status'  => 'denied',
			)
		);
		$this->assertNotEmpty( $denied, 'A denied bridged permission must write a denied audit row.' );
	}

	public function test_wrapper_copies_output_schema_and_idempotent_annotation(): void {
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
					'demo/out',
					array(
						'label'               => 'Out',
						'description'         => 'o',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
						),
						'meta'                => array(
							'annotations' => array(
								'readonly'   => true,
								'idempotent' => true,
							),
						),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/out' ) );
		$this->register_wrappers();

		$wrapper = wp_get_ability( 'aafm-bridge/demo-out' );
		$this->assertNotNull( $wrapper );

		$output = $wrapper->get_output_schema();
		$this->assertSame( 'boolean', $output['properties']['ok']['type'], 'Output schema is copied and normalized.' );

		$annotations = $wrapper->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['idempotent'] ?? false, 'The idempotent annotation is carried across.' );
	}

	/**
	 * Register two foreign abilities whose slugs normalize to the SAME wrapper name.
	 *
	 * Slugs demo/a-b and demo/a--b both collapse to aafm-bridge/demo-a-b (the normalizer folds
	 * the double dash to a single one). Both are valid core ability names (lowercase, dashes).
	 *
	 * @return void
	 */
	private function register_colliding_foreigners(): void {
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
				foreach ( array( 'demo/a-b', 'demo/a--b' ) as $slug ) {
					wp_register_ability(
						$slug,
						array(
							'label'               => $slug,
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
			}
		);
	}

	public function test_normalization_collision_registers_one_and_reports_loser(): void {
		$this->register_colliding_foreigners();
		// demo/a-b is listed first, so it claims the wrapper; demo/a--b loses.
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/a-b', 'demo/a--b' ) );
		$this->register_wrappers();

		$this->assertTrue( wp_has_ability( 'aafm-bridge/demo-a-b' ) );

		$wrappers = array_filter(
			array_keys( wp_get_abilities() ),
			static fn( string $slug ): bool => 0 === strncmp( $slug, 'aafm-bridge/', 12 )
		);
		$this->assertCount( 1, $wrappers, 'Exactly one wrapper registers for two colliding slugs.' );

		$collisions = aafm_bridge_collisions();
		$this->assertArrayHasKey( 'demo/a--b', $collisions, 'The losing slug is reported.' );
		$this->assertSame( 'demo/a-b', $collisions['demo/a--b']['winner'] );
		$this->assertSame( 'aafm-bridge/demo-a-b', $collisions['demo/a--b']['wrapper'] );
		$this->assertArrayNotHasKey( 'demo/a-b', $collisions, 'The winner is not a collision.' );
	}

	/**
	 * Register a foreign ability with an explicit output_schema and execute_callback, for the
	 * bridge schema-validation tests. Uses the same 'demo-things' category ensure_categories()
	 * already wires, so no extra category registration is needed here.
	 *
	 * @param string              $slug     The foreign ability slug.
	 * @param array<string,mixed> $schema   The declared output_schema.
	 * @param callable            $execute  The execute_callback, called with the ability input.
	 * @return void
	 */
	private function register_foreign_with_schema( string $slug, array $schema, callable $execute ): void {
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
			static function () use ( $slug, $schema, $execute ): void {
				wp_register_ability(
					$slug,
					array(
						'label'               => $slug,
						'description'         => 'Bridge schema-validation fixture.',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => $schema,
						'execute_callback'    => $execute,
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * A bridged ability whose execute() result contradicts its own declared
	 * output_schema must surface as a WP_Error, not pass through under our
	 * wrapper name - the decision this task set out to enforce.
	 *
	 * Investigation finding, recorded rather than papered over: this was already
	 * true before any bridge.php change, and stays true with none. WP core's
	 * WP_Ability::execute() has validated a result against its declared
	 * output_schema since 6.9.0 (wp-includes/abilities-api/class-wp-ability.php,
	 * execute() calling validate_output() on every non-error result), and this is
	 * the plugin's stated minimum WordPress version. The bridge wrapper's
	 * execute_callback calls $live->execute() on the FOREIGN ability object, so
	 * the foreign ability validates its own output against its own schema before
	 * our closure ever sees the result; a mismatch already comes back a WP_Error.
	 * Separately, aafm_register_enabled_bridged_abilities() copies that same
	 * schema onto our OWN wrapper's registration, so our wrapper's execute() would
	 * independently validate again even if the foreign ability somehow did not.
	 * Both layers are core's, not this file's. No bridge.php change was made; this
	 * test pins the invariant so a future refactor that bypasses WP_Ability::execute()
	 * (e.g. calling the raw execute_callback directly) cannot reopen the hole
	 * silently.
	 */
	public function test_a_bridged_result_that_contradicts_its_declared_schema_is_an_error(): void {
		$this->register_foreign_with_schema(
			'vendor/lies-about-its-shape',
			array(
				'type'       => 'object',
				'properties' => array( 'count' => array( 'type' => 'integer' ) ),
				'required'   => array( 'count' ),
			),
			static fn(): array => array( 'count' => 'not an integer' )
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/lies-about-its-shape' ) );
		$this->register_wrappers();

		$result = wp_get_ability( 'aafm-bridge/vendor-lies-about-its-shape' )->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'vendor/lies-about-its-shape', $result->get_error_message() );
		$this->assertStringContainsString(
			'count',
			$result->get_error_message(),
			'The operator needs the field name to report this upstream.'
		);
	}

	/**
	 * The negative case: a bridged result that DOES conform to its declared schema
	 * passes through unchanged. Proves the validation is a real check, not a
	 * blanket rejection.
	 */
	public function test_a_conforming_bridged_result_passes_through_unchanged(): void {
		$this->register_foreign_with_schema(
			'vendor/honest',
			array(
				'type'       => 'object',
				'properties' => array( 'count' => array( 'type' => 'integer' ) ),
			),
			static fn(): array => array( 'count' => 7 )
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/honest' ) );
		$this->register_wrappers();

		$this->assertSame(
			array( 'count' => 7 ),
			wp_get_ability( 'aafm-bridge/vendor-honest' )->execute( array() )
		);
	}

	/**
	 * Data provider pairing a scalar output_schema with the matching legal value it describes.
	 * This is the exact shape the wrap-then-copy-schema defect (568388b) reopened: a bridged
	 * ability that legitimately declares a scalar output type and returns a value of that type,
	 * as opposed to the schema-less cases in scalar_result_provider() below, which never
	 * exercised the copied-schema validation at all.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:mixed}>
	 */
	public function scalar_schema_provider(): array {
		return array(
			'boolean schema, bool result'  => array( array( 'type' => 'boolean' ), true ),
			'string schema, string result' => array( array( 'type' => 'string' ), 'deleted' ),
			'integer schema, int result'   => array( array( 'type' => 'integer' ), 42 ),
		);
	}

	/**
	 * The regression an adversarial Codex pass found and no existing test covered: a bridged
	 * ability that declares a scalar output_schema (e.g. `{type: boolean}`) and legitimately
	 * returns a matching scalar (e.g. `true`) must succeed and return that scalar unchanged.
	 * Wrapping it into `array( 'data' => $value )` - as includes/bridge.php did between
	 * 568388b and the revert in this branch - fails validation against the very schema this
	 * file copies from the foreign ability onto our own wrapper's registration, turning a real
	 * success into a spurious `ability_invalid_output` error. register_foreign_with_schema()
	 * lets a schema and an execute_callback be declared together; register_foreign_returning()
	 * used below is deliberately schema-less, which is exactly why it never caught this.
	 *
	 * @dataProvider scalar_schema_provider
	 * @param array<string,mixed> $schema The foreign ability's declared output_schema.
	 * @param mixed               $value  The value returned, matching that schema.
	 */
	public function test_bridged_ability_with_scalar_schema_returning_matching_scalar_succeeds( array $schema, $value ): void {
		$this->acting_as( 'administrator' );
		$this->register_foreign_with_schema( 'vendor/scalar-schema', $schema, static fn() => $value );
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/scalar-schema' ) );
		$this->register_wrappers();

		$result = wp_get_ability( 'aafm-bridge/vendor-scalar-schema' )->execute( array() );

		$this->assertSame(
			$value,
			$result,
			'A bridged ability whose result matches its own declared scalar schema must succeed and return the value unchanged.'
		);

		$rows = aafm_query_activity( array( 'ability' => 'aafm-bridge/vendor-scalar-schema' ) );
		$this->assertNotEmpty( $rows );
		$this->assertSame(
			'success',
			(string) $rows[0]['status'],
			'A schema-conforming scalar result must log success, not error.'
		);
	}

	/**
	 * Task 8: a foreign ability's output_schema built from a bare `oneOf` - with no top-level
	 * 'type' - is a legal, spec-compliant JSON Schema (JSON Schema does not require 'type', and
	 * WP core's rest_validate_value_from_schema() explicitly supports this: it derives the
	 * effective type from whichever oneOf branch matches the actual value, see
	 * wp-includes/rest-api.php around the `isset( $args['oneOf'] )` block). A schema declaring a
	 * bare top-level 'type' with nothing else, e.g. {description:'...'}, was tried first here and
	 * rejected as a fixture: core's own validate_output() throws on that even for a DIRECT call
	 * (an unrelated core limitation, not this bug), so it could not isolate the bridge defect.
	 * aafm_bridge_output_schema() used to route every foreign output schema through
	 * aafm_normalize_json_schema(), which is INPUT-oriented and defaults a typeless schema to
	 * {type:object, properties:{}} because a call's arguments are always an object. Stamping
	 * type:object onto a oneOf schema disables core's per-branch type inference (it only fires
	 * when 'type' is unset), so when the foreign ability legitimately returned a scalar (matching
	 * its real, typeless oneOf schema), OUR wrapper's own WP_Ability::execute() validated that
	 * scalar against the FABRICATED {type:object} schema and rejected it with
	 * ability_invalid_output - even though the identical ability called directly (validated
	 * against its real schema) succeeded. This proves the bridged call now succeeds and returns
	 * the exact same value the direct call does.
	 */
	public function test_bridged_ability_with_typeless_output_schema_returning_scalar_succeeds(): void {
		$this->acting_as( 'administrator' );
		$typeless_oneof_schema = array(
			'oneOf' => array(
				array( 'type' => 'string' ),
				array( 'type' => 'integer' ),
			),
		);
		$this->register_foreign_with_schema(
			'vendor/typeless-output',
			$typeless_oneof_schema, // No top-level 'type' - legal JSON Schema.
			static fn(): string => 'new-id-123'
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/typeless-output' ) );
		$this->register_wrappers();

		$direct  = wp_get_ability( 'vendor/typeless-output' )->execute( array() );
		$bridged = wp_get_ability( 'aafm-bridge/vendor-typeless-output' )->execute( array() );

		$this->assertSame( 'new-id-123', $direct, 'Sanity check: the foreign ability itself succeeds directly.' );
		$this->assertSame(
			$direct,
			$bridged,
			'A typeless oneOf output schema must not gain a fabricated type:object from our normalizer - the bridged call must return the same value the direct call does, not an ability_invalid_output WP_Error.'
		);
	}

	/**
	 * Register a foreign ability that declares no output_schema and executes to the given
	 * value, exactly like register_foreign() above but with a caller-supplied callback. No
	 * output_schema means core's validate_output() short-circuits true on any shape, so this is
	 * the realistic path for a foreign ability that legitimately returns a bare scalar.
	 *
	 * @param string   $slug    The foreign ability slug.
	 * @param callable $execute The execute_callback, called with the ability input.
	 * @return void
	 */
	private function register_foreign_returning( string $slug, callable $execute ): void {
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
			static function () use ( $slug, $execute ): void {
				wp_register_ability(
					$slug,
					array(
						'label'               => $slug,
						'description'         => 'Bridged scalar-result fixture.',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => $execute,
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * Data provider of legal-but-scalar foreign return values. WP core documents
	 * WP_Ability::execute() as returning mixed|WP_Error - unlike our OWN contract of
	 * array|WP_Error - so a bridged ability returning true (a common "the write succeeded"
	 * result) or a bare string/int is not a contract violation on the foreign side.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function scalar_result_provider(): array {
		return array(
			'bool true' => array( true ),
			'string'    => array( 'deleted' ),
			'int'       => array( 42 ),
		);
	}

	/**
	 * The heart of the original bug: a bridged ability returning a legal scalar was caught by
	 * the malformed-result guard in aafm_register_ability_with_log() - a guard written for OUR
	 * array|WP_Error contract, wrongly applied to a wider foreign contract - and turned into a
	 * WP_Error even though the foreign write had already happened. The fix scopes that guard to
	 * native (aafm/*) ability names only, so a bridged wrapper's execute_callback returns a
	 * scalar result exactly as the foreign ability produced it - no wrap, no wider contract
	 * applied to code we do not control.
	 *
	 * @dataProvider scalar_result_provider
	 * @param mixed $scalar The legal scalar value a foreign ability returns.
	 */
	public function test_bridged_scalar_result_passes_through_not_rejected_as_malformed( $scalar ): void {
		$this->acting_as( 'administrator' );
		$this->register_foreign_returning( 'vendor/scalar-result', static fn() => $scalar );
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/scalar-result' ) );
		$this->register_wrappers();

		$result = wp_get_ability( 'aafm-bridge/vendor-scalar-result' )->execute( array() );

		$this->assertNotInstanceOf(
			\WP_Error::class,
			$result,
			'A bridged ability legally returning a scalar must not be treated as malformed.'
		);
		$this->assertSame( $scalar, $result );
	}

	/**
	 * Same call as above, but asserting the audit row - this is the actual observable damage
	 * the bug caused: the write had already happened, but the log said 'error', which is what
	 * an operator or calling agent actually sees. A legal scalar result must produce a
	 * 'success' row, not an 'error' one.
	 *
	 * @dataProvider scalar_result_provider
	 * @param mixed $scalar The legal scalar value a foreign ability returns.
	 */
	public function test_bridged_scalar_result_logs_activity_success( $scalar ): void {
		$this->acting_as( 'administrator' );
		$this->register_foreign_returning( 'vendor/scalar-result', static fn() => $scalar );
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/scalar-result' ) );
		$this->register_wrappers();

		wp_get_ability( 'aafm-bridge/vendor-scalar-result' )->execute( array() );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-bridge/vendor-scalar-result' ) );
		$this->assertNotEmpty( $rows );
		$this->assertSame(
			'success',
			(string) $rows[0]['status'],
			'A legal scalar result from a bridged ability must log success, not error.'
		);
	}

	/**
	 * A bridged ability that genuinely errors (returns a WP_Error itself, not a schema
	 * mismatch) must still surface as that same WP_Error, unwrapped. The scalar-normalization
	 * fix in includes/bridge.php only touches non-array, non-WP_Error results.
	 */
	public function test_bridged_wp_error_result_still_surfaces_unwrapped(): void {
		$this->acting_as( 'administrator' );
		$this->register_foreign_returning(
			'vendor/errors',
			static fn() => new \WP_Error( 'vendor_boom', 'The vendor ability failed.' )
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'vendor/errors' ) );
		$this->register_wrappers();

		$result = wp_get_ability( 'aafm-bridge/vendor-errors' )->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'vendor_boom', $result->get_error_code() );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-bridge/vendor-errors' ) );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'error', (string) $rows[0]['status'] );
	}

	/**
	 * A bridged call announces its resolve like any other, and a bridged FAILURE announces the null
	 * detail the exclusion leaves on the column rather than the foreign plugin's own error code.
	 *
	 * Both halves were unpinned end to end. aafm_ability_resolved is reached through the real
	 * wrapper only by ResolveHookTest's native cases, so skipping the announcement for every
	 * aafm-bridge/* call left the whole suite green, and the bridged exclusion in
	 * aafm_build_activity_detail_from_result() was asserted only as a unit, never at the layer a
	 * monitor actually reads.
	 */
	public function test_a_bridged_call_announces_its_resolve_and_never_a_foreign_error_code(): void {
		$fired = array();
		add_action(
			'aafm_ability_resolved',
			static function ( $record ) use ( &$fired ): void {
				$fired[] = $record;
			}
		);

		$this->acting_as( 'administrator' );
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
						'description'         => 'Echoes value.',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'v' => array( 'type' => 'string' ) ),
						),
						// A foreign plugin composing a code out of its own input, which is the
						// whole reason bridged codes are excluded from the detail column.
						'execute_callback'    => static fn( $i ) => new \WP_Error(
							'duplicate_sku_' . ( $i['v'] ?? '' ),
							'That SKU already exists.'
						),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo' ) );
		$this->register_wrappers();

		wp_get_ability( 'aafm-bridge/demo-echo' )->execute( array( 'v' => 'ABC-123-CUSTOMER' ) );

		$this->assertCount( 1, $fired, 'A bridged resolve is a resolve: it announces exactly once.' );
		$this->assertSame( 'error', $fired[0]['status'] );
		$this->assertNull(
			$fired[0]['detail'],
			'A foreign error code is not an identifier by construction, so it must reach neither the column nor the hook.'
		);

		$rows = aafm_query_activity( array( 'ability' => 'aafm-bridge/demo-echo' ) );
		$this->assertCount( 1, $rows, 'Guard on the guard: one row per call, so there is a single id to match against.' );
		$this->assertSame( (int) $rows[0]['id'], $fired[0]['row_id'], 'The announced row_id must be the row the call wrote.' );
		$this->assertNull( $rows[0]['detail'], 'And the column agrees with the hook.' );
	}

	/**
	 * Register a foreign ability whose input schema carries a keyword outside the set an MCP
	 * client is guaranteed to understand, like a third-party ability author reasonably might.
	 *
	 * @return void
	 */
	private function register_foreign_with_unsupported_schema_keyword(): void {
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
					'demo/schema-with-unsupported-keyword',
					array(
						'label'               => 'Schema with unsupported keyword',
						'description'         => 'A foreign ability whose schema carries a keyword MCP clients do not expect.',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'               => 'object',
							'properties'         => array(
								'name' => array( 'type' => 'string' ),
							),
							'x-vendor-extension' => 'not a real JSON Schema keyword',
						),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * A bridged ability's schema is prepared through WP 7.1's wp_prepare_json_schema_for_client()
	 * before it is copied onto our wrapper's registration, so a keyword outside the REST-safe
	 * allow-list a third-party author might have used never reaches an MCP client through us.
	 * Skipped when core does not provide the function (this plugin's WP 6.9/7.0 floor).
	 */
	public function test_bridged_input_schema_strips_a_keyword_outside_the_client_allowlist(): void {
		if ( ! function_exists( 'wp_prepare_json_schema_for_client' ) ) {
			$this->markTestSkipped( 'wp_prepare_json_schema_for_client() requires WP 7.1+.' );
		}

		$this->register_foreign_with_unsupported_schema_keyword();
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/schema-with-unsupported-keyword' ) );
		$this->register_wrappers();

		$schema = wp_get_ability( 'aafm-bridge/demo-schema-with-unsupported-keyword' )->get_input_schema();

		$this->assertArrayHasKey( 'type', $schema, 'A recognized keyword must survive.' );
		$this->assertArrayHasKey( 'properties', $schema, 'A recognized keyword must survive.' );
		$this->assertArrayNotHasKey( 'x-vendor-extension', $schema, 'An unsupported keyword must be stripped before it reaches an MCP client.' );
	}

	/**
	 * Register a foreign ability whose OUTPUT schema carries a keyword outside the client
	 * allow-list, mirroring register_foreign_with_unsupported_schema_keyword() above but for
	 * get_output_schema() instead of get_input_schema().
	 *
	 * @return void
	 */
	private function register_foreign_with_unsupported_output_schema_keyword(): void {
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
					'demo/output-schema-with-unsupported-keyword',
					array(
						'label'               => 'Output schema with unsupported keyword',
						'description'         => 'A foreign ability whose output schema carries a keyword MCP clients do not expect.',
						'category'            => 'demo-things',
						'output_schema'       => array(
							'type'               => 'object',
							'properties'         => array(
								'ok' => array( 'type' => 'boolean' ),
							),
							'x-vendor-extension' => 'not a real JSON Schema keyword',
						),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * Fix round 1, test-quality F2: Task 8 delegated BOTH aafm_bridge_input_schema() and
	 * aafm_bridge_output_schema() to wp_prepare_json_schema_for_client(), but only the input side
	 * had a test. A regression that removed just the output-schema call would have passed clean.
	 * Mirrors the input-schema test above, asserting on the specific stripped keyword.
	 */
	public function test_bridged_output_schema_strips_a_keyword_outside_the_client_allowlist(): void {
		if ( ! function_exists( 'wp_prepare_json_schema_for_client' ) ) {
			$this->markTestSkipped( 'wp_prepare_json_schema_for_client() requires WP 7.1+.' );
		}

		$this->register_foreign_with_unsupported_output_schema_keyword();
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/output-schema-with-unsupported-keyword' ) );
		$this->register_wrappers();

		$schema = wp_get_ability( 'aafm-bridge/demo-output-schema-with-unsupported-keyword' )->get_output_schema();

		$this->assertArrayHasKey( 'type', $schema, 'A recognized keyword must survive.' );
		$this->assertArrayHasKey( 'properties', $schema, 'A recognized keyword must survive.' );
		$this->assertArrayNotHasKey( 'x-vendor-extension', $schema, 'An unsupported keyword must be stripped from the output schema before it reaches an MCP client.' );
	}
}
