<?php
/**
 * Reusable admin notice component: variant mapping, escaping, and the html/icon args.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

// Notices render inline SVGs from oversio_icon(); load both helpers directly.
require_once OVERSIO_PLUGIN_DIR . 'includes/admin/icons.php';
require_once OVERSIO_PLUGIN_DIR . 'includes/admin/notices.php';

final class NoticesTest extends TestCase {

	public function test_each_variant_maps_to_class_and_svg_icon(): void {
		// Each variant renders its variant class and an inline SVG icon (no Dashicons).
		$variants = array( 'warning', 'info', 'success', 'error' );
		foreach ( $variants as $variant ) {
			$html = oversio_get_notice_html( $variant, 'Hello' );
			$this->assertStringContainsString( 'oversio-notice-' . $variant, $html );
			$this->assertStringContainsString( 'oversio-notice-ic', $html );
			$this->assertStringContainsString( '<svg class="oversio-icon"', $html );
			$this->assertStringNotContainsString( 'dashicons', $html );
			$this->assertStringContainsString( 'Hello', $html );
		}
	}

	public function test_unknown_variant_falls_back_to_info(): void {
		$html = oversio_get_notice_html( 'explode', 'x' );
		$this->assertStringContainsString( 'oversio-notice-info', $html );
		$this->assertStringContainsString( '<svg class="oversio-icon"', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	public function test_message_is_escaped_by_default(): void {
		$html = oversio_get_notice_html( 'info', '<script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_html_arg_passes_prebuilt_markup_through(): void {
		$html = oversio_get_notice_html( 'info', '<a href="#">link</a>', array( 'html' => true ) );
		$this->assertStringContainsString( '<a href="#">link</a>', $html );
	}

	public function test_icon_override_is_honored(): void {
		// The icon arg swaps the glyph to the named oversio_icon; the shield path is distinctive.
		$shield = oversio_icon( 'shield' );
		$html   = oversio_get_notice_html( 'info', 'x', array( 'icon' => 'shield' ) );
		$this->assertStringContainsString( $shield, $html );
	}

	public function test_legacy_dashicon_arg_maps_to_svg(): void {
		// Back-compat: the old dashicon override name maps to the closest oversio_icon glyph.
		$shield = oversio_icon( 'shield' );
		$html   = oversio_get_notice_html( 'info', 'x', array( 'dashicon' => 'dashicons-shield' ) );
		$this->assertStringContainsString( $shield, $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
	}
}
