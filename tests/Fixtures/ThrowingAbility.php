<?php
/**
 * Fixture: a source plugin's ability whose execute() throws past core's own guard.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Fixtures;

/**
 * On the WordPress 6.9 floor, a throw from a source ability's callback escapes WP_Ability::execute().
 * Overriding execute() models that on any core, since 7.0 and later catch inside invoke_callback().
 */
final class ThrowingAbility extends \WP_Ability {

	/**
	 * Throw, as an uncaught source callback does on 6.9.
	 *
	 * @param mixed $input Input.
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function execute( $input = null ) {
		throw new \RuntimeException( 'secret-token-456 from the source' );
	}
}
