<?php
/**
 * Sweep: the redirect half of Site Ops is read only, and this test is what keeps it so.
 *
 * Every file under includes/siteops/redirects/, plus the registry and the read helpers, must not
 * name a writer: no vendor save, no post or option write, no transient write, and neither of the
 * two Safe Redirect Manager functions that store its rule list in a transient. The files are
 * found by glob, so the test passes with any number of adapter files.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SiteOpsNoWriterSweepTest extends TestCase {

	private const NEEDLES = array( '->save(', 'update_post_meta', 'wp_update_post', 'wp_insert_post', 'wp_delete_post', 'update_option', 'delete_option', 'set_transient', 'delete_transient', '->update(', '->insert(', '->delete(', '_save_redirects', 'srm_create_redirect', 'srm_get_redirects', 'srm_match_redirect', 'change_status', '->enable(', '->disable(', 'red_set_options' );

	/**
	 * The files the sweep reads, as paths under the plugin root.
	 *
	 * @return array<int,string>
	 */
	private function files(): array {
		$files = array( 'includes/siteops/redirect-registry.php', 'includes/siteops/redirect-read.php' );
		foreach ( (array) glob( AAFM_PLUGIN_DIR . 'includes/siteops/redirects/*.php' ) as $path ) {
			$files[] = 'includes/siteops/redirects/' . basename( (string) $path );
		}
		return $files;
	}

	public function test_no_redirect_file_names_a_writer(): void {
		$offenders = array();
		foreach ( $this->files() as $file ) {
			$source = (string) file_get_contents( AAFM_PLUGIN_DIR . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This plugin's own local source.
			$this->assertNotSame( '', $source, $file . ' must be readable.' );
			foreach ( self::NEEDLES as $needle ) {
				if ( false !== strpos( $source, $needle ) ) {
					$offenders[] = $file . ' names ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $offenders, "The redirect files are read only:\n" . implode( "\n", $offenders ) );
	}

	public function test_every_adapter_file_defines_exactly_its_five_functions(): void {
		foreach ( (array) glob( AAFM_PLUGIN_DIR . 'includes/siteops/redirects/*.php' ) as $path ) {
			$slug   = str_replace( '-', '_', basename( (string) $path, '.php' ) );
			$source = (string) file_get_contents( (string) $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This plugin's own local source.
			preg_match_all( '/^function\s+([a-z0-9_]+)\s*\(/m', $source, $found );
			$expected = array();
			foreach ( array( 'ready', 'can', 'all', 'match', 'list_404s' ) as $name ) {
				$expected[] = 'aafm_redirect_' . $slug . '_' . $name;
			}
			$this->assertSame( $expected, $found[1], basename( (string) $path ) . ' must define its five adapter functions, in order, and nothing else.' );
			$this->assertArrayHasKey( $slug, aafm_site_redirect_adapters(), basename( (string) $path ) . ' has no registry entry.' );
		}
		$this->addToAssertionCount( 1 );
	}
}
