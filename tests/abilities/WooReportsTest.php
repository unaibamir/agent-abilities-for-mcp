<?php
/**
 * Integration tests for the W4-WC7 abilities: wc-get-sales-report, wc-get-top-sellers-report,
 * wc-count-orders, wc-count-products, wc-count-customers, wc-list-payment-gateways,
 * wc-get-payment-gateway, wc-count-coupons, wc-update-payment-gateway.
 *
 * WooCommerce is not installed in the DDEV test environment. Payment gateways are backed by
 * WcGatewayStubStore through the WC_Payment_Gateway / WC_Payment_Gateways eval stubs. Order,
 * product, and coupon counts rely on wp_count_posts() against real WP post fixtures inserted
 * in set_up(). Sales / top-sellers reports exercise the executor's SQL against the real temp DB.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcGatewayStubStore;
use WP_Error;

final class WooReportsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->stub_wc_gateways();
		$this->seed_wc_gateways();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_reports();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcGatewayStubStore::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action Action name.
	 * @param callable $cb     Callback.
	 */
	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable and register the full WC7 ability set.
	 */
	private function register_wc_reports(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-get-sales-report',
				'aafm/wc-get-top-sellers-report',
				'aafm/wc-count-orders',
				'aafm/wc-count-products',
				'aafm/wc-count-customers',
				'aafm/wc-list-payment-gateways',
				'aafm/wc-get-payment-gateway',
				'aafm/wc-count-coupons',
				'aafm/wc-update-payment-gateway',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// Integration guard
	// =========================================================================

	/**
	 * All WC7 abilities must be absent from the registry when WooCommerce is inactive.
	 */
	public function test_abilities_hidden_when_woocommerce_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-get-sales-report', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-top-sellers-report', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-count-orders', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-count-products', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-count-customers', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-payment-gateways', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-payment-gateway', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-count-coupons', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-payment-gateway', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// aafm/wc-get-sales-report
	// =========================================================================

	/**
	 * Sales report returns the expected shape with zero values when no orders exist.
	 */
	public function test_get_sales_report_returns_shape(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_sales_report( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'total_sales', $res );
		$this->assertArrayHasKey( 'order_count', $res );
		$this->assertArrayHasKey( 'net_sales', $res );
		$this->assertArrayHasKey( 'average_sales', $res );
		$this->assertSame( 0, $res['order_count'] );
		$this->assertSame( '0.00', $res['total_sales'] );
	}

	/**
	 * Sales report accepts explicit date parameters without error.
	 */
	public function test_get_sales_report_accepts_date_params(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_sales_report(
			array(
				'start_date' => '2020-01-01',
				'end_date'   => '2020-12-31',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'total_sales', $res );
	}

	/**
	 * Sales report returns WP_Error when WooCommerce integration is inactive.
	 */
	public function test_get_sales_report_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_get_sales_report( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Sales report requires manage_woocommerce.
	 */
	public function test_get_sales_report_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-sales-report' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-get-top-sellers-report
	// =========================================================================

	/**
	 * Top sellers returns the expected shape with an empty items array.
	 */
	public function test_get_top_sellers_returns_shape(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_top_sellers_report( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'items', $res );
		$this->assertIsArray( $res['items'] );
	}

	/**
	 * T2-5: a seeded completed order with line items produces a non-empty, correct top-sellers
	 * row — product ids come from order ITEM data, not shop_order post meta.
	 */
	public function test_get_top_sellers_aggregates_order_line_items(): void {
		\AAFM\Tests\WcOrderStubStore::reset();
		// Product 101 is seeded by stub_woocommerce(); add a second product to rank below it.
		\AAFM\Tests\WcStubStore::seed( 202, array( 'name' => 'Runner Up' ) );

		$today = gmdate( 'Y-m-d\TH:i:s' );
		\AAFM\Tests\WcOrderStubStore::seed(
			6001,
			array(
				'status'       => 'completed',
				'date_created' => $today,
				'items'        => array(
					array(
						'product_id' => 101,
						'quantity'   => 5,
					),
					array(
						'product_id' => 202,
						'quantity'   => 2,
					),
				),
			)
		);
		\AAFM\Tests\WcOrderStubStore::seed(
			6002,
			array(
				'status'       => 'processing',
				'date_created' => $today,
				'items'        => array(
					array(
						'product_id' => 101,
						'quantity'   => 3,
					),
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_top_sellers_report( array( 'period' => 'month' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['items'], 'A seeded order with line items must produce rows.' );

		// Product 101 sold 8 (5 + 3) and ranks first; product 202 sold 2 and ranks second.
		$this->assertSame( 101, $res['items'][0]['product_id'] );
		$this->assertSame( 8, $res['items'][0]['quantity'] );
		$this->assertSame( 'Test Widget', $res['items'][0]['name'] );
		$this->assertSame( 202, $res['items'][1]['product_id'] );
		$this->assertSame( 2, $res['items'][1]['quantity'] );
	}

	/**
	 * TF-3: the date window is pushed into the wc_get_orders() query, not applied after loading
	 * every order. An order outside the window must not be aggregated, and the query that ran must
	 * carry the date window rather than an unbounded limit => -1 full table scan.
	 */
	public function test_get_top_sellers_constrains_query_to_window(): void {
		\AAFM\Tests\WcOrderStubStore::reset();
		\AAFM\Tests\WcStubStore::seed( 303, array( 'name' => 'Stale Product' ) );

		$in_window  = gmdate( 'Y-m-d\TH:i:s' );
		$out_window = gmdate( 'Y-m-d\TH:i:s', strtotime( '-2 years' ) );

		// In-window order: product 101, qty 4.
		\AAFM\Tests\WcOrderStubStore::seed(
			7001,
			array(
				'status'       => 'completed',
				'date_created' => $in_window,
				'items'        => array(
					array(
						'product_id' => 101,
						'quantity'   => 4,
					),
				),
			)
		);
		// Out-of-window order, two years ago: must never be aggregated.
		\AAFM\Tests\WcOrderStubStore::seed(
			7002,
			array(
				'status'       => 'completed',
				'date_created' => $out_window,
				'items'        => array(
					array(
						'product_id' => 303,
						'quantity'   => 50,
					),
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_top_sellers_report( array( 'period' => 'month' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// Only the in-window product is aggregated; the two-year-old order is excluded.
		$product_ids = array_column( $res['items'], 'product_id' );
		$this->assertContains( 101, $product_ids, 'The in-window product must be aggregated.' );
		$this->assertNotContains( 303, $product_ids, 'A product sold only outside the window must not be aggregated.' );

		// The query itself was constrained to the window, not an unbounded full-table scan.
		$args = \AAFM\Tests\WcOrderStubStore::$last_query_args;
		$this->assertArrayHasKey( 'date_created', $args, 'The date window must be pushed into the wc_get_orders() query.' );
		$this->assertStringStartsWith( '>=', (string) $args['date_created'], 'The window must constrain date_created as a lower bound.' );
		$this->assertNotSame( -1, $args['limit'] ?? null, 'The query must not load every order with limit => -1.' );
	}

	/**
	 * Top sellers accepts all valid period values.
	 */
	public function test_get_top_sellers_accepts_all_periods(): void {
		$this->acting_as( 'administrator' );
		foreach ( array( 'week', 'month', 'year' ) as $period ) {
			$res = aafm_exec_wc_get_top_sellers_report( array( 'period' => $period ) );
			$this->assertNotInstanceOf( WP_Error::class, $res, "Period $period failed." );
			$this->assertArrayHasKey( 'items', $res );
		}
	}

	/**
	 * Top sellers returns WP_Error when WooCommerce is inactive.
	 */
	public function test_get_top_sellers_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_get_top_sellers_report( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-count-orders
	// =========================================================================

	/**
	 * Count orders returns all expected status keys plus total.
	 */
	public function test_count_orders_returns_all_statuses(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_orders( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		foreach ( array( 'pending', 'processing', 'on_hold', 'completed', 'cancelled', 'refunded', 'failed', 'total' ) as $key ) {
			$this->assertArrayHasKey( $key, $res );
			$this->assertIsInt( $res[ $key ] );
		}
	}

	/**
	 * Count orders: total equals sum of all individual statuses.
	 */
	public function test_count_orders_total_equals_sum(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_orders( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$expected_total = $res['pending'] + $res['processing'] + $res['on_hold']
			+ $res['completed'] + $res['cancelled'] + $res['refunded'] + $res['failed'];
		$this->assertSame( $expected_total, $res['total'] );
	}

	/**
	 * Count orders returns WP_Error when WooCommerce is inactive.
	 */
	public function test_count_orders_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_count_orders( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Count orders requires manage_woocommerce.
	 */
	public function test_count_orders_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-count-orders' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-count-products
	// =========================================================================

	/**
	 * Count products returns all expected status keys.
	 */
	public function test_count_products_returns_all_statuses(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_products( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		foreach ( array( 'publish', 'draft', 'private', 'pending', 'trash', 'total' ) as $key ) {
			$this->assertArrayHasKey( $key, $res );
			$this->assertIsInt( $res[ $key ] );
		}
	}

	/**
	 * Count products: total does not include trash.
	 */
	public function test_count_products_total_excludes_trash(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_products( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$expected_total = $res['publish'] + $res['draft'] + $res['private'] + $res['pending'];
		$this->assertSame( $expected_total, $res['total'] );
	}

	/**
	 * Count products returns WP_Error when WooCommerce is inactive.
	 */
	public function test_count_products_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_count_products( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-count-customers
	// =========================================================================

	/**
	 * Count customers returns a registered key with an integer value.
	 */
	public function test_count_customers_returns_registered(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_customers( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'registered', $res );
		$this->assertIsInt( $res['registered'] );
		$this->assertGreaterThanOrEqual( 1, $res['registered'] ); // At least the admin created during test bootstrap.
	}

	/**
	 * Count customers returns WP_Error when WooCommerce is inactive.
	 */
	public function test_count_customers_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_count_customers( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Count customers requires manage_woocommerce.
	 */
	public function test_count_customers_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-count-customers' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-list-payment-gateways
	// =========================================================================

	/**
	 * List gateways returns both seeded gateways with lean shape.
	 */
	public function test_list_payment_gateways_returns_seeded(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_list_payment_gateways( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'gateways', $res );
		$this->assertCount( 2, $res['gateways'] );

		$ids = array_column( $res['gateways'], 'id' );
		$this->assertContains( 'paypal', $ids );
		$this->assertContains( 'stripe', $ids );

		// Lean shape: id, title, enabled only.
		$first = $res['gateways'][0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'title', $first );
		$this->assertArrayHasKey( 'enabled', $first );
		$this->assertArrayNotHasKey( 'settings', $first );
	}

	/**
	 * List gateways enabled field reflects the seeded state.
	 */
	public function test_list_payment_gateways_enabled_state(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_list_payment_gateways( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$by_id = array_column( $res['gateways'], null, 'id' );
		$this->assertTrue( $by_id['paypal']['enabled'] );
		$this->assertFalse( $by_id['stripe']['enabled'] );
	}

	/**
	 * List gateways returns WP_Error when WooCommerce is inactive.
	 */
	public function test_list_payment_gateways_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_list_payment_gateways( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * List gateways requires manage_woocommerce.
	 */
	public function test_list_payment_gateways_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-payment-gateways' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-get-payment-gateway
	// =========================================================================

	/**
	 * Get gateway returns the full shape with secrets stripped.
	 */
	public function test_get_payment_gateway_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertArrayHasKey( 'title', $res );
		$this->assertArrayHasKey( 'description', $res );
		$this->assertArrayHasKey( 'enabled', $res );
		$this->assertArrayHasKey( 'order', $res );
		$this->assertArrayHasKey( 'settings', $res );
		$this->assertSame( 'paypal', $res['id'] );
		$this->assertSame( 'PayPal', $res['title'] );
		$this->assertTrue( $res['enabled'] );
	}

	/**
	 * Get gateway strips secret keys from settings.
	 */
	public function test_get_payment_gateway_redacts_secrets(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		// The seeded paypal gateway has an 'api_secret' key which must be stripped.
		$this->assertArrayNotHasKey( 'api_secret', $res['settings'] );
	}

	/**
	 * Get gateway strips stripe_secret from stripe gateway.
	 */
	public function test_get_payment_gateway_redacts_stripe_secret(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'stripe' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayNotHasKey( 'stripe_secret', $res['settings'] );
	}

	/**
	 * A secret nested under a benign parent key must be redacted at depth — shallow,
	 * top-level-only redaction leaks credentials stored inside a sub-array.
	 */
	public function test_get_payment_gateway_redacts_nested_secret(): void {
		\AAFM\Tests\WcGatewayStubStore::save_gateway(
			'paypal',
			array(
				'settings' => array(
					'title'    => 'PayPal',
					'advanced' => array(
						'mode'       => 'live',
						'api_secret' => 'nested-secret-value',
					),
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$json = wp_json_encode( $res['settings'] );
		$this->assertStringNotContainsString( 'nested-secret-value', (string) $json, 'A secret two levels deep must not leak.' );
		// The benign sibling under the same parent must survive.
		$this->assertSame( 'live', $res['settings']['advanced']['mode'] );
	}

	/**
	 * A credential stored under an unconventional key name (one not in the original
	 * key/secret/token/password/api/private list) must still be redacted.
	 */
	public function test_get_payment_gateway_redacts_unconventional_secret_key(): void {
		\AAFM\Tests\WcGatewayStubStore::save_gateway(
			'paypal',
			array(
				'settings' => array(
					'title'      => 'PayPal',
					'credential' => 'unconventional-credential-value',
					'auth_pwd'   => 'unconventional-pwd-value',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$this->assertArrayNotHasKey( 'credential', $res['settings'], 'A "credential" key must be redacted.' );
		$this->assertArrayNotHasKey( 'auth_pwd', $res['settings'], 'An "auth_pwd" key must be redacted.' );
	}

	/**
	 * Get gateway returns WP_Error for an unknown id.
	 */
	public function test_get_payment_gateway_not_found(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'nonexistent_gw' ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_not_found', $res->get_error_code() );
	}

	/**
	 * Get gateway returns WP_Error when WooCommerce is inactive.
	 */
	public function test_get_payment_gateway_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Get gateway requires manage_woocommerce.
	 */
	public function test_get_payment_gateway_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-payment-gateway' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-count-coupons
	// =========================================================================

	/**
	 * Count coupons returns all expected keys.
	 */
	public function test_count_coupons_returns_all_statuses(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_coupons( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		foreach ( array( 'publish', 'draft', 'private', 'pending', 'trash', 'total' ) as $key ) {
			$this->assertArrayHasKey( $key, $res );
			$this->assertIsInt( $res[ $key ] );
		}
	}

	/**
	 * Count coupons: total excludes trash.
	 */
	public function test_count_coupons_total_excludes_trash(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_count_coupons( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$expected_total = $res['publish'] + $res['draft'] + $res['private'] + $res['pending'];
		$this->assertSame( $expected_total, $res['total'] );
	}

	/**
	 * Count coupons returns WP_Error when WooCommerce is inactive.
	 */
	public function test_count_coupons_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_count_coupons( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Count coupons requires manage_woocommerce.
	 */
	public function test_count_coupons_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-count-coupons' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-update-payment-gateway
	// =========================================================================

	/**
	 * Update gateway: enable a disabled gateway.
	 */
	public function test_update_payment_gateway_enable(): void {
		$this->acting_as( 'administrator' );
		// Stripe starts disabled.
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'stripe',
				'enabled'    => true,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'enabled', $res );
		// Re-read from store to confirm persistence.
		$stored = WcGatewayStubStore::get( 'stripe' );
		$this->assertSame( 'yes', $stored['enabled'] );
	}

	/**
	 * Update gateway: disable an enabled gateway.
	 */
	public function test_update_payment_gateway_disable(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'enabled'    => false,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$stored = WcGatewayStubStore::get( 'paypal' );
		$this->assertSame( 'no', $stored['enabled'] );
	}

	/**
	 * Update gateway: change title.
	 */
	public function test_update_payment_gateway_title(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'Pay with PayPal',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Pay with PayPal', $res['title'] );
	}

	/**
	 * Update gateway: audit-logs success.
	 */
	public function test_update_payment_gateway_logs_success(): void {
		$this->acting_as( 'administrator' );
		aafm_clear_activity_log();
		wp_get_ability( 'aafm/wc-update-payment-gateway' )->execute(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'PayPal Updated',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-payment-gateway', $abilities );
	}

	/**
	 * Update gateway: returns WP_Error for unknown gateway id and logs deny.
	 */
	public function test_update_payment_gateway_not_found_logs_deny(): void {
		$this->acting_as( 'editor' );
		aafm_clear_activity_log();
		wp_get_ability( 'aafm/wc-update-payment-gateway' )->check_permissions( array( 'gateway_id' => 'bogus_gw' ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-update-payment-gateway', $abilities );
	}

	/**
	 * Update gateway returns WP_Error when save() fails.
	 */
	public function test_update_payment_gateway_save_failure(): void {
		$this->acting_as( 'administrator' );
		WcGatewayStubStore::$force_save_failure = true;
		$res                                    = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'Should Fail',
			)
		);
		WcGatewayStubStore::$force_save_failure = false;

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Update gateway returns WP_Error when WooCommerce is inactive.
	 */
	public function test_update_payment_gateway_inactive_wc(): void {
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$res = aafm_exec_wc_update_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Update gateway requires manage_woocommerce.
	 */
	public function test_update_payment_gateway_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-payment-gateway' )->check_permissions( array() )
		);
	}

	/**
	 * Secrets are not returned even after an update.
	 */
	public function test_update_payment_gateway_redacts_secrets_in_response(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'PayPal Safe',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayNotHasKey( 'api_secret', $res['settings'] );
	}
}
