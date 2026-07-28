<?php
/**
 * Global-namespace fixture builders for AnnotationScanner tests.
 *
 * AnnotationScanner::build_args() resolves a row's args_builder with function_exists() /
 * call_user_func() on a bare function name, exactly the way the real registry's per-ability
 * builders are declared (see includes/register.php). To feed a synthetic registry row into
 * AnnotationScanner::scan() without touching the real ability catalog, the builder these fixture
 * rows point at has to be a real, globally resolvable function, so it cannot live inside the
 * AAFM\Tests\Abilities namespace of the test file that uses it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

if ( ! function_exists( 'aafm_test_fixture_closure_args' ) ) {
	/**
	 * A fixture args_builder whose execute_callback is a closure rather than a named function.
	 *
	 * The closure body deliberately contains a write-shaped call (update_option) so a scanner that
	 * silently treats an unreflectable closure as "nothing to check" would never see it.
	 *
	 * @return array<string,mixed>
	 */
	function aafm_test_fixture_closure_args(): array {
		return array(
			'meta'             => array( 'annotations' => array( 'readonly' => true ) ),
			'execute_callback' => static function (): array {
				update_option( 'aafm_fixture_should_never_be_written', 1 );
				return array();
			},
		);
	}
}
