<?php
/**
 * Eager-load our bundled wordpress/mcp-adapter copy to win the WP\MCP\ class-declaration race.
 *
 * The wordpress/mcp-adapter library is bundled by multiple plugins, all under the shared
 * WP\MCP\ namespace. PHP can hold only one WP\MCP\Core\McpAdapter declaration per request, so
 * whichever plugin's autoloader declares it first wins for the whole site. A plugin shipping an
 * older copy via a plain Composer autoloader (confirmed: Rank Math SEO bundles 0.4.1) can win
 * that race, and our floor check then rejects the loaded version - so our /mcp route never
 * registers (site-wide 404 for our endpoint).
 *
 * Our copy is 0.5.0 and we MUST run it: 0.4.1 lacks the mcp_adapter_tools_list filter, our
 * request-time per-connection capability gate, so running on it would be a silent security
 * regression. The public McpAdapter API is identical between 0.4.1 and 0.5.0 and 0.5.0 is an
 * additive superset, so forcing our 0.5.0 to be the loaded copy is API-safe for other plugins.
 *
 * The fix: register a PREPENDED autoloader for the WP\MCP\ namespace resolving from our bundled
 * copy, then EAGER-DECLARE every adapter class from that copy (aafm_eager_load_adapter()), both at
 * the plugin file's top level. The win does NOT come from folder-name ordering: plugins load in
 * activation order (the active_plugins option), not alphabetically. It comes from eager-declare
 * beating lazy-autoload - we declare the WP\MCP\ classes outright at our include time, while a
 * sibling shipping the adapter as a plain Composer library only declares them on first reference,
 * so PHP commits to our copy first. McpAdapter is a final class with no declaration-time
 * dependencies, so eager resolution is clean.
 *
 * MAINTENANCE: when the bundled wordpress/mcp-adapter is updated, re-verify
 * aafm_adapter_namespace_map() against each bundled package's composer.json PSR-4 map (the adapter
 * AND the php-mcp-schema package it depends on), re-check the /includes/Cli/ skip in
 * aafm_eager_load_adapter() - confirm it still covers the WP-CLI-only classes, and re-check the
 * plugin-shell skip (aafm_adapter_is_plugin_shell_class()) still names the standalone plugin's
 * bootstrap classes. Also confirm whether any new runtime-only directory needs the same treatment.
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
 * able to resolve - and win - both. Order does not matter here: the two prefixes are mutually
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
		// If realpath() returns false - e.g. an open_basedir restriction blocks the path, or the
		// vendor symlink is broken - we return null and the plugin degrades safely to the
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
 * Reverse of aafm_adapter_class_to_path(): derive the FQCN a bundled file declares from its path.
 *
 * Pure string mapping (no filesystem I/O) so the eager loader can ask "is this file's class already
 * declared?" before it require_once's the file. Given a PSR-4 base directory and its namespace
 * prefix, a file at "{base}Core/McpAdapter.php" maps to "{prefix}Core\McpAdapter". Returns null for
 * any path outside the base or without a .php extension, so an unexpected path falls back to the
 * old unconditional require rather than guessing a wrong class name.
 *
 * @param string $path   Absolute path to a bundled PHP file.
 * @param string $base   PSR-4 base directory (matching the prefix), trailing slash optional.
 * @param string $prefix Namespace prefix for that base, including its trailing separator.
 * @return string|null Fully-qualified class name, or null when the path is not under the base.
 */
function aafm_adapter_path_to_class( string $path, string $base, string $prefix ): ?string {
	$path = wp_normalize_path( $path );
	$base = rtrim( wp_normalize_path( $base ), '/' ) . '/';

	if ( 0 !== strncmp( $path, $base, strlen( $base ) ) ) {
		return null;
	}

	$relative = substr( $path, strlen( $base ) );
	if ( '.php' !== strtolower( substr( $relative, -4 ) ) ) {
		return null;
	}

	$relative = substr( $relative, 0, -4 );

	return $prefix . str_replace( '/', '\\', $relative );
}

