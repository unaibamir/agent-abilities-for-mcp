<?php
/**
 * Coexistence: the standalone `mcp-adapter` plugin.
 *
 * The standalone WordPress/mcp-adapter *plugin* bundles the same wordpress/mcp-adapter library we
 * do (both 0.5.0), but its main file `require_once`s includes/Autoloader.php UNCONDITIONALLY - a
 * plain require with no class_exists guard - which declares WP\MCP\Autoloader. Before the fix, our
 * eager load pre-declared WP\MCP\Autoloader from our own bundle, so that unguarded require threw a
 * non-catchable "Cannot declare class WP\MCP\Autoloader, because the name is already in use" fatal
 * and white-screened the whole site whenever both plugins were active - in EITHER activation order.
 *
 * These tests lock in the fix: our eager load skips the standalone plugin's bootstrap-shell classes
 * (an explicit allowlist of WP\MCP\Autoloader / WP\MCP\Plugin, plus a structural fallback for any
 * other direct child of WP\MCP\) so their unguarded require can declare its own copy without
 * colliding, while our eager load still loads every real runtime class. Both load orders are
 * exercised. The older-copy (Rank Math) win stays covered by AdapterEagerLoadTest + CoexistenceTest,
 * which remain green.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class StandaloneAdapterPluginTest extends TestCase {

	/**
	 * The bootstrap-shell classifier matches only the explicit allowlist of the standalone plugin's
	 * two scaffolding classes, and never a real runtime class (which always lives in a sub-namespace).
	 */
	public function test_plugin_shell_classifier_matches_only_bootstrap_classes(): void {
		// The two shell classes the standalone plugin declares itself.
		$this->assertTrue( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\Autoloader' ) );
		$this->assertTrue( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\Plugin' ) );

		// Every class that actually serves /mcp lives in a sub-namespace and must NOT be treated as
		// shell - skipping any of these would break our own adapter.
		$this->assertFalse( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\Core\\McpAdapter' ) );
		$this->assertFalse( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\Handlers\\Tools\\ToolsHandler' ) );
		$this->assertFalse( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\Transport\\HttpTransport' ) );

		// The two known classes are matched by the explicit allowlist (layer 1), independent of the
		// structural heuristic - they are the guaranteed, self-documenting core of the skip.
		// A not-yet-named direct child of WP\MCP\ is still caught by the structural fallback (layer 2)
		// so WSOD protection is never narrower than the two classes we happen to know today.
		$this->assertTrue( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\Autoloaderish' ) );

		// Degenerate / foreign inputs never match.
		$this->assertFalse( aafm_adapter_is_plugin_shell_class( 'WP\\MCP\\' ) );
		$this->assertFalse( aafm_adapter_is_plugin_shell_class( 'Some\\Other\\Thing' ) );
	}

	/**
	 * Our real bundle no longer eager-declares its own bootstrap-shell classes, so the standalone
	 * plugin's unguarded require has a clean name to declare.
	 */
	public function test_real_bundle_shell_classes_are_not_declared_by_eager_load(): void {
		// The plugin bootstrap already ran the eager load. The shell classes must remain undeclared
		// (the standalone plugin, if active, is the one that declares them - not us).
		$this->assertFalse(
			class_exists( 'WP\\MCP\\Autoloader', false ),
			'Eager load must NOT pre-declare WP\\MCP\\Autoloader - that is the redeclaration-fatal surface.'
		);
		$this->assertFalse(
			class_exists( 'WP\\MCP\\Plugin', false ),
			'Eager load must NOT pre-declare WP\\MCP\\Plugin.'
		);

		// But our runtime adapter IS committed to our 0.5.0 copy (the win we must not lose).
		$this->assertTrue( class_exists( \WP\MCP\Core\McpAdapter::class, false ) );
		$this->assertSame( '0.5.0', \WP\MCP\Core\McpAdapter::VERSION );
	}

	/**
	 * Order A (ours first - the real WSOD path): our eager load runs, then the standalone plugin's
	 * unguarded require declares its own shell class. This must NOT fatal, and our runtime class in
	 * the same bundle must still load.
	 */
	public function test_ours_first_then_standalone_unguarded_require_does_not_fatal(): void {
		$fixtures = AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterShell/';

		$this->assertFalse( class_exists( 'WP\\MCP\\Autoloaderish', false ) );
		$this->assertFalse( class_exists( 'WP\\MCP\\Core\\Widget', false ) );

		// Our eager pass over a bundle that contains BOTH a shell file (WP\MCP\Autoloaderish) and a
		// runtime file (WP\MCP\Core\Widget).
		aafm_eager_require_adapter_dir( $fixtures . 'bundle/' );

		// The shell class was skipped: it is still undeclared, so the standalone plugin can declare
		// its own copy without a collision.
		$this->assertFalse(
			class_exists( 'WP\\MCP\\Autoloaderish', false ),
			'The plugin-shell class must be skipped by the eager load, not pre-declared.'
		);
		// The runtime class was loaded from our bundle.
		$this->assertTrue(
			class_exists( 'WP\\MCP\\Core\\Widget', false ),
			'A real runtime class must still be eager-loaded.'
		);
		$this->assertSame( 'bundle', \WP\MCP\Core\Widget::SOURCE );

		// Now the standalone plugin does its unguarded `require_once includes/Autoloader.php`. With
		// the shell class still undeclared this declares cleanly - no "already in use" fatal.
		require $fixtures . 'standalone/Autoloaderish.php';

		$this->assertSame(
			'standalone',
			\WP\MCP\Autoloaderish::SOURCE,
			'The standalone plugin must declare its OWN shell copy without colliding with ours.'
		);
	}

	/**
	 * Order B (theirs first): the standalone plugin loads first and declares its shell class, then
	 * our eager load runs over a bundle that also carries that class. It must skip it (no
	 * redeclaration fatal) and leave the standalone's copy in place.
	 */
	public function test_standalone_first_then_our_eager_load_does_not_fatal(): void {
		$fixtures = AAFM_PLUGIN_DIR . 'tests/Fixtures/AdapterShell/';

		// The standalone plugin loaded first and declared WP\MCP\Plugish.
		require $fixtures . 'foreign-first/Plugish.php';
		$this->assertSame( 'standalone', \WP\MCP\Plugish::SOURCE );

		// Our eager pass over a bundle that ALSO declares WP\MCP\Plugish must not fatal.
		aafm_eager_require_adapter_dir( $fixtures . 'bundle-b/' );

		$this->assertSame(
			'standalone',
			\WP\MCP\Plugish::SOURCE,
			'The standalone plugin copy must be retained; our eager load must not redeclare it.'
		);
	}
}
