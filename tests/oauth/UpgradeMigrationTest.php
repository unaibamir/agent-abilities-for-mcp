<?php
/**
 * Tests for the one-time OAuth-toggle upgrade migration
 * (aafm_oauth_preserve_toggle_on_upgrade).
 *
 * Before 1.3.0 the toggle readers defaulted ON, so an install that updated in place
 * from a pre-seed version - with no stored toggle row - was serving OAuth on the
 * default. 1.3.0 flips the default to OFF (fail-closed for new installs). This
 * migration preserves an upgrading site's prior on-by-default state so the change
 * never silently disables a live Claude/ChatGPT connection, while leaving a fresh
 * install's seeded '0' and an operator's explicit opt-out untouched, and running
 * exactly once.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Covers preservation of an absent OAuth toggle row (which carries DCR with it now),
 * non-clobbering of a seeded '0' and of an explicit opt-out, and the once-only guard.
 */
class UpgradeMigrationTest extends TestCase {

	/**
	 * F9 (1.7.5 deferred): the clean test bootstrap loads the plugin on muplugins_loaded, so
	 * aafm_oauth_dcr_adopt_on_by_default() already ran once for real before ANY test's own
	 * fixture setup - certifying aafm_oauth_dcr_default_on_touched (its independent B2 sibling
	 * signal) in the process. Deleting only aafm_oauth_dcr_default_on_migrated, as every DCR
	 * adoption test here does, left that touched marker still set to '1' from bootstrap, so the
	 * function's second guard returned early before ever reaching the DCR-enable write these
	 * tests exercise. Clear both markers here so every test starts from a genuinely
	 * not-yet-migrated state; a test that needs to simulate the sibling being already certified
	 * (test_dcr_adoption_keeps_one_and_adopts_absent()'s first call) still does that explicitly
	 * inline.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		delete_option( 'aafm_oauth_dcr_default_on_touched' );
	}

	/**
	 * An install that updated in place from a pre-1.3.0 version has NO stored OAuth
	 * toggle row and was running OAuth on the old on-by-default reader. The migration
	 * writes '1' for OAuth so the surface (and any live connection) keeps working after
	 * the off-by-default change. It writes no DCR row - DCR is a separate toggle handled
	 * by aafm_oauth_dcr_adopt_on_by_default() - and DCR reads on by default regardless.
	 */
	public function test_absent_oauth_row_is_preserved_on(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '1', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( get_option( 'aafm_oauth_dcr_enabled', false ), 'The OAuth migration does not write a DCR row.' );
		$this->assertTrue( aafm_oauth_enabled() );
	}

