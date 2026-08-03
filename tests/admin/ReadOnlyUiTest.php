<?php
/**
 * Read-only mode on screen: the locked ability row, the posture pill in the page header, the
 * Settings switch, and the "Enable all reads" bulk control.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ReadOnlyUiTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
	}

	/**
	 * Render one Abilities-tab ability row and return its markup.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	private function render_row( string $name ): string {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( $name, $registry, "Unknown ability: $name" );

		ob_start();
		aafm_render_ability_row(
			array( 'name' => $name ) + $registry[ $name ],
			array(),
			aafm_ability_disclosures()
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render one Integrations-tab ability row and return its markup.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	private function render_integration_row( string $name ): string {
		$registry = aafm_get_abilities_registry_full();
		$this->assertArrayHasKey( $name, $registry, "Unknown ability: $name" );

		ob_start();
		aafm_render_integration_ability_row(
			array( 'name' => $name ) + $registry[ $name ],
			array(),
			aafm_ability_disclosures()
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Settings tab and return its markup.
	 *
	 * @return string
	 */
	private function render_settings_tab(): string {
		ob_start();
		aafm_render_settings_tab();
		return (string) ob_get_clean();
	}

	/**
	 * Render the whole plugin page (header, tab nav, active tab) as an administrator.
	 *
	 * @return string
	 */
	private function render_admin_page(): string {
		$this->acting_as( 'administrator' );
		// The wizard renders over the page on a first-run install and drags a lot of unrelated
		// markup in with it; this file is about the header band, so close it the way an operator
		// who has finished setup would have.
		update_option( 'aafm_quickconnect_finished', '1' );

		ob_start();
		aafm_render_admin_page();
		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------------------------------
	// The locked row.
	// ---------------------------------------------------------------------------------------

	/**
	 * Plan 149 test 5, and the load-bearing half of spec 1.7. "No checkbox" rather than "disabled
	 * checkbox" is not cosmetic: the bulk enable-all control queries
	 * input[type="checkbox"][name="aafm_abilities[]"] inside the card, so a greyed-out but present
	 * input would still be swept in by it. That is the 1.5.0 bug.
	 */
	public function test_a_write_row_renders_no_checkbox_while_the_mode_is_on(): void {
		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_row( 'aafm/create-post' );

		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'aafm-ability-locked', $html );
	}

	public function test_a_locked_write_row_points_at_read_only_not_high_risk(): void {
		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_row( 'aafm/create-post' );

		$this->assertStringContainsString( 'Read-only mode', $html );
		$this->assertStringNotContainsString( 'Turn on High-risk abilities', $html );
	}

	public function test_a_read_row_still_renders_its_checkbox(): void {
		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_row( 'aafm/get-posts' );

		$this->assertStringContainsString( 'name="aafm_abilities[]" value="aafm/get-posts"', $html );
		$this->assertStringNotContainsString( 'aafm-ability-locked', $html );
	}

	public function test_a_write_row_is_unchanged_while_the_mode_is_off(): void {
		$html = $this->render_row( 'aafm/create-post' );

		$this->assertStringContainsString( 'name="aafm_abilities[]" value="aafm/create-post"', $html );
		$this->assertStringNotContainsString( 'aafm-ability-locked', $html );
	}

	/**
	 * The Integrations renderer is a separate call site, and it is the one that shipped without a
	 * lock branch in 1.5.0. Both surfaces go through aafm_render_ability_toggle_control() now, so
	 * assert the second one directly rather than trusting the shared helper by inspection.
	 */
	public function test_the_integrations_renderer_locks_a_write_too(): void {
		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_integration_row( 'aafm/wc-list-orders' );
		$this->assertStringContainsString( 'name="aafm_abilities[]" value="aafm/wc-list-orders"', $html, 'A WooCommerce read stays selectable.' );

		$html = $this->render_integration_row( 'aafm/wc-update-product' );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'Read-only mode', $html );
	}

	/**
	 * Plan 149 test 6 on screen. Both floors hold this row; the note has to name the one actually
	 * holding it, or it sends the operator to a switch that would change nothing.
	 */
	public function test_a_row_under_both_floors_names_read_only(): void {
		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_integration_row( 'aafm/wc-create-coupon' );

		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'Read-only mode', $html );
		$this->assertStringNotContainsString( 'Turn on High-risk abilities', $html );
	}

	// ---------------------------------------------------------------------------------------
	// The posture pill.
	// ---------------------------------------------------------------------------------------

	/**
	 * The pill states the posture always, not only when something is restricted.
	 */
	public function test_the_page_header_states_the_posture_when_nothing_is_restricted(): void {
		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'data-posture="read_write"', $html );
		$this->assertStringContainsString( 'Read + write', $html );
	}

	/**
	 * Plan 149 test 7. The pill reads the EFFECTIVE state, computed through the same helpers the
	 * floor uses, never the raw option. Here the option is absent and a filter is holding the mode
	 * on, which is exactly the case a raw-option read would get wrong - and getting it wrong means
	 * the header tells an operator writes are reachable when they are not.
	 */
	public function test_the_pill_follows_the_filter_not_the_raw_option(): void {
		$this->assertSame( 'MISSING', get_option( 'aafm_read_only_mode', 'MISSING' ), 'Fixture check: the option must be absent.' );
		add_filter( 'aafm_force_read_only_mode', '__return_true' );

		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'data-posture="read_only"', $html );
		$this->assertStringContainsString( 'Read-only', $html );
	}

	/**
	 * The pill sits in the page header, above the tab nav, so it renders on every tab. It is
	 * deliberately not on the Abilities tab: that tab lists native abilities only, and a global
	 * switch sitting on a partial list reads as scoped to that list.
	 */
	public function test_the_pill_renders_above_the_tab_nav(): void {
		$html     = $this->render_admin_page();
		$pill_at  = strpos( $html, 'data-posture=' );
		$nav_at   = strpos( $html, 'nav-tab-wrapper' );
		$head_end = strpos( $html, 'wp-header-end' );

		$this->assertNotFalse( $pill_at, 'The posture pill is missing from the page header.' );
		$this->assertNotFalse( $nav_at );
		$this->assertLessThan( $head_end, $pill_at, 'The pill must sit inside the page head block.' );
		$this->assertLessThan( $nav_at, $pill_at, 'The pill must render above the tab nav so it appears on every tab.' );
	}

	public function test_the_endpoint_pill_still_renders_beside_it(): void {
		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'aafm-page-head-pills', $html );
		$this->assertSame( 2, substr_count( $html, 'aafm-status-pill' ), 'The header carries exactly two pills: posture and endpoint.' );
	}

	// ---------------------------------------------------------------------------------------
	// The Settings switch.
	// ---------------------------------------------------------------------------------------

	/**
	 * The switch has to sit inside #aafm-settings-form: admin.js reads the checkbox off that form,
	 * so a card that drifted outside it would post nothing and the mode would silently reset to off
	 * on every save.
	 */
	public function test_the_settings_tab_carries_the_switch_inside_the_form(): void {
		$html      = $this->render_settings_tab();
		$switch_at = strpos( $html, 'name="aafm_read_only_mode"' );
		$form_end  = strpos( $html, '</form>' );

		$this->assertNotFalse( $switch_at, 'The read-only switch is missing from the Settings tab.' );
		$this->assertNotFalse( $form_end );
		$this->assertLessThan( $form_end, $switch_at, 'The switch must sit inside #aafm-settings-form or admin.js cannot read it.' );
	}

	public function test_the_switch_is_off_in_the_markup_by_default(): void {
		$html = $this->render_settings_tab();
		$this->assertSame(
			1,
			preg_match( '/<input[^>]*name="aafm_read_only_mode"[^>]*>/', $html, $m )
		);
		$this->assertStringNotContainsString( 'checked', $m[0] );
	}

	public function test_the_switch_reflects_a_stored_mode(): void {
		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_settings_tab();
		$this->assertSame(
			1,
			preg_match( '/<input[^>]*name="aafm_read_only_mode"[^>]*>/', $html, $m )
		);
		$this->assertStringContainsString( 'checked', $m[0] );
	}

	/**
	 * Spec 1.5. While the mode is on, the high-risk switch is moot - all eight of its abilities are
	 * writes and read-only already has them. The card has to say so and render inactive rather than
	 * sitting there looking live while flipping it changes nothing.
	 */
	public function test_the_high_risk_card_renders_inactive_while_the_mode_is_on(): void {
		$this->assertStringNotContainsString( 'is-inactive', $this->render_settings_tab() );

		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_settings_tab();

		$this->assertStringContainsString( 'is-inactive', $html );
		$this->assertStringContainsString( 'Read-only mode is on, so this does nothing right now', $html );
	}

	/**
	 * The high-risk checkbox stays enabled underneath the dimmed card on purpose. A disabled input
	 * posts nothing, and the sanitizer reads a missing field as off, so disabling it would quietly
	 * re-lock the category on the operator's next save.
	 */
	public function test_the_inactive_card_still_submits_its_high_risk_value(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_read_only_mode', true );

		$this->assertSame(
			1,
			preg_match( '/<input[^>]*name="aafm_high_risk_abilities_unlocked"[^>]*>/', $this->render_settings_tab(), $m )
		);
		$this->assertStringNotContainsString( 'disabled', $m[0] );
		$this->assertStringContainsString( 'checked', $m[0] );
	}

	// ---------------------------------------------------------------------------------------
	// The bulk "Enable all reads" control.
	// ---------------------------------------------------------------------------------------

	/**
	 * Plan 149 test 8. The mode deliberately enables nothing on its own (spec 1.3), so the
	 * convenience it refuses to provide is this explicit, operator-initiated action instead.
	 *
	 * The behaviour is JS, so what is pinned here is the contract the JS binds to: the button
	 * exists in each section, every ability row carries the data-risk the binding scopes on, and
	 * the selector in admin.js is the one this markup actually produces. A drift in either half
	 * silently turns the button into a no-op, which is the failure mode that looks like success.
	 */
	public function test_each_abilities_section_offers_enable_all_reads(): void {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm-enable-reads', $html );
		$this->assertStringContainsString( 'Enable all reads', $html );
		$this->assertSame(
			substr_count( $html, 'aafm-section-toggle-all' ),
			substr_count( $html, 'aafm-enable-reads' ),
			'Every section with an enable/disable-all control needs a reads-only control beside it.'
		);
	}

	public function test_every_ability_row_carries_the_risk_the_bulk_control_scopes_on(): void {
		$this->assertStringContainsString( 'data-risk="read"', $this->render_row( 'aafm/get-posts' ) );
		$this->assertStringContainsString( 'data-risk="write"', $this->render_row( 'aafm/create-post' ) );
		$this->assertStringContainsString( 'data-risk="destructive"', $this->render_row( 'aafm/trash-post' ) );
	}

	public function test_the_bulk_control_selector_matches_the_rendered_markup(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin asset off disk, never a remote URL.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );

		$this->assertStringContainsString( "querySelectorAll( '.aafm-enable-reads' )", $js );
		$this->assertStringContainsString(
			'.aafm-ability-row[data-risk="read"] input[type="checkbox"][name="aafm_abilities[]"]',
			$js,
			'The bulk control must scope to read rows only, through the attribute the renderer emits.'
		);
		// The scope roots it walks up to are the two containers the two tabs actually render.
		$this->assertStringContainsString( '.aafm-subject-panel, .aafm-integration-card', $js );
	}

	public function test_the_integrations_tab_offers_it_too(): void {
		ob_start();
		aafm_render_integration_abilities(
			'woocommerce',
			array(
				array(
					'name' => 'aafm/wc-list-orders',
					'risk' => 'read',
				),
			),
			array(),
			array()
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm-enable-reads', $html );
	}
}
