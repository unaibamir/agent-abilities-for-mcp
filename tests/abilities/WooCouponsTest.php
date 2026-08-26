<?php
/**
 * WooCommerce coupon abilities: wc-list-coupons, wc-get-coupon, wc-create-coupon,
 * wc-update-coupon.
 *
 * WooCommerce is not installed in the DDEV test environment - every WC host function and class is
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
		$this->unlock_high_risk_abilities();
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
	 * Doc 214, finding 6: the WC_Coupon stub's getters used to apply no WooCommerce filter at
	 * all, unlike real WC_Coupon (get_prop()-backed, filtered 'woocommerce_coupon_get_{prop}').
	 * A site-installed plugin hooking that real filter would have been silently invisible to
	 * this suite. Hook two of the getters this ability's own shaping reads and confirm the
	 * filtered value, not the stored one, reaches the wire.
	 */
	public function test_get_coupon_applies_real_woocommerce_coupon_filters(): void {
		$this->acting_as( 'administrator' );
		add_filter( 'woocommerce_coupon_get_code', static fn() => 'filtered-code' );
		add_filter( 'woocommerce_coupon_get_amount', static fn() => '999.00' );

		$res = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5001 ) );

		remove_all_filters( 'woocommerce_coupon_get_code' );
		remove_all_filters( 'woocommerce_coupon_get_amount' );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'filtered-code', $res['code'], 'A real WooCommerce coupon filter must reach this shape.' );
		$this->assertSame( '999.00', $res['amount'] );
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
	 * B51: a negative usage_limit must be rejected, not sign-flipped by absint into a live limit.
	 *
	 * The absint(-5) call returns 5, so a negative usage_limit was silently persisted as its positive twin and the
	 * write reported success. The integer schema now carries minimum:0, so a negative is refused at
	 * input validation.
	 */
	public function test_create_coupon_rejects_a_negative_usage_limit(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'        => 'NEGLIMIT',
				'amount'      => '10.00',
				'usage_limit' => -5,
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'A negative usage_limit must be refused, not stored as its absolute value.'
		);
	}

	/**
	 * B53: a non-numeric amount used to be silently swallowed (WC casts it toward 0 on the way to
	 * storage) while the tax sibling validates its rate. It must be refused with an actionable
	 * error before any write.
	 */
	public function test_create_coupon_rejects_a_non_numeric_amount(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'   => 'BADAMOUNT',
				'amount' => 'ten-percent',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_invalid_coupon_amount', $res->get_error_code() );
		$this->assertStringContainsString( 'ten-percent', $res->get_error_message(), 'the error must name the rejected value.' );
	}

	/**
	 * B53: an unparseable date_expires was silently swallowed to null (WC_Data::set_date_prop
	 * catches its own parse exception), so the caller believed an expiry was set when the coupon
	 * would never expire. It must be refused, and on update the stored expiry must survive.
	 */
	public function test_update_coupon_rejects_an_unparseable_expiry_and_keeps_the_stored_one(): void {
		$this->acting_as( 'administrator' );

		$before = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5001 ) );
		$this->assertSame( '2025-12-31T23:59:59', $before['date_expires'], 'guard: the fixture stores an expiry.' );

		$res = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id'    => 5001,
				'date_expires' => 'next-ish tuesday-ish',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_invalid_coupon_expiry', $res->get_error_code() );

		$after = wp_get_ability( 'aafm/wc-get-coupon' )->execute( array( 'coupon_id' => 5001 ) );
		$this->assertSame( '2025-12-31T23:59:59', $after['date_expires'], 'a refused expiry must leave the stored one untouched.' );
	}

	/**
	 * B53 control: null and empty string stay valid ways to clear the expiry.
	 */
	public function test_update_coupon_null_expiry_still_clears(): void {
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id'    => 5001,
				'date_expires' => null,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNull( $res['date_expires'] );
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
	 * Store failure on update surfaces as WP_Error, not a false success.
	 */
	public function test_update_coupon_store_failure_returns_error(): void {
		$this->acting_as( 'administrator' );
		// Seed a real coupon so we get past the "unknown id" guard.
		$created = wp_get_ability( 'aafm/wc-create-coupon' )->execute( array( 'code' => 'UPDATEFAIL' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $created );
		$new_id = $created['id'];

		WcCouponStubStore::$force_save_failure = true;
		$res                                   = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id' => $new_id,
				'amount'    => '9.99',
			)
		);
		WcCouponStubStore::$force_save_failure = false;

		$this->assertInstanceOf( \WP_Error::class, $res, 'Save failure on update must not lie success.' );
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
	 * Cases: each coupon ability and the minimal valid args its original test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function provide_closed_schema_cases(): array {
		return array(
			'list-coupons'  => array( 'aafm/wc-list-coupons', array() ),
			'get-coupon'    => array( 'aafm/wc-get-coupon', array( 'coupon_id' => 5001 ) ),
			'create-coupon' => array( 'aafm/wc-create-coupon', array( 'code' => 'EVIL' ) ),
			'update-coupon' => array( 'aafm/wc-update-coupon', array( 'coupon_id' => 5001 ) ),
		);
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

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each coupon ability and the args its original audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function provide_success_audit_cases(): array {
		return array(
			'list-coupons'  => array( 'aafm/wc-list-coupons', array() ),
			'get-coupon'    => array( 'aafm/wc-get-coupon', array( 'coupon_id' => 5001 ) ),
			'create-coupon' => array( 'aafm/wc-create-coupon', array( 'code' => 'AUDITME' ) ),
			'update-coupon' => array(
				'aafm/wc-update-coupon',
				array(
					'coupon_id' => 5001,
					'amount'    => '11.00',
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

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each coupon ability and the args its original denied audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function provide_denied_audit_cases(): array {
		return array(
			'list-coupons'  => array( 'aafm/wc-list-coupons', array(), 'editor' ),
			'get-coupon'    => array( 'aafm/wc-get-coupon', array( 'coupon_id' => 5001 ), 'editor' ),
			'create-coupon' => array( 'aafm/wc-create-coupon', array( 'code' => 'DENIED' ), 'editor' ),
			'update-coupon' => array( 'aafm/wc-update-coupon', array( 'coupon_id' => 5001 ), 'editor' ),
		);
	}

	// =========================================================================
	// Post-conditions on the resulting coupon
	//
	// Every per-field guard only fires when its own key is present in $input, and each of these
	// two invariants is a relationship between two INDEPENDENTLY OPTIONAL fields. So no per-field
	// guard can ever see both halves, and both invariants stayed reachable through a different
	// door until a check on the final object closed them.
	// =========================================================================

	/**
	 * The open route: flipping a coupon that already stores 150 to percent enters no guard at all,
	 * because `amount` is not in the input. WooCommerce validates this pair from one side only -
	 * set_amount() reads the CURRENT discount_type, while set_discount_type() never re-reads
	 * amount - and WC_Coupon::save() re-validates nothing, so a 150% coupon persisted and the call
	 * returned success.
	 */
	public function test_flipping_an_over_100_fixed_coupon_to_percent_is_rejected(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '150' );

		$result = aafm_wc_apply_coupon_input( $coupon, array( 'discount_type' => 'percent' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_wc_invalid_coupon_amount', $result->get_error_code() );
	}

	/**
	 * The same shape on the min/max pair: set_minimum_amount() is a bare set_prop, so raising the
	 * minimum past a stored maximum entered no guard either and persisted an unusable coupon.
	 */
	public function test_raising_the_minimum_above_the_stored_maximum_is_rejected(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_maximum_amount( '50' );

		$result = aafm_wc_apply_coupon_input( $coupon, array( 'minimum_amount' => '500' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_wc_invalid_coupon_maximum', $result->get_error_code() );
	}

	/**
	 * The post-condition must not cost an operator a legitimate edit. Changing the type and the
	 * amount together in one call lands on a valid coupon, so it passes.
	 */
	public function test_a_legitimate_type_and_amount_change_in_one_call_still_passes(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '150' );

		$this->assertNull(
			aafm_wc_apply_coupon_input(
				$coupon,
				array(
					'discount_type' => 'percent',
					'amount'        => '50',
				)
			)
		);
	}

	/**
	 * The boundary itself, which nothing asserted: 100 percent off is a real and common coupon, and
	 * the guard rejects ABOVE 100 rather than AT it. That matches real WooCommerce, whose own
	 * set_amount() (class-wc-coupon.php:628) uses the same comparison.
	 *
	 * Worth a case of its own because the failure direction is bad and invisible. Turning `> 100`
	 * into `>= 100` leaves the whole coupon suite green while rejecting every legitimate 100 percent
	 * coupon with a hard WP_Error, on a money-moving ability.
	 */
	public function test_an_exactly_one_hundred_percent_coupon_is_accepted(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_discount_type( 'percent' );

		$this->assertNull(
			aafm_wc_apply_coupon_input( $coupon, array( 'amount' => '100' ) ),
			'Exactly 100 percent is a legitimate coupon and must not be rejected.'
		);
		// Not a guard on the post-condition, and worth saying so. Measured: disabling the
		// post-condition entirely leaves this assertion green, because 100.001 is refused one layer
		// earlier by WC_Coupon::set_amount() throwing into the per-field catch in
		// aafm_wc_apply_coupon_input(). What it does pin is that a hair over the limit is refused
		// SOMEWHERE, which is the property a caller sees. The post-condition itself is pinned by
		// the two cases that reach it through its own door, a stored amount already over 100 with
		// only discount_type in the input.
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_wc_apply_coupon_input( $coupon, array( 'amount' => '100.001' ) ),
			'A hair over the limit must still be refused, whichever layer does it.'
		);
	}

	/**
	 * The regression this guard could easily ship with. get_maximum_amount() returns '' when
	 * unset, and (float) '' is 0.0, so a naive "minimum greater than maximum" check would reject
	 * every ordinary minimum-only coupon. Real WooCommerce guards exactly this with `(float)
	 * $amount &&` at class-wc-coupon.php:808. Without this test the guard ships broken and the
	 * whole coupon suite stays green.
	 */
	public function test_a_minimum_only_coupon_is_still_accepted(): void {
		$coupon = new \WC_Coupon();

		$this->assertNull(
			aafm_wc_apply_coupon_input(
				$coupon,
				array(
					'code'           => 'MINONLY',
					'discount_type'  => 'percent',
					'amount'         => '10',
					'minimum_amount' => '100',
				)
			)
		);
	}

	/**
	 * An unrelated edit to a coupon another plugin already left invalid must still go through.
	 * Each post-condition runs only when the input could have moved its own pair, so an operator
	 * is never locked out of their own data by a guard with nothing left to prevent.
	 */
	public function test_an_unrelated_edit_to_an_already_invalid_coupon_is_not_blocked(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '150' );
		$coupon->set_discount_type( 'percent' );

		$this->assertNull( aafm_wc_apply_coupon_input( $coupon, array( 'description' => 'Just a note.' ) ) );
	}

	/**
	 * End to end, through the abilities themselves and re-read from the store: no route may leave
	 * a percent coupon over 100 persisted. This is the assertion the re-review flagged as missing,
	 * because the three above all stop at the input applier and never reach save().
	 *
	 * @dataProvider provide_over_100_percent_routes
	 *
	 * @param array<string,mixed>      $create The create call.
	 * @param array<string,mixed>|null $update The follow-up update, or null for create-only.
	 */
	public function test_no_route_persists_a_percent_coupon_over_one_hundred( array $create, ?array $update ): void {
		$this->acting_as( 'administrator' );

		$created = wp_get_ability( 'aafm/wc-create-coupon' )->execute( $create );

		if ( null !== $update && ! $created instanceof WP_Error ) {
			$update['coupon_id'] = $created['id'];
			wp_get_ability( 'aafm/wc-update-coupon' )->execute( $update );
		}

		// Re-read from the store rather than trusting the return value: the defect this closes was
		// a call that returned SUCCESS while persisting the invalid pair. A route that is rejected
		// outright at create time never reaches the store, and that is an equally valid outcome.
		if ( $created instanceof WP_Error ) {
			$this->assertSame( 'aafm_wc_invalid_coupon_amount', $created->get_error_code() );
			return;
		}

		$stored = new \WC_Coupon( (int) $created['id'] );
		$this->assertFalse(
			'percent' === $stored->get_discount_type() && (float) $stored->get_amount() > 100,
			'No route may persist a percentage coupon discounting more than 100 percent.'
		);
	}

	/**
	 * Cases: every way the two fields can be combined across create and update.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>|null}>
	 */
	public function provide_over_100_percent_routes(): array {
		return array(
			'create with both at once'          => array(
				array(
					'code'          => 'ROUTE1',
					'discount_type' => 'percent',
					'amount'        => '150',
				),
				null,
			),
			'create fixed then flip to percent' => array(
				array(
					'code'          => 'ROUTE2',
					'discount_type' => 'fixed_cart',
					'amount'        => '150',
				),
				array( 'discount_type' => 'percent' ),
			),
			'create fixed then raise the amount as percent' => array(
				array(
					'code'          => 'ROUTE3',
					'discount_type' => 'percent',
					'amount'        => '50',
				),
				array( 'amount' => '150' ),
			),
		);
	}
}
