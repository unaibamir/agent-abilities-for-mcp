<?php
/**
 * The hourly backup check's callback must exist in a process that loaded none of the lazy files.
 *
 * This class requires nothing in set_up on purpose: the last test needs a process in which
 * includes/siteops/backup-updraft.php has never been loaded.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class BackupVerifyCronTest extends TestCase {

	public function test_the_callback_is_hooked_and_lives_in_the_always_loaded_file(): void {
		$this->assertNotFalse( has_action( 'aafm_backup_verify', 'aafm_backup_verify_cron' ) );

		$function = new \ReflectionFunction( 'aafm_backup_verify_cron' );
		$this->assertStringEndsWith( 'includes/siteops/backup-gate.php', str_replace( '\\', '/', (string) $function->getFileName() ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$main = (string) file_get_contents( AAFM_PLUGIN_FILE );
		$this->assertStringContainsString( "require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-gate.php';", $main, 'The bootstrap loads the file on every request.' );
	}

	public function test_the_callback_loads_both_files_before_it_calls_the_runner(): void {
		$function = new \ReflectionFunction( 'aafm_backup_verify_cron' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$lines = explode( "\n", (string) file_get_contents( (string) $function->getFileName() ) );
		$body  = implode( "\n", array_slice( $lines, (int) $function->getStartLine() - 1, (int) $function->getEndLine() - (int) $function->getStartLine() + 1 ) );

		$vendor  = strpos( $body, "require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';" );
		$updraft = strpos( $body, "require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';" );
		$run     = strpos( $body, 'aafm_backup_verify_run();' );

		$this->assertNotFalse( $vendor );
		$this->assertNotFalse( $updraft );
		$this->assertNotFalse( $run );
		$this->assertLessThan( $run, $vendor );
		$this->assertLessThan( $run, $updraft );
	}

	/**
	 * A cron request loads the bootstrap and nothing lazy. Runs in its own process so no other
	 * test has loaded the runner's file.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hook_runs_in_a_process_that_loaded_nothing_lazy(): void {
		$this->assertFalse( function_exists( 'aafm_backup_verify_run' ), 'A process with nothing lazy loaded.' );

		do_action( 'aafm_backup_verify' );

		$this->assertTrue( function_exists( 'aafm_backup_verify_run' ), 'The callback loaded the runner and called it.' );
	}
}
