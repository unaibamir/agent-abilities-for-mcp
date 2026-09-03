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

	public function test_uninstall_clears_a_stale_cached_enabled_list(): void {
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
	}
}