	/**
	 * The DCR default-on adoption flips an install that predates the on-by-default policy.
	 * Old DCR defaulted off and seeded an explicit '0', so most legacy installs hold '0';
	 * this migration writes '1' once so ChatGPT and Claude can connect (issue #90). A stale
	 * '0' is treated as the old default, not a considered opt-out, so it is flipped.
	 */
	public function test_dcr_adoption_flips_a_stored_zero_on(): void {
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );
		$this->assertTrue( aafm_oauth_dcr_enabled() );
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_default_on_migrated' ) );
	}

	/**
	 * The adoption leaves an explicit '1' untouched and adopts an absent row to '1'.
	 */
	public function test_dcr_adoption_keeps_one_and_adopts_absent(): void {
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '1' );
		aafm_oauth_dcr_adopt_on_by_default();
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );

		// B2 (1.7.5 deferred): resetting BOTH the guard row and its independent sibling to
		// simulate a genuinely fresh, not-yet-migrated install for the second scenario below -
		// the first call above already certified the sibling, and leaving it set would make the
		// second call return early without ever adopting the absent row this scenario tests.
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		delete_option( 'aafm_oauth_dcr_default_on_touched' );
		delete_option( 'aafm_oauth_dcr_enabled' );
		aafm_oauth_dcr_adopt_on_by_default();
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );
	}

	/**
	 * The adoption runs exactly once. After the guard is set, an operator who turns the
	 * DCR toggle off again is respected - the migration does not flip it back on.
	 */
	public function test_dcr_adoption_guard_respects_a_later_optout(): void {
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		aafm_oauth_dcr_adopt_on_by_default();
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );

		// Operator deliberately turns it back off after the one-time adoption.
		update_option( 'aafm_oauth_dcr_enabled', '0' );
		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame( '0', get_option( 'aafm_oauth_dcr_enabled' ), 'A post-adoption opt-out is not clobbered.' );
		$this->assertFalse( aafm_oauth_dcr_enabled() );
	}

	/**
	 * Codex round 7, R7-2: the guard used to read get_option()'s cache-trusting view. The database
	 * guard row is genuinely '1' (adoption already ran), but a stale persistent cache still claims
	 * it is '0' (not yet run) - the exact reproduction from the finding. The old code would rerun
	 * the migration and re-force DCR back on over the operator's later, deliberate opt-out.
	 */
	public function test_dcr_adoption_guard_ignores_a_stale_cache_claiming_not_yet_migrated(): void {
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		aafm_oauth_dcr_adopt_on_by_default();
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );

		// Operator deliberately turns it back off after the one-time adoption.
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		// A stale cache layer still claims the guard has not run, even though the real row is '1'.
		$all                                       = wp_load_alloptions( true );
		$all['aafm_oauth_dcr_default_on_migrated'] = '0';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_dcr_default_on_migrated', 'MISSING' ),
			'Precondition: the stale cache is what get_option() sees.'
		);

		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_dcr_enabled' ),
			'The migration must not rerun from a stale cache claiming it is still pending: a post-adoption opt-out is not clobbered.'
		);
	}

	/**
	 * Codex round 7, R7-2, the other direction: the database guard row is genuinely absent
	 * (adoption never ran), but a stale persistent cache claims it is already '1'. The old code
	 * would skip the migration entirely, permanently leaving a legacy install stuck with DCR off
	 * (the #90 footgun this migration exists to fix).
	 */
	public function test_dcr_adoption_runs_when_a_stale_cache_hides_an_absent_guard(): void {
		global $wpdb;

		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_oauth_dcr_default_on_migrated' ) );

		$all                                       = wp_load_alloptions( true );
		$all['aafm_oauth_dcr_default_on_migrated'] = '1';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame(
			'1',
			get_option( 'aafm_oauth_dcr_default_on_migrated', 'MISSING' ),
			'Precondition: the stale cache is what get_option() sees.'
		);
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'aafm_oauth_dcr_default_on_migrated' ) ),
			'Precondition: no DB row.'
		);

		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame(
			'1',
			get_option( 'aafm_oauth_dcr_enabled' ),
			'The migration must still run from the real (absent) database row, even though a stale cache claimed it had already completed.'
		);
	}

	/**
	 * A fresh 1.3.0 install seeds an explicit '0' OAuth row at activation before this
	 * migration ever runs, so the migration must leave it off - the off-by-default
	 * default is only correct for genuinely new installs.
	 */
	public function test_seeded_zero_row_stays_off(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '0' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
	}

	/**
	 * An operator who explicitly turned OAuth off ('0' stored) keeps it off across the
	 * migration - an explicit opt-out is never clobbered. DCR is a separate toggle and
	 * is not touched by this OAuth migration.
	 */
	public function test_explicit_oauth_optout_is_kept(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '0' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
	}

	/**
	 * Codex round 5, R5-3: the absence check used to read get_option()'s cache-trusting view, so
	 * a stale persistent object cache still serving an old value after the real row was gone
	 * would make the migration think a row already existed, skip the preservation write, and then
	 * mark itself done for good - permanently losing the pre-upgrade "on" state. The row is
	 * deleted directly (bypassing delete_option(), which would also clear the cache) and a stale
	 * '0' is planted in the alloptions cache, mirroring PersistentObjectCacheSwitchTest's
	 * plant_stale_on(), so this proves the migration reads the database, not the cache.
	 */
	public function test_absent_row_is_preserved_even_when_a_stale_cache_still_serves_a_value(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		delete_option( 'aafm_oauth_enabled' );

		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_oauth_enabled' ) );
		$all                       = wp_load_alloptions( true );
		$all['aafm_oauth_enabled'] = '0';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( '0', get_option( 'aafm_oauth_enabled', 'MISSING' ), 'Precondition: the stale cache is what get_option() sees.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'aafm_oauth_enabled' ) ), 'Precondition: no DB row.' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame(
			'1',
			get_option( 'aafm_oauth_enabled' ),
			'The migration must preserve the pre-upgrade on state from the real database row, even though a stale cache claimed a value was already stored.'
		);
		$this->assertTrue( aafm_oauth_enabled() );
	}

	/**
	 * The migration runs exactly once. After it has set its guard, a later absence of
	 * a toggle row (for example a plugin reset returning to the off-by-default state)
	 * must NOT be silently forced back on.
	 */
	public function test_guard_prevents_a_second_run(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		aafm_oauth_preserve_toggle_on_upgrade();
		$this->assertSame( '1', get_option( 'aafm_oauth_toggle_migrated' ) );

		delete_option( 'aafm_oauth_enabled' );
		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertFalse( get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
	}

	/**
	 * Codex round 8, R8-3: the migration-marker write's return value used to be discarded, so a
	 * certification failure on the guard itself (not the preceding preservation write, which was
	 * already checked) was indistinguishable from success. The guard must stay unset - so the
	 * migration is retried rather than recorded as done when it was not - and the failure must be
	 * logged rather than silent.
	 */
	public function test_toggle_migration_marker_failure_is_logged_and_leaves_the_guard_unset(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '1' );

		$this->make_option_write_unpersistable( 'aafm_oauth_toggle_migrated', '0' );
		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_toggle_migrated', '0' ),
			'A certification failure on the marker write must leave the guard unset, not silently record completion.'
		);
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm_oauth_toggle_migrated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-array key, not a meta query.
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows, 'A failed marker write must be logged, not silently retried forever with no trace.' );
	}

	/**
	 * Codex round 8, R8-3, the DCR sibling: same failure, same requirement - the guard stays
	 * unset and the failure is logged.
	 */
	public function test_dcr_adoption_marker_failure_is_logged_and_leaves_the_guard_unset(): void {
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		$this->make_option_write_unpersistable( 'aafm_oauth_dcr_default_on_migrated', '0' );
		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ), 'The DCR enable write is unaffected by the marker write failing.' );
		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_dcr_default_on_migrated', '0' ),
			'A certification failure on the marker write must leave the guard unset, not silently record completion.'
		);
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm_oauth_dcr_default_on_migrated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-array key, not a meta query.
				'status'  => 'error',
			)
		);
		$this->assertNotEmpty( $rows, 'A failed marker write must be logged, not silently retried forever with no trace.' );
	}

	/**
	 * B2 (1.7.5 deferred): the actual security property the second signal restores. The guard
	 * row's own write keeps failing to certify forever (never recovers, unlike a one-off blip),
	 * so under the old single-signal design every later request would re-read DCR from scratch
	 * and re-flip an operator's own opt-out back on, since a stored '0' looked indistinguishable
	 * from the untouched pre-migration default. With the independently keyed sibling signal
	 * certifying on the first call (the marker sabotage only targets the guard row, not the
	 * sibling), a later request must respect the opt-out even though the guard has still never
	 * been set.
	 */
	public function test_dcr_adoption_respects_a_later_optout_even_when_the_guard_write_never_persists(): void {
		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		$this->make_option_write_unpersistable( 'aafm_oauth_dcr_default_on_migrated', '0' );
		aafm_oauth_dcr_adopt_on_by_default();
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ), 'Precondition: the first call still adopts DCR on.' );
		$this->assertSame( '0', get_option( 'aafm_oauth_dcr_default_on_migrated', '0' ), 'Precondition: the guard write is still failing to certify.' );

		// Operator deliberately turns it back off. The guard has STILL never been set.
		update_option( 'aafm_oauth_dcr_enabled', '0' );
		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_dcr_enabled' ),
			'A persistently failing guard write must not mean a persistently re-enabled toggle: the operator\'s opt-out survives.'
		);
		$this->assertFalse( aafm_oauth_dcr_enabled() );
	}

	/**
	 * R2-4 (1.7.5 deferred, round 2): the toggle-preservation migration already completed, and the
	 * operator has since turned OAuth off deliberately - a decision unrelated to the migration
	 * itself. A transient failure reading the migration's OWN guard row must not be read as "never
	 * migrated": that would fall through to the absence check, find the (present, '0') row, but -
	 * before this fix - a failed read of THAT row also collapses to "absent" and rewrites it to
	 * '1', clobbering the opt-out. Faulting only the guard read is enough to prove the abort: this
	 * fails if aafm_oauth_preserve_toggle_on_upgrade() stops checking db_error on the guard read
	 * and falls through toward the write below.
	 */
	public function test_toggle_preservation_aborts_when_the_guard_read_fails(): void {
		update_option( 'aafm_oauth_toggle_migrated', '1' );
		update_option( 'aafm_oauth_enabled', '0' );

		$this->fail_option_read( 'aafm_oauth_toggle_migrated' );
		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_enabled' ),
			'A guard read that itself failed must not be treated as "never migrated" and overwrite an operator\'s explicit opt-out.'
		);
	}

	/**
	 * R2-4 (1.7.5 deferred, round 2): DCR adoption already completed (both markers certified) and
	 * the operator has since turned DCR off deliberately. A transient failure reading the FIRST
	 * guard row must not fall through toward re-flipping DCR back on - this is Codex's own named
	 * reproduction: "fail the two initial marker reads... both guards fall through, and the
	 * deliberate opt-out is rewritten to '1'." Fails if aafm_oauth_dcr_adopt_on_by_default() stops
	 * checking db_error on the guard read.
	 */
	public function test_dcr_adoption_aborts_when_the_guard_read_fails(): void {
		update_option( 'aafm_oauth_dcr_default_on_migrated', '1' );
		aafm_persist_operator_switch( 'aafm_oauth_dcr_default_on_touched', true );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		$this->fail_option_read( 'aafm_oauth_dcr_default_on_migrated' );
		aafm_oauth_dcr_adopt_on_by_default();

		$this->assertSame(
			'0',
			get_option( 'aafm_oauth_dcr_enabled' ),
			'A guard read that itself failed must not be treated as "never migrated" and overwrite an operator\'s explicit opt-out.'
		);
	}

	/**
	 * Makes the direct database SELECT aafm_read_option_views() issues for $option fail (not
	 * merely read absent), by rewriting that one query to target a table that does not exist -
	 * the same technique OauthRevokeAjaxTest uses for a write query, applied to this read.
	 *
	 * @param string $option Option name whose row-fetch query should fail.
	 * @return void
	 */
	private function fail_option_read( string $option ): void {
		add_filter(
			'query',
			static function ( string $query ) use ( $option ): string {
				return false !== strpos( $query, "option_name = '{$option}'" )
					? 'SELECT * FROM aafm_missing_table_for_test'
					: $query;
			}
		);
	}

	/**
	 * Makes a single option write to $option uncertifiable by reverting the row back to
	 * $stuck_raw_value immediately after WordPress writes it, so aafm_update_option_verified()'s
	 * post-write database read never matches what was intended and the write is reported as
	 * failed. Mirrors SettingsSaveTest's helper of the same name.
	 *
	 * @param string $option          Option name to sabotage.
	 * @param string $stuck_raw_value The value the row is forced back to after every write.
	 * @return void
	 */
	private function make_option_write_unpersistable( string $option, string $stuck_raw_value ): void {
		$revert = static function () use ( $option, $stuck_raw_value ): void {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
					$option,
					$stuck_raw_value
				)
			);
		};
		$guard  = static function ( $changed ) use ( $option, $revert ): void {
			if ( $changed === $option ) {
				$revert();
			}
		};
		add_action( 'added_option', $guard );
		add_action( 'updated_option', $guard );
	}
}
