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

namespace Oversio\Tests;

/**
 * Host-API stub helpers, mixed into an integration slice's test case.
 */
trait IntegrationStubs {

	/**
	 * Slugs forced active during the current test, removed on tear-down.
	 *
	 * @var array<int,string>
	 */
	private array $oversio_forced_integrations = array();

	/**
	 * Force an integration active for the current test via its per-slug filter.
	 *
	 * @param string $slug One of 'yoast' | 'rankmath' | 'aioseo' | 'acf' | 'woocommerce'.
	 * @return void
	 */
	protected function force_integration( string $slug ): void {
		add_filter( 'oversio_integration_active_' . $slug, '__return_true' );
		$this->oversio_forced_integrations[] = $slug;
	}

	/**
	 * Define the minimal Yoast host surface: the WPSEO_VERSION constant so detection reports Yoast
	 * active, plus a rendered-head filter so the yoast-get-head ability returns a non-empty string.
	 *
	 * @return void
	 */
	protected function stub_yoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mimicking Yoast's own constant so detection sees it; a test stub, never shipped.
			define( 'WPSEO_VERSION', 'stub-test' );
		}
		add_filter(
			'oversio_seo_rendered_head',
			static fn( string $head, int $id, string $plugin ): string => 'yoast' === $plugin ? '<title>Yoast head ' . $id . '</title>' : $head,
			10,
			3
		);
	}

	/**
	 * Define the minimal Rank Math host surface: the RankMath marker class so detection reports Rank
	 * Math active, plus a rendered-head filter so the rankmath-get-head ability returns a non-empty
	 * string. The rank_math_* post meta (incl. the serialized robots array and the dynamic schema
	 * keys) is read/written with core get_post_meta/update_post_meta, so no extra store is needed.
	 *
	 * @return void
	 */
	protected function stub_rankmath(): void {
		if ( ! class_exists( 'RankMath' ) ) {
			eval( 'class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class-only marker stub for tests; never shipped.
		}
		add_filter(
			'oversio_seo_rendered_head',
			static fn( string $head, int $id, string $plugin ): string => 'rankmath' === $plugin ? '<title>Rank Math head ' . $id . '</title>' : $head,
			10,
			3
		);
	}

	/**
	 * Define the minimal AIOSEO host surface: the aioseo() marker function so detection reports AIOSEO
	 * active, a stateful AIOSEO\Plugin\Common\Models\Post model backed by AioseoStubStore (so a write
	 * through getPost->set->save round-trips on a following getPost — the real-model code path), and a
	 * rendered-head filter so the aioseo-get-head ability returns a non-empty string.
	 *
	 * @return void
	 */
	protected function stub_aioseo(): void {
		AioseoStubStore::reset();
		if ( ! function_exists( 'aioseo' ) ) {
			eval( 'function aioseo() { return new \stdClass(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a function-only marker stub for tests; never shipped.
		}
		if ( ! class_exists( 'AIOSEO\\Plugin\\Common\\Models\\Post' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_aioseo_post_model_source() );
		}
		add_filter(
			'oversio_seo_rendered_head',
			static fn( string $head, int $id, string $plugin ): string => 'aioseo' === $plugin ? '<title>AIOSEO head ' . $id . '</title>' : $head,
			10,
			3
		);
	}

	/**
	 * Source of the stub AIOSEO\Plugin\Common\Models\Post model. Kept as a string so the eval is
	 * guarded by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * The static getPost($id) returns a model populated from AioseoStubStore (a blank-defaults
	 * instance when no row exists, mirroring real AIOSEO). Public props mirror the wp_aioseo_posts
	 * columns the ability touches. save() writes the props back to the store. A test-only stub.
	 *
	 * @return string
	 */
	private function oversio_aioseo_post_model_source(): string {
		return <<<'PHP'
namespace AIOSEO\Plugin\Common\Models;
class Post {
	public $post_id = 0;
	public $title = '';
	public $description = '';
	public $canonical_url = '';
	public $og_title = '';
	public $og_description = '';
	public $og_image_custom_url = '';
	public $twitter_title = '';
	public $twitter_description = '';
	public $twitter_image_custom_url = '';
	public $robots_noindex = false;
	public $robots_nofollow = false;
	public $robots_default = true;
	public static function getPost( $post_id ) {
		$row = \Oversio\Tests\AioseoStubStore::get( (int) $post_id );
		$model = new self();
		foreach ( $row as $k => $v ) {
			if ( property_exists( $model, $k ) ) {
				$model->$k = $v;
			}
		}
		$model->post_id = (int) $post_id;
		return $model;
	}
	public function save() {
		if ( \Oversio\Tests\AioseoStubStore::$save_should_fail ) {
			return false;
		}
		\Oversio\Tests\AioseoStubStore::save( (int) $this->post_id, get_object_vars( $this ) );
		return true;
	}
}
PHP;
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
	 * filter explicitly, and the host-inactive test drives the oversio_acf_active seam to false.
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
			eval( 'function acf_get_field_groups( $args = array() ) { return \Oversio\Tests\AcfStubStore::groups_without_fields(); }' );
		}
		if ( ! function_exists( 'acf_get_fields' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_fields( $group ) { return \Oversio\Tests\AcfStubStore::fields_for_group( $group ); }' );
		}
		if ( ! function_exists( 'acf_get_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_field( $key ) { return \Oversio\Tests\AcfStubStore::field_def( $key ); }' );
		}
		if ( ! function_exists( 'get_fields' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function get_fields( $selector = false ) { return \Oversio\Tests\AcfStubStore::all_values( $selector ); }' );
		}
		if ( ! function_exists( 'get_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function get_field( $selector, $post_id = false, $format = true ) { return $format ? \Oversio\Tests\AcfStubStore::value_formatted( $selector, $post_id ) : \Oversio\Tests\AcfStubStore::value( $selector, $post_id ); }' );
		}
		if ( ! function_exists( 'update_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function update_field( $selector, $value, $post_id = false ) { return \Oversio\Tests\AcfStubStore::record( $selector, $value, $post_id ); }' );
		}
	}

	/**
	 * Seed the WcAttributeStubStore with the two default global attributes used across WC1c tests.
	 *
	 * Call this after stub_woocommerce(), which resets the attribute store at the top of its own
	 * body (via WcAttributeStubStore::reset()), to ensure the attributes are seeded for each test
	 * that needs them.
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
	 * integration filter explicitly, and the host-inactive test drives the oversio_woocommerce_active
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
			eval( $this->oversio_wc_product_class_source() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
		}
		if ( ! class_exists( 'WC_Product_Variation' ) ) {
			// A minimal WC_Product_Variation backed by the same WcStubStore (a variation is a product
			// row carrying type='variation' and a parent_id). Its get_attributes() returns the flat
			// name=>value map a real variation exposes (NOT the WC_Product_Attribute objects a variable
			// parent returns). Defined after WC_Product so the parent class exists. A test-only stub.
			eval( $this->oversio_wc_variation_class_source() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			// Fix Code MED-1: return a WC_Product_Variation when the stored row is a variation
			// (type=variation), else a base WC_Product — mirroring real WooCommerce, so
			// oversio_wc_get_variation()'s instanceof WC_Product_Variation branch is the exercised path.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_product( $id = false ) { $id = (int) $id; if ( ! \Oversio\Tests\WcStubStore::exists( $id ) ) { return false; } $row = \Oversio\Tests\WcStubStore::get( $id ); return ( "variation" === ( $row["type"] ?? "" ) ) ? new \WC_Product_Variation( $id ) : new \WC_Product( $id ); }' );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_products( $args = array() ) { return \Oversio\Tests\WcStubStore::query( $args ); }' );
		}

		// Global product attribute (taxonomy) stubs (W4-WC1c). Each mirrors the real WC function's
		// signature and delegates to WcAttributeStubStore, which holds a stdClass row per attribute
		// using the real WC field names (attribute_id / attribute_name / attribute_label / etc.).
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_attribute_taxonomies() { return \Oversio\Tests\WcAttributeStubStore::all(); }' );
		}
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_create_attribute( $args ) { $id = \Oversio\Tests\WcAttributeStubStore::create( (array) $args ); return ( $id > 0 ) ? $id : new \WP_Error( "wc_attribute_create_failed", "Create failed." ); }' );
		}
		if ( ! function_exists( 'wc_update_attribute' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_update_attribute( $id, $args ) { $ok = \Oversio\Tests\WcAttributeStubStore::update( (int) $id, (array) $args ); return $ok ? (int) $id : new \WP_Error( "wc_attribute_update_failed", "Update failed." ); }' );
		}
		if ( ! function_exists( 'wc_delete_attribute' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_delete_attribute( $id ) { return \Oversio\Tests\WcAttributeStubStore::delete( (int) $id ); }' );
		}
		if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_attribute_taxonomy_name( $name ) { return "pa_" . $name; }' );
		}
		if ( ! function_exists( 'wc_sanitize_taxonomy_name' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_sanitize_taxonomy_name( $name ) { return sanitize_title( $name ); }' );
		}

		// Order stubs (W4-WC2). wc_get_orders() and wc_get_order() (the order variant, different from
		// the product variant of the same function name) delegate to WcOrderStubStore. The WC_Order
		// class is defined via eval so the class_exists guard prevents double-define across tests.
		if ( ! class_exists( 'WC_Order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_order_class_source() );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_orders( $args = array() ) { return \Oversio\Tests\WcOrderStubStore::query( $args ); }' );
		}
		// NOTE: wc_get_order() is also defined above for products (returns WC_Product). WooCommerce
		// uses the same function name for both — in real WC, wc_get_order() returns a WC_Order when
		// the post type is shop_order. Since the stubs are process-wide, if wc_get_product() has
		// already defined wc_get_order for products (it hasn't — wc_get_order is NOT the same as
		// wc_get_product), we define wc_get_order separately here. The product stubs use
		// wc_get_product(); orders use wc_get_order().
		if ( ! function_exists( 'wc_get_order' ) ) {
			// Returns WC_Order_Refund when the id is in the refund map, WC_Order when it is a
			// regular order, or false when the id is unknown. Mirrors real WooCommerce behaviour
			// where shop_order_refund posts return WC_Order_Refund from wc_get_order().
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_order( $id = false ) { $id = (int) $id; if ( null !== \Oversio\Tests\WcOrderStubStore::get_refund_by_id( $id ) ) { return new \WC_Order_Refund( $id ); } if ( ! \Oversio\Tests\WcOrderStubStore::exists( $id ) ) { return false; } return new \WC_Order( $id ); }' );
		}
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_order_statuses() { return array( "wc-pending" => "Pending", "wc-processing" => "Processing", "wc-on-hold" => "On hold", "wc-completed" => "Completed", "wc-cancelled" => "Cancelled", "wc-refunded" => "Refunded", "wc-failed" => "Failed" ); }' );
		}

		// Note and refund stubs (W4-WC2.3). These delegate entirely to WcOrderStubStore so tests
		// can seed notes/refunds per test and assert round-trip reads/creates/deletes without WC.
		if ( ! class_exists( 'WC_Order_Refund' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_order_refund_class_source() );
		}
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_order_notes( $args = array() ) { $order_id = isset( $args["order_id"] ) ? (int) $args["order_id"] : 0; return \Oversio\Tests\WcOrderStubStore::get_notes( $order_id ); }' );
		}
		if ( ! function_exists( 'wc_delete_order_note' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_delete_order_note( $note_id ) { return \Oversio\Tests\WcOrderStubStore::delete_note( (int) $note_id ); }' );
		}
		if ( ! function_exists( 'wc_create_refund' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_create_refund( $args = array() ) { $order_id = isset( $args["order_id"] ) ? (int) $args["order_id"] : 0; $amount = isset( $args["amount"] ) ? (string) $args["amount"] : "0.00"; $reason = isset( $args["reason"] ) ? (string) $args["reason"] : ""; if ( ! \Oversio\Tests\WcOrderStubStore::exists( $order_id ) ) { return new \WP_Error( "wc_create_refund_failed", "Order not found." ); } return \Oversio\Tests\WcOrderStubStore::add_refund( $order_id, $amount, $reason ); }' );
		}

		// Customer stubs (W4-WC3). WC_Customer is defined via eval so class_exists prevents
		// double-define across tests. The customer abilities hydrate via `new WC_Customer( $id )` and
		// validate with get_id() (mirroring real WooCommerce, which has NO wc_get_customer() helper),
		// so the WC_Customer stub constructor leaves data['id'] = 0 for unknown ids. The delete path
		// is intentionally NOT stubbed here: the wc-delete-customer executor calls wp_delete_user() on
		// a real WP user (same as the delete-user ability), so delete tests create real WP users.
		if ( ! class_exists( 'WC_Customer' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_customer_class_source() );
		}
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			// Mirrors the real WooCommerce wc_create_new_customer() signature.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_create_new_customer( $email, $username = "", $password = "", $args = array() ) { $c = new \WC_Customer(); $c->set_email( $email ); $c->set_username( $username ); $id = $c->save( true ); if ( ! $id ) { return new \WP_Error( "registration-error", "Create failed." ); } return (int) $id; }' );
		}
		if ( ! function_exists( 'wc_update_customer' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_update_customer( $id, $args = array() ) { $id = (int) $id; if ( ! \Oversio\Tests\WcCustomerStubStore::exists( $id ) ) { return new \WP_Error( "wc_update_customer_failed", "Customer not found." ); } $c = new \WC_Customer( $id ); if ( isset( $args["email"] ) ) { $c->set_email( $args["email"] ); } if ( isset( $args["first_name"] ) ) { $c->set_first_name( $args["first_name"] ); } if ( isset( $args["last_name"] ) ) { $c->set_last_name( $args["last_name"] ); } $c->save( false ); return new \WC_Customer( $id ); }' );
		}
		if ( ! function_exists( 'wc_get_customers' ) ) {
			// Returns WC_Customer objects from the stub store, honoring limit/paged args.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_customers( $args = array() ) { return \Oversio\Tests\WcCustomerStubStore::query( $args ); }' );
		}
	}

	/**
	 * Seed the WcOrderStubStore with default order fixtures for WC2 tests.
	 *
	 * Seeds order id 5001 with a billing email + phone so PII-exposure tests can assert presence.
	 * Call after stub_woocommerce() (which resets the product store but not the order store), and
	 * before registering order abilities.
	 *
	 * @return void
	 */
	protected function seed_wc_orders(): void {
		WcOrderStubStore::reset();
		WcOrderStubStore::seed(
			5001,
			array(
				'number'         => '5001',
				'status'         => 'processing',
				'total'          => '49.99',
				'currency'       => 'USD',
				'date_created'   => '2024-06-01T10:00:00',
				'date_paid'      => '2024-06-01T10:01:00',
				'customer_id'    => 42,
				'customer_note'  => 'Please deliver before noon.',
				'items'          => array(
					array(
						'name'       => 'Test Widget',
						'product_id' => 101,
						'quantity'   => 2,
						'subtotal'   => '39.98',
						'total'      => '39.98',
					),
				),
				'billing'        => array(
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'company'    => 'Acme Corp',
					'address_1'  => '123 Main St',
					'address_2'  => '',
					'city'       => 'Springfield',
					'state'      => 'IL',
					'postcode'   => '62701',
					'country'    => 'US',
					'email'      => 'billing@example.com',
					'phone'      => '+1-555-0100',
				),
				'shipping'       => array(
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'company'    => '',
					'address_1'  => '123 Main St',
					'address_2'  => '',
					'city'       => 'Springfield',
					'state'      => 'IL',
					'postcode'   => '62701',
					'country'    => 'US',
				),
				'total_tax'      => '4.00',
				'subtotal'       => '39.98',
				'shipping_total' => '5.99',
			)
		);
	}

	/**
	 * The source of the stub WC_Order class. Kept as a string so the eval definition is guarded
	 * by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * The WC_Order stub reads from WcOrderStubStore keyed by id. Its getters mirror the real
	 * WooCommerce WC_Order API the order abilities call: get_id, get_order_number, get_status,
	 * get_total, get_currency, get_date_created, get_date_paid, get_customer_id, get_items,
	 * get_billing_*, get_shipping_*, get_customer_note, get_total_tax, get_subtotal,
	 * get_shipping_total. WC_DateTime is not needed; date fields are stored as strings.
	 *
	 * @return string
	 */
	private function oversio_wc_order_class_source(): string {
		return <<<'PHP'
class WC_Order {
	private $data = array();
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		$stored = \Oversio\Tests\WcOrderStubStore::get( $id );
		$this->data = is_array( $stored ) ? $stored : array( 'id' => 0 );
	}
	public function get_id() { return (int) ( $this->data['id'] ?? 0 ); }
	public function get_order_number() { return (string) ( $this->data['number'] ?? (string) $this->get_id() ); }
	public function get_status() { return (string) ( $this->data['status'] ?? 'processing' ); }
	public function get_total() { return (string) ( $this->data['total'] ?? '0.00' ); }
	public function get_currency() { return (string) ( $this->data['currency'] ?? 'USD' ); }
	public function get_date_created() { return $this->data['date_created'] ?? null; }
	public function get_date_paid() { return $this->data['date_paid'] ?? null; }
	public function get_customer_id() { return (int) ( $this->data['customer_id'] ?? 0 ); }
	public function get_customer_note() { return (string) ( $this->data['customer_note'] ?? '' ); }
	public function get_items( $types = 'line_item' ) { return (array) ( $this->data['items'] ?? array() ); }
	public function get_total_tax() { return (string) ( $this->data['total_tax'] ?? '0.00' ); }
	public function get_subtotal() { return (string) ( $this->data['subtotal'] ?? '0.00' ); }
	public function get_shipping_total() { return (string) ( $this->data['shipping_total'] ?? '0.00' ); }
	public function get_billing_first_name() { return (string) ( $this->data['billing']['first_name'] ?? '' ); }
	public function get_billing_last_name() { return (string) ( $this->data['billing']['last_name'] ?? '' ); }
	public function get_billing_company() { return (string) ( $this->data['billing']['company'] ?? '' ); }
	public function get_billing_address_1() { return (string) ( $this->data['billing']['address_1'] ?? '' ); }
	public function get_billing_address_2() { return (string) ( $this->data['billing']['address_2'] ?? '' ); }
	public function get_billing_city() { return (string) ( $this->data['billing']['city'] ?? '' ); }
	public function get_billing_state() { return (string) ( $this->data['billing']['state'] ?? '' ); }
	public function get_billing_postcode() { return (string) ( $this->data['billing']['postcode'] ?? '' ); }
	public function get_billing_country() { return (string) ( $this->data['billing']['country'] ?? '' ); }
	public function get_billing_email() { return (string) ( $this->data['billing']['email'] ?? '' ); }
	public function get_billing_phone() { return (string) ( $this->data['billing']['phone'] ?? '' ); }
	public function get_shipping_first_name() { return (string) ( $this->data['shipping']['first_name'] ?? '' ); }
	public function get_shipping_last_name() { return (string) ( $this->data['shipping']['last_name'] ?? '' ); }
	public function get_shipping_company() { return (string) ( $this->data['shipping']['company'] ?? '' ); }
	public function get_shipping_address_1() { return (string) ( $this->data['shipping']['address_1'] ?? '' ); }
	public function get_shipping_address_2() { return (string) ( $this->data['shipping']['address_2'] ?? '' ); }
	public function get_shipping_city() { return (string) ( $this->data['shipping']['city'] ?? '' ); }
	public function get_shipping_state() { return (string) ( $this->data['shipping']['state'] ?? '' ); }
	public function get_shipping_postcode() { return (string) ( $this->data['shipping']['postcode'] ?? '' ); }
	public function get_shipping_country() { return (string) ( $this->data['shipping']['country'] ?? '' ); }
	public function set_status( $v ) { $s = (string) $v; $this->data['status'] = 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s; }
	public function update_status( $v ) { $s = (string) $v; $this->data['status'] = 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s; return true; }
	public function set_customer_id( $v ) { $this->data['customer_id'] = (int) $v; }
	public function set_customer_note( $v ) { $this->data['customer_note'] = (string) $v; }
	public function set_billing_first_name( $v ) { $this->data['billing']['first_name'] = (string) $v; }
	public function set_billing_last_name( $v ) { $this->data['billing']['last_name'] = (string) $v; }
	public function set_billing_company( $v ) { $this->data['billing']['company'] = (string) $v; }
	public function set_billing_address_1( $v ) { $this->data['billing']['address_1'] = (string) $v; }
	public function set_billing_address_2( $v ) { $this->data['billing']['address_2'] = (string) $v; }
	public function set_billing_city( $v ) { $this->data['billing']['city'] = (string) $v; }
	public function set_billing_state( $v ) { $this->data['billing']['state'] = (string) $v; }
	public function set_billing_postcode( $v ) { $this->data['billing']['postcode'] = (string) $v; }
	public function set_billing_country( $v ) { $this->data['billing']['country'] = (string) $v; }
	public function set_billing_email( $v ) { $this->data['billing']['email'] = (string) $v; }
	public function set_billing_phone( $v ) { $this->data['billing']['phone'] = (string) $v; }
	public function set_shipping_first_name( $v ) { $this->data['shipping']['first_name'] = (string) $v; }
	public function set_shipping_last_name( $v ) { $this->data['shipping']['last_name'] = (string) $v; }
	public function set_shipping_company( $v ) { $this->data['shipping']['company'] = (string) $v; }
	public function set_shipping_address_1( $v ) { $this->data['shipping']['address_1'] = (string) $v; }
	public function set_shipping_address_2( $v ) { $this->data['shipping']['address_2'] = (string) $v; }
	public function set_shipping_city( $v ) { $this->data['shipping']['city'] = (string) $v; }
	public function set_shipping_state( $v ) { $this->data['shipping']['state'] = (string) $v; }
	public function set_shipping_postcode( $v ) { $this->data['shipping']['postcode'] = (string) $v; }
	public function set_shipping_country( $v ) { $this->data['shipping']['country'] = (string) $v; }
	public function add_product( $product, $qty = 1 ) {
		$pid = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
		$qty = (int) $qty;
		$price = is_object( $product ) && method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
		$name = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
		$line = number_format( $price * $qty, 2, '.', '' );
		$this->data['items'][] = array( 'name' => $name, 'product_id' => $pid, 'quantity' => $qty, 'subtotal' => $line, 'total' => $line );
		return count( $this->data['items'] ) - 1;
	}
	public function calculate_totals( $and_taxes = true ) {
		$subtotal = 0.0;
		foreach ( (array) ( $this->data['items'] ?? array() ) as $item ) {
			$subtotal += (float) ( $item['total'] ?? 0 );
		}
		$this->data['subtotal'] = number_format( $subtotal, 2, '.', '' );
		$this->data['total']    = number_format( $subtotal + (float) ( $this->data['shipping_total'] ?? 0 ) + (float) ( $this->data['total_tax'] ?? 0 ), 2, '.', '' );
		return $this->data['total'];
	}
	public function add_order_note( $note, $customer_note = false, $added_by_user = false ) { $note = (string) $note; $customer_note = (bool) $customer_note; $id = (int) ( $this->data['id'] ?? 0 ); return \Oversio\Tests\WcOrderStubStore::add_note( $id, $note, $customer_note ); }
	public function get_refunds() { $id = (int) ( $this->data['id'] ?? 0 ); return \Oversio\Tests\WcOrderStubStore::get_refunds_for_order( $id ); }
	public function delete( $force = false ) { $id = (int) ( $this->data['id'] ?? 0 ); return \Oversio\Tests\WcOrderStubStore::delete_order( $id ); }
	public function save() { $id = (int) ( $this->data['id'] ?? 0 ); $id = \Oversio\Tests\WcOrderStubStore::save( $this->data ); $this->data['id'] = $id; return $id; }
}
PHP;
	}

	/**
	 * The source of the stub WC_Product class. Kept as a string so the eval definition is guarded by
	 * class_exists and the trait file still holds exactly one object structure (the trait).
	 *
	 * @return string
	 */
	private function oversio_wc_product_class_source(): string {
		return <<<'PHP'
class WC_Product {
	private $data = array();
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		$stored = \Oversio\Tests\WcStubStore::get( $id );
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
	public function save() { $id = \Oversio\Tests\WcStubStore::save( $this->data ); $this->data['id'] = $id; return $id; }
	public function delete( $force = false ) { return \Oversio\Tests\WcStubStore::delete( (int) ( $this->data['id'] ?? 0 ) ); }
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
	private function oversio_wc_variation_class_source(): string {
		return <<<'PHP'
class WC_Product_Variation {
	private $data = array();
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		$stored = \Oversio\Tests\WcStubStore::get( $id );
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
	public function save() { $this->data['type'] = 'variation'; $id = \Oversio\Tests\WcStubStore::save( $this->data ); $this->data['id'] = $id; return $id; }
	public function delete( $force = false ) { return \Oversio\Tests\WcStubStore::delete( (int) ( $this->data['id'] ?? 0 ) ); }
}
PHP;
	}

	/**
	 * The source of the stub WC_Order_Refund class. Kept as a string so the eval definition is
	 * guarded by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * WC_Order_Refund reads from WcOrderStubStore::get_refund_by_id(). Its getters mirror the
	 * real WooCommerce WC_Order_Refund API the refund abilities call: get_id, get_amount,
	 * get_reason, get_date_created. delete() delegates to WcOrderStubStore::delete_refund().
	 *
	 * @return string
	 */
	private function oversio_wc_order_refund_class_source(): string {
		return <<<'PHP'
class WC_Order_Refund {
	private $data = array();
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		$stored = \Oversio\Tests\WcOrderStubStore::get_refund_by_id( $id );
		$this->data = is_array( $stored ) ? $stored : array( 'id' => 0, 'amount' => '0.00', 'reason' => '', 'date_created' => '' );
	}
	public function get_id() { return (int) ( $this->data['id'] ?? 0 ); }
	public function get_amount() { return (string) ( $this->data['amount'] ?? '0.00' ); }
	public function get_reason() { return (string) ( $this->data['reason'] ?? '' ); }
	public function get_date_created() { return $this->data['date_created'] ?? null; }
	public function delete( $force = false ) { $id = (int) ( $this->data['id'] ?? 0 ); return \Oversio\Tests\WcOrderStubStore::delete_refund( $id ); }
}
PHP;
	}

	/**
	 * Seed the WcCustomerStubStore with default customer fixtures for WC3 tests.
	 *
	 * Seeds customer id 7001 with email + billing phone so PII-exposure tests can assert presence.
	 * Call after stub_woocommerce() (which does not reset the customer store), and before
	 * registering customer abilities.
	 *
	 * @return void
	 */
	protected function seed_wc_customers(): void {
		WcCustomerStubStore::reset();
		WcCustomerStubStore::seed(
			7001,
			array(
				'email'        => 'jane@example.com',
				'first_name'   => 'Jane',
				'last_name'    => 'Doe',
				'username'     => 'janedoe',
				'orders_count' => 3,
				'total_spent'  => '149.97',
				'date_created' => '2024-05-01T09:00:00',
				'billing'      => array(
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'company'    => 'Acme Corp',
					'address_1'  => '123 Main St',
					'address_2'  => '',
					'city'       => 'Springfield',
					'state'      => 'IL',
					'postcode'   => '62701',
					'country'    => 'US',
					'email'      => 'jane@example.com',
					'phone'      => '+1-555-0200',
				),
				'shipping'     => array(
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'company'    => '',
					'address_1'  => '123 Main St',
					'address_2'  => '',
					'city'       => 'Springfield',
					'state'      => 'IL',
					'postcode'   => '62701',
					'country'    => 'US',
				),
			)
		);
	}

	/**
	 * The source of the stub WC_Customer class. Kept as a string so the eval definition is guarded
	 * by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * The WC_Customer stub reads from WcCustomerStubStore keyed by id. Its getters mirror the real
	 * WooCommerce WC_Customer API the customer abilities call. save() delegates to the store and
	 * accepts an $is_new flag so the create vs update fail-flags can be exercised independently.
	 *
	 * @return string
	 */
	private function oversio_wc_customer_class_source(): string {
		return <<<'PHP'
class WC_Customer {
	private $data = array();
	private $is_new = true;
	public function __construct( $id = 0 ) {
		$id = (int) $id;
		if ( $id > 0 ) {
			$stored = \Oversio\Tests\WcCustomerStubStore::get( $id );
			$this->data = is_array( $stored ) ? $stored : array( 'id' => 0 );
			$this->is_new = false;
		} else {
			$this->data = array( 'id' => 0 );
		}
	}
	public function get_id() { return (int) ( $this->data['id'] ?? 0 ); }
	public function get_email() { return (string) ( $this->data['email'] ?? '' ); }
	public function get_first_name() { return (string) ( $this->data['first_name'] ?? '' ); }
	public function get_last_name() { return (string) ( $this->data['last_name'] ?? '' ); }
	public function get_username() { return (string) ( $this->data['username'] ?? '' ); }
	public function get_order_count() { return (int) ( $this->data['orders_count'] ?? 0 ); }
	public function get_total_spent() { return (string) ( $this->data['total_spent'] ?? '0.00' ); }
	public function get_date_created() { return $this->data['date_created'] ?? null; }
	public function get_billing_first_name() { return (string) ( $this->data['billing']['first_name'] ?? '' ); }
	public function get_billing_last_name() { return (string) ( $this->data['billing']['last_name'] ?? '' ); }
	public function get_billing_company() { return (string) ( $this->data['billing']['company'] ?? '' ); }
	public function get_billing_address_1() { return (string) ( $this->data['billing']['address_1'] ?? '' ); }
	public function get_billing_address_2() { return (string) ( $this->data['billing']['address_2'] ?? '' ); }
	public function get_billing_city() { return (string) ( $this->data['billing']['city'] ?? '' ); }
	public function get_billing_state() { return (string) ( $this->data['billing']['state'] ?? '' ); }
	public function get_billing_postcode() { return (string) ( $this->data['billing']['postcode'] ?? '' ); }
	public function get_billing_country() { return (string) ( $this->data['billing']['country'] ?? '' ); }
	public function get_billing_email() { return (string) ( $this->data['billing']['email'] ?? '' ); }
	public function get_billing_phone() { return (string) ( $this->data['billing']['phone'] ?? '' ); }
	public function get_shipping_first_name() { return (string) ( $this->data['shipping']['first_name'] ?? '' ); }
	public function get_shipping_last_name() { return (string) ( $this->data['shipping']['last_name'] ?? '' ); }
	public function get_shipping_company() { return (string) ( $this->data['shipping']['company'] ?? '' ); }
	public function get_shipping_address_1() { return (string) ( $this->data['shipping']['address_1'] ?? '' ); }
	public function get_shipping_address_2() { return (string) ( $this->data['shipping']['address_2'] ?? '' ); }
	public function get_shipping_city() { return (string) ( $this->data['shipping']['city'] ?? '' ); }
	public function get_shipping_state() { return (string) ( $this->data['shipping']['state'] ?? '' ); }
	public function get_shipping_postcode() { return (string) ( $this->data['shipping']['postcode'] ?? '' ); }
	public function get_shipping_country() { return (string) ( $this->data['shipping']['country'] ?? '' ); }
	public function set_email( $v ) { $this->data['email'] = (string) $v; }
	public function set_first_name( $v ) { $this->data['first_name'] = (string) $v; }
	public function set_last_name( $v ) { $this->data['last_name'] = (string) $v; }
	public function set_username( $v ) { $this->data['username'] = (string) $v; }
	public function set_billing_first_name( $v ) { $this->data['billing']['first_name'] = (string) $v; }
	public function set_billing_last_name( $v ) { $this->data['billing']['last_name'] = (string) $v; }
	public function set_billing_company( $v ) { $this->data['billing']['company'] = (string) $v; }
	public function set_billing_address_1( $v ) { $this->data['billing']['address_1'] = (string) $v; }
	public function set_billing_address_2( $v ) { $this->data['billing']['address_2'] = (string) $v; }
	public function set_billing_city( $v ) { $this->data['billing']['city'] = (string) $v; }
	public function set_billing_state( $v ) { $this->data['billing']['state'] = (string) $v; }
	public function set_billing_postcode( $v ) { $this->data['billing']['postcode'] = (string) $v; }
	public function set_billing_country( $v ) { $this->data['billing']['country'] = (string) $v; }
	public function set_billing_email( $v ) { $this->data['billing']['email'] = (string) $v; }
	public function set_billing_phone( $v ) { $this->data['billing']['phone'] = (string) $v; }
	public function set_shipping_first_name( $v ) { $this->data['shipping']['first_name'] = (string) $v; }
	public function set_shipping_last_name( $v ) { $this->data['shipping']['last_name'] = (string) $v; }
	public function set_shipping_company( $v ) { $this->data['shipping']['company'] = (string) $v; }
	public function set_shipping_address_1( $v ) { $this->data['shipping']['address_1'] = (string) $v; }
	public function set_shipping_address_2( $v ) { $this->data['shipping']['address_2'] = (string) $v; }
	public function set_shipping_city( $v ) { $this->data['shipping']['city'] = (string) $v; }
	public function set_shipping_state( $v ) { $this->data['shipping']['state'] = (string) $v; }
	public function set_shipping_postcode( $v ) { $this->data['shipping']['postcode'] = (string) $v; }
	public function set_shipping_country( $v ) { $this->data['shipping']['country'] = (string) $v; }
	public function save( $is_new = null ) {
		$new = ( null === $is_new ) ? $this->is_new : (bool) $is_new;
		$id = \Oversio\Tests\WcCustomerStubStore::save( $this->data, $new );
		if ( $id > 0 ) {
			$this->data['id'] = $id;
			$this->is_new = false;
		}
		return $id;
	}
}
PHP;
	}

	/**
	 * Seed the WcCouponStubStore with default coupon fixtures for WC4 tests.
	 *
	 * Seeds coupon ids 5001 and 5002 so shape and pagination tests have two rows to work with.
	 * Call after stub_woocommerce() (which does not reset the coupon store), and before
	 * registering coupon abilities.
	 *
	 * @return void
	 */
	protected function seed_wc_coupons(): void {
		WcCouponStubStore::reset();
		WcCouponStubStore::seed(
			5001,
			array(
				'code'           => 'SAVE10',
				'amount'         => '10.00',
				'discount_type'  => 'fixed_cart',
				'description'    => 'Save $10 on your order.',
				'date_expires'   => '2025-12-31T23:59:59',
				'usage_count'    => 5,
				'usage_limit'    => 100,
				'individual_use' => true,
			)
		);
		WcCouponStubStore::seed(
			5002,
			array(
				'code'               => 'PERCENT20',
				'amount'             => '20.00',
				'discount_type'      => 'percent',
				'description'        => '20% off sitewide.',
				'date_expires'       => null,
				'usage_count'        => 12,
				'usage_limit'        => null,
				'individual_use'     => false,
				'email_restrictions' => array( 'vip@example.com' ),
			)
		);
	}

	/**
	 * Define the minimal WooCommerce coupon surface so the coupon abilities can list/read/create/
	 * update/delete through the WC CRUD layer.
	 *
	 * Real WooCommerce has no wc_get_coupons(): coupons are the 'shop_coupon' post type, listed via
	 * WP_Query and hydrated through WC_Coupon. This helper registers that post type so the list
	 * query can find the seeded posts, defines the WC_Coupon class (global, once per process), and
	 * provides wc_get_coupon_id_by_code() for the code-string constructor path. The field data lives
	 * in WcCouponStubStore. Must be called AFTER stub_woocommerce() (which defines the WooCommerce
	 * marker class and grants the manage_woocommerce capability), because it piggy-backs on that
	 * infrastructure.
	 *
	 * @return void
	 */
	protected function stub_wc_coupons(): void {
		if ( ! post_type_exists( 'shop_coupon' ) ) {
			register_post_type(
				'shop_coupon',
				array(
					'public'  => false,
					'show_ui' => false,
				)
			);
		}
		if ( ! class_exists( 'WC_Coupon' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_coupon_class_source() );
		}
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only stub for tests; never shipped.
			eval( 'function wc_get_coupon_id_by_code( $code ) { return \Oversio\Tests\WcCouponStubStore::get_id_by_code( (string) $code ); }' );
		}
	}

	/**
	 * The source of the stub WC_Coupon class. Kept as a string so the eval definition is guarded
	 * by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * The WC_Coupon stub reads from WcCouponStubStore keyed by id. Its constructor also accepts a
	 * coupon code string (mirroring real WC), resolving it to an id via get_id_by_code(). Getters
	 * mirror the real WooCommerce WC_Coupon API the coupon abilities call. Setters stage changes on
	 * the instance; save() persists to the store; delete() removes. A test-only stub, never shipped.
	 *
	 * @return string
	 */
	private function oversio_wc_coupon_class_source(): string {
		return <<<'PHP'
class WC_Coupon {
	private $data = array();
	public function __construct( $code_or_id = 0 ) {
		$id = 0;
		if ( is_int( $code_or_id ) || ( is_string( $code_or_id ) && ctype_digit( (string) $code_or_id ) ) ) {
			$id = (int) $code_or_id;
		} elseif ( is_string( $code_or_id ) && '' !== $code_or_id ) {
			$id = \Oversio\Tests\WcCouponStubStore::get_id_by_code( $code_or_id );
		}
		if ( $id > 0 ) {
			$stored = \Oversio\Tests\WcCouponStubStore::get( $id );
			$this->data = is_array( $stored ) ? $stored : array( 'id' => 0 );
		} else {
			$this->data = array( 'id' => 0 );
		}
	}
	public function get_id() { return (int) ( $this->data['id'] ?? 0 ); }
	public function get_code() { return (string) ( $this->data['code'] ?? '' ); }
	public function get_amount() { return (string) ( $this->data['amount'] ?? '0.00' ); }
	public function get_discount_type() { return (string) ( $this->data['discount_type'] ?? 'fixed_cart' ); }
	public function get_description() { return (string) ( $this->data['description'] ?? '' ); }
	public function get_date_expires() { return $this->data['date_expires'] ?? null; }
	public function get_usage_count() { return (int) ( $this->data['usage_count'] ?? 0 ); }
	public function get_usage_limit() { $v = $this->data['usage_limit'] ?? null; return ( null === $v ) ? null : (int) $v; }
	public function get_usage_limit_per_user() { $v = $this->data['usage_limit_per_user'] ?? null; return ( null === $v ) ? null : (int) $v; }
	public function get_minimum_amount() { return (string) ( $this->data['minimum_amount'] ?? '' ); }
	public function get_maximum_amount() { return (string) ( $this->data['maximum_amount'] ?? '' ); }
	public function get_individual_use() { return (bool) ( $this->data['individual_use'] ?? false ); }
	public function get_exclude_sale_items() { return (bool) ( $this->data['exclude_sale_items'] ?? false ); }
	public function get_product_ids() { return (array) ( $this->data['product_ids'] ?? array() ); }
	public function get_excluded_product_ids() { return (array) ( $this->data['excluded_product_ids'] ?? array() ); }
	public function get_email_restrictions() { return (array) ( $this->data['email_restrictions'] ?? array() ); }
	public function set_code( $v ) { $this->data['code'] = strtolower( (string) $v ); }
	public function set_amount( $v ) { $this->data['amount'] = (string) $v; }
	public function set_discount_type( $v ) { $this->data['discount_type'] = (string) $v; }
	public function set_description( $v ) { $this->data['description'] = (string) $v; }
	public function set_date_expires( $v ) { $this->data['date_expires'] = ( null === $v ) ? null : (string) $v; }
	public function set_usage_limit( $v ) { $this->data['usage_limit'] = ( null === $v ) ? null : (int) $v; }
	public function set_usage_limit_per_user( $v ) { $this->data['usage_limit_per_user'] = ( null === $v ) ? null : (int) $v; }
	public function set_minimum_amount( $v ) { $this->data['minimum_amount'] = (string) $v; }
	public function set_maximum_amount( $v ) { $this->data['maximum_amount'] = (string) $v; }
	public function set_individual_use( $v ) { $this->data['individual_use'] = (bool) $v; }
	public function set_exclude_sale_items( $v ) { $this->data['exclude_sale_items'] = (bool) $v; }
	public function set_product_ids( $v ) { $this->data['product_ids'] = array_map( 'intval', (array) $v ); }
	public function set_excluded_product_ids( $v ) { $this->data['excluded_product_ids'] = array_map( 'intval', (array) $v ); }
	public function set_email_restrictions( $v ) { $this->data['email_restrictions'] = array_map( 'strval', (array) $v ); }
	public function save() { $id = \Oversio\Tests\WcCouponStubStore::save( $this->data ); $this->data['id'] = $id; return $id; }
	public function delete( $force = false ) { return \Oversio\Tests\WcCouponStubStore::delete( (int) ( $this->data['id'] ?? 0 ) ); }
}
PHP;
	}

	/**
	 * Seed the WcShippingStubStore with default zone and method fixtures for WC5 tests.
	 *
	 * Seeds zone 1 (Europe) and zone 2 (USA) with two methods each. Zone 0 (Rest of World)
	 * is always present via the store; seed it here so list tests see it. Call after
	 * stub_wc_shipping() (which resets the store), and before registering shipping abilities.
	 *
	 * @return void
	 */
	protected function seed_wc_shipping(): void {
		WcShippingStubStore::reset();
		WcShippingStubStore::seed(
			0,
			array(
				'zone_name'  => 'Rest of World',
				'zone_order' => 0,
			)
		);
		WcShippingStubStore::seed(
			1,
			array(
				'zone_name'      => 'Europe',
				'zone_order'     => 1,
				'zone_locations' => array(
					array(
						'type' => 'continent',
						'code' => 'EU',
					),
				),
			)
		);
		WcShippingStubStore::seed(
			2,
			array(
				'zone_name'      => 'USA',
				'zone_order'     => 2,
				'zone_locations' => array(
					array(
						'type' => 'country',
						'code' => 'US',
					),
				),
			)
		);
		WcShippingStubStore::seed_method(
			1,
			1,
			array(
				'id'           => 'flat_rate',
				'method_title' => 'Flat Rate',
				'enabled'      => 'yes',
			)
		);
		WcShippingStubStore::seed_method(
			1,
			2,
			array(
				'id'           => 'free_shipping',
				'method_title' => 'Free Shipping',
				'enabled'      => 'yes',
			)
		);
		WcShippingStubStore::seed_method(
			2,
			3,
			array(
				'id'           => 'flat_rate',
				'method_title' => 'Flat Rate',
				'enabled'      => 'yes',
			)
		);
		WcShippingStubStore::seed_method(
			2,
			4,
			array(
				'id'           => 'local_pickup',
				'method_title' => 'Local Pickup',
				'enabled'      => 'no',
			)
		);
		WcShippingStubStore::$next_instance_id = 5;

		// Back the is_enabled toggle with a real temp table so the production $wpdb->update()
		// against woocommerce_shipping_zone_methods works unmodified (same pattern as the tax stub).
		WcShippingStubStore::drop_methods_table();
		WcShippingStubStore::create_methods_table();
	}

	/**
	 * Define the minimal WooCommerce shipping surface so the shipping zone and method abilities
	 * can list/read/create/update/delete through the WC API layer.
	 *
	 * WooCommerce's WC_Shipping_Zone class, WC_Shipping_Method class, and WC_Shipping_Zones static
	 * are global and defined once per process, so the actual zone/method state lives in
	 * WcShippingStubStore. This helper must be called AFTER stub_woocommerce() (which defines the
	 * WooCommerce marker class and grants the manage_woocommerce capability).
	 *
	 * @return void
	 */
	protected function stub_wc_shipping(): void {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_shipping_zone_class_source() );
		}
		if ( ! class_exists( 'WC_Shipping_Method' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_shipping_method_class_source() );
		}
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( 'class WC_Shipping_Zones { public static function get_zones( $args = array() ) { $rows = \Oversio\Tests\WcShippingStubStore::all(); $out = array(); foreach ( $rows as $row ) { $zone_id = (int)( $row["zone_id"] ?? 0 ); $z = new \WC_Shipping_Zone( $zone_id ); $out[$zone_id] = array_merge( $row, array( "zone_object" => $z ) ); } return $out; } }' );
		}
	}

	/**
	 * The source of the stub WC_Shipping_Zone class. Kept as a string so the eval definition is
	 * guarded by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * @return string
	 */
	private function oversio_wc_shipping_zone_class_source(): string {
		return <<<'PHP'
class WC_Shipping_Zone {
	private $data = array();
	public function __construct( $zone_id = 0 ) {
		$zone_id = (int) $zone_id;
		$stored = \Oversio\Tests\WcShippingStubStore::get( $zone_id );
		$this->data = is_array( $stored ) ? $stored : array( 'zone_id' => $zone_id, 'zone_name' => '', 'zone_order' => 0, 'zone_locations' => array() );
	}
	public function get_id() { return (int) ( $this->data['zone_id'] ?? 0 ); }
	public function get_data() {
		return array(
			'id'             => (int) ( $this->data['zone_id'] ?? 0 ),
			'zone_name'      => (string) ( $this->data['zone_name'] ?? '' ),
			'zone_order'     => (int) ( $this->data['zone_order'] ?? 0 ),
			'zone_locations' => (array) ( $this->data['zone_locations'] ?? array() ),
		);
	}
	public function get_zone_name() { return (string) ( $this->data['zone_name'] ?? '' ); }
	public function get_zone_order() { return (int) ( $this->data['zone_order'] ?? 0 ); }
	public function set_zone_name( $v ) { $this->data['zone_name'] = (string) $v; }
	public function set_zone_order( $v ) { $this->data['zone_order'] = (int) $v; }
	public function save() {
		$id = \Oversio\Tests\WcShippingStubStore::save_zone( $this->data );
		if ( $id > 0 ) { $this->data['zone_id'] = $id; }
		return $id;
	}
	public function delete( $force = false ) {
		$id = (int) ( $this->data['zone_id'] ?? 0 );
		return \Oversio\Tests\WcShippingStubStore::delete_zone( $id );
	}
	public function get_shipping_methods( $enabled_only = false ) {
		$zone_id = (int) ( $this->data['zone_id'] ?? 0 );
		$methods_data = \Oversio\Tests\WcShippingStubStore::methods_for_zone( $zone_id );
		$out = array();
		foreach ( $methods_data as $m ) {
			if ( $enabled_only && 'yes' !== ( $m['enabled'] ?? 'yes' ) ) { continue; }
			$instance_id = (int) ( $m['instance_id'] ?? 0 );
			$obj = new \WC_Shipping_Method( $instance_id, $zone_id );
			$out[ $instance_id ] = $obj;
		}
		return $out;
	}
	public function add_shipping_method( $type ) {
		$zone_id = (int) ( $this->data['zone_id'] ?? 0 );
		return \Oversio\Tests\WcShippingStubStore::add_method( $zone_id, (string) $type );
	}
	public function delete_shipping_method( $instance_id ) {
		$zone_id = (int) ( $this->data['zone_id'] ?? 0 );
		return \Oversio\Tests\WcShippingStubStore::delete_method( $zone_id, (int) $instance_id );
	}
}
PHP;
	}

	/**
	 * The source of the stub WC_Shipping_Method class. Kept as a string so the eval definition is
	 * guarded by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * @return string
	 */
	private function oversio_wc_shipping_method_class_source(): string {
		return <<<'PHP'
class WC_Shipping_Method {
	public $instance_id = 0;
	public $id = '';
	public $method_title = '';
	public $title = '';
	public $enabled = 'yes';
	public $settings = array();
	public $instance_settings = array();
	private $zone_id = 0;
	public function __construct( $instance_id = 0, $zone_id = 0 ) {
		$this->instance_id = (int) $instance_id;
		$this->zone_id     = (int) $zone_id;
		$stored = \Oversio\Tests\WcShippingStubStore::get_method( $this->zone_id, $this->instance_id );
		if ( is_array( $stored ) ) {
			$this->id           = (string) ( $stored['id'] ?? 'flat_rate' );
			$this->method_title = (string) ( $stored['method_title'] ?? '' );
			$this->settings     = (array) ( $stored['settings'] ?? array() );
			// The production code persists instance settings as a WP option keyed by
			// get_instance_option_key() (core update_option()), exactly as WC does. Merge that
			// option over the seeded settings so a write round-trips on the next read — this is
			// what WC_Settings_API::init_settings() does on construct.
			$option = get_option( $this->get_instance_option_key() );
			if ( is_array( $option ) ) {
				$this->settings = array_merge( $this->settings, $option );
			}
			// WC populates $title from the "title" instance setting; mirror that so a
			// write through the instance settings option round-trips on the next read.
			$this->title = (string) ( $this->settings['title'] ?? $this->method_title );
			// is_enabled lives in the woocommerce_shipping_zone_methods table, which the
			// production toggle writes via $wpdb->update(). Read it back from the table when
			// a row exists, falling back to the in-memory store flag.
			$is_enabled = \Oversio\Tests\WcShippingStubStore::read_is_enabled( $this->instance_id );
			if ( null !== $is_enabled ) {
				$this->enabled = ( 1 === $is_enabled ) ? 'yes' : 'no';
			} else {
				$this->enabled = (string) ( $stored['enabled'] ?? 'yes' );
			}
		}
	}
	public function get_instance_id() { return $this->instance_id; }
	public function init_instance_settings() { $this->instance_settings = (array) $this->settings; }
	public function get_instance_option_key() { return 'woocommerce_' . $this->id . '_' . $this->instance_id . '_settings'; }
	// Mirrors WC_Settings_API::update_option(): persist a single instance setting into the WP
	// option keyed by get_instance_option_key(), the same place the constructor reads back from.
	// Production update_shipping_method() writes the whole array via core update_option() directly;
	// this method exists to match WC's real API surface.
	public function update_option( $key, $value = '' ) {
		$option = get_option( $this->get_instance_option_key() );
		$option = is_array( $option ) ? $option : array();
		$option[ $key ] = $value;
		return update_option( $this->get_instance_option_key(), $option );
	}
}
PHP;
	}

	/**
	 * Remove every filter this trait added. Call from the slice's tear_down().
	 *
	 * @return void
	 */
	protected function reset_integration_stubs(): void {
		foreach ( $this->oversio_forced_integrations as $slug ) {
			remove_filter( 'oversio_integration_active_' . $slug, '__return_true' );
		}
		$this->oversio_forced_integrations = array();
		AcfStubStore::reset();
		AioseoStubStore::reset();
		WcStubStore::reset();
		WcAttributeStubStore::reset();
		WcOrderStubStore::reset();
		WcCustomerStubStore::reset();
		WcCouponStubStore::reset();
		WcShippingStubStore::reset();
		WcTaxStubStore::reset();
		WcGatewayStubStore::reset();
	}

	/**
	 * Seed the WcTaxStubStore with default tax class fixtures for WC6 tests.
	 *
	 * Call after stub_wc_tax() (which resets the class store), and before registering
	 * the tax abilities.
	 *
	 * @return void
	 */
	protected function seed_wc_tax(): void {
		WcTaxStubStore::reset();
		WcTaxStubStore::seed();
	}

	/**
	 * Define the minimal WooCommerce tax surface so the tax rate and class abilities can
	 * list/read/create/delete through the WC_Tax API layer.
	 *
	 * WooCommerce's WC_Tax class is global and defined once per process; class state lives in
	 * WcTaxStubStore. Tax rate CRUD goes through $wpdb against the temp table created by
	 * WcTaxStubStore::create_tax_rates_table(). This helper must be called AFTER
	 * stub_woocommerce() (which defines the WooCommerce marker class and grants the
	 * manage_woocommerce capability).
	 *
	 * @return void
	 */
	protected function stub_wc_tax(): void {
		if ( ! class_exists( 'WC_Tax' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_tax_class_source() );
		}
	}

	/**
	 * The source of the stub WC_Tax class. Kept as a string so the eval definition is
	 * guarded by class_exists and the trait file holds exactly one object structure (the trait).
	 *
	 * @return string
	 */
	private function oversio_wc_tax_class_source(): string {
		return <<<'PHP'
class WC_Tax {
	/**
	 * Return all custom tax class NAMES (standard is NOT included, mirroring real WC).
	 *
	 * @return string[]
	 */
	public static function get_tax_classes(): array {
		return array_values( \Oversio\Tests\WcTaxStubStore::$classes );
	}

	/**
	 * Return all custom tax class SLUGS, in the same order as get_tax_classes() names.
	 *
	 * @return string[]
	 */
	public static function get_tax_class_slugs(): array {
		return array_keys( \Oversio\Tests\WcTaxStubStore::$classes );
	}

	/**
	 * Create a tax class.
	 *
	 * @param string $name Class name.
	 * @param string $slug Optional slug; derived from name when empty.
	 * @return array<string,string>|\WP_Error
	 */
	public static function create_tax_class( string $name, string $slug = '' ): array|\WP_Error {
		if ( \Oversio\Tests\WcTaxStubStore::$force_save_failure ) {
			return new \WP_Error( 'wc_tax', 'Tax class save failed.' );
		}
		$slug = $slug ? $slug : sanitize_title( $name );
		if ( isset( \Oversio\Tests\WcTaxStubStore::$classes[ $slug ] ) ) {
			return new \WP_Error( 'wc_tax', 'Tax class already exists.' );
		}
		\Oversio\Tests\WcTaxStubStore::$classes[ $slug ] = $name;
		return array( 'name' => $name, 'slug' => $slug );
	}

	/**
	 * Delete a tax class by a field/value pair.
	 *
	 * @param string $field Field name (only 'slug' is supported).
	 * @param string $value Field value.
	 * @return bool|\WP_Error
	 */
	public static function delete_tax_class_by( string $field, string $value ): bool|\WP_Error {
		if ( \Oversio\Tests\WcTaxStubStore::$force_delete_failure ) {
			return new \WP_Error( 'wc_tax', 'Tax class delete failed.' );
		}
		if ( 'slug' === $field ) {
			if ( isset( \Oversio\Tests\WcTaxStubStore::$classes[ $value ] ) ) {
				unset( \Oversio\Tests\WcTaxStubStore::$classes[ $value ] );
				return true;
			}
			return new \WP_Error( 'wc_tax', 'Tax class not found.' );
		}
		return false;
	}
}
PHP;
	}

	/**
	 * Register the WC_Payment_Gateway and WC_Payment_Gateways stub classes (eval-backed).
	 *
	 * @return void
	 */
	protected function stub_wc_gateways(): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_payment_gateway_class_source() );
		}
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
			eval( $this->oversio_wc_payment_gateways_class_source() );
		}
	}

	/**
	 * Seed default gateway fixtures into WcGatewayStubStore.
	 *
	 * @return void
	 */
	protected function seed_wc_gateways(): void {
		\Oversio\Tests\WcGatewayStubStore::seed();
	}

	/**
	 * The source of the stub WC_Payment_Gateway class.
	 *
	 * @return string
	 */
	private function oversio_wc_payment_gateway_class_source(): string {
		return <<<'PHP'
class WC_Payment_Gateway {
	/** @var string */
	public $id = '';
	/** @var string */
	public $title = '';
	/** @var string */
	public $description = '';
	/** @var string */
	public $enabled = 'yes';
	/** @var int */
	public $order = 0;
	/** @var array<string,mixed> */
	public $settings = array();

	/**
	 * @param array<string,mixed> $data Initial gateway data.
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}

	/**
	 * Mirrors WC_Settings_API::update_option(): writes the value, then returns WordPress
	 * update_option()'s result, which is false when the value was unchanged (no write needed) —
	 * NOT only on failure. The new value is persisted to in-memory settings either way.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return bool
	 */
	public function update_option( $key, $value ) {
		$key = (string) $key;
		$changed = \Oversio\Tests\WcGatewayStubStore::update_option( $this->id, $key, $value );
		// Keep the in-memory settings in sync with what the store accepted so get_option() reflects
		// the persisted state (matching how real WC_Settings_API caches $this->settings).
		$stored = \Oversio\Tests\WcGatewayStubStore::get( $this->id );
		if ( is_array( $stored ) ) {
			$this->settings = $stored['settings'] ?? array();
		}
		return $changed;
	}

	/**
	 * Mirrors WC_Settings_API::get_option(): reads from the in-memory settings cache.
	 *
	 * @param string $key
	 * @param mixed  $empty_value
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		$key = (string) $key;
		if ( isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}
		return $empty_value;
	}
}
PHP;
	}

	/**
	 * The source of the stub WC_Payment_Gateways class.
	 *
	 * @return string
	 */
	private function oversio_wc_payment_gateways_class_source(): string {
		return <<<'PHP'
class WC_Payment_Gateways {
	/** @return static */
	public static function instance() {
		return new static();
	}

	/** @return array<string,\WC_Payment_Gateway> */
	public function payment_gateways() {
		$out = array();
		foreach ( \Oversio\Tests\WcGatewayStubStore::all() as $id => $data ) {
			$gw              = new \WC_Payment_Gateway( $data );
			$gw->settings    = $data['settings'] ?? array();
			$out[ (string) $id ] = $gw;
		}
		return $out;
	}
}
PHP;
	}
}
