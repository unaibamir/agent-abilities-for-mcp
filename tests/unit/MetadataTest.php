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

	public function test_requires_php_74(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( '7.4', $this->plugin_headers()['RequiresPHP'] );
	}

	public function test_version_constant_matches_header(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( $this->plugin_headers()['Version'], AAFM_VERSION );
	}

	public function test_release_version_is_one_six_three(): void {
		$this->assertSame( '1.6.3', AAFM_VERSION );
	}

	public function test_readme_stable_tag_matches_version(): void {
		// Reading our own bundled readme from a local path - not a remote fetch.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $matches ) );
		$this->assertSame( AAFM_VERSION, trim( $matches[1] ) );
	}

	/**
	 * README.md's Stable tag row is the GitHub-facing half of the same fact readme.txt carries,
	 * and README.md is the file that has actually drifted before: it was still calling ChatGPT
	 * unsupported two releases after that shipped, which is what forced 1.2.1.
	 */
	public function test_readme_md_stable_tag_matches_version(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'README.md' );
		$this->assertSame( 1, preg_match( '/^\|\s*\*\*Stable tag\*\*\s*\|\s*(.+?)\s*\|$/m', $readme, $matches ) );
		$this->assertSame( AAFM_VERSION, trim( $matches[1] ) );
	}

	/**
	 * The MCP registry manifest, server.json, is the other unguarded pin. The release workflow
	 * rewrites the published value with jq at publish time, so the registry itself is never
	 * wrong, but the repo literal only earns a non-failing ::warning:: when it drifts, which
	 * means it can sit stale for a whole release cycle with nothing stopping it.
	 */
	public function test_server_json_version_matches_version(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = json_decode( (string) file_get_contents( AAFM_PLUGIN_DIR . 'server.json' ), true );
		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'version', $manifest );
		$this->assertSame( AAFM_VERSION, $manifest['version'] );
	}

	/**
	 * B6: the blanket "argument values are never stored" claim is false and has been since
	 * activity-log v5 (1.5.0): aafm_activity_detail_field() stores ids, meta key names, slugs,
	 * and enum members in the detail column by design, and several of those arrive as argument
	 * values. The honest claim is that free-text argument content (bodies, emails, anything
	 * written) is never stored, and identifiers are. Pin both readmes so the blanket wording
	 * cannot come back.
	 */
	public function test_readmes_do_not_overpromise_about_stored_argument_values(): void {
		foreach ( array( 'readme.txt', 'README.md' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$text = (string) file_get_contents( AAFM_PLUGIN_DIR . $file );
			$this->assertDoesNotMatchRegularExpression(
				'/values are (still )?never stored/i',
				$text,
				"{$file} must not carry the blanket never-stored claim the detail column disproves."
			);
			$this->assertStringNotContainsString(
				'never the values',
				$text,
				"{$file} must not carry the blanket never-the-values claim."
			);
			$this->assertStringContainsString(
				'Free-text argument content',
				$text,
				"{$file} must state the honest scope: free text is never stored, identifiers are."
			);
		}
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
