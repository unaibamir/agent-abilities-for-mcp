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

	// -------------------------------------------------------------------------
	// Task 7: WC_Coupon::set_maximum_amount() - maximum below minimum
	// -------------------------------------------------------------------------

	/**
	 * The true Red Gate: calls aafm_wc_apply_coupon_input() directly, bypassing WP_Ability
	 * entirely, per the same reasoning documented on the SKU and amount tests above.
	 *
	 * The minimum_amount field is applied earlier in aafm_wc_apply_coupon_input() than
	 * maximum_amount, so a single call carrying both fields reaches the throw in one pass - no
	 * second call needed, unlike the percent-over-100 amount case above.
	 */
	public function test_apply_coupon_input_returns_an_error_instead_of_throwing_on_a_maximum_below_the_minimum(): void {
		$coupon = new \WC_Coupon();

		$result = aafm_wc_apply_coupon_input(
			$coupon,
			array(
				'minimum_amount' => '100',
				'maximum_amount' => '50',
			)
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'aafm_wc_apply_coupon_input() must catch WC_Data_Exception from set_maximum_amount() when the maximum is below the minimum and return a WP_Error, not let it escape uncaught.'
		);
		$this->assertSame( 'aafm_wc_invalid_coupon_maximum', $result->get_error_code() );
		$this->assertStringContainsString( '50', $result->get_error_message() );
		$this->assertStringContainsString( '100', $result->get_error_message() );
	}

	/**
	 * End-to-end through the real ability: a create carrying minimum_amount=100 and
	 * maximum_amount=50 in the same request. As with the SKU and amount tests above, the
	 * differentiator that proves this plugin's own catch ran (rather than core's Throwable
	 * fallback) is the specific error code, not just instanceof WP_Error.
	 */
	public function test_create_coupon_with_a_maximum_below_the_minimum_returns_the_plugins_own_error_not_the_cores(): void {
		$this->acting_as( 'administrator' );

		$result = wp_get_ability( 'aafm/wc-create-coupon' )->execute(
			array(
				'code'           => 'aafm-max-below-min',
				'amount'         => '5',
				'discount_type'  => 'fixed_cart',
				'minimum_amount' => '100',
				'maximum_amount' => '50',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_invalid_coupon_maximum',
			$result->get_error_code(),
			'A maximum below the minimum must surface as this plugin\'s own aafm_wc_invalid_coupon_maximum error, not core\'s generic ability_callback_exception fallback.'
		);
	}

	// -------------------------------------------------------------------------
	// Task 6: the choke-point floor. Tasks 3 and 4 guard two known WooCommerce
	// setters by name; this proves the wrapper in register.php catches ANY
	// throwing execute_callback, native or bridged, known vendor or not.
	// -------------------------------------------------------------------------

	/**
	 * Registers a fixture ability whose execute_callback always throws, through the same
	 * decorated wrapper every real ability goes through (aafm_register_ability_with_log()),
	 * inside a simulated wp_abilities_api_init action - core's wp_register_ability() refuses to
	 * run outside one (see TestCase::in_action()). Registering directly with wp_register_ability()
	 * instead would bypass the decorated closure entirely and test nothing.
	 */
	private function register_throwing_fixture(): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-test/throws',
					array(
						'label'               => 'Throws on purpose',
						'description'         => 'Test fixture that throws from its execute callback.',
						'category'            => 'aafm-reads',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static function () {
							throw new \RuntimeException( 'boom from the ability' );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * The choke point in aafm_register_ability_with_log() must catch every Throwable from
	 * $original_execute(), not only the two WooCommerce setters Tasks 3 and 4 guard by name -
	 * the WooCommerce integration alone has 103 setter call sites and only 2 try/catch blocks,
	 * and the next vendor release can add a throw anywhere.
	 *
	 * The WP_DEBUG rethrow is filter-gated off here on purpose: WP_DEBUG is true in this
	 * project's PHPUnit environment (tests/../wp-tests-config.php), so without disabling
	 * `aafm_rethrow_ability_exceptions` the exception would always re-escape and this test would
	 * only prove the rethrow branch works, never the catch-and-log branch it exists to cover.
	 *
	 * On this project's PHPUnit core (7.0.2), WP_Ability::invoke_callback() also wraps every
	 * callback in its own try ( Throwable $e ) - a hardening change added to core after this
	 * plugin's WP 6.9 floor. Without this fix the RuntimeException still gets caught, just one
	 * layer further out by core's fallback, and reported as the generic 'ability_callback_exception'
	 * - which, unlike this plugin's own catch, embeds the raw exception message straight into the
	 * error text. The specific error CODE and the "message never leaks" assertion below are what
	 * distinguish "this plugin's own catch ran" from "core's fallback silently absorbed the gap
	 * instead", the same technique the two tests above use for set_sku()/set_amount() (see
	 * test_create_variation_with_a_duplicate_sku_returns_the_plugins_own_error_not_the_cores).
	 */
	public function test_a_throwing_ability_returns_an_error_and_records_a_resolved_audit_row(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_throwing_fixture();

		$result = wp_get_ability( 'aafm-test/throws' )->execute( array() );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A throwing ability must return WP_Error, never let the exception reach the caller uncaught.'
		);
		$this->assertSame(
			'aafm_ability_exception',
			$result->get_error_code(),
			'A generic ability_callback_exception here would mean the RuntimeException escaped this plugin\'s own choke point in register.php uncaught and was only caught by WP_Ability\'s own Throwable guard - which is a newer-core addition this plugin\'s WP 6.9 floor does not have.'
		);
		$this->assertStringNotContainsString(
			'boom from the ability',
			(string) $result->get_error_message(),
			'The internal exception message must never reach the calling client - it belongs only in the activity log detail column, which is admin-only. Core\'s own ability_callback_exception fallback embeds it verbatim, which is part of why this plugin cannot rely on that fallback.'
		);

		// A crash mid-execute used to leave the row stuck at 'started' - the only forensic signal
		// a crash happened, per the comment at register.php:202-203. Catching the exception
		// resolves the row like any ordinary call, so the exception's class and message must be
		// written into detail or that signal is lost outright.
		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/throws' ) );
		$this->assertCount( 1, $rows, 'One row per call: the started row is updated in place, never duplicated.' );
		$this->assertSame( 'error', $rows[0]['status'], 'The row must resolve to error, not stay stuck at started.' );
		$this->assertStringContainsString(
			'RuntimeException',
			(string) $rows[0]['detail'],
			'The activity log detail column is the admin-only forensic trail - it must name the exception class.'
		);
		$this->assertStringContainsString(
			'boom from the ability',
			(string) $rows[0]['detail'],
			'And the exception message, so a crash stays distinguishable from an ordinary validation error in the log.'
		);
	}
}
