<?php
/**
 * Integration tests for the W4-WC7 abilities: wc-get-sales-report, wc-get-top-sellers-report,
 * wc-count-orders, wc-count-products, wc-list-payment-gateways, wc-get-payment-gateway,
 * wc-update-payment-gateway.
 *
 * WooCommerce is not installed in the DDEV test environment. Payment gateways are backed by
 * WcGatewayStubStore through the WC_Payment_Gateway / WC_Payment_Gateways eval stubs. Order
 * and product counts rely on wp_count_posts() against real WP post fixtures inserted in
 * set_up(). Sales / top-sellers reports exercise the executor's SQL against the real temp DB.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\WcGatewayStubStore;
use WP_Error;

final class WooReportsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->force_integration( 'woocommerce' );
		$this->unlock_high_risk_abilities();
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
				'aafm/wc-list-payment-gateways',
				'aafm/wc-get-payment-gateway',
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
		$this->assertArrayNotHasKey( 'aafm/wc-list-payment-gateways', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-payment-gateway', $registry );
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
	 * B25: an unparseable date must return an error, not a confidently wrong money figure.
	 *
	 * The strtotime('garbage') call returns false, which cast to 0 silently widened the window to all-time on a bad
	 * start date and emptied it on a bad end date, returning success either way. The report now
	 * refuses an unparseable date.
	 */
	public function test_get_sales_report_rejects_an_unparseable_date(): void {
		$this->acting_as( 'administrator' );

		$bad_start = aafm_exec_wc_get_sales_report( array( 'start_date' => 'not-a-date' ) );
		$this->assertInstanceOf( WP_Error::class, $bad_start, 'A garbage start_date must be refused.' );

		$bad_end = aafm_exec_wc_get_sales_report( array( 'end_date' => 'also-garbage' ) );
		$this->assertInstanceOf( WP_Error::class, $bad_end, 'A garbage end_date must be refused.' );
	}

	/**
	 * The net_sales figure must be the product revenue actually KEPT: gross total minus tax and
	 * shipping, minus the product-only portion of any refund (refund total minus its tax/shipping).
	 * Every other sales-report test runs with zero orders, so the net_sales arithmetic (only
	 * reached with >= 1 order in the window) never executed before this. Seed one completed order
	 * carrying tax + shipping + a partial refund and assert the kept-revenue figure.
	 */
	public function test_get_sales_report_nets_out_tax_shipping_and_refunds(): void {
		$this->acting_as( 'administrator' );

		// 120 gross = 95 product + 10 tax + 15 shipping. A 30.00 refund splits into 23 product +
		// 2 tax + 5 shipping. Kept product revenue = 95 - 23 = 72.00.
		\AAFM\Tests\WcOrderStubStore::seed(
			9001,
			array(
				'status'                  => 'completed',
				'total'                   => '120.00',
				'total_tax'               => '10.00',
				'shipping_total'          => '15.00',
				'total_refunded'          => '30.00',
				'total_tax_refunded'      => '2.00',
				'total_shipping_refunded' => '5.00',
				'date_created'            => '2021-06-15 12:00:00',
			)
		);

		$res = aafm_exec_wc_get_sales_report(
			array(
				'start_date' => '2021-06-01',
				'end_date'   => '2021-06-30',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 1, $res['order_count'] );
		$this->assertSame( '120.00', $res['total_sales'], 'total_sales is the gross order total.' );
		$this->assertSame( '72.00', $res['net_sales'], 'net_sales nets out tax, shipping, and the product portion of the refund.' );
	}

	/**
	 * H7: the sales report must page through the ENTIRE in-window order history, not just the first
	 * page. The executor loops wc_get_orders() at 200 orders/page and advances by the public `page`
	 * query var. Before the fix it passed `paged`, which legacy (non-HPOS) order storage ignores, so
	 * every iteration re-read page 1 (200 orders) and the do-while never terminated -- the report
	 * either looped forever or, at best, only ever counted the first 200 orders. Seed 250 in-window
	 * completed orders (two pages) and assert the report terminates and aggregates all 250.
	 */
	public function test_get_sales_report_covers_all_pages(): void {
		$this->acting_as( 'administrator' );
		\AAFM\Tests\WcOrderStubStore::reset();

		// 250 completed orders spanning two 200-order pages, each a flat 10.00 with no tax, shipping,
		// or refunds so the arithmetic is exact: 250 orders * 10.00 = 2500.00 gross and net.
		for ( $i = 0; $i < 250; $i++ ) {
			\AAFM\Tests\WcOrderStubStore::seed(
				8100 + $i,
				array(
					'status'       => 'completed',
					'total'        => '10.00',
					'date_created' => '2021-06-15 12:00:00',
				)
			);
		}

		$res = aafm_exec_wc_get_sales_report(
			array(
				'start_date' => '2021-06-01',
				'end_date'   => '2021-06-30',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 250, $res['order_count'], 'The report must count every in-window order across all pages, not just the first 200.' );
		$this->assertSame( '2500.00', $res['total_sales'], 'total_sales must sum all 250 orders, proving the loop paged to exhaustion.' );
		$this->assertSame( '2500.00', $res['net_sales'] );
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
	 * row - product ids come from order ITEM data, not shop_order post meta.
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
	 * H7: the top-sellers report shares the sales report's paging loop and had the same bug. It pages
	 * wc_get_orders() at 200 orders/page via the public `page` query var; before the fix it passed
	 * `paged`, which legacy order storage ignores, so the loop re-read page 1 forever (or only ever
	 * aggregated the first 200 orders). Seed 250 in-window orders each selling product 101 once and
	 * assert the aggregated quantity is 250 -- proof the loop advanced through both pages.
	 */
	public function test_get_top_sellers_covers_all_pages(): void {
		\AAFM\Tests\WcOrderStubStore::reset();
		$today = gmdate( 'Y-m-d\TH:i:s' );

		// 250 completed orders (two 200-order pages), each with a single unit of product 101.
		for ( $i = 0; $i < 250; $i++ ) {
			\AAFM\Tests\WcOrderStubStore::seed(
				8300 + $i,
				array(
					'status'       => 'completed',
					'date_created' => $today,
					'items'        => array(
						array(
							'product_id' => 101,
							'quantity'   => 1,
						),
					),
				)
			);
		}

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_top_sellers_report( array( 'period' => 'year' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['items'] );
		$this->assertSame( 101, $res['items'][0]['product_id'] );
		$this->assertSame( 250, $res['items'][0]['quantity'], 'Every in-window order must be aggregated across all pages, not just the first 200.' );
	}

	/**
	 * Top sellers accepts all valid period values AND computes the right date window for each -
	 * not merely "no error", which a wrong branch (e.g. a `match`-to-`switch` conversion that
	 * silently loosened the comparison) could still satisfy.
	 */
	public function test_get_top_sellers_accepts_all_periods(): void {
		$this->acting_as( 'administrator' );

		// Mirrors aafm_exec_wc_get_top_sellers_report()'s own per-period computation exactly, so
		// this pins the actual computed window rather than just the absence of a WP_Error.
		$expected_starts = array(
			'week'  => gmdate( 'Y-m-d', strtotime( '-1 week' ) ) . ' 00:00:00',
			'month' => gmdate( 'Y-m-01' ) . ' 00:00:00',
			'year'  => gmdate( 'Y-01-01' ) . ' 00:00:00',
		);

		foreach ( $expected_starts as $period => $expected_start ) {
			\AAFM\Tests\WcOrderStubStore::reset();
			$res = aafm_exec_wc_get_top_sellers_report( array( 'period' => $period ) );
			$this->assertNotInstanceOf( WP_Error::class, $res, "Period $period failed." );
			$this->assertArrayHasKey( 'items', $res );

			$expected_date_created = '>=' . (int) strtotime( $expected_start );
			$args                  = \AAFM\Tests\WcOrderStubStore::$last_query_args;
			$this->assertSame( $expected_date_created, $args['date_created'] ?? null, "Period $period computed the wrong date window." );
		}
	}

	/**
	 * PHP 7.4's sort functions are not stable (PHP 8.0+ made arsort() stable), and because the
	 * report slices straight through a tie, an unstable sort would return a genuinely different
	 * SET of top sellers on 7.4, not merely a different order. Seed enough tied products to
	 * exceed the ~16-element threshold below which PHP 7.4's insertion sort happens to stay
	 * stable by accident, and pin that ties preserve original insertion order.
	 */
	public function test_get_top_sellers_ties_preserve_original_order(): void {
		\AAFM\Tests\WcOrderStubStore::reset();
		$today = gmdate( 'Y-m-d\TH:i:s' );

		$product_ids = range( 9001, 9025 );
		$items       = array();
		foreach ( $product_ids as $product_id ) {
			\AAFM\Tests\WcStubStore::seed( $product_id, array( 'name' => "Tied Product $product_id" ) );
			$items[] = array(
				'product_id' => $product_id,
				'quantity'   => 1,
			);
		}
		\AAFM\Tests\WcOrderStubStore::seed(
			9500,
			array(
				'status'       => 'completed',
				'date_created' => $today,
				'items'        => $items,
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_top_sellers_report(
			array(
				'period' => 'year',
				'limit'  => 25,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$actual_ids = array_column( $res['items'], 'product_id' );
		$this->assertSame( $product_ids, $actual_ids, 'a fully tied set must preserve original insertion order on every PHP version, not just PHP 8+.' );
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
	 * B52: `total` used to be a hardcoded seven-status sum, so orders in a custom status (which
	 * plugins register through the wc_order_statuses filter) were silently excluded while the
	 * shape claimed a complete total. The total now derives from wc_get_order_statuses().
	 */
	public function test_count_orders_total_includes_custom_statuses(): void {
		$this->acting_as( 'administrator' );

		// A plugin-registered custom status plus one order in it and one in a built-in status.
		\AAFM\Tests\WcOrderStubStore::$order_statuses['wc-shipped'] = 'Shipped';
		\AAFM\Tests\WcOrderStubStore::seed( 9310, array( 'status' => 'shipped' ) );
		\AAFM\Tests\WcOrderStubStore::seed( 9311, array( 'status' => 'completed' ) );

		$res = aafm_exec_wc_count_orders( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 1, $res['completed'] );
		$this->assertSame( 2, $res['total'], 'total must count every registered status, including custom ones - not just the built-in seven.' );
	}

	/**
	 * A refund is not an order.
	 *
	 * Under HPOS a refund is a row in the same wc_orders table with type shop_order_refund, and it
	 * carries its own status - a refund against a completed order is itself 'wc-completed'. An
	 * untyped wc_get_orders() returns both, so a store with zero completed orders and three
	 * refunds reported three completed orders and a total nobody could reconcile.
	 */
	public function test_count_orders_does_not_count_refunds_as_orders(): void {
		$this->acting_as( 'administrator' );

		\AAFM\Tests\WcOrderStubStore::seed( 9320, array( 'status' => 'processing' ) );
		\AAFM\Tests\WcOrderStubStore::seed(
			9321,
			array(
				'status' => 'completed',
				'type'   => 'shop_order_refund',
			)
		);
		\AAFM\Tests\WcOrderStubStore::seed(
			9322,
			array(
				'status' => 'completed',
				'type'   => 'shop_order_refund',
			)
		);

		$res = aafm_exec_wc_count_orders( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 0, $res['completed'], 'refunds must not be counted as completed orders.' );
		$this->assertSame( 1, $res['total'], 'only the one real order counts.' );
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

	/**
	 * Count products reports the resolved language, null when WPML is off - matching the
	 * language reported by wc-list-products so the two abilities agree on scope.
	 */
	public function test_count_products_reports_language_null_without_wpml(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not active' );
		}
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-count-products' )->execute( array() );
		$this->assertArrayHasKey( 'language', $res );
		$this->assertNull( $res['language'] ); // WPML off in the unit suite.
	}

	/**
	 * The count-products input schema accepts the shared lang property.
	 */
	public function test_count_products_accepts_lang_in_its_input_schema(): void {
		$schema = wp_get_ability( 'aafm/wc-count-products' )->get_input_schema();
		$this->assertArrayHasKey( 'lang', $schema['properties'] );
	}

	/**
	 * Aafm/wc-count-products calls the shared WPML count helper with an empty perm argument so
	 * its WPML-off delegation preserves the ORIGINAL wp_count_posts('product') behavior (no perm
	 * at all) instead of the 'readable' gate aafm/count-posts uses. wp_count_posts() short-circuits
	 * to an empty stdClass for an unregistered post type, so 'product' is registered for the
	 * duration of this test to get real counts out of it.
	 */
	public function test_helper_forwards_empty_perm_so_private_counts_are_ungated(): void {
		register_post_type( 'aafm_test_product', array( 'capability_type' => 'post' ) );

		try {
			$other_author_id = self::factory()->user->create( array( 'role' => 'author' ) );
			self::factory()->post->create(
				array(
					'post_type'   => 'aafm_test_product',
					'post_status' => 'private',
					'post_author' => $other_author_id,
				)
			);

			// Current user cannot read_private_posts and did not author the private item.
			$this->acting_as( 'subscriber' );

			$no_perm  = aafm_wpml_count_posts_by_status( 'aafm_test_product', null, '' );
			$readable = aafm_wpml_count_posts_by_status( 'aafm_test_product', null );

			$this->assertSame( 1, $no_perm['private'] ?? 0, 'An empty perm must not apply the private-post capability gate, matching wc-count-products\' original behavior.' );
			$this->assertSame( 0, $readable['private'] ?? 0, 'The default perm ("readable"), used by count-posts, must still gate private counts by capability.' );
		} finally {
			unregister_post_type( 'aafm_test_product' );
		}
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
	 * M13: WC_Payment_Gateway declares no `order` property, so order must be derived from the
	 * gateway's position in WooCommerce's own sorted payment_gateways() list, not a fabricated
	 * property read. The fixture seeds paypal then stripe, so paypal is position 0, stripe is 1.
	 */
	public function test_get_payment_gateway_order_reflects_list_position(): void {
		$this->acting_as( 'administrator' );

		$paypal = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $paypal );
		$this->assertSame( 0, $paypal['order'], 'paypal is seeded first, so its position is 0.' );

		$stripe = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'stripe' ) );
		$this->assertNotInstanceOf( WP_Error::class, $stripe );
		$this->assertSame( 1, $stripe['order'], 'stripe is seeded second, so its position is 1.' );
	}

	/**
	 * Get gateway strips secret keys from settings.
	 */
	public function test_get_payment_gateway_redacts_secrets(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		// The seeded paypal gateway has an 'api_secret' key which must be stripped.
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['api_secret'] );
	}

	/**
	 * Register 1.7: title and description must go through get_title()/get_description(), which
	 * apply the 'woocommerce_gateway_title' and 'woocommerce_gateway_description' filters that
	 * translation and white-label plugins hook. Reading the raw $title/$description properties
	 * skips those filters and reports the wrong name.
	 */
	public function test_get_payment_gateway_title_and_description_are_filtered(): void {
		add_filter(
			'woocommerce_gateway_title',
			static function ( $title, $gateway_id ) {
				return 'paypal' === $gateway_id ? 'PayPal (translated)' : $title;
			},
			10,
			2
		);
		add_filter(
			'woocommerce_gateway_description',
			static function ( $description, $gateway_id ) {
				return 'paypal' === $gateway_id ? 'Translated description.' : $description;
			},
			10,
			2
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'PayPal (translated)', $res['title'], 'The filtered title must be reported, not the raw property.' );
		$this->assertSame( 'Translated description.', $res['description'], 'The filtered description must be reported, not the raw property.' );
	}

	/**
	 * The list endpoint reads its own title separately from the single-gateway shape, so it needs
	 * its own pin. A fix applied only to aafm_wc_gateway_shape() leaves this sibling reporting the
	 * raw, unfiltered name.
	 */
	public function test_list_payment_gateways_titles_are_filtered(): void {
		add_filter(
			'woocommerce_gateway_title',
			static function ( $title, $gateway_id ) {
				return 'paypal' === $gateway_id ? 'PayPal (translated)' : $title;
			},
			10,
			2
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_list_payment_gateways( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$titles = wp_list_pluck( $res['gateways'], 'title', 'id' );
		$this->assertSame( 'PayPal (translated)', $titles['paypal'], 'The list endpoint must report the filtered title, not the raw property.' );
	}

	/**
	 * Get gateway strips stripe_secret from stripe gateway.
	 */
	public function test_get_payment_gateway_redacts_stripe_secret(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'stripe' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['stripe_secret'] );
	}

	/**
	 * A secret nested under a benign parent key must be redacted at depth - shallow,
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

		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['credential'], 'A "credential" key must be redacted.' );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['auth_pwd'], 'An "auth_pwd" key must be redacted.' );
	}

	/**
	 * PayFast's official WooCommerce gateway stores its IPN-signing secret under the literal field
	 * name "passphrase" - missed by the original pattern, which matches "password", not "pass".
	 */
	public function test_get_payment_gateway_redacts_passphrase(): void {
		\AAFM\Tests\WcGatewayStubStore::save_gateway(
			'paypal',
			array(
				'settings' => array(
					'title'      => 'PayPal',
					'passphrase' => 'payfast-style-signing-secret',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['passphrase'], 'A "passphrase" key must be redacted.' );
	}

	/**
	 * PayU-style gateways sign requests with a "salt" value.
	 */
	public function test_get_payment_gateway_redacts_salt(): void {
		\AAFM\Tests\WcGatewayStubStore::save_gateway(
			'paypal',
			array(
				'settings' => array(
					'title' => 'PayPal',
					'salt'  => 'payu-style-hash-salt',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['salt'], 'A "salt" key must be redacted.' );
	}

	/**
	 * Pin/certificate/pem round out the expanded denylist, each exercised standalone and with a
	 * plausible underscore-joined variant so the boundary-anchored match still catches a real field.
	 */
	public function test_get_payment_gateway_redacts_pin_certificate_and_pem(): void {
		\AAFM\Tests\WcGatewayStubStore::save_gateway(
			'paypal',
			array(
				'settings' => array(
					'title'           => 'PayPal',
					'pin'             => 'a-card-pin',
					'security_pin'    => 'another-pin-value',
					'ssl_certificate' => 'cert-contents',
					'client_cert'     => 'cert-contents-2',
					'private_key_pem' => 'pem-contents',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['pin'] );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['security_pin'] );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['ssl_certificate'] );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['client_cert'] );
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['private_key_pem'] );
	}

	/**
	 * The boundary-anchored "pin" token must NOT over-redact a benign key that merely contains "pin"
	 * as a substring - e.g. "shipping" (s-h-i-PIN-g) - proving the anchoring works, not just the
	 * token addition. A loose substring match here would silently strip a legitimate setting on
	 * every gateway/method whose settings happen to mention shipping.
	 */
	public function test_get_payment_gateway_does_not_over_redact_pin_substring(): void {
		\AAFM\Tests\WcGatewayStubStore::save_gateway(
			'paypal',
			array(
				'settings' => array(
					'title'                 => 'PayPal',
					'enable_local_shipping' => 'yes',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_get_payment_gateway( array( 'gateway_id' => 'paypal' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey(
			'enable_local_shipping',
			$res['settings'],
			'A benign key merely containing "pin" as a substring (shipping) must survive.'
		);
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
	 * Update gateway: display order persists to the woocommerce_gateway_order option, not just the
	 * in-memory object (WooCommerce reads ordering from that option on the next request).
	 */
	public function test_update_payment_gateway_persists_display_order(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 4,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$ordering = get_option( 'woocommerce_gateway_order' );
		$this->assertIsArray( $ordering );
		$this->assertSame( 4, (int) $ordering['paypal'] );
		$this->assertSame( 4, $res['order'], 'The response order must reflect what was just requested (M13).' );
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
	 * Update gateway returns WP_Error on a genuine persistence failure: the write is rejected, so a
	 * read-back of the setting still shows the old value (mismatch). This is distinct from
	 * WooCommerce's update_option() returning false merely because the value was unchanged.
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
	 * Update gateway must not report a false success when the in-memory copy holds the requested value
	 * but the write never reached the database.
	 *
	 * WC_Settings_API::update_option() sets $this->settings[$key] BEFORE the DB write, so a
	 * same-instance $gateway->get_option($key) read returns the requested value even when persistence
	 * failed. The executor therefore verifies against the DB-persisted settings row (get_option_key()),
	 * not the gateway object. Here the store rejects the write while the gateway's in-memory copy still
	 * reports the requested title - the exact false-success vector - and the executor must still error.
	 */
	public function test_update_payment_gateway_in_memory_copy_does_not_mask_failed_persist(): void {
		$this->acting_as( 'administrator' );
		WcGatewayStubStore::$force_save_failure = true;

		$gateways = \WC_Payment_Gateways::instance()->payment_gateways();
		$gateways['paypal']->update_option( 'title', 'Ghost Title' );
		// The in-memory copy reflects the requested value even though nothing persisted...
		$this->assertSame( 'Ghost Title', $gateways['paypal']->get_option( 'title' ), 'Guard: in-memory copy holds the un-persisted value.' );
		// ...but the DB-persisted settings row does not.
		$persisted = get_option( $gateways['paypal']->get_option_key(), array() );
		$this->assertNotSame( 'Ghost Title', $persisted['title'] ?? null, 'Guard: the value never reached the persisted option row.' );

		$res                                    = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'Ghost Title',
			)
		);
		WcGatewayStubStore::$force_save_failure = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A write that never persisted must report failure, not a false success.' );
	}

	/**
	 * B32: with the old sequence, a mid-batch persistence failure returned a bare generic error
	 * after earlier fields had already landed - "error" with changed state. The executor now
	 * verifies the whole batch at the end and, when anything failed, names exactly which fields
	 * persisted and which did not, in both the message and the error data.
	 */
	public function test_update_payment_gateway_partial_persist_failure_names_what_landed(): void {
		$this->acting_as( 'administrator' );
		WcGatewayStubStore::$fail_keys = array( 'enabled' );

		$res                           = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'New PayPal Title',
				'enabled'    => false,
			)
		);
		WcGatewayStubStore::$fail_keys = array();

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_gateway_write_failed', $res->get_error_code() );

		$data = (array) $res->get_error_data();
		$this->assertSame( array( 'title' ), $data['persisted'] ?? null, 'the error must state which fields actually landed.' );
		$this->assertSame( array( 'enabled' ), $data['failed'] ?? null, 'the error must state which fields did not persist.' );
		$this->assertStringContainsString( 'enabled', $res->get_error_message() );
		$this->assertStringContainsString( 'title', $res->get_error_message() );
	}

	/**
	 * Update gateway succeeds when the requested value already equals the stored value.
	 *
	 * Regression: WC_Settings_API::update_option() returns false when the value is unchanged (no write
	 * needed), which a naive return-value gate mistook for a failure. The executor must verify the
	 * desired end-state by read-back, so setting an already-correct value still succeeds. The paypal
	 * fixture seeds enabled => 'yes', so re-enabling it is the unchanged-value case.
	 */
	public function test_update_payment_gateway_unchanged_value_succeeds(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'enabled'    => true,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$stored = WcGatewayStubStore::get( 'paypal' );
		$this->assertSame( 'yes', $stored['enabled'] );
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
		$this->assertSame( aafm_wc_redaction_marker(), $res['settings']['api_secret'] );
	}

	/**
	 * FIX-3 item 3 (sweep finding, A2 batch): the per-status order count now delegates to
	 * wc_orders_count(), WooCommerce's own public, HPOS/legacy-abstracted, cache-backed count
	 * helper, instead of a hand-rolled wc_get_orders() pagination probe. Both mechanisms read the
	 * same underlying count, so there is no behavioural difference to drive a test red - this pins
	 * the source-level fact instead, as the finding predicted, and states plainly it could not go
	 * red any other way.
	 */
	public function test_count_orders_by_status_delegates_to_wc_orders_count(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/reports.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test fixture, not a remote URL.
		$this->assertStringContainsString(
			"wc_orders_count( \$status, 'shop_order' )",
			$source,
			'aafm_wc_count_orders_by_status() must delegate to wc_orders_count(), pinning the shop_order type the same way it always has.'
		);
	}

	/**
	 * FIX-3 item 4 (sweep finding, B4 batch): the only live gap in this dispatch.
	 * WC_Settings_API::process_admin_options() - the real admin-form gateway save path - fires
	 * woocommerce_update_option with array('id' => $option_key) before its own update_option()
	 * call. This ability never fired it, so WooCommerce's own opt-in usage-tracking snapshot
	 * (WC_Settings_Tracking::add_option_to_list(), wired to this exact hook) never saw a gateway
	 * change made through this ability. Asserts the real hook fires with the exact vendor payload
	 * shape, not an invented one.
	 */
	public function test_update_payment_gateway_fires_the_vendor_update_option_hook(): void {
		$this->acting_as( 'administrator' );

		$captured = array();
		add_action(
			'woocommerce_update_option',
			static function ( $arg ) use ( &$captured ): void {
				$captured[] = $arg;
			}
		);

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'stripe',
				'title'      => 'Stripe Renamed',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertCount( 1, $captured, 'the hook must fire exactly once for one settings save.' );
		$this->assertSame(
			array( 'id' => 'woocommerce_stripe_settings' ),
			$captured[0],
			'the payload must match WC_Settings_API::process_admin_options()\'s own shape: array("id" => $option_key).'
		);
	}

	/**
	 * The hook must not fire when nothing on the gateway's own settings changed - only the display
	 * order was sent, which is a separate option, not part of process_admin_options()'s scope.
	 */
	public function test_update_payment_gateway_order_only_does_not_fire_the_settings_hook(): void {
		$this->acting_as( 'administrator' );

		$captured = array();
		add_action(
			'woocommerce_update_option',
			static function ( $arg ) use ( &$captured ): void {
				$captured[] = $arg;
			}
		);

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'stripe',
				'order'      => 3,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $captured, 'an order-only update must not fire the settings-save hook.' );
	}

	/**
	 * The write_outcome rows' decoded detail, in insert order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function outcome_details(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s ORDER BY id', aafm_activity_log_table(), 'write_outcome' ) );
		return array_map(
			static function ( $detail ): array {
				return (array) json_decode( (string) $detail, true );
			},
			$rows
		);
	}

	/**
	 * The detail of one woocommerce write_outcome row.
	 *
	 * @param string   $entity Logged entity.
	 * @param int|null $id     Object id, or null.
	 * @param string   $status Status.
	 * @return array<string,mixed>
	 */
	private function wc_row( string $entity, ?int $id, string $status ): array {
		return array(
			'kind'             => 'woocommerce',
			'entity'           => $entity,
			'object_id'        => null === $id ? null : (string) $id,
			'key'              => null,
			'status'           => $status,
			'rows'             => null,
			'modified_by_site' => false,
			'key_omitted'      => false,
		);
	}

	/**
	 * The detail of one woocommerce option-operation row.
	 *
	 * @param string   $entity Logged entity.
	 * @param int|null $id     Object id, or null.
	 * @param string   $option Option name.
	 * @param string   $status Status.
	 * @return array<string,mixed>
	 */
	private function wc_option_row( string $entity, ?int $id, string $option, string $status ): array {
		$row        = $this->wc_row( $entity, $id, $status );
		$row['key'] = $option;
		return $row;
	}

	/**
	 * Keep an option's stored value whatever a write asks for.
	 *
	 * @param mixed $value     The new value.
	 * @param mixed $old_value The stored value.
	 * @return mixed
	 */
	public static function keep_old_value( $value, $old_value ) {
		unset( $value );
		return $old_value;
	}

	public function test_a_gateway_setting_logs_written_then_unchanged_then_refused(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$input = array(
			'gateway_id' => 'paypal',
			'title'      => 'PayPal Logged',
		);

		$this->assertIsArray( aafm_exec_wc_update_payment_gateway( $input ) );
		$this->assertIsArray( aafm_exec_wc_update_payment_gateway( $input ) );

		$filter = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10, 2 );
		$input['title'] = 'PayPal Kept Out';
		$refused        = aafm_exec_wc_update_payment_gateway( $input );
		remove_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10 );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame(
			array(
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_paypal_settings', 'written' ),
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_paypal_settings', 'unchanged' ),
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_paypal_settings', 'refused' ),
			),
			$this->outcome_details()
		);
	}

	public function test_the_gateway_order_option_logs_written_then_unchanged_then_refused(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$input = array(
			'gateway_id' => 'paypal',
			'order'      => 7,
		);

		$this->assertIsArray( aafm_exec_wc_update_payment_gateway( $input ) );
		$this->assertIsArray( aafm_exec_wc_update_payment_gateway( $input ) );

		$filter = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10, 2 );
		$input['order'] = 8;
		$refused        = aafm_exec_wc_update_payment_gateway( $input );
		remove_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10 );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame(
			array(
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_gateway_order', 'written' ),
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_gateway_order', 'unchanged' ),
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_gateway_order', 'refused' ),
			),
			$this->outcome_details()
		);
	}

	public function test_a_gateway_setting_whose_row_cannot_be_read_back_logs_unconfirmed(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$input = array(
			'gateway_id' => 'paypal',
			'title'      => 'PayPal Logged',
		);
		$this->assertIsArray( aafm_exec_wc_update_payment_gateway( $input ) );

		$filter = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10, 2 );
		$input['title'] = 'PayPal Kept Out';
		QueryFaultInjector::reset_fired_count();
		$result = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT option_value FROM', "option_name = 'woocommerce_paypal_settings'" ),
			static function () use ( $input ) {
				return aafm_exec_wc_update_payment_gateway( $input );
			}
		);
		remove_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10 );

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			array(
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_paypal_settings', 'written' ),
				$this->wc_option_row( 'payment_gateway', null, 'woocommerce_paypal_settings', 'unconfirmed' ),
			),
			$this->outcome_details()
		);
	}

	public function test_a_gateway_setting_with_no_stored_row_logs_refused(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_paypal_settings' );

		$filter = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10, 2 );
		$result = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'PayPal Kept Out',
			)
		);
		remove_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( get_option( 'woocommerce_paypal_settings' ) );
		$this->assertSame(
			array( $this->wc_option_row( 'payment_gateway', null, 'woocommerce_paypal_settings', 'refused' ) ),
			$this->outcome_details()
		);
	}

	/**
	 * The md5 of an option's row as the database holds it, or null when there is no row.
	 *
	 * @param string $option Option name.
	 * @return string|null
	 */
	private function option_row_md5( string $option ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the test's own view of the row, past every cache.
		$md5 = $wpdb->get_var( $wpdb->prepare( "SELECT MD5(option_value) FROM $wpdb->options WHERE option_name = %s", $option ) );
		return null === $md5 ? null : (string) $md5;
	}

	/**
	 * Both object cache views of an option: its per-option entry and its alloptions entry.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private function option_cache_views( string $option ): array {
		$found     = false;
		$per_entry = wp_cache_get( $option, 'options', true, $found );
		$all       = wp_cache_get( 'alloptions', 'options', true );
		return array(
			'per_option' => $found ? $per_entry : '(absent)',
			'alloptions' => is_array( $all ) && array_key_exists( $option, $all ) ? $all[ $option ] : '(absent)',
		);
	}

	/**
	 * Store the gateway ordering as a row, then leave a different copy in one cache view: the
	 * per-option entry for a row that is not autoloaded, the alloptions entry for one that is.
	 *
	 * @param array<string,int> $row    The ordering the database holds.
	 * @param array<string,int> $cached The stale ordering the cache holds.
	 * @param bool              $in_all Whether the row is autoloaded, so the stale copy sits in alloptions.
	 */
	private function plant_stale_gateway_order( array $row, array $cached, bool $in_all ): void {
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', $row, '', $in_all );
		if ( $in_all ) {
			wp_cache_delete( 'woocommerce_gateway_order', 'options' );
			$all                              = wp_load_alloptions( true );
			$all['woocommerce_gateway_order'] = maybe_serialize( $cached );
			wp_cache_set( 'alloptions', $all, 'options' );
		} else {
			wp_cache_set( 'woocommerce_gateway_order', maybe_serialize( $cached ), 'options' );
		}
	}

	/**
	 * Doc 250's scenario (R1-4): the row says paypal is at 1, a stale cache says 5. The request
	 * asks for 5 with a title, so a write judged from the cache would be skipped as a no-op and
	 * read back as done. The ordering views disagree, so nothing is written at all: not the
	 * ordering, not the title, not a cache entry, and no outcome row.
	 *
	 * @param bool $in_all Whether the stale copy sits in alloptions rather than the per-option entry.
	 */
	private function assert_a_stale_cached_order_refuses_with_nothing_written( bool $in_all ): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$this->plant_stale_gateway_order( array( 'paypal' => 1 ), array( 'paypal' => 5 ), $in_all );

		$order_md5    = $this->option_row_md5( 'woocommerce_gateway_order' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );
		$order_views  = $this->option_cache_views( 'woocommerce_gateway_order' );
		$this->assertSame( maybe_serialize( array( 'paypal' => 5 ) ), $in_all ? $order_views['alloptions'] : $order_views['per_option'], 'Guard: the stale copy is planted.' );

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 5,
				'title'      => 'Never Written',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_gateway_write_failed', $res->get_error_code() );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ), 'the ordering row must be untouched.' );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ), 'no gateway setting may be written.' );
		$this->assertSame( 'PayPal', WcGatewayStubStore::get( 'paypal' )['settings']['title'], 'no gateway setting may be written.' );
		$this->assertSame( $order_views, $this->option_cache_views( 'woocommerce_gateway_order' ), 'no cache entry of the WooCommerce option may change.' );
		$this->assertSame( array(), $this->outcome_details(), 'a refusal before any write logs no write outcome.' );
	}

	public function test_gateway_order_refuses_when_the_per_option_cache_disagrees_with_the_row(): void {
		$this->assert_a_stale_cached_order_refuses_with_nothing_written( false );
	}

	public function test_gateway_order_refuses_when_the_alloptions_cache_disagrees_with_the_row(): void {
		$this->assert_a_stale_cached_order_refuses_with_nothing_written( true );
	}

	/**
	 * With no cached copy of the ordering at all there is nothing to disagree with, so the row
	 * decides: the write goes ahead, merges into the row's other entries, and certifies from it.
	 */
	public function test_gateway_order_with_no_cached_copy_writes_and_certifies_against_the_row(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option(
			'woocommerce_gateway_order',
			array(
				'paypal' => 1,
				'stripe' => 0,
			),
			'',
			false
		);
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$this->assertSame(
			array(
				'per_option' => '(absent)',
				'alloptions' => '(absent)',
			),
			$this->option_cache_views( 'woocommerce_gateway_order' ),
			'Guard: no cache view holds the ordering.'
		);

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 3,
			)
		);

		$this->assertIsArray( $res );
		$this->assertSame( 3, $res['order'] );
		$this->assertSame(
			md5(
				maybe_serialize(
					array(
						'paypal' => 3,
						'stripe' => 0,
					)
				)
			),
			$this->option_row_md5( 'woocommerce_gateway_order' )
		);
	}

	/**
	 * A failed read of the ordering row cannot tell a stale cache from a healthy one, so the
	 * request refuses before anything is written.
	 */
	public function test_gateway_order_refuses_when_the_row_cannot_be_read(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => 1 ), '', false );
		$order_md5    = $this->option_row_md5( 'woocommerce_gateway_order' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );

		QueryFaultInjector::reset_fired_count();
		ob_start();
		$res = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT option_value FROM', "option_name = 'woocommerce_gateway_order'" ),
			static function () {
				return aafm_exec_wc_update_payment_gateway(
					array(
						'gateway_id' => 'paypal',
						'order'      => 4,
						'title'      => 'Never Written',
					)
				);
			}
		);
		ob_end_clean();

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_gateway_write_failed', $res->get_error_code() );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ) );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ) );
		$this->assertSame( array(), $this->outcome_details() );
	}

	/**
	 * The merged ordering is built from the row, not from get_option(), whose option_{name}
	 * filters can answer with entries the row does not hold.
	 */
	public function test_gateway_order_is_merged_into_the_row_not_a_filtered_read(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'stripe' => 0 ), '', false );
		$ghost = static function ( $value ) {
			$value          = is_array( $value ) ? $value : array();
			$value['ghost'] = 9;
			return $value;
		};
		add_filter( 'option_woocommerce_gateway_order', $ghost );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 2,
			)
		);
		remove_filter( 'option_woocommerce_gateway_order', $ghost );

		$this->assertIsArray( $res );
		$this->assertSame(
			md5(
				maybe_serialize(
					array(
						'stripe' => 0,
						'paypal' => 2,
					)
				)
			),
			$this->option_row_md5( 'woocommerce_gateway_order' )
		);
	}

	/**
	 * A cache backend that primes its copy with the new ordering while the row write itself is
	 * refused: get_option() then shows the requested order, the row does not, and the row decides.
	 */
	public function test_gateway_order_certifies_against_the_row_when_the_cache_is_primed_by_a_refused_write(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => 1 ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$order_md5 = $this->option_row_md5( 'woocommerce_gateway_order' );
		$prime     = static function ( $value, $old_value ) {
			wp_cache_set( 'woocommerce_gateway_order', maybe_serialize( $value ), 'options' );
			return $old_value;
		};
		add_filter( 'pre_update_option_woocommerce_gateway_order', $prime, 10, 2 );
		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 6,
			)
		);
		remove_filter( 'pre_update_option_woocommerce_gateway_order', $prime, 10 );

		$this->assertSame( array( 'paypal' => 6 ), get_option( 'woocommerce_gateway_order' ), 'Guard: the cache shows the refused order.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ) );
	}

	/**
	 * The gateway settings check reads the row (W0 b5w0-code-1, gateway half). A stale cache that
	 * already holds the requested title makes core's update_option() skip the write as a no-op, so
	 * a get_option() read-back shows the title the row never received.
	 */
	public function test_gateway_setting_certifies_against_the_row_not_a_stale_cached_copy(): void {
		$this->acting_as( 'administrator' );
		$row = WcGatewayStubStore::get( 'paypal' )['settings'];
		delete_option( 'woocommerce_paypal_settings' );
		add_option( 'woocommerce_paypal_settings', $row, '', false );
		$settings_md5   = $this->option_row_md5( 'woocommerce_paypal_settings' );
		$stale          = $row;
		$stale['title'] = 'Cached Title';
		wp_cache_set( 'woocommerce_paypal_settings', maybe_serialize( $stale ), 'options' );

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'title'      => 'Cached Title',
			)
		);

		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ), 'Guard: the row never received the title.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_gateway_write_failed', $res->get_error_code() );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'title' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * The settings read-back gives the restrictive answer when it cannot read the row. The settings
	 * INSERT and every read of that row fail, and the requested value is '', which an unread row
	 * must not confirm (ledger b5c2r1-fixsurface-1, b5huntb-1).
	 */
	public function test_gateway_setting_with_a_failed_write_and_an_unreadable_row_reports_the_key_failed(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_paypal_settings' );

		QueryFaultInjector::reset_fired_count();
		$res = QueryFaultInjector::break_query_with_real_error(
			array( 'INSERT INTO', $wpdb->options, 'woocommerce_paypal_settings' ),
			static function () {
				return QueryFaultInjector::break_query_with_real_error(
					array( 'SELECT option_value FROM', "option_name = 'woocommerce_paypal_settings'" ),
					static function () {
						return aafm_exec_wc_update_payment_gateway(
							array(
								'gateway_id'  => 'paypal',
								'description' => '',
							)
						);
					}
				);
			}
		);

		$this->assertGreaterThanOrEqual( 2, QueryFaultInjector::fired_count(), 'Guard: the mutation and its confirming read both fail.' );
		$this->assertNull( $this->option_row_md5( 'woocommerce_paypal_settings' ), 'Guard: no row landed.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_gateway_write_failed', $res->get_error_code() );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'description' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * A pre_update_option filter that keeps the old value leaves no settings row, so the requested
	 * '' title was never stored: the key is reported failed, not matched against a missing entry
	 * (262 s12, ledger b5c2r1-fixsurface-1, b5hunta-2).
	 */
	public function test_gateway_setting_absent_from_a_vetoed_row_reports_the_key_failed(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_paypal_settings' );
		$filter = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10, 2 );
		try {
			$res = aafm_exec_wc_update_payment_gateway(
				array(
					'gateway_id' => 'paypal',
					'title'      => '',
				)
			);
		} finally {
			remove_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10 );
		}

		$this->assertNull( $this->option_row_md5( 'woocommerce_paypal_settings' ), 'Guard: the veto kept the row absent.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'title' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * A stored false is not a stored ''. The row holds false for the title, a veto keeps that row,
	 * and the requested '' title is reported failed rather than matched by its string form (ledger
	 * b5c2r2-codex-1).
	 */
	public function test_gateway_setting_stored_as_false_does_not_confirm_a_requested_empty_string(): void {
		$this->acting_as( 'administrator' );
		$row          = WcGatewayStubStore::get( 'paypal' )['settings'];
		$row['title'] = false;
		delete_option( 'woocommerce_paypal_settings' );
		add_option( 'woocommerce_paypal_settings', $row, '', false );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );
		$filter       = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10, 2 );
		try {
			$res = aafm_exec_wc_update_payment_gateway(
				array(
					'gateway_id' => 'paypal',
					'title'      => '',
				)
			);
		} finally {
			remove_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10 );
		}

		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ), 'Guard: the veto kept the row.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'title' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * A stored position that is not numeric is not position 0. The ordering row holds 'abc' for the
	 * gateway, a veto keeps that row, and the requested order 0 is reported failed rather than
	 * matched through (int) 'abc' (ledger b5c2r2-codex-1).
	 */
	public function test_gateway_order_stored_as_a_non_numeric_string_does_not_confirm_position_zero(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => 'abc' ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$order_md5 = $this->option_row_md5( 'woocommerce_gateway_order' );
		$filter    = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10, 2 );
		try {
			$res = aafm_exec_wc_update_payment_gateway(
				array(
					'gateway_id' => 'paypal',
					'order'      => 0,
				)
			);
		} finally {
			remove_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10 );
		}

		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ), 'Guard: the veto kept the row.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * WooCommerce can store a position as a numeric string, and that still confirms the order it
	 * equals: a vetoed write over a row holding '3' reports order 3 persisted.
	 */
	public function test_gateway_order_stored_as_a_numeric_string_confirms_the_equal_position(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => '3' ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$filter = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10, 2 );
		try {
			$res = aafm_exec_wc_update_payment_gateway(
				array(
					'gateway_id' => 'paypal',
					'order'      => 3,
				)
			);
		} finally {
			remove_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10 );
		}

		$this->assertIsArray( $res );
		$this->assertSame( 3, $res['order'] );
	}

	/**
	 * Stored positions that are not the requested integer or its decimal string, one row each
	 * (design 3.3 rows G-d to G-l).
	 *
	 * @return array<string,array{0:mixed,1:int}>
	 */
	public function non_canonical_positions(): array {
		return array(
			'G-d float'               => array( 3.9, 3 ),
			'G-e decimal string'      => array( '3.9', 3 ),
			'G-f exponent string'     => array( '3e0', 3 ),
			'G-g leading space'       => array( ' 3', 3 ),
			'G-h nan'                 => array( NAN, 0 ),
			'G-i leading zero'        => array( '03', 3 ),
			'G-j whole float'         => array( 3.0, 3 ),
			'G-l decimal zero string' => array( '3.0', 3 ),
		);
	}

	/**
	 * Under a veto that keeps the old ordering row, only the requested integer or its decimal
	 * string confirms the order; any other stored position reports it failed.
	 *
	 * @dataProvider non_canonical_positions
	 *
	 * @param mixed $stored  The position the kept row holds.
	 * @param int   $request The requested order.
	 */
	public function test_gateway_order_confirms_only_the_requested_integer_under_a_veto( $stored, int $request ): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => $stored ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$order_md5 = $this->option_row_md5( 'woocommerce_gateway_order' );
		$filter    = array( self::class, 'keep_old_value' );
		add_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10, 2 );
		try {
			$res = aafm_exec_wc_update_payment_gateway(
				array(
					'gateway_id' => 'paypal',
					'order'      => $request,
				)
			);
		} finally {
			remove_filter( 'pre_update_option_woocommerce_gateway_order', $filter, 10 );
		}

		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ), 'Guard: the veto kept the row.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * A gateway setting stored as a number still confirms the equal text: a vetoed title write
	 * over a row holding int 5 or float 5.0 reports the title persisted for a request of '5'.
	 */
	public function test_gateway_setting_stored_as_a_number_confirms_the_equal_text(): void {
		$this->acting_as( 'administrator' );
		$filter = array( self::class, 'keep_old_value' );
		foreach ( array(
			'int'   => 5,
			'float' => 5.0,
		) as $label => $stored ) {
			delete_option( 'woocommerce_paypal_settings' );
			add_option( 'woocommerce_paypal_settings', array( 'title' => $stored ), '', false );
			wp_cache_delete( 'woocommerce_paypal_settings', 'options' );
			add_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10, 2 );
			try {
				$res = aafm_exec_wc_update_payment_gateway(
					array(
						'gateway_id' => 'paypal',
						'title'      => '5',
					)
				);
			} finally {
				remove_filter( 'pre_update_option_woocommerce_paypal_settings', $filter, 10 );
			}

			$this->assertIsArray( $res, $label );
		}
	}

	/**
	 * The ordering read-back gives the restrictive answer when it cannot read the row. The UPDATE
	 * of the ordering fails, and every read of its row after the write fails too.
	 */
	public function test_gateway_order_with_a_failed_write_and_an_unreadable_row_reports_order_failed(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => 1 ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$order_md5 = $this->option_row_md5( 'woocommerce_gateway_order' );

		$read_fault = QueryFaultInjector::real_error_filter( array( 'SELECT option_value FROM', "option_name = 'woocommerce_gateway_order'" ) );
		$arm        = static function () use ( $read_fault ): void {
			if ( ! has_filter( 'query', $read_fault ) ) {
				add_filter( 'query', $read_fault );
			}
		};
		add_action( 'aafm_write_completed', $arm );
		QueryFaultInjector::reset_fired_count();
		try {
			$res = QueryFaultInjector::break_query_with_real_error(
				array( 'UPDATE', "'woocommerce_gateway_order'" ),
				static function () {
					return aafm_exec_wc_update_payment_gateway(
						array(
							'gateway_id' => 'paypal',
							'order'      => 4,
						)
					);
				}
			);
		} finally {
			remove_action( 'aafm_write_completed', $arm );
			remove_filter( 'query', $read_fault );
		}

		$this->assertGreaterThanOrEqual( 2, QueryFaultInjector::fired_count(), 'Guard: the mutation and its confirming read both fail.' );
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ), 'Guard: the ordering row is unchanged.' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
	}

	/**
	 * Run $callback with every forced wp_cache_get() missing while unforced reads still answer from
	 * the request's copy: the state a Redis drop-in is in after a Redis error, when a forced get
	 * returns false and the internal copy get_option() reads is still loaded. The real cache object
	 * is put back afterwards.
	 *
	 * @param callable $callback Code to run.
	 * @return mixed $callback()'s return value.
	 */
	private function with_forced_cache_reads_missing( callable $callback ) {
		global $wp_object_cache;
		$real = $wp_object_cache;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double, restored in finally.
		$wp_object_cache = new class( $real ) {
			/**
			 * The cache every call but a forced get goes to.
			 *
			 * @var object
			 */
			private $real;

			/**
			 * Wrap the real cache object.
			 *
			 * @param object $real The real cache object.
			 */
			public function __construct( $real ) {
				$this->real = $real;
			}

			/**
			 * A forced get misses; an unforced one answers from the real cache.
			 *
			 * @param int|string $key   Key.
			 * @param string     $group Group.
			 * @param bool       $force Whether the read is forced.
			 * @param bool|null  $found Whether the key was found.
			 * @return mixed
			 */
			public function get( $key, $group = 'default', $force = false, &$found = null ) {
				if ( $force ) {
					$found = false;
					return false;
				}
				return $this->real->get( $key, $group, false, $found );
			}

			/**
			 * Forward every other cache call to the real cache object.
			 *
			 * @param string       $name Method.
			 * @param array<mixed> $args Arguments.
			 * @return mixed
			 */
			public function __call( $name, $args ) {
				return $this->real->$name( ...$args );
			}
		};
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the real cache object.
			$wp_object_cache = $real;
		}
	}

	/**
	 * Ledger b5hunta-1: the row and the per-option entry say paypal is at 1 while the alloptions entry says
	 * 5. get_option() answers from alloptions first, so a request for 5 would be skipped as a no-op.
	 * Every cache copy is checked against the row, so the request refuses before the title is written.
	 */
	public function test_gateway_order_refuses_when_alloptions_disagrees_beside_an_agreeing_per_option_entry(): void {
		$this->acting_as( 'administrator' );
		$this->plant_stale_gateway_order( array( 'paypal' => 1 ), array( 'paypal' => 5 ), true );
		wp_cache_set( 'woocommerce_gateway_order', maybe_serialize( array( 'paypal' => 1 ) ), 'options' );
		$order_md5    = $this->option_row_md5( 'woocommerce_gateway_order' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );
		$views        = $this->option_cache_views( 'woocommerce_gateway_order' );
		$this->assertNotSame( $views['per_option'], $views['alloptions'], 'Guard: the two cache copies disagree.' );

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 5,
				'title'      => 'Never Written',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ) );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ) );
		$this->assertSame( $views, $this->option_cache_views( 'woocommerce_gateway_order' ) );
	}

	/**
	 * G7 (262 step 11 decision table): a notoptions entry over an existing ordering row refuses,
	 * with nothing written and no cache entry of the WooCommerce option changed.
	 */
	public function test_gateway_order_refuses_when_notoptions_sits_over_the_row(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => 1 ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$not                              = (array) wp_cache_get( 'notoptions', 'options' );
		$not['woocommerce_gateway_order'] = true;
		wp_cache_set( 'notoptions', $not, 'options' );
		$order_md5    = $this->option_row_md5( 'woocommerce_gateway_order' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 5,
				'title'      => 'Never Written',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ) );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ) );
		$this->assertArrayHasKey( 'woocommerce_gateway_order', (array) wp_cache_get( 'notoptions', 'options' ) );
	}

	/**
	 * Ledger b5c2r1-codex-2: a cached '' over a missing ordering row. get_option() answers '', so the
	 * ordering UPDATE would hit no row after the title was written. The check refuses first.
	 */
	public function test_gateway_order_refuses_a_cached_empty_string_over_a_missing_row(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		wp_cache_set( 'woocommerce_gateway_order', '', 'options' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );

		$res = aafm_exec_wc_update_payment_gateway(
			array(
				'gateway_id' => 'paypal',
				'order'      => 5,
				'title'      => 'Never Written',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertNull( $this->option_row_md5( 'woocommerce_gateway_order' ) );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ) );
	}

	/**
	 * Ledger b5c2r1-fixsurface-2: the unreadable-row refusal with no cache entry at all, so only the failed
	 * read itself can refuse (the existing twin also has a per-option entry that disagrees with the
	 * failed read's false).
	 */
	public function test_gateway_order_refuses_an_unreadable_row_with_no_cache_entry(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'woocommerce_gateway_order' );
		add_option( 'woocommerce_gateway_order', array( 'paypal' => 1 ), '', false );
		wp_cache_delete( 'woocommerce_gateway_order', 'options' );
		$order_md5    = $this->option_row_md5( 'woocommerce_gateway_order' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );

		QueryFaultInjector::reset_fired_count();
		$res = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT option_value FROM', "option_name = 'woocommerce_gateway_order'" ),
			static function () {
				return aafm_exec_wc_update_payment_gateway(
					array(
						'gateway_id' => 'paypal',
						'order'      => 4,
						'title'      => 'Never Written',
					)
				);
			}
		);

		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ) );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ) );
	}

	/**
	 * Ledger b5c1r3-security-1 on the gateway caller: the request's own per-option copy says 5 over a row of
	 * 1 while every forced read misses. update_option() would take 5 as the old value and skip the
	 * write, so the runtime copy is checked too and the request refuses before the title is written.
	 */
	public function test_gateway_order_refuses_a_stale_runtime_per_option_copy_when_the_forced_read_misses(): void {
		$this->acting_as( 'administrator' );
		$this->plant_stale_gateway_order( array( 'paypal' => 1 ), array( 'paypal' => 5 ), false );
		$order_md5    = $this->option_row_md5( 'woocommerce_gateway_order' );
		$settings_md5 = $this->option_row_md5( 'woocommerce_paypal_settings' );

		$res = $this->with_forced_cache_reads_missing(
			static function () {
				return aafm_exec_wc_update_payment_gateway(
					array(
						'gateway_id' => 'paypal',
						'order'      => 5,
						'title'      => 'Never Written',
					)
				);
			}
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'persisted' => array(),
				'failed'    => array( 'order' ),
			),
			$res->get_error_data()
		);
		$this->assertSame( $order_md5, $this->option_row_md5( 'woocommerce_gateway_order' ) );
		$this->assertSame( $settings_md5, $this->option_row_md5( 'woocommerce_paypal_settings' ) );
	}
}
