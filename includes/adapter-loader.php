<?php
/**
 * Eager-load our bundled wordpress/mcp-adapter copy to win the WP\MCP\ class-declaration race.
 *
 * The wordpress/mcp-adapter library is bundled by multiple plugins, all under the shared
 * WP\MCP\ namespace. PHP can hold only one WP\MCP\Core\McpAdapter declaration per request, so
 * whichever plugin's autoloader declares it first wins for the whole site. A plugin shipping an
 * older copy via a plain Composer autoloader (confirmed: Rank Math SEO bundles 0.4.1) can win
 * that race, and our floor check then rejects the loaded version — so our /mcp route never
 * registers (site-wide 404 for our endpoint).
 *
 * Our copy is 0.5.0 and we MUST run it: 0.4.1 lacks the mcp_adapter_tools_list filter, our
 * request-time per-connection capability gate, so running on it would be a silent security
 * regression. The public McpAdapter API is identical between 0.4.1 and 0.5.0 and 0.5.0 is an
 * additive superset, so forcing our 0.5.0 to be the loaded copy is API-safe for other plugins.
 *
 * The fix: register a PREPENDED autoloader for the WP\MCP\ namespace, resolving from our bundled
 * copy, at the plugin file's top level. Because plugin folders load alphabetically and we sort
 * first as "agent-abilities-for-mcp", this runs before any sibling's autoloader is even loaded;
 * McpAdapter is a final class with no declaration-time dependencies, so eager resolution is clean.
 *
 * MAINTENANCE: when the bundled wordpress/mcp-adapter is updated, re-verify
 * aafm_adapter_namespace_map() against each bundled package's composer.json PSR-4 map (the adapter
 * AND the php-mcp-schema package it depends on), and re-check the /includes/Cli/ skip in
 * aafm_eager_load_adapter() — confirm it still covers the WP-CLI-only classes, and whether any new
 * runtime-only directory needs the same skip treatment.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The PSR-4 namespace prefixes our bundle owns, mapped to their base directory under vendor/.
 *
 * The adapter (WP\MCP\) declares method return types in the schema package (WP\McpSchema\), so
 * PHP's covariance check needs the schema classes available the moment an adapter class is
 * declared. Both packages are bundled by siblings under these shared namespaces, so we must be
 * able to resolve — and win — both. Order does not matter here: the two prefixes are mutually
 * exclusive (WP\McpSchema\ does not start with WP\MCP\, which requires a trailing backslash).
 *
 * @return array<string, string> Map of namespace prefix (with trailing separators) to base dir.
 */
function aafm_adapter_namespace_map(): array {
	return array(
		'WP\\MCP\\'       => AAFM_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/includes/',
		'WP\\McpSchema\\' => AAFM_PLUGIN_DIR . 'vendor/wordpress/php-mcp-schema/src/',
	);
}

/**
 * Map a bundled-namespace class name to the absolute file path inside our copy.
 *
 * Pure helper (no I/O side effects beyond filesystem existence checks) so the path mapping can
 * be asserted in isolation. Handles every prefix in aafm_adapter_namespace_map() (the adapter and
 * the schema package it depends on). Returns null for any class outside those namespaces, for any
 * name that would traverse outside its base directory, and for a class whose file does not exist
 * in our bundle (so other autoloaders can still resolve it).
 *
 * @param string $class_name Fully-qualified class name to resolve.
 * @return string|null Absolute path to the class file in our bundle, or null when not resolvable.
 */
function aafm_adapter_class_to_path( string $class_name ): ?string {
	foreach ( aafm_adapter_namespace_map() as $prefix => $base ) {
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			continue;
		}

		$relative = substr( $class_name, strlen( $prefix ) );

		// Reject any traversal attempt outright (e.g. WP\MCP\..\..\Evil).
		if ( false !== strpos( $relative, '..' ) ) {
			return null;
		}

		$file = $base . str_replace( '\\', '/', $relative ) . '.php';

		// The file must exist and resolve to a real path strictly inside the base directory.
		// If realpath() returns false — e.g. an open_basedir restriction blocks the path, or the
		// vendor symlink is broken — we return null and the plugin degrades safely to the
		// floor/notice fallback in bootstrap.php rather than fataling on a bogus require.
		$real_file = realpath( $file );
		$real_base = realpath( $base );

		if ( false === $real_file || false === $real_base ) {
			return null;
		}

		$real_base = rtrim( $real_base, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strncmp( $real_file, $real_base, strlen( $real_base ) ) ) {
			return null;
		}

		return $real_file;
	}

	return null;
}