/**
 * Whether a bundled FQCN is one of the standalone plugin's bootstrap-shell classes.
 *
 * The wordpress/mcp-adapter *plugin* (as opposed to the adapter runtime) ships two bootstrap files
 * directly under includes/: Autoloader.php (WP\MCP\Autoloader) and Plugin.php (WP\MCP\Plugin). Its
 * main plugin file requires includes/Autoloader.php UNCONDITIONALLY - a plain `require_once` with no
 * class_exists guard (mcp-adapter.php -> includes/Autoloader.php:19 `final class Autoloader`). If our
 * eager load has already declared WP\MCP\Autoloader from our bundle, that unguarded require throws a
 * non-catchable "Cannot declare class WP\MCP\Autoloader, because the name is already in use" fatal
 * and white-screens the whole site. This is a risk in EITHER load order (plugins load in activation
 * order, not by folder name): if our eager declare runs first, their unguarded require then collides;
 * if their plugin runs first, they declare it and our require would collide. Skipping these shell
 * classes is therefore correct regardless of which plugin loads first.
 *
 * These two classes are pure plugin scaffolding: the adapter RUNTIME that actually serves /mcp
 * (WP\MCP\Core\*, Handlers\*, Domain\*, Transport\*, Infrastructure\*, Servers\*, Abilities\*) never
 * references either of them, so we gain nothing by pre-declaring them and lose coexistence by doing
 * so. We therefore skip them in the eager load: the standalone plugin's unguarded require then
 * declares its OWN copy with no collision, while our eager load still commits PHP to our 0.5.0
 * McpAdapter (the class that carries the per-connection capability gate). This does NOT weaken the
 * Rank Math case: Rank Math bundles an older adapter as a plain Composer LIBRARY (lazy autoloader, no
 * unguarded plugin-shell require) and loads after us, so our eager McpAdapter still wins that race.
 *
 * Two layers, allowlist first (hardened):
 *   1. An explicit allowlist of the two classes the standalone plugin is known to declare itself -
 *      WP\MCP\Autoloader and WP\MCP\Plugin. These are always skipped, named and self-documenting, so
 *      the real, present coexistence case never depends on a heuristic.
 *   2. A broad structural fallback: any OTHER direct child of WP\MCP\ (nothing after stripping
 *      "WP\MCP\" contains a separator) is also treated as scaffolding. This keeps WSOD protection if
 *      the standalone ever renames or adds a bootstrap-shell file we do not yet name. Every runtime
 *      class lives in a sub-namespace (WP\MCP\Core\..., WP\MCP\Handlers\...), and the only direct
 *      children of WP\MCP\ in our bundle are exactly the two allowlisted shell classes, so the
 *      fallback never skips a class the /mcp path needs.
 *
 * A pure allowlist (dropping layer 2) would be sharper but would re-open the redeclaration WSOD for
 * any standalone shell class not named above; the fallback is deliberately retained so coverage is
 * never narrower than the failure mode this whole mechanism exists to prevent.
 *
 * @param string $fqcn Fully-qualified class name derived from a bundled file path.
 * @return bool True when the class is a WP\MCP\ bootstrap-shell class we must not pre-declare.
 */
