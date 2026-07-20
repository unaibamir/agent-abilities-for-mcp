<?php
/**
 * Standing guard for the readonly-but-writes class.
 *
 * Reflects every read/readonly ability's execute callback (and one hop of aafm_* delegation) and
 * fails if any of them makes a write-shaped call the annotation swears it does not. This is the
 * cheap, always-on gate that runs before a wave of new abilities is authored: an ability grouped
 * `risk: read` / annotated `readonly: true` that quietly mutates state is a security-and-trust bug
 * agents cannot see, and unit tests of the callback's happy path never surface it.
 *
 * Enumerates the bare unit registry, which holds the core / always-on reads. Integration reads
 * (wc-*, acf-*, seo-*) only join the registry when their host plugin is active, so they are covered
 * by the parallel contract test (tests/contract/AnnotationCorrectnessContractTest.php) where the
 * real vendors are loaded.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\AnnotationScanner;
use AAFM\Tests\TestCase;

final class AnnotationCorrectnessTest extends TestCase {

	/**
	 * No read/readonly ability may make an unsuppressed write-shaped call.
	 */
	public function test_no_readonly_ability_writes(): void {
		$result = AnnotationScanner::scan( aafm_get_abilities_registry() );

		// A broken enumeration that scans nothing must not pass silently: this env always exposes
		// the core reads, so a zero here means the scanner stopped seeing the registry, not that
		// the plugin is clean.
		$this->assertGreaterThan(
			0,
			$result['scanned'],
			'The scanner found no read/readonly abilities to check, so enumeration is broken.'
		);

		$this->assertSame(
			array(),
			$result['violations'],
			sprintf(
				"A readonly/read ability makes a write-shaped call (ability | claim | write-call | file:line):\n%s",
				AnnotationScanner::format( $result['violations'] )
			)
		);
	}
}
