<?php
/**
 * Coexistence: the adapter's automatic "default MCP server" must stay disabled.
 *
 * Wordpress/mcp-adapter auto-registers a server (server_id
 * 'mcp-adapter-default-server') that discovers and exposes ANY ability across ANY plugin
 * carrying meta.mcp.public=true, unless a consumer returns false on the
 * mcp_adapter_create_default_server filter (WP\MCP\Core\McpAdapter::maybe_create_default_server(),
 * vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php). This plugin disables it
 * (includes/bootstrap.php) and stamps meta.mcp.public=true on every ability it registers
 * (includes/register.php, aafm_register_ability_with_log()) on the documented understanding
 * that the stamp is inert as long as this filter stays wired. This test proves both halves of
 * that understanding still hold after every adapter bump, rather than trusting the docblock.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class AdapterDefaultServerSuppressedTest extends TestCase {

	public function test_default_server_creation_filter_is_registered_and_returns_false(): void {
		$this->assertNotFalse(
			has_filter( 'mcp_adapter_create_default_server', '__return_false' ),
			'includes/bootstrap.php must keep suppressing the adapter default server via mcp_adapter_create_default_server.'
		);

		$this->assertFalse(
			apply_filters( 'mcp_adapter_create_default_server', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook.
			'The mcp_adapter_create_default_server filter chain must resolve to false on this site.'
		);
	}

	/**
	 * McpAdapter::instance() only ever schedules init() on the rest_api_init action (priority
	 * 15; WP_CLI context uses 'init' at 20 instead, not exercised here). init() is what calls
	 * maybe_create_default_server() - the method that actually reads the
	 * mcp_adapter_create_default_server filter - and only afterwards fires mcp_adapter_init.
	 * Firing mcp_adapter_init directly, as an earlier draft of this test did, skips
	 * maybe_create_default_server() entirely and would pass even if the suppression filter were
	 * silently removed. Firing the real rest_api_init action is what actually exercises it.
	 *
	 * Init() also guards itself with a static $initialized flag, so if some earlier test in this
	 * same PHPUnit process already triggered rest_api_init (several do:
	 * tests/oauth/SchemaTest.php, tests/oauth/HandshakeTest.php, tests/audit/LogInternalsTest.php
	 * among others), this call is a no-op - but that is fine here: maybe_create_default_server()
	 * still ran for real, exactly once, at whichever point rest_api_init first fired in this
	 * process, against the same permanently-registered suppression filter this site always
	 * carries. Either way, the get_server() assertion below reflects a real evaluation of that
	 * filter, not a bypass of it.
	 */
	public function test_default_server_is_never_created(): void {
		$adapter = \WP\MCP\Core\McpAdapter::instance();

		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook, fired for real (not simulated) so McpAdapter::init()'s own hooked callback actually runs.

		$this->assertNull(
			$adapter->get_server( 'mcp-adapter-default-server' ),
			'The adapter default server must never be registered on this site.'
		);
	}

	/**
	 * Every native ability in the plugin's own catalog must carry meta.mcp.public=true
	 * (includes/register.php's documented stamp), so a future removal of that stamp is caught
	 * here rather than silently changing behaviour only if the suppression above ever regresses.
	 *
	 * Explicitly enables and registers the full native catalog first (the enabled-abilities
	 * option is deleted in TestCase::set_up(), so nothing is registered by default when this
	 * test runs in isolation - the idiom used across the suite, e.g.
	 * tests/abilities/ServerDiscoveryTest.php), then checks each of those catalog names
	 * specifically - NOT every wp_get_abilities() entry under the aafm/ prefix. The registry
	 * persists across the whole PHPUnit process and other suites deliberately register aafm/*
	 * fixture abilities with an explicit meta.mcp.public=false to prove the stamp never
	 * overwrites a caller's own value (tests/abilities/RegisterWrapperTest.php,
	 * test_ability_with_explicit_mcp_public_false_is_not_overwritten) - scanning by prefix alone
	 * would wrongly fail against that fixture whenever this runs after it in the same process.
	 */
	public function test_every_registered_aafm_ability_is_stamped_mcp_public(): void {
		$catalog_names = array_keys( aafm_get_abilities_registry() );
		$this->register_enabled( $catalog_names );

		$checked = 0;

		foreach ( $catalog_names as $name ) {
			$ability = wp_get_ability( $name );
			if ( null === $ability ) {
				continue;
			}

			$meta = $ability->get_meta();
			$this->assertTrue(
				(bool) ( $meta['mcp']['public'] ?? false ),
				sprintf( 'Ability "%s" is missing the meta.mcp.public stamp.', $name )
			);
			++$checked;
		}

		$this->assertGreaterThan( 0, $checked, 'No native abilities from the catalog were registered to check - the fixture setup is broken.' );
	}
}
