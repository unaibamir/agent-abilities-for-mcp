<?php
/**
 * WooCommerce customer abilities: wc-list-customers, wc-get-customer, wc-create-customer,
 * wc-update-customer.
 *
 * WooCommerce is not installed in the DDEV test environment — every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcCustomerStubStore. The seed_wc_customers()
 * helper resets and seeds the store per test so each test starts with a clean, known state.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use Oversio\Tests\IntegrationStubs;
use Oversio\Tests\WcCustomerStubStore;
use WP_Error;

final class WooCustomersTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->seed_wc_customers();
		oversio_registry_cache_should_flush( true );
		$this->register_wc_customers();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcCustomerStubStore::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Enable and register the full WooCommerce customer ability set.
	 */
	private function register_wc_customers(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/wc-list-customers',
				'oversio/wc-get-customer',
				'oversio/wc-create-customer',
				'oversio/wc-update-customer',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	// =========================================================================
	// oversio/wc-list-customers
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_customers_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-list-customers' )->check_permissions( array() )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-list-customers' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'customers', $res );
		$this->assertArrayHasKey( 'total', $res );
	}

	/**
	 * List rows must include email (PII deliberately exposed) but no billing/shipping block.
	 */
	public function test_list_customers_exposes_email_not_address_block(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-list-customers' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['customers'] );

		$row = $res['customers'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'email', $row );
		$this->assertArrayHasKey( 'first_name', $row );
		$this->assertArrayHasKey( 'last_name', $row );
		$this->assertArrayHasKey( 'username', $row );
		$this->assertArrayHasKey( 'orders_count', $row );
		$this->assertArrayHasKey( 'total_spent', $row );

		// List rows intentionally carry email PII but no full address block.
		$this->assertArrayNotHasKey( 'billing', $row );
		$this->assertArrayNotHasKey( 'shipping', $row );
		$this->assertArrayNotHasKey( 'date_created', $row );
	}


	/**
	 * Host-inactive: customer abilities must be absent from the registry when WooCommerce is off.
	 */
	public function test_list_customers_host_inactive_absent_from_registry(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'oversio_integration_active_woocommerce' );
		add_filter( 'oversio_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( oversio_integration_active( 'woocommerce' ) );
		oversio_registry_cache_should_flush( true );

		$registry = oversio_get_abilities_registry();
		$this->assertArrayNotHasKey( 'oversio/wc-list-customers', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-get-customer', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-create-customer', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-update-customer', $registry );

		remove_filter( 'oversio_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// oversio/wc-get-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_get_customer_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-get-customer' )->check_permissions( array( 'customer_id' => 7001 ) )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A non-existent customer id returns a not-found WP_Error, not a raw exception from WC_Customer.
	 */
	public function test_get_customer_missing_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Full shape includes email AND billing phone (PII under the disclaimer).
	 */
	public function test_get_customer_exposes_pii_email_and_billing_phone(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// Top-level PII.
		$this->assertSame( 'jane@example.com', $res['email'] );

		// Billing PII must be present and populated.
		$this->assertArrayHasKey( 'billing', $res );
		$this->assertIsArray( $res['billing'] );
		$this->assertSame( 'jane@example.com', $res['billing']['email'] );
		$this->assertSame( '+1-555-0200', $res['billing']['phone'] );

		// Shipping must be present.
		$this->assertArrayHasKey( 'shipping', $res );
		$this->assertIsArray( $res['shipping'] );
	}

	/**
	 * Full shape includes all expected scalar fields.
	 */
	public function test_get_customer_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$this->assertSame( 7001, $res['id'] );
		$this->assertSame( 'Jane', $res['first_name'] );
		$this->assertSame( 'Doe', $res['last_name'] );
		$this->assertSame( 'janedoe', $res['username'] );
		$this->assertSame( 3, $res['orders_count'] );
		$this->assertSame( '149.97', $res['total_spent'] );
		$this->assertArrayHasKey( 'date_created', $res );
	}

	/**
	 * Unknown customer id returns WP_Error.
	 */
	public function test_get_customer_unknown_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Empty billing and shipping on a fresh customer serialize as objects, not arrays.
	 */
	public function test_get_customer_empty_billing_shipping_are_objects(): void {
		WcCustomerStubStore::seed( 7090, array( 'email' => 'empty@example.com' ) );
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 7090 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// When all billing fields are empty strings the assembler must return (object)array()
		// so JSON encodes as {} not [].
		$this->assertIsObject( $res['billing'], 'Empty billing must serialize as an object {}.' );
		$this->assertIsObject( $res['shipping'], 'Empty shipping must serialize as an object {}.' );
	}


	// =========================================================================
	// oversio/wc-create-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_create_customer_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-create-customer' )->check_permissions(
				array( 'email' => 'new@example.com' )
			)
		);
	}

	/**
	 * Create returns the full rich shape with the new customer id.
	 */
	public function test_create_customer_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array(
				'email'      => 'newcustomer@example.com',
				'username'   => 'newcustomer',
				'first_name' => 'Alice',
				'last_name'  => 'Smith',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( 'Alice', $res['first_name'] );
		$this->assertSame( 'Smith', $res['last_name'] );
	}

	/**
	 * Real WooCommerce wc_create_customer() returns an int user id (or WP_Error), not a
	 * WC_Customer object. A successful create must return the rich shape exactly once and
	 * leave a single customer behind — no false error on the success path, no duplicate.
	 */
	public function test_create_customer_int_return_is_success_no_duplicate(): void {
		WcCustomerStubStore::reset();
		$this->acting_as( 'administrator' );

		$first = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array(
				'email'      => 'intreturn@example.com',
				'first_name' => 'Int',
				'last_name'  => 'Return',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $first, 'A positive int return must be treated as success, not an error.' );
		$this->assertArrayHasKey( 'id', $first );
		$this->assertGreaterThan( 0, $first['id'] );
		$this->assertSame( 'Int', $first['first_name'] );

		// Exactly one customer exists after a single create — no duplicate spawned on the
		// success path (the inverted check used to create then return an error).
		$after_one = wp_get_ability( 'oversio/wc-list-customers' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $after_one );
		$this->assertSame( 1, $after_one['total'], 'A single create must leave exactly one customer.' );
	}

	/**
	 * Create with billing fields writes them through to the returned shape.
	 */
	public function test_create_customer_with_billing_fields(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array(
				'email'   => 'billing@example.com',
				'billing' => array(
					'first_name' => 'Bob',
					'last_name'  => 'Jones',
					'phone'      => '+1-555-9999',
					'city'       => 'Chicago',
				),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Bob', $res['billing']['first_name'] );
		$this->assertSame( '+1-555-9999', $res['billing']['phone'] );
		$this->assertSame( 'Chicago', $res['billing']['city'] );
	}

	/**
	 * Nested-smuggle via billing.role must be rejected by the closed billing schema before the
	 * executor runs — the billing sub-object carries additionalProperties:false.
	 */
	public function test_create_customer_nested_smuggle_billing_role_is_rejected(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array(
				'email'   => 'smuggle@example.com',
				'billing' => array(
					'first_name' => 'Eve',
					'role'       => 'administrator', // Smuggled field — must be rejected.
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'billing.role smuggle must be rejected by the closed schema.' );
	}

	/**
	 * Nested-smuggle via shipping.role must also be rejected.
	 */
	public function test_create_customer_nested_smuggle_shipping_role_is_rejected(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array(
				'email'    => 'smuggle2@example.com',
				'shipping' => array(
					'first_name' => 'Eve',
					'role'       => 'administrator', // Smuggled field — must be rejected.
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'shipping.role smuggle must be rejected by the closed schema.' );
	}



	/**
	 * Store failure (create_should_fail) surfaces as WP_Error, not a false-success.
	 */
	public function test_create_customer_store_failure_returns_error(): void {
		WcCustomerStubStore::$create_should_fail = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array( 'email' => 'fail@example.com' )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Store failure must not lie success.' );
		WcCustomerStubStore::$create_should_fail = false;
	}

	// =========================================================================
	// oversio/wc-update-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_update_customer_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-update-customer' )->check_permissions(
				array( 'customer_id' => 7001 )
			)
		);
	}

	/**
	 * Update with only customer_id (no other fields) is a no-op success.
	 */
	public function test_update_customer_empty_patch_is_noop(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array( 'customer_id' => 7001 )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 7001, $res['id'] );
		// Existing data must be unchanged.
		$this->assertSame( 'jane@example.com', $res['email'] );
	}

	/**
	 * Update changes only the supplied fields; unsupplied fields are preserved.
	 */
	public function test_update_customer_field_isolation(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'first_name'  => 'Janet',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Janet', $res['first_name'] );
		// last_name was not supplied; it must survive unchanged.
		$this->assertSame( 'Doe', $res['last_name'] );
	}

	/**
	 * Update with billing fields writes them through.
	 */
	public function test_update_customer_billing_fields(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'billing'     => array(
					'city'  => 'Shelbyville',
					'phone' => '+1-555-1234',
				),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Shelbyville', $res['billing']['city'] );
		$this->assertSame( '+1-555-1234', $res['billing']['phone'] );
	}

	/**
	 * Nested-smuggle via billing.role must be rejected.
	 */
	public function test_update_customer_nested_smuggle_billing_role_is_rejected(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'billing'     => array(
					'role' => 'administrator',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'billing.role smuggle must be rejected by the closed schema.' );
	}

	/**
	 * Unknown customer id returns WP_Error.
	 */
	public function test_update_customer_unknown_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 99999,
				'first_name'  => 'Ghost',
			)
		);
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
			array_merge( $valid_min_args, array( 'evil_field' => 'x' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown field.' );
	}

	/**
	 * Cases: each customer ability and the minimal valid args its original test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function provide_closed_schema_cases(): array {
		return array(
			'list-customers'  => array( 'oversio/wc-list-customers', array() ),
			'get-customer'    => array( 'oversio/wc-get-customer', array( 'customer_id' => 7001 ) ),
			'create-customer' => array( 'oversio/wc-create-customer', array( 'email' => 'x@example.com' ) ),
			'update-customer' => array( 'oversio/wc-update-customer', array( 'customer_id' => 7001 ) ),
		);
	}

	/**
	 * Create→get round-trip: a newly created customer can be retrieved by its returned id.
	 */
	public function test_create_then_get_round_trip(): void {
		$this->acting_as( 'administrator' );
		$created = wp_get_ability( 'oversio/wc-create-customer' )->execute(
			array(
				'email'      => 'roundtrip@example.com',
				'first_name' => 'Round',
				'last_name'  => 'Trip',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $created );
		$new_id = $created['id'];

		$fetched = wp_get_ability( 'oversio/wc-get-customer' )->execute(
			array( 'customer_id' => $new_id )
		);
		$this->assertNotInstanceOf( WP_Error::class, $fetched );
		$this->assertSame( $new_id, $fetched['id'] );
		$this->assertSame( 'Round', $fetched['first_name'] );
	}

	// =========================================================================
	// FIX-5: update store-failure guard.
	// =========================================================================

	/**
	 * Store failure on update (update_should_fail) must surface as WP_Error, never false-success.
	 */
	public function test_update_customer_store_failure_returns_error(): void {
		WcCustomerStubStore::$update_should_fail = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'first_name'  => 'ShouldFail',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A store failure on update must not lie success.' );
		WcCustomerStubStore::$update_should_fail = false;
	}

	// =========================================================================
	// FIX-6: empty store returns empty array + zero total.
	// =========================================================================

	/**
	 * With no customers in the store the ability must return customers:[] (array) and total:0.
	 */
	public function test_list_customers_empty_store_returns_empty(): void {
		WcCustomerStubStore::reset();

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-list-customers' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $res['customers'], 'customers must be an empty array when the store is empty.' );
		$this->assertSame( 0, $res['total'], 'total must be 0 when the store is empty.' );
		// The customers value must encode as [] not {} in JSON.
		$json = wp_json_encode( $res['customers'] );
		$this->assertSame( '[]', $json, 'An empty customers list must JSON-encode as [] not {}.' );
	}

	// =========================================================================
	// FIX-7: id fidelity and grand total across pages.
	// =========================================================================

	/**
	 * Seeding two customers must surface both ids in the list and report total:2.
	 * A paginated sub-request must still report total:2 (grand count, not page count).
	 */
	public function test_list_customers_returns_both_ids_and_grand_total(): void {
		WcCustomerStubStore::reset();
		WcCustomerStubStore::seed( 7001, array( 'email' => 'a@example.com' ) );
		WcCustomerStubStore::seed( 7002, array( 'email' => 'b@example.com' ) );

		$this->acting_as( 'administrator' );

		// Full list: both ids present, total == 2.
		$res = wp_get_ability( 'oversio/wc-list-customers' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$ids = wp_list_pluck( $res['customers'], 'id' );
		$this->assertContains( 7001, $ids );
		$this->assertContains( 7002, $ids );
		$this->assertSame( 2, $res['total'] );

		// Page 2 of per_page=1: only 1 row in page, but total is still the grand count (2).
		$paged = wp_get_ability( 'oversio/wc-list-customers' )->execute(
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $paged );
		$this->assertCount( 1, $paged['customers'], 'Page 2 of per_page=1 must contain exactly 1 row.' );
		$this->assertSame( 2, $paged['total'], 'total must be the grand count (2), not the page slice length (1).' );
	}

	// =========================================================================
	// FIX-8: pin error codes on unknown-id tests.
	// =========================================================================

	/**
	 * Unknown customer id on get must return a WP_Error with code oversio_error.
	 */
	public function test_get_customer_unknown_id_returns_oversio_error_code(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-customer' )->execute( array( 'customer_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'oversio_error', $res->get_error_code() );
	}

	/**
	 * Unknown customer id on update must return a WP_Error with code oversio_error.
	 */
	public function test_update_customer_unknown_id_returns_oversio_error_code(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 99999,
				'first_name'  => 'Ghost',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'oversio_error', $res->get_error_code() );
	}

	// =========================================================================
	// FIX-9: update field-isolation — billing-only and shipping-only patches.
	// =========================================================================

	/**
	 * Smuggling shipping.role on update must be rejected by the closed shipping schema.
	 */
	public function test_update_customer_nested_smuggle_shipping_role_is_rejected(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'shipping'    => array(
					'role' => 'administrator',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'shipping.role smuggle must be rejected by the closed schema.' );
	}

	/**
	 * Updating billing fields only must leave shipping intact.
	 */
	public function test_update_customer_billing_only_leaves_shipping_intact(): void {
		// Seed a customer with known billing and shipping data.
		WcCustomerStubStore::seed(
			7050,
			array(
				'email'    => 'iso@example.com',
				'billing'  => array(
					'first_name' => 'OldBilling',
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => 'OldCity',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
					'email'      => '',
					'phone'      => '',
				),
				'shipping' => array(
					'first_name' => 'KeepMe',
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '99 Ship St',
					'address_2'  => '',
					'city'       => 'ShipCity',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7050,
				'billing'     => array( 'city' => 'NewCity' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'NewCity', $res['billing']['city'], 'billing.city must be updated.' );
		// Shipping fields must survive untouched.
		$this->assertSame( 'KeepMe', $res['shipping']['first_name'], 'shipping.first_name must not be cleared by a billing-only update.' );
		$this->assertSame( 'ShipCity', $res['shipping']['city'], 'shipping.city must not be cleared by a billing-only update.' );
	}

	/**
	 * Updating shipping fields only must leave billing intact.
	 */
	public function test_update_customer_shipping_only_leaves_billing_intact(): void {
		WcCustomerStubStore::seed(
			7051,
			array(
				'email'    => 'iso2@example.com',
				'billing'  => array(
					'first_name' => 'KeepBilling',
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '10 Bill Ave',
					'address_2'  => '',
					'city'       => 'BillCity',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
					'email'      => 'iso2@example.com',
					'phone'      => '+1-555-0300',
				),
				'shipping' => array(
					'first_name' => 'OldShip',
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => 'OldShipCity',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-update-customer' )->execute(
			array(
				'customer_id' => 7051,
				'shipping'    => array( 'city' => 'NewShipCity' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'NewShipCity', $res['shipping']['city'], 'shipping.city must be updated.' );
		// Billing fields must survive untouched.
		$this->assertSame( 'KeepBilling', $res['billing']['first_name'], 'billing.first_name must not be cleared by a shipping-only update.' );
		$this->assertSame( 'BillCity', $res['billing']['city'], 'billing.city must not be cleared by a shipping-only update.' );
	}

	/**
	 * Audit: a successful execute is recorded under the calling ability.
	 *
	 * @dataProvider provide_success_audit_cases
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Execute args.
	 */
	public function test_success_is_audited( string $ability, array $args ): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( $ability )->execute( $args );

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each customer ability and the args its original audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function provide_success_audit_cases(): array {
		return array(
			'list-customers'  => array( 'oversio/wc-list-customers', array() ),
			'get-customer'    => array( 'oversio/wc-get-customer', array( 'customer_id' => 7001 ) ),
			'create-customer' => array( 'oversio/wc-create-customer', array( 'email' => 'audit@example.com' ) ),
			'update-customer' => array(
				'oversio/wc-update-customer',
				array(
					'customer_id' => 7001,
					'first_name'  => 'Audited',
				),
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
	 * @param string               $low_role Role that must be denied.
	 */
	public function test_denied_is_audited( string $ability, array $args, string $low_role ): void {
		$this->acting_as( $low_role );
		wp_get_ability( $ability )->check_permissions( $args );

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each customer ability and the args its original denied audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function provide_denied_audit_cases(): array {
		return array(
			'list-customers'  => array( 'oversio/wc-list-customers', array(), 'editor' ),
			'get-customer'    => array( 'oversio/wc-get-customer', array( 'customer_id' => 7001 ), 'editor' ),
			'create-customer' => array( 'oversio/wc-create-customer', array( 'email' => 'denied@example.com' ), 'editor' ),
			'update-customer' => array( 'oversio/wc-update-customer', array( 'customer_id' => 7001 ), 'editor' ),
		);
	}
}
