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

	// =========================================================================
	// aafm/wc-create-order + aafm/wc-update-order
	// =========================================================================

	/**
	 * Enable + register the full order ability set including writes.
	 */
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
}
