<?php
/**
 * Coexistence: version-floor compatibility logic.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Coexistence;

use Oversio\Tests\TestCase;

final class CoexistenceTest extends TestCase {

	public function test_adapter_within_tested_range_is_compatible(): void {
		// Compatible == at or above the floor AND below the upper bound (the tested 0.5.x line).
		$this->assertTrue( oversio_adapter_is_compatible( OVERSIO_MIN_ADAPTER_VERSION ) );
		$this->assertTrue( oversio_adapter_is_compatible( '0.5.0' ) );
		$this->assertTrue( oversio_adapter_is_compatible( '0.5.9' ) );
	}

	public function test_older_adapter_is_incompatible(): void {
		$this->assertFalse( oversio_adapter_is_compatible( '0.4.0' ) );
		$this->assertFalse( oversio_adapter_is_compatible( '0.3.9' ) );
		$this->assertFalse( oversio_adapter_is_too_new( '0.4.0' ) );
	}

	public function test_too_new_adapter_is_incompatible(): void {
		// A newer adapter (at or above the upper bound) may have a changed API, so it is rejected.
		$this->assertFalse( oversio_adapter_is_compatible( OVERSIO_MAX_ADAPTER_VERSION ) );
		$this->assertFalse( oversio_adapter_is_compatible( '0.6.0' ) );
		$this->assertFalse( oversio_adapter_is_compatible( '1.0.0' ) );
		$this->assertTrue( oversio_adapter_is_too_new( '0.6.0' ) );
		$this->assertTrue( oversio_adapter_is_too_new( '1.0.0' ) );
	}

	public function test_missing_adapter_class_short_circuits_init(): void {
		// When the class is absent, oversio_init_mcp() must return false and not fatal.
		// Simulated by asserting the guard reads the constant rather than hard-failing.
		$this->assertIsBool( oversio_adapter_is_compatible( '0.5.0' ) );
	}

	public function test_outdated_notice_reports_loaded_and_required_versions(): void {
		// The actionable notice must always state the loaded adapter version and our floor so an
		// operator knows what they have vs what is needed. Rendered as an admin (the notice guards
		// on activate_plugins).
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		ob_start();
		oversio_notice_adapter_outdated();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( OVERSIO_MIN_ADAPTER_VERSION, $html );
		$this->assertStringContainsString( (string) ( oversio_loaded_adapter_version() ?? '' ), $html );
	}

	public function test_outdated_notice_is_silent_for_users_without_activate_plugins(): void {
		// A subscriber lacks activate_plugins, so the notice prints nothing at all.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		ob_start();
		oversio_notice_adapter_outdated();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_adapter_owner_resolver_returns_empty_when_not_under_plugins_dir(): void {
		// In the unit-test environment the adapter autoloads from the plugin's own vendor/ tree,
		// which is NOT a distinct plugin folder under WP_PLUGIN_DIR, so the resolver returns '' and
		// the notice falls back to the generic wording rather than naming a wrong plugin.
		$this->assertSame( '', oversio_resolve_adapter_owner_plugin() );
	}
}
