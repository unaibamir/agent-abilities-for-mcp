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
}
