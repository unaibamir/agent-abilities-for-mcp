<?php
/**
 * Fixture: a source plugin's ability class that skips core's output validation.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Fixtures;

/**
 * Mirrors the override ACF ships in ACF_REST_Ability: validate_output() always passes.
 */
final class ValidateOutputOverridingAbility extends \WP_Ability {

	/**
	 * Skip the schema check, as the source plugin does.
	 *
	 * @param mixed $output The result.
	 * @return true
	 */
	protected function validate_output( $output ) {
		return true;
	}
}
