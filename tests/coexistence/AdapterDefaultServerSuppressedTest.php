<?php
/**
 * Coexistence: the adapter's "default MCP server" follows aafm_default_server_mode, and this
 * plugin's own tools never appear on it.
 *
 * WordPress/mcp-adapter can register a server (server_id 'mcp-adapter-default-server') that
 * discovers and runs ANY ability carrying an effective meta.mcp.public=true, unless a consumer
 * returns false on the mcp_adapter_create_default_server filter
 * (WP\MCP\Core\McpAdapter::maybe_create_default_server()). This plugin no longer suppresses it
 * unconditionally: includes/default-server.php resolves the filter from the owner's mode (auto,
 * on, off). The invariant that matters is that our own tools are stamped meta.mcp.public=false
 * (includes/register.php) and refused on that server by a hard guard in every mode.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class AdapterDefaultServerSuppressedTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_default_server_mode' );
		parent::tear_down();
	}

	public function test_off_mode_creates_no_default_server(): void {
		update_option( 'aafm_default_server_mode', 'off' );
		$this->assertFalse(
			apply_filters( 'mcp_adapter_create_default_server', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
			'Off mode must resolve the adapter filter to false.'
		);
	}

	/**
	 * Every native ability in the plugin's own catalog must carry meta.mcp.public=false
	 * (includes/register.php's stamp), so the adapter's default server can never list or run it.
	 *
	 * Explicitly enables and registers the full native catalog first (the enabled-abilities
	 * option is deleted in TestCase::set_up(), so nothing is registered by default when this
	 * test runs in isolation), then checks each of those catalog names specifically - NOT every
	 * wp_get_abilities() entry under the aafm/ prefix, because other suites register aafm/*
	 * fixtures that the process keeps.
	 */
	public function test_every_registered_aafm_ability_is_stamped_not_public(): void {
		$catalog_names = array_keys( aafm_get_abilities_registry() );
		$this->register_enabled( $catalog_names );

		$checked = 0;
		foreach ( $catalog_names as $name ) {
			$ability = wp_get_ability( $name );
			if ( null === $ability ) {
				continue;
			}
			$meta = $ability->get_meta();
			$this->assertFalse(
				(bool) ( $meta['mcp']['public'] ?? true ),
				sprintf( 'Ability "%s" must carry meta.mcp.public = false.', $name )
			);
			++$checked;
		}

		$this->assertGreaterThan( 0, $checked, 'No native abilities from the catalog were registered to check.' );
	}
}
