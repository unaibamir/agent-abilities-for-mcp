<?php
/**
 * Reusable admin notice component: variant mapping, escaping, and the html/dashicon args.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

// The bootstrap require for notices.php lands in Task 2, so load it directly here.
require_once AAFM_PLUGIN_DIR . 'includes/admin/notices.php';

final class NoticesTest extends TestCase {

	public function test_each_variant_maps_to_class_and_dashicon(): void {
		$map = array(
			'warning' => 'dashicons-warning',
			'info'    => 'dashicons-info',
			'success' => 'dashicons-yes-alt',
			'error'   => 'dashicons-dismiss',
		);
		foreach ( $map as $variant => $icon ) {
			$html = aafm_get_notice_html( $variant, 'Hello' );
			$this->assertStringContainsString( 'aafm-notice-' . $variant, $html );
			$this->assertStringContainsString( $icon, $html );
			$this->assertStringContainsString( 'Hello', $html );
		}
	}

	public function test_unknown_variant_falls_back_to_info(): void {
		$html = aafm_get_notice_html( 'explode', 'x' );
		$this->assertStringContainsString( 'aafm-notice-info', $html );
		$this->assertStringContainsString( 'dashicons-info', $html );
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

	public function test_dashicon_override_is_honored(): void {
		$html = aafm_get_notice_html( 'info', 'x', array( 'dashicon' => 'dashicons-shield' ) );
		$this->assertStringContainsString( 'dashicons-shield', $html );
	}
}
