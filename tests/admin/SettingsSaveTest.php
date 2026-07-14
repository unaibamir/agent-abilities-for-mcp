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
		// All-invalid input collapses to an empty (allow-all) list - the dangerous case.
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

	public function test_retention_days_getter_clamps(): void {
		update_option( 'aafm_log_retention_days', 30 );
		$this->assertSame( 30, aafm_log_retention_days() );
		update_option( 'aafm_log_retention_days', -5 );
		$this->assertSame( 0, aafm_log_retention_days() ); // 0 = keep forever.
		update_option( 'aafm_log_retention_days', 99999 );
		$this->assertSame( 3650, aafm_log_retention_days() );
		delete_option( 'aafm_log_retention_days' );
		$this->assertSame( 30, aafm_log_retention_days() ); // Default.
	}

	public function test_settings_sanitizer_bounds_retention_days(): void {
		$this->assertSame( 30, aafm_sanitize_settings_input( array() )['aafm_log_retention_days'] );
		$this->assertSame( 0, aafm_sanitize_settings_input( array( 'aafm_log_retention_days' => '-5' ) )['aafm_log_retention_days'] );
		$this->assertSame( 3650, aafm_sanitize_settings_input( array( 'aafm_log_retention_days' => '99999' ) )['aafm_log_retention_days'] );
		$this->assertSame( 14, aafm_sanitize_settings_input( array( 'aafm_log_retention_days' => '14' ) )['aafm_log_retention_days'] );
	}

	public function test_settings_render_uses_warning_notice(): void {
		ob_start();
		aafm_render_settings_tab();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="aafm_rate_limit_per_min"', $html );
		$this->assertStringContainsString( 'name="aafm_ip_allowlist"', $html );
		$this->assertStringContainsString( 'name="aafm_force_draft"', $html );
		$this->assertStringContainsString( 'name="aafm_max_title_len"', $html );
		$this->assertStringContainsString( 'name="aafm_log_retention_days"', $html );
		$this->assertStringContainsString( 'aafm-notice-warning', $html );
		$this->assertStringContainsString( 'id="aafm-settings-form"', $html );
		$this->assertStringContainsString( 'aafm-set-row', $html );
		$this->assertStringContainsString( 'aafm-switch', $html );
	}

	public function test_settings_render_wraps_groups_in_section_component(): void {
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		// The three groups (Safety controls / OAuth / Danger zone) each render through
		// the shared aafm_render_section() component, so the class appears three times.
		$this->assertSame( 3, substr_count( $html, 'aafm-section aafm-card' ) );

		// Every frozen-contract input name survives the migration unchanged.
		foreach (
			array(
				'aafm_rate_limit_per_min',
				'aafm_max_title_len',
				'aafm_log_retention_days',
				'aafm_force_draft',
				'aafm_oauth_enabled',
				'aafm_oauth_dcr_enabled',
				'aafm_ip_allowlist',
			) as $name
		) {
			$this->assertStringContainsString( 'name="' . $name . '"', $html );
		}

		// No stray empty card: every card-pad body holds real markup (the Wave-4
		// empty-card defect class). An empty body would render the two tags back to back.
		$this->assertStringNotContainsString( 'aafm-section-body"></div>', $html );

		// The frozen AJAX/option-key contract is preserved via the unchanged save action.
		$this->assertStringContainsString( 'id="aafm-settings-form"', $html );
	}

	/**
	 * The Settings save handler (#bindSaveSettings in admin.js) builds its POST body field by
	 * field rather than serializing the whole form, so every checkbox has to be forwarded
	 * explicitly. It once forwarded only force-draft, delete-on-uninstall, and the numeric
	 * fields, leaving out the OAuth, DCR, and strict-block toggles. Because the server reads an
	 * absent checkbox as off, that omission made every save silently write those three toggles
	 * off - so turning OAuth on and saving left it off on reload. This locks the payload contract
	 * so a dropped checkbox is caught here instead of on a live site.
	 */
	public function test_settings_save_script_forwards_every_checkbox(): void {
		$path = AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test, not a remote URL.
		$js = (string) file_get_contents( $path );

		// Isolate the settings-save handler so a field forwarded by a different handler cannot
		// mask a regression here.
		// Anchor on the method definition token ('() {'), not the bare call site 'this.#bind…();'.
		$start = strpos( $js, '#bindSaveSettings() {' );
		$end   = strpos( $js, '#bindMetaChips() {' );
		$this->assertNotFalse( $start, '#bindSaveSettings handler not found in admin.js.' );
		$this->assertNotFalse( $end, 'Could not bound the #bindSaveSettings handler.' );
		$handler = substr( $js, (int) $start, (int) $end - (int) $start );

		foreach (
			array(
				'aafm_oauth_enabled',
				'aafm_oauth_dcr_enabled',
				'aafm_block_guard_strict',
			) as $field
		) {
			$this->assertStringContainsString(
				"body.append( '" . $field . "', '1' )",
				$handler,
				$field . ' is not forwarded by the settings save handler, so saving would reset it off.'
			);
		}
	}

	/**
	 * Server-side round-trip contract behind the fix above: with the checkbox present the
	 * sanitizer keeps the toggle on; with it absent (the payload the pre-fix script sent) it
	 * coerces off. Documents both sides of the failure mode so the "absent -> off" semantics is
	 * not accidentally loosened while fixing the payload.
	 */
	public function test_settings_sanitizer_round_trips_oauth_and_block_guard_toggles(): void {
		$on = aafm_sanitize_settings_input(
			array(
				'aafm_oauth_enabled'      => '1',
				'aafm_oauth_dcr_enabled'  => '1',
				'aafm_block_guard_strict' => '1',
			)
		);
		$this->assertSame( '1', $on['aafm_oauth_enabled'] );
		$this->assertSame( '1', $on['aafm_oauth_dcr_enabled'] );
		$this->assertTrue( $on['aafm_block_guard_strict'] );

		$off = aafm_sanitize_settings_input( array() );
		$this->assertSame( '0', $off['aafm_oauth_enabled'] );
		$this->assertSame( '0', $off['aafm_oauth_dcr_enabled'] );
		$this->assertFalse( $off['aafm_block_guard_strict'] );
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
