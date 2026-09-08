<?php
/**
 * Reusable admin notice component: variant mapping, escaping, and the html/icon args.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

// Notices render inline SVGs from aafm_icon(); load both helpers directly.
require_once AAFM_PLUGIN_DIR . 'includes/admin/icons.php';
require_once AAFM_PLUGIN_DIR . 'includes/admin/notices.php';

final class NoticesTest extends TestCase {

	public function test_each_variant_maps_to_class_and_svg_icon(): void {
		// Each variant renders its variant class and an inline SVG icon (no Dashicons).
		$variants = array( 'warning', 'info', 'success', 'error' );
		foreach ( $variants as $variant ) {
			$html = aafm_get_notice_html( $variant, 'Hello' );
			$this->assertStringContainsString( 'aafm-notice-' . $variant, $html );
			$this->assertStringContainsString( 'aafm-notice-ic', $html );
			$this->assertStringContainsString( '<svg class="aafm-icon"', $html );
			$this->assertStringNotContainsString( 'dashicons', $html );
			$this->assertStringContainsString( 'Hello', $html );
		}
	}

	public function test_unknown_variant_falls_back_to_info(): void {
		$html = aafm_get_notice_html( 'explode', 'x' );
		$this->assertStringContainsString( 'aafm-notice-info', $html );
		$this->assertStringContainsString( '<svg class="aafm-icon"', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	public function test_message_is_escaped_by_default(): void {
		$html = aafm_get_notice_html( 'info', '<script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_html_arg_passes_prebuilt_markup_through(): void {
		$html = aafm_get_notice_html( 'info', '<a href="#">link</a>', array( 'html' => true ) );
		$this->assertStringContainsString( '<a href="#">link</a>', $html );
	}

	public function test_icon_override_is_honored(): void {
		// The icon arg swaps the glyph to the named aafm_icon; the shield path is distinctive.
		// The notice embeds the icon through wp_kses() (escape-late), so compare against the
		// same escaped form - the distinctive shield path survives kses unchanged.
		$shield = wp_kses( aafm_icon( 'shield' ), aafm_svg_allowed_html() );
		$html   = aafm_get_notice_html( 'info', 'x', array( 'icon' => 'shield' ) );
		$this->assertStringContainsString( $shield, $html );
	}

	public function test_get_notice_html_appends_an_extra_class_to_the_wrapper(): void {
		$html = aafm_get_notice_html( 'info', 'A message.', array( 'class' => 'aafm-os-note' ) );

		$this->assertStringContainsString( 'aafm-notice aafm-notice-info aafm-os-note"', $html );
	}

	public function test_get_notice_html_sanitizes_a_hostile_class_argument(): void {
		// aafm_get_notice_html() documents a single extra class via sanitize_html_class(),
		// which strips anything that isn't a valid single CSS class token - prove a
		// multi-token/hostile value collapses to something safe rather than injecting markup.
		$html = aafm_get_notice_html( 'info', 'A message.', array( 'class' => '"><script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		// The literal injection pattern (closing the class attribute's quote early, then closing
		// the tag) never survives - only alphanumeric/underscore/hyphen characters remain in the
		// class token, so a plain "><, which any two adjacent normal HTML tags also produce, is
		// not itself proof of anything; the real proof is that the hostile "><script sequence
		// specifically is gone.
		$this->assertStringNotContainsString( '"><script', $html );
	}
}
