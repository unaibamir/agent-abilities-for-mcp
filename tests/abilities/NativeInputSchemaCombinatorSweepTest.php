<?php
/**
 * Sweep: no native aafm/* ability advertises a top-level oneOf, anyOf, allOf or not on its input
 * schema. ChatGPT silently drops a whole tools/list refresh over one such schema, so a native tool
 * must never reintroduce what the bridge flattens for foreign tools.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class NativeInputSchemaCombinatorSweepTest extends TestCase {

	public function test_no_native_ability_has_a_top_level_schema_combinator(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array_keys( aafm_get_abilities_registry() ) );

		$checked = 0;
		foreach ( wp_get_abilities() as $slug => $ability ) {
			if ( 0 !== strncmp( (string) $slug, 'aafm/', 5 ) ) {
				continue;
			}
			++$checked;
			$schema = $ability->get_input_schema();
			foreach ( array( 'oneOf', 'anyOf', 'allOf', 'not' ) as $keyword ) {
				$this->assertArrayNotHasKey( $keyword, $schema, sprintf( '%s advertises a top-level %s.', $slug, $keyword ) );
			}
		}
		$this->assertGreaterThan( 50, $checked, 'The sweep saw too few abilities; the setup is broken.' );
	}
}