/**
 * Register a prepended SPL autoloader that resolves the WP\MCP\ namespace from our bundled copy.
 *
 * Idempotent: a static guard ensures at most one loader is ever registered, no matter how many
 * times this runs. The loader is registered with throw=true and prepend=true so it sits at the
 * front of the autoload chain.
 *
 * On its own this autoloader cannot guarantee the win: every later-loading plugin's Composer
 * autoloader also registers with prepend=true and leapfrogs ours, so by the time WP\MCP\Core\
 * McpAdapter is first referenced a sibling's copy may resolve first. The race is settled
 * deterministically by aafm_eager_load_adapter() (below), which declares our classes outright.
 * This autoloader's real job is to (a) satisfy declaration-time interface/trait dependencies
 * pulled in during that eager load — it is the only WP\MCP\ autoloader registered that early, so
 * no foreign copy can answer those — and (b) cover installs with no conflicting sibling at all.
 *
 * @return void
 */
function aafm_register_adapter_autoloader(): void {
	static $registered = false;

	if ( $registered ) {
		return;
	}

	$registered = true;

	spl_autoload_register(
		static function ( string $class_name ): void {
			$path = aafm_adapter_class_to_path( $class_name );

			// On a miss, do nothing and let the next autoloader try. Never error.
			if ( null === $path ) {
				return;
			}

			require_once $path;
		},
		true,
		true
	);
}

/**
 * Eager-load every class in our bundled adapter copy, declaring them before any sibling can.
 *
 * A prepended autoloader cannot reliably win the WP\MCP\ class-declaration race: each
 * later-loading plugin's Composer autoloader also prepends itself and leapfrogs ours, so the
 * first reference to WP\MCP\Core\McpAdapter can resolve to a sibling's older copy (confirmed:
 * Rank Math SEO bundles 0.4.1). PHP, however, allows only ONE declaration of a class per
 * request. Because plugin folders load alphabetically and we sort first as
 * "agent-abilities-for-mcp", our main file runs before any conflicting sibling's file. Declaring
 * all of our 0.5.0 WP\MCP\ classes here, during our plugin-include phase, makes PHP commit to our
 * copy; a later sibling that references the same class then transparently uses ours. The public
 * McpAdapter API is identical across 0.4.1 and 0.5.0 (0.5.0 is an additive superset), so a
 * 0.4.1-expecting consumer keeps working — and we keep the per-connection capability gate that
 * 0.4.1 lacks.
 *
 * One recursive require_once pass is sufficient: if a class file references a not-yet-declared
 * WP\MCP\ interface or trait, the prepended autoloader registered above resolves it from our
 * bundle (it is the only WP\MCP\ autoloader active this early, so no foreign copy can intercept).
 *
 * The Cli/ subdirectory is skipped on purpose: McpCommand extends \WP_CLI_Command, which does not
 * exist outside a WP-CLI request, so declaring it here would fatal. Those classes are never used
 * by the REST /mcp path.
 *
 * Cost is negligible: aafm_init_mcp() already calls McpAdapter::instance() on every request, which
 * loads the adapter anyway — eager-loading the remaining sibling classes in the same phase adds
 * only a handful of require_once calls on already-bundled files.
 *
 * Inverse-version trade: this override is version-agnostic — it forces ANY later-loading sibling
 * (older OR newer copy) onto our 0.5.0, since PHP commits to whichever copy is declared first. The
 * floor/upper-bound check and "too old"/"too new" notices in bootstrap.php are the fallback for the
 * residual case where an incompatible copy is declared by a plugin that loads BEFORE us.
 *
 * Idempotent via require_once and a static guard.
 *
 * @return void
 */
function aafm_eager_load_adapter(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	$loaded = true;

	$base = AAFM_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/includes/';

	if ( ! is_dir( $base ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$path = $file->getPathname();

		// Skip CLI classes: McpCommand extends \WP_CLI_Command, which is undefined outside a
		// WP-CLI request, so declaring it here would fatal. The REST /mcp path never needs them.
		if ( false !== strpos( wp_normalize_path( $path ), '/includes/Cli/' ) ) {
			continue;
		}

		require_once $path;
	}
}
