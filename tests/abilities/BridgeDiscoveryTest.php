<?php
/**
 * Bridge discovery: enumerate foreign abilities grouped by source plugin, and the
 * wrapper-name transform.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BridgeDiscoveryTest extends TestCase {

	/**
	 * Register a throwaway category and a foreign ability inside the gated init actions.
	 *
	 * Core's registry requires every ability to name a registered category, so the fixture
	 * registers its own 'demo-things' category first (idempotent).
	 *
	 * @param string              $slug Ability slug.
	 * @param array<string,mixed> $args Ability args (category defaults to 'demo-things').
	 * @return void
	 */
	private function register_foreign( string $slug, array $args ): void {
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
		$args += array( 'category' => 'demo-things' );
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $slug, $args ): void {
				wp_register_ability( $slug, $args );
			}
		);
	}

	public function test_name_transform(): void {
		$this->assertSame( 'aafm-bridge/elementor-get-pages', aafm_bridge_tool_name( 'Elementor/Get_Pages' ) );
		$this->assertSame( 'aafm-bridge/core-get-site-info', aafm_bridge_tool_name( 'core/get-site-info' ) );
	}

	public function test_discovery_excludes_our_own_and_lists_foreign(): void {
		$this->register_foreign(
			'demo/list-things',
			array(
				'label'               => 'List things',
				'description'         => 'Lists things.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
				'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
			)
		);

		$groups = aafm_discover_foreign_abilities();
		$this->assertArrayHasKey( 'demo', $groups );
		$slugs = wp_list_pluck( $groups['demo']['abilities'], 'slug' );
		$this->assertContains( 'demo/list-things', $slugs );
		$this->assertArrayNotHasKey( 'aafm', $groups );        // Never list ourselves.
		$this->assertArrayNotHasKey( 'aafm-bridge', $groups ); // Never list wrappers.

		// The read annotation is classified as a read risk, and the MCP tool name is derived.
		$row = $groups['demo']['abilities'][0];
		$this->assertSame( 'read', $row['risk'] );
		$this->assertTrue( $row['readonly'] );
		$this->assertSame( 'aafm-bridge-demo-list-things', $row['tool_name'] );
	}
}
