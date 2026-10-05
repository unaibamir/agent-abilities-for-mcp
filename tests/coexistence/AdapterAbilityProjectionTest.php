<?php
/**
 * Every ability this plugin registers must still project into an MCP tool on the 0.7.0 adapter.
 *
 * 0.7.0 rejects an invalid component instead of repairing it, so a schema or annotation the old
 * adapter quietly fixed up would silently drop that ability from tools/list. This builds each
 * native ability as a tool and projects it through both schema revisions the adapter negotiates.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;
use WP\MCP\Domain\Tools\McpTool;
use WP\McpSchema\Schemas;

final class AdapterAbilityProjectionTest extends TestCase {

	public function test_every_native_ability_projects_on_both_schema_revisions(): void {
		$names = array_keys( aafm_get_abilities_registry() );
		$this->register_enabled( $names );

		$schemas = array();
		foreach ( Schemas::supportedVersions() as $version ) {
			$schemas[ $version ] = Schemas::create()->forVersion( $version );
		}

		$built    = 0;
		$rejected = array();

		foreach ( $names as $name ) {
			$ability = wp_get_ability( $name );
			if ( null === $ability ) {
				continue;
			}

			$tool = McpTool::fromAbility( $ability );
			if ( is_wp_error( $tool ) ) {
				$rejected[] = $name . ': ' . $tool->get_error_message();
				continue;
			}
			++$built;

			foreach ( $schemas as $version => $schema ) {
				if ( ! $tool->is_available_for( $schema ) ) {
					$error      = $tool->get_projection_error( $version );
					$rejected[] = sprintf( '%s on %s: %s', $name, $version, $error ? $error->getMessage() : 'not available' );
				}
			}
		}

		$this->assertSame( array(), $rejected, 'Abilities the 0.7.0 adapter would drop from tools/list.' );
		$this->assertSame( count( $names ), $built, 'Every catalog ability must register and build as a tool.' );
	}
}
