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
	 * the off-by-default change. DCR now follows OAuth, so preserving OAuth on carries
	 * DCR on with it - no separate DCR row is written.
	 */
	public function test_absent_oauth_row_is_preserved_on_and_dcr_follows(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '1', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( get_option( 'aafm_oauth_dcr_enabled', false ), 'The migration no longer writes a DCR row.' );
		$this->assertTrue( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'DCR follows the preserved OAuth state.' );
	}

	/**
	 * A fresh 1.3.0 install seeds an explicit '0' OAuth row at activation before this
	 * migration ever runs, so the migration must leave it off - the off-by-default
	 * default is only correct for genuinely new installs. DCR follows off.
	 */
	public function test_seeded_zero_row_stays_off(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '0' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertFalse( aafm_oauth_dcr_enabled() );
	}

	/**
	 * An operator who explicitly turned OAuth off ('0' stored) keeps it off across the
	 * migration, and DCR follows off - even if a stale legacy DCR row says otherwise,
	 * because the helper no longer reads it. An explicit opt-out is never clobbered.
	 */
	public function test_explicit_optout_is_kept_and_dcr_follows_off(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '0' );
		update_option( 'aafm_oauth_dcr_enabled', '1' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'DCR follows the OAuth opt-out; the legacy DCR row is ignored.' );
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
