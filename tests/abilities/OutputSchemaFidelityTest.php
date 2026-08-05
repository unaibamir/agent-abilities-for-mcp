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
use AAFM\Tests\WcGatewayStubStore;
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

	/**
	 * Task 13: aafm_allowed_site_settings() is filterable (helpers.php:583-592). A site that
	 * hooks the filter down to an empty list - the natural way to stop exposing settings
	 * without disabling the whole ability - makes the settings map empty, and an empty PHP
	 * array encodes as [] against the declared object schema (settings.php:84).
	 */
	public function test_get_site_settings_encodes_as_an_empty_object_when_the_allowlist_is_filtered_empty(): void {
		add_filter( 'aafm_allowed_site_settings', '__return_empty_array' );

		$result = aafm_exec_get_site_settings();

		$this->assertSame(
			'{}',
			wp_json_encode( $result['settings'] ),
			'settings is declared type:object at settings.php:84; a filter-emptied allowlist must still encode as {} not [].'
		);

		remove_filter( 'aafm_allowed_site_settings', '__return_empty_array' );
	}

	/**
	 * Register just enough abilities/categories to exercise the reusable-block write paths,
	 * mirroring BlocksTest::register_blocks().
	 *
	 * @return void
	 */
	private function register_blocks_abilities(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/create-block', 'aafm/update-block' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Task 14: a hook (or a cache race) that removes the just-written block between the write
	 * returning and the executor's own re-fetch must not fall through to aafm_rich_block()'s
	 * array() fallback, which would encode as [] against the seven-property object output
	 * schema. wp_after_insert_post is the LAST action wp_insert_post() fires - deleting there
	 * reproduces the race without corrupting core's own post-save processing, which has
	 * already finished by the time that action runs.
	 */
	public function test_create_block_null_reread_returns_an_error_not_a_schema_violating_empty_shape(): void {
		$this->register_blocks_abilities();
		$this->acting_as( 'editor' );

		add_action(
			'wp_after_insert_post',
			static function ( int $post_id ): void {
				wp_delete_post( $post_id, true );
			}
		);

		$res = wp_get_ability( 'aafm/create-block' )->execute(
			array(
				'title'   => 'Doomed block',
				'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame(
			'aafm_error',
			$res->get_error_code(),
			'aafm_rich_block() returns array() for a non-WP_Post, which would encode as [] against the seven-property object output schema; the null-reread guard must return aafm_generic_error() instead of that schema-violating empty shape.'
		);
	}

	/**
	 * Task 14, the update-block call site: identical guard, identical race.
	 */
	public function test_update_block_null_reread_returns_an_error_not_a_schema_violating_empty_shape(): void {
		$this->register_blocks_abilities();
		$this->acting_as( 'editor' );
		$id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Doomed update',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			)
		);

		add_action(
			'wp_after_insert_post',
			static function ( int $post_id ): void {
				wp_delete_post( $post_id, true );
			}
		);

		$res = wp_get_ability( 'aafm/update-block' )->execute(
			array(
				'block_id' => $id,
				'content'  => '<!-- wp:list --><ul><li>a</li></ul><!-- /wp:list -->',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame(
			'aafm_error',
			$res->get_error_code(),
			'aafm_rich_block() returns array() for a non-WP_Post, which would encode as [] against the seven-property object output schema; the null-reread guard must return aafm_generic_error() instead of that schema-violating empty shape.'
		);
	}

	/**
	 * Task 15: update-comment's null-reread guard, the same shape as Task 14. wp_update_comment()
	 * fires exactly one 'edit_comment' action and then does its OWN get_comment() re-read (to
	 * feed wp_transition_comment_status()) before it returns - verified against
	 * wp-includes/comment.php:2752-2753. Let that one internal read through untouched, then null
	 * every 'get_comment' call after it, so only the EXECUTOR's own re-read (the one
	 * aafm_exec_update_comment() performs once wp_update_comment() has already returned) sees
	 * null. Nulling the core-internal read too would make wp_update_comment() dereference a null
	 * $comment and trip a PHP warning, which fails this suite's failOnWarning gate for the wrong
	 * reason entirely.
	 */
	public function test_update_comment_null_reread_returns_an_error_not_a_schema_violating_empty_shape(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/update-comment' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
		$this->acting_as( 'editor' );

		$post    = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post,
				'comment_content' => 'before',
			)
		);

		$past_edit        = false;
		$calls_after_edit = 0;
		add_action(
			'edit_comment',
			static function () use ( &$past_edit ): void {
				$past_edit = true;
			}
		);
		add_filter(
			'get_comment',
			static function ( $comment_object ) use ( &$past_edit, &$calls_after_edit ) {
				if ( ! $past_edit ) {
					return $comment_object;
				}
				++$calls_after_edit;
				// The first post-edit_comment call is wp_update_comment()'s own internal read;
				// only the second (the executor's re-read) gets nulled.
				return 1 === $calls_after_edit ? $comment_object : null;
			}
		);

		$res = wp_get_ability( 'aafm/update-comment' )->execute(
			array(
				'comment_id' => $comment,
				'content'    => 'after',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame(
			'aafm_error',
			$res->get_error_code(),
			'aafm_redact_comment() returns array() for a non-WP_Comment, which would encode as [] against the declared object schema; the null-reread guard must return aafm_generic_error() instead of that schema-violating empty shape.'
		);
	}

	/**
	 * Task 16: a gateway that never calls init_settings() - nothing in the WC_Payment_Gateway
	 * contract requires it, even though every WooCommerce core gateway does - leaves $settings
	 * at the class default, an empty array, which encodes as [] against the declared object
	 * schema.
	 */
	public function test_gateway_settings_encodes_as_an_empty_object_when_never_initialized(): void {
		$this->stub_woocommerce();
		$this->stub_wc_gateways();

		$gateway = new \WC_Payment_Gateway(
			array(
				'id'    => 'bare',
				'title' => 'Bare Gateway',
			)
		);

		$row = aafm_wc_gateway_shape( $gateway, 0 );

		$this->assertSame(
			'{}',
			wp_json_encode( $row['settings'] ),
			'settings is declared type:object; a gateway with no initialized settings must still encode as {} not [].'
		);
	}

	/**
	 * Task 16: WC_Payment_Gateway declares $title/$description with no default (this project's
	 * test stub gives them '' only for round-tripping convenience - real WooCommerce does not),
	 * so an unassigned title/description reads back as null against the declared string schema.
	 */
	public function test_gateway_title_and_description_encode_as_strings_even_when_unassigned(): void {
		$this->stub_woocommerce();
		$this->stub_wc_gateways();

		$gateway              = new \WC_Payment_Gateway( array( 'id' => 'bare' ) );
		$gateway->title       = null;
		$gateway->description = null;

		$row = aafm_wc_gateway_shape( $gateway, 0 );

		$this->assertSame( '""', wp_json_encode( $row['title'] ), 'title is declared type:string; an unassigned title must not encode as null.' );
		$this->assertSame( '""', wp_json_encode( $row['description'] ), 'description is declared type:string; an unassigned description must not encode as null.' );
	}

	/**
	 * Task 16: the same missing-default risk reaches wc-list-payment-gateways too, through a
	 * separate hand-built row rather than aafm_wc_gateway_shape(). Reached through the real
	 * exec function (not a direct shape-builder call) because this path is reachable with only
	 * a stub-store fixture, no manual property poke required.
	 */
	public function test_list_payment_gateways_title_encodes_as_a_string_even_when_unassigned(): void {
		$this->stub_woocommerce();
		$this->force_integration( 'woocommerce' );
		$this->stub_wc_gateways();
		WcGatewayStubStore::$gateways['bare'] = array(
			'id'    => 'bare',
			'title' => null,
		);

		$res = aafm_exec_wc_list_payment_gateways( array() );

		$this->assertIsArray( $res );
		$bare = null;
		foreach ( $res['gateways'] as $row ) {
			if ( 'bare' === $row['id'] ) {
				$bare = $row;
			}
		}
		$this->assertNotNull( $bare, 'Fixture setup check: the bare gateway must appear in the list.' );
		$this->assertSame(
			'""',
			wp_json_encode( $bare['title'] ),
			'title is declared type:string in wc-list-payment-gateways; an unassigned title must not encode as null.'
		);
	}

	/**
	 * Task 17: aafm_redact_user()/aafm_rich_user() fall back to an empty shape for a
	 * non-WP_User, which would encode as [] against the 'user' => object declarations
	 * (users.php:170, :322, :465). All three call sites (users.php:201, :263, :412, :647)
	 * already guard against this, so it is unreachable through any ability - call the helpers
	 * directly to prove the backstop itself is correct, rather than fabricating an unreachable
	 * ability path to make it look live.
	 */
	public function test_redact_user_encodes_as_an_empty_object_for_a_non_wp_user(): void {
		$this->assertSame( '{}', wp_json_encode( aafm_redact_user( false ) ) );
	}

	/**
	 * Task 17, the rich-user variant.
	 */
	public function test_rich_user_encodes_as_an_empty_object_for_a_non_wp_user(): void {
		$this->assertSame( '{}', wp_json_encode( aafm_rich_user( false ) ) );
	}

	/**
	 * Task 18: aafm_media_item_payload() only populates $sizes when
	 * wp_get_attachment_image_src() returns truthy, which never happens for a non-image
	 * attachment (PDF, zip, audio) - so every non-image attachment encodes sizes as [] while
	 * every image encodes {}. media.php declares 'media' as a bare type:object with no nested
	 * properties today, so nothing rejects this yet, but it is the same class as issue #81 and
	 * becomes live the moment anyone tightens that schema.
	 */
	public function test_media_item_sizes_encodes_as_an_empty_object_for_a_non_image_attachment(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-media-item' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
		$this->acting_as( 'administrator' );

		$att_id = self::factory()->attachment->create_object(
			'document.pdf',
			0,
			array(
				'post_mime_type' => 'application/pdf',
				'post_type'      => 'attachment',
			)
		);

		$res = wp_get_ability( 'aafm/get-media-item' )->execute( array( 'attachment_id' => $att_id ) );

		$this->assertIsArray( $res );
		$this->assertSame(
			'{}',
			wp_json_encode( $res['media']['sizes'] ),
			'wp_get_attachment_image_src() never returns truthy for a non-image attachment, so sizes stays array() - it must still encode as {} not [], the same class as issue #81.'
		);
	}
}
