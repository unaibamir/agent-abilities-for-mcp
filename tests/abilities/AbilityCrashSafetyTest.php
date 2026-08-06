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
	 * that type already set, exercising the branch directly and independently of any input
	 * ordering. See test_apply_coupon_input_returns_an_error_for_a_same_call_percent_amount_over_one_hundred()
	 * below for the same-call case, now also caught since aafm_wc_apply_coupon_input() applies
	 * discount_type before amount.
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
	 * The same-request Red Gate finding 5 of the final review identified: aafm_wc_apply_coupon_input()
	 * used to apply amount BEFORE discount_type. A fresh WC_Coupon defaults to discount_type
	 * 'fixed_cart', and WC_Coupon::set_amount() only rejects an amount over 100 once
	 * get_discount_type() already reads 'percent' - so a single create call carrying BOTH
	 * discount_type=percent and amount=150 never triggered the throw: set_amount() ran first
	 * against the still-fixed_cart coupon, accepted 150 without complaint, and set_discount_type()
	 * then applied 'percent' afterwards with nothing left to re-check it. The coupon persisted as a
	 * 150%-off coupon, a state WooCommerce's own setter exists to prevent, on a high-risk
	 * money-moving ability. Reordering so discount_type is applied first means set_amount() sees
	 * the real, final discount_type and throws in the same call that requested it.
	 */
	public function test_apply_coupon_input_returns_an_error_for_a_same_call_percent_amount_over_one_hundred(): void {
		$coupon = new \WC_Coupon();

		$result = aafm_wc_apply_coupon_input(
			$coupon,
			array(
				'discount_type' => 'percent',
				'amount'        => '150',
			)
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A single call carrying discount_type=percent and amount=150 together must be rejected - applying discount_type before amount means set_amount() sees the real discount_type in the same request, not two requests later.'
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
	 * End-to-end through the real ability: an existing percent coupon updated past 100. Deliberately
	 * two calls - create a percent coupon first, then send only `amount` on the update - to cover
	 * the update path, where a caller loads an existing coupon and changes just its amount without
	 * repeating discount_type. The same-request combination (both fields in one create call) is
	 * covered at the unit level by
	 * test_apply_coupon_input_returns_an_error_for_a_same_call_percent_amount_over_one_hundred()
	 * above.
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
	 *
	 * @param string $name Ability name. The registry is process-wide and is not reset between
	 *                     cases, so a second case in this class needs its own name.
	 */
	private function register_throwing_fixture( string $name = 'aafm-test/throws' ): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
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
		// resolves the row like any ordinary call, so the exception's class and throw site must be
		// written into detail or that signal is lost outright.
		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/throws' ) );
		$this->assertCount( 1, $rows, 'One row per call: the started row is updated in place, never duplicated.' );
		$this->assertSame( 'error', $rows[0]['status'], 'The row must resolve to error, not stay stuck at started.' );
		$this->assertStringContainsString(
			'RuntimeException',
			(string) $rows[0]['detail'],
			'The activity log detail column is the admin-only forensic trail - it must name the exception class.'
		);
		// Until 1.6.1 this asserted the message WAS in the detail, which is the sixth known instance
		// in this codebase of a test asserting the bug and passing forever. The message is a vendor
		// string that routinely interpolates the value that caused the throw, the column is exported
		// to CSV, and the wp.org listing promises argument values are never stored. The class and
		// throw site carry the same forensic weight and cannot carry a value.
		$this->assertStringNotContainsString(
			'boom from the ability',
			(string) $rows[0]['detail'],
			'The exception MESSAGE must never be stored: it can interpolate the argument value that caused the throw.'
		);
		$this->assertStringContainsString(
			' at ',
			(string) $rows[0]['detail'],
			'The detail names the throw site, which identifies the defect more precisely than the message does.'
		);
	}

	/**
	 * A fixture whose PERMISSION callback throws, registered the same decorated way
	 * register_throwing_fixture() does. Its execute callback is never reached.
	 *
	 * @param string $message The message the permission callback throws, so a test can assert it
	 *                        does not leak.
	 * @param string $name    Ability name.
	 */
	private function register_fixture_ability_whose_permission_throws( string $message, string $name = 'aafm/test-throwing-perm' ): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $message, $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Throws from its permission callback',
						'description'         => 'Test fixture whose permission callback throws.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn(): array => array(),
						'permission_callback' => static function () use ( $message ): bool {
							throw new \RuntimeException( esc_html( $message ) );
						},
					)
				);
			}
		);
	}

	/**
	 * Activity rows for one ability. They are ARRAYS, not objects (see the aafm_query_activity()
	 * docblock), so index with ['status'] / ['detail'].
	 *
	 * @param string $ability Ability name.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_activity_rows( string $ability ): array {
		return aafm_query_activity( array( 'ability' => $ability ) );
	}

	/**
	 * Tool DTOs for aafm_filter_mcp_tools_list(), which reads $tool->getName(). Includes the
	 * throwing fixture alongside a healthy native, so a test can tell "the bad tool was dropped"
	 * from "the whole catalogue was lost".
	 *
	 * @param string $throwing Ability name of the fixture whose permission throws.
	 * @return array<int,object>
	 */
	private function fake_tools_including_healthy_natives( string $throwing = 'aafm/test-throwing-perm' ): array {
		return array(
			$this->fake_tool( aafm_mcp_tool_name( 'aafm/get-post' ) ),
			$this->fake_tool( aafm_mcp_tool_name( $throwing ) ),
			$this->fake_tool( aafm_mcp_tool_name( 'aafm/get-posts' ) ),
		);
	}

	/**
	 * One stand-in for the adapter's Tool DTO, exposing only the getName() the filter reads.
	 *
	 * @param string $name Wire-form tool name.
	 * @return object
	 */
	private function fake_tool( string $name ): object {
		return new class( $name ) {
			/**
			 * Wire-form tool name.
			 *
			 * @var string
			 */
			private $tool_name;

			/**
			 * Store the name the filter will read back.
			 *
			 * @param string $name Wire-form tool name.
			 */
			public function __construct( string $name ) {
				$this->tool_name = $name;
			}

			/**
			 * The adapter's own accessor name, which aafm_filter_mcp_tools_list() calls.
			 *
			 * @return string
			 */
			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the adapter's Tool DTO method name.
				return $this->tool_name;
			}
		};
	}

	/**
	 * The permission phase had no Throwable floor, so a throw escaped into the adapter, which
	 * builds its error from $throwable->getMessage() (McpTool::check_permission()) and sends that
	 * raw vendor text to the client. The Abilities API admits only a strict true, so denying with
	 * false is a denial on both the 6.9 floor and 7.0+, and because false is not a WP_Error the
	 * adapter substitutes its own generic "Permission denied" - the leak closes by construction
	 * rather than by wording someone can regress.
	 */
	public function test_a_throwing_permission_callback_denies_instead_of_leaking(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_fixture_ability_whose_permission_throws( 'DB user wp_admin pass hunter2 at /var/www/secret/config.php' );

		$allowed = wp_get_ability( 'aafm/test-throwing-perm' )->check_permissions( array() );

		$this->assertFalse( $allowed, 'The Abilities API admits only a strict true; a crash must deny.' );
	}

	/**
	 * Before the guard the throw skipped the `if ( true !== $allowed )` audit block entirely, so a
	 * crashed permission check wrote ZERO rows and left no record anywhere.
	 */
	public function test_a_throwing_permission_callback_writes_one_denied_row_without_the_message(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_fixture_ability_whose_permission_throws( 'secret-value-here', 'aafm/test-throwing-perm-row' );

		wp_get_ability( 'aafm/test-throwing-perm-row' )->check_permissions( array() );

		$rows = $this->read_activity_rows( 'aafm/test-throwing-perm-row' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertStringNotContainsString( 'secret-value-here', (string) $rows[0]['detail'] );
		$this->assertStringContainsString( ' at ', (string) $rows[0]['detail'] );
	}

	/**
	 * The raw undecorated callback runs inside aafm_filter_mcp_tools_list()'s per-tool loop, by way
	 * of aafm_user_can_call_ability(). Without its own guard one throwing vendor
	 * callback propagated out of the loop and took down the entire tools/list response, hiding
	 * every healthy tool with it.
	 */
	public function test_one_throwing_permission_callback_does_not_empty_tools_list(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_fixture_ability_whose_permission_throws( 'boom', 'aafm/test-throwing-perm-list' );

		// Ticking the option is NOT enough on its own. aafm_get_enabled_abilities()
		// (registry.php:230) intersects the stored option against the live catalog, so a fixture
		// that is not in the catalog is dropped from aafm_all_server_ability_names(), never lands
		// in the filter's tool-name map, and sails through ungated - the test would pass with the
		// guard deleted. Put it in the catalog first.
		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ): array {
				$registry['aafm/test-throwing-perm-list'] = array(
					'label'       => 'Throws from its permission callback',
					'description' => 'Test fixture whose permission callback throws.',
					'category'    => 'aafm-reads',
					'risk'        => 'read',
				);
				return $registry;
			}
		);
		aafm_flush_registry_cache();

		$this->register_enabled( array( 'aafm/get-post', 'aafm/get-posts', 'aafm/test-throwing-perm-list' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertContains(
			'aafm/test-throwing-perm-list',
			aafm_all_server_ability_names(),
			'Guard on the guard: if the fixture is not in the advertised set, the filter never gates it.'
		);

		$visible = aafm_filter_mcp_tools_list( $this->fake_tools_including_healthy_natives( 'aafm/test-throwing-perm-list' ) );

		$this->assertNotEmpty( $visible, 'One throwing vendor callback must not take down the whole catalogue.' );
		$names = array_map( static fn( $tool ): string => $tool->getName(), $visible );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/get-post' ), $names, 'Healthy tools stay visible.' );
		$this->assertNotContains(
			aafm_mcp_tool_name( 'aafm/test-throwing-perm-list' ),
			$names,
			'And the crashing one fails closed rather than being advertised.'
		);
	}

	/**
	 * The default-on branch of aafm_rethrow_ability_exceptions had no test at all, so nothing
	 * pinned the behaviour the filter's docblock promises: the Throwable propagates untouched and
	 * the row is deliberately LEFT stuck at 'started', because that stuck row is development's
	 * forensic signal that something crashed uncaught. Resolving it would erase the one thing this
	 * branch exists to keep loud.
	 *
	 * The filter also receives the Throwable now, which is the substantive half of the 1.6.1
	 * change: a filter deciding whether to re-throw could not previously see what it was deciding
	 * about.
	 *
	 * This calls OUR decorated closure directly rather than going through WP_Ability::execute(),
	 * and that is not a shortcut. On this project's PHPUnit core (7.0.2)
	 * WP_Ability::invoke_callback() wraps every callback in its own catch ( Throwable ), so a
	 * correct re-throw is absorbed one layer out and returned as core's generic
	 * 'ability_callback_exception' - the assertion would then be measuring core's version, not our
	 * branch, and would read identically on a build where this branch was deleted. Reaching the
	 * closure by reflection makes the test say the same thing on the WP 6.9 floor, which has no
	 * such guard, and on 7.0+, which does.
	 */
	public function test_the_rethrow_branch_propagates_and_leaves_the_row_unresolved(): void {
		$seen = null;
		add_filter(
			'aafm_rethrow_ability_exceptions',
			static function ( $rethrow, $e = null ) use ( &$seen ) {
				$seen = $e;
				return true;
			},
			10,
			2
		);
		$this->register_throwing_fixture( 'aafm-test/throws-rethrow' );

		$property = new \ReflectionProperty( \WP_Ability::class, 'execute_callback' );
		$property->setAccessible( true );
		$decorated = $property->getValue( wp_get_ability( 'aafm-test/throws-rethrow' ) );

		$caught = null;
		try {
			$decorated( array() );
		} catch ( \RuntimeException $e ) {
			$caught = $e;
		}

		$this->assertInstanceOf(
			\RuntimeException::class,
			$caught,
			'With the filter on, the Throwable must reach the caller instead of becoming a WP_Error.'
		);
		$this->assertSame( 'boom from the ability', $caught->getMessage() );
		$this->assertSame( $caught, $seen, 'The filter must be handed the Throwable it is deciding about.' );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/throws-rethrow' ) );
		$this->assertCount( 1, $rows, 'The started row is written before the callback runs, so it exists.' );
		$this->assertSame(
			'started',
			$rows[0]['status'],
			'The rethrow branch must leave the row stuck at started - that is the forensic signal.'
		);
		$this->assertNull( $rows[0]['detail'], 'And it must write no crash detail, since it wrote no resolution.' );
	}

	/**
	 * Half B of the crash-detail routing: the tail aafm_update_activity_status() in
	 * aafm_register_ability_with_log() runs unconditionally on the SAME row a crash already
	 * resolved. Once aafm_build_activity_detail_from_result() grew a WP_Error branch, that tail
	 * would overwrite the exception's class and throw site with the string 'aafm_ability_exception',
	 * which names only our own wrapper. The $crash_detail_written flag suppresses it. Without this
	 * test the flag could be dropped and every other test in this file would still pass.
	 */
	public function test_a_crashed_call_keeps_the_exception_detail_not_the_wrapper_code(): void {
		// A distinct fixture name: the abilities registry is process-wide and is not reset between
		// cases, so reusing 'aafm-test/throws' here trips core's already-registered notice.
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_throwing_fixture( 'aafm-test/throws-detail' );

		wp_get_ability( 'aafm-test/throws-detail' )->execute( array() );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/throws-detail' ) );
		$this->assertCount( 1, $rows );
		$this->assertStringStartsWith( 'RuntimeException at ', (string) $rows[0]['detail'] );
		$this->assertNotSame(
			'aafm_ability_exception',
			(string) $rows[0]['detail'],
			'The tail update must not clobber the throw site with the wrapper error code.'
		);
	}

	/**
	 * Half A of the same change, from the other side: an ordinary WP_Error result - no exception
	 * anywhere - records its own error code, so a validation failure is as legible in the log as a
	 * crash now is.
	 */
	public function test_an_ordinary_wp_error_result_stores_its_code(): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-test/errors',
					array(
						'label'               => 'Returns a WP_Error',
						'description'         => 'Test fixture that returns a plain WP_Error.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function () {
							return new WP_Error( 'aafm_test_rejected', 'Free-form prose naming the value 12345.' );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		wp_get_ability( 'aafm-test/errors' )->execute( array() );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/errors' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertSame( 'aafm_test_rejected', (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( '12345', (string) $rows[0]['detail'], 'The code, never the message.' );
	}
}
