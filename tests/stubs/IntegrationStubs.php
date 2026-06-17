<?php
/**
 * Shared host-API stub helpers for the Wave 4 integration tests.
 *
 * The DDEV site ships none of the five host plugins (Yoast / Rank Math / AIOSEO /
 * ACF / WooCommerce) and must stay that way, so every integration slice forces its
 * integration active through the per-slug filter and defines just the slice of the
 * host API its abilities call. This trait centralises that: force_integration()
 * flips the filter (and remembers it so tear-down removes it), and the per-host
 * helpers below define the minimal stubs a slice needs. Every class/function stub is
 * guarded so a second include in the same process never fatals.
 *
 * This file lives under tests/ and never ships; the source-scan security rails only
 * walk includes/, so nothing here is in their scope.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

/**
 * Host-API stub helpers, mixed into an integration slice's test case.
 */
trait IntegrationStubs {

	/**
	 * Slugs forced active during the current test, removed on tear-down.
	 *
	 * @var array<int,string>
	 */
	private array $aafm_forced_integrations = array();

	/**
	 * Force an integration active for the current test via its per-slug filter.
	 *
	 * @param string $slug One of 'seo' | 'acf' | 'woocommerce'.
	 * @return void
	 */
	protected function force_integration( string $slug ): void {
		add_filter( 'aafm_integration_active_' . $slug, '__return_true' );
		$this->aafm_forced_integrations[] = $slug;
	}

