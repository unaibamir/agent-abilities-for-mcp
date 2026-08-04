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

	public function tear_down(): void {
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			if ( 0 === strncmp( (string) $slug, 'demo/', 5 ) ) {
				wp_unregister_ability( (string) $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register a live read-only and a live write foreign ability.
	 *
	 * The Bridge tab only ever renders rows it discovered from the live registry, so a row whose
	 * slug is not registered is a combination production cannot produce on an active card. These
	 * fixtures make the risk classification real rather than incidentally correct: the renderer
	 * resolves each slug and reads its actual annotations, the same as it would on a real site.
	 *
	 * @return void
	 */
	private function register_bridge_fixtures(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				$base = array(
					'category'            => 'demo-things',
					'description'         => 'Fixture.',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				);
				wp_register_ability(
					'demo/reader',
					$base + array(
						'label' => 'Reader',
						'meta'  => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				wp_register_ability(
					'demo/writer',
					$base + array(
						'label' => 'Writer',
						'meta'  => array(
							'annotations' => array(
								'readonly'    => false,
								'destructive' => false,
							),
						),
					)
				);
			}
		);
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
	 * Both governance switches are rows of the Safety controls card now, and read-only mode is the
	 * first of them. That order is the meaning: it is the wider of the two ceilings, so an operator
	 * reads it before the switch it can hold, and the high-risk row's "turn read-only mode off
	 * above" note points at the row immediately preceding it rather than at another card.
	 */
	public function test_read_only_mode_is_the_first_row_of_safety_controls(): void {
		$html = $this->render_settings_tab();

		$safety_at     = strpos( $html, 'Safety controls' );
		$read_only_at  = strpos( $html, 'name="aafm_read_only_mode"' );
		$high_risk_at  = strpos( $html, 'name="aafm_high_risk_abilities_unlocked"' );
		$rate_limit_at = strpos( $html, 'name="aafm_rate_limit_per_min"' );

		$this->assertNotFalse( $safety_at, 'The Safety controls card is missing from the Settings tab.' );
		$this->assertNotFalse( $read_only_at, 'The read-only switch is missing from the Settings tab.' );
		$this->assertNotFalse( $high_risk_at );
		$this->assertNotFalse( $rate_limit_at );

		$this->assertLessThan( $read_only_at, $safety_at, 'Read-only mode must render inside the Safety controls card.' );
		$this->assertLessThan( $high_risk_at, $read_only_at, 'Read-only mode must sit above the high-risk switch.' );
		$this->assertLessThan( $rate_limit_at, $high_risk_at, 'The two governance switches come before the per-behaviour controls.' );

		// "First row" literally: exactly one .aafm-set-row has opened between the card head and the
		// switch. Counted from the card head rather than from the top of the tab, because the OAuth
		// card sits above Safety controls now and brings two rows of its own with it.
		$this->assertSame(
			1,
			substr_count( substr( $html, $safety_at, $read_only_at - $safety_at ), 'aafm-set-row' ),
			'Read-only mode must be the first row of the card, with nothing above it.'
		);
	}

	/**
	 * The standalone card is gone; it was one row in a card of its own, which is what broke the
	 * rhythm of the tab. Pin its absence so it cannot come back beside the row.
	 */
	public function test_the_standalone_read_only_card_is_gone(): void {
		$this->assertStringNotContainsString(
			'aafm-card-head-title">Read-only mode',
			$this->render_settings_tab()
		);
	}

	/**
	 * Spec 1.5. While the mode is on, the high-risk switch is moot - all eight of its abilities are
	 * writes and read-only already has them. That used to be said by dimming a whole card; now that
	 * the switch is a row, the row carries it: the .is-inactive dim, a "Held by read-only mode"
	 * pill beside the label, and the inline notice naming the switch directly above it.
	 */
	public function test_the_high_risk_row_renders_inactive_while_the_mode_is_on(): void {
		$this->assertStringNotContainsString( 'is-inactive', $this->render_settings_tab() );

		update_option( 'aafm_read_only_mode', true );
		$html = $this->render_settings_tab();

		$this->assertMatchesRegularExpression(
			'/<div class="aafm-set-row is-inactive">/',
			$html,
			'The dim has to land on the high-risk ROW now that its card is gone.'
		);
		$this->assertStringContainsString( 'Held by read-only mode', $html );
		$this->assertStringContainsString( 'Read-only mode is on, so this does nothing right now', $html );
		$this->assertStringContainsString( 'Turn read-only mode off above to use this switch', $html );

		// The dimmed row is the high-risk one, not some other row that happens to be inactive.
		$inactive_at  = strpos( $html, 'aafm-set-row is-inactive' );
		$high_risk_at = strpos( $html, 'name="aafm_high_risk_abilities_unlocked"' );
		$this->assertNotFalse( $high_risk_at );
		$this->assertLessThan( $high_risk_at, $inactive_at );
	}

	/**
	 * The high-risk checkbox stays enabled underneath the dimmed row on purpose. A disabled input
	 * posts nothing, and the sanitizer reads a missing field as off, so disabling it would quietly
	 * re-lock the category on the operator's next save.
	 */
	public function test_the_inactive_row_still_submits_its_high_risk_value(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_read_only_mode', true );

		$this->assertSame(
			1,
			preg_match( '/<input[^>]*name="aafm_high_risk_abilities_unlocked"[^>]*>/', $this->render_settings_tab(), $m )
		);
		$this->assertStringNotContainsString( 'disabled', $m[0] );
		$this->assertStringContainsString( 'checked', $m[0] );
	}

	/**
	 * Three rows carry much longer copy than the rest of the tab, so their tail sits behind a
	 * native "See more". Collapsed is not cut: assert the tail is inside the <details> AND that the
	 * lead sentence is not, so a future edit cannot satisfy this by deleting the long half.
	 */
	public function test_the_long_descriptions_collapse_without_losing_their_copy(): void {
		$html = $this->render_settings_tab();

		$this->assertSame(
			3,
			substr_count( $html, '<details class="aafm-set-more">' ),
			'Exactly three rows collapse: read-only mode, the high-risk switch, and delete on uninstall.'
		);

		preg_match_all( '#<details class="aafm-set-more">(.*?)</details>#s', $html, $m );
		$collapsed = implode( ' ', $m[1] );

		foreach (
			array(
				'A bridged ability counts as a read only when the plugin that wrote it says so',
				'Abilities bridged in from other plugins are not covered by it',
				'ordinary WordPress account credentials the plugin does not own',
			) as $tail
		) {
			$this->assertStringContainsString( $tail, $collapsed, 'The tail of a long description must survive behind the expander.' );
		}

		foreach (
			array(
				'no ability that creates, changes, or deletes anything can be switched on',
				'your settings, activity log, and OAuth data are kept if you delete the plugin',
			) as $lead
		) {
			$this->assertStringContainsString( $lead, $html );
			$this->assertStringNotContainsString( $lead, $collapsed, 'The lead sentence stays visible; only the remainder collapses.' );
		}
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
			'.aafm-ability-row[data-risk="read"] input[type="checkbox"][name="aafm_abilities[]"]:not([disabled])',
			$js,
			'The bulk control must scope to read rows only, through the attribute the renderer emits.'
		);
		// The Bridge tab posts its own field name through its own AJAX save, so the binding has to
		// match that too or the button is a silent no-op on exactly the tab spec 1.4 put in scope.
		$this->assertStringContainsString(
			'.aafm-ability-row[data-risk="read"] input[type="checkbox"][name="bridged_abilities[]"]:not([disabled])',
			$js,
			'The bulk control must also match the Bridge tab field name.'
		);
		// The scope roots it walks up to are the containers the three tabs actually render.
		$this->assertStringContainsString( '.aafm-subject-panel, .aafm-integration-card', $js );
	}

	/**
	 * Render one bridge group and return its markup.
	 *
	 * @param array<int,array<string,mixed>> $rows Ability display records.
	 * @return string
	 */
	private function render_bridge_group( array $rows ): string {
		$this->register_bridge_fixtures();
		ob_start();
		aafm_render_bridge_group( 'demo', array( 'abilities' => $rows ), array(), false );
		return (string) ob_get_clean();
	}

	public function test_the_bridge_tab_offers_enable_all_reads(): void {
		$html = $this->render_bridge_group(
			array(
				array(
					'slug'  => 'demo/reader',
					'label' => 'Reader',
					'risk'  => 'read',
				),
			)
		);

		$this->assertStringContainsString( 'aafm-enable-reads', $html );
		$this->assertStringContainsString( 'Enable all reads', $html );
		// It sits beside the existing pair, per spec 1.8.
		$this->assertStringContainsString( 'data-bridge-bulk="enable"', $html );
		$this->assertStringContainsString( 'data-bridge-bulk="disable"', $html );
	}

	/**
	 * The binding walks up to `.aafm-integration-card`, and on this tab that class lands on the
	 * group's own <details>. If the bridge group ever stopped carrying it the button would find no
	 * scope and silently do nothing, which is the failure mode that looks exactly like success.
	 */
	public function test_the_bridge_group_carries_the_scope_root_the_binding_walks_to(): void {
		$html = $this->render_bridge_group(
			array(
				array(
					'slug'  => 'demo/reader',
					'label' => 'Reader',
					'risk'  => 'read',
				),
			)
		);

		$this->assertStringContainsString( 'aafm-integration-card', $html );
		$this->assertMatchesRegularExpression( '/<details[^>]*class="[^"]*aafm-integration-card/', $html );
	}

	public function test_a_bridge_read_row_carries_the_risk_the_bulk_control_scopes_on(): void {
		$html = $this->render_bridge_group(
			array(
				array(
					'slug'  => 'demo/reader',
					'label' => 'Reader',
					'risk'  => 'read',
				),
				array(
					'slug'  => 'demo/writer',
					'label' => 'Writer',
					'risk'  => 'write',
				),
			)
		);

		$this->assertStringContainsString( 'data-risk="read"', $html );
		$this->assertStringContainsString( 'data-risk="write"', $html );
		$this->assertStringContainsString( 'name="bridged_abilities[]" value="demo/reader"', $html );
	}

	/**
	 * The bridge renderer computes its read-only lock inline rather than through
	 * aafm_ability_lock_reason(), and this is the test that pins WHY.
	 *
	 * That helper falls through to aafm_ability_is_locked(), which is a plain
	 * in_array() over aafm_high_risk_abilities() - the built-ins plus whatever the public
	 * aafm_high_risk_abilities filter adds. A site that names a FOREIGN slug in that filter would
	 * make it return true. The high-risk floor never subtracts from the bridged registration walk,
	 * so the row would show a padlock over an ability that still registers: a lock the floor does
	 * not enforce, which is exactly the defect class 1.5.0 shipped.
	 *
	 * So: a filter-added foreign slug must still render a live checkbox here. This fails the moment
	 * anyone routes this renderer through the shared helper.
	 */
	public function test_a_bridge_row_never_renders_a_high_risk_lock(): void {
		add_filter(
			'aafm_high_risk_abilities',
			static fn( array $e ): array => array_merge( $e, array( 'demo/writer' ) )
		);
		$this->assertTrue(
			aafm_ability_is_locked( 'demo/writer' ),
			'Fixture check: the filter must actually make the shared predicate report this slug locked.'
		);

		$html = $this->render_bridge_group(
			array(
				array(
					'slug'  => 'demo/writer',
					'label' => 'Writer',
					'risk'  => 'write',
				),
			)
		);

		$this->assertStringContainsString(
			'name="bridged_abilities[]" value="demo/writer"',
			$html,
			'The high-risk floor does not reach bridged abilities, so this row must stay toggleable.'
		);
		$this->assertStringNotContainsString( 'aafm-ability-locked', $html );
		$this->assertStringNotContainsString( 'Turn on High-risk abilities', $html );
	}

	/**
	 * The "Unavailable" group renders enabled slugs whose host plugin is inactive, so by definition
	 * none of its slugs is a registered ability. Core treats wp_get_ability() on an unregistered
	 * slug as a programming error and raises _doing_it_wrong(), which the WP test case converts into
	 * a failure, so rendering the group with nothing registered IS the assertion.
	 *
	 * The `$disabled ? '' : ...` short-circuit in aafm_render_bridge_permission_line() is what
	 * protects it today, by skipping the lookup entirely for an orphan row. That
	 * is load-bearing and was previously unpinned: removing the short-circuit makes this test fail
	 * with the notice above. Every other caller of aafm_bridge_permission_label() comes from
	 * aafm_discover_foreign_abilities(), which enumerates wp_get_abilities(), so its slug is always
	 * registered. The two facts together are why the label helper needs no guard of its own.
	 */
	public function test_the_orphan_group_renders_without_a_doing_it_wrong_notice(): void {
		$this->assertFalse( wp_has_ability( 'demo/gone' ), 'Fixture check: the orphan slug must NOT be registered.' );

		ob_start();
		aafm_render_bridge_orphan_group( array( 'demo/gone' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'demo/gone', $html, 'An orphan must still be shown, not silently dropped.' );
	}

	/**
	 * The other side of it: read-only mode DOES reach bridged abilities, so the inline clause has
	 * to still lock a write. Without this, "do not use the shared helper" could be satisfied by
	 * doing no locking at all.
	 */
	public function test_read_only_mode_still_locks_a_bridged_write(): void {
		update_option( 'aafm_read_only_mode', true );

		$html = $this->render_bridge_group(
			array(
				array(
					'slug'  => 'demo/writer',
					'label' => 'Writer',
					'risk'  => 'write',
				),
			)
		);

		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'aafm-ability-locked', $html );
		$this->assertStringContainsString( 'Read-only mode', $html );
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
