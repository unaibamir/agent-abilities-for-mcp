<?php
/**
 * WooCommerce coupon abilities: wc-list-coupons, wc-get-coupon, wc-create-coupon,
 * wc-update-coupon, wc-delete-coupon.
 *
 * WooCommerce is not installed in the DDEV test environment — every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcCouponStubStore. The seed_wc_coupons()
 * helper resets and seeds the store per test so each test starts with a clean, known state.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcCouponStubStore;
use WP_Error;

final class WooCouponsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->stub_wc_coupons();
		$this->seed_wc_coupons();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_coupons();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcCouponStubStore::reset();
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
	 * Enable and register the full WooCommerce coupon ability set.
	 */
	private function register_wc_coupons(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-coupons',
				'aafm/wc-get-coupon',
				'aafm/wc-create-coupon',
				'aafm/wc-update-coupon',
				'aafm/wc-delete-coupon',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// aafm/wc-list-coupons
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_coupons_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-coupons' )->check_permissions( array() )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-coupons' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'coupons', $res );
		$this->assertArrayHasKey( 'total', $res );
	}

	/**
	 * List rows carry the lean shape fields only (no full coupon config detail).
	 */
	public function test_list_coupons_lean_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-coupons' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['coupons'] );

		$row = $res['coupons'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'code', $row );
		$this->assertArrayHasKey( 'amount', $row );
		$this->assertArrayHasKey( 'discount_type', $row );
		$this->assertArrayHasKey( 'date_expires', $row );
		$this->assertArrayHasKey( 'usage_count', $row );

		// Full config detail must NOT appear in list rows.
		$this->assertArrayNotHasKey( 'email_restrictions', $row );
		$this->assertArrayNotHasKey( 'product_ids', $row );
		$this->assertArrayNotHasKey( 'description', $row );
	}

	/**
	 * Total reflects the full store count regardless of page size.
	 */
	public function test_list_coupons_grand_total(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-coupons' )->execute( array( 'per_page' => 1 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		// Two coupons seeded; total must reflect all, not just the page.
		$this->assertSame( 2, $res['total'] );
		$this->assertCount( 1, $res['coupons'] );
	}

	/**
	 * Closed schema rejects an unknown field.
	 */
	public function test_list_coupons_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-coupons' )->execute( array( 'evil_field' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown field.' );
	}

	/**
	 * Audit: a successful list call is recorded.
	 */
	public function test_list_coupons_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-list-coupons' )->execute( array() );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-list-coupons', $abilities );
	}

	/**
	 * Audit: a denied check_permissions call is recorded.
	 */
	public function test_list_coupons_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-list-coupons' )->check_permissions( array() );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-list-coupons', $abilities );
	}

	/**
	 * Readonly annotation is set; destructive is false.
	 */
	public function test_list_coupons_is_read_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-list-coupons' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'] ?? false, 'wc-list-coupons must be annotated readonly.' );
		$this->assertFalse( $annotations['destructive'] ?? true, 'wc-list-coupons must be annotated non-destructive.' );
	}

	/**
	 * Empty store returns an empty coupons array (not an object).
	 */
	public function test_list_coupons_empty_store_returns_empty_array(): void {
		WcCouponStubStore::reset();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-coupons' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertIsArray( $res['coupons'], 'Empty coupons list must be an array, not an object.' );
		$this->assertCount( 0, $res['coupons'] );
		$this->assertSame( 0, $res['total'] );
	}

	/**
	 * Host-inactive: coupon abilities must be absent from the registry when WooCommerce is off.
	 */
	public function test_list_coupons_host_inactive_absent_from_registry(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-coupons', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-coupon', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-coupon', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-coupon', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-delete-coupon', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// aafm/wc-get-coupon
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_get_coupon_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-coupon' )->check_permissions( array( 'coupon_id' => 5001 ) )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Full shape includes all expected fields.
	 */
	public function test_get_coupon_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5001 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$this->assertSame( 5001, $res['id'] );
		$this->assertSame( 'save10', $res['code'] );
		$this->assertSame( '10.00', $res['amount'] );
		$this->assertSame( 'fixed_cart', $res['discount_type'] );
		$this->assertArrayHasKey( 'description', $res );
		$this->assertArrayHasKey( 'date_expires', $res );
		$this->assertArrayHasKey( 'usage_count', $res );
		$this->assertArrayHasKey( 'usage_limit', $res );
		$this->assertArrayHasKey( 'usage_limit_per_user', $res );
		$this->assertArrayHasKey( 'minimum_amount', $res );
		$this->assertArrayHasKey( 'maximum_amount', $res );
		$this->assertArrayHasKey( 'individual_use', $res );
		$this->assertArrayHasKey( 'exclude_sale_items', $res );
		$this->assertArrayHasKey( 'product_ids', $res );
		$this->assertArrayHasKey( 'excluded_product_ids', $res );
		$this->assertArrayHasKey( 'email_restrictions', $res );
	}

	/**
	 * Email_restrictions surfaces as a config field (not PII), present in full shape.
	 */
	public function test_get_coupon_exposes_email_restrictions(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5002 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertIsArray( $res['email_restrictions'] );
		$this->assertContains( 'vip@example.com', $res['email_restrictions'] );
	}

	/**
	 * Unknown coupon id returns a WP_Error with the canonical aafm_error code.
	 */
	public function test_get_coupon_unknown_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
	}

	/**
	 * Closed schema rejects an unknown field.
	 */
	public function test_get_coupon_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-coupon' )->execute(
			array(
				'coupon_id'  => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful get is recorded.
	 */
	public function test_get_coupon_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5001 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-get-coupon', $abilities );
	}

	/**
	 * Audit: denied check_permissions call is recorded.
	 */
	public function test_get_coupon_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-get-coupon' )->check_permissions( array( 'coupon_id' => 5001 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-get-coupon', $abilities );
	}

	/**
	 * Readonly annotation is set; destructive is false.
	 */
	public function test_get_coupon_is_read_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-get-coupon' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'] ?? false );
		$this->assertFalse( $annotations['destructive'] ?? true );
	}

	// =========================================================================
	// aafm/wc-create-coupon
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_create_coupon_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-coupon' )->check_permissions(
				array( 'code' => 'NEW10' )
			)
		);
	}

	/**
	 * Create returns the full rich shape with the new coupon id.
	 */
	public function test_create_coupon_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'          => 'NEWCOUPON',
				'amount'        => '15.00',
				'discount_type' => 'percent',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( 'newcoupon', $res['code'] ); // WC lowercases coupon codes.
		$this->assertSame( '15.00', $res['amount'] );
		$this->assertSame( 'percent', $res['discount_type'] );
	}

	/**
	 * Create with optional config fields stores them correctly.
	 */
	public function test_create_coupon_with_optional_fields(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'               => 'OPTVIP',
				'amount'             => '5.00',
				'discount_type'      => 'fixed_cart',
				'usage_limit'        => 50,
				'individual_use'     => true,
				'email_restrictions' => array( 'vip@example.com' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 50, $res['usage_limit'] );
		$this->assertTrue( $res['individual_use'] );
		$this->assertContains( 'vip@example.com', $res['email_restrictions'] );
	}

	/**
	 * Closed schema rejects an unknown top-level field.
	 */
	public function test_create_coupon_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'       => 'EVIL',
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful create is recorded.
	 */
	public function test_create_coupon_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array( 'code' => 'AUDITME' )
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-coupon', $abilities );
	}

	/**
	 * Audit: denied permission check is recorded.
	 */
	public function test_create_coupon_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-coupon' )->check_permissions(
			array( 'code' => 'DENIED' )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-coupon', $abilities );
	}

	/**
	 * Write annotation is set; destructive is false.
	 */
	public function test_create_coupon_is_write_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-create-coupon' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'] ?? true );
		$this->assertFalse( $annotations['destructive'] ?? true );
	}

	/**
	 * Store failure surfaces as WP_Error, not a false success.
	 */
	public function test_create_coupon_store_failure_returns_error(): void {
		WcCouponStubStore::$force_save_failure = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array( 'code' => 'WILLFAIL' )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Store failure must not lie success.' );
		WcCouponStubStore::$force_save_failure = false;
	}

	// =========================================================================
	// aafm/wc-update-coupon
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_update_coupon_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-coupon' )->check_permissions(
				array( 'coupon_id' => 5001 )
			)
		);
	}

	/**
	 * Update with only coupon_id (no other fields) is a no-op success.
	 */
	public function test_update_coupon_empty_patch_is_noop(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array( 'coupon_id' => 5001 )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 5001, $res['id'] );
		// Existing data must be unchanged.
		$this->assertSame( 'save10', $res['code'] );
	}

	/**
	 * Update changes only the supplied fields; unsupplied fields are preserved.
	 */
	public function test_update_coupon_field_isolation(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id' => 5001,
				'amount'    => '12.00',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '12.00', $res['amount'] );
		// discount_type was not supplied; it must survive unchanged.
		$this->assertSame( 'fixed_cart', $res['discount_type'] );
	}

	/**
	 * Unknown coupon id returns WP_Error.
	 */
	public function test_update_coupon_unknown_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id' => 99999,
				'amount'    => '5.00',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Closed schema rejects an unknown top-level field.
	 */
	public function test_update_coupon_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id'  => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful update is recorded.
	 */
	public function test_update_coupon_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id' => 5001,
				'amount'    => '11.00',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-coupon', $abilities );
	}

	/**
	 * Audit: denied permission check is recorded.
	 */
	public function test_update_coupon_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-update-coupon' )->check_permissions(
			array( 'coupon_id' => 5001 )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-update-coupon', $abilities );
	}

	/**
	 * Write annotation is set; destructive is false.
	 */
	public function test_update_coupon_is_write_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-update-coupon' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'] ?? true );
		$this->assertFalse( $annotations['destructive'] ?? true );
	}

	/**
	 * Create→update→get round-trip: a created coupon can be updated and the change is visible.
	 */
	public function test_create_update_get_round_trip(): void {
		$this->acting_as( 'administrator' );

		$created = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'   => 'ROUNDTRIP',
				'amount' => '5.00',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $created );
		$new_id = $created['id'];

		$updated = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id' => $new_id,
				'amount'    => '99.00',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $updated );
		$this->assertSame( '99.00', $updated['amount'] );

		$fetched = wp_get_ability( 'aafm/wc-get-coupon' )->execute(
			array( 'coupon_id' => $new_id )
		);
		$this->assertNotInstanceOf( WP_Error::class, $fetched );
		$this->assertSame( '99.00', $fetched['amount'] );
	}

	// =========================================================================
	// aafm/wc-delete-coupon
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_delete_coupon_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-coupon' )->check_permissions(
				array( 'coupon_id' => 5001 )
			)
		);
	}

	/**
	 * Happy path: valid coupon id permanently removes the coupon and returns deleted:true.
	 */
	public function test_delete_coupon_valid_delete_succeeds(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-coupon' )->execute(
			array( 'coupon_id' => 5001 )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertTrue( $res['deleted'] );

		// Coupon must be gone from the store.
		$this->assertFalse( WcCouponStubStore::exists( 5001 ) );
	}

	/**
	 * Unknown coupon id returns WP_Error.
	 */
	public function test_delete_coupon_unknown_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-coupon' )->execute(
			array( 'coupon_id' => 99999 )
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Store delete failure returns WP_Error — never lies success.
	 */
	public function test_delete_coupon_store_failure_returns_error(): void {
		WcCouponStubStore::$force_delete_failure = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-coupon' )->execute(
			array( 'coupon_id' => 5001 )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Delete failure must never lie success.' );
		WcCouponStubStore::$force_delete_failure = false;
	}

	/**
	 * Closed schema rejects an unknown field.
	 */
	public function test_delete_coupon_closed_schema_rejects_unknown_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-coupon' )->execute(
			array(
				'coupon_id'  => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Audit: successful delete is recorded.
	 */
	public function test_delete_coupon_success_is_audited(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-delete-coupon' )->execute(
			array( 'coupon_id' => 5001 )
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-coupon', $abilities );
	}

	/**
	 * Audit: denied permission check is recorded.
	 */
	public function test_delete_coupon_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-coupon' )->check_permissions(
			array( 'coupon_id' => 5001 )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-coupon', $abilities );
	}

	/**
	 * Destructive annotation is set on delete; write annotation is false.
	 */
	public function test_delete_coupon_is_destructive_annotated(): void {
		$annotations = wp_get_ability( 'aafm/wc-delete-coupon' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'] ?? true );
		$this->assertTrue( $annotations['destructive'] ?? false, 'wc-delete-coupon must be annotated destructive.' );
	}
}
