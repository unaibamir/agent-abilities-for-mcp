<?php
/**
 * WooCommerce customer abilities: wc-list-customers, wc-get-customer, wc-create-customer,
 * wc-update-customer, wc-delete-customer.
 *
 * WooCommerce is not installed in the DDEV test environment — every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcCustomerStubStore. The seed_wc_customers()
 * helper resets and seeds the store per test so each test starts with a clean, known state.
 *
 * The delete ability exercises REAL WordPress user creation (wp_insert_user) because
 * wc-delete-customer ultimately calls wp_delete_user(), which only works on real WP users.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcCustomerStubStore;
use WP_Error;

final class WooCustomersTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->seed_wc_customers();
		aafm_registry_cache_should_flush( true );
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
	 * Enable and register the full WooCommerce customer ability set.
	 */
	private function register_wc_customers(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-customers',
				'aafm/wc-get-customer',
				'aafm/wc-create-customer',
				'aafm/wc-update-customer',
				'aafm/wc-delete-customer',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// aafm/wc-list-customers
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_customers_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-customers' )->check_permissions( array() )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-customers' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'customers', $res );
		$this->assertArrayHasKey( 'total', $res );
	}

	/**
	 * List rows must include email (PII deliberately exposed) but no billing/shipping block.
	 */
	public function test_list_customers_exposes_email_not_address_block(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-customers' )->execute( array() );
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
	 * Closed schema rejects an unknown field.
	 */
	public function test_list_customers_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-customers' )->execute( array( 'evil_field' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown field.' );
	}

	/**
	 * Audit: a successful list call is recorded.
	 */
	public function test_list_customers_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-list-customers' )->execute( array() );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-list-customers', $abilities );
	}

	/**
	 * Audit: a denied check_permissions call is recorded.
	 */
	public function test_list_customers_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-list-customers' )->check_permissions( array() );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-list-customers', $abilities );
	}

	/**
	 * Readonly annotation is set; destructive is false.
	 */
	public function test_list_customers_is_read_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-list-customers' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'] ?? false, 'wc-list-customers must be annotated readonly.' );
		$this->assertFalse( $annotations['destructive'] ?? true, 'wc-list-customers must be annotated non-destructive.' );
	}

	/**
	 * Host-inactive: customer abilities must be absent from the registry when WooCommerce is off.
	 */
	public function test_list_customers_host_inactive_absent_from_registry(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-customers', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-customer', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-customer', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-customer', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-delete-customer', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// aafm/wc-get-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_get_customer_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-customer' )->check_permissions( array( 'customer_id' => 7001 ) )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Full shape includes email AND billing phone (PII under the disclaimer).
	 */
	public function test_get_customer_exposes_pii_email_and_billing_phone(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );
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
		$res = wp_get_ability( 'aafm/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );
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
		$res = wp_get_ability( 'aafm/wc-get-customer' )->execute( array( 'customer_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Empty billing and shipping on a fresh customer serialize as objects, not arrays.
	 */
	public function test_get_customer_empty_billing_shipping_are_objects(): void {
		WcCustomerStubStore::seed( 7090, array( 'email' => 'empty@example.com' ) );
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-customer' )->execute( array( 'customer_id' => 7090 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// When all billing fields are empty strings the assembler must return (object)array()
		// so JSON encodes as {} not [].
		$this->assertIsObject( $res['billing'], 'Empty billing must serialize as an object {}.' );
		$this->assertIsObject( $res['shipping'], 'Empty shipping must serialize as an object {}.' );
	}

	/**
	 * Closed schema rejects an unknown field.
	 */
	public function test_get_customer_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-customer' )->execute(
			array(
				'customer_id' => 7001,
				'evil_field'  => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful get is recorded.
	 */
	public function test_get_customer_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-get-customer' )->execute( array( 'customer_id' => 7001 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-get-customer', $abilities );
	}

	/**
	 * Audit: denied check_permissions call is recorded.
	 */
	public function test_get_customer_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-get-customer' )->check_permissions( array( 'customer_id' => 7001 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-get-customer', $abilities );
	}

	/**
	 * Readonly annotation is set; destructive is false.
	 */
	public function test_get_customer_is_read_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-get-customer' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'] ?? false );
		$this->assertFalse( $annotations['destructive'] ?? true );
	}

	// =========================================================================
	// aafm/wc-create-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_create_customer_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-customer' )->check_permissions(
				array( 'email' => 'new@example.com' )
			)
		);
	}

	/**
	 * Create returns the full rich shape with the new customer id.
	 */
	public function test_create_customer_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-customer' )->execute(
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
	 * Create with billing fields writes them through to the returned shape.
	 */
	public function test_create_customer_with_billing_fields(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-customer' )->execute(
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
		$res = wp_get_ability( 'aafm/wc-create-customer' )->execute(
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
		$res = wp_get_ability( 'aafm/wc-create-customer' )->execute(
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
	 * Top-level unknown field is rejected by the closed root schema.
	 */
	public function test_create_customer_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-customer' )->execute(
			array(
				'email'      => 'x@example.com',
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful create is recorded.
	 */
	public function test_create_customer_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-create-customer' )->execute(
			array( 'email' => 'audit@example.com' )
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-customer', $abilities );
	}

	/**
	 * Audit: denied permission check is recorded.
	 */
	public function test_create_customer_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-customer' )->check_permissions(
			array( 'email' => 'denied@example.com' )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-customer', $abilities );
	}

	/**
	 * Write annotation is set; destructive is false.
	 */
	public function test_create_customer_is_write_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-create-customer' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'] ?? true );
		$this->assertFalse( $annotations['destructive'] ?? true );
	}

	/**
	 * Store failure (create_should_fail) surfaces as WP_Error, not a false-success.
	 */
	public function test_create_customer_store_failure_returns_error(): void {
		WcCustomerStubStore::$create_should_fail = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-customer' )->execute(
			array( 'email' => 'fail@example.com' )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Store failure must not lie success.' );
		WcCustomerStubStore::$create_should_fail = false;
	}

	// =========================================================================
	// aafm/wc-update-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_update_customer_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-customer' )->check_permissions(
				array( 'customer_id' => 7001 )
			)
		);
	}

	/**
	 * Update with only customer_id (no other fields) is a no-op success.
	 */
	public function test_update_customer_empty_patch_is_noop(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-customer' )->execute(
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
		$res = wp_get_ability( 'aafm/wc-update-customer' )->execute(
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
		$res = wp_get_ability( 'aafm/wc-update-customer' )->execute(
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
		$res = wp_get_ability( 'aafm/wc-update-customer' )->execute(
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
		$res = wp_get_ability( 'aafm/wc-update-customer' )->execute(
			array(
				'customer_id' => 99999,
				'first_name'  => 'Ghost',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Closed schema rejects an unknown top-level field.
	 */
	public function test_update_customer_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'evil_field'  => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful update is recorded.
	 */
	public function test_update_customer_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-update-customer' )->execute(
			array(
				'customer_id' => 7001,
				'first_name'  => 'Audited',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-customer', $abilities );
	}

	/**
	 * Audit: denied permission check is recorded.
	 */
	public function test_update_customer_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-update-customer' )->check_permissions(
			array( 'customer_id' => 7001 )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-update-customer', $abilities );
	}

	/**
	 * Write annotation is set; destructive is false.
	 */
	public function test_update_customer_is_write_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-update-customer' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'] ?? true );
		$this->assertFalse( $annotations['destructive'] ?? true );
	}

	/**
	 * Create→get round-trip: a newly created customer can be retrieved by its returned id.
	 */
	public function test_create_then_get_round_trip(): void {
		$this->acting_as( 'administrator' );
		$created = wp_get_ability( 'aafm/wc-create-customer' )->execute(
			array(
				'email'      => 'roundtrip@example.com',
				'first_name' => 'Round',
				'last_name'  => 'Trip',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $created );
		$new_id = $created['id'];

		$fetched = wp_get_ability( 'aafm/wc-get-customer' )->execute(
			array( 'customer_id' => $new_id )
		);
		$this->assertNotInstanceOf( WP_Error::class, $fetched );
		$this->assertSame( $new_id, $fetched['id'] );
		$this->assertSame( 'Round', $fetched['first_name'] );
	}

	// =========================================================================
	// aafm/wc-delete-customer
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_delete_customer_requires_manage_woocommerce(): void {
		// Create a real WP user so the permission check does not fail on missing user first.
		$victim_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-customer' )->check_permissions(
				array(
					'customer_id' => $victim_id,
					'reassign_to' => $reassign_id,
				)
			)
		);
	}

	/**
	 * Guard 1: non-existent customer id returns WP_Error.
	 */
	public function test_delete_customer_unknown_victim_returns_error(): void {
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-customer' )->execute(
			array(
				'customer_id' => 99999,
				'reassign_to' => $reassign_id,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Guard 2: the current user cannot delete their own account.
	 */
	public function test_delete_customer_cannot_delete_self(): void {
		$admin_id    = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $admin_id );
		$res = wp_get_ability( 'aafm/wc-delete-customer' )->execute(
			array(
				'customer_id' => $admin_id,
				'reassign_to' => $reassign_id,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Guard 3a: reassign_to missing / zero returns WP_Error.
	 */
	public function test_delete_customer_missing_reassign_returns_error(): void {
		$victim_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->acting_as( 'administrator' );
		// The schema requires reassign_to, but exercise the executor guard via a direct call
		// by bypassing the ability gateway and calling the executor directly.
		$res = aafm_exec_wc_delete_customer(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => 0,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Guard 3b: reassign_to same as victim returns WP_Error.
	 */
	public function test_delete_customer_reassign_equals_victim_returns_error(): void {
		$victim_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_delete_customer(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => $victim_id,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Guard 3c: reassign_to refers to a non-existent user.
	 */
	public function test_delete_customer_nonexistent_reassign_returns_error(): void {
		$victim_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->acting_as( 'administrator' );
		$res = aafm_exec_wc_delete_customer(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => 99999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Guard 4: cannot remove the last administrator.
	 */
	public function test_delete_customer_last_admin_is_protected(): void {
		// The test suite always has the main admin user. If there is only one admin, the guard fires.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		if ( count( $admins ) !== 1 ) {
			$this->markTestSkipped( 'Last-admin guard requires exactly one administrator in the test DB.' );
		}

		$victim_id   = (int) $admins[0];
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Become a different admin so guard 2 (self-delete) doesn't fire first.
		$other_admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );

		// Now we have two admins; delete $other_admin so $victim_id becomes the last one.
		wp_delete_user( $other_admin, $victim_id );

		// Re-act as main admin (victim) would fail self-check; use the reassign user instead.
		// Set current user to reassign_id (subscriber with manage_woocommerce granted by stub).
		$subscriber = $reassign_id;
		wp_set_current_user( $subscriber );
		$res = aafm_exec_wc_delete_customer(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => $subscriber,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Happy path: valid victim + reassign deletes the user and returns deleted:true.
	 */
	public function test_delete_customer_valid_delete_succeeds(): void {
		$victim_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-customer' )->execute(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => $reassign_id,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertTrue( $res['deleted'] );

		// Verify the user no longer exists in WordPress.
		$this->assertFalse( get_userdata( $victim_id ) );
	}

	/**
	 * Audit: successful delete is recorded.
	 */
	public function test_delete_customer_success_is_audited(): void {
		$victim_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-delete-customer' )->execute(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => $reassign_id,
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-customer', $abilities );
	}

	/**
	 * Audit: denied permission check is recorded.
	 */
	public function test_delete_customer_denied_is_audited(): void {
		$victim_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-customer' )->check_permissions(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => $reassign_id,
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-customer', $abilities );
	}

	/**
	 * Destructive annotation is set; readonly is false.
	 */
	public function test_delete_customer_is_destructive_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-delete-customer' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'] ?? true );
		$this->assertTrue( $annotations['destructive'] ?? false );
	}

	/**
	 * Closed schema rejects an unknown field.
	 */
	public function test_delete_customer_closed_schema_rejects_unknown_field(): void {
		$victim_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$reassign_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-customer' )->execute(
			array(
				'customer_id' => $victim_id,
				'reassign_to' => $reassign_id,
				'evil_field'  => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}
}
