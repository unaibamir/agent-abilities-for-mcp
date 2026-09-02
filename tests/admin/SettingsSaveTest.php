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

		// The three groups (Safety controls / OAuth / Danger zone) each carry the shared card
		// classes, so the pair appears three times. Read-only mode and the high-risk switch used to
		// be two more cards of their own; they are rows inside Safety controls now.
		$this->assertSame( 3, substr_count( $html, 'aafm-section aafm-card' ) );

		// Every frozen-contract input name survives the migration unchanged.
		foreach (
			array(
				'aafm_rate_limit_per_min',
				'aafm_max_title_len',
				'aafm_log_retention_days',
				'aafm_force_draft',
				'aafm_oauth_enabled',
				'aafm_ip_allowlist',
			) as $name
		) {
			$this->assertStringContainsString( 'name="' . $name . '"', $html );
		}

		// The separate DCR toggle was removed - DCR follows OAuth now, so no such input renders.
		$this->assertStringNotContainsString( 'name="aafm_oauth_dcr_enabled"', $html );

		// No stray empty card: every card-pad body holds real markup (the Wave-4
		// empty-card defect class). An empty body would render the two tags back to back.
		$this->assertStringNotContainsString( 'aafm-section-body"></div>', $html );

		// The frozen AJAX/option-key contract is preserved via the unchanged save action.
		$this->assertStringContainsString( 'id="aafm-settings-form"', $html );
	}

	/**
	 * OAuth leads the tab: connecting an agent is the first thing an operator does here, and the
	 * safety controls only start mattering once something is connected. The Danger zone stays last.
	 * The "these safety controls are optional" caption travels with the card it names rather than
	 * sitting at the head of the tab introducing OAuth.
	 */
	public function test_oauth_renders_above_safety_controls(): void {
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		$oauth_at   = strpos( $html, 'aafm-card-head-title">OAuth' );
		$caption_at = strpos( $html, 'These safety controls are optional' );
		$safety_at  = strpos( $html, 'aafm-card-head-title">Safety controls' );
		$danger_at  = strpos( $html, 'aafm-card-head-title">Danger zone' );

		$this->assertNotFalse( $oauth_at, 'The OAuth card is missing from the Settings tab.' );
		$this->assertNotFalse( $caption_at, 'The safety-controls caption is missing from the Settings tab.' );
		$this->assertNotFalse( $safety_at, 'The Safety controls card is missing from the Settings tab.' );
		$this->assertNotFalse( $danger_at, 'The Danger zone card is missing from the Settings tab.' );

		$this->assertLessThan( $safety_at, $oauth_at, 'OAuth must be the first card on the Settings tab.' );
		$this->assertLessThan( $safety_at, $caption_at, 'The caption must introduce the Safety controls card.' );
		$this->assertLessThan( $caption_at, $oauth_at, 'The caption belongs below OAuth, not above it.' );
		$this->assertLessThan( $danger_at, $safety_at, 'The Danger zone stays last.' );

		// The OAuth switch moves with the card and stays inside the form: admin.js reads it off
		// #aafm-settings-form, so a card that drifted past </form> would post nothing and the toggle
		// would silently save off.
		$form_end = strpos( $html, '</form>' );
		$this->assertNotFalse( $form_end );
		$at = strpos( $html, 'name="aafm_oauth_enabled"' );
		$this->assertNotFalse( $at, 'aafm_oauth_enabled is missing from the Settings tab.' );
		$this->assertLessThan( $safety_at, $at, 'aafm_oauth_enabled must render inside the OAuth card above Safety controls.' );
		$this->assertLessThan( $form_end, $at, 'aafm_oauth_enabled must sit inside #aafm-settings-form.' );
	}

	/**
	 * One Save on the tab, in the shared sticky bar, inside the form. Two save controls would mean
	 * two bindings and two status elements to keep in step, and this tab has already shipped one
	 * bug from a hand-maintained parallel list drifting. The button classes and the
	 * .aafm-save-status span are what admin.js binds to, so they are pinned here too.
	 */
	public function test_the_single_save_control_sits_in_the_sticky_bar_inside_the_form(): void {
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'type="submit"' ), 'The Settings tab carries exactly one submit button.' );
		$this->assertSame( 1, substr_count( $html, 'aafm-save-status' ), 'One save control means one status element.' );
		$this->assertSame( 1, substr_count( $html, 'aafm-savebar' ), 'The save bar renders once.' );

		$bar_at   = strpos( $html, 'aafm-savebar' );
		$form_end = strpos( $html, '</form>' );
		$this->assertNotFalse( $bar_at );
		$this->assertNotFalse( $form_end );
		$this->assertLessThan( $form_end, $bar_at, 'The save bar must stay inside #aafm-settings-form or submitting breaks.' );

		// The button keeps its exact class contract.
		$bar = substr( $html, (int) $bar_at, $form_end - (int) $bar_at );
		$this->assertStringContainsString( 'class="aafm-btn aafm-btn-primary"', $bar );
		$this->assertStringContainsString( '<span class="aafm-save-status" aria-live="polite">', $bar );
	}

	/**
	 * The Settings save handler (#bindSaveSettings in admin.js) builds its POST body field by
	 * field rather than serializing the whole form, so every checkbox has to be forwarded
	 * explicitly. It once forwarded only force-draft, delete-on-uninstall, and the numeric
	 * fields, leaving out the OAuth and strict-block toggles. Because the server reads an absent
	 * checkbox as off, that omission made every save silently write those toggles off - so turning
	 * OAuth on and saving left it off on reload. This locks the payload contract so a dropped
	 * checkbox is caught here instead of on a live site. (DCR is no longer a checkbox here - it
	 * follows OAuth - so it is not in the forwarded set.)
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
				'aafm_block_guard_strict',
				'aafm_high_risk_abilities_unlocked',
			) as $field
		) {
			$this->assertStringContainsString(
				"body.append( '" . $field . "', '1' )",
				$handler,
				$field . ' is not forwarded by the settings save handler, so saving would reset it off.'
			);
		}

		// The DCR checkbox was removed; the handler must not forward it any more.
		$this->assertStringNotContainsString(
			"body.append( 'aafm_oauth_dcr_enabled'",
			$handler,
			'DCR follows OAuth now; the save handler must not forward a DCR field.'
		);
	}

	/**
	 * Server-side round-trip contract behind the fix above: with the checkbox present the
	 * sanitizer keeps the toggle on; with it absent (the payload the pre-fix script sent) it
	 * coerces off. Documents both sides of the failure mode so the "absent -> off" semantics is
	 * not accidentally loosened while fixing the payload. DCR is no longer a sanitized field.
	 */
	public function test_settings_sanitizer_round_trips_oauth_and_block_guard_toggles(): void {
		$on = aafm_sanitize_settings_input(
			array(
				'aafm_oauth_enabled'      => '1',
				'aafm_block_guard_strict' => '1',
			)
		);
		$this->assertSame( '1', $on['aafm_oauth_enabled'] );
		$this->assertTrue( $on['aafm_block_guard_strict'] );
		$this->assertArrayNotHasKey( 'aafm_oauth_dcr_enabled', $on, 'DCR is no longer a sanitized settings field.' );

		$off = aafm_sanitize_settings_input( array() );
		$this->assertSame( '0', $off['aafm_oauth_enabled'] );
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

	public function set_up(): void {
		parent::set_up();
		// The AJAX handler logs every flip of the high-risk switch (aafm_log_high_risk_switch_change()),
		// so the activity-log table needs to exist for the two AJAX tests below, mirroring
		// AbilityToggleAuditTest's set_up.
		aafm_install_activity_log();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'], $_POST['aafm_high_risk_abilities_unlocked'] );
		parent::tear_down();
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 * Mirrors BridgeDirectorySaveTest::intercept_die().
	 *
	 * @return void
	 */
	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload.
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Turning the master switch off through the Settings AJAX handler must delete the option row
	 * rather than store a falsy value - the fresh-install default is an absent row, and the
	 * plugin's own UI otherwise has no way back to it once the switch has been touched once. The
	 * sentinel default on get_option() is what actually proves this: a stored empty string or a
	 * stored false would both read back as falsy against a plain `false` default, but only a
	 * genuinely absent row fails to override a sentinel the option could never legitimately hold.
	 */
	public function test_ajax_save_settings_deletes_high_risk_option_when_switch_turned_off(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_high_risk_abilities_unlocked', true ); // Start unlocked.
		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		// The switch's checkbox is absent from $_POST entirely - exactly what a browser sends
		// when an unchecked checkbox is submitted.

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame(
			'MISSING',
			get_option( 'aafm_high_risk_abilities_unlocked', 'MISSING' ),
			'Turning the switch off must delete the option row, not store a falsy value.'
		);
		$this->assertFalse( get_option( 'aafm_high_risk_abilities_unlocked', false ) );
	}

	/**
	 * The other side of the fix above: turning the switch on must still store an explicit true, not
	 * also be swept into a delete.
	 */
	public function test_ajax_save_settings_keeps_high_risk_option_stored_true_when_switch_on(): void {
		$this->acting_as( 'administrator' );
		$this->intercept_die();
		$nonce                                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']                             = $nonce;
		$_REQUEST['nonce']                          = $nonce;
		$_POST['aafm_high_risk_abilities_unlocked'] = '1';

		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertTrue( get_option( 'aafm_high_risk_abilities_unlocked', false ) );
	}

	/**
	 * Every field the Settings tab renders must be forwarded by the save payload in admin.js.
	 *
	 * The save handler does not serialise the form: admin.js names each field by hand and appends
	 * it. A field that is rendered but never appended is not merely unsaved, it is actively saved
	 * OFF, because aafm_sanitize_settings_input() reads an absent checkbox as false and the write
	 * that follows then persists that false. So the switch appears to do nothing, and any later
	 * save on that tab silently clears it.
	 *
	 * This has now happened twice: first to the OAuth, DCR and strict-block toggles, then to
	 * aafm_read_only_mode in 1.6.0, which shipped through a full test suite and five review lanes
	 * because every test on that surface asserted the PHP half only. Asserting the checkbox is
	 * "inside the form" proves nothing here, since nothing reads the form.
	 *
	 * Pinning the two halves against each other is the only thing that catches it, so this test
	 * derives the field list from the renderer rather than restating it.
	 */
	public function test_every_rendered_settings_field_is_forwarded_by_the_save_payload(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin source off disk, never a remote URL.
		$php = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/settings.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin asset off disk, never a remote URL.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );

		$this->assertSame(
			1,
			preg_match_all( '/name="(aafm_[a-z_]+)"/', $php, $matches ) > 0 ? 1 : 0,
			'The settings renderer should emit at least one named field.'
		);

		$rendered = array_values( array_unique( $matches[1] ) );
		$this->assertContains(
			'aafm_read_only_mode',
			$rendered,
			'Guard against this test silently passing if the switch is ever removed from the renderer.'
		);

		foreach ( $rendered as $field ) {
			$this->assertStringContainsString(
				"'" . $field . "'",
				$js,
				sprintf(
					'The Settings tab renders %s but admin.js never appends it to the save payload, so saving that tab writes it off.',
					$field
				)
			);
		}
	}
}
