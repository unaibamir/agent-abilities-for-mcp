<?php
/**
 * Slice 5: the read-only plugin inventory (list-plugins).
 *
 * The list-plugins ability is the single read-only ability in this slice. It gates on the
 * activate_plugins capability (the bar WordPress puts on the Plugins screen) and returns
 * a per-plugin inventory of relative basename, name, version, and active state - never an
 * absolute filesystem path. The no-path-leak assertion is the load-bearing guarantee.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class PluginsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		aafm_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	public function test_list_plugins_requires_activate_plugins(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/list-plugins' )->check_permissions( array() ) );
	}

	public function test_list_plugins_allows_a_capable_admin(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( wp_get_ability( 'aafm/list-plugins' )->check_permissions( array() ) );
	}

	public function test_list_plugins_returns_inventory_without_paths(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/list-plugins' )->execute( array() );
		$this->assertArrayHasKey( 'plugins', $res );
		$this->assertNotEmpty( $res['plugins'] );
		$first = $res['plugins'][0];
		$this->assertArrayHasKey( 'plugin', $first );
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'version', $first );
		$this->assertArrayHasKey( 'active', $first );
		// The plugin field is the relative basename, never an absolute path.
		$this->assertStringNotContainsString( ABSPATH, (string) $first['plugin'], 'plugin field leaked an absolute path.' );
		// No absolute server path is leaked anywhere in the response.
		$json = (string) wp_json_encode( $res );
		$this->assertStringNotContainsString( ABSPATH, $json, 'absolute path leaked.' );
	}

	public function test_list_plugins_denial_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/list-plugins' )->check_permissions( array() );
		$denied = aafm_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 100,
			)
		);
		$this->assertNotEmpty( $denied, 'A denied list-plugins must write a denied audit row.' );
	}
}
