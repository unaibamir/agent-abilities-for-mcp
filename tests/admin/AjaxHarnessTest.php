<?php
/**
 * The shared AJAX test harness cleans up after itself.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class AjaxHarnessTest extends TestCase {

	use AjaxHarness;

	public function tear_down(): void {
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	/**
	 * A second intercept_die() in one test replaces the first filter, so reset leaves none behind.
	 * The test library keeps a wp_die filter of its own, so the callbacks are compared to before.
	 */
	public function test_the_harness_leaves_no_die_filter_after_a_double_intercept(): void {
		$count  = static function ( string $hook ): int {
			return isset( $GLOBALS['wp_filter'][ $hook ] ) ? count( $GLOBALS['wp_filter'][ $hook ]->callbacks, COUNT_RECURSIVE ) : 0;
		};
		$before = array( $count( 'wp_die_handler' ), $count( 'wp_die_ajax_handler' ) );

		$this->post_as( 'administrator' );
		$this->intercept_die();
		$this->reset_ajax_harness();

		$this->assertSame( $before, array( $count( 'wp_die_handler' ), $count( 'wp_die_ajax_handler' ) ) );
	}
}
