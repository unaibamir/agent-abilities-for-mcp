<?php
/**
 * Site-settings read/write abilities (Wave 2, Slice 4).
 *
 * The update-site-settings ability is the most dangerous write in the catalog: a careless
 * implementation could change siteurl/home/admin_email and lock out or take over a
 * site. These tests are the containment proof — the allowlist excludes every
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
	 * must reject the WHOLE call (fail-closed), and the site's real takeover settings —
	 * siteurl, home, admin_email, default_role, users_can_register — must be unchanged.
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
}
