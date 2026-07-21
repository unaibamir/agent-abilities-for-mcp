<?php
/**
 * Asserts the plugin headers declare the correct minimum environment.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class MetadataTest extends TestCase {

	private function plugin_headers(): array {
		return get_plugin_data( AAFM_PLUGIN_FILE, false, false );
	}

	public function test_requires_at_least_wp_69(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( '6.9', $this->plugin_headers()['RequiresWP'] );
	}

	public function test_requires_php_80(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( '8.0', $this->plugin_headers()['RequiresPHP'] );
	}

	public function test_version_constant_matches_header(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( $this->plugin_headers()['Version'], AAFM_VERSION );
	}

	public function test_release_version_is_one_four_zero(): void {
		$this->assertSame( '1.4.0', AAFM_VERSION );
	}

	public function test_readme_stable_tag_matches_version(): void {
		// Reading our own bundled readme from a local path - not a remote fetch.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $matches ) );
		$this->assertSame( AAFM_VERSION, trim( $matches[1] ) );
	}

	public function test_readme_core_ability_count_is_not_drifted(): void {
		// The changelog advertises the core-ability count in digits ("NN across WordPress core").
		// Assert it against the live count so the readme can never silently fall out of sync again.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/(\d+) across WordPress core/', $readme, $matches ) );
		$this->assertSame( aafm_core_ability_count(), (int) $matches[1] );
	}
}
