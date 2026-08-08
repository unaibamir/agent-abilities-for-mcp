<?php
/**
 * Site-settings read/write abilities (Wave 2, Slice 4).
 *
 * The update-site-settings ability is the most dangerous write in the catalog: a careless
 * implementation could change siteurl/home/admin_email and lock out or take over a
 * site. These tests are the containment proof - the allowlist excludes every
 * takeover-class key, the closed schema plus the server-side allowlist reject any
 * smuggled key, and the integer bounds are clamped server-side so a 0 or 99 can never
 * be persisted.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class SiteSettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Enable the whole catalog and register categories + abilities, mirroring the
	 * idiom the catalog tests use (the Abilities API registry is process-wide).
	 */
	private function register_all(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		aafm_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	public function test_allowlist_excludes_takeover_class_keys(): void {
		$allow = aafm_allowed_site_settings();
		$this->assertContains( 'blogname', $allow );
		$this->assertContains( 'timezone_string', $allow );
		foreach ( array( 'siteurl', 'home', 'admin_email', 'default_role', 'users_can_register' ) as $danger ) {
			$this->assertNotContains( $danger, $allow, "$danger must never be agent-writable in v1." );
		}
	}

	public function test_allowlist_filter_can_narrow_but_never_widen_to_a_takeover_key(): void {
		// A rogue filter tries to ADD admin_email and siteurl. The post-filter array_diff
		// must re-strip them, so the dangerous keys can never be widened back in.
		$rogue = static function ( array $base ): array {
			$base[] = 'admin_email';
			$base[] = 'siteurl';
			return $base;
		};
		add_filter( 'aafm_allowed_site_settings', $rogue );
		$allow = aafm_allowed_site_settings();
		remove_filter( 'aafm_allowed_site_settings', $rogue );

		$this->assertNotContains( 'admin_email', $allow, 'A rogue filter widened the allowlist to admin_email.' );
		$this->assertNotContains( 'siteurl', $allow, 'A rogue filter widened the allowlist to siteurl.' );
	}

	/**
	 * B50: "the set can only be NARROWED" was only true for the five takeover-class keys the
	 * array_diff stripped. Any OTHER option - template, active_plugins, WPLANG - could be
	 * ADDED by a filter, and update-site-settings would then happily write it. Narrowing must
	 * mean the filtered set intersects the fixed base, so a filter can remove but never add.
	 */
	public function test_allowlist_filter_cannot_add_any_key_outside_the_base(): void {
		$rogue = static function ( array $base ): array {
			$base[] = 'template';       // Theme switch: not in the $never list, still dangerous.
			$base[] = 'active_plugins'; // Ditto.
			return $base;
		};
		add_filter( 'aafm_allowed_site_settings', $rogue );
		$allow = aafm_allowed_site_settings();
		remove_filter( 'aafm_allowed_site_settings', $rogue );

		$this->assertNotContains( 'template', $allow, 'A filter must not widen the allowlist beyond the fixed base.' );
		$this->assertNotContains( 'active_plugins', $allow, 'A filter must not widen the allowlist beyond the fixed base.' );
		$this->assertContains( 'blogname', $allow, 'base keys the filter kept still pass.' );
	}

	/**
	 * B50 companion: narrowing itself still works after the intersect.
	 */
	public function test_allowlist_filter_can_still_narrow(): void {
		$narrow = static fn( array $base ): array => array_diff( $base, array( 'posts_per_page' ) );
		add_filter( 'aafm_allowed_site_settings', $narrow );
		$allow  = aafm_allowed_site_settings();
		remove_filter( 'aafm_allowed_site_settings', $narrow );

		$this->assertNotContains( 'posts_per_page', $allow );
		$this->assertContains( 'blogname', $allow );
	}

	public function test_get_site_settings_returns_allowlisted_values_for_admin_only(): void {
		$this->register_all();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/get-site-settings' )->check_permissions( array() ) );

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/get-site-settings' )->execute( array() );
		$this->assertArrayHasKey( 'settings', $res );
		$this->assertArrayHasKey( 'blogname', $res['settings'] );
		$this->assertArrayNotHasKey( 'admin_email', $res['settings'], 'must never return admin_email.' );
	}

	public function test_update_site_settings_writes_only_allowlisted_keys(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'       => 'New Name',
					'posts_per_page' => 7,
				),
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 'New Name', get_option( 'blogname' ) );
		$this->assertSame( 7, (int) get_option( 'posts_per_page' ) );
	}

	/**
	 * B11: re-submitting the current blogname as its human-readable form must be a no-op success,
	 * not a false rejection that also kills every co-submitted setting.
	 *
	 * Core stores blogname escaped ("Bob's Store" -> "Bob&#039;s Store"), so our unescaped sanitize
	 * of the same value differs from the stored value, yet sanitize_option() escapes it back to the
	 * stored value. The old code read that as core rejecting an invalid value and errored out,
	 * blocking the co-submitted posts_per_page too. The write must now succeed.
	 */
	public function test_update_site_settings_accepts_a_valid_no_op_on_an_escaped_name(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		// Establish the escaped stored form the way core does.
		update_option( 'blogname', "Bob's Store" );
		$stored = get_option( 'blogname' );
		$this->assertSame( 'Bob&#039;s Store', $stored, 'Precondition: core stores blogname escaped.' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'       => "Bob's Store", // The human-readable form the caller would send.
					'posts_per_page' => 12,
				),
			)
		);

		$this->assertIsArray( $res, 'A valid no-op on blogname must not fail the whole write.' );
		$this->assertSame( 12, (int) get_option( 'posts_per_page' ), 'The co-submitted setting must apply.' );
		$this->assertSame( 'Bob&#039;s Store', get_option( 'blogname' ), 'The name is unchanged.' );
	}

	/**
	 * The B11 fix must not swallow the guard's real purpose: an invalid value core silently reverts
	 * to the current one still has to be an error, not a no-op success.
	 */
	public function test_update_site_settings_still_rejects_an_invalid_timezone_revert(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'timezone_string' => 'Not/AZone' ),
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'An invalid timezone core reverts must still be reported as an error.'
		);
	}

	public function test_update_site_settings_rejects_a_non_allowlisted_key(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$before = get_option( 'admin_email' );
		$res    = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'admin_email' => 'attacker@evil.test' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'a non-allowlisted key must be rejected.' );
		$this->assertSame( $before, get_option( 'admin_email' ), 'admin_email must be untouched.' );
	}

	/**
	 * Headline containment proof: a takeover-class key smuggled alongside a legitimate one
	 * must reject the WHOLE call (fail-closed), and the site's real takeover settings -
	 * siteurl, home, admin_email, default_role, users_can_register - must be unchanged.
	 * A leak here is a site takeover or lockout.
	 */
	public function test_update_site_settings_contains_every_takeover_key(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		$before = array();
		foreach ( array( 'siteurl', 'home', 'admin_email', 'default_role', 'users_can_register' ) as $key ) {
			$before[ $key ] = get_option( $key );
		}

		// Smuggle every takeover key, paired with a legitimate one to prove the legitimate
		// write does NOT sneak the rest in past a partial apply.
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'           => 'Owned',
					'siteurl'            => 'https://attacker.test',
					'home'               => 'https://attacker.test',
					'admin_email'        => 'attacker@evil.test',
					'default_role'       => 'administrator',
					'users_can_register' => 1,
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'a smuggled takeover key must reject the whole call.' );
		foreach ( $before as $key => $value ) {
			$this->assertSame( $value, get_option( $key ), "$key must be unchanged after a smuggled write." );
		}
		// The legitimate key in the same call must NOT have been applied (fail-closed, not partial).
		$this->assertNotSame( 'Owned', get_option( 'blogname' ), 'a rejected call must not partial-apply blogname.' );
	}

	public function test_update_site_settings_requires_manage_options_and_is_destructive(): void {
		$this->register_all();
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/update-site-settings' )->check_permissions( array() ) );
		$ann = wp_get_ability( 'aafm/update-site-settings' )->get_meta_item( 'annotations' );
		$this->assertTrue( $ann['destructive'] );
	}

	public function test_update_site_settings_clamps_integer_ranges(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'posts_per_page' => 0,
					'start_of_week'  => 99,
				),
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 1, (int) get_option( 'posts_per_page' ), 'posts_per_page=0 must floor to 1.' );
		$this->assertSame( 6, (int) get_option( 'start_of_week' ), 'start_of_week=99 must clamp to 6.' );
	}

	public function test_update_site_settings_clamps_a_negative_posts_per_page_to_one(): void {
		// absint would turn -5 into 5; the floor/cap form must clamp it to 1.
		$this->register_all();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'posts_per_page' => -5 ),
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 1, (int) get_option( 'posts_per_page' ), 'a negative posts_per_page must floor to 1, not flip to 5.' );
	}

	/**
	 * Discovery: both abilities gate on manage_options object-independently, so they fall
	 * through to their permission_callback at list time. An admin must see both; an editor
	 * (no manage_options) must see neither.
	 */
	public function test_site_settings_are_discoverable_by_an_admin_and_hidden_from_an_editor(): void {
		$this->register_all();

		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-site-settings' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-site-settings' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-site-settings' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-site-settings' ) );
	}

	/**
	 * A non-scalar value is refused outright before any write - the agent can never store a
	 * structure, and the execute degrades on its OWN generic error, not the API safety net.
	 */
	public function test_update_site_settings_rejects_a_non_scalar_value(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$before = get_option( 'blogname' );
		$res    = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'blogname' => array( 'x', 'y' ) ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'a non-scalar value must be rejected.' );
		$this->assertSame( 'aafm_error', $res->get_error_code(), 'must degrade on our generic error, not the API exception net.' );
		$this->assertSame( $before, get_option( 'blogname' ), 'blogname must be untouched after a rejected non-scalar.' );
	}

	/**
	 * A malformed timezone_string is rejected by WordPress's own sanitize_option (which
	 * update_option fires): core silently REVERTS to the previously stored value rather
	 * than storing the bogus one. Left unchecked, the ability would report success on a
	 * write that never actually happened. It must instead compare the read-back to what
	 * was intended and error on the mismatch, leaving the prior value untouched.
	 */
	public function test_update_site_settings_errors_on_a_malformed_timezone_core_silently_reverts(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$before = get_option( 'timezone_string' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'timezone_string' => 'Not/AZone' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'a bogus timezone that core silently reverts must be reported as an error.' );
		$this->assertNotSame( 'Not/AZone', get_option( 'timezone_string' ), 'a bogus timezone must not persist verbatim.' );
		$this->assertSame( $before, get_option( 'timezone_string' ), 'the prior timezone must be untouched.' );
	}

	/**
	 * A valid timezone_string is accepted and reported as changed - the new revert-detection
	 * must not false-positive on a legitimate write.
	 */
	public function test_update_site_settings_accepts_a_valid_timezone(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'timezone_string' => 'Europe/Berlin' ),
			)
		);

		$this->assertIsArray( $res );
		$this->assertSame( 'Europe/Berlin', get_option( 'timezone_string' ) );
	}
}
