<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and our plugin.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// Autoload the AAFM\Tests\ helper/base classes (e.g. AAFM\Tests\TestCase). This is deliberately
// NOT served by a Composer autoload-dev map: keeping the test PSR-4 mapping out of composer.json
// avoids any tooling folding the dev autoload (and tests/phpstan-stubs.php's global shims —
// WC_Payment_Gateways, ACF, …) into the shipped production autoloader/classmap. A self-contained
// PSR-4 autoloader here keeps the suite working without that coupling.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AAFM\\Tests\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/agent-abilities-for-mcp.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// Shared host-API stub helpers for the Wave 4 integration tests. Loaded after the WP
// test bootstrap so add_filter() and the plugin functions the stubs reference exist.
// AcfStubStore / WcStubStore are the ACF and WooCommerce stubs' backing stores and must load
// before the trait that uses them.
require_once __DIR__ . '/stubs/AcfStubStore.php';
require_once __DIR__ . '/stubs/AioseoStubStore.php';
require_once __DIR__ . '/stubs/WcStubStore.php';
require_once __DIR__ . '/stubs/WcAttributeStubStore.php';
require_once __DIR__ . '/stubs/WcOrderStubStore.php';
require_once __DIR__ . '/stubs/WcCustomerStubStore.php';
require_once __DIR__ . '/stubs/WcCouponStubStore.php';
require_once __DIR__ . '/stubs/WcShippingStubStore.php';
require_once __DIR__ . '/stubs/WcTaxStubStore.php';
require_once __DIR__ . '/stubs/WcGatewayStubStore.php';
require_once __DIR__ . '/stubs/IntegrationStubs.php';
