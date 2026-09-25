<?php
/**
 * A test stand-in for WooCommerce's data store registry, WC_Data_Store (woocommerce 11.1.1
 * includes/class-wc-data-store.php), plus the four core store class names it can report.
 *
 * Required only by tests/abilities/VendorReaderLoadTest.php, never by the bootstrap, so a test
 * that never asks for it keeps running with no WC_Data_Store class at all. Once required it stays
 * defined for the rest of the process, so every store name defaults to one that is not a core
 * store: a later stub-backed WooCommerce test keeps the path it had before this file loaded.
 * VendorReaderLoadTest calls WC_Data_Store::reset() in tear_down().
 *
 * The real load() returns a new WC_Data_Store, whose constructor throws Exception for a store it
 * cannot resolve (class-wc-data-store.php:95, :101, :107), and get_current_class_name() returns
 * the store's class name (:148-150). The stub keeps both shapes; the name comes from a map the
 * test sets, and a flag makes the constructor throw.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Data_Store' ) ) {
	// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
	eval(
		'class WC_Data_Store {
			public static $stores = array();
			public static $throw = false;
			public static $throw_error = false;
			private $current_class_name = "";
			public function __construct( $object_type ) {
				if ( self::$throw ) {
					throw new \Exception( "Invalid data store." );
				}
				if ( self::$throw_error ) {
					throw new \Error( "Store class failed to load." );
				}
				$this->current_class_name = self::$stores[ $object_type ] ?? "WC_Stub_Data_Store";
			}
			public static function load( $object_type ) {
				return new WC_Data_Store( $object_type );
			}
			public function get_current_class_name() {
				return $this->current_class_name;
			}
			public static function reset() {
				self::$stores = array();
				self::$throw       = false;
				self::$throw_error = false;
			}
		}'
	);
}

foreach ( array( 'WC_Product_Data_Store_CPT', 'WC_Order_Data_Store_CPT', 'WC_Coupon_Data_Store_CPT', 'WC_Customer_Data_Store' ) as $aafm_store_class ) {
	if ( ! class_exists( $aafm_store_class ) ) {
		eval( 'class ' . $aafm_store_class . ' {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class-name marker for the store check; tests only.
	}
}
unset( $aafm_store_class );

if ( ! class_exists( 'AAFM_Test_Product_Data_Store_Subclass' ) ) {
	eval( 'class AAFM_Test_Product_Data_Store_Subclass extends WC_Product_Data_Store_CPT {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a store that extends the core product store; tests only.
}

if ( ! class_exists( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' ) ) {
	// WooCommerce's HPOS order store (OrdersTableDataStore.php:192-196): only its static table-name
	// getter, which the rollback check reads.
	eval( 'namespace Automattic\\WooCommerce\\Internal\\DataStores\\Orders; class OrdersTableDataStore { public static function get_orders_table_name() { global $wpdb; return $wpdb->prefix . "wc_orders"; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
}
