<?php
/**
 * WooCommerce tax abilities: wc-list-tax-rates, wc-get-tax-rate, wc-create-tax-rate,
 * wc-update-tax-rate, wc-list-tax-classes, wc-create-tax-class.
 *
 * WooCommerce is not installed in the DDEV test environment. Tax rates are backed by a real
 * temp table (woocommerce_tax_rates) created in the test DB by WcTaxStubStore. Tax classes
 * are backed by WcTaxStubStore::$classes through the WC_Tax eval stub.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use Oversio\Tests\IntegrationStubs;
use Oversio\Tests\WcTaxStubStore;
use WP_Error;

final class WooTaxTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->stub_wc_tax();
		$this->seed_wc_tax();
		WcTaxStubStore::create_tax_rates_table();
		WcTaxStubStore::seed_rates();
		oversio_registry_cache_should_flush( true );
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
	 * Enable and register the full WooCommerce tax ability set.
	 */
	private function register_wc_tax(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/wc-list-tax-rates',
				'oversio/wc-get-tax-rate',
				'oversio/wc-create-tax-rate',
				'oversio/wc-update-tax-rate',
				'oversio/wc-list-tax-classes',
				'oversio/wc-create-tax-class',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	// =========================================================================
	// Integration guard
	// =========================================================================

	/**
	 * Tax abilities must be absent from the registry when WooCommerce is inactive.
	 */
	public function test_abilities_hidden_when_woocommerce_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'oversio_integration_active_woocommerce' );
		add_filter( 'oversio_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( oversio_integration_active( 'woocommerce' ) );
		oversio_registry_cache_should_flush( true );

		$registry = oversio_get_abilities_registry();
		$this->assertArrayNotHasKey( 'oversio/wc-list-tax-rates', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-get-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-create-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-update-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-list-tax-classes', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-create-tax-class', $registry );

		remove_filter( 'oversio_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// oversio/wc-list-tax-rates
	// =========================================================================

	/**
	 * List returns both seeded rates with the canonical shape.
	 */
	public function test_list_tax_rates_returns_seeded_rates(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-list-tax-rates' )->execute( array() );

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
			wp_get_ability( 'oversio/wc-list-tax-rates' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// oversio/wc-get-tax-rate
	// =========================================================================

	/**
	 * Getting a seeded rate by id returns the canonical rate shape.
	 */
	public function test_get_tax_rate_returns_rate(): void {
		$this->acting_as( 'administrator' );

		// Fetch id from list first.
		$list    = wp_get_ability( 'oversio/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		$res = wp_get_ability( 'oversio/wc-get-tax-rate' )->execute( array( 'rate_id' => $rate_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $rate_id, $res['id'] );
		$this->assertSame( 'GB', $res['country'] );
	}

	/**
	 * Unknown id returns WP_Error.
	 */
	public function test_get_tax_rate_unknown_id_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-get-tax-rate' )->execute( array( 'rate_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// oversio/wc-create-tax-rate
	// =========================================================================

	/**
	 * Create inserts a new row and returns the full rate shape with a new id.
	 */
	public function test_create_tax_rate_inserts_and_returns(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-tax-rate' )->execute(
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
		$fetched = wp_get_ability( 'oversio/wc-get-tax-rate' )->execute( array( 'rate_id' => $res['id'] ) );
		$this->assertNotInstanceOf( WP_Error::class, $fetched );
		$this->assertSame( 'Test Rate', $fetched['name'] );
	}

	// =========================================================================
	// oversio/wc-update-tax-rate
	// =========================================================================

	/**
	 * Update changes only the supplied field; unsupplied fields survive unchanged.
	 */
	public function test_update_tax_rate_changes_fields(): void {
		$this->acting_as( 'administrator' );

		$list             = wp_get_ability( 'oversio/wc-list-tax-rates' )->execute( array() );
		$rate_id          = (int) $list['rates'][0]['id'];
		$original_country = $list['rates'][0]['country'];

		$res = wp_get_ability( 'oversio/wc-update-tax-rate' )->execute(
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
		$res = wp_get_ability( 'oversio/wc-update-tax-rate' )->execute(
			array( 'rate_id' => 999999 )
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// oversio/wc-list-tax-classes
	// =========================================================================

	/**
	 * List includes the implicit Standard class plus the two seeded classes.
	 */
	public function test_list_tax_classes_returns_standard_plus_seeded(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-list-tax-classes' )->execute( array() );

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
		$res = wp_get_ability( 'oversio/wc-list-tax-classes' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		foreach ( $res['classes'] as $class ) {
			$this->assertArrayHasKey( 'name', $class );
			$this->assertArrayHasKey( 'slug', $class );
		}
	}

	// =========================================================================
	// oversio/wc-create-tax-class
	// =========================================================================

	/**
	 * Create adds a new class visible in the list.
	 */
	public function test_create_tax_class_succeeds(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'oversio/wc-create-tax-class' )->execute(
			array( 'name' => 'Super Rate' )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'super-rate', $res['slug'] );
		$this->assertSame( 'Super Rate', $res['name'] );

		// Confirm it appears in the list.
		$list  = wp_get_ability( 'oversio/wc-list-tax-classes' )->execute( array() );
		$slugs = array_column( $list['classes'], 'slug' );
		$this->assertContains( 'super-rate', $slugs );
	}

	/**
	 * Store failure returns WP_Error.
	 */
	public function test_create_tax_class_failure_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		WcTaxStubStore::$force_save_failure = true;
		$res                                = wp_get_ability( 'oversio/wc-create-tax-class' )->execute(
			array( 'name' => 'Failing Class' )
		);
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
		wp_get_ability( 'oversio/wc-create-tax-rate' )->execute( array( 'rate' => '3.0000' ) );

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-create-tax-rate', $abilities );
	}

	/**
	 * Denied create-tax-rate is recorded in the activity log.
	 */
	public function test_create_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-create-tax-rate' )->check_permissions( array( 'rate' => '3.0000' ) );

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-create-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: update-tax-rate
	// =========================================================================

	/**
	 * Successful update-tax-rate is recorded in the activity log.
	 */
	public function test_update_tax_rate_audit_success(): void {
		$this->acting_as( 'administrator' );

		$list    = wp_get_ability( 'oversio/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		wp_get_ability( 'oversio/wc-update-tax-rate' )->execute(
			array(
				'rate_id' => $rate_id,
				'name'    => 'Audit VAT',
			)
		);

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-update-tax-rate', $abilities );
	}

	/**
	 * Denied update-tax-rate is recorded in the activity log.
	 */
	public function test_update_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-update-tax-rate' )->check_permissions( array( 'rate_id' => 1 ) );

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-update-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: create-tax-class
	// =========================================================================

	/**
	 * Successful create-tax-class is recorded in the activity log.
	 */
	public function test_create_tax_class_audit_success(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'oversio/wc-create-tax-class' )->execute( array( 'name' => 'Audit Class' ) );

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-create-tax-class', $abilities );
	}

	/**
	 * Denied create-tax-class is recorded in the activity log.
	 */
	public function test_create_tax_class_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-create-tax-class' )->check_permissions( array( 'name' => 'Denied' ) );

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-create-tax-class', $abilities );
	}
}
