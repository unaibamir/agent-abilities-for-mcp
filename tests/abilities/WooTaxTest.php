<?php
/**
 * WooCommerce tax abilities: wc-list-tax-rates, wc-get-tax-rate, wc-create-tax-rate,
 * wc-update-tax-rate, wc-delete-tax-rate, wc-list-tax-classes, wc-get-tax-class,
 * wc-create-tax-class, wc-delete-tax-class.
 *
 * WooCommerce is not installed in the DDEV test environment. Tax rates are backed by a real
 * temp table (woocommerce_tax_rates) created in the test DB by WcTaxStubStore. Tax classes
 * are backed by WcTaxStubStore::$classes through the WC_Tax eval stub.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcTaxStubStore;
use WP_Error;

final class WooTaxTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->stub_wc_tax();
		$this->seed_wc_tax();
		WcTaxStubStore::create_tax_rates_table();
		WcTaxStubStore::seed_rates();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_tax();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcTaxStubStore::drop_tax_rates_table();
		WcTaxStubStore::reset();
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
	 * Enable and register the full WooCommerce tax ability set.
	 */
	private function register_wc_tax(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-tax-rates',
				'aafm/wc-get-tax-rate',
				'aafm/wc-create-tax-rate',
				'aafm/wc-update-tax-rate',
				'aafm/wc-delete-tax-rate',
				'aafm/wc-list-tax-classes',
				'aafm/wc-get-tax-class',
				'aafm/wc-create-tax-class',
				'aafm/wc-delete-tax-class',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// Integration guard
	// =========================================================================

	/**
	 * Tax abilities must be absent from the registry when WooCommerce is inactive.
	 */
	public function test_abilities_hidden_when_woocommerce_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-tax-rates', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-delete-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-tax-classes', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-tax-class', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-tax-class', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-delete-tax-class', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// aafm/wc-list-tax-rates
	// =========================================================================

	/**
	 * List returns both seeded rates with the canonical shape.
	 */
	public function test_list_tax_rates_returns_seeded_rates(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'rates', $res );
		$this->assertArrayHasKey( 'total', $res );
		$this->assertCount( 2, $res['rates'] );
		$this->assertSame( 2, $res['total'] );

		$first = $res['rates'][0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'country', $first );
		$this->assertArrayHasKey( 'rate', $first );
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'compound', $first );
		$this->assertArrayHasKey( 'shipping', $first );
		$this->assertArrayHasKey( 'class', $first );
	}

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_tax_rates_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-tax-rates' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-get-tax-rate
	// =========================================================================

	/**
	 * Getting a seeded rate by id returns the canonical rate shape.
	 */
	public function test_get_tax_rate_returns_rate(): void {
		$this->acting_as( 'administrator' );

		// Fetch id from list first.
		$list    = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		$res = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => $rate_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $rate_id, $res['id'] );
		$this->assertSame( 'GB', $res['country'] );
	}

	/**
	 * Unknown id returns WP_Error.
	 */
	public function test_get_tax_rate_unknown_id_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-create-tax-rate
	// =========================================================================

	/**
	 * Create inserts a new row and returns the full rate shape with a new id.
	 */
	public function test_create_tax_rate_inserts_and_returns(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-tax-rate' )->execute(
			array(
				'rate'    => '10.0000',
				'name'    => 'Test Rate',
				'country' => 'US',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( 'US', $res['country'] );
		$this->assertSame( '10.0000', $res['rate'] );
		$this->assertSame( 'Test Rate', $res['name'] );

		// Confirm it's actually in the DB.
		$fetched = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => $res['id'] ) );
		$this->assertNotInstanceOf( WP_Error::class, $fetched );
		$this->assertSame( 'Test Rate', $fetched['name'] );
	}

	// =========================================================================
	// aafm/wc-update-tax-rate
	// =========================================================================

	/**
	 * Update changes only the supplied field; unsupplied fields survive unchanged.
	 */
	public function test_update_tax_rate_changes_fields(): void {
		$this->acting_as( 'administrator' );

		$list             = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id          = (int) $list['rates'][0]['id'];
		$original_country = $list['rates'][0]['country'];

		$res = wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array(
				'rate_id' => $rate_id,
				'name'    => 'Updated VAT',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Updated VAT', $res['name'] );
		// Country was not supplied; it must survive unchanged.
		$this->assertSame( $original_country, $res['country'] );
	}

	/**
	 * Updating an unknown id returns WP_Error.
	 */
	public function test_update_tax_rate_unknown_id_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array( 'rate_id' => 999999 )
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-delete-tax-rate
	// =========================================================================

	/**
	 * Delete removes the row; subsequent get returns WP_Error.
	 */
	public function test_delete_tax_rate_removes_row(): void {
		$this->acting_as( 'administrator' );

		$list    = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		$res = wp_get_ability( 'aafm/wc-delete-tax-rate' )->execute( array( 'rate_id' => $rate_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertTrue( $res['deleted'] );

		$fetched = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => $rate_id ) );
		$this->assertInstanceOf( WP_Error::class, $fetched );
	}

	/**
	 * Deleting an unknown id returns WP_Error.
	 */
	public function test_delete_tax_rate_unknown_id_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-tax-rate' )->execute( array( 'rate_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-list-tax-classes
	// =========================================================================

	/**
	 * List includes the implicit Standard class plus the two seeded classes.
	 */
	public function test_list_tax_classes_returns_standard_plus_seeded(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-tax-classes' )->execute( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'classes', $res );
		$this->assertArrayHasKey( 'total', $res );

		$slugs = array_column( $res['classes'], 'slug' );
		$this->assertContains( 'standard', $slugs );
		$this->assertContains( 'reduced-rate', $slugs );
		$this->assertContains( 'zero-rate', $slugs );
		$this->assertSame( 3, $res['total'] );
	}

	/**
	 * Each class row has name and slug.
	 */
	public function test_list_tax_classes_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-tax-classes' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		foreach ( $res['classes'] as $class ) {
			$this->assertArrayHasKey( 'name', $class );
			$this->assertArrayHasKey( 'slug', $class );
		}
	}

	// =========================================================================
	// aafm/wc-get-tax-class
	// =========================================================================

	/**
	 * Get by slug returns the matching class.
	 */
	public function test_get_tax_class_by_slug(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-tax-class' )->execute( array( 'slug' => 'reduced-rate' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'reduced-rate', $res['slug'] );
	}

	/**
	 * Get "standard" returns the Standard class.
	 */
	public function test_get_tax_class_standard_slug(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-tax-class' )->execute( array( 'slug' => 'standard' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'standard', $res['slug'] );
		$this->assertSame( 'Standard', $res['name'] );
	}

	/**
	 * Unknown slug returns WP_Error.
	 */
	public function test_get_tax_class_unknown_slug_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-tax-class' )->execute( array( 'slug' => 'does-not-exist' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-create-tax-class
	// =========================================================================

	/**
	 * Create adds a new class visible in the list.
	 */
	public function test_create_tax_class_succeeds(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-tax-class' )->execute(
			array( 'name' => 'Super Rate' )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'super-rate', $res['slug'] );
		$this->assertSame( 'Super Rate', $res['name'] );

		// Confirm it appears in the list.
		$list  = wp_get_ability( 'aafm/wc-list-tax-classes' )->execute( array() );
		$slugs = array_column( $list['classes'], 'slug' );
		$this->assertContains( 'super-rate', $slugs );
	}

	/**
	 * Store failure returns WP_Error.
	 */
	public function test_create_tax_class_failure_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		WcTaxStubStore::$force_save_failure = true;
		$res                                = wp_get_ability( 'aafm/wc-create-tax-class' )->execute(
			array( 'name' => 'Failing Class' )
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-delete-tax-class
	// =========================================================================

	/**
	 * Delete removes the class; subsequent get returns WP_Error.
	 */
	public function test_delete_tax_class_removes_it(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-tax-class' )->execute( array( 'slug' => 'reduced-rate' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertTrue( $res['deleted'] );

		$fetched = wp_get_ability( 'aafm/wc-get-tax-class' )->execute( array( 'slug' => 'reduced-rate' ) );
		$this->assertInstanceOf( WP_Error::class, $fetched );
	}

	/**
	 * Deleting the Standard class is rejected with WP_Error.
	 */
	public function test_delete_standard_tax_class_is_rejected(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-tax-class' )->execute( array( 'slug' => 'standard' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Delete store failure returns WP_Error.
	 */
	public function test_delete_tax_class_failure_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		WcTaxStubStore::$force_delete_failure = true;
		$res                                  = wp_get_ability( 'aafm/wc-delete-tax-class' )->execute( array( 'slug' => 'reduced-rate' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// Audit: create-tax-rate
	// =========================================================================

	/**
	 * Successful create-tax-rate is recorded in the activity log.
	 */
	public function test_create_tax_rate_audit_success(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-create-tax-rate' )->execute( array( 'rate' => '3.0000' ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-rate', $abilities );
	}

	/**
	 * Denied create-tax-rate is recorded in the activity log.
	 */
	public function test_create_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-tax-rate' )->check_permissions( array( 'rate' => '3.0000' ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: update-tax-rate
	// =========================================================================

	/**
	 * Successful update-tax-rate is recorded in the activity log.
	 */
	public function test_update_tax_rate_audit_success(): void {
		$this->acting_as( 'administrator' );

		$list    = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array(
				'rate_id' => $rate_id,
				'name'    => 'Audit VAT',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-tax-rate', $abilities );
	}

	/**
	 * Denied update-tax-rate is recorded in the activity log.
	 */
	public function test_update_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-update-tax-rate' )->check_permissions( array( 'rate_id' => 1 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-update-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: delete-tax-rate
	// =========================================================================

	/**
	 * Successful delete-tax-rate is recorded in the activity log.
	 */
	public function test_delete_tax_rate_audit_success(): void {
		$this->acting_as( 'administrator' );

		$list    = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		wp_get_ability( 'aafm/wc-delete-tax-rate' )->execute( array( 'rate_id' => $rate_id ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-tax-rate', $abilities );
	}

	/**
	 * Denied delete-tax-rate is recorded in the activity log.
	 */
	public function test_delete_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-tax-rate' )->check_permissions( array( 'rate_id' => 1 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: create-tax-class
	// =========================================================================

	/**
	 * Successful create-tax-class is recorded in the activity log.
	 */
	public function test_create_tax_class_audit_success(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-create-tax-class' )->execute( array( 'name' => 'Audit Class' ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-class', $abilities );
	}

	/**
	 * Denied create-tax-class is recorded in the activity log.
	 */
	public function test_create_tax_class_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-tax-class' )->check_permissions( array( 'name' => 'Denied' ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-class', $abilities );
	}

	// =========================================================================
	// Audit: delete-tax-class
	// =========================================================================

	/**
	 * Successful delete-tax-class is recorded in the activity log.
	 */
	public function test_delete_tax_class_audit_success(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-delete-tax-class' )->execute( array( 'slug' => 'zero-rate' ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-tax-class', $abilities );
	}

	/**
	 * Denied delete-tax-class is recorded in the activity log.
	 */
	public function test_delete_tax_class_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-tax-class' )->check_permissions( array( 'slug' => 'reduced-rate' ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-tax-class', $abilities );
	}
}
