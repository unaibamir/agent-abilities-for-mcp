<?php
/**
 * WooCommerce order read abilities: aafm/wc-list-orders (lean, no PII in list rows) and
 * aafm/wc-get-order (full shape including customer billing/shipping PII under the disclaimer).
 *
 * WooCommerce is not installed in the DDEV test environment - every WC host function and class is
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
		$this->unlock_high_risk_abilities();
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

		// PII keys MUST NOT appear in list rows - list is lean for payload economy.
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

	/**
	 * The list total must count orders, not refunds.
	 *
	 * Under HPOS a refund is a row in wc_orders with type shop_order_refund and a status of its
	 * own, so an untyped query returns it. The rows themselves were already dropped by the
	 * WC_Order instanceof guard, which made this worse rather than better: the store reported
	 * "total 3" over an empty rows array, and paging walked pages that held nothing.
	 */
	public function test_list_orders_does_not_count_refunds_in_the_total(): void {
		WcOrderStubStore::reset();
		WcOrderStubStore::seed(
			5030,
			array(
				'number' => '5030',
				'status' => 'completed',
			)
		);
		WcOrderStubStore::seed(
			5031,
			array(
				'number' => '5031',
				'status' => 'completed',
				'type'   => 'shop_order_refund',
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array( 'status' => 'completed' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 1, $res['total'], 'the refund must not inflate the total.' );
		$this->assertCount( 1, $res['orders'] );
		$this->assertSame( 5030, $res['orders'][0]['id'] );
	}

	public function test_list_orders_empty_store_returns_empty(): void {
		// With no orders in the store the ability must return orders:[] and total:0.
		// This pins both the plain-array fallback path and the paginate object path on an empty result.
		WcOrderStubStore::reset();

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $res['orders'], 'orders must be an empty array when the store is empty.' );
		$this->assertSame( 0, $res['total'], 'total must be 0 when the store is empty.' );
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
	 * This is the inverse of the redaction-proof tests elsewhere - here we assert that customer
	 * PII is deliberately exposed under the Integrations security disclaimer, gated by
	 * manage_woocommerce, and audited. Never add a default-strip or an opt-in gate here.
	 */
	public function test_get_order_exposes_billing_pii_intentionally(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// The billing email MUST be the seeded value - not stripped, not redacted.
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
		$this->assertSame( 'aafm_error', $res->get_error_code() );
	}

	public function test_get_order_rejects_zero_id(): void {
		// The minimum:1 schema constraint must reject order_id:0 before execute runs.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 0 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Closed schema: an unknown field injected on top of valid args is rejected by execute().
	 *
	 * @dataProvider provide_closed_schema_cases
	 *
	 * @param string               $ability        Ability name.
	 * @param array<string, mixed> $valid_min_args Minimal valid args for the ability.
	 */
	public function test_closed_schema_rejects_unknown_field( string $ability, array $valid_min_args ): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( $ability )->execute(
			array_merge( $valid_min_args, array( 'evil_field' => 'injected' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown top-level field.' );
	}

	/**
	 * Cases: each order read and the minimal valid args its original test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function provide_closed_schema_cases(): array {
		return array(
			'list-orders' => array( 'aafm/wc-list-orders', array() ),
			'get-order'   => array( 'aafm/wc-get-order', array( 'order_id' => 5001 ) ),
		);
	}

	/**
	 * B57: status "any" used to trust the storage backend's default status set, which includes
	 * the internal checkout-draft status on HPOS and excludes it on legacy CPT storage - the same
	 * call gave a backend-dependent answer. "any" now expands to the registered statuses from
	 * wc_get_order_statuses() explicitly, which never include the ephemeral checkout-draft.
	 */
	public function test_list_orders_any_excludes_checkout_draft_explicitly(): void {
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed( 5090, array( 'status' => 'checkout-draft' ) );

		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array( 'status' => 'any' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$ids = wp_list_pluck( $res['orders'], 'id' );
		$this->assertNotContains( 5090, $ids, 'the internal checkout-draft status must be excluded from "any" on every backend.' );
		$this->assertContains( 5001, $ids );
		$this->assertSame( 1, $res['total'], 'total must agree with the explicit status expansion.' );

		// The query must have received an explicit status list, not the backend-default "any".
		$pushed = WcOrderStubStore::$last_query_args['status'] ?? null;
		$this->assertIsArray( $pushed, '"any" must be expanded to an explicit status list, not passed through to the backend.' );
		$this->assertNotContains( 'checkout-draft', $pushed );
	}

	/**
	 * B57 control: an explicit checkout-draft request still returns the draft orders.
	 */
	public function test_list_orders_explicit_checkout_draft_still_works(): void {
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed( 5091, array( 'status' => 'checkout-draft' ) );

		$res = wp_get_ability( 'aafm/wc-list-orders' )->execute( array( 'status' => 'checkout-draft' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array( 5091 ), wp_list_pluck( $res['orders'], 'id' ) );
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

	/**
	 * B24: the order read must expose each line item's own order-item id, because
	 * wc-create-order-refund's line_items contract documents "the order's own line item id, as
	 * returned by reading the order" - and until this fix the read returned no id at all, making
	 * the documented per-line refund unusable.
	 */
	public function test_get_order_exposes_line_item_ids(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['line_items'] );

		// Wire-level assertion: the encoded row carries the seeded order-item id.
		$json = wp_json_encode( $res['line_items'][0] );
		$this->assertStringContainsString( '"id":1', (string) $json, 'line_items rows must carry the order-item id the refund contract documents.' );
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

	// =========================================================================
	// aafm/wc-create-order + aafm/wc-update-order
	// =========================================================================

	/**
	 * Enable + register the full order ability set including writes.
	 */
	/**
	 * Put the seeded order into a status WooCommerce still treats as editable.
	 *
	 * The fixture seeds 5001 as `processing` (IntegrationStubs), which is the normal state of a
	 * PAID order and one WooCommerce does NOT treat as editable. Since R3-1 the update ability
	 * refuses to add line items to such an order, so every test that exercises a successful add has
	 * to state which kind of order it is adding to. That precondition used to be incidental; it is
	 * load-bearing now, which is why it is spelled rather than left to the fixture default.
	 */
	private function make_seeded_order_editable(): void {
		WcOrderStubStore::$orders[5001]['status'] = 'on-hold';
	}

	private function register_wc_order_writes(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-orders',
				'aafm/wc-get-order',
				'aafm/wc-create-order',
				'aafm/wc-update-order',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_create_order_returns_rich_shape_and_persists(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array(
				'status'        => 'processing',
				'customer_id'   => 7,
				'customer_note' => 'Test note',
				'billing'       => array(
					'first_name' => 'Alice',
					'last_name'  => 'Smith',
					'email'      => 'alice@example.com',
					'city'       => 'London',
					'country'    => 'GB',
				),
				'shipping'      => array(
					'first_name' => 'Alice',
					'last_name'  => 'Smith',
					'country'    => 'GB',
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		// Rich shape keys present.
		$this->assertArrayHasKey( 'id', $res );
		$this->assertArrayHasKey( 'status', $res );
		$this->assertArrayHasKey( 'billing', $res );
		$this->assertArrayHasKey( 'shipping', $res );
		$this->assertArrayHasKey( 'totals', $res );
		$this->assertArrayHasKey( 'line_items', $res );
		$this->assertGreaterThan( 0, $res['id'], 'Created order must have a non-zero id.' );
		// Persisted: retrievable via wc-get-order.
		$get = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => $res['id'] ) );
		$this->assertNotInstanceOf( \WP_Error::class, $get );
		$this->assertSame( $res['id'], $get['id'] );
	}

	public function test_create_order_denied_requires_manage_woocommerce(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-order' )->check_permissions( array( 'status' => 'processing' ) )
		);
	}


	public function test_create_order_top_level_smuggle_rejected(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array( 'evil_field' => 'x' )
		);
		$this->assertInstanceOf( \WP_Error::class, $res, 'Closed schema must reject unknown top-level field.' );
	}

	/**
	 * MED-4 nested-smuggle: a key inside billing{} must be rejected.
	 * billing.role is a canonical example of a data-smuggling attempt (trying to ride
	 * a role/account field in via the address block). The billing sub-schema sets
	 * additionalProperties:false, so this must return WP_Error, not succeed.
	 */
	public function test_create_order_billing_nested_smuggle_rejected(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array(
				'billing' => array(
					'first_name' => 'Alice',
					'role'       => 'administrator', // Smuggled key inside billing.
				),
			)
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'billing.role smuggle must be rejected -- billing sub-schema is closed.'
		);
	}

	/**
	 * MED-4 nested-smuggle: a key inside line_items[].
	 * line_items[] items also set additionalProperties:false; meta_data is a common
	 * injection vector in WC -- it must be rejected before execute.
	 */
	public function test_create_order_line_items_nested_smuggle_rejected(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array(
				'line_items' => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'meta_data'  => 'injected', // Smuggled key inside line_items item.
					),
				),
			)
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'line_items[].meta_data smuggle must be rejected -- item sub-schema is closed.'
		);
	}

	public function test_create_order_invalid_status_returns_error(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array( 'status' => 'totally-invalid-status' )
		);
		$this->assertInstanceOf( \WP_Error::class, $res, 'Invalid status must return WP_Error.' );
	}

	public function test_create_order_empty_billing_shipping_encode_as_objects(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order' )->execute( array() );
		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$encoded = wp_json_encode( $res );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( '"billing":[]', $encoded );
		$this->assertStringNotContainsString( '"shipping":[]', $encoded );
	}

	public function test_update_order_patches_billing_city(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id' => 5001,
				'billing'  => array(
					'city' => 'Chicago',
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'Chicago', $res['billing']['city'] ?? null, 'billing.city must be patched.' );
	}

	public function test_update_order_field_isolation_billing_does_not_touch_shipping(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		// Seed with a known shipping country.
		WcOrderStubStore::seed(
			5050,
			array(
				'number'   => '5050',
				'status'   => 'processing',
				'billing'  => array( 'city' => 'Berlin' ),
				'shipping' => array( 'country' => 'DE' ),
			)
		);

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id' => 5050,
				'billing'  => array( 'city' => 'Hamburg' ),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $res );
		// billing updated.
		$this->assertSame( 'Hamburg', $res['billing']['city'] ?? null );
		// shipping country MUST be unchanged.
		$this->assertSame( 'DE', $res['shipping']['country'] ?? null, 'Updating billing must not touch shipping.' );
	}

	public function test_update_order_field_isolation_shipping_does_not_touch_billing(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		// Seed with a known billing city.
		WcOrderStubStore::seed(
			5051,
			array(
				'number'   => '5051',
				'status'   => 'processing',
				'billing'  => array( 'city' => 'Berlin' ),
				'shipping' => array( 'country' => 'DE' ),
			)
		);

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id' => 5051,
				'shipping' => array( 'country' => 'FR' ),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $res );
		// shipping updated.
		$this->assertSame( 'FR', $res['shipping']['country'] ?? null );
		// billing city MUST be unchanged.
		$this->assertSame( 'Berlin', $res['billing']['city'] ?? null, 'Updating shipping must not touch billing.' );
	}

	public function test_update_order_line_items_nested_smuggle_rejected(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'   => 5001,
				'line_items' => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'meta_data'  => 'injected', // Smuggled key inside line_items item.
					),
				),
			)
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'line_items[].meta_data smuggle must be rejected on update too -- the item sub-schema is closed.'
		);
	}

	/**
	 * B27: the add-items promise is "the entire request fails with no partial write". Real
	 * add_product() persists each item row immediately, so a throw mid-loop used to leave the
	 * earlier items attached to the order while the caller was told the request failed. A
	 * mid-loop throw must now roll the already-written items back and return a specific error.
	 */
	public function test_update_order_mid_loop_add_failure_leaves_no_partial_write(): void {
		$this->register_wc_order_writes();
		$this->make_seeded_order_editable();
		$this->acting_as( 'administrator' );

		// Order 5001 seeds exactly one line item. Throw on the SECOND add of this request.
		WcOrderStubStore::$add_product_throw_on_call = 2;

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
					array(
						'product_id' => 101,
						'quantity'   => 2,
					),
				),
			)
		);
		WcOrderStubStore::$add_product_throw_on_call = 0;

		$this->assertInstanceOf( \WP_Error::class, $res, 'a mid-loop add failure must fail the request.' );
		$this->assertSame( 'aafm_wc_line_items_not_applied', $res->get_error_code() );

		// The store must hold ONLY the original seeded item - the first added item was rolled back.
		$stored_items = WcOrderStubStore::get( 5001 )['items'] ?? array();
		$this->assertCount( 1, $stored_items, 'the item added before the throw must be rolled back, keeping the promise of no partial write.' );
	}

	/**
	 * B27 (create side): a mid-loop throw on wc-create-order used to leave the already-added
	 * items persisted as order_id-0 orphan rows. They must be cleaned up on failure.
	 */
	public function test_create_order_mid_loop_add_failure_leaves_no_orphan_items(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$add_product_throw_on_call = 2;

		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array(
				'line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
					array(
						'product_id' => 101,
						'quantity'   => 2,
					),
				),
			)
		);
		WcOrderStubStore::$add_product_throw_on_call = 0;

		$this->assertInstanceOf( \WP_Error::class, $res, 'a mid-loop add failure must fail the create.' );
		$this->assertSame( 'aafm_wc_line_items_not_applied', $res->get_error_code() );
		$this->assertSame( array(), WcOrderStubStore::$orphan_items, 'no order_id-0 orphan item rows may survive a failed create.' );
	}

	public function test_update_order_empty_billing_shipping_encode_as_objects(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		// Seed an order with empty billing/shipping, then patch a non-address field.
		WcOrderStubStore::seed(
			5052,
			array(
				'number' => '5052',
				'status' => 'processing',
			)
		);

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'    => 5052,
				'customer_id' => 7,
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$encoded = wp_json_encode( $res );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( '"billing":[]', $encoded, 'Empty billing must encode as {} on the update return path.' );
		$this->assertStringNotContainsString( '"shipping":[]', $encoded, 'Empty shipping must encode as {} on the update return path.' );
	}

	public function test_update_order_empty_patch_is_noop_success(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$before = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( \WP_Error::class, $before );

		// Empty PATCH -- no fields.
		$res = wp_get_ability( 'aafm/wc-update-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( \WP_Error::class, $res, 'Empty PATCH must be a no-op success.' );
		$this->assertSame( $before['status'], $res['status'], 'Status must be unchanged on empty PATCH.' );
	}

	public function test_update_order_unknown_id_returns_error(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-order' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
	}

	public function test_update_order_denied_requires_manage_woocommerce(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-order' )->check_permissions( array( 'order_id' => 5001 ) )
		);
	}


	public function test_update_order_top_level_smuggle_rejected(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'   => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $res, 'Closed schema must reject unknown top-level field.' );
	}

	/**
	 * MED-4 nested-smuggle on update: billing.role must be rejected.
	 */
	public function test_update_order_billing_nested_smuggle_rejected(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id' => 5001,
				'billing'  => array(
					'city' => 'London',
					'role' => 'administrator',
				),
			)
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'billing.role smuggle on update must be rejected -- billing sub-schema is closed.'
		);
	}

	// =========================================================================
	// aafm/wc-update-order -- add_line_items / line_items (additive, MCP defect fix)
	//
	// wc-update-order used to call the SAME line_items handler as create, which always ADDS via
	// add_product() -- an agent asked to "change this order to 2 units" instead got 2 MORE units.
	// The fix does not change that additive behaviour (removing it would be a breaking change for
	// existing callers already sending line_items); it adds an honestly-named add_line_items field
	// and keeps line_items as a deprecated additive alias. Order 5001 (seeded in set_up() via
	// seed_wc_orders()) carries exactly one line item -- product 101, quantity 2 -- so every test
	// below can assert the original item survives untouched alongside whatever is added.
	// =========================================================================

	/**
	 * Add_line_items is the honestly-named field: sending it must ADD a new line item to the
	 * existing order, on top of whatever is already there -- never replace it.
	 */
	public function test_update_order_add_line_items_adds_a_new_item(): void {
		$this->register_wc_order_writes();
		$this->make_seeded_order_editable();
		$this->acting_as( 'administrator' );

		$before = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( \WP_Error::class, $before );
		$this->assertCount( 1, $before['line_items'], 'Order 5001 seeds exactly one line item.' );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertCount( 2, $res['line_items'], 'add_line_items must ADD a second line item, not replace the first.' );
		$this->assertSame( 101, $res['line_items'][0]['product_id'] );
		$this->assertSame( 2, $res['line_items'][0]['quantity'], 'The original qty-2 item must survive untouched.' );
		$this->assertSame( 101, $res['line_items'][1]['product_id'] );
		$this->assertSame( 1, $res['line_items'][1]['quantity'], 'The newly added item carries the requested quantity.' );
	}

	/**
	 * Adding a line item must move the order's TOTAL, not just its items and subtotal.
	 *
	 * WC_Abstract_Order::add_product() writes the item row and leaves the order total alone, so
	 * before the fix an order that gained 19.99 of goods still billed the figure it had before.
	 * Order 5001 seeds one item at
	 * 39.98 with tax 4.00 and shipping 5.99 against a stored total of 49.99; adding product 101
	 * (19.99) once takes the goods to 59.97 and the order to 69.96.
	 */
	public function test_update_order_add_line_items_recalculates_the_order_total(): void {
		$this->register_wc_order_writes();
		$this->make_seeded_order_editable();
		$this->acting_as( 'administrator' );

		$before = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertNotInstanceOf( \WP_Error::class, $before );
		$this->assertSame( '49.99', $before['totals']['total'] );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertSame(
			'69.96',
			$res['totals']['total'],
			'The order total must follow the line items it now holds, not stay at the pre-update figure.'
		);
		$this->assertSame( '59.97', $res['totals']['subtotal'], 'The goods subtotal covers both items.' );

		// Persisted, not just shaped into the response: a fresh read sees the same total.
		$after = wp_get_ability( 'aafm/wc-get-order' )->execute( array( 'order_id' => 5001 ) );
		$this->assertSame( '69.96', $after['totals']['total'] );
	}

	/**
	 * The other half of the same fix: an update that does not touch the line items must leave the
	 * total exactly as it was. WooCommerce lets a shop owner set an order total by hand, and a
	 * postcode or customer-note correction is no reason to recompute it out from under them. Order
	 * 5001's seeded total (49.99) deliberately does not equal its own items plus tax and shipping
	 * (49.97), so a stray recalculation would be visible here.
	 */
	public function test_update_order_without_line_items_leaves_the_total_untouched(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'      => 5001,
				'customer_note' => 'Leave with the neighbour.',
				'billing'       => array( 'postcode' => '62702' ),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'Leave with the neighbour.', $res['customer_note'] );
		$this->assertSame( '62702', $res['billing']['postcode'] );
		$this->assertSame(
			'49.99',
			$res['totals']['total'],
			'An update that adds no line items must not rewrite a total someone set by hand.'
		);
	}

	/**
	 * An empty add_line_items array asks for nothing to be added, so it is not a line-item change
	 * and must not trigger a recalculation either.
	 */
	public function test_update_order_with_an_empty_line_items_array_leaves_the_total_untouched(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertCount( 1, $res['line_items'], 'Nothing was added.' );
		$this->assertSame( '49.99', $res['totals']['total'] );
	}

	/**
	 * Line_items is kept as a deprecated alias with IDENTICAL additive behaviour to
	 * add_line_items -- an existing caller that already sends line_items on update must keep
	 * getting the same (additive) result after this fix, never a silent switch to replace.
	 */
	public function test_update_order_line_items_deprecated_alias_still_adds(): void {
		$this->register_wc_order_writes();
		$this->make_seeded_order_editable();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'   => 5001,
				'line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 3,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertCount( 2, $res['line_items'], 'The deprecated line_items alias must still ADD, not replace.' );
		$this->assertSame( 2, $res['line_items'][0]['quantity'], 'The original item survives untouched.' );
		$this->assertSame( 3, $res['line_items'][1]['quantity'], 'The item sent via the deprecated alias is added.' );
	}

	/**
	 * Documented both-sent rule: line_items and add_line_items sent together on the same call are
	 * COMBINED into one list -- every item in both is added, none is dropped in favour of the
	 * other. (See the add_line_items/line_items descriptions in aafm_args_wc_update_order().)
	 */
	public function test_update_order_add_line_items_and_line_items_combine_when_both_sent(): void {
		$this->register_wc_order_writes();
		$this->make_seeded_order_editable();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'line_items'     => array(
					array(
						'product_id' => 101,
						'quantity'   => 5,
					),
				),
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 7,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertCount(
			3,
			$res['line_items'],
			'Sending both fields combines them: the original item plus one from each field.'
		);
		$quantities = wp_list_pluck( $res['line_items'], 'quantity' );
		$this->assertSame(
			array( 2, 5, 7 ),
			$quantities,
			'The original item, then the line_items item, then the add_line_items item, in that order.'
		);
	}

	/**
	 * Wc-create-order keeps accepting line_items, unchanged: on create there is no existing item
	 * to add to, so line_items is not deprecated there and carries none of the update-only
	 * alias/combine wording -- it is simply how a new order's items are provided.
	 */
	public function test_create_order_accepts_line_items(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array(
				'line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 4,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertCount( 1, $res['line_items'] );
		$this->assertSame( 101, $res['line_items'][0]['product_id'] );
		$this->assertSame( 4, $res['line_items'][0]['quantity'] );
	}

	/**
	 * Wc-create-order has no add_line_items field -- there is no existing order to add to on
	 * create, so the closed schema must reject it rather than silently accepting and ignoring it.
	 */
	public function test_create_order_rejects_add_line_items(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-order' )->execute(
			array(
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'add_line_items has no place on create -- the closed schema must reject it.'
		);
	}

	// =========================================================================
	// aafm/wc-update-order-status
	// =========================================================================

	/**
	 * Enable + register the full order ability set including wc-update-order-status.
	 */
	private function register_wc_order_status_write(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-orders',
				'aafm/wc-get-order',
				'aafm/wc-create-order',
				'aafm/wc-update-order',
				'aafm/wc-update-order-status',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_update_order_status_sets_the_status(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 5001,
				'status'   => 'completed',
			)
		);

		$this->assertIsArray( $res );
		$this->assertSame( 'completed', $res['status'], 'Status must be updated to completed.' );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertSame( 5001, $res['id'] );
	}

	public function test_update_order_status_wc_prefixed_form_accepted(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 5001,
				'status'   => 'wc-completed',
			)
		);

		$this->assertIsArray( $res );
		$this->assertSame( 'completed', $res['status'], 'wc-prefixed status form must be accepted and normalised.' );
	}

	public function test_update_order_status_invalid_status_returns_error(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 5001,
				'status'   => 'not-a-real-status',
			)
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'An unrecognised status slug must be rejected.'
		);
	}

	public function test_update_order_status_unknown_order_returns_error(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 99999,
				'status'   => 'completed',
			)
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'A non-existent order_id must return an error.'
		);
	}

	public function test_update_order_status_denied_requires_manage_woocommerce(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'editor' );

		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 5001,
				'status'   => 'completed',
			)
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'Editor must not be able to update order status -- manage_woocommerce required.'
		);
	}

	/**
	 * B55: WC_Order::update_status() swallows its own exceptions and returns false on a failed
	 * transition, and the executor ignored that return - a failed transition came back as a
	 * success payload showing the OLD status. The return is now checked and the resulting status
	 * verified, erroring honestly.
	 */
	public function test_update_order_status_failed_transition_is_an_error_not_a_success_payload(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$update_status_should_fail = true;
		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 5001,
				'status'   => 'completed',
			)
		);
		WcOrderStubStore::$update_status_should_fail = false;

		$this->assertInstanceOf( \WP_Error::class, $res, 'a failed transition must be an error, never a payload carrying the old status as success.' );
		$this->assertSame( 'aafm_wc_status_update_failed', $res->get_error_code() );

		// The stored status must be untouched.
		$this->assertSame( 'processing', (string) ( WcOrderStubStore::get( 5001 )['status'] ?? '' ) );
	}

	public function test_update_order_status_top_level_smuggle_rejected(): void {
		$this->register_wc_order_status_write();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-order-status' )->execute(
			array(
				'order_id' => 5001,
				'status'   => 'completed',
				'role'     => 'administrator',
			)
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$res,
			'Top-level smuggle via extra key must be rejected -- schema is closed.'
		);
	}

	/**
	 * Register the write abilities a case needs before it runs.
	 *
	 * The order reads register in set_up(); the order writes register on demand
	 * through their own helpers, so each audit case names the helper it needs.
	 *
	 * @param string $helper Helper method name, or '' when no extra registration is needed.
	 */
	private function register_for_audit( string $helper ): void {
		if ( '' === $helper ) {
			return;
		}
		$this->$helper();
	}

	/**
	 * Audit: a successful execute is recorded under the calling ability.
	 *
	 * @dataProvider provide_success_audit_cases
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Execute args.
	 * @param string               $helper  Registration helper to run first, or ''.
	 */
	public function test_success_is_audited( string $ability, array $args, string $helper ): void {
		$this->register_for_audit( $helper );
		$this->acting_as( 'administrator' );
		wp_get_ability( $ability )->execute( $args );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each order ability and the args/registration its original audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function provide_success_audit_cases(): array {
		return array(
			'list-orders'         => array( 'aafm/wc-list-orders', array(), '' ),
			'get-order'           => array( 'aafm/wc-get-order', array( 'order_id' => 5001 ), '' ),
			'create-order'        => array( 'aafm/wc-create-order', array(), 'register_wc_order_writes' ),
			'update-order'        => array( 'aafm/wc-update-order', array( 'order_id' => 5001 ), 'register_wc_order_writes' ),
			'update-order-status' => array(
				'aafm/wc-update-order-status',
				array(
					'order_id' => 5001,
					'status'   => 'completed',
				),
				'register_wc_order_status_write',
			),
		);
	}

	/**
	 * Audit: a denied permission check is recorded under the calling ability.
	 *
	 * @dataProvider provide_denied_audit_cases
	 *
	 * @param string               $ability  Ability name.
	 * @param array<string, mixed> $args     check_permissions args.
	 * @param string               $helper   Registration helper to run first, or ''.
	 * @param string               $low_role Role that must be denied.
	 */
	public function test_denied_is_audited( string $ability, array $args, string $helper, string $low_role ): void {
		$this->register_for_audit( $helper );
		$this->acting_as( $low_role );
		wp_get_ability( $ability )->check_permissions( $args );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each order ability and the args/registration its original denied audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
	 */
	public function provide_denied_audit_cases(): array {
		return array(
			'list-orders'         => array( 'aafm/wc-list-orders', array(), '', 'editor' ),
			'get-order'           => array( 'aafm/wc-get-order', array( 'order_id' => 5001 ), '', 'editor' ),
			'create-order'        => array( 'aafm/wc-create-order', array(), 'register_wc_order_writes', 'editor' ),
			'update-order'        => array( 'aafm/wc-update-order', array( 'order_id' => 5001 ), 'register_wc_order_writes', 'editor' ),
			'update-order-status' => array(
				'aafm/wc-update-order-status',
				array(
					'order_id' => 5001,
					'status'   => 'completed',
				),
				'register_wc_order_status_write',
				'editor',
			),
		);
	}

	/**
	 * R3-1: adding a line item to an order WooCommerce no longer treats as editable is REFUSED.
	 *
	 * This replaces a test that asserted calculate_totals() was called with false for a completed
	 * order. That assertion encoded the defect instead of detecting it: passing false does protect
	 * the historical tax on the existing items, but it also means the NEW item keeps the empty tax
	 * map it was created with, so a taxable line was recorded at its net price and the order
	 * under-billed. Confirmed against real WooCommerce on the DDEV clone, 20% rate, price 100:
	 * a completed order came out at total 100 / tax 0 while the identical pending order came out at
	 * total 120 / tax 20.
	 *
	 * Neither branch was safe, so the write is refused. The assertion is deliberately on the
	 * OUTCOME (an error, and no item written) rather than on which argument reached a stub, because
	 * asserting the argument is what let the original defect through.
	 */
	public function test_adding_an_item_to_a_completed_order_is_refused(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$orders[5001]['status'] = 'completed';
		$items_before                             = WcOrderStubStore::$add_product_calls;

		$result = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'Adding goods to a non-editable order must be refused, not recorded without tax.'
		);
		$this->assertSame( 'aafm_wc_order_not_editable', $result->get_error_code() );
		$this->assertStringContainsString(
			'completed',
			$result->get_error_message(),
			'The error names the status that blocked the write so the caller can act on it.'
		);
		$this->assertSame(
			$items_before,
			WcOrderStubStore::$add_product_calls,
			'The refusal must happen BEFORE add_product(), which writes each item row immediately.'
		);
	}

	/**
	 * R3-1: a PROCESSING order refuses the add, and this is the case worth doubting.
	 *
	 * It has its own named test rather than only a data-provider row because it is the one a future
	 * reader will question. processing is the normal state of a paid order, so refusing it looks at
	 * first glance like this plugin inventing a restriction. It is not: WC_Order::is_editable()
	 * (class-wc-order.php:1715) returns true only for pending, on-hold and auto-draft, and
	 * WooCommerce's own order screen gates its "Add item(s)" button on that same call. A processing
	 * order gives you no way to add an item in WooCommerce's own UI either.
	 *
	 * This is also where the old defect fired most often. Adding a taxable line to a processing
	 * order recorded it with no tax at all, which understated what the customer was charged.
	 */
	public function test_adding_an_item_to_a_processing_order_is_refused(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$orders[5001]['status'] = 'processing';
		$items_before                             = WcOrderStubStore::$add_product_calls;

		$result = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'A paid, processing order is not editable, so the add is refused.' );
		$this->assertSame( 'aafm_wc_order_not_editable', $result->get_error_code() );
		$this->assertStringContainsString( 'processing', $result->get_error_message(), 'The error names the status so the caller knows why.' );
		$this->assertSame( $items_before, WcOrderStubStore::$add_product_calls, 'Nothing was written before the refusal.' );
	}

	/**
	 * R3-1: the refusal covers every non-editable status, not just completed. processing is the one
	 * that matters most in practice -- it is the normal state of a paid order, and it is NOT
	 * editable, so this is where the old code silently recorded untaxed goods most often.
	 *
	 * @dataProvider provide_non_editable_statuses
	 *
	 * @param string $status A status WooCommerce does not treat as editable.
	 */
	public function test_adding_an_item_is_refused_for_every_non_editable_status( string $status ): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$orders[5001]['status'] = $status;

		$result = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, $status . ' is not editable, so the add must be refused.' );
		$this->assertSame( 'aafm_wc_order_not_editable', $result->get_error_code() );
	}

	/**
	 * Cases: the WooCommerce order statuses that is_editable() excludes.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_non_editable_statuses(): array {
		return array(
			'processing' => array( 'processing' ),
			'completed'  => array( 'completed' ),
			'refunded'   => array( 'refunded' ),
			'cancelled'  => array( 'cancelled' ),
			'failed'     => array( 'failed' ),
		);
	}

	/**
	 * R3-1: a request that adds items AND completes the order in one call still taxes the goods.
	 *
	 * Editability is judged against the status the order HAD when the request arrived, and the
	 * recalculation then runs with taxes on unconditionally. Re-reading editability at the
	 * recalculation would consult the status this same request just applied, and take the untaxed
	 * branch for goods it had already accepted.
	 */
	public function test_adding_an_item_while_completing_the_order_still_recomputes_taxes(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$orders[5001]['status']          = 'pending';
		WcOrderStubStore::$last_calculate_totals_and_taxes = null;

		$result = wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'status'         => 'completed',
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result, 'The order was editable when the request arrived, so the add is allowed.' );
		$this->assertTrue(
			WcOrderStubStore::$last_calculate_totals_and_taxes,
			'Taxes must be computed for the goods this request added, whatever status the same request went on to set.'
		);
	}

	public function test_adding_an_item_to_an_editable_order_does_recompute_taxes(): void {
		$this->register_wc_order_writes();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::$orders[5001]['status']          = 'pending';
		WcOrderStubStore::$last_calculate_totals_and_taxes = null;

		wp_get_ability( 'aafm/wc-update-order' )->execute(
			array(
				'order_id'       => 5001,
				'add_line_items' => array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertTrue(
			WcOrderStubStore::$last_calculate_totals_and_taxes,
			'An order still being assembled should get a full recalculation, taxes included.'
		);
	}
	/**
	 * A throwing order lookup inside the rollback is contained, not propagated. (R8C-6)
	 *
	 * A lookup through wc_get_order() can throw as well as return false, and the two in the update
	 * rollback were unwrapped while the adjacent create rollback already treats a lookup as fallible. That
	 * matters more here than anywhere: this function IS the exception recovery path and has already
	 * deleted the request's item rows by the time it looks the order up, so an escaping Throwable
	 * hands the caller a raw crash in place of any of the three structured results, with the rows
	 * gone and the recalculation's tax changes never put back.
	 */
	public function test_rollback_survives_a_throwing_order_lookup(): void {
		$this->seed_orders_for_rollback_probe();

		\AAFM\Tests\WcOrderStubStore::$throw_on_get = true;
		try {
			$result = aafm_wc_rollback_recalculated_order( 4001, array(), array( 'total' => '10.00' ) );
		} finally {
			\AAFM\Tests\WcOrderStubStore::$throw_on_get = false;
		}

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'A throwing lookup must still yield a structured rollback result, not escape.'
		);
		$this->assertSame(
			'aafm_wc_order_totals_not_restored',
			$result->get_error_code(),
			'With the order unreadable the restore cannot be confirmed, so the honest verdict is the weak one, not the strong one.'
		);
	}

	/**
	 * The loader answers null for every way a lookup can fail, and only for those.
	 */
	public function test_order_loader_returns_null_rather_than_throwing(): void {
		$this->seed_orders_for_rollback_probe();

		$this->assertNull( aafm_wc_load_order_or_null( 0 ), 'A zero id is not a lookup.' );
		$this->assertNull( aafm_wc_load_order_or_null( 987654 ), 'A missing order is null, not false.' );
		$this->assertInstanceOf( \WC_Order::class, aafm_wc_load_order_or_null( 4001 ), 'A real order still loads.' );

		\AAFM\Tests\WcOrderStubStore::$throw_on_get = true;
		try {
			$this->assertNull( aafm_wc_load_order_or_null( 4001 ), 'A throwing factory is null, not an escaping Throwable.' );
		} finally {
			\AAFM\Tests\WcOrderStubStore::$throw_on_get = false;
		}
	}

	/**
	 * Seed one plain order for the rollback probes above.
	 */
	private function seed_orders_for_rollback_probe(): void {
		WcOrderStubStore::reset();
		WcOrderStubStore::seed(
			4001,
			array(
				'number' => '4001',
				'status' => 'processing',
				'total'  => '10.00',
			)
		);
	}
}
