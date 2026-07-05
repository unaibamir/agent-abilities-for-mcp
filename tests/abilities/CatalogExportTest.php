<?php
/**
 * Catalog exporter: aafm.catalog/v1 dataset built from discovered foreign abilities.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class CatalogExportTest extends TestCase {

	public function tear_down(): void {
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register a demo category + a read-only foreign ability.
	 *
	 * @return void
	 */
	private function register_foreign(): void {
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
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
			}
		);
	}

	public function test_catalog_shape(): void {
		$this->register_foreign();

		$cat = aafm_build_catalog( false );
		$this->assertSame( 'aafm.catalog/v1', $cat['schema'] );
		$this->assertArrayHasKey( 'generated', $cat );
		$this->assertArrayHasKey( 'site', $cat );

		$names = wp_list_pluck( $cat['plugins'], 'namespace' );
		$this->assertContains( 'demo', $names );
		$this->assertNotContains( 'aafm', $names ); // Native excluded by default.

		$demo = current( array_filter( $cat['plugins'], static fn( $p ) => 'demo' === $p['namespace'] ) );
		$this->assertSame( 'aafm-bridge-demo-echo', $demo['abilities'][0]['mcp_tool_name'] );
		$this->assertTrue( $demo['abilities'][0]['readonly'] );
		$this->assertSame( 'read', $demo['abilities'][0]['risk'] );
	}
}