function aafm_adapter_is_plugin_shell_class( string $fqcn ): bool {
	// Layer 1 - explicit allowlist. The standalone wordpress/mcp-adapter plugin `require_once`s these
	// two directly under includes/, unguarded, so their names must stay free for it to declare.
	$shell_classes = array(
		'WP\\MCP\\Autoloader',
		'WP\\MCP\\Plugin',
	);
	if ( in_array( $fqcn, $shell_classes, true ) ) {
		return true;
	}

	// Layer 2 - structural fallback for an as-yet-unnamed direct child of WP\MCP\.
	$prefix = 'WP\\MCP\\';
	if ( 0 !== strncmp( $fqcn, $prefix, strlen( $prefix ) ) ) {
		return false;
	}

	$remainder = substr( $fqcn, strlen( $prefix ) );

	return '' !== $remainder && false === strpos( $remainder, '\\' );
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
 * pulled in during that eager load - it is the only WP\MCP\ autoloader registered that early, so
 * no foreign copy can answer those - and (b) cover installs with no conflicting sibling at all.
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
 * request. The win is eager-declare vs lazy-autoload, not folder ordering: plugins load in
 * activation order (the active_plugins option), not alphabetically, but a sibling that ships the
 * adapter as a plain Composer library only declares its classes on first reference, whereas we
 * declare all of our 0.5.0 WP\MCP\ classes here, during our plugin-include phase. That makes PHP
 * commit to our copy; a later sibling that references the same class then transparently uses ours. The public
 * McpAdapter API is identical across 0.4.1 and 0.5.0 (0.5.0 is an additive superset), so a
 * 0.4.1-expecting consumer keeps working - and we keep the per-connection capability gate that
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
 * The standalone plugin's bootstrap-shell classes (WP\MCP\Autoloader, WP\MCP\Plugin) are also
 * skipped: the standalone wordpress/mcp-adapter plugin `require_once`s its own includes/Autoloader.php
 * UNCONDITIONALLY, so pre-declaring WP\MCP\Autoloader from our bundle makes that unguarded require
 * fatal and WSOD the site whenever both plugins are active. Our runtime never touches those classes.
 * See aafm_adapter_is_plugin_shell_class().
 *
 * Cost is negligible: aafm_init_mcp() already calls McpAdapter::instance() on every request, which
 * loads the adapter anyway - eager-loading the remaining sibling classes in the same phase adds
 * only a handful of require_once calls on already-bundled files.
 *
 * Inverse-version trade: this override is version-agnostic - it forces ANY later-loading sibling
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

	aafm_eager_require_adapter_dir( AAFM_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/includes/' );

	// Now that a copy is committed, guard the request-time per-connection capability gate. The
	// version floor (bootstrap.php) only proves the copy REPORTS an in-range version; it cannot
	// prove that copy still APPLIES the mcp_adapter_tools_list filter our gate rides on. Priority 5
	// runs before server.php registers aafm_register_mcp_server (priority 10) on mcp_adapter_init,
	// so a stripped copy is caught before create_server ever registers the /mcp route. See
	// aafm_guard_adapter_capability_gate().
	add_action( 'mcp_adapter_init', 'aafm_guard_adapter_capability_gate', 5 );
}

/**
 * Recursively require every WP\MCP\ class file under $base, skipping any already declared.
 *
 * Split out of aafm_eager_load_adapter() (no static guard of its own) so the redeclaration-safety
 * behaviour can be exercised against a fixture directory. The eager-load pass only ever wins the
 * WP\MCP\ race when OUR file runs first; if a sibling that loads before us already declared one of
 * these classes (e.g. a plugin earlier in activation order that eager-declares its own mcp-adapter
 * copy), an
 * unconditional require_once would throw a non-catchable "Cannot declare class … already in use"
 * fatal and white-screen the whole site before bootstrap.php's floor notice can render. So before
 * requiring a file we derive the class it declares and skip it when that class/interface/trait
 * already exists - making the eager load idempotent against foreign pre-declaration and letting the
 * floor/notice fallback take over instead of fataling.
 *
 * @param string $base PSR-4 base directory for the WP\MCP\ namespace, with trailing slash.
 * @return void
 */
function aafm_eager_require_adapter_dir( string $base ): void {
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

		$fqcn = aafm_adapter_path_to_class( $path, $base, 'WP\\MCP\\' );

		// Skip the standalone plugin's bootstrap-shell classes (WP\MCP\Autoloader, WP\MCP\Plugin).
		// Its main plugin file `require_once`s includes/Autoloader.php UNCONDITIONALLY (no guard), so
		// if we pre-declared WP\MCP\Autoloader here PHP would fatal on their redeclaration and WSOD the
		// site whenever both plugins are active. Our runtime never uses these classes, so not declaring
		// them costs nothing and lets the standalone plugin coexist. See
		// aafm_adapter_is_plugin_shell_class() for the full rationale (and the Rank Math non-regression).
		if ( null !== $fqcn && aafm_adapter_is_plugin_shell_class( $fqcn ) ) {
			continue;
		}

		// If a class this file declares is already in scope (a sibling that loaded before us
		// declared its own copy), requiring our file would fatal on redeclaration. Skip it: PHP
		// keeps the already-declared copy, and our floor/notice fallback handles a version mismatch.
		if ( null !== $fqcn
			&& ( class_exists( $fqcn, false ) || interface_exists( $fqcn, false ) || trait_exists( $fqcn, false ) )
		) {
			continue;
		}

		require_once $path;
	}
}

