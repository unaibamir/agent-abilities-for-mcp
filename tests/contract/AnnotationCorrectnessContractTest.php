<?php
/**
 * Annotation-correctness guard, run where the real integration hosts are active.
 *
 * The unit AnnotationCorrectnessTest scans the bare registry, which holds only the core reads.
 * Integration abilities (the wc-, acf-, yoast-, aioseo- and rankmath- families) join the registry
 * only when their host plugin is loaded. This contract case runs the SAME scanner under the
 * contract bootstrap, where real WooCommerce, ACF, Yoast, AIOSEO and Rank Math are provisioned, so
 * their read abilities are enumerated and checked against reality (a readonly SEO or store read
 * that delegates to a helper which writes is caught here). Any future integration (Elementor, and
 * so on) is covered automatically once its host is active, with no change to this test.
 *
 * Skips cleanly when no vendor is provisioned, matching the rest of the contract suite.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Contract;

use AAFM\Tests\Support\AnnotationScanner;
use AAFM\Tests\TestCase;

/**
 * Runs the annotation-correctness scanner over the registry with real integration hosts loaded.
 *
 * @group contract
 */
final class AnnotationCorrectnessContractTest extends TestCase {

	/**
	 * Ability-name prefixes that only appear when an integration host is active.
	 *
	 * @var array<int,string>
	 */
	private const INTEGRATION_PREFIXES = array(
		'aafm/wc-',
		'aafm/acf-',
		'aafm/yoast-',
		'aafm/aioseo-',
		'aafm/rankmath-',
	);

	/**
	 * No integration read/readonly ability may make an unsuppressed write-shaped call.
	 */
	public function test_no_integration_readonly_ability_writes(): void {
		$result = AnnotationScanner::scan( aafm_get_abilities_registry() );

		$integration_reads = array_values(
			array_filter(
				$result['abilities'],
				static function ( string $name ): bool {
					foreach ( self::INTEGRATION_PREFIXES as $prefix ) {
						if ( 0 === strpos( $name, $prefix ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);

		if ( array() === $integration_reads ) {
			$this->markTestSkipped( 'No integration vendor provisioned. Run tests/bin/install-vendors.sh so the wc-, acf- and seo- reads register.' );
		}

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
