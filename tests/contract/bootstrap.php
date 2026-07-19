<?php
/**
 * Bootstrap for the `contract` test suite.
 *
 * Unlike tests/bootstrap.php (which loads the fabricating vendor stubs), this bootstrap loads the
 * REAL, version-pinned vendor plugins from the throwaway WP test install so a green contract test
 * proves the vendor symbol genuinely exists and behaves as our abilities assume. Provision the
 * vendors first with tests/bin/install-vendors.sh.
 *
 * No stub file is loaded here on purpose: the whole point of the suite is to see the real vendor,
 * not our model of it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// Autoload the AAFM\Tests\ helper/base classes (AAFM\Tests\TestCase, the contract cases). Mirrors
// the self-contained loader in tests/bootstrap.php; kept out of composer.json for the same reason.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AAFM\\Tests\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = dirname( __DIR__ ) . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

/*
 * The contract suite installs real vendor plugins (WooCommerce, AIOSEO, Yoast create their own DB
 * tables), so it MUST run against a DEDICATED database. Sharing the fast suite's `wordpress_test`
 * would leave those vendor tables behind and break stub-based tests that expect them absent.
 *
 * Clone the generated wp-tests-config.php, swap DB_NAME to a contract-only database, create that
 * database, and point the WP test bootstrap at the clone via WP_TESTS_CONFIG_FILE_PATH.
 */
$_contract_db     = getenv( 'WP_CONTRACT_DB' ) ?: 'wordpress_contract';
$_source_config   = $_tests_dir . '/wp-tests-config.php';
$_contract_config = sys_get_temp_dir() . '/aafm-contract-wp-tests-config.php';

// This block provisions the contract database and config BEFORE WordPress loads, so $wpdb and
// WP_Filesystem do not exist yet; raw mysqli/file calls are unavoidable. WP_TESTS_CONFIG_FILE_PATH
// is a WordPress core constant, not a plugin global.
// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
if ( is_readable( $_source_config ) ) {
	$_config_php = (string) file_get_contents( $_source_config );

	// Extract the DB credentials so the contract database can be created before WP installs into it.
	$_db_creds = static function ( string $name ) use ( $_config_php ): string {
		return preg_match( "/define\(\s*'" . $name . "',\s*'([^']*)'/", $_config_php, $m ) ? $m[1] : '';
	};
	$_db_user  = $_db_creds( 'DB_USER' );
	$_db_pass  = $_db_creds( 'DB_PASSWORD' );
	$_db_host  = $_db_creds( 'DB_HOST' ) ?: 'localhost';

	/*
	 * Create the dedicated database, best-effort. Works when the test user may create databases
	 * (CI runs as root). In a restricted environment (DDEV grants the `db` user only its own
	 * databases) this quietly no-ops, so the contract DB must be pre-created once with an admin user:
	 *   ddev exec mysql -uroot -proot -h db -e \
	 *     'CREATE DATABASE IF NOT EXISTS wordpress_contract; \
	 *      GRANT ALL PRIVILEGES ON wordpress_contract.* TO "db"@"%"; FLUSH PRIVILEGES;'
	 */
	if ( function_exists( 'mysqli_connect' ) ) {
		mysqli_report( MYSQLI_REPORT_OFF );
		$_link = @mysqli_connect( $_db_host, $_db_user, $_db_pass );
		if ( $_link instanceof mysqli ) {
			@mysqli_query( $_link, 'CREATE DATABASE IF NOT EXISTS `' . $_contract_db . '`' );
			mysqli_close( $_link );
		}
	}

	// Write the contract config with the swapped database name and use it for this run.
	$_config_php = preg_replace(
		"/(define\(\s*'DB_NAME',\s*')[^']*(')/",
		'${1}' . $_contract_db . '${2}',
		$_config_php
	);
	file_put_contents( $_contract_config, (string) $_config_php );
	define( 'WP_TESTS_CONFIG_FILE_PATH', $_contract_config );
}
// phpcs:enable WordPress.DB.RestrictedFunctions, WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin and the real vendor plugins as must-use, before WordPress finishes booting.
 *
 * Each vendor main file is required only when present, so a partially-provisioned test core skips
 * the missing vendor (its contract tests then skip loudly rather than fabricate a pass).
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$plugins = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress' ) . '/wp-content/plugins';

		// Real vendors first, so their classes/functions exist before the plugin registers abilities.
		$vendor_mains = array(
			'woocommerce/woocommerce.php',
			'advanced-custom-fields/acf.php',
			'wordpress-seo/wp-seo.php',
			'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'seo-by-rank-math/rank-math.php',
			// WPML is licensed (no free wp.org zip) -- install-vendors.sh cannot provision
			// it, so this is normally absent and WpmlContractTest skips. Dropping a real,
			// licensed copy into this test core's plugins dir makes that leg assert for real.
			'sitepress-multilingual-cms/sitepress.php',
		);
		foreach ( $vendor_mains as $main ) {
			$path = $plugins . '/' . $main;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		require dirname( __DIR__, 2 ) . '/agent-abilities-for-mcp.php';
	}
);

/**
 * Once WordPress is loaded, create the WooCommerce schema and roles so behavioural contract tests
 * (customer role, shipping zones, order notes, attribute backfill) exercise a functioning store.
 * Reflection-only contract tests do not need this, but running it is harmless and deterministic.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( '\WC_Install' ) && ! get_option( 'aafm_contract_wc_installed' ) ) {
			\WC_Install::install();
			// Materialize the customer role and register post types for the current request.
			if ( function_exists( 'wc_get_page_id' ) && class_exists( '\WC_Post_types' ) ) {
				\WC_Post_types::register_post_types();
				\WC_Post_types::register_taxonomies();
			}
			update_option( 'aafm_contract_wc_installed', 1 );
		}
	}
);

require $_tests_dir . '/includes/bootstrap.php';

/*
 * Keep vendor boot-time PHP notices/warnings out of the test output stream. On a cold CI database a
 * vendor may warn before its async schema migration completes (see the config comment); those are
 * not converted to exceptions here, so without this they would print and trip output strictness.
 * Genuine failures still surface through explicit assertions and convertErrorsToExceptions.
 */
ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
