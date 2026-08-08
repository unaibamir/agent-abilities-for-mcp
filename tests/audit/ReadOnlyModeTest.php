<?php
/**
 * Read-only mode: the switch, the one-directional filter, the two floors, and the guarantee that
 * turning the mode on enables nothing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class ReadOnlyModeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_enabled_bridged_abilities' );
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function tear_down(): void {
		delete_option( 'aafm_enabled_bridged_abilities' );
		// Foreign fixtures live in the process-wide abilities registry, so drop them or the next
		// test classifies against an ability it never registered.
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'ro-demo/', 8 ) || 0 === strncmp( $slug, 'aafm-bridge/', 12 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	public function test_the_mode_is_off_by_default(): void {
		$this->assertFalse( aafm_read_only_mode() );
	}

	public function test_the_stored_option_turns_it_on(): void {
		update_option( 'aafm_read_only_mode', true );
		$this->assertTrue( aafm_read_only_mode() );
	}

	/**
	 * The filter direction is the security property, not a convenience. It mirrors
	 * aafm_force_block_high_risk_abilities: a site can force the mode ON regardless of what the
	 * settings screen says, and nothing a site adds can force it off. A filter that could widen
	 * access would be a filter that could switch the floor off.
	 */
	public function test_the_filter_can_force_the_mode_on(): void {
		add_filter( 'aafm_force_read_only_mode', '__return_true' );
		$this->assertTrue( aafm_read_only_mode() );
	}

	public function test_the_filter_cannot_force_the_mode_off(): void {
		update_option( 'aafm_read_only_mode', true );
		add_filter( 'aafm_force_read_only_mode', '__return_false' );
		$this->assertTrue( aafm_read_only_mode() );
	}

	// ---------------------------------------------------------------------------------------
	// The native floor.
	// ---------------------------------------------------------------------------------------

	/**
	 * Plan 149 test 1. The name is in the operator's option and the ability is in the live
	 * registry, so the only thing that can keep it out of the registered set is the floor.
	 */
	public function test_a_native_write_is_never_registered_while_the_mode_is_on(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post', 'aafm/trash-post' ) );
		update_option( 'aafm_read_only_mode', true );

		$enabled = aafm_get_enabled_abilities();

		$this->assertNotContains( 'aafm/create-post', $enabled );
		$this->assertNotContains( 'aafm/trash-post', $enabled, 'A destructive ability is a write too.' );
	}

	/**
	 * Plan 149 test 2. The floor subtracts writes and nothing else.
	 */
	public function test_a_native_read_is_untouched_by_the_mode(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post' ) );
		update_option( 'aafm_read_only_mode', true );

		$this->assertContains( 'aafm/get-posts', aafm_get_enabled_abilities() );
	}

	public function test_turning_the_mode_off_restores_the_write(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post' ) );
		update_option( 'aafm_read_only_mode', true );
		$this->assertNotContains( 'aafm/create-post', aafm_get_enabled_abilities() );

		aafm_set_read_only_mode( false );
		$this->assertContains( 'aafm/create-post', aafm_get_enabled_abilities() );
	}

	/**
	 * Plan 149 test 3, and the rule the whole design rests on (spec 1.3): the mode is a ceiling,
	 * never a bulk action. Turning it on must not enable a single read, and turning it off must
	 * not enable a single write. The page header promises "Nothing is exposed until you turn it
	 * on"; if picking the safest posture switched on seventy reads the operator never chose, that
	 * sentence would stop being true and the safest choice would expose more than before.
	 *
	 * Asserted on the raw stored options, byte for byte, on both edges of the switch.
	 */
	public function test_flipping_the_mode_enables_nothing(): void {
		$native  = array( 'aafm/get-posts', 'aafm/create-post' );
		$bridged = array( 'ro-demo/writer' );
		update_option( 'aafm_enabled_abilities', $native );
		update_option( 'aafm_enabled_bridged_abilities', $bridged );

		$native_before  = get_option( 'aafm_enabled_abilities' );
		$bridged_before = get_option( 'aafm_enabled_bridged_abilities' );

		aafm_set_read_only_mode( true );
		aafm_get_enabled_abilities();
		aafm_get_enabled_bridged_abilities();

		$this->assertSame( $native_before, get_option( 'aafm_enabled_abilities' ), 'Turning read-only mode ON must not touch the stored native selection.' );
		$this->assertSame( $bridged_before, get_option( 'aafm_enabled_bridged_abilities' ), 'Turning read-only mode ON must not touch the stored bridged selection.' );

		aafm_set_read_only_mode( false );
		aafm_get_enabled_abilities();
		aafm_get_enabled_bridged_abilities();

		$this->assertSame( $native_before, get_option( 'aafm_enabled_abilities' ), 'Turning read-only mode OFF must not touch the stored native selection.' );
		$this->assertSame( $bridged_before, get_option( 'aafm_enabled_bridged_abilities' ), 'Turning read-only mode OFF must not touch the stored bridged selection.' );
	}

	/**
	 * The two floors are independent and both subtract. Unlocking the high-risk category must not
	 * hand back a write that read-only mode is holding, and read-only mode must not hand back a
	 * high-risk ability just because it is a write the operator ticked.
	 */
	public function test_the_two_floors_both_subtract(): void {
		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ): array {
				$registry['aafm/wc-create-coupon'] = array(
					'label' => 'High-risk fixture',
					'group' => 'writes',
					'risk'  => 'write',
				);
				return $registry;
			}
		);
		aafm_flush_registry_cache();

		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post', 'aafm/wc-create-coupon' ) );
		update_option( 'aafm_read_only_mode', true );
		update_option( 'aafm_high_risk_abilities_unlocked', true );

		$enabled = aafm_get_enabled_abilities();
		$this->assertSame( array( 'aafm/get-posts' ), $enabled, 'Unlocking the high-risk category must not reopen anything while read-only mode is on.' );
	}

	// ---------------------------------------------------------------------------------------
	// The save path. Spec 2.4 promises the stored option is never rewritten, and that promise has
	// to survive a save, not just a read: a locked row renders no checkbox, so a write is absent
	// from every POST made while the mode is on.
	// ---------------------------------------------------------------------------------------

	/**
	 * The Abilities tab posts an unscoped, full-replace list. Without carrying lock-hidden names
	 * forward, the first save made while the mode is on would empty the operator's entire write
	 * selection and turning the mode off would hand back a blank slate - the opposite of what the
	 * mode promises, and a far bigger loss than the high-risk floor's eight names.
	 */
	public function test_a_full_replace_save_keeps_the_writes_the_mode_is_hiding(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post' ) );
		update_option( 'aafm_read_only_mode', true );

		// What the browser actually posts: the read row's checkbox, and nothing for the write row,
		// because the write row rendered no checkbox at all.
		$resolved = aafm_resolve_scoped_enabled_input( array( 'aafm_abilities' => array( 'aafm/get-posts' ) ) );
		$stored   = aafm_set_enabled_abilities( $resolved );

		$this->assertContains( 'aafm/create-post', $stored, 'A hidden write must survive the save that never offered it.' );
		$this->assertContains( 'aafm/get-posts', $stored );
	}

	/**
	 * The other direction. A write that is NOT already stored cannot have come from the screen
	 * while the mode is on, so it is refused rather than parked in the option to activate the
	 * moment the mode goes off. Same rule the high-risk floor applies, same reason: the UI is not
	 * an authorization boundary.
	 */
	public function test_a_posted_write_that_was_not_already_stored_is_refused(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( 'aafm_read_only_mode', true );

		$stored = aafm_set_enabled_abilities( array( 'aafm/get-posts', 'aafm/create-post' ) );

		$this->assertNotContains( 'aafm/create-post', $stored );
		$this->assertSame( array( 'aafm/get-posts' ), get_option( 'aafm_enabled_abilities' ) );

		// And the refusal is audited, the same as a blocked high-risk enable.
		$rows = aafm_query_activity( array( 'per_page' => 10 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ability_enable_blocked', $rows[0]['event_type'] );
		$this->assertSame( 'aafm/create-post', $rows[0]['ability'] );

		// B37: the row must name its ACTUAL cause. create-post is not high-risk; the reason this
		// forged/stale POST was refused is read-only mode, and the audit trail exists to record
		// exactly that event, so a hardcoded "(high-risk locked)" detail is a false statement in
		// the one row written to describe it.
		$this->assertStringContainsString( 'read-only', (string) $rows[0]['detail'], 'The refusal detail must name read-only mode as the cause.' );
		$this->assertStringNotContainsString( 'high-risk', (string) $rows[0]['detail'], 'The refusal detail must not blame the high-risk floor.' );
	}

	/**
	 * The bridge tab has its own save with its own field name, so it needs the same carry-forward
	 * or the first bridge save while the mode is on drops every enabled bridged write.
	 */
	public function test_a_bridge_save_keeps_the_bridged_writes_the_mode_is_hiding(): void {
		$this->register_foreign_fixtures();
		update_option( 'aafm_enabled_bridged_abilities', array( 'ro-demo/reader', 'ro-demo/destroyer' ) );
		update_option( 'aafm_read_only_mode', true );
		$this->acting_as( 'administrator' );

		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		// Only the read row has a checkbox, so only it is posted.
		$_POST['bridged_abilities'] = array( 'ro-demo/reader' );

		$this->run_ajax( 'aafm_ajax_save_bridged_abilities' );

		$stored = aafm_get_stored_bridged_abilities_raw();
		$this->assertContains( 'ro-demo/destroyer', $stored, 'A hidden bridged write must survive the save that never offered it.' );
		$this->assertContains( 'ro-demo/reader', $stored );
	}

	public function test_a_bridge_save_refuses_a_write_that_was_not_already_stored(): void {
		$this->register_foreign_fixtures();
		update_option( 'aafm_enabled_bridged_abilities', array( 'ro-demo/reader' ) );
		update_option( 'aafm_read_only_mode', true );
		$this->acting_as( 'administrator' );

		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = array( 'ro-demo/reader', 'ro-demo/destroyer' );

		$this->run_ajax( 'aafm_ajax_save_bridged_abilities' );

		$this->assertSame( array( 'ro-demo/reader' ), aafm_get_stored_bridged_abilities_raw() );
	}

	/**
	 * Run an AJAX handler in-process: route wp_die through an exception so the JSON send does not
	 * exit the test, and swallow the echoed body. Mirrors SettingsSaveTest::run_handler().
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return void
	 */
	private function run_ajax( callable $handler ): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		ob_end_clean();

		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'], $_POST['bridged_abilities'] );
	}

	// ---------------------------------------------------------------------------------------
	// Classification.
	// ---------------------------------------------------------------------------------------

	/**
	 * Native risk is authoritative in the registry row, not guessed from the name.
	 */
	public function test_native_risk_comes_off_the_registry(): void {
		$this->assertTrue( aafm_ability_is_read( 'aafm/get-posts' ) );
		$this->assertFalse( aafm_ability_is_read( 'aafm/create-post' ) );
		$this->assertFalse( aafm_ability_is_read( 'aafm/trash-post' ) );
	}

	/**
	 * Fail closed. An unknown name cannot be positively classified as a read, so it is not one.
	 */
	public function test_an_unclassifiable_name_is_never_a_read(): void {
		$this->assertFalse( aafm_ability_is_read( 'aafm/no-such-ability-9f3c1a' ) );
		$this->assertFalse( aafm_ability_is_read( 'someone-else/never-registered' ) );
		$this->assertFalse( aafm_ability_is_read( '' ) );
	}

	/**
	 * Plan 149 test 6. When both floors apply, the reason has to be the one actually holding the
	 * ability down, or the row's note sends the operator to a switch that would change nothing.
	 */
	public function test_when_both_floors_apply_the_reason_is_read_only(): void {
		update_option( 'aafm_read_only_mode', true );
		$this->assertTrue( aafm_ability_is_locked( 'aafm/wc-create-coupon' ), 'Fixture check: the ability must be high-risk locked too.' );
		$this->assertSame( 'read_only', aafm_ability_lock_reason( 'aafm/wc-create-coupon' ) );
	}

	public function test_the_high_risk_reason_survives_when_the_mode_is_off(): void {
		$this->assertSame( 'high_risk', aafm_ability_lock_reason( 'aafm/wc-create-coupon' ) );
	}

	public function test_an_ordinary_read_has_no_lock_reason(): void {
		update_option( 'aafm_read_only_mode', true );
		$this->assertNull( aafm_ability_lock_reason( 'aafm/get-posts' ) );
	}

	// ---------------------------------------------------------------------------------------
	// The bridged floor.
	// ---------------------------------------------------------------------------------------

	/**
	 * Register three foreign abilities covering every annotation case that matters: an explicit
	 * read-only one, an explicitly destructive one, and one that declares nothing at all.
	 *
	 * @return void
	 */
	private function register_foreign_fixtures(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'ro-demo-things' ) ) {
					wp_register_ability_category(
						'ro-demo-things',
						array(
							'label'       => 'Read-only demo things',
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
					'category'            => 'ro-demo-things',
					'description'         => 'Fixture.',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				);
				wp_register_ability(
					'ro-demo/reader',
					$base + array(
						'label' => 'Reader',
						'meta'  => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				wp_register_ability(
					'ro-demo/destroyer',
					$base + array(
						'label' => 'Destroyer',
						'meta'  => array(
							'annotations' => array(
								'readonly'    => false,
								'destructive' => true,
							),
						),
					)
				);
				wp_register_ability( 'ro-demo/silent', $base + array( 'label' => 'Silent' ) );
			}
		);
	}

	/**
	 * Plan 149 test 4. Read-only mode reaches bridged abilities, classified by the foreign
	 * plugin's own annotation - the same declaration this plugin already trusts to decide the
	 * "can delete data" confirm and what it reports to MCP clients. The unannotated case is the
	 * one that matters most: aafm_bridge_risk() already treats silence as destructive, so an
	 * ability that says nothing is excluded automatically rather than waved through.
	 */
	public function test_the_bridged_floor_keeps_only_declared_reads(): void {
		$this->register_foreign_fixtures();
		update_option(
			'aafm_enabled_bridged_abilities',
			array( 'ro-demo/reader', 'ro-demo/destroyer', 'ro-demo/silent' )
		);

		$this->assertSame(
			array( 'ro-demo/reader', 'ro-demo/destroyer', 'ro-demo/silent' ),
			aafm_get_enabled_bridged_abilities(),
			'With the mode off, every enabled slug bridges.'
		);

		update_option( 'aafm_read_only_mode', true );

		$this->assertSame( array( 'ro-demo/reader' ), aafm_get_enabled_bridged_abilities() );
	}

	/**
	 * A slug whose host plugin is not active right now cannot be classified at all, so it is not a
	 * read. Same fail-closed rule as an unknown native name.
	 */
	public function test_an_unresolvable_bridged_slug_is_not_a_read(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'ro-demo/reader' ) );
		update_option( 'aafm_read_only_mode', true );

		// No fixtures registered: the ability does not exist this request.
		$this->assertSame( array(), aafm_get_enabled_bridged_abilities() );
	}

	public function test_the_raw_bridged_accessor_ignores_the_floor(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'ro-demo/reader', 'ro-demo/destroyer' ) );
		update_option( 'aafm_read_only_mode', true );

		$this->assertSame(
			array( 'ro-demo/reader', 'ro-demo/destroyer' ),
			aafm_get_stored_bridged_abilities_raw(),
			'The raw accessor is what the admin screen and the save path read, so it must never subtract.'
		);
	}

	public function test_the_server_ability_list_honours_the_bridged_floor(): void {
		$this->register_foreign_fixtures();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post' ) );
		update_option( 'aafm_enabled_bridged_abilities', array( 'ro-demo/reader', 'ro-demo/destroyer' ) );
		update_option( 'aafm_read_only_mode', true );

		$names = aafm_all_server_ability_names();

		$this->assertContains( 'aafm/get-posts', $names );
		$this->assertContains( 'aafm-bridge/ro-demo-reader', $names );
		$this->assertNotContains( 'aafm/create-post', $names );
		$this->assertNotContains( 'aafm-bridge/ro-demo-destroyer', $names );
	}

	// ---------------------------------------------------------------------------------------
	// The posture the header states.
	// ---------------------------------------------------------------------------------------

	/**
	 * The pill states the posture always, not only when something is restricted.
	 */
	public function test_the_posture_reads_read_write_by_default(): void {
		$posture = aafm_exposure_posture();
		$this->assertSame( 'read_write', $posture['state'] );
		$this->assertSame( 'neutral', $posture['variant'] );
	}

	public function test_the_posture_reports_an_unlocked_high_risk_category(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$posture = aafm_exposure_posture();
		$this->assertSame( 'high_risk_unlocked', $posture['state'] );
		$this->assertSame( 'warn', $posture['variant'] );
	}

	/**
	 * Plan 149 test 7, the effective-state half. The posture is computed from the same helpers the
	 * floor uses, never from the raw option, so it can never claim read plus write while a filter
	 * is holding the mode on - or claim read-only while something is actually registered.
	 */
	public function test_the_posture_follows_the_filter_not_the_raw_option(): void {
		$this->assertSame( 'MISSING', get_option( 'aafm_read_only_mode', 'MISSING' ), 'Fixture check: the option must be absent.' );
		add_filter( 'aafm_force_read_only_mode', '__return_true' );

		$posture = aafm_exposure_posture();
		$this->assertSame( 'read_only', $posture['state'] );
		$this->assertSame( 'success', $posture['variant'] );
	}

	/**
	 * Read-only wins over an unlocked high-risk category in the header too. While the mode is on
	 * the high-risk switch is moot, so a "high-risk unlocked" warning would overstate the site's
	 * exposure at exactly the moment it is at its narrowest.
	 */
	public function test_read_only_beats_the_high_risk_posture(): void {
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_read_only_mode', true );
		$this->assertSame( 'read_only', aafm_exposure_posture()['state'] );
	}

	// ---------------------------------------------------------------------------------------
	// The audit row, and the option's off state.
	// ---------------------------------------------------------------------------------------

	/**
	 * Plan 149 test 9. One row per real change, and a non-blank Event: the admin table and its
	 * AJAX re-render both put the raw `ability` value straight into the Event cell, so a blank one
	 * renders as a blank row. Mirrors the synthetic name the high-risk switch already uses.
	 */
	public function test_flipping_the_switch_writes_one_setting_changed_row(): void {
		$this->acting_as( 'administrator' );
		aafm_log_read_only_switch_change( false, true );

		$rows = aafm_query_activity( array( 'per_page' => 10 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'setting_changed', $rows[0]['event_type'] );
		$this->assertSame( 'aafm/read-only-mode', $rows[0]['ability'] );
		$this->assertNotSame( '', (string) $rows[0]['ability'], 'A blank ability renders as a blank Event cell.' );
		$this->assertSame( 'Read-only mode turned on', $rows[0]['detail'] );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	public function test_turning_the_switch_off_is_logged_too(): void {
		aafm_log_read_only_switch_change( true, false );
		$rows = aafm_query_activity( array( 'per_page' => 10 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Read-only mode turned off', $rows[0]['detail'] );
	}

	public function test_an_unchanged_save_writes_nothing(): void {
		aafm_log_read_only_switch_change( false, false );
		aafm_log_read_only_switch_change( true, true );
		$this->assertSame( array(), aafm_query_activity( array( 'per_page' => 10 ) ) );
	}

	/**
	 * The setting_changed event type already exists, so the mode needs no new one. Pinned
	 * because a row whose event_type is not in this list renders unfiltered in the Activity tab.
	 */
	public function test_the_event_type_is_one_the_activity_tab_knows(): void {
		$this->assertContains( 'setting_changed', aafm_activity_event_types() );
	}

	/**
	 * Plan 149 test 10. Off deletes the row rather than storing a falsy value - off is the option's
	 * out-of-the-box state, and an explicit false is one a fresh install can never be in.
	 *
	 * The sentinel default is what actually proves it: a stored false reads back falsy against a
	 * `false` default, so assertFalse() would pass either way and mask exactly this bug. Same
	 * pattern as the high-risk switch fix in 1.5.0.
	 */
	public function test_turning_the_mode_off_deletes_the_option_row(): void {
		aafm_set_read_only_mode( true );
		$this->assertTrue( (bool) get_option( 'aafm_read_only_mode' ) );

		aafm_set_read_only_mode( false );
		$this->assertSame( 'MISSING', get_option( 'aafm_read_only_mode', 'MISSING' ) );
	}

	public function test_a_reset_clears_the_mode(): void {
		$this->assertContains( 'aafm_read_only_mode', aafm_config_option_names() );
	}

	// ---------------------------------------------------------------------------------------
	// The wizard (spec 1.10).
	// ---------------------------------------------------------------------------------------

	/**
	 * The wizard's read-only default used to be a one-time set of ticks, which eroded the first
	 * time anyone switched a single write on somewhere else. Setting the mode is what makes the
	 * promise it shows on day one still true in month six.
	 */
	public function test_finishing_the_wizard_without_write_turns_the_mode_on(): void {
		aafm_quickconnect_apply_abilities( false );
		$this->assertTrue( aafm_read_only_mode() );
	}

	/**
	 * Ticking the write row has to clear the mode in the same breath, or the wizard would enable a
	 * write bundle the floor then refuses to register: a screen saying yes over a server saying no.
	 */
	public function test_finishing_the_wizard_with_write_clears_the_mode(): void {
		aafm_quickconnect_apply_abilities( false );
		$this->assertTrue( aafm_read_only_mode() );

		aafm_quickconnect_apply_abilities( true );

		$this->assertSame( 'MISSING', get_option( 'aafm_read_only_mode', 'MISSING' ) );
		$this->assertContains( 'aafm/create-post', aafm_get_enabled_abilities(), 'The write bundle the wizard just enabled must actually register.' );
	}

	/**
	 * A wizard finish must not quietly discard a write the operator enabled on the Abilities tab.
	 * Reading the floored enabled list here instead of the raw stored one would do exactly that on
	 * any run made while the mode was already on.
	 */
	public function test_finishing_the_wizard_does_not_discard_writes_set_elsewhere(): void {
		update_option( 'aafm_read_only_mode', true );
		update_option( 'aafm_enabled_abilities', array( 'aafm/update-term' ) );

		aafm_quickconnect_apply_abilities( false );

		$this->assertContains(
			'aafm/update-term',
			(array) get_option( 'aafm_enabled_abilities' ),
			'A write the operator set outside the wizard must survive a wizard finish.'
		);
	}
}
