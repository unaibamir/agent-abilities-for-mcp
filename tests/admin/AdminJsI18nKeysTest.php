<?php
/**
 * Every admin.js string looked up by key has that key in a localized PHP array, so it reaches the
 * POT. A key with no PHP entry falls back to the hard-coded English and can never be translated.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AdminJsI18nKeysTest extends TestCase {

	public function test_every_literal_js_key_is_localized(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		preg_match_all( "/#t\\(\\s*'([A-Za-z0-9_]+)'/", $js, $found );
		$keys = array_values( array_unique( $found[1] ) );
		$this->assertGreaterThan( 100, count( $keys ), 'The key scan found too few lookups to be reading admin.js.' );

		$php = '';
		foreach ( (array) glob( AAFM_PLUGIN_DIR . 'includes/admin/*.php' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
			$php .= (string) file_get_contents( (string) $file );
		}

		$missing = array_values(
			array_filter(
				$keys,
				static fn( string $key ): bool => 1 !== preg_match( "/'" . preg_quote( $key, '/' ) . "'\\s*=>/", $php )
			)
		);
		$this->assertSame( array(), $missing );
	}
}
