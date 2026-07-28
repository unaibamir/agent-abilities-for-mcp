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

if ( ! function_exists( 'aafm_test_fixture_internal_function_args' ) ) {
	/**
	 * A fixture args_builder whose execute_callback is a real, named, resolvable function that
	 * PHP itself provides - so it passes every check a string execute_callback normally gets
	 * (is_string, function_exists) while still being impossible to reflect a body for, because an
	 * internal function has no PHP source and ReflectionFunction::getFileName() returns false for
	 * it. A scanner that only guards against a closure/missing name (null $execute) still walks
	 * straight into this case and must treat it the same way: unscannable, not clean.
	 *
	 * @return array<string,mixed>
	 */
	function aafm_test_fixture_internal_function_args(): array {
		return array(
			'meta'             => array( 'annotations' => array( 'readonly' => true ) ),
			'execute_callback' => 'strlen',
		);
	}
}

if ( ! function_exists( 'aafm_test_fixture_dynamic_dispatch_writer' ) ) {
	/**
	 * The hidden writer a dynamic-dispatch execute_callback reaches only through
	 * call_user_func(), never through a literal aafm_*( call the delegate scanner can see.
	 *
	 * @return array<string,mixed>
	 */
	function aafm_test_fixture_dynamic_dispatch_writer(): array {
		update_option( 'aafm_fixture_should_never_be_written', 1 );
		return array();
	}
}

if ( ! function_exists( 'aafm_test_fixture_dynamic_dispatch_execute' ) ) {
	/**
	 * A named, fully reflectable execute_callback whose OWN body contains no write-shaped call
	 * and no literal aafm_*( delegate call - it reaches its writer exclusively through
	 * call_user_func(), which the delegate scanner's regex (\baafm_[a-z0-9_]+\s*\() cannot see.
	 * A scanner that only follows literal calls reports this ability clean even though it writes.
	 *
	 * @return array<string,mixed>
	 */
	function aafm_test_fixture_dynamic_dispatch_execute(): array {
		return call_user_func( 'aafm_test_fixture_dynamic_dispatch_writer' );
	}
}

if ( ! function_exists( 'aafm_test_fixture_dynamic_dispatch_args' ) ) {
	/**
	 * A fixture args_builder pairing a fully reflectable execute_callback with a write hidden
	 * behind call_user_func() dispatch rather than a closure or a missing function.
	 *
	 * @return array<string,mixed>
	 */
	function aafm_test_fixture_dynamic_dispatch_args(): array {
		return array(
			'meta'             => array( 'annotations' => array( 'readonly' => true ) ),
			'execute_callback' => 'aafm_test_fixture_dynamic_dispatch_execute',
		);
	}
}
