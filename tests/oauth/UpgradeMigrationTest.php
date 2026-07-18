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
 * Covers preservation of an absent toggle row, non-clobbering of a seeded '0' and of
 * an explicit opt-out, per-key independence, and the once-only guard.
 */
class UpgradeMigrationTest extends TestCase {

	/**
	 * An install that updated in place from a pre-1.3.0 version has NO stored toggle
	 * row and was running OAuth on the old on-by-default reader. The migration writes
	 * '1' for both toggles so the surface (and any live connection) keeps working
	 * after the off-by-default change.
	 */
	public function test_absent_rows_are_preserved_on(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '1', get_option( 'aafm_oauth_enabled' ) );
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );
		$this->assertTrue( aafm_oauth_enabled() );
		$this->assertTrue( aafm_oauth_dcr_enabled() );
	}

	/**
	 * A fresh 1.3.0 install seeds an explicit '0' row at activation before this
	 * migration ever runs, so the migration must leave those rows off - the
	 * off-by-default default is only correct for genuinely new installs.
	 */
	public function test_seeded_zero_rows_stay_off(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '0' );
		update_option( 'aafm_oauth_dcr_enabled', '0' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertSame( '0', get_option( 'aafm_oauth_dcr_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
		$this->assertFalse( aafm_oauth_dcr_enabled() );
	}

	/**
	 * Per-key independence: an operator who explicitly turned the main toggle off
	 * ('0' stored) keeps it off, while a sibling toggle that was never stored is
	 * still preserved on. An explicit opt-out is never clobbered.
	 */
	public function test_explicit_optout_is_kept_while_absent_sibling_preserved(): void {
		delete_option( 'aafm_oauth_toggle_migrated' );
		update_option( 'aafm_oauth_enabled', '0' );
		delete_option( 'aafm_oauth_dcr_enabled' );

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertSame( '1', get_option( 'aafm_oauth_dcr_enabled' ) );
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
