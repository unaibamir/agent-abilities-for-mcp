<?php
/**
 * WooCommerce product-variation abilities (sub-slice W4-WC1b).
 *
 * The DDEV site ships no WooCommerce host plugin, so each test forces the integration active through
 * its per-slug filter and defines the minimal WooCommerce host surface via stub_woocommerce() (the
 * IntegrationStubs trait). A variation is a product row carrying type='variation' and a parent_id;
 * the abilities list/read/create/update/delete through the WC CRUD layer (wc_get_product /
 * WC_Product_Variation), all served by the WcStubStore with parent/children linkage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcStubStore;
use WP_Error;

final class WooVariationsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->seed_variable_parent_with_variations();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_variations();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

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
	 * Seed a variable parent (id 500) owning two variations (601, 602). The parent is seeded first so
	 * the store's children linkage attaches each variation to it.
	 *
	 * @param int $variation_count How many variations to attach to the parent.
	 */
	private function seed_variable_parent_with_variations( int $variation_count = 2 ): void {
		$products = array(
			array(
				'id'     => 500,
				'name'   => 'Variable Parent',
				'type'   => 'variable',
				'status' => 'publish',
			),
		);
		for ( $i = 1; $i <= $variation_count; $i++ ) {
			$products[] = array(
				'id'            => 600 + $i,
				'parent_id'     => 500,
				'type'          => 'variation',
				'sku'           => 'VAR-' . ( 600 + $i ),
				'regular_price' => '5.0' . $i,
				'price'         => '5.0' . $i,
				'status'        => 'publish',
				'stock_status'  => 'instock',
				'description'   => 'Variation ' . $i,
				'attributes'    => array( 'pa_color' => 'red' ),
			);
		}
		$this->stub_woocommerce( $products );
	}

	/**
	 * Enable + register the WooCommerce variation set so the abilities can be invoked.
	 */
	private function register_wc_variations(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-product-variations',
				'aafm/wc-get-product-variation',
				'aafm/wc-create-product-variation',
				'aafm/wc-update-product-variation',
				'aafm/wc-delete-product-variation',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * WC1b reads: list + get.
	 */
	public function test_list_variations_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-product-variations' )->check_permissions( array( 'product_id' => 500 ) )
		);

		$this->acting_as( 'administrator' ); // admin has manage_woocommerce.
		$res = wp_get_ability( 'aafm/wc-list-product-variations' )->execute( array( 'product_id' => 500 ) );
		$this->assertArrayHasKey( 'variations', $res );
		$this->assertArrayHasKey( 'total', $res );
		$this->assertSame( 2, $res['total'] );
		$ids = wp_list_pluck( $res['variations'], 'id' );
		sort( $ids );
		$this->assertSame( array( 601, 602 ), $ids );
	}

	public function test_list_variation_rows_are_lean(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-product-variations' )->execute( array( 'product_id' => 500 ) );
		$row = $res['variations'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'parent_id', $row );
		$this->assertArrayHasKey( 'sku', $row );
		$this->assertArrayHasKey( 'price', $row );
		$this->assertArrayHasKey( 'stock_status', $row );
		$this->assertArrayHasKey( 'status', $row );
		$this->assertArrayNotHasKey( 'description', $row, 'list rows are lean (no description).' );
		$this->assertArrayNotHasKey( 'attributes', $row, 'list rows are lean (no attributes).' );
	}

	public function test_list_variations_denies_a_subscriber(): void {
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-product-variations' )->check_permissions( array( 'product_id' => 500 ) )
		);
	}

	public function test_list_variations_requires_product_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-product-variations' )->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $res, 'product_id (the parent) is required.' );
	}

	public function test_list_variations_unknown_parent_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-product-variations' )->execute( array( 'product_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_variation_returns_rich_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 601, $res['id'] );
		$this->assertSame( 500, $res['parent_id'], 'The variation reports its parent.' );
		$this->assertSame( 'VAR-601', $res['sku'] );
		$this->assertArrayHasKey( 'description', $res );
		$this->assertArrayHasKey( 'attributes', $res );
		$this->assertArrayHasKey( 'regular_price', $res );
		$this->assertArrayHasKey( 'stock_quantity', $res );
		// The flat name=>value attribute map round-trips.
		$attributes = (array) $res['attributes'];
		$this->assertSame( 'red', $attributes['pa_color'] ?? null );
	}

	public function test_get_variation_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_variation_denies_a_subscriber(): void {
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-product-variation' )->check_permissions( array( 'variation_id' => 601 ) )
		);
	}

	/**
	 * A valid id that is NOT a variation — the variable parent itself (500) or a simple product —
	 * must be rejected by get/update/delete. aafm_wc_get_variation() returns null for a non-variation
	 * product, so each surface returns a WP_Error. (Fix Code MED-2.)
	 */
	public function test_get_variation_rejects_a_non_variation_product(): void {
		$this->acting_as( 'administrator' );
		// 500 is the variable parent (type=variable), a valid product but not a variation.
		$res = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 500 ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'The variable parent is not a variation; get must reject it.' );
	}

	public function test_update_variation_rejects_a_non_variation_product(): void {
		$this->acting_as( 'administrator' );
		// Seed a simple product; updating it as a variation must fail.
		WcStubStore::seed(
			800,
			array(
				'id'   => 800,
				'name' => 'Simple Product',
				'type' => 'simple',
			)
		);
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute(
			array(
				'variation_id' => 800,
				'sku'          => 'NOT-A-VARIATION',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A simple product is not a variation; update must reject it.' );
	}

	public function test_delete_variation_rejects_a_non_variation_product(): void {
		$this->acting_as( 'administrator' );
		// The variable parent (500) is a valid product but not a variation; delete must refuse it.
		$res = wp_get_ability( 'aafm/wc-delete-product-variation' )->execute( array( 'variation_id' => 500 ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'The variable parent is not a variation; delete must reject it.' );
		$this->assertTrue( WcStubStore::exists( 500 ), 'The parent must survive a rejected delete.' );
	}

	public function test_get_variation_empty_attributes_encodes_as_object_not_array(): void {
		// A variation with no chosen attributes: the map must JSON-encode to "{}" (object), never "[]".
		WcStubStore::seed(
			700,
			array(
				'id'         => 700,
				'parent_id'  => 500,
				'type'       => 'variation',
				'attributes' => array(),
			)
		);
		$this->acting_as( 'administrator' );
		$res  = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 700 ) );
		$json = (string) wp_json_encode( $res['attributes'] );
		$this->assertSame( '{}', $json, 'Empty variation attributes must encode as {}.' );
	}

	public function test_get_variation_output_schema_declares_every_emitted_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$schema   = aafm_args_wc_get_product_variation()['output_schema'];
		$declared = array_keys( $schema['properties'] );

		foreach ( array_keys( $res ) as $emitted_key ) {
			$this->assertContains(
				$emitted_key,
				$declared,
				sprintf( 'Emitted field "%s" must be declared in the get-variation output_schema.', $emitted_key )
			);
		}
	}

	public function test_wc_variation_abilities_absent_when_host_inactive(): void {
		// Mirror WooProductsTest: assert at the REGISTRY level. stub_woocommerce() defines class
		// WooCommerce process-wide, so real detection still reports WC active after removing the force
		// filter — pin it off through the aafm_woocommerce_active seam.
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );
		$this->assertArrayNotHasKey( 'aafm/wc-list-product-variations', aafm_get_abilities_registry() );
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	public function test_list_variations_discovery_admin_yes_editor_no(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/wc-list-product-variations' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/wc-list-product-variations' ) );
	}

	/**
	 * WC1b writes: create + update.
	 */
	public function test_create_variation_attaches_to_the_parent_and_returns_rich(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id'    => 500,
				'sku'           => 'VAR-NEW',
				'regular_price' => '7.50',
				'stock_status'  => 'instock',
				'description'   => 'A fresh variation.',
				'attributes'    => array( 'pa_size' => 'large' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 500, $res['parent_id'], 'The new variation reports its parent.' );
		$this->assertSame( 'VAR-NEW', $res['sku'] );
		$this->assertSame( '7.50', $res['regular_price'] );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( 'large', ( (array) $res['attributes'] )['pa_size'] ?? null );

		// The variation is now readable through the store and listed under its parent.
		$this->assertTrue( WcStubStore::exists( (int) $res['id'] ) );
		$list = wp_get_ability( 'aafm/wc-list-product-variations' )->execute( array( 'product_id' => 500 ) );
		$this->assertContains( (int) $res['id'], wp_list_pluck( $list['variations'], 'id' ) );
	}

	public function test_create_variation_requires_parent_product_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute( array( 'sku' => 'NO-PARENT' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'product_id (the parent) is required on create.' );
	}

	public function test_create_variation_unknown_parent_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute( array( 'product_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'A variation cannot attach to a nonexistent parent.' );
	}

	public function test_create_variation_rejects_a_non_variable_parent(): void {
		// Fix MCP LOW-1: a variation only belongs under a variable parent. Attaching to a simple
		// product silently no-ops in the store, so the create exec rejects a non-variable parent.
		$this->acting_as( 'administrator' );
		WcStubStore::seed(
			801,
			array(
				'id'   => 801,
				'name' => 'Simple Parent',
				'type' => 'simple',
			)
		);
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id' => 801,
				'sku'        => 'UNDER-SIMPLE',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A variation cannot attach to a simple (non-variable) parent.' );
	}

	public function test_create_variation_sanitizes_description(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id'  => 500,
				'description' => '<script>alert(1)</script><strong>bold</strong>',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertStringNotContainsString( '<script>', $res['description'], 'The description drops scripts.' );
		$this->assertStringContainsString( '<strong>', $res['description'], 'The description keeps benign markup.' );
	}

	public function test_create_variation_rejects_a_smuggled_top_level_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id'  => 500,
				'post_author' => 999999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled top-level field.' );
	}

	public function test_create_variation_rejects_a_smuggled_nested_attribute_value(): void {
		// MEDIUM-4: the attributes map is closed to scalar string values. A smuggled NESTED structure
		// (an object/array value inside the flat name=>value map) must be rejected before execute, not
		// just a smuggled top-level field.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id' => 500,
				'attributes' => array(
					'pa_color' => array( 'smuggled' => 'object-value' ),
				),
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'A non-string (nested) attribute value must be rejected by the closed attributes schema.'
		);
	}

	public function test_create_variation_accepts_a_clean_attribute_map(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute(
			array(
				'product_id' => 500,
				'attributes' => array(
					'pa_color' => 'blue',
					'pa_size'  => 'small',
				),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A clean attribute map must be accepted.' );
		$attributes = (array) $res['attributes'];
		$this->assertSame( 'blue', $attributes['pa_color'] ?? null );
		$this->assertSame( 'small', $attributes['pa_size'] ?? null );
	}

	public function test_create_variation_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-product-variation' )->check_permissions( array( 'product_id' => 500 ) )
		);
	}

	public function test_create_variation_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-variation' )->execute( array( 'product_id' => 500 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-product-variation', $abilities );
	}


	public function test_update_variation_patches_by_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute(
			array(
				'variation_id' => 601,
				'sku'          => 'VAR-601-RENAMED',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'VAR-601-RENAMED', $res['sku'] );
		// Untouched fields survive the PATCH.
		$this->assertSame( 500, $res['parent_id'], 'A PATCH leaves the parent intact.' );

		$read = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertSame( 'VAR-601-RENAMED', $read['sku'], 'The update must round-trip.' );
	}

	public function test_list_variations_total_is_grand_count_not_page_count(): void {
		// Fix API LOW-1 / Code LOW-2: a parent with 3 children, paged at per_page:1 — one row on the
		// page, but total is the grand child count (3), not the page length.
		$this->reset_integration_stubs();
		$this->force_integration( 'woocommerce' );
		$this->seed_variable_parent_with_variations( 3 );
		aafm_registry_cache_should_flush( true );
		$this->register_wc_variations();

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-product-variations' )->execute(
			array(
				'product_id' => 500,
				'per_page'   => 1,
			)
		);
		$this->assertCount( 1, $res['variations'], 'per_page:1 returns exactly one row.' );
		$this->assertSame( 3, $res['total'], 'total is the grand child count, not the page length.' );
	}

	public function test_update_variation_empty_patch_is_a_noop(): void {
		// Fix API LOW-2: an update carrying only variation_id (no other fields) is a valid no-op — it
		// returns the rich shape (not a WP_Error) and a seeded field survives untouched.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'An empty PATCH is a no-op, not an error.' );
		$this->assertSame( 'VAR-601', $res['sku'], 'The seeded sku survives an empty PATCH.' );
	}

	public function test_update_variation_leaves_other_fields_untouched(): void {
		// Fix API LOW-3: updating sku ONLY must leave regular_price, description, and the attributes
		// map untouched.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute(
			array(
				'variation_id' => 601,
				'sku'          => 'VAR-601-ISOLATED',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$read = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertSame( 'VAR-601-ISOLATED', $read['sku'], 'sku changed.' );
		$this->assertSame( '5.01', $read['regular_price'], 'regular_price is untouched.' );
		$this->assertSame( 'Variation 1', $read['description'], 'description is untouched.' );
		$this->assertSame( 'red', ( (array) $read['attributes'] )['pa_color'] ?? null, 'the attributes map is untouched.' );
	}

	public function test_update_variation_requires_variation_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute( array( 'sku' => 'No id' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'variation_id is required on update.' );
	}

	public function test_update_variation_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute(
			array(
				'variation_id' => 999999,
				'sku'          => 'Ghost',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_update_variation_rejects_a_smuggled_nested_attribute_value(): void {
		// MEDIUM-4 on the update schema too.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute(
			array(
				'variation_id' => 601,
				'attributes'   => array(
					'pa_color' => array( 'smuggled' => 'object-value' ),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A non-string attribute value must be rejected on update too.' );
	}

	public function test_update_variation_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-product-variation' )->check_permissions( array( 'variation_id' => 601 ) )
		);
	}

	public function test_update_variation_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-variation' )->execute(
			array(
				'variation_id' => 601,
				'sku'          => 'VAR-601-AUDITED',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-product-variation', $abilities );
	}

	public function test_create_and_update_variation_share_the_get_output_schema(): void {
		$get    = aafm_args_wc_get_product_variation()['output_schema']['properties'];
		$create = aafm_args_wc_create_product_variation()['output_schema']['properties'];
		$update = aafm_args_wc_update_product_variation()['output_schema']['properties'];

		$this->assertSame( $get, $create, 'create-variation shares the rich get output schema.' );
		$this->assertSame( $get, $update, 'update-variation shares the rich get output schema.' );
	}

	/**
	 * WC1b delete: destructive, permanent via the WC data store.
	 */
	public function test_delete_variation_removes_it_permanently(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( WcStubStore::exists( 601 ) );

		$res = wp_get_ability( 'aafm/wc-delete-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertTrue( $res['deleted'] );
		$this->assertSame( 601, $res['id'] );

		// Gone — a following read finds nothing, and the parent no longer lists it.
		$this->assertFalse( WcStubStore::exists( 601 ) );
		$read = wp_get_ability( 'aafm/wc-get-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertInstanceOf( WP_Error::class, $read, 'A deleted variation can no longer be read.' );

		$list = wp_get_ability( 'aafm/wc-list-product-variations' )->execute( array( 'product_id' => 500 ) );
		$this->assertNotContains( 601, wp_list_pluck( $list['variations'], 'id' ), 'The parent no longer lists the deleted variation.' );
	}

	/**
	 * T2-3: a failed variation delete (the WC data store reports failure) returns the generic
	 * error, not deleted:true, and the variation is still present.
	 */
	public function test_delete_variation_store_failure_returns_error(): void {
		$this->acting_as( 'administrator' );

		WcStubStore::$delete_should_fail = true;
		$res                             = wp_get_ability( 'aafm/wc-delete-product-variation' )->execute( array( 'variation_id' => 601 ) );
		WcStubStore::$delete_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed variation delete must not report deleted:true.' );
		$this->assertTrue( WcStubStore::exists( 601 ), 'The variation must still exist after a failed delete.' );
	}

	public function test_delete_variation_is_annotated_destructive(): void {
		$annotations = wp_get_ability( 'aafm/wc-delete-product-variation' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['destructive'] ?? false, 'wc-delete-product-variation must be destructive.' );
		$this->assertFalse( $annotations['readonly'] ?? true, 'wc-delete-product-variation is not readonly.' );
	}

	public function test_delete_variation_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-product-variation' )->execute( array( 'variation_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_delete_variation_requires_variation_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-product-variation' )->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $res, 'variation_id is required on delete.' );
	}

	public function test_delete_variation_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-product-variation' )->check_permissions( array( 'variation_id' => 601 ) )
		);
	}

	public function test_delete_variation_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-product-variation' )->execute( array( 'variation_id' => 601 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-product-variation', $abilities );
	}

	/**
	 * Audit: a denied permission check is recorded, and the gate actually denies.
	 *
	 * @dataProvider provide_denied_audit_cases
	 *
	 * @param string               $ability  Ability name.
	 * @param array<string, mixed> $args     check_permissions args.
	 * @param string               $low_role Role that must be denied.
	 */
	public function test_denied_is_audited( string $ability, array $args, string $low_role ): void {
		$this->acting_as( $low_role );
		$this->assertNotTrue( wp_get_ability( $ability )->check_permissions( $args ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each variation write and the args its original denied audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function provide_denied_audit_cases(): array {
		return array(
			'create-product-variation' => array( 'aafm/wc-create-product-variation', array( 'product_id' => 500 ), 'editor' ),
			'delete-product-variation' => array( 'aafm/wc-delete-product-variation', array( 'variation_id' => 601 ), 'editor' ),
		);
	}
}
