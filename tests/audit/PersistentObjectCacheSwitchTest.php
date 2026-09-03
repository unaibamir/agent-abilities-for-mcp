<?php
/**
 * The two operator switches that delete their option row when turned off must still turn off when a
 * persistent object cache is serving a stale autoloaded-options blob that predates the delete.
 *
 * Reproduced on a live site behind a host-provided Redis drop-in (2026-09-03): the DB row was gone,
 * Redis still carried aafm_read_only_mode = 1, delete_option() found no row and returned before
 * touching the cache, the settings screen showed the mode on forever, and the activity log recorded
 * "turned off" five times in a row. The same shape applies to the high-risk unlock, where the stale
 * direction is the dangerous one: a cache that still says "unlocked" after the operator locked it.
 *
 * This suite runs against PHPUnit's WP_Object_Cache, WordPress core's own in-process, single-request
 * object cache stand-in - not a persistent backend, and its wp_cache_get()/wp_cache_set() do not
 * distinguish `$force` from a normal call, so `aafm_force_refresh_option_caches()`'s forced reads
 * behave exactly like unforced ones here. That is enough to reproduce and pin the read-modify-write
 * bug class this file is named for (a stale `alloptions`/`notoptions` entry with no backing DB row),
 * because that class of bug is about which cache entries get read and rewritten within one request,
 * not about crossing a network boundary. What this suite cannot exercise - a persistent backend that
 * actually diverges between processes, honors or ignores `$force` for real, or has a remote write
 * fail after already priming its own runtime cache (finding 6 in the 1.7.3 hotfix review) - is
 * covered by the Redis-backed MCP-sim harness and by manual `ddev wp eval` checks against a live
 * Redis object-cache drop-in, never by this file.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class PersistentObjectCacheSwitchTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		delete_option( 'aafm_read_only_mode' );
		delete_option( 'aafm_high_risk_abilities_unlocked' );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'pre_option_aafm_read_only_mode' );
		remove_all_filters( 'pre_option_aafm_high_risk_abilities_unlocked' );
		remove_all_filters( 'pre_option_aafm_rate_limit_per_min' );
		remove_all_filters( 'pre_option_aafm_enabled_abilities' );
		unset( $_POST['nonce'], $_REQUEST['nonce'], $_POST['aafm_read_only_mode'], $_POST['aafm_high_risk_abilities_unlocked'] );
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( 'aafm_read_only_mode' );
		delete_option( 'aafm_high_risk_abilities_unlocked' );
		parent::tear_down();
	}

	/**
	 * Put the object cache in the state a stale persistent cache leaves behind: the autoloaded blob
	 * carries the switch as on while the options table has no row for it.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private function plant_stale_on( string $option ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
		$all            = wp_load_alloptions( true );
		$all[ $option ] = '1';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( '1', get_option( $option, 'MISSING' ), 'Precondition: the stale cache is what get_option() sees.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $option ) ), 'Precondition: no DB row.' );
	}

	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

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

	private function post_settings_save(): array {
		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		return $this->run_handler( 'aafm_ajax_save_settings' );
	}

	private function latest_log_row( string $ability ): array {
		$rows = aafm_query_activity(
			array(
				'ability'  => $ability,
				'per_page' => 1,
			)
		);
		$row  = is_array( $rows ) && isset( $rows['entries'][0] ) ? $rows['entries'][0] : ( $rows[0] ?? array() );
		return is_array( $row ) ? $row : array();
	}

	public function test_read_only_off_recovers_from_a_stale_persistent_cache(): void {
		$this->plant_stale_on( 'aafm_read_only_mode' );

		$persisted = aafm_set_read_only_mode( false );

		// Observable state first: this is what a page render or a later request would actually see,
		// and it is the thing that matters. The return-value contract is asserted after it, as its
		// own, separate claim - against 1.7.2's aafm_set_read_only_mode() (which returned void) this
		// ordering is what makes the test fail on the boolean contract specifically, rather than
		// stopping on a fatal before ever reaching the state assertions below.
		$this->assertFalse( aafm_read_only_mode(), 'Read-only mode must read as off after the operator turns it off.' );
		$this->assertSame( 'MISSING', get_option( 'aafm_read_only_mode', 'MISSING' ), 'Off is still the absent row.' );
		$this->assertTrue( $persisted, 'The write must report that the off state actually persisted.' );
	}

	public function test_read_only_on_recovers_from_a_stale_absent_cache(): void {
		global $wpdb;
		// The mirror image: DB row present and on, cache says the option is known-absent.
		update_option( 'aafm_read_only_mode', true );
		$all = wp_load_alloptions( true );
		unset( $all['aafm_read_only_mode'] );
		wp_cache_set( 'alloptions', $all, 'options' );
		wp_cache_set( 'notoptions', array( 'aafm_read_only_mode' => true ), 'options' );
		$this->assertFalse( get_option( 'aafm_read_only_mode', false ), 'Precondition: the stale cache hides the row.' );

		$persisted = aafm_set_read_only_mode( true );

		// Observable state before the return-value contract - see the comment on the test above.
		$this->assertTrue( aafm_read_only_mode() );
		$this->assertTrue( $persisted );

		// PARTIAL finding 6 (Codex hotfix re-check): a stale `notoptions` entry planted before the
		// write - the exact shape this test opens with - must not survive it either. get_option()
		// consults `notoptions` BEFORE the database, so a write that leaves the option still listed
		// there would certify as failed (or worse, read back as absent again on the very next
		// request) even with `alloptions` and the per-option key both freshly repaired.
		$not_options = wp_cache_get( 'notoptions', 'options', true );
		$this->assertIsArray( $not_options, 'The notoptions blob must be rewritten, not dropped.' );
		$this->assertArrayNotHasKey(
			'aafm_read_only_mode',
			$not_options,
			'A stale notoptions entry planted before the write must not still claim the option is absent after it.'
		);
	}

	/**
	 * MEDIUM (Codex hotfix review, finding 4): the on branch used to call update_option() before
	 * forgetting any stale cache entry. update_option() decides UPDATE-vs-INSERT from get_option()'s
	 * cached idea of the current value, so with the row absent and the cache still claiming the
	 * switch was already on, it ran an UPDATE against a row that did not exist, affected nothing,
	 * and returned before touching any cache at all - leaving the stale entry for this function's
	 * own after-write forget to clear a moment later with no write to show for it. That made this
	 * exact case - cache says on, row absent, operator (again) requests on - fail on the first
	 * attempt and only succeed on a second call, after the first call's cleanup had already fixed
	 * the cache for next time. Forgetting the cache BEFORE the write, not only after, closes that.
	 */
	public function test_read_only_on_recovers_in_one_attempt_when_cache_and_request_already_agree(): void {
		$this->plant_stale_on( 'aafm_read_only_mode' );

		$persisted = aafm_set_read_only_mode( true );

		$this->assertTrue( aafm_read_only_mode(), 'Read-only mode must read as on.' );
		$this->assertSame( '1', get_option( 'aafm_read_only_mode', 'MISSING' ), 'The row must actually be stored, not merely still cached.' );
		$this->assertTrue( $persisted, 'The write must report success on the FIRST attempt, not require a second save.' );
	}

	public function test_high_risk_lock_recovers_from_a_stale_persistent_cache_through_the_settings_save(): void {
		$this->acting_as( 'administrator' );
		$this->plant_stale_on( 'aafm_high_risk_abilities_unlocked' );
		// Checkbox absent from $_POST: the operator locked the category.

		$json = $this->post_settings_save();

		$this->assertTrue( (bool) ( $json['success'] ?? false ), 'A lock that persisted is a successful save.' );
		$this->assertFalse( aafm_high_risk_unlocked(), 'High-risk abilities must be locked after the operator locks them, whatever the cache said before.' );
		$this->assertSame( 'MISSING', get_option( 'aafm_high_risk_abilities_unlocked', 'MISSING' ) );
	}

	public function test_read_only_off_through_the_settings_save_recovers_and_logs_success(): void {
		$this->acting_as( 'administrator' );
		$this->plant_stale_on( 'aafm_read_only_mode' );

		$json = $this->post_settings_save();

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertFalse( aafm_read_only_mode() );
		$row = $this->latest_log_row( 'aafm/read-only-mode' );
		$this->assertSame( 'success', $row['status'] ?? null );
	}

	/**
	 * HIGH (Codex hotfix review, finding 2): the settings save used to write both governance
	 * switches unconditionally before checking either result, so a save that asked to lock
	 * high-risk abilities AND turn read-only mode off in the same request could fail to persist the
	 * lock (a stale cache) while still turning read-only mode off - leaving every high-risk ability
	 * reachable (nothing left holding writes down) even though the response reported an error.
	 *
	 * Plants a cache that blocks ONLY the high-risk lock from persisting, then asks for both the
	 * lock (restrictive) and read-only-off (permissive) in one save, and asserts the permissive
	 * change never landed: read-only mode is still on, so nothing is reachable regardless of what
	 * the high-risk switch itself ended up saying.
	 */
	public function test_a_failed_restrictive_change_blocks_the_paired_permissive_change_in_the_same_save(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_read_only_mode', true );
		// A cache the plugin cannot repair for this one option: whatever is written, the read keeps
		// coming back unlocked, so the requested LOCK can never actually persist.
		add_filter( 'pre_option_aafm_high_risk_abilities_unlocked', static fn() => '1' );

		// Both checkboxes absent from $_POST: the operator asks to lock high-risk abilities
		// (restrictive) and to turn read-only mode off (permissive) in the same save.
		$json = $this->post_settings_save();

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the requested lock did not persist.' );
		$this->assertTrue( aafm_high_risk_unlocked(), 'Precondition check: the lock genuinely could not persist.' );
		$this->assertTrue(
			aafm_read_only_mode(),
			'Read-only mode must NOT have been turned off: the paired restrictive change failed first, so the permissive change must never have been attempted.'
		);
		$this->assertTrue( (bool) get_option( 'aafm_read_only_mode', false ), 'The read-only-mode row itself must be untouched, not merely the floored reader.' );
	}

	/**
	 * HIGH (Codex hotfix re-check, new finding 1): the verified ordinary-settings loop used to run
	 * BEFORE either governance switch, so a save that requested read-only mode ON and also happened
	 * to include an ordinary setting that failed to persist would abort on the ordinary setting and
	 * never even attempt the requested read-only-on - leaving the site wider open than the operator
	 * asked for, on top of reporting an error. Read-only ON is the restrictive direction and must
	 * persist before an unrelated ordinary-setting failure gets a chance to cut the save short.
	 */
	public function test_an_ordinary_setting_failure_does_not_block_a_requested_restrictive_read_only_on(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'aafm_read_only_mode' );
		delete_option( 'aafm_rate_limit_per_min' );
		// A cache the plugin cannot repair for this one ordinary option: whatever is written, the
		// read keeps coming back at a value nothing posted could ever match.
		add_filter( 'pre_option_aafm_rate_limit_per_min', static fn() => '999999' );

		$_POST['aafm_read_only_mode'] = '1';
		$json                         = $this->post_settings_save();
		unset( $_POST['aafm_read_only_mode'] );
		remove_all_filters( 'pre_option_aafm_rate_limit_per_min' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the ordinary setting failed to persist.' );
		$this->assertTrue(
			aafm_read_only_mode(),
			'The requested restrictive switch (read-only ON) must still persist even though a later ordinary setting failed to verify.'
		);
		$row = $this->latest_log_row( 'aafm/read-only-mode' );
		$this->assertSame( 'success', $row['status'] ?? null, 'The restrictive switch that did persist must be logged as a success, not left unlogged.' );
	}

	public function test_a_switch_that_will_not_persist_is_reported_as_an_error_not_success(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_read_only_mode', true );
		// A cache the plugin cannot repair: whatever is written, the read keeps coming back on.
		add_filter( 'pre_option_aafm_read_only_mode', static fn() => '1' );

		$json = $this->post_settings_save();

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must not claim success when the switch did not take.' );
		$this->assertStringContainsString( 'object cache', strtolower( (string) ( $json['data']['message'] ?? '' ) ) );
		$row = $this->latest_log_row( 'aafm/read-only-mode' );
		$this->assertSame( 'error', $row['status'] ?? null, 'The activity log must record the failed switch as an error, never as "turned off".' );
	}

	public function test_forgetting_one_option_leaves_every_other_cached_option_alone(): void {
		update_option( 'aafm_pocs_neighbour', 'kept' );
		$this->plant_stale_on( 'aafm_read_only_mode' );
		$before = wp_cache_get( 'alloptions', 'options', true );
		$this->assertSame( 'kept', $before['aafm_pocs_neighbour'] ?? null );

		aafm_forget_option_caches( 'aafm_read_only_mode' );

		$after = wp_cache_get( 'alloptions', 'options', true );
		$this->assertIsArray( $after, 'The autoloaded blob must be rewritten, not dropped.' );
		$this->assertArrayNotHasKey( 'aafm_read_only_mode', $after );
		$this->assertSame( 'kept', $after['aafm_pocs_neighbour'] ?? null, 'Only the one key is removed.' );
		$this->assertSame( count( $before ) - 1, count( $after ) );
		delete_option( 'aafm_pocs_neighbour' );
	}

	public function test_reset_to_defaults_clears_a_stale_cached_enabled_list(): void {
		global $wpdb;
		// Reset truncates the activity-log and OAuth tables too; install them so it runs clean.
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_enabled_abilities' ) );
		$all                           = wp_load_alloptions( true );
		$all['aafm_enabled_abilities'] = serialize( array( 'aafm/delete-post' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- planting the raw cache shape.
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( array( 'aafm/delete-post' ), get_option( 'aafm_enabled_abilities' ), 'Precondition: the stale list is what get_option() sees.' );

		aafm_reset_plugin();

		$this->assertSame( 'MISSING', get_option( 'aafm_enabled_abilities', 'MISSING' ), 'Reset must leave no stale enabled list behind.' );
	}

	/**
	 * Codex hotfix review, finding 9: this test's name previously said "enabled list", but it
	 * plants and checks aafm_high_risk_abilities_unlocked, not aafm_enabled_abilities - the sibling
	 * enabled-list case is test_reset_to_defaults_clears_a_stale_cached_enabled_list() above, which
	 * covers reset rather than uninstall. Renamed to match what the test actually does.
	 */
	public function test_uninstall_clears_a_stale_cached_high_risk_unlock(): void {
		global $wpdb;
		aafm_install_activity_log();
		aafm_install_oauth_tables();
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_high_risk_abilities_unlocked' ) );
		$all                                      = wp_load_alloptions( true );
		$all['aafm_high_risk_abilities_unlocked'] = '1';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertTrue( aafm_high_risk_unlocked(), 'Precondition: the stale unlock is what the floor sees.' );

		aafm_uninstall_site();

		$this->assertFalse( aafm_high_risk_unlocked(), 'Uninstall must not leave a stale unlock for the next install to inherit.' );
	}

	/**
	 * The Quick Connect wizard's finish step is the plugin's other write-gate entry point (first-run
	 * onboarding, alongside the main settings save covered above). It must not claim success when the
	 * read-only-mode switch it flips on the operator's behalf fails to persist.
	 */
	public function test_quickconnect_finish_reports_an_error_when_read_only_mode_will_not_persist(): void {
		$this->acting_as( 'administrator' );
		// A cache the plugin cannot repair: whatever is written, the read keeps coming back off.
		add_filter( 'pre_option_aafm_read_only_mode', static fn() => '0' );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		// Write off means the wizard turns read-only mode ON, which is the write this filter blocks.
		$_POST['write'] = '0';
		$json           = $this->run_handler( 'aafm_ajax_quickconnect_finish' );
		unset( $_POST['write'] );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The finish handler must not claim success when read-only mode did not persist.' );
		$this->assertStringContainsString( 'object cache', strtolower( (string) ( $json['data']['message'] ?? '' ) ) );
		$row = $this->latest_log_row( 'aafm/read-only-mode' );
		$this->assertSame( 'error', $row['status'] ?? null, 'The activity log must record the failed switch as an error, never as success.' );
		// Codex hotfix review, finding 8: the completion flag used to be written before this check,
		// so a reload after this exact failure would have shown the site as "finished" even though
		// setup never actually completed. It must stay unset while the response is an error.
		$this->assertFalse( aafm_quickconnect_is_finished(), 'The wizard must not be marked finished when the switch it flips did not persist.' );
	}

	/**
	 * MEDIUM (Codex hotfix re-check, new finding 2): aafm_quickconnect_apply_abilities() used to
	 * discard aafm_set_enabled_abilities()'s verified-write boolean entirely, and the finish handler
	 * checked only whether read-only mode persisted - so a run whose enabled-abilities write silently
	 * failed under a stale persistent object cache still reported success and marked the wizard
	 * finished, even though the abilities the operator asked for were never actually made reachable.
	 * Mirrors the read-only-mode failure test above, for the sibling write.
	 */
	public function test_quickconnect_finish_reports_an_error_when_the_enabled_abilities_write_will_not_persist(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'aafm_enabled_abilities' );
		// A cache the plugin cannot repair for this option: whatever is written, the read keeps
		// coming back empty, which can never match the non-empty read/write bundle the wizard intends
		// to enable.
		add_filter( 'pre_option_aafm_enabled_abilities', static fn() => array() );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$_POST['write']    = '1';
		$json              = $this->run_handler( 'aafm_ajax_quickconnect_finish' );
		unset( $_POST['write'] );
		remove_all_filters( 'pre_option_aafm_enabled_abilities' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The finish handler must not claim success when the enabled-abilities write did not persist.' );
		$this->assertStringContainsString( 'object cache', strtolower( (string) ( $json['data']['message'] ?? '' ) ) );
		$this->assertFalse( aafm_quickconnect_is_finished(), 'The wizard must not be marked finished when the enabled-abilities write it made did not persist.' );
	}

	/**
	 * MEDIUM (Codex hotfix re-check, new finding 3): the Abilities-tab AJAX toggle used to log the
	 * intended ability_enabled/ability_disabled diff BEFORE checking whether the write actually
	 * persisted, so a stale persistent object cache could leave a success-style row on record for
	 * an ability that was never actually made reachable. The row written on a failed persist must
	 * say so as an error, and no success-style row for the attempted ability may exist at all.
	 */
	public function test_the_abilities_ajax_toggle_logs_a_failure_row_not_a_success_diff_when_the_write_will_not_persist(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_enabled_abilities', array() );
		// A cache the plugin cannot repair for this option: whatever is written, the read keeps
		// coming back empty, which can never match the non-empty set this save intends to enable.
		add_filter( 'pre_option_aafm_enabled_abilities', static fn() => array() );

		$this->intercept_die();
		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['aafm_abilities'] = array( 'aafm/get-posts' );
		$json                    = $this->run_handler( 'aafm_ajax_save_abilities' );
		unset( $_POST['aafm_abilities'] );
		remove_all_filters( 'pre_option_aafm_enabled_abilities' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The AJAX toggle must not claim success when the write did not persist.' );
		$this->assertStringContainsString( 'object cache', strtolower( (string) ( $json['data']['message'] ?? '' ) ) );

		$rows = aafm_query_activity( array( 'per_page' => 20 ) );
		$this->assertEmpty(
			array_filter(
				$rows,
				static fn( array $r ): bool => 'ability_enabled' === ( $r['event_type'] ?? '' ) && 'aafm/get-posts' === ( $r['ability'] ?? '' )
			),
			'A write that never persisted must not leave a success-style ability_enabled row for the attempted ability.'
		);
		$row = $this->latest_log_row( 'aafm_enabled_abilities' );
		$this->assertSame( 'error', $row['status'] ?? null, 'The failed write must be logged as a single error row naming the option, not silently dropped.' );
	}
}