/**
 * Whether a PHP source file applies the per-connection capability filter our request-time gate rides on.
 *
 * The adapter's tools/list handler is expected to run
 * `apply_filters( 'mcp_adapter_tools_list', $tools, $server )` while dispatching tools/list - the
 * hook where aafm_filter_mcp_tools_list() (server.php) drops any tool the connection may not call.
 * A sibling that pre-declares an in-range adapter COPY with that apply_filters() call stripped would
 * clear the version floor yet silently disable the gate: our callback stays registered but the
 * loaded copy never invokes it. Scanning the loaded handler's own source for the call detects a
 * stripped copy regardless of the version string it reports, which the version floor alone cannot.
 *
 * Pure (path in, bool out) with no side effects beyond a read, so it can be exercised against
 * fixtures. Returns false when the file is unreadable or the call is absent - the caller fails safe.
 *
 * @param string $file Absolute path to the PHP file that should apply the filter.
 * @return bool True when the file contains an apply_filters() call on 'mcp_adapter_tools_list'.
 */
function aafm_adapter_file_applies_tools_list_filter( string $file ): bool {
	if ( '' === $file || ! is_readable( $file ) ) {
		return false;
	}

	// Reading a local, already-loaded PHP source file for integrity verification; WP_Filesystem is
	// unnecessary and not initialised this early in the request.
	$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( false === $source ) {
		return false;
	}

	// Match an actual apply_filters() CALL on the exact hook, not a substring of the source. Walk the
	// token stream for the function-name token 'apply_filters' (or apply_filters_ref_array) directly
	// followed by '(' and the literal 'mcp_adapter_tools_list'. A mention of the hook inside a
	// comment, a string, or a heredoc collapses to a single non-T_STRING token, so a decoy copy that
	// only names the hook in a docblock or a string literal never counts as the gate being present.
	$significant = array();
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$significant[] = $token;
	}

	$method_operators = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON );
	if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
		$method_operators[] = T_NULLSAFE_OBJECT_OPERATOR;
	}

	$total = count( $significant );
	for ( $i = 0; $i + 2 < $total; $i++ ) {
		$name = $significant[ $i ];
		if ( ! is_array( $name ) || T_STRING !== $name[0] ) {
			continue;
		}
		$lower = strtolower( $name[1] );
		if ( 'apply_filters' !== $lower && 'apply_filters_ref_array' !== $lower ) {
			continue;
		}

		// A method or static call ($x->apply_filters(...) / Cls::apply_filters(...)) is not the
		// global adapter hook, so skip it.
		if ( $i > 0 ) {
			$prev = $significant[ $i - 1 ];
			if ( is_array( $prev ) && in_array( $prev[0], $method_operators, true ) ) {
				continue;
			}
		}

		$open = $significant[ $i + 1 ];
		$hook = $significant[ $i + 2 ];
		if ( '(' === $open
			&& is_array( $hook )
			&& T_CONSTANT_ENCAPSED_STRING === $hook[0]
			&& 'mcp_adapter_tools_list' === trim( $hook[1], '\'"' )
		) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the LOADED adapter copy still carries the request-time per-connection capability gate.
 *
 * Reflects the loaded WP\MCP\Handlers\Tools\ToolsHandler (the class that dispatches tools/list) to
 * its source file and asserts that file applies the mcp_adapter_tools_list filter. Fails safe
 * (returns false) when the handler class is not loaded, its file cannot be resolved or read, or the
 * filter call is absent. Uses class_exists(..., false) so a missing handler is treated as "gate
 * absent" rather than triggering an autoload that could mask the very substitution we are checking.
 *
 * @return bool True when the loaded tools/list handler applies the capability filter.
 */
function aafm_adapter_capability_gate_present(): bool {
	$handler = 'WP\\MCP\\Handlers\\Tools\\ToolsHandler';

	if ( ! class_exists( $handler, false ) ) {
		return false;
	}

	try {
		$file = ( new ReflectionClass( $handler ) )->getFileName();
	} catch ( ReflectionException $e ) {
		return false;
	}

	if ( ! is_string( $file ) ) {
		return false;
	}

	return aafm_adapter_file_applies_tools_list_filter( $file );
}

/**
 * Apply the capability-gate fail-safe: refuse to register our /mcp server when the gate is absent.
 *
 * Split from aafm_guard_adapter_capability_gate() (which supplies the live gate-presence result) so
 * the decision can be exercised deterministically in tests without substituting the real adapter.
 * Mirrors the version-floor handling in bootstrap.php: unhook server registration so create_server
 * never runs (no ungated route) and surface an admin notice, rather than serving tools whose
 * per-connection visibility filter would never fire.
 *
 * @param bool $gate_present Whether the loaded adapter copy applies the capability filter.
 * @return void
 */
function aafm_apply_adapter_capability_gate_guard( bool $gate_present ): void {
	if ( $gate_present ) {
		return;
	}

	remove_action( 'mcp_adapter_init', 'aafm_register_mcp_server' );
	add_action( 'admin_notices', 'aafm_notice_adapter_capability_gate_missing' );
}

/**
 * Guard hook (mcp_adapter_init, priority 5): fail safe if the loaded adapter has no capability gate.
 *
 * Runs before server.php registers aafm_register_mcp_server (priority 10), so a stripped-but-in-range
 * adapter copy is caught before create_server registers the /mcp route.
 *
 * @return void
 */
function aafm_guard_adapter_capability_gate(): void {
	aafm_apply_adapter_capability_gate_guard( aafm_adapter_capability_gate_present() );
}

/**
 * Admin notice: the loaded MCP adapter copy is missing the request-time per-connection capability gate.
 *
 * A sibling plugin declared an in-range adapter copy whose mcp_adapter_tools_list filter has been
 * stripped, so the gate that decides which tools a connection may see would never run. We refuse to
 * register the /mcp server rather than expose an ungated route, and name the offending plugin when
 * it can be resolved (reusing bootstrap.php's resolver) so the operator knows what to update or
 * deactivate. All output escaped.
 *
 * @return void
 */
function aafm_notice_adapter_capability_gate_missing(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$plugin = function_exists( 'aafm_resolve_adapter_owner_plugin' ) ? aafm_resolve_adapter_owner_plugin() : '';

	echo '<div class="notice notice-error"><p>';
	if ( '' !== $plugin ) {
		printf(
			/* translators: %s: name of the plugin loading the stripped adapter copy. */
			esc_html__( 'Agent Abilities for MCP is disabled: the plugin %s is loading an MCP Adapter copy that is missing the per-connection capability gate, so agent tools cannot be served safely. Update or deactivate that plugin to enable agent tools.', 'agent-abilities-for-mcp' ),
			esc_html( $plugin )
		);
	} else {
		esc_html_e( 'Agent Abilities for MCP is disabled: another active plugin is loading an MCP Adapter copy that is missing the per-connection capability gate, so agent tools cannot be served safely. Update or deactivate that plugin to enable agent tools.', 'agent-abilities-for-mcp' );
	}
	echo '</p></div>';
}