	/**
	 * Define the minimal SEO host surface for a given plugin so detection reports it
	 * active and aafm_seo_meta_keys() resolves the right key map.
	 *
	 * The SEO abilities read/write the mapped keys with core get_post_meta /
	 * update_post_meta, so a "stub" only needs the active-plugin signal — once the
	 * detection marker is defined, aafm_seo_active_plugin() returns this plugin and
	 * the production key map applies. No host classes or filter override are required.
	 *
	 * @param string $plugin 'yoast' | 'rankmath' | 'aioseo'.
	 * @return void
	 */
	protected function stub_seo_plugin( string $plugin ): void {
		switch ( $plugin ) {
			case 'yoast':
				if ( ! defined( 'WPSEO_VERSION' ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mimicking Yoast's own constant so detection sees it; a test stub, never shipped.
					define( 'WPSEO_VERSION', 'stub-test' );
				}
				break;
			case 'rankmath':
				if ( ! class_exists( 'RankMath' ) ) {
					eval( 'class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class-only marker stub for tests; never shipped.
				}
				break;
			case 'aioseo':
				if ( ! function_exists( 'aioseo' ) ) {
					eval( 'function aioseo() { return new \stdClass(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a function-only marker stub for tests; never shipped.
				}
				break;
		}
	}

	/**
	 * Define the minimal ACF host surface so detection reports ACF active and the ACF abilities
	 * can read field-group structure + hydrated values and record writes.
	 *
	 * ACF's functions are global and defined once per process, so the actual field/value state
	 * lives in a process-wide store (AcfStubStore) that this helper RESETS and seeds on every
	 * call. The guarded function definitions below read+write that store, so within a test a
	 * value written through update_field() is visible to a following get_field()/get_fields().
	 *
	 * Config shape (per the A1 plan):
	 *   array(
	 *     'groups' => array( array( 'key' => 'group_1', 'title' => 'Hero',
	 *                  'fields' => array( array( 'key' => 'field_1', 'label' => 'Headline', 'type' => 'text' ) ) ) ),
	 *     'values' => array( 'field_1' => 'Hello' ),  // seeds the current object's hydrated values.
	 *   )
	 *
	 * Defining get_field() makes real ACF detection true; the slice still forces the integration
	 * filter explicitly, and the host-inactive test drives the aafm_acf_active seam to false.
	 *
	 * @param array<string,mixed> $config Group + value seed.
	 * @return void
	 */
	protected function stub_acf( array $config ): void {
		AcfStubStore::reset();
		AcfStubStore::$groups = isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : array();
		$values               = isset( $config['values'] ) && is_array( $config['values'] ) ? $config['values'] : array();
		// Seed the seeded values under every object selector the test might read, plus the
		// "current object" bucket (selector '' / 0) ACF uses when no explicit id is given.
		AcfStubStore::$seed_values = $values;

		// Build the field-definition index (key => {key,label,type}) from the group fields so
		// acf_get_field() can resolve a field's type for type-aware sanitize.
		foreach ( AcfStubStore::$groups as $group ) {
			$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();
			foreach ( $fields as $field ) {
				if ( isset( $field['key'] ) ) {
					AcfStubStore::$field_defs[ (string) $field['key'] ] = $field;
				}
			}
		}

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_field_groups( $args = array() ) { return \AAFM\Tests\AcfStubStore::groups_without_fields(); }' );
		}
		if ( ! function_exists( 'acf_get_fields' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_fields( $group ) { return \AAFM\Tests\AcfStubStore::fields_for_group( $group ); }' );
		}
		if ( ! function_exists( 'acf_get_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_field( $key ) { return \AAFM\Tests\AcfStubStore::field_def( $key ); }' );
		}
		if ( ! function_exists( 'get_fields' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function get_fields( $selector = false ) { return \AAFM\Tests\AcfStubStore::all_values( $selector ); }' );
		}
		if ( ! function_exists( 'get_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function get_field( $selector, $post_id = false, $format = true ) { return \AAFM\Tests\AcfStubStore::value( $selector, $post_id ); }' );
		}
		if ( ! function_exists( 'update_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function update_field( $selector, $value, $post_id = false ) { return \AAFM\Tests\AcfStubStore::record( $selector, $value, $post_id ); }' );
		}
	}

	/**
	 * Seed the WcAttributeStubStore with the two default global attributes used across WC1c tests.
	 *
	 * Call this after stub_woocommerce() (which resets the attribute store via reset_integration_stubs)
	 * to ensure the attributes are seeded for each test that needs them.
	 *
	 * Seeded: id 1 = Color (pa_color, select), id 2 = Size (pa_size, select).
	 *
	 * @return void
	 */
	protected function seed_wc_attributes(): void {
		$color                    = new \stdClass();
		$color->attribute_id      = 1;
		$color->attribute_label   = 'Color';
		$color->attribute_name    = 'color';
		$color->attribute_type    = 'select';
		$color->attribute_orderby = 'menu_order';
		$color->attribute_public  = false;
		WcAttributeStubStore::seed( 1, $color );

		$size                    = new \stdClass();
		$size->attribute_id      = 2;
		$size->attribute_label   = 'Size';
		$size->attribute_name    = 'size';
		$size->attribute_type    = 'select';
		$size->attribute_orderby = 'menu_order';
		$size->attribute_public  = false;
		WcAttributeStubStore::seed( 2, $size );
	}

	/**
	 * Define the minimal WooCommerce host surface so detection reports WooCommerce active and the
	 * product abilities can list/read/create/update/delete through the WC CRUD layer.
	 *
	 * WooCommerce's wc_get_product/wc_get_products are global and the WC_Product class is declared
	 * once per process, so the actual product state lives in a process-wide store (WcStubStore) that
	 * this helper RESETS and seeds on every call. The guarded definitions below read+write that
	 * store, so within a test a product created/updated through ->save() is visible to a following
	 * wc_get_product()/wc_get_products(), and ->delete(true) removes it.
	 *
	 * Defining the WooCommerce marker class makes real WC detection true; the slice still forces the
	 * integration filter explicitly, and the host-inactive test drives the aafm_woocommerce_active
	 * seam to false.
	 *
	 * @param array<int,array<string,mixed>> $products Seed products (each an id => data array, or a
	 *                                                 data array carrying its own 'id'). Defaults to
	 *                                                 one simple product id 101.
	 * @return void
	 */
	protected function stub_woocommerce( array $products = array() ): void {
		WcStubStore::reset();
		WcAttributeStubStore::reset();

		// WooCommerce grants administrators (and shop managers) the manage_woocommerce capability on
		// activation; the stock WP administrator role does not carry it. Mirror that here so an admin
		// can exercise the product abilities while an editor still genuinely lacks the cap. The role
		// option write is rolled back by the transaction-isolated fixture, so live state is untouched.
		$admin = get_role( 'administrator' );
		if ( null !== $admin && ! $admin->has_cap( 'manage_woocommerce' ) ) {
			$admin->add_cap( 'manage_woocommerce' );
		}

		if ( array() === $products ) {
			$products = array(
				array(
					'id'           => 101,
					'name'         => 'Test Widget',
					'sku'          => 'WIDGET-101',
					'price'        => '19.99',
					'status'       => 'publish',
					'stock_status' => 'instock',
				),
			);
		}
		foreach ( $products as $product ) {
			$product = (array) $product;
			WcStubStore::seed( (int) ( $product['id'] ?? WcStubStore::$next_id ), $product );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class-only marker stub for tests; never shipped.
		}
		if ( ! class_exists( 'WC_Product' ) ) {
			// A minimal WC_Product backed by WcStubStore: getters read the stored data, setters stage
			// changes on the instance, save() persists, delete() removes. Only the methods the product
			// abilities call are implemented. A test-only stub, never shipped.
			eval( $this->aafm_wc_product_class_source() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
		}
		if ( ! class_exists( 'WC_Product_Variation' ) ) {
			// A minimal WC_Product_Variation backed by the same WcStubStore (a variation is a product
			// row carrying type='variation' and a parent_id). Its get_attributes() returns the flat
			// name=>value map a real variation exposes (NOT the WC_Product_Attribute objects a variable
			// parent returns). Defined after WC_Product so the parent class exists. A test-only stub.
			eval( $this->aafm_wc_variation_class_source() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			// Fix Code MED-1: return a WC_Product_Variation when the stored row is a variation
			// (type=variation), else a base WC_Product — mirroring real WooCommerce, so
			// aafm_wc_get_variation()'s instanceof WC_Product_Variation branch is the exercised path.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_product( $id = false ) { $id = (int) $id; if ( ! \AAFM\Tests\WcStubStore::exists( $id ) ) { return false; } $row = \AAFM\Tests\WcStubStore::get( $id ); return ( "variation" === ( $row["type"] ?? "" ) ) ? new \WC_Product_Variation( $id ) : new \WC_Product( $id ); }' );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_products( $args = array() ) { return \AAFM\Tests\WcStubStore::query( $args ); }' );
		}

		// Global product attribute (taxonomy) stubs (W4-WC1c). Each mirrors the real WC function's
		// signature and delegates to WcAttributeStubStore, which holds a stdClass row per attribute
		// using the real WC field names (attribute_id / attribute_name / attribute_label / etc.).
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_attribute_taxonomies() { return \AAFM\Tests\WcAttributeStubStore::all(); }' );
		}
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_create_attribute( $args ) { $id = \AAFM\Tests\WcAttributeStubStore::create( (array) $args ); return ( $id > 0 ) ? $id : new \WP_Error( "wc_attribute_create_failed", "Create failed." ); }' );
		}
		if ( ! function_exists( 'wc_update_attribute' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_update_attribute( $id, $args ) { $ok = \AAFM\Tests\WcAttributeStubStore::update( (int) $id, (array) $args ); return $ok ? (int) $id : new \WP_Error( "wc_attribute_update_failed", "Update failed." ); }' );
		}
		if ( ! function_exists( 'wc_delete_attribute' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_delete_attribute( $id ) { return \AAFM\Tests\WcAttributeStubStore::delete( (int) $id ); }' );
		}
		if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_attribute_taxonomy_name( $name ) { return "pa_" . $name; }' );
		}
		if ( ! function_exists( 'wc_sanitize_taxonomy_name' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_sanitize_taxonomy_name( $name ) { return sanitize_title( $name ); }' );
		}
	}

	/**
	 * The source of the stub WC_Product class. Kept as a string so the eval definition is guarded by
	 * class_exists and the trait file still holds exactly one object structure (the trait).
	 *
	 * @return string
	 */
	private function aafm_wc_product_class_source(): string {
		return <<<'PHP'
class WC_Product {
	private $data = array();
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		$stored = \AAFM\Tests\WcStubStore::get( $id );
		$this->data = is_array( $stored ) ? $stored : array( 'id' => 0 );
	}
	public function get_id() { return (int) ( $this->data['id'] ?? 0 ); }
	public function get_name() { return (string) ( $this->data['name'] ?? '' ); }
	public function get_type() { return (string) ( $this->data['type'] ?? 'simple' ); }
	public function get_status() { return (string) ( $this->data['status'] ?? 'publish' ); }
	public function get_sku() { return (string) ( $this->data['sku'] ?? '' ); }
	public function get_description() { return (string) ( $this->data['description'] ?? '' ); }
	public function get_short_description() { return (string) ( $this->data['short_description'] ?? '' ); }
	public function get_price() { return (string) ( $this->data['price'] ?? '' ); }
	public function get_regular_price() { return (string) ( $this->data['regular_price'] ?? '' ); }
	public function get_sale_price() { return (string) ( $this->data['sale_price'] ?? '' ); }
	public function get_stock_status() { return (string) ( $this->data['stock_status'] ?? 'instock' ); }
	public function get_stock_quantity() { return $this->data['stock_quantity'] ?? null; }
	public function get_manage_stock() { return (bool) ( $this->data['manage_stock'] ?? false ); }
	public function get_featured() { return (bool) ( $this->data['featured'] ?? false ); }
	public function get_category_ids() { return (array) ( $this->data['category_ids'] ?? array() ); }
	public function get_tag_ids() { return (array) ( $this->data['tag_ids'] ?? array() ); }
	public function get_gallery_image_ids() { return (array) ( $this->data['gallery_image_ids'] ?? array() ); }
	public function get_image_id() { return (int) ( $this->data['image_id'] ?? 0 ); }
	public function get_attributes() { return (array) ( $this->data['attributes'] ?? array() ); }
	public function get_children() { return (array) ( $this->data['children'] ?? array() ); }
	public function set_name( $v ) { $this->data['name'] = (string) $v; }
	public function set_status( $v ) { $this->data['status'] = (string) $v; }
	public function set_sku( $v ) { $this->data['sku'] = (string) $v; }
	public function set_description( $v ) { $this->data['description'] = (string) $v; }
	public function set_short_description( $v ) { $this->data['short_description'] = (string) $v; }
	public function set_regular_price( $v ) { $this->data['regular_price'] = (string) $v; $this->data['price'] = (string) $v; } // price tracks regular only (sale price never mirrors here).
	public function set_sale_price( $v ) { $this->data['sale_price'] = (string) $v; }
	public function set_price( $v ) { $this->data['price'] = (string) $v; }
	public function set_stock_status( $v ) { $this->data['stock_status'] = (string) $v; }
	public function set_stock_quantity( $v ) { $this->data['stock_quantity'] = ( null === $v ? null : (int) $v ); }
	public function set_manage_stock( $v ) { $this->data['manage_stock'] = (bool) $v; }
	public function set_featured( $v ) { $this->data['featured'] = (bool) $v; }
	public function set_category_ids( $v ) { $this->data['category_ids'] = array_map( 'intval', (array) $v ); }
	public function set_tag_ids( $v ) { $this->data['tag_ids'] = array_map( 'intval', (array) $v ); }
	public function set_gallery_image_ids( $v ) { $this->data['gallery_image_ids'] = array_map( 'intval', (array) $v ); }
	public function set_image_id( $v ) { $this->data['image_id'] = (int) $v; }
	public function set_attributes( $v ) { $this->data['attributes'] = (array) $v; }
	public function save() { $id = \AAFM\Tests\WcStubStore::save( $this->data ); $this->data['id'] = $id; return $id; }
	public function delete( $force = false ) { \AAFM\Tests\WcStubStore::delete( (int) ( $this->data['id'] ?? 0 ) ); return true; }
}
PHP;
	}

	/**
	 * The source of the stub WC_Product_Variation class. Kept as a string so the eval definition is
	 * guarded by class_exists and the trait file still holds exactly one object structure (the trait).
	 *
	 * A variation is a product row with type='variation' and a parent_id; its attributes are a flat
	 * name=>value map (the variation's chosen values), unlike a variable parent's attribute objects.
	 *
	 * @return string
	 */
	private function aafm_wc_variation_class_source(): string {
		return <<<'PHP'
class WC_Product_Variation {
	private $data = array();
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		$stored = \AAFM\Tests\WcStubStore::get( $id );
		$this->data = is_array( $stored ) ? $stored : array( 'id' => 0, 'type' => 'variation' );
	}
	public function get_id() { return (int) ( $this->data['id'] ?? 0 ); }
	public function get_parent_id() { return (int) ( $this->data['parent_id'] ?? 0 ); }
	public function get_type() { return 'variation'; }
	public function get_status() { return (string) ( $this->data['status'] ?? 'publish' ); }
	public function get_sku() { return (string) ( $this->data['sku'] ?? '' ); }
	public function get_description() { return (string) ( $this->data['description'] ?? '' ); }
	public function get_price() { return (string) ( $this->data['price'] ?? '' ); }
	public function get_regular_price() { return (string) ( $this->data['regular_price'] ?? '' ); }
	public function get_sale_price() { return (string) ( $this->data['sale_price'] ?? '' ); }
	public function get_stock_status() { return (string) ( $this->data['stock_status'] ?? 'instock' ); }
	public function get_stock_quantity() { return $this->data['stock_quantity'] ?? null; }
	public function get_manage_stock() { return (bool) ( $this->data['manage_stock'] ?? false ); }
	public function get_image_id() { return (int) ( $this->data['image_id'] ?? 0 ); }
	public function get_attributes() { return (array) ( $this->data['attributes'] ?? array() ); }
	public function set_parent_id( $v ) { $this->data['parent_id'] = (int) $v; }
	public function set_status( $v ) { $this->data['status'] = (string) $v; }
	public function set_sku( $v ) { $this->data['sku'] = (string) $v; }
	public function set_description( $v ) { $this->data['description'] = (string) $v; }
	public function set_regular_price( $v ) { $this->data['regular_price'] = (string) $v; $this->data['price'] = (string) $v; } // price tracks regular only.
	public function set_sale_price( $v ) { $this->data['sale_price'] = (string) $v; }
	public function set_price( $v ) { $this->data['price'] = (string) $v; }
	public function set_stock_status( $v ) { $this->data['stock_status'] = (string) $v; }
	public function set_stock_quantity( $v ) { $this->data['stock_quantity'] = ( null === $v ? null : (int) $v ); }
	public function set_manage_stock( $v ) { $this->data['manage_stock'] = (bool) $v; }
	public function set_image_id( $v ) { $this->data['image_id'] = (int) $v; }
	public function set_attributes( $v ) { $this->data['attributes'] = (array) $v; }
	public function save() { $this->data['type'] = 'variation'; $id = \AAFM\Tests\WcStubStore::save( $this->data ); $this->data['id'] = $id; return $id; }
	public function delete( $force = false ) { \AAFM\Tests\WcStubStore::delete( (int) ( $this->data['id'] ?? 0 ) ); return true; }
}
PHP;
	}

	/**
	 * Remove every filter this trait added. Call from the slice's tear_down().
	 *
	 * @return void
	 */
	protected function reset_integration_stubs(): void {
		foreach ( $this->aafm_forced_integrations as $slug ) {
			remove_filter( 'aafm_integration_active_' . $slug, '__return_true' );
		}
		$this->aafm_forced_integrations = array();
		AcfStubStore::reset();
		WcStubStore::reset();
		WcAttributeStubStore::reset();
	}
}
