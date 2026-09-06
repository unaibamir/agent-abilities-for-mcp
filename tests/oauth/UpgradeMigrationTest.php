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

		delete_option( 'aafm_oauth_dcr_default_on_migrated' );
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
}
