<?php
/**
 * WooCommerce order read abilities: aafm/wc-list-orders (lean, no PII in list rows) and
 * aafm/wc-get-order (full shape including customer billing/shipping PII under the disclaimer).
 *
 * WooCommerce is not installed in the DDEV test environment — every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcOrderStubStore. The stub_wc_orders() helper
 * resets and seeds the store per test so each test starts with a clean, known state.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcOrderStubStore;
use WP_Error;

final class WooOrdersTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		// stub_woocommerce() adds manage_woocommerce to administrator and defines the base WC classes.
		$this->stub_woocommerce();
		// Seed order test fixtures including a PII-carrying order.
		$this->seed_wc_orders();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_orders();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcOrderStubStore::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action Action name to simulate.
	 * @param callable $cb     Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable + register the WooCommerce order read ability set.
	 */
	private function register_wc_orders(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-orders',
				'aafm/wc-get-order',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// aafm/wc-list-orders
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_orders_requires_manage_woocommerce(): void {
		// An editor (no manage_woocommerce) must be denied at the permission gate.
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-orders' )->check_permissions( array() )
		);

		// An administrator (given manage_woocommerce by stub_woocommerce()) is allowed.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'orders', $res );
		$this->assertArrayHasKey( 'total', $res );
	}

	public function test_list_orders_returns_lean_rows_no_pii(): void {
		// List rows must carry id, number, status, total, currency, date_created, customer_id
		// and absolutely NO billing address, email, or phone fields.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['orders'] );

		$row = $res['orders'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'number', $row );
		$this->assertArrayHasKey( 'status', $row );
		$this->assertArrayHasKey( 'total', $row );
		$this->assertArrayHasKey( 'currency', $row );
		$this->assertArrayHasKey( 'date_created', $row );
		$this->assertArrayHasKey( 'customer_id', $row );

		// PII keys MUST NOT appear in list rows — list is lean for payload economy.
		$this->assertArrayNotHasKey( 'billing', $row );
		$this->assertArrayNotHasKey( 'email', $row );
		$this->assertArrayNotHasKey( 'phone', $row );
		$this->assertArrayNotHasKey( 'shipping', $row );
		$this->assertArrayNotHasKey( 'line_items', $row );
		$this->assertArrayNotHasKey( 'customer_note', $row );
	}

	public function test_list_orders_total_is_the_grand_count_not_the_page_length(): void {
		// Seed 3 orders total; fetch page 1 with per_page=2. total must be 3, not 2.
		WcOrderStubStore::reset();
		WcOrderStubStore::seed(
			5001,
			array(
				'number' => '5001',
				'status' => 'processing',
			)
		);
		WcOrderStubStore::seed(
			5002,
			array(
				'number' => '5002',
				'status' => 'processing',
			)
		);
		WcOrderStubStore::seed(
			5003,
			array(
				'number' => '5003',
				'status' => 'processing',
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute(
			array(
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertCount( 2, $res['orders'], 'Page slice should contain 2 rows.' );
		$this->assertSame( 3, $res['total'], 'total must be the grand count (3), not the page slice length (2).' );
	}

	public function test_list_orders_status_filter_works(): void {
		WcOrderStubStore::reset();
		WcOrderStubStore::seed(
			5010,
			array(
				'number' => '5010',
				'status' => 'processing',
			)
		);
		WcOrderStubStore::seed(
			5011,
			array(
				'number' => '5011',
				'status' => 'completed',
			)
		);
		WcOrderStubStore::seed(
			5012,
			array(
				'number' => '5012',
				'status' => 'completed',
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array( 'status' => 'completed' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 2, $res['total'] );
		$statuses = wp_list_pluck( $res['orders'], 'status' );
		foreach ( $statuses as $s ) {
			$this->assertSame( 'completed', $s );
		}
	}

	public function test_list_orders_paging_returns_correct_page(): void {
		WcOrderStubStore::reset();
		WcOrderStubStore::seed( 5020, array( 'number' => '5020' ) );
		WcOrderStubStore::seed( 5021, array( 'number' => '5021' ) );
		WcOrderStubStore::seed( 5022, array( 'number' => '5022' ) );

		$this->acting_as( 'administrator' );
		// Page 2 of per_page=2 should return only the third order.
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertCount( 1, $res['orders'] );
		$this->assertSame( 5022, $res['orders'][0]['id'] );
		$this->assertSame( 3, $res['total'] );
	}

	public function test_list_orders_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-list-orders' )->execute( array() );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-list-orders', $abilities );
	}

	public function test_list_orders_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-list-orders' )->check_permissions( array() );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-list-orders', $abilities );
	}

	public function test_list_orders_is_read_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-list-orders' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'] ?? false, 'wc-list-orders must be annotated readonly.' );
		$this->assertFalse( $annotations['destructive'] ?? true, 'wc-list-orders must be annotated non-destructive.' );
	}

	public function test_list_orders_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array( 'evil_field' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown field.' );
	}

	// =========================================================================
	// aafm/wc-get-order
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_get_order_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-order' )->check_permissions( array( 'order_id' => 5001 ) )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
	}

	public function test_get_order_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// Top-level shape.
		$this->assertArrayHasKey( 'id', $res );
		$this->assertArrayHasKey( 'number', $res );
		$this->assertArrayHasKey( 'status', $res );
		$this->assertArrayHasKey( 'currency', $res );
		$this->assertArrayHasKey( 'date_created', $res );
		$this->assertArrayHasKey( 'date_paid', $res );
		$this->assertArrayHasKey( 'customer_id', $res );
		$this->assertArrayHasKey( 'customer_note', $res );
		$this->assertArrayHasKey( 'line_items', $res );
		$this->assertArrayHasKey( 'billing', $res );
		$this->assertArrayHasKey( 'shipping', $res );

		// Totals sub-object.
		$this->assertArrayHasKey( 'totals', $res );
		$this->assertArrayHasKey( 'total', $res['totals'] );
		$this->assertArrayHasKey( 'subtotal', $res['totals'] );
		$this->assertArrayHasKey( 'tax', $res['totals'] );
		$this->assertArrayHasKey( 'shipping', $res['totals'] );

		// Billing sub-object fields.
		$billing = $res['billing'];
		$this->assertArrayHasKey( 'first_name', $billing );
		$this->assertArrayHasKey( 'last_name', $billing );
		$this->assertArrayHasKey( 'email', $billing );
		$this->assertArrayHasKey( 'phone', $billing );
		$this->assertArrayHasKey( 'address_1', $billing );
		$this->assertArrayHasKey( 'city', $billing );
		$this->assertArrayHasKey( 'country', $billing );

		// Shipping sub-object fields.
		$shipping = $res['shipping'];
		$this->assertArrayHasKey( 'first_name', $shipping );
		$this->assertArrayHasKey( 'address_1', $shipping );
		$this->assertArrayHasKey( 'country', $shipping );
		// Shipping has no email or phone (that is a billing-only field).
		$this->assertArrayNotHasKey( 'email', $shipping );
		$this->assertArrayNotHasKey( 'phone', $shipping );
	}

	/**
	 * PII-exposure proof: billing email and phone are PRESENT (intentional, not an accidental leak).
	 *
	 * This is the inverse of the redaction-proof tests elsewhere — here we assert that customer
	 * PII is deliberately exposed under the Integrations security disclaimer, gated by
	 * manage_woocommerce, and audited. Never add a default-strip or an opt-in gate here.
	 */
	public function test_get_order_exposes_billing_pii_intentionally(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// The billing email MUST be the seeded value — not stripped, not redacted.
		$this->assertSame(
			'billing@example.com',
			$res['billing']['email'],
			'wc-get-order must expose billing email; PII is intentional under the Integrations disclaimer.'
		);

		// The billing phone MUST be present and non-empty.
		$this->assertArrayHasKey( 'phone', $res['billing'], 'billing.phone must be present.' );
		$this->assertNotEmpty( $res['billing']['phone'], 'billing.phone must be non-empty for the seeded order.' );
	}

	public function test_get_order_empty_billing_and_shipping_encode_as_objects(): void {
		// Seed an order with empty billing/shipping maps.
		WcOrderStubStore::seed(
			5099,
			array(
				'number'   => '5099',
				'billing'  => array(),
				'shipping' => array(),
			)
		);
		// Re-register so the new order is accessible.
		$this->register_wc_orders();

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5099 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// Both billing and shipping must be stdClass/objects (encode as {}) not arrays ([]).
		$this->assertIsObject( $res['billing'], 'Empty billing map must be an object, not an array.' );
		$this->assertIsObject( $res['shipping'], 'Empty shipping map must be an object, not an array.' );
		$encoded = wp_json_encode( $res );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( '"billing":[]', $encoded, 'billing must encode as {}, not [].' );
		$this->assertStringNotContainsString( '"shipping":[]', $encoded, 'shipping must encode as {}, not [].' );
	}

	public function test_get_order_unknown_id_returns_generic_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_order_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-get-order', $abilities );
	}

	public function test_get_order_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-get-order' )->check_permissions( array( 'order_id' => 5001 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-get-order', $abilities );
	}

	public function test_get_order_is_read_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-get-order' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'] ?? false, 'wc-get-order must be annotated readonly.' );
		$this->assertFalse( $annotations['destructive'] ?? true, 'wc-get-order must be annotated non-destructive.' );
	}

	public function test_get_order_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute(
			array(
				'order_id'   => 5001,
				'evil_field' => 'injected',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown top-level field.' );
	}

	public function test_get_order_line_items_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertIsArray( $res['line_items'] );

		if ( ! empty( $res['line_items'] ) ) {
			$item = $res['line_items'][0];
			$this->assertArrayHasKey( 'name', $item );
			$this->assertArrayHasKey( 'product_id', $item );
			$this->assertArrayHasKey( 'quantity', $item );
			$this->assertArrayHasKey( 'subtotal', $item );
			$this->assertArrayHasKey( 'total', $item );
		}
	}

	// =========================================================================
	// Host-inactive gate
	// =========================================================================

	/**
	 * Order abilities must be absent from the registry when WooCommerce is not active.
	 */
	public function test_order_abilities_absent_when_host_inactive(): void {
		// Pin WooCommerce detection off through the low-level seam so the class WooCommerce
		// marker (defined process-wide by stub_woocommerce()) does not falsely report WC active.
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-orders', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-order', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}
}
