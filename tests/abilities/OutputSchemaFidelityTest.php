<?php
/**
 * Wire-shape tests: what abilities actually serialize versus what their schemas declare.
 *
 * These assert on wp_json_encode() output rather than the PHP value, because
 * array() and (object) array() are indistinguishable in PHP but encode to
 * "[]" and "{}" respectively, and only the encoded form reaches the client.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcShippingStubStore;
use AAFM\Tests\WcStubStore;

final class OutputSchemaFidelityTest extends TestCase {

	use IntegrationStubs;

	public function tear_down(): void {
		// The abilities registry persists across tests; drop the demo fixture and any wrapper it
		// grew so a bridge fixture from one test never leaks into another (same pattern as
		// BridgeDiscoveryTest / BridgeWrapperTest).
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) || 0 === strncmp( $slug, 'aafm-bridge/', 12 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Register a throwaway foreign ability and turn it into a live aafm-bridge/* wrapper, the
	 * same two-step BridgeWrapperTest/BridgeDiscoveryTest use elsewhere: gated-action
	 * registration of the foreign ability, then aafm_register_enabled_bridged_abilities() to
	 * produce the wrapper aafm_filter_bridged_tool_call_result() actually looks up.
	 *
	 * @param string     $foreign_slug  Foreign ability slug, e.g. 'demo/list-with-schema'.
	 * @param array|null $output_schema Output schema to declare, or null to declare none.
	 * @return void
	 */
	private function register_bridged_fixture( string $foreign_slug, ?array $output_schema ): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $foreign_slug, $output_schema ): void {
				$args = array(
					'label'               => $foreign_slug,
					'description'         => 'Fixture foreign ability for the bridge result-filter tests.',
					'category'            => 'demo-things',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'execute_callback'    => static fn() => array(),
					'permission_callback' => '__return_true',
				);
				if ( null !== $output_schema ) {
					$args['output_schema'] = $output_schema;
				}
				wp_register_ability( $foreign_slug, $args );
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( $foreign_slug ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
	}

	/**
	 * Task 5 / issue found alongside #81: aafm_filter_bridged_tool_call_result() wrapped ANY
	 * bare list under {"data": ...}, even when the foreign ability's own output_schema declared a
	 * non-object root. The adapter advertises {type:object, properties:{result:<schema>},
	 * required:['result']} for a non-object-root schema (SchemaTransformer::
	 * transform_to_object_schema()), so a wrapped {"data":[...]} body satisfies neither the
	 * published shape (no 'result' key) nor omits the undeclared one ('data' isn't in the
	 * published properties either) - a strict client rejects the whole response.
	 */
	public function test_a_bridged_ability_with_a_declared_output_schema_is_not_wrapped(): void {
		$this->register_bridged_fixture(
			'demo/list-with-schema',
			array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			)
		);

		$result = aafm_filter_bridged_tool_call_result(
			array( 'a', 'b', 'c' ),
			array(),
			'aafm-bridge-demo-list-with-schema'
		);

		$this->assertArrayNotHasKey(
			'data',
			is_array( $result ) ? $result : array( 'data' => null ),
			'A wrapper with a declared output schema advertises that schema verbatim (or the adapter\'s {result:...} rewrite of it); wrapping under "data" is a key neither side declares.'
		);
		$this->assertSame(
			array( 'a', 'b', 'c' ),
			$result,
			'The foreign ability\'s own return value must reach the wire unchanged when its schema is declared.'
		);
		$this->assertSame(
			'["a","b","c"]',
			wp_json_encode( $result ),
			'The wire body must match the array the schema describes, not a {"data": [...]} envelope.'
		);
	}

	/**
	 * The regression guard: when the foreign ability declares NO output_schema at all, the
	 * original wrap must still fire. Nothing downstream is validating or advertising a shape in
	 * that case, so wrapping a bare list into an object is still the best available response to
	 * upstream mcp-adapter#253 (see aafm_filter_bridged_tool_call_result()'s docblock).
	 */
	public function test_a_bridged_ability_with_no_declared_output_schema_is_still_wrapped(): void {
		$this->register_bridged_fixture( 'demo/list-no-schema', null );

		$result = aafm_filter_bridged_tool_call_result(
			array( 'a', 'b', 'c' ),
			array(),
			'aafm-bridge-demo-list-no-schema'
		);

		$this->assertSame(
			array( 'data' => array( 'a', 'b', 'c' ) ),
			$result,
			'With no declared output_schema, nothing advertises a shape to contradict, so the data-wrap safety net must still apply.'
		);
		$this->assertSame(
			'{"data":["a","b","c"]}',
			wp_json_encode( $result ),
			'The wire body for the no-schema case must keep the original {"data": [...]} envelope.'
		);
	}

	/**
	 * M2: a bare empty array must never be wrapped, schema or no schema. array() is a "list" by
	 * aafm_bridge_is_list()'s definition (vacuously true), so the pre-fix code wrapped it into
	 * {"data":[]} unconditionally - even for a foreign ability whose declared schema is
	 * {type:object, additionalProperties:false}, which that shape violates outright.
	 */
	public function test_a_bridged_empty_array_result_is_never_wrapped_even_with_a_declared_schema(): void {
		$this->register_bridged_fixture(
			'demo/list-empty-forbidding-extra-keys',
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
			)
		);

		$result = aafm_filter_bridged_tool_call_result(
			array(),
			array(),
			'aafm-bridge-demo-list-empty-forbidding-extra-keys'
		);

		$this->assertSame(
			array(),
			$result,
			'An empty array must pass through untouched: wrapping it into {"data":[]} would add a key that additionalProperties:false explicitly forbids.'
		);
		$this->assertSame(
			'[]',
			wp_json_encode( $result ),
			'The wire body for an empty result must stay [] - no "data" envelope added.'
		);
	}

	public function test_a_page_with_no_terms_encodes_terms_as_an_empty_object(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'A page has no taxonomies',
			)
		);

		$shape = aafm_rich_post( get_post( $page_id ) );

		$this->assertSame(
			'{}',
			wp_json_encode( $shape['terms'] ),
			'terms is declared type:object at helpers.php:1123, so an empty map must encode as {} not [].'
		);
	}

	public function test_a_post_with_no_allowlisted_meta_encodes_meta_as_an_empty_object(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$shape = aafm_rich_post( get_post( $post_id ) );

		$this->assertSame(
			'{}',
			wp_json_encode( $shape['meta'] ),
			'meta is declared type:object at helpers.php:1145, so an empty map must encode as {} not [].'
		);
	}

	public function test_a_post_with_terms_still_encodes_terms_as_a_populated_object(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$cat_id  = self::factory()->category->create( array( 'name' => 'Reporting' ) );
		wp_set_object_terms( $post_id, array( (int) $cat_id ), 'category' );

		$shape = aafm_rich_post( get_post( $post_id ) );
		$json  = (string) wp_json_encode( $shape['terms'] );

		$this->assertStringStartsWith( '{', $json, 'A populated terms map must still be an object.' );
		$this->assertStringContainsString( '"category"', $json );
		$this->assertSame( 'Reporting', $shape['terms']['category'][0]['name'], 'Array access must still work.' );
	}

	/**
	 * A zone shipping method that has never been configured has no per-instance option, so
	 * instance_settings comes back empty. settings is declared type:object (shipping.php:597);
	 * an empty PHP array encodes as [], not {}, and the adapter passes the return value verbatim
	 * into structuredContent - the same class of bug as the terms/meta cases above, one bucket
	 * deeper in WooCommerce's shipping method API.
	 */
	public function test_a_shipping_method_with_no_instance_settings_encodes_settings_as_an_empty_object(): void {
		$this->prepare_wc_shipping_stub();

		$method = $this->make_zone_shipping_method( 'free_shipping', array() );

		$row = aafm_rich_wc_shipping_method( $method );

		$this->assertSame(
			'{}',
			wp_json_encode( $row['settings'] ),
			'settings is declared type:object at shipping.php:597; an empty map must encode as {} not [].'
		);
	}

	/**
	 * $method->settings is WooCommerce's legacy global bucket. This project's stub constructor
	 * merges the per-instance option into that legacy bucket for round-tripping convenience,
	 * which papers over a wrong-bucket read for any key the instance option also sets - so this
	 * test seeds a key that exists ONLY in the legacy bucket (never written to the instance
	 * option) to prove the output comes from instance_settings alone. Reading the legacy bucket
	 * would let that stale key leak into a live agent's view of the method's real configuration.
	 */
	public function test_shipping_settings_reports_the_instance_configuration_not_the_legacy_bucket(): void {
		$this->prepare_wc_shipping_stub();

		WcShippingStubStore::seed_method(
			1,
			1,
			array(
				'id'           => 'flat_rate',
				'method_title' => 'Flat Rate',
				'enabled'      => 'yes',
				// The legacy bucket: a stale value under a key the instance option below never
				// sets. Only a read of the wrong bucket can make this key reach the wire.
				'settings'     => array(
					'title'         => 'STALE-LEGACY-TITLE',
					'legacy_marker' => 'must-not-leak',
				),
			)
		);
		update_option(
			'woocommerce_flat_rate_1_settings',
			array(
				'title' => 'Flat rate',
				'cost'  => '9.99',
			)
		);

		$zone    = new \WC_Shipping_Zone( 1 );
		$methods = $zone->get_shipping_methods();
		$method  = $methods[1];

		$row = aafm_rich_wc_shipping_method( $method );

		$this->assertSame( 'Flat rate', $row['settings']['title'], 'settings must expose the real instance title.' );
		$this->assertSame( '9.99', $row['settings']['cost'], 'settings must expose the real configured cost.' );
		$this->assertArrayNotHasKey(
			'legacy_marker',
			$row['settings'],
			'A key that exists ONLY in the legacy $settings bucket must not leak into the instance_settings-based output.'
		);
	}

	/**
	 * Task 10: get_gallery_image_ids() is built through array_filter() then wp_parse_id_list()
	 * (array_unique(array_map('absint', ...))), all key-preserving. A stored
	 * _product_image_gallery meta of "12,0,34" yields [0=>12, 2=>34] - a PHP array with a gap - and
	 * only wp_json_encode() reveals that this differs from a clean list: a gap-keyed array encodes
	 * as a JSON OBJECT ({"0":12,"2":34}), not the JSON array the output_schema declares. Seeded
	 * directly on a fixture product's stub row (never a live product) to reproduce the exact PHP
	 * shape a gallery meta with a gap would produce, without depending on real WordPress post meta
	 * plumbing this test harness's WC_Product stub does not use.
	 */
	public function test_product_images_with_a_key_gap_still_encodes_as_a_json_array(): void {
		$this->stub_woocommerce();
		WcStubStore::seed(
			301,
			array(
				'id'                => 301,
				'name'              => 'Gallery Gap',
				'type'              => 'simple',
				// The gap at key 1 mirrors what array_filter()+wp_parse_id_list() would leave behind
				// for a raw gallery meta of "12,0,34" (the 0 gets filtered out, but its key does not
				// get closed).
				'gallery_image_ids' => array(
					0 => 12,
					2 => 34,
				),
			)
		);

		$row = aafm_rich_wc_product( \wc_get_product( 301 ) );

		$this->assertSame(
			array( 12, 34 ),
			$row['images'],
			'array_values() must close the key gap so the PHP value is a clean list.'
		);
		$this->assertStringStartsWith(
			'[',
			wp_json_encode( $row['images'] ),
			'images is declared type:array; a key-gapped array must not encode as a JSON object.'
		);
		$this->assertSame( '[12,34]', wp_json_encode( $row['images'] ) );
	}

	/**
	 * Task 10 / L3: variation_ids has the identical key-gap risk via get_children() on a grouped
	 * product, but it never self-heals - the grouped data store persists the array verbatim on
	 * save rather than imploding it back to a clean sequence the way the gallery meta does. Same
	 * seed-the-gap-directly approach as the images test above.
	 */
	public function test_product_variation_ids_with_a_key_gap_still_encodes_as_a_json_array(): void {
		$this->stub_woocommerce();
		WcStubStore::seed(
			302,
			array(
				'id'       => 302,
				'name'     => 'Grouped Gap',
				'type'     => 'grouped',
				'children' => array(
					0 => 401,
					2 => 402,
				),
			)
		);

		$row = aafm_rich_wc_product( \wc_get_product( 302 ) );

		$this->assertSame( array( 401, 402 ), $row['variation_ids'] );
		$this->assertSame( '[401,402]', wp_json_encode( $row['variation_ids'] ) );
	}

	/**
	 * Task 12: WC_Product_Variation::get_manage_stock() can return the STRING 'parent' when the
	 * variation does not manage its own stock but its parent does. (bool) 'parent' is true, so
	 * unguarded this would report manage_stock: true for a variation that owns no stock_quantity
	 * of its own - the "silent wrong answer" class, not a schema violation (the wire type stays a
	 * valid boolean either way, which is exactly why wp_json_encode() alone cannot catch this one;
	 * only the VALUE is wrong). Seeds a parent that manages its own stock and a variation that does
	 * not, so get_manage_stock() actually returns the 'parent' string rather than a plain bool.
	 */
	public function test_variation_manage_stock_reports_false_when_it_is_inherited_from_the_parent(): void {
		$this->stub_woocommerce(
			array(
				array(
					'id'           => 700,
					'name'         => 'Stock-managed parent',
					'type'         => 'variable',
					'manage_stock' => true,
				),
			)
		);
		WcStubStore::seed(
			701,
			array(
				'id'        => 701,
				'parent_id' => 700,
				'type'      => 'variation',
				'sku'       => 'INHERITS-701',
				// manage_stock deliberately absent: the variation does not manage its own stock,
				// so get_manage_stock() must consult the parent and return 'parent', not a bool.
			)
		);

		$variation = \wc_get_product( 701 );
		$this->assertSame(
			'parent',
			$variation->get_manage_stock(),
			'Fixture setup check: the stub must actually reproduce the string \'parent\' return, or this test proves nothing about the fix.'
		);

		$row = aafm_rich_wc_variation( $variation );

		$this->assertFalse(
			$row['manage_stock'],
			'A variation inheriting manage_stock from its parent does not manage its own stock and must report false, not the truthy cast of the string \'parent\'.'
		);
		$this->assertSame( 'false', wp_json_encode( $row['manage_stock'] ) );
	}

	/**
	 * WooCommerce is never installed in this test environment - WC_Shipping_Zone,
	 * WC_Shipping_Method, and WC_Shipping_Zones come from the IntegrationStubs trait (see
	 * WooShippingTest's class docblock). Define them and reset the process-wide store so each
	 * test starts from a clean slate.
	 *
	 * @return void
	 */
	private function prepare_wc_shipping_stub(): void {
		$this->stub_woocommerce();
		$this->stub_wc_shipping();
		WcShippingStubStore::reset();
	}

	/**
	 * Build a zone shipping method whose configuration lives only in instance_settings,
	 * matching real WooCommerce. Returns the method object the redactor consumes.
	 *
	 * @param string               $method_id Shipping method id.
	 * @param array<string,string> $instance  Instance settings to store.
	 * @return \WC_Shipping_Method
	 */
	private function make_zone_shipping_method( string $method_id, array $instance ) {
		$zone = new \WC_Shipping_Zone( 0 );
		$zone->set_zone_name( 'Fidelity test zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( $method_id );

		update_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', $instance );

		$methods = $zone->get_shipping_methods();
		return $methods[ $instance_id ];
	}
}
