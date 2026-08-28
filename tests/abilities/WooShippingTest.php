<?php
/**
 * WooCommerce shipping abilities: wc-list-shipping-zones, wc-get-shipping-zone,
 * wc-create-shipping-zone, wc-update-shipping-zone,
 * wc-list-shipping-methods, wc-get-shipping-method, wc-create-shipping-method,
 * wc-update-shipping-method.
 *
 * WooCommerce is not installed in the DDEV test environment - every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcShippingStubStore. The seed_wc_shipping()
 * helper resets and seeds the store per test so each test starts with a clean, known state.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcShippingStubStore;
use WP_Error;

final class WooShippingTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->stub_wc_shipping();
		$this->seed_wc_shipping();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_shipping();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcShippingStubStore::drop_methods_table();
		WcShippingStubStore::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Enable and register the full WooCommerce shipping ability set.
	 */
	private function register_wc_shipping(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-shipping-zones',
				'aafm/wc-get-shipping-zone',
				'aafm/wc-create-shipping-zone',
				'aafm/wc-update-shipping-zone',
				'aafm/wc-list-shipping-methods',
				'aafm/wc-get-shipping-method',
				'aafm/wc-create-shipping-method',
				'aafm/wc-update-shipping-method',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// aafm/wc-list-shipping-zones
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_shipping_zones_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-shipping-zones' )->check_permissions( array() )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-shipping-zones' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'zones', $res );
		$this->assertArrayHasKey( 'total', $res );
	}

	/**
	 * List rows carry the lean shape only: id, zone_name, zone_order - no zone_locations.
	 */
	public function test_list_shipping_zones_lean_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-shipping-zones' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertNotEmpty( $res['zones'] );

		$row = $res['zones'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'zone_name', $row );
		$this->assertArrayHasKey( 'zone_order', $row );

		// Full zone detail must NOT appear in list rows.
		$this->assertArrayNotHasKey( 'zone_locations', $row );
	}

	/**
	 * Every seeded zone comes back with its real fields and the total matches.
	 *
	 * Guards the C1 regression: WC_Shipping_Zones::get_zones() has no zone_object key, so the
	 * old reader (which kept only rows whose zone_object was a WC_Shipping_Zone) dropped every
	 * real zone and returned {"zones":[],"total":0} on a store that actually had zones. The
	 * stub now mirrors the real get_zones() shape, so this asserts the fix reads the row fields.
	 */
	public function test_list_shipping_zones_returns_seeded_zones(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-shipping-zones' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		// Two zones were seeded (Europe order 1, USA order 2); all() returns them in zone_order.
		$this->assertSame( 2, $res['total'] );
		$this->assertCount( 2, $res['zones'] );

		$this->assertSame(
			array(
				'id'         => 1,
				'zone_name'  => 'Europe',
				'zone_order' => 1,
			),
			$res['zones'][0]
		);
		$this->assertSame(
			array(
				'id'         => 2,
				'zone_name'  => 'USA',
				'zone_order' => 2,
			),
			$res['zones'][1]
		);
	}

	/**
	 * A get_zones() row that cannot resolve to a real zone id surfaces a WP_Error, rather than
	 * being silently skipped into a short or empty list.
	 */
	public function test_list_shipping_zones_unresolvable_row_is_wp_error(): void {
		$this->acting_as( 'administrator' );
		// A malformed row with no usable zone id (mirrors get_zones() returning junk).
		WcShippingStubStore::$rows_override = array(
			array( 'zone_name' => 'Broken' ),
		);

		$res = wp_get_ability( 'aafm/wc-list-shipping-zones' )->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Host-inactive: all 8 shipping abilities must be absent from the registry when WooCommerce is off.
	 */
	public function test_list_shipping_zones_host_inactive_absent_from_registry(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-shipping-zones', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-shipping-zone', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-shipping-zone', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-shipping-zone', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-shipping-methods', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-shipping-method', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-shipping-method', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-shipping-method', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// aafm/wc-get-shipping-zone
	// =========================================================================

	/**
	 * Full shape includes id, zone_name, zone_order, and zone_locations.
	 */
	public function test_get_shipping_zone_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-shipping-zone' )->execute( array( 'zone_id' => 1 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$this->assertSame( 1, $res['id'] );
		$this->assertSame( 'Europe', $res['zone_name'] );
		$this->assertArrayHasKey( 'zone_order', $res );
		$this->assertArrayHasKey( 'zone_locations', $res );
		$this->assertIsArray( $res['zone_locations'] );
		$this->assertNotEmpty( $res['zone_locations'] );
	}

	/**
	 * B33: the real WC_Shipping_Zone CONSTRUCTOR throws for a missing non-zero id (the zone data
	 * store's read_multiple() raises "Invalid data store."), so the resolver's null branch was
	 * dead and a routine bad id was crash-classified by the catalog-wide Throwable catch. The
	 * stub now models the vendor throw, the resolver catches it, and an unknown zone id returns
	 * the same clean not-found the tax sibling uses.
	 */
	public function test_get_shipping_zone_unknown_id_is_a_clean_not_found(): void {
		$this->assertFalse(
			WcShippingStubStore::exists( 99999 ),
			'Zone 99999 must not be in the stub store after seeding.'
		);
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-get-shipping-zone' )->execute( array( 'zone_id' => 99999 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_not_found', $res->get_error_code(), 'an unknown zone id is a routine not-found, never a crash-classified error.' );
	}

	// =========================================================================
	// aafm/wc-create-shipping-zone
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_create_shipping_zone_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-shipping-zone' )->check_permissions(
				array( 'zone_name' => 'Asia' )
			)
		);
	}

	/**
	 * Happy path: creates a zone and returns id, zone_name, zone_order.
	 */
	public function test_create_shipping_zone_success(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-shipping-zone' )->execute(
			array(
				'zone_name'  => 'Asia',
				'zone_order' => 3,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( 'Asia', $res['zone_name'] );
		$this->assertSame( 3, $res['zone_order'] );
	}

	/**
	 * Store failure surfaces as WP_Error, not a false success.
	 */
	public function test_create_shipping_zone_store_failure_returns_error(): void {
		WcShippingStubStore::$force_save_failure = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-shipping-zone' )->execute(
			array( 'zone_name' => 'WillFail' )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Store failure must not lie success.' );
		WcShippingStubStore::$force_save_failure = false;
	}

	// =========================================================================
	// aafm/wc-update-shipping-zone
	// =========================================================================

	/**
	 * Update changes only the supplied field; unsupplied fields survive unchanged.
	 */
	public function test_update_shipping_zone_field_isolation(): void {
		$this->acting_as( 'administrator' );
		$original       = wp_get_ability( 'aafm/wc-get-shipping-zone' )->execute( array( 'zone_id' => 1 ) );
		$original_order = $original['zone_order'];

		$res = wp_get_ability( 'aafm/wc-update-shipping-zone' )->execute(
			array(
				'zone_id'   => 1,
				'zone_name' => 'Europe (Updated)',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Europe (Updated)', $res['zone_name'] );
		// zone_order was not supplied; it must survive unchanged.
		$this->assertSame( $original_order, $res['zone_order'] );
	}

	/**
	 * B33 (update side): an unknown zone id is refused with the clean not-found before anything
	 * is written, instead of the vendor constructor throw escaping into a crash classification.
	 */
	public function test_update_shipping_zone_unknown_id_is_a_clean_not_found(): void {
		$this->assertFalse(
			WcShippingStubStore::exists( 99999 ),
			'Zone 99999 must not be in the stub store after seeding.'
		);
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-update-shipping-zone' )->execute(
			array(
				'zone_id'   => 99999,
				'zone_name' => 'Ghost Zone',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_not_found', $res->get_error_code() );
	}

	/**
	 * Zone 0 (Rest of the World) cannot be updated: it has no stored row, so WC_Shipping_Zone::save()
	 * would take the CREATE branch and mint a stray duplicate zone while the executor re-read the
	 * untouched zone 0 and reported it as "updated". The executor rejects zone_id 0 with an
	 * actionable error before ever calling save(), so no phantom zone is created.
	 */
	public function test_update_shipping_zone_rejects_rest_of_world_zone_zero(): void {
		$res = aafm_exec_wc_update_shipping_zone(
			array(
				'zone_id'   => 0,
				'zone_name' => 'Should not stick',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'Updating zone 0 must be refused, not silently succeed.' );
		$this->assertSame( 'aafm_zone_not_editable', $res->get_error_code() );
	}

	/**
	 * Store failure on update surfaces as WP_Error.
	 */
	public function test_update_shipping_zone_store_failure_returns_error(): void {
		WcShippingStubStore::$force_save_failure = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-shipping-zone' )->execute(
			array(
				'zone_id'   => 1,
				'zone_name' => 'WillFail',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Save failure on update must not lie success.' );
		WcShippingStubStore::$force_save_failure = false;
	}

	// =========================================================================
	// aafm/wc-list-shipping-methods
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_shipping_methods_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-shipping-methods' )->check_permissions(
				array( 'zone_id' => 1 )
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-shipping-methods' )->execute(
			array( 'zone_id' => 1 )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'methods', $res );
	}

	/**
	 * Lists methods for a seeded zone - zone 1 (Europe) has 2 methods.
	 */
	public function test_list_shipping_methods_for_zone(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-shipping-methods' )->execute(
			array( 'zone_id' => 1 )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertCount( 2, $res['methods'] );
		$this->assertSame( 2, $res['total'] );
	}

	// =========================================================================
	// aafm/wc-get-shipping-method
	// =========================================================================

	/**
	 * Full shape includes instance_id, id (type), method_title, enabled.
	 */
	public function test_get_shipping_method_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 1, $res['instance_id'] );
		$this->assertSame( 'flat_rate', $res['id'] );
		$this->assertArrayHasKey( 'method_title', $res );
		$this->assertArrayHasKey( 'enabled', $res );
	}

	/**
	 * A shipping method's settings can hold carrier API keys / account credentials / license
	 * keys. Those must be redacted in the returned shape - including secrets nested in a
	 * sub-array and secrets under an unconventional key name.
	 */
	public function test_get_shipping_method_redacts_secrets(): void {
		\AAFM\Tests\WcShippingStubStore::seed_method(
			1,
			1,
			array(
				'id'           => 'flat_rate',
				'method_title' => 'Flat rate',
				'enabled'      => 'yes',
			)
		);
		// A zone method's real configuration lives in its per-instance option, not the
		// legacy settings bucket seed_method() writes above (see aafm_wc_instance_settings()
		// in shipping.php). Seed the secrets there so this proves the redactor against the
		// bucket production code actually reads, not against a fiction.
		update_option(
			'woocommerce_flat_rate_1_settings',
			array(
				'title'      => 'Flat rate',
				'api_key'    => 'carrier-api-key-value',
				'credential' => 'carrier-credential-value',
				'advanced'   => array(
					'mode'          => 'live',
					'account_token' => 'nested-account-token-value',
				),
			)
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$json = wp_json_encode( $res['settings'] );
		$this->assertStringNotContainsString( 'carrier-api-key-value', (string) $json, 'A top-level api_key must be redacted.' );
		$this->assertStringNotContainsString( 'carrier-credential-value', (string) $json, 'An unconventional "credential" key must be redacted.' );
		$this->assertStringNotContainsString( 'nested-account-token-value', (string) $json, 'A secret nested two levels deep must be redacted.' );
		// The benign title and nested mode must survive.
		$this->assertSame( 'Flat rate', $res['settings']['title'] );
		$this->assertSame( 'live', $res['settings']['advanced']['mode'] );
	}

	/**
	 * Unknown instance id returns WP_Error.
	 */
	public function test_get_shipping_method_unknown_instance_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 99999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-create-shipping-method
	// =========================================================================

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_create_shipping_method_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-shipping-method' )->check_permissions(
				array(
					'zone_id'     => 1,
					'method_type' => 'flat_rate',
				)
			)
		);
	}

	/**
	 * Happy path: creates a method and returns the method shape.
	 */
	public function test_create_shipping_method_success(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'method_type' => 'free_shipping',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'instance_id', $res );
		$this->assertGreaterThan( 0, $res['instance_id'] );
		$this->assertSame( 'free_shipping', $res['id'] );
	}

	/**
	 * Store failure surfaces as WP_Error.
	 */
	public function test_create_shipping_method_store_failure_returns_error(): void {
		WcShippingStubStore::$force_save_failure = true;
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'method_type' => 'flat_rate',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Store failure must not lie success.' );
		WcShippingStubStore::$force_save_failure = false;
	}

	// =========================================================================
	// aafm/wc-update-shipping-method
	// =========================================================================

	/**
	 * Update changes only the supplied field; unsupplied fields survive unchanged.
	 */
	public function test_update_shipping_method_field_isolation(): void {
		$this->acting_as( 'administrator' );
		$original      = wp_get_ability( 'aafm/wc-get-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
			)
		);
		$original_type = $original['id'];

		$res = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
				'enabled'     => 'no',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'no', $res['enabled'] );
		// id (type) was not supplied; it must survive unchanged.
		$this->assertSame( $original_type, $res['id'] );
	}

	/**
	 * The enabled flag and method_title persist and survive a fresh read (write -> read round-trip).
	 *
	 * Guards the bug where update relied on a non-existent WC_Shipping_Method::save(): the enabled
	 * toggle must hit the is_enabled column and the title must hit the instance settings, both
	 * reflected by a subsequent get.
	 */
	public function test_update_shipping_method_persists_and_round_trips(): void {
		$this->acting_as( 'administrator' );

		// Toggle off + rename.
		$res = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'      => 1,
				'instance_id'  => 1,
				'enabled'      => 'no',
				'method_title' => 'Renamed',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'no', $res['enabled'] );
		$this->assertSame( 'Renamed', $res['method_title'] );

		$read = wp_get_ability( 'aafm/wc-get-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
			)
		);
		$this->assertSame( 'no', $read['enabled'], 'enabled toggle must survive a fresh read.' );
		$this->assertSame( 'Renamed', $read['method_title'], 'title must survive a fresh read.' );

		// Toggle back on.
		$res = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
				'enabled'     => 'yes',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'yes', $res['enabled'] );

		$read = wp_get_ability( 'aafm/wc-get-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
			)
		);
		$this->assertSame( 'yes', $read['enabled'], 'enabled re-toggle must survive a fresh read.' );
		// Title was not re-supplied; it must survive unchanged.
		$this->assertSame( 'Renamed', $read['method_title'] );
	}

	/**
	 * Unknown instance id returns WP_Error.
	 */
	public function test_update_shipping_method_unknown_id_returns_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 99999,
				'enabled'     => 'no',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A DB failure on the enabled toggle surfaces as WP_Error.
	 *
	 * The production enabled toggle is a direct $wpdb->update() against the
	 * woocommerce_shipping_zone_methods table. Dropping the temp table makes that query fail
	 * ($wpdb->update() returns false), which must surface as a WP_Error rather than a false success.
	 */
	public function test_update_shipping_method_store_failure_returns_error(): void {
		global $wpdb;
		WcShippingStubStore::drop_methods_table();
		$this->acting_as( 'administrator' );

		// The missing-table query is an expected failure here; suppress the wpdb error print so
		// the deliberate failure does not mark the test risky.
		$suppressed = $wpdb->suppress_errors( true );
		$res        = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
				'enabled'     => 'no',
			)
		);
		$wpdb->suppress_errors( $suppressed );

		$this->assertInstanceOf( WP_Error::class, $res, 'A DB failure on the enabled toggle must not lie success.' );
	}

	/**
	 * B42: WooCommerce fires woocommerce_shipping_zone_method_status_toggled whenever the enabled
	 * toggle actually changes the row (its AJAX, REST v2, and v4 write paths all do), so
	 * extensions hooking it went stale when the toggle came through this ability. The executor
	 * must fire it with WC's exact signature - (instance_id, method_id, zone_id, is_enabled) -
	 * and only when a row really changed, mirroring core's own rows-affected gate.
	 */
	public function test_update_shipping_method_enabled_toggle_fires_wc_status_action(): void {
		$this->acting_as( 'administrator' );

		$captured = array();
		$listener = static function ( $instance_id, $method_id, $zone_id, $is_enabled ) use ( &$captured ): void {
			$captured[] = array( $instance_id, $method_id, $zone_id, $is_enabled );
		};
		add_action( 'woocommerce_shipping_zone_method_status_toggled', $listener, 10, 4 );

		// Instance 1 in zone 1 is seeded enabled; toggling to 'no' changes the row.
		$res = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
				'enabled'     => 'no',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array( array( 1, 'flat_rate', 1, false ) ),
			$captured,
			'the toggle must fire the WooCommerce status action once, with the vendor signature.'
		);

		// Setting the same value again changes no row; WC core gates the action on rows affected,
		// so it must not fire here either.
		$captured = array();
		wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'     => 1,
				'instance_id' => 1,
				'enabled'     => 'no',
			)
		);
		$this->assertSame( array(), $captured, 'a no-change toggle affects no row, so the action must not fire - matching WC core.' );

		remove_action( 'woocommerce_shipping_zone_method_status_toggled', $listener, 10 );
	}

	/**
	 * B32: the title used to persist via update_option() BEFORE the enabled toggle ran its
	 * $wpdb->update(), so a failed enabled write returned an error AFTER the title had already
	 * landed - the caller was told "error" while state changed. The enabled write (the only
	 * detectably fallible write) now runs first, so an error means nothing changed.
	 */
	public function test_update_shipping_method_failed_enabled_write_means_nothing_changed(): void {
		global $wpdb;
		WcShippingStubStore::drop_methods_table();
		$this->acting_as( 'administrator' );

		$suppressed = $wpdb->suppress_errors( true );
		$res        = wp_get_ability( 'aafm/wc-update-shipping-method' )->execute(
			array(
				'zone_id'      => 1,
				'instance_id'  => 1,
				'method_title' => 'Should Not Land',
				'enabled'      => 'no',
			)
		);
		$wpdb->suppress_errors( $suppressed );

		$this->assertInstanceOf( WP_Error::class, $res, 'a failed enabled write must fail the request.' );

		$option = get_option( 'woocommerce_flat_rate_1_settings', array() );
		$title  = is_array( $option ) ? ( $option['title'] ?? null ) : null;
		$this->assertNotSame( 'Should Not Land', $title, 'the title must not persist on an errored request - an error has to mean nothing changed.' );
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
	 * Cases: each shipping ability and the args its original audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function provide_success_audit_cases(): array {
		return array(
			'list-shipping-zones'    => array( 'aafm/wc-list-shipping-zones', array() ),
			'create-shipping-zone'   => array( 'aafm/wc-create-shipping-zone', array( 'zone_name' => 'AuditZone' ) ),
			'update-shipping-zone'   => array(
				'aafm/wc-update-shipping-zone',
				array(
					'zone_id'   => 1,
					'zone_name' => 'Europe 2',
				),
			),
			'create-shipping-method' => array(
				'aafm/wc-create-shipping-method',
				array(
					'zone_id'     => 1,
					'method_type' => 'local_pickup',
				),
			),
			'update-shipping-method' => array(
				'aafm/wc-update-shipping-method',
				array(
					'zone_id'      => 1,
					'instance_id'  => 1,
					'method_title' => 'Standard Flat Rate',
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
	 * Cases: each shipping ability and the args its original denied audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function provide_denied_audit_cases(): array {
		return array(
			'list-shipping-zones'    => array( 'aafm/wc-list-shipping-zones', array(), 'editor' ),
			'create-shipping-zone'   => array( 'aafm/wc-create-shipping-zone', array( 'zone_name' => 'Denied' ), 'editor' ),
			'update-shipping-zone'   => array( 'aafm/wc-update-shipping-zone', array( 'zone_id' => 1 ), 'editor' ),
			'create-shipping-method' => array(
				'aafm/wc-create-shipping-method',
				array(
					'zone_id'     => 1,
					'method_type' => 'flat_rate',
				),
				'editor',
			),
			'update-shipping-method' => array(
				'aafm/wc-update-shipping-method',
				array(
					'zone_id'     => 1,
					'instance_id' => 1,
				),
				'editor',
			),
		);
	}

	/**
	 * Task 12a: the create/update-zone descriptions must name the zone_locations gap plainly and
	 * point at the route that actually works (the WooCommerce admin), not just say "not settable"
	 * with no next step.
	 */
	public function test_create_and_update_shipping_zone_descriptions_name_the_locations_gap(): void {
		$create_description = (string) wp_get_ability( 'aafm/wc-create-shipping-zone' )->get_description();
		$update_description = (string) wp_get_ability( 'aafm/wc-update-shipping-zone' )->get_description();

		foreach ( array( $create_description, $update_description ) as $description ) {
			$this->assertStringContainsString(
				'zone_locations',
				$description,
				'the description must name the exact field that cannot be set, not just gesture at "locations".'
			);
			$this->assertStringContainsString(
				'WooCommerce > Settings > Shipping',
				$description,
				'the description must point at the admin route that actually works.'
			);
		}
	}

	/**
	 * Task 12a: the create/update-method descriptions must name the cost gap plainly and point at
	 * the route that actually works.
	 */
	public function test_create_and_update_shipping_method_descriptions_name_the_cost_gap(): void {
		$create_description = (string) wp_get_ability( 'aafm/wc-create-shipping-method' )->get_description();
		$update_description = (string) wp_get_ability( 'aafm/wc-update-shipping-method' )->get_description();

		// Test-quality finding 3 (fix round 1, 208): a bare 'cost' substring is an ordinary English
		// word that a future edit could satisfy with unrelated cost-adjacent prose while silently
		// dropping the actual capability-gap explanation. Assert the specific fragments that state
		// the field cannot be written, matching the sibling zone_locations test's exact-field-name
		// discipline instead of a generic word.
		$this->assertStringContainsString(
			"cost is left at WooCommerce's own default",
			$create_description,
			'the create description must explain that the method\'s cost is not settable through this ability.'
		);
		$this->assertStringContainsString(
			'this plugin\'s abilities cannot write it',
			$create_description,
			'the create description must state plainly that this plugin cannot write the cost.'
		);
		$this->assertStringContainsString(
			'cost cannot be changed through this plugin\'s abilities',
			$update_description,
			'the update description must state plainly that this plugin cannot write the cost.'
		);

		foreach ( array( $create_description, $update_description ) as $description ) {
			$this->assertStringContainsString(
				"zone's shipping method settings",
				$description,
				'the description must point at the admin route that actually works.'
			);
		}
	}

	/**
	 * FIX-3 item 2 (sweep finding A1): the shared zone resolver used by 7 of this file's 8
	 * abilities now delegates to WC_Shipping_Zones::get_zone() instead of hand-instantiating
	 * WC_Shipping_Zone in a try/catch with a redundant id re-check. Traced both paths line by
	 * line against real WooCommerce source and found no observable difference today (the vendor
	 * resolver does the identical instantiate-inside-try/catch internally), so there is no
	 * behavioural difference to drive a test red - this pins the source-level fact instead, as the
	 * finding predicted, and states plainly it could not go red any other way.
	 */
	public function test_zone_resolver_delegates_to_wc_shipping_zones(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/shipping.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test fixture, not a remote URL.
		$this->assertStringContainsString(
			'\WC_Shipping_Zones::get_zone( $zone_id )',
			$source,
			'aafm_wc_get_shipping_zone_object() must delegate to WC_Shipping_Zones::get_zone(), the same resolver WooCommerce\'s own REST controller base class uses.'
		);
		preg_match( '/function aafm_wc_get_shipping_zone_object.*?\n}/s', $source, $matches );
		$this->assertStringNotContainsString(
			'new \WC_Shipping_Zone(',
			$matches[0] ?? '',
			'the zone resolver\'s own function body must not hand-instantiate WC_Shipping_Zone directly any more (a different function, wc-create-shipping-zone, legitimately still does).'
		);
	}
}
