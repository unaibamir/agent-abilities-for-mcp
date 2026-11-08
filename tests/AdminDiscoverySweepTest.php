<?php
/**
 * Sweep: an administrator with every tool switched on discovers every tool.
 *
 * AllowlistSweepTest checks that scoping hides tools. This checks the other direction: a tool
 * whose permission needs an object id answers no to the empty-input probe, so without its own
 * case in aafm_ability_list_permission() it vanishes from tools/list even for an administrator,
 * while tools/call would still work.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AdminDiscoverySweepTest extends TestCase {

	private const INTEGRATIONS = array( 'yoast', 'rankmath', 'aioseo', 'acf', 'woocommerce' );

	public function set_up(): void {
		parent::set_up();
		foreach ( self::INTEGRATIONS as $integration ) {
			add_filter( "aafm_integration_active_{$integration}", '__return_true' );
		}
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		foreach ( self::INTEGRATIONS as $integration ) {
			remove_filter( "aafm_integration_active_{$integration}", '__return_true' );
		}
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * Enable and register the whole catalog with the high-risk lock down, act as an administrator,
	 * and return every registry tool the administrator cannot discover.
	 *
	 * @return string[]
	 */
	private function undiscoverable_for_admin(): array {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->register_enabled( array_keys( aafm_get_abilities_registry() ) );
		$this->acting_as( 'administrator' );

		$missed = array();
		foreach ( array_keys( aafm_get_abilities_registry() ) as $name ) {
			if ( ! aafm_user_can_discover_ability( $name ) ) {
				$missed[] = $name;
			}
		}
		return $missed;
	}

	public function test_an_admin_with_everything_enabled_discovers_every_tool(): void {
		$this->assertGreaterThan( 150, count( aafm_get_abilities_registry() ), 'The sweep must walk the whole catalog.' );

		$missed = $this->undiscoverable_for_admin();

		$this->assertSame( array(), $missed, "An administrator cannot discover these tools:\n" . implode( "\n", $missed ) );
	}

	public function test_the_sweep_goes_red_on_a_tool_that_needs_a_missing_case(): void {
		$name    = 'aafm/discovery-sweep-probe';
		$fixture = static function ( array $registry ) use ( $name ): array {
			$registry[ $name ] = array(
				'label'        => 'Discovery sweep probe',
				'description'  => 'Fixture whose permission needs an id and has no list case.',
				'group'        => 'writes',
				'risk'         => 'write',
				'args_builder' => static fn(): array => array(
					'label'               => 'Discovery sweep probe',
					'description'         => 'Fixture whose permission needs an id and has no list case.',
					'category'            => 'aafm-writes',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => static fn(): array => array(),
					'permission_callback' => static fn( $input = array() ): bool => ! empty( $input['post_id'] ) && current_user_can( 'edit_post', (int) $input['post_id'] ),
				),
			);
			return $registry;
		};
		add_filter( 'aafm_abilities_registry', $fixture );
		aafm_flush_registry_cache();

		$missed = $this->undiscoverable_for_admin();

		remove_filter( 'aafm_abilities_registry', $fixture );
		aafm_flush_registry_cache();

		$this->assertContains( $name, $missed );
	}
}
