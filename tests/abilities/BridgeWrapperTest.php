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
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo', 'demo/echo', '', 42 ) );
		$this->assertSame( array( 'demo/echo', '42' ), aafm_get_enabled_bridged_abilities() );
	}
}
