<?php
/**
 * A throwable whose class name and file name are both longer than the caps
 * aafm_build_activity_detail_from_exception() puts on them.
 *
 * Both halves have to come from something the builder actually copies. A long exception MESSAGE
 * proves nothing, because the builder never reads the message - which is why the bounds test that
 * used one stayed green with both caps deleted.
 *
 * The file name is the reason this lives in its own file rather than beside the test: getFile()
 * reports where the throwable was constructed, so the only way to hand the builder a basename over
 * 64 characters is to construct it in a file with one. That also rules out the PSR-4 autoloader,
 * whose file name would have to match the 128-plus-character class name, so the test requires this
 * file directly.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Fixtures;

/**
 * Deliberately overlong. Renaming it shorter silently disables the class-cap assertion, which the
 * test guards against by asserting the raw name is over the cap before asserting the cut.
 */
final class ExceptionWithADeliberatelyOverlongClassNameThatExistsOnlyToExerciseTheOneHundredAndTwentyEightCharacterCapOfTheAuditDetailBuilder extends \RuntimeException {

	/**
	 * Build one, here, so getFile() reports this file's overlong name.
	 *
	 * @return self
	 */
	public static function raised_here(): self {
		return new self( 'anything' );
	}
}
