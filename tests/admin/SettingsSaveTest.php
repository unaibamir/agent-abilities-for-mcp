<?php
/**
 * Settings tab: sanitizer bounds, IP validation, force-draft default, and render coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class SettingsSaveTest extends TestCase {

	public function test_settings_sanitizer_bounds_and_validates_ips(): void {
		$out = aafm_sanitize_settings_input(
			array(
				'aafm_rate_limit_per_min' => '-3',
				'aafm_max_title_len'      => '99999999',
				'aafm_force_draft'        => '1',
				'aafm_ip_allowlist'       => "10.0.0.1\nnot-an-ip\n192.168.0.0/24\n",
			)
		);
		$this->assertSame( 0, $out['aafm_rate_limit_per_min'] );            // Clamped from negative.
		$this->assertSame( 100000, $out['aafm_max_title_len'] );           // Clamped to exact upper bound.
		$this->assertTrue( $out['aafm_force_draft'] );
		$this->assertSame( array( '10.0.0.1', '192.168.0.0/24' ), $out['aafm_ip_allowlist'] ); // Invalid line dropped.
	}

	public function test_settings_sanitizer_dedups_allowlist(): void {
		$out = aafm_sanitize_settings_input(
			array(
				'aafm_ip_allowlist' => "10.0.0.1\n10.0.0.1\n10.0.0.2",
			)
		);
		$this->assertSame( array( '10.0.0.1', '10.0.0.2' ), $out['aafm_ip_allowlist'] );
	}

	public function test_settings_sanitizer_force_draft_unchecked_is_false(): void {
		$out = aafm_sanitize_settings_input( array() ); // No force_draft key -> false.
		$this->assertFalse( $out['aafm_force_draft'] );
		$this->assertSame( 0, $out['aafm_rate_limit_per_min'] );
		$this->assertSame( array(), $out['aafm_ip_allowlist'] );
	}

	public function test_settings_sanitizer_keeps_valid_ipv6_and_cidr(): void {
		$out = aafm_sanitize_settings_input(
			array( 'aafm_ip_allowlist' => "2001:db8::1\n2001:db8::/32\n10.0.0.0/8\n203.0.113.5" )
		);
		$this->assertSame(
			array( '2001:db8::1', '2001:db8::/32', '10.0.0.0/8', '203.0.113.5' ),
			$out['aafm_ip_allowlist']
		);
	}

	public function test_settings_sanitizer_drops_out_of_range_prefix(): void {
		$out = aafm_sanitize_settings_input(
			array( 'aafm_ip_allowlist' => "10.0.0.0/33\n10.0.0.0/abc\n10.0.0.0/24" )
		);
		$this->assertSame( array( '10.0.0.0/24' ), $out['aafm_ip_allowlist'] );
	}

	public function test_settings_sanitizer_reports_all_invalid_collapses_to_empty(): void {
		// All-invalid input collapses to an empty (allow-all) list — the dangerous case.
		$out = aafm_sanitize_settings_input(
			array(
				'aafm_ip_allowlist' => "garbage\nnot-an-ip\n10.0.0.0/99",
			)
		);
		$this->assertSame( array(), $out['aafm_ip_allowlist'] );
	}

	public function test_dropped_ip_line_count(): void {
		$this->assertSame( 2, aafm_count_dropped_ip_lines( "10.0.0.1\ngarbage\n192.168.0.0/24\nbad/99" ) );
		$this->assertSame( 0, aafm_count_dropped_ip_lines( "10.0.0.1\n192.168.0.0/24" ) );
		$this->assertSame( 0, aafm_count_dropped_ip_lines( '' ) );
	}

	public function test_settings_render_uses_warning_notice(): void {
		ob_start();
		aafm_render_settings_tab();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="aafm_rate_limit_per_min"', $html );
		$this->assertStringContainsString( 'name="aafm_ip_allowlist"', $html );
		$this->assertStringContainsString( 'name="aafm_force_draft"', $html );
		$this->assertStringContainsString( 'name="aafm_max_title_len"', $html );
		$this->assertStringContainsString( 'aafm-notice-warning', $html );
		$this->assertStringContainsString( 'id="aafm-settings-form"', $html );
	}

	public function test_is_valid_ip_or_cidr_accepts_and_rejects(): void {
		$this->assertTrue( aafm_is_valid_ip_or_cidr( '10.0.0.1' ) );
		$this->assertTrue( aafm_is_valid_ip_or_cidr( '192.168.0.0/24' ) );
		$this->assertTrue( aafm_is_valid_ip_or_cidr( '2001:db8::/32' ) );
		$this->assertFalse( aafm_is_valid_ip_or_cidr( 'not-an-ip' ) );
		$this->assertFalse( aafm_is_valid_ip_or_cidr( '10.0.0.0/33' ) );
		$this->assertFalse( aafm_is_valid_ip_or_cidr( '10.0.0.0/' ) );
		$this->assertFalse( aafm_is_valid_ip_or_cidr( '' ) );
	}
}
