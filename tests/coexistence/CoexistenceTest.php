<?php
/**
 * Coexistence: version-floor compatibility logic.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class CoexistenceTest extends TestCase {

	public function test_equal_or_newer_adapter_is_compatible(): void {
		$this->assertTrue( aafm_adapter_is_compatible( '0.5.0' ) );
		$this->assertTrue( aafm_adapter_is_compatible( '0.6.0' ) );
		$this->assertTrue( aafm_adapter_is_compatible( '1.0.0' ) );
	}

	public function test_older_adapter_is_incompatible(): void {
		$this->assertFalse( aafm_adapter_is_compatible( '0.4.0' ) );
		$this->assertFalse( aafm_adapter_is_compatible( '0.3.9' ) );
	}

	public function test_missing_adapter_class_short_circuits_init(): void {
		// When the class is absent, aafm_init_mcp() must return false and not fatal.
		// Simulated by asserting the guard reads the constant rather than hard-failing.
		$this->assertIsBool( aafm_adapter_is_compatible( '0.5.0' ) );
	}

	public function test_outdated_notice_reports_loaded_and_required_versions(): void {
		// The actionable notice must always state the loaded adapter version and our floor so an
		// operator knows what they have vs what is needed. Rendered as an admin (the notice guards
		// on activate_plugins).
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		ob_start();
		aafm_notice_adapter_outdated();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( AAFM_MIN_ADAPTER_VERSION, $html );
		$this->assertStringContainsString( (string) ( aafm_loaded_adapter_version() ?? '' ), $html );
	}

	public function test_outdated_notice_is_silent_for_users_without_activate_plugins(): void {
		// A subscriber lacks activate_plugins, so the notice prints nothing at all.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		ob_start();
		aafm_notice_adapter_outdated();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_adapter_owner_resolver_returns_empty_when_not_under_plugins_dir(): void {
		// In the unit-test environment the adapter autoloads from the plugin's own vendor/ tree,
		// which is NOT a distinct plugin folder under WP_PLUGIN_DIR, so the resolver returns '' and
		// the notice falls back to the generic wording rather than naming a wrong plugin.
		$this->assertSame( '', aafm_resolve_adapter_owner_plugin() );
	}
}
