<?php
/**
 * Activation-hook callbacks must be loadable at activation time.
 *
 * Activating a plugin includes its main file and fires the activation hook in the same request,
 * WITHOUT ever running plugins_loaded. So every callback passed to register_activation_hook() has to
 * be defined by code that loads at top level of the main plugin file - not only inside aafm_bootstrap()
 * (which is hooked on plugins_loaded and never runs during activation). A callback defined only inside
 * bootstrap is an undefined function when WordPress calls it on activation: a fresh install fatals.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ActivationHookLoadingTest extends TestCase {

	/**
	 * Every callback registered with register_activation_hook( AAFM_PLUGIN_FILE, ... ) in the main
	 * plugin file must be defined either inline in that file or by an include required at top level
	 * (before the aafm_bootstrap() definition). The defining file is resolved with reflection - the
	 * real path PHP loaded the function from - so this catches a callback whose file is required only
	 * inside aafm_bootstrap(). Regression guard for the 1.4.0 activation fatal: the menu-pointer flag
	 * callback lived only in a bootstrap-required file, so a fresh activation could not call it.
	 */
	public function test_every_activation_callback_is_loaded_before_bootstrap(): void {
		$main   = AAFM_PLUGIN_FILE;
		$source = (string) file_get_contents( $main );

		// The top-level region: everything before the aafm_bootstrap() definition. Includes required
		// here load during the activation request; includes required inside aafm_bootstrap() do not.
		$boot_pos = strpos( $source, 'function aafm_bootstrap(' );
		$this->assertNotFalse( $boot_pos, 'Could not locate the aafm_bootstrap() definition.' );
		$top_region = substr( $source, 0, $boot_pos );

		preg_match_all(
			"/register_activation_hook\(\s*AAFM_PLUGIN_FILE\s*,\s*'([^']+)'/",
			$source,
			$matches
		);
		$callbacks = $matches[1];
		$this->assertNotEmpty( $callbacks, 'No activation-hook callbacks were found to check.' );

		$plugin_dir = AAFM_PLUGIN_DIR;

		foreach ( $callbacks as $callback ) {
			$this->assertTrue(
				function_exists( $callback ),
				"Activation callback {$callback} is not defined at all."
			);

			$file = ( new \ReflectionFunction( $callback ) )->getFileName();
			$this->assertIsString( $file, "Could not resolve the defining file for {$callback}." );

			// Defined inline in the main plugin file: always available at activation.
			if ( realpath( $file ) === realpath( $main ) ) {
				continue;
			}

			// Otherwise its include must be required at top level so it loads at activation time.
			// Match the actual require statement (not a bare path mention, which a comment could
			// satisfy), scoped to the region before aafm_bootstrap().
			$relative = str_replace( $plugin_dir, '', $file );
			$require  = "require_once AAFM_PLUGIN_DIR . '{$relative}';";
			$this->assertStringContainsString(
				$require,
				$top_region,
				"Activation callback {$callback} is defined in {$relative}, which is not required at "
				. 'top level of the main plugin file. It would be undefined when WordPress fires the '
				. 'activation hook (plugins_loaded does not run during activation). Require its file '
				. 'before aafm_bootstrap(), not only inside it.'
			);
		}
	}

	/**
	 * The specific regression: the first-activation menu-pointer flag callback is callable without
	 * aafm_bootstrap() having run. Its file must be loaded at top level, not only inside bootstrap.
	 */
	public function test_menu_pointer_flag_callback_file_is_loaded_at_top_level(): void {
		$this->assertTrue( function_exists( 'aafm_quickconnect_flag_menu_pointer' ) );

		$file       = ( new \ReflectionFunction( 'aafm_quickconnect_flag_menu_pointer' ) )->getFileName();
		$relative   = str_replace( AAFM_PLUGIN_DIR, '', (string) $file );
		$source     = (string) file_get_contents( AAFM_PLUGIN_FILE );
		$top_region = substr( $source, 0, (int) strpos( $source, 'function aafm_bootstrap(' ) );

		$this->assertStringContainsString(
			"require_once AAFM_PLUGIN_DIR . '{$relative}';",
			$top_region,
			'onboarding-pointer.php must be required at top level of the main plugin file so the '
			. 'activation callback is defined when the activation hook fires.'
		);
	}
}
