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
		// The abilities registry persists across tests, so drop the demo fixtures and any
		// wrappers this case registered to keep the next test isolated.
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) || 0 === strncmp( $slug, 'aafm-bridge/', 12 ) ) {
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

	public function test_disabled_foreign_ability_not_registered(): void {
		$this->register_foreign( true );
		update_option( 'aafm_enabled_bridged_abilities', array() );
		$this->register_wrappers();

		$this->assertFalse( wp_has_ability( 'aafm-bridge/demo-echo' ) );
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
}
