<?php
/**
 * Crash-safety tests: an ability must never let an exception escape.
 *
 * Each test drives a real throwing path and asserts a WP_Error comes back. Asserting that valid
 * input succeeds proves nothing about the crash - the test has to actually trigger the throw.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcStubStore;
use WP_Error;

final class AbilityCrashSafetyTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		// A single variable parent (500); the duplicate-SKU holder is seeded per-test below.
		$this->stub_woocommerce(
			array(
				array(
					'id'     => 500,
					'name'   => 'Variable Parent',
					'type'   => 'variable',
					'status' => 'publish',
				),
			)
		);
		// Coupon abilities (Task 4) live on their own stub surface, registered after
		// stub_woocommerce() the same way WooCouponsTest does. wc-create-coupon/wc-update-coupon
		// are on the high-risk floor (includes/audit/high-risk.php), so registration silently
		// drops them from the enabled set unless unlocked - without this the ability-level tests
		// below would call wp_get_ability() on a name that never registered and fail with a
		// fixture error ("call to a member function execute() on null"), not the crash-safety
		// signal they exist to prove.
		$this->stub_wc_coupons();
		$this->unlock_high_risk_abilities();
		aafm_registry_cache_should_flush( true );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-create-product-variation',
				'aafm/wc-create-coupon',
				'aafm/wc-update-coupon',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Seed a product holding the SKU the tests below try to reuse.
	 */
	private function seed_taken_sku(): void {
		// Stub-store SKU uniqueness is scanned across the whole store (mirroring WooCommerce's
		// wc_product_has_unique_sku(), which checks every product/variation, not just siblings) - seed
		// an unrelated product holding the SKU a variation will try to claim.
		WcStubStore::seed(
			900,
			array(
				'id'   => 900,
				'name' => 'Holds the SKU',
				'sku'  => 'AAFM-DUPE-SKU',
				'type' => 'simple',
			)
		);
	}

	/**
	 * The true Red Gate: calls aafm_wc_apply_variation_input() directly, bypassing WP_Ability
	 * entirely, so nothing but this function's own error handling can turn the exception into a
	 * WP_Error.
	 *
	 * WC_Product_Variation::set_sku() throws WC_Data_Exception on a duplicate SKU - verified
	 * against real WooCommerce by WooCommerceContractTest::
	 * test_create_product_with_a_duplicate_sku_is_a_clean_error() (tests/contract/WooCommerceContractTest.php),
	 * which pins the identical contract for the sibling WC_Product setter. Unguarded, that
	 * exception is fatal: WP_Ability::execute() on this plugin's WP 6.9 floor (the live DDEV site
	 * runs 6.9.4; see wp/wp-includes/abilities-api/class-wp-ability.php there) carries no try/catch
	 * of its own, so nothing stops the throw before it reaches the caller. Note this project's
	 * PHPUnit environment happens to boot against a newer core (7.0.2, ABSPATH in
	 * tests/../wp-tests-config.php) whose WP_Ability::invoke_callback() DOES wrap every callback in
	 * try ( Throwable $e ), a hardening change added after 6.9. That masks this exact bug at the
	 * ability-execute layer in THIS test run, which is why this test calls the vulnerable function
	 * directly instead of going through wp_get_ability()->execute() - see the companion
	 * ability-level test below for how that masking is worked around there.
	 */
	public function test_apply_variation_input_returns_an_error_instead_of_throwing_on_a_duplicate_sku(): void {
		$this->seed_taken_sku();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( 500 );

		$result = aafm_wc_apply_variation_input( $variation, array( 'sku' => 'AAFM-DUPE-SKU' ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'aafm_wc_apply_variation_input() must catch WC_Data_Exception from set_sku() and return a WP_Error, not let it escape uncaught - this call goes straight to the function under test, with no WP_Ability wrapper in between to mask a missing catch.'
		);
		$this->assertSame( 'aafm_wc_duplicate_sku', $result->get_error_code() );
		$this->assertStringContainsString( 'AAFM-DUPE-SKU', $result->get_error_message() );
	}

	/**
	 * The end-to-end path through the real ability. On this project's PHPUnit core (7.0.2) the
	 * outer WP_Ability::execute() catches every Throwable itself, so an assertInstanceOf(WP_Error)
	 * here would pass whether or not aafm_wc_apply_variation_input() has its own guard - it would be
	 * proving core's hardening works, not proving this fix (the exact "assert something adjacent to
	 * the claim" trap SHARED-CONTEXT warns about). The differentiator is the error CODE: core's
	 * catch-all reports the generic 'ability_callback_exception' (see
	 * AbilityEdgeCasesTest::test_update_post_execute_survives_post_deleted_mid_update for the same
	 * house pattern on a different ability); this fix's own catch reports 'aafm_wc_duplicate_sku'.
	 * Only the latter proves this function is the one that caught it.
	 */
	public function test_create_variation_with_a_duplicate_sku_returns_the_plugins_own_error_not_the_cores(): void {
		$this->seed_taken_sku();

		$this->acting_as( 'administrator' );

		$result = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id' => 500,
				'sku'        => 'AAFM-DUPE-SKU',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_duplicate_sku',
			$result->get_error_code(),
			'A duplicate SKU must surface as this plugin\'s own aafm_wc_duplicate_sku error. A generic ability_callback_exception here would mean the WC_Data_Exception escaped aafm_wc_apply_variation_input() and was only caught by WP_Ability\'s own Throwable guard - which does not exist on this plugin\'s WP 6.9 floor.'
		);
	}

	// -------------------------------------------------------------------------
	// Task 4: WC_Coupon::set_amount() - negative amount, and percent over 100
	// -------------------------------------------------------------------------

	/**
	 * The true Red Gate for the negative-amount branch: calls aafm_wc_apply_coupon_input()
	 * directly, bypassing WP_Ability entirely, per the same reasoning documented on the SKU test
	 * above (this test core's WP_Ability::invoke_callback() already catches every Throwable, which
	 * would mask a missing guard at the ability layer).
	 */
	public function test_apply_coupon_input_returns_an_error_instead_of_throwing_on_a_negative_amount(): void {
		$coupon = new \WC_Coupon();

		$result = aafm_wc_apply_coupon_input( $coupon, array( 'amount' => '-5' ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'aafm_wc_apply_coupon_input() must catch WC_Data_Exception from set_amount() on a negative amount and return a WP_Error, not let it escape uncaught.'
		);
		$this->assertSame( 'aafm_wc_invalid_coupon_amount', $result->get_error_code() );
	}

	/**
	 * The true Red Gate for the percent-over-100 branch. WC_Coupon::set_amount() only rejects an
	 * amount over 100 once the coupon's own discount_type is already 'percent' (it reads
	 * get_discount_type(), not the incoming input), so the coupon under test is constructed with
	 * that type already set, mirroring the "update an existing percent coupon" case the brief's
	 * ordering subtlety describes - set_amount() runs before set_discount_type() in
	 * aafm_wc_apply_coupon_input(), so a same-request create of amount+discount_type=percent can
	 * never reach this branch; only a second call against an already-percent coupon can.
	 */
	public function test_apply_coupon_input_returns_an_error_instead_of_throwing_on_a_percent_amount_over_one_hundred(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_discount_type( 'percent' );

		$result = aafm_wc_apply_coupon_input( $coupon, array( 'amount' => '150' ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'aafm_wc_apply_coupon_input() must catch WC_Data_Exception from set_amount() on a percent coupon over 100 and return a WP_Error, not let it escape uncaught.'
		);
		$this->assertSame( 'aafm_wc_invalid_coupon_amount', $result->get_error_code() );
	}

	/**
	 * End-to-end through the real ability: a negative amount on create. As with the SKU test above,
	 * the differentiator that proves this plugin's own catch ran (rather than core's Throwable
	 * fallback) is the specific error code, not just instanceof WP_Error.
	 */
	public function test_create_coupon_with_a_negative_amount_returns_the_plugins_own_error_not_the_cores(): void {
		$this->acting_as( 'administrator' );

		$result = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'          => 'aafm-negative',
				'amount'        => '-5',
				'discount_type' => 'fixed_cart',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_invalid_coupon_amount',
			$result->get_error_code(),
			'A negative coupon amount must surface as this plugin\'s own aafm_wc_invalid_coupon_amount error, not core\'s generic ability_callback_exception fallback.'
		);
	}

	/**
	 * End-to-end through the real ability: an existing percent coupon updated past 100. Exercises
	 * the ordering subtlety directly - the coupon must already carry discount_type=percent before
	 * the update, because set_amount() runs before set_discount_type() inside
	 * aafm_wc_apply_coupon_input() and so cannot see a same-request discount_type change.
	 */
	public function test_update_percent_coupon_over_one_hundred_returns_the_plugins_own_error_not_the_cores(): void {
		$this->acting_as( 'administrator' );

		$created = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'          => 'aafm-percent',
				'amount'        => '10',
				'discount_type' => 'percent',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $created );

		$result = wp_get_ability( 'aafm/wc-update-coupon' )->execute(
			array(
				'coupon_id' => $created['id'],
				'amount'    => '150',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_invalid_coupon_amount',
			$result->get_error_code(),
			'A percent coupon pushed over 100 must surface as this plugin\'s own aafm_wc_invalid_coupon_amount error, not core\'s generic ability_callback_exception fallback.'
		);
	}
}
