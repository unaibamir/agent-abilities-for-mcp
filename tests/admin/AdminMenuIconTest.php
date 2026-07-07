<?php
/**
 * The top-level admin menu icon resolves to the shipped monochrome brand SVG by URL.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AdminMenuIconTest extends TestCase {

	public function test_menu_icon_is_the_shipped_svg_url(): void {
		$icon = aafm_admin_menu_icon();

		// A plain asset URL (WordPress renders it as an <img>), not a base64 data URI - the
		// source-tree security guard forbids base64/file reads for this.
		$this->assertStringEndsWith( 'assets/wp-admin-icon.svg', $icon );
		$this->assertStringStartsNotWith( 'data:', $icon );
		$this->assertStringStartsNotWith( 'dashicons-', $icon );
	}

	public function test_shipped_admin_icon_is_monochrome(): void {
		// The menu reads this at runtime; it must ship (not live only in .wordpress-org),
		// and it must be the monochrome mark - a colored #2271b1 square blends into the
		// selected-item background.
		$path = AAFM_PLUGIN_DIR . 'assets/wp-admin-icon.svg';
		$this->assertFileExists( $path );

		// Reading a bundled test asset (tests/ is not part of the source-tree security scan).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$svg = (string) file_get_contents( $path );
		$this->assertStringContainsString( '<svg', $svg );
		// The colored app icon is a filled <rect> square; the menu mark is shield-only.
		$this->assertStringNotContainsString( '<rect', $svg, 'Menu icon must not carry the app-icon square.' );
	}
}
