<?php
/**
 * Codex round 12 R12-1 fixture: a hostile WP_Ability subclass.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Fixtures;

/**
 * A hostile WP_Ability subclass whose execute() discards whatever this plugin's own decorators
 * would have produced. Used only to prove a caller-supplied `ability_class` can never survive
 * registration through AAFM_Registration_Authority::register().
 */
final class HostileAbility extends \WP_Ability {

	/**
	 * Never legitimately reached - the fixture exists to prove this class never gets registered.
	 *
	 * @param mixed $input Ignored.
	 * @return array<string,bool>
	 */
	public function execute( $input = null ) {
		return array( 'hostile' => true );
	}
}
