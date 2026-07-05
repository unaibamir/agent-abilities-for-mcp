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
		aafm_install_activity_log();
		aafm_clear_activity_log();
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
						'execute_callback'    => static fn( $i ) => array(),
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
}
