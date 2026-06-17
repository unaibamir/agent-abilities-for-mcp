<?php
/**
 * WooCommerce product abilities (sub-slice W4-WC1a).
 *
 * The DDEV site ships no WooCommerce host plugin, so each test forces the integration active through
 * its per-slug filter and defines the minimal WooCommerce host surface via stub_woocommerce() (the
 * IntegrationStubs trait). The abilities list/read/create/update/delete through the WC CRUD layer
 * (wc_get_products / wc_get_product / WC_Product), all served by the WcStubStore.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class WooProductsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_products();
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
	 * Enable + register the WooCommerce product set so the abilities can be invoked.
	 */
	private function register_wc_products(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-products',
				'aafm/wc-get-product',
				'aafm/wc-create-product',
				'aafm/wc-update-product',
				'aafm/wc-delete-product',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_list_products_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/wc-list-products' )->check_permissions( array() ) );

		$this->acting_as( 'administrator' ); // admin has manage_woocommerce.
		$res = wp_get_ability( 'aafm/wc-list-products' )->execute( array() );
		$this->assertArrayHasKey( 'products', $res );
		$this->assertArrayHasKey( 'total', $res );
		$this->assertSame( 1, $res['total'] );
		$this->assertSame( 101, $res['products'][0]['id'] );
		$this->assertSame( 'Test Widget', $res['products'][0]['name'] );
		$this->assertSame( 'WIDGET-101', $res['products'][0]['sku'] );
		$this->assertArrayNotHasKey( 'description', $res['products'][0], 'list rows are lean.' );
	}

	public function test_list_products_denies_a_subscriber(): void {
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/wc-list-products' )->check_permissions( array() ) );
	}

	public function test_get_product_returns_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-product' )->execute( array( 'product_id' => 101 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 101, $res['id'] );
		$this->assertSame( 'Test Widget', $res['name'] );
		$this->assertArrayHasKey( 'description', $res );
		$this->assertArrayHasKey( 'attributes', $res );
		$this->assertArrayHasKey( 'images', $res );
		$this->assertArrayHasKey( 'variation_ids', $res );
		$this->assertArrayHasKey( 'categories', $res );
	}

	public function test_get_product_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-product' )->execute( array( 'product_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_product_denies_a_subscriber(): void {
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-product' )->check_permissions( array( 'product_id' => 101 ) )
		);
	}

	public function test_get_product_empty_attributes_encodes_as_object_not_array(): void {
		// The attributes map is type:object; an empty one must JSON-encode to "{}" (object), never
		// "[]" (list), per the get-all-post-meta / ACF empty-map lesson.
		$this->acting_as( 'administrator' );
		$res  = wp_get_ability( 'aafm/wc-get-product' )->execute( array( 'product_id' => 101 ) );
		$json = (string) wp_json_encode( $res['attributes'] );
		$this->assertSame( '{}', $json, 'Empty product attributes must encode as {}.' );
	}

	public function test_get_product_output_schema_declares_every_emitted_field(): void {
		// The output_schema must match the full rich shape the executor returns, not a subset. Every
		// key in the emitted result must be a declared property (get/create/update share one builder).
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-product' )->execute( array( 'product_id' => 101 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$schema   = aafm_args_wc_get_product()['output_schema'];
		$declared = array_keys( $schema['properties'] );

		foreach ( array_keys( $res ) as $emitted_key ) {
			$this->assertContains(
				$emitted_key,
				$declared,
				sprintf( 'Emitted field "%s" must be declared in the get-product output_schema.', $emitted_key )
			);
		}

		// Spot-check the rich fields the old schema omitted are now present.
		foreach ( array( 'sku', 'price', 'status', 'regular_price', 'sale_price', 'stock_quantity', 'tags', 'image_id', 'type', 'description' ) as $rich_field ) {
			$this->assertContains( $rich_field, $declared, sprintf( 'The schema lists the rich field "%s".', $rich_field ) );
		}
	}

	public function test_create_and_update_share_the_get_output_schema(): void {
		// All three product-returning abilities expose the same rich output shape.
		$get    = aafm_args_wc_get_product()['output_schema']['properties'];
		$create = aafm_args_wc_create_product()['output_schema']['properties'];
		$update = aafm_args_wc_update_product()['output_schema']['properties'];

		$this->assertSame( $get, $create, 'create-product shares the rich get output schema.' );
		$this->assertSame( $get, $update, 'update-product shares the rich get output schema.' );
	}

	public function test_wc_abilities_absent_when_host_inactive(): void {
		// HIGH-2: assert at the REGISTRY level (not via aafm_user_can_discover_ability, which leaks
		// through the process-wide raw-permission $store once any test registered the set). The
		// stub_woocommerce() helper defines class WooCommerce process-wide, so real detection still
		// reports WC active after removing the force filter — pin it off through aafm_woocommerce_active.
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );
		$this->assertArrayNotHasKey( 'aafm/wc-list-products', aafm_get_abilities_registry() );
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	public function test_list_products_discovery_admin_yes_editor_no(): void {
		// The WC abilities gate on the object-independent manage_woocommerce cap, so they fall through
		// to their real permission_callback at discovery (no server.php case). An admin discovers them;
		// an editor (no manage_woocommerce) does not.
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/wc-list-products' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/wc-list-products' ) );
	}

	/**
	 * WC1.2: create + update writes.
	 */
	public function test_create_product_persists_and_returns_the_full_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute(
			array(
				'name'          => 'New Gadget',
				'type'          => 'simple',
				'status'        => 'publish',
				'description'   => 'A useful gadget.',
				'regular_price' => '9.50',
				'sku'           => 'GADGET-1',
				'stock_status'  => 'instock',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'New Gadget', $res['name'] );
		$this->assertSame( 'GADGET-1', $res['sku'] );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertGreaterThan( 0, $res['id'] );

		// The product is now readable through the store.
		$this->assertTrue( \AAFM\Tests\WcStubStore::exists( (int) $res['id'] ) );
	}

	public function test_create_product_sanitizes_description_and_name(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute(
			array(
				'name'        => '<script>alert(1)</script>Clean Name',
				'description' => '<script>alert(2)</script><strong>bold</strong>',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertStringNotContainsString( '<script>', $res['name'], 'The name is flattened to text.' );
		$this->assertStringNotContainsString( '<script>', $res['description'], 'The description drops scripts.' );
		$this->assertStringContainsString( '<strong>', $res['description'], 'The description keeps benign markup.' );
	}

	public function test_create_product_requires_name(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute( array( 'sku' => 'NO-NAME' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'name is required on create.' );
	}

	public function test_create_product_rejects_a_smuggled_top_level_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute(
			array(
				'name'        => 'Sneaky',
				'post_author' => 999999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled top-level field.' );
	}

	public function test_create_product_rejects_a_smuggled_nested_attribute_field(): void {
		// MEDIUM-4: every nested object in a write schema is itself additionalProperties:false. A
		// smuggled key INSIDE an attributes item must be rejected, not just a top-level smuggle.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute(
			array(
				'name'       => 'Has Attributes',
				'attributes' => array(
					array(
						'name'       => 'Color',
						'options'    => array( 'Red', 'Blue' ),
						'evil_field' => 'x',
					),
				),
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'A smuggled key inside an attributes item must be rejected by the nested closed schema.'
		);
	}

	public function test_create_product_accepts_a_clean_nested_attribute(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute(
			array(
				'name'       => 'Clean Attributes',
				'attributes' => array(
					array(
						'name'    => 'Size',
						'options' => array( 'S', 'M', 'L' ),
					),
				),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A clean nested attribute must be accepted.' );
	}

	public function test_create_product_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-product' )->check_permissions( array( 'name' => 'X' ) )
		);
	}

	public function test_update_product_patches_by_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product' )->execute(
			array(
				'product_id' => 101,
				'name'       => 'Renamed Widget',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Renamed Widget', $res['name'] );
		// Untouched fields survive the PATCH.
		$this->assertSame( 'WIDGET-101', $res['sku'], 'A PATCH leaves unsent fields intact.' );

		$read = wp_get_ability( 'aafm/wc-get-product' )->execute( array( 'product_id' => 101 ) );
		$this->assertSame( 'Renamed Widget', $read['name'], 'The update must round-trip.' );
	}

	public function test_update_product_requires_product_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product' )->execute( array( 'name' => 'No id' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'product_id is required on update.' );
	}

	public function test_update_product_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product' )->execute(
			array(
				'product_id' => 999999,
				'name'       => 'Ghost',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_update_product_rejects_a_smuggled_nested_attribute_field(): void {
		// MEDIUM-4 on the update schema too.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product' )->execute(
			array(
				'product_id' => 101,
				'attributes' => array(
					array(
						'name'       => 'Color',
						'options'    => array( 'Red' ),
						'evil_field' => 'x',
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A smuggled nested attribute key must be rejected on update too.' );
	}

	public function test_update_product_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-product' )->check_permissions( array( 'product_id' => 101 ) )
		);
	}

	public function test_create_product_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product' )->execute( array( 'name' => 'Audited Create' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-product', $abilities );
	}

	public function test_create_product_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-product' )->check_permissions( array( 'name' => 'X' ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-product', $abilities );
	}

	public function test_update_product_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product' )->execute(
			array(
				'product_id' => 101,
				'name'       => 'Audited Update',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-product', $abilities );
	}

	public function test_update_product_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-product' )->check_permissions( array( 'product_id' => 101 ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-update-product', $abilities );
	}

	/**
	 * WC1.3: delete (destructive, permanent via the WC data store).
	 */
	public function test_delete_product_removes_it_permanently(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( \AAFM\Tests\WcStubStore::exists( 101 ) );

		$res = wp_get_ability( 'aafm/wc-delete-product' )->execute( array( 'product_id' => 101 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertTrue( $res['deleted'] );
		$this->assertSame( 101, $res['id'] );

		// Gone — a following read finds nothing.
		$this->assertFalse( \AAFM\Tests\WcStubStore::exists( 101 ) );
		$read = wp_get_ability( 'aafm/wc-get-product' )->execute( array( 'product_id' => 101 ) );
		$this->assertInstanceOf( WP_Error::class, $read, 'A deleted product can no longer be read.' );
	}

	public function test_delete_product_is_annotated_destructive(): void {
		$annotations = wp_get_ability( 'aafm/wc-delete-product' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['destructive'] ?? false, 'wc-delete-product must be destructive.' );
		$this->assertFalse( $annotations['readonly'] ?? true, 'wc-delete-product is not readonly.' );
	}

	public function test_delete_product_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-product' )->execute( array( 'product_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_delete_product_requires_product_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-product' )->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $res, 'product_id is required on delete.' );
	}

	public function test_delete_product_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-product' )->check_permissions( array( 'product_id' => 101 ) )
		);
	}

	public function test_delete_product_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-product' )->execute( array( 'product_id' => 101 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-product', $abilities );
	}

	public function test_delete_product_denied_is_audited(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-product' )->check_permissions( array( 'product_id' => 101 ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-product', $abilities );
	}
}
