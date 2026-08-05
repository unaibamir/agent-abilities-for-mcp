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
		aafm_registry_cache_should_flush( true );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/wc-create-product-variation' ) );
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
}
