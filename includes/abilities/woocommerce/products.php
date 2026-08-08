<?php
/**
 * WooCommerce integration abilities - product reads and writes (sub-slice W4-WC1a).
 *
 * Registers ONLY when WooCommerce is active (aafm_integration_active('woocommerce')); a host-inactive
 * site contributes zero entries to the registry. Every ability gates on the flat, object-independent
 * manage_woocommerce capability and falls through to its real permission_callback at discovery (no
 * server.php case). Shared helpers live in _shared.php, loaded before this file.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_products_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_products_full_definitions' );

/**
 * Contribute the WooCommerce products definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_products_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_products_registry_definitions() );
}

/**
 * Contribute the WooCommerce product definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_products_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_products_registry_definitions() );
}

/**
 * The WooCommerce product registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_products_registry_definitions(): array {
	return array(
		'aafm/wc-list-products'  => array(
			'label'        => __( 'List WooCommerce products', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists WooCommerce products with their id, name, SKU, price, stock status, status, categories, and featured flag, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_products',
		),

		'aafm/wc-get-product'    => array(
			'label'        => __( 'Get WooCommerce product', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one WooCommerce product by id, including its description, prices, stock, images, attributes, variation ids, and categories. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_product',
		),

		'aafm/wc-create-product' => array(
			'label'        => __( 'Create WooCommerce product', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a WooCommerce product from a name (required) plus optional type, status, description, prices, SKU, stock, categories, tags, images, and attributes. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_product',
		),

		'aafm/wc-update-product' => array(
			'label'        => __( 'Update WooCommerce product', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a WooCommerce product by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_product',
		),

		'aafm/wc-delete-product' => array(
			'label'        => __( 'Delete WooCommerce product', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Permanently deletes a WooCommerce product by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_delete_product',
		),
	);
}

/**
 * Resolve a product id to a WC_Product, or null when WooCommerce is unavailable or the id is unknown.
 *
 * @param int $id Product id.
 * @return \WC_Product|null
 */
function aafm_wc_get_product( int $id ): ?\WC_Product {
	if ( $id < 1 || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}
	$product = wc_get_product( $id );
	return $product instanceof \WC_Product ? $product : null;
}

/**
 * The lean list shape for a product: id, name, sku, price, stock_status, status, categories[],
 * featured. No description (list rows stay lean).
 *
 * @param \WC_Product $product Product.
 * @return array<string,mixed>
 */
function aafm_redact_wc_product( \WC_Product $product ): array {
	return array(
		'id'           => (int) $product->get_id(),
		'name'         => (string) $product->get_name(),
		'sku'          => (string) $product->get_sku(),
		'price'        => (string) $product->get_price(),
		'stock_status' => (string) $product->get_stock_status(),
		'status'       => (string) $product->get_status(),
		'categories'   => array_map( 'intval', (array) $product->get_category_ids() ),
		'featured'     => (bool) $product->get_featured(),
	);
}

/**
 * The full single-product shape: the lean fields plus description, short_description, prices, stock,
 * images, attributes (a key=>value-ish map cast to object so an empty one encodes as {}), variation
 * ids, and tags. Never a filesystem path - images are attachment ids, not file paths.
 *
 * @param \WC_Product $product Product.
 * @return array<string,mixed>
 */
function aafm_rich_wc_product( \WC_Product $product ): array {
	$attributes = array();
	foreach ( (array) $product->get_attributes() as $key => $attribute ) {
		$attributes[ (string) $key ] = aafm_wc_attribute_shape( $attribute );
	}

	$base = aafm_redact_wc_product( $product );

	return array_merge(
		$base,
		array(
			'type'              => (string) $product->get_type(),
			'description'       => (string) $product->get_description(),
			'short_description' => (string) $product->get_short_description(),
			'regular_price'     => (string) $product->get_regular_price(),
			'sale_price'        => (string) $product->get_sale_price(),
			'manage_stock'      => (bool) $product->get_manage_stock(),
			'stock_quantity'    => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'tags'              => array_map( 'intval', (array) $product->get_tag_ids() ),
			'image_id'          => (int) $product->get_image_id(),
			// array_values() re-keys sequentially. get_gallery_image_ids() runs the stored
			// _product_image_gallery meta through array_filter() then wp_parse_id_list()
			// (array_unique(array_map('absint', ...))), which are both key-preserving. A gallery
			// meta of "12,0,34" (a gap left by anything writing that meta directly - migrations,
			// importers, third-party plugins - since only WooCommerce's own save path self-heals
			// it) yields [0=>12, 2=>34], which json_encode()s as an OBJECT ({"0":12,"2":34})
			// against a schema declaring type: array. array_values() forces the JSON array shape.
			'images'            => array_values( array_map( 'intval', (array) $product->get_gallery_image_ids() ) ),
			// Cast so an empty attributes map encodes to "{}" (object) per the schema, never "[]".
			'attributes'        => (object) $attributes,
			// Same key-gap risk as images, but it never self-heals here: get_children() on a
			// grouped product reads its child list from the grouped data store, which persists the
			// array verbatim on save (class-wc-product-grouped-data-store-cpt.php:35) rather than
			// imploding it back to a clean sequence the way the gallery meta does.
			// B56: get_children() is not variation-specific - on a grouped product it returns the
			// grouped child PRODUCT ids, which are not variations. Only a variable product's
			// children are variations, so every other type reports an empty list.
			'variation_ids'     => 'variable' === $product->get_type()
				? array_values( array_map( 'intval', (array) $product->get_children() ) )
				: array(),
		)
	);
}

/**
 * Reduce one WC product attribute to a JSON-safe shape (name + options), tolerating either a
 * WC_Product_Attribute object or a plain array. Never returns a path.
 *
 * @param mixed $attribute Attribute (object or array).
 * @return array<string,mixed>
 */
function aafm_wc_attribute_shape( $attribute ): array {
	if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
		$name    = (string) $attribute->get_name();
		$options = method_exists( $attribute, 'get_options' ) ? (array) $attribute->get_options() : array();
	} elseif ( is_array( $attribute ) ) {
		$name    = (string) ( $attribute['name'] ?? '' );
		$options = (array) ( $attribute['options'] ?? array() );
	} else {
		$name    = '';
		$options = array();
	}

	return array(
		'name'    => $name,
		'options' => array_values( array_map( 'sanitize_text_field', array_map( 'strval', $options ) ) ),
	);
}

/**
 * The shared output_schema properties for the full single-product shape - the exact field set
 * aafm_rich_wc_product() emits. Reused by the get/create/update output_schemas so all three stay in
 * lockstep with the rich assembler. `attributes` is an object (an empty map encodes as {}); the
 * list-shaped fields are arrays of integers.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_product_output_properties(): array {
	return array(
		'id'                => array( 'type' => 'integer' ),
		'name'              => array( 'type' => 'string' ),
		'sku'               => array( 'type' => 'string' ),
		'price'             => array( 'type' => 'string' ),
		'stock_status'      => array( 'type' => 'string' ),
		'status'            => array( 'type' => 'string' ),
		'categories'        => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'featured'          => array( 'type' => 'boolean' ),
		'type'              => array( 'type' => 'string' ),
		'description'       => array( 'type' => 'string' ),
		'short_description' => array( 'type' => 'string' ),
		'regular_price'     => array( 'type' => 'string' ),
		'sale_price'        => array( 'type' => 'string' ),
		'manage_stock'      => array( 'type' => 'boolean' ),
		'stock_quantity'    => array( 'type' => array( 'integer', 'null' ) ),
		'tags'              => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'image_id'          => array( 'type' => 'integer' ),
		'images'            => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'attributes'        => array(
			'type'                 => 'object',
			// Keys are attribute slugs; each value is a {name, options[]} pair. The key set is
			// product-defined, so the map is open and the value shape is declared here (A3).
			'additionalProperties' => array(
				'type'       => 'object',
				'properties' => array(
					'name'    => array( 'type' => 'string' ),
					'options' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
		),
		'variation_ids'     => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( 'Ids of this product\'s variations. Populated only for variable products; every other type (including grouped, whose children are separate products, not variations) reports an empty list.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Args for aafm/wc-list-products.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_products(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-list-products' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-products' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => AAFM_LIST_PAGE_MAX,
						'description' => __( 'Page number of products to return, 1-indexed. Defaults to 1.', 'agent-abilities-for-mcp' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Number of products per page, 1 to 100. Defaults to 20.', 'agent-abilities-for-mcp' ),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => "Status filter; 'any' returns all states.",
						'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private' ),
					),
				),
				aafm_lang_schema_fragment()
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'products' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => array( 'type' => 'integer' ),
							'name'         => array( 'type' => 'string' ),
							'sku'          => array( 'type' => 'string' ),
							'price'        => array( 'type' => 'string' ),
							'stock_status' => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
							'categories'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'featured'     => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
					),
				),
				'total'    => array( 'type' => 'integer' ),
				'language' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The WPML language the list was scoped to ("all" for every language), or null when WPML is inactive.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_products',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/wc-list-products.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_products( array $input ) {
	$lang = aafm_resolve_lang( $input );
	if ( is_wp_error( $lang ) ) {
		return $lang;
	}
	$out = array(
		'products' => array(),
		'total'    => 0,
		'language' => $lang,
	);

	if ( ! function_exists( 'wc_get_products' ) ) {
		return $out;
	}

	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';

	// aafm_with_language() returns mixed, so restore the concrete type wc_get_products() promises
	// (an array of WC_Product, or a stdClass carrying ->products/->total when paginate is true) -
	// otherwise the is_object() narrowing below loses stdClass's dynamic-property allowance.
	/**
	 * The raw WooCommerce product query result.
	 *
	 * @var WC_Product[]|\stdClass $query
	 */
	$query = aafm_with_language(
		$lang,
		static function () use ( $per_page, $page, $status ) {
			return wc_get_products(
				array(
					'limit'    => $per_page,
					'page'     => $page,
					'status'   => $status,
					'paginate' => true,
				)
			);
		}
	);

	// With paginate => true WooCommerce returns an object carrying ->products (the page) and ->total
	// (the full matching count); total is the grand total for pagination, not the page row count.
	$products = is_object( $query ) ? (array) $query->products : (array) $query;
	$total    = is_object( $query ) ? (int) $query->total : count( $products );

	foreach ( $products as $product ) {
		if ( $product instanceof \WC_Product ) {
			$out['products'][] = aafm_redact_wc_product( $product );
		}
	}
	$out['total'] = $total;

	return $out;
}

/**
 * Args for aafm/wc-get-product.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_product(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-get-product' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-product' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The WooCommerce product id to read.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_product_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_product',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/wc-get-product.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_get_product( array $input ) {
	$product = aafm_wc_get_product( (int) ( $input['product_id'] ?? 0 ) );
	if ( null === $product ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_product( $product );
}

/**
 * The shared writable product-field properties for the create/update input schemas.
 *
 * MEDIUM-4: every nested object/array-of-objects here is itself additionalProperties:false. The
 * `attributes` items ({name, options}) close their own schema so a smuggled key inside an attribute
 * is rejected before execute, not just a smuggled top-level field. `images` is a flat int list (no
 * nested object to close). The top-level schema layered on top is also closed by each args builder.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_product_write_properties(): array {
	return array(
		'type'              => array(
			'type' => 'string',
			'enum' => array( 'simple', 'grouped', 'external', 'variable' ),
		),
		'status'            => array(
			'type'        => 'string',
			'description' => "The product's publish state.",
			'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
		),
		'description'       => array(
			'type'        => 'string',
			'description' => __( "The product's full description, shown on the single product page. Accepts the same HTML as post content.", 'agent-abilities-for-mcp' ),
		),
		'short_description' => array(
			'type'        => 'string',
			'description' => __( 'A short summary shown near the price on the single product page. Accepts limited HTML.', 'agent-abilities-for-mcp' ),
		),
		'sku'               => array(
			'type'        => 'string',
			'description' => __( "The product's SKU (stock keeping unit). WooCommerce requires SKUs to be unique across products.", 'agent-abilities-for-mcp' ),
		),
		'regular_price'     => array(
			'type'        => 'string',
			'pattern'     => '^\\d+(\\.\\d{1,2})?$',
			'description' => 'A decimal price as a string, e.g. "19.99" (no currency symbol or thousands separator).',
		),
		'sale_price'        => array(
			'type'        => 'string',
			'pattern'     => '^\\d+(\\.\\d{1,2})?$',
			'description' => 'A decimal price as a string, e.g. "14.99". Must be at or below regular_price to take effect.',
		),
		'stock_status'      => array(
			'type'        => 'string',
			'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
			'description' => __( 'Whether the product is in stock, out of stock, or on backorder.', 'agent-abilities-for-mcp' ),
		),
		'stock_quantity'    => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'On-hand quantity (only applied when manage_stock is true).',
		),
		'manage_stock'      => array(
			'type'        => 'boolean',
			'description' => __( 'Whether WooCommerce tracks a numeric stock_quantity for this product.', 'agent-abilities-for-mcp' ),
		),
		'featured'          => array(
			'type'        => 'boolean',
			'description' => __( "Whether the product is marked as featured (used by 'featured products' widgets and shortcodes).", 'agent-abilities-for-mcp' ),
		),
		'categories'        => array(
			'type'        => 'array',
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( "Product category term ids. Replaces the product's current categories.", 'agent-abilities-for-mcp' ),
		),
		'tags'              => array(
			'type'        => 'array',
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( "Product tag term ids. Replaces the product's current tags.", 'agent-abilities-for-mcp' ),
		),
		'image_id'          => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( "Attachment id to use as the product's main image.", 'agent-abilities-for-mcp' ),
		),
		'images'            => array(
			'type'        => 'array',
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( "Attachment ids for the product's image gallery, in display order. Replaces the current gallery.", 'agent-abilities-for-mcp' ),
		),
		'attributes'        => array(
			'type'        => 'array',
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'    => array(
						'type'        => 'string',
						'description' => __( 'Display name of the custom attribute, for example "Material". This is also the key it is upserted by, so reusing an existing name updates that attribute rather than adding a second one.', 'agent-abilities-for-mcp' ),
					),
					'options' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The attribute\'s possible values, for example ["Cotton", "Wool"]. Sending this replaces that attribute\'s full option list rather than appending to it.', 'agent-abilities-for-mcp' ),
					),
				),
				'required'             => array( 'name' ),
				// MEDIUM-4: close the nested attribute object so a smuggled key is rejected.
				'additionalProperties' => false,
			),
			'description' => __( 'Custom (product-level) attributes as {name, options[]} objects. Each is upserted by name; existing custom attributes not included here are left unchanged, not removed. Global/taxonomy attributes already on the product are not affected by this field.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Apply a sanitized, validated input map onto a WC_Product via its setters. Only the keys present in
 * $input are written (PATCH semantics), so an update leaves unsent fields intact. Every value is
 * sanitized for its kind before it reaches a setter; caller input never reaches a setter raw.
 *
 * WC_Product::set_sku() throws WC_Data_Exception when the SKU collides with another product
 * (abstract-wc-product.php: wc_product_has_unique_sku() fails). Caught here rather than left to
 * surface as an uncaught exception; every other setter in this function is not known to throw.
 *
 * @param \WC_Product         $product The product to mutate.
 * @param array<string,mixed> $input   Validated input (already schema-checked).
 * @return \WP_Error|null Null on success, or a WP_Error naming the conflicting SKU.
 */
function aafm_wc_apply_product_input( \WC_Product $product, array $input ): ?\WP_Error {
	if ( array_key_exists( 'name', $input ) ) {
		$product->set_name( sanitize_text_field( (string) $input['name'] ) );
	}
	if ( array_key_exists( 'status', $input ) ) {
		$product->set_status( sanitize_key( (string) $input['status'] ) );
	}
	if ( array_key_exists( 'sku', $input ) ) {
		$sku = sanitize_text_field( (string) $input['sku'] );
		try {
			$product->set_sku( $sku );
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error(
				'aafm_wc_duplicate_sku',
				sprintf(
					/* translators: %s: the conflicting SKU value. */
					__( 'The SKU "%s" is already in use by another product.', 'agent-abilities-for-mcp' ),
					$sku
				)
			);
		}
	}
	if ( array_key_exists( 'description', $input ) ) {
		$product->set_description( wp_kses_post( (string) $input['description'] ) );
	}
	if ( array_key_exists( 'short_description', $input ) ) {
		$product->set_short_description( wp_kses_post( (string) $input['short_description'] ) );
	}
	if ( array_key_exists( 'regular_price', $input ) ) {
		$product->set_regular_price( aafm_wc_sanitize_price( $input['regular_price'] ) );
	}
	if ( array_key_exists( 'sale_price', $input ) ) {
		$product->set_sale_price( aafm_wc_sanitize_price( $input['sale_price'] ) );
	}
	// stock_status is derived by WooCommerce from stock_quantity whenever stock is managed, so
	// validate_props() overwrites a caller-supplied stock_status on save. Refuse the contradictory
	// pair rather than report a success that never applied.
	$manage_after = array_key_exists( 'manage_stock', $input )
		? (bool) $input['manage_stock']
		: (bool) $product->get_manage_stock();
	if ( array_key_exists( 'stock_status', $input ) && $manage_after ) {
		return new \WP_Error(
			'aafm_stock_status_derived',
			__( 'stock_status cannot be set while manage_stock is true: WooCommerce derives it from stock_quantity. Set stock_quantity instead, or set manage_stock to false.', 'agent-abilities-for-mcp' )
		);
	}
	if ( array_key_exists( 'stock_status', $input ) ) {
		$product->set_stock_status( sanitize_key( (string) $input['stock_status'] ) );
	}
	if ( array_key_exists( 'stock_quantity', $input ) ) {
		$product->set_stock_quantity( (int) $input['stock_quantity'] );
	}
	if ( array_key_exists( 'manage_stock', $input ) ) {
		$product->set_manage_stock( (bool) $input['manage_stock'] );
	}
	if ( array_key_exists( 'featured', $input ) ) {
		$product->set_featured( (bool) $input['featured'] );
	}
	if ( array_key_exists( 'categories', $input ) ) {
		$product->set_category_ids( array_map( 'absint', (array) $input['categories'] ) );
	}
	if ( array_key_exists( 'tags', $input ) ) {
		$product->set_tag_ids( array_map( 'absint', (array) $input['tags'] ) );
	}
	if ( array_key_exists( 'image_id', $input ) ) {
		$product->set_image_id( absint( $input['image_id'] ) );
	}
	if ( array_key_exists( 'images', $input ) ) {
		$product->set_gallery_image_ids( array_map( 'absint', (array) $input['images'] ) );
	}
	if ( array_key_exists( 'attributes', $input ) ) {
		$sanitized = aafm_wc_sanitize_attributes( (array) $input['attributes'] );
		$product->set_attributes(
			aafm_wc_build_product_attributes( $sanitized, (array) $product->get_attributes() )
		);
	}
	return null;
}

/**
 * Build the WC_Product_Attribute set to write, merging the caller's attributes onto the product's
 * existing ones by slug.
 *
 * WC_Product::set_attributes() keeps ONLY WC_Product_Attribute instances and pre-nulls every
 * existing attribute key (abstract-wc-product.php set_attributes()). Passing plain {name, options}
 * arrays therefore silently drops the requested attributes on create, and on update the pre-null
 * wipes whatever the caller did not resend (orphaning any variations built on those attributes).
 * This rebuilds each requested attribute as a real object and seeds the set with the existing
 * attributes so unmentioned ones survive: sent attributes are upserted by slug, unsent ones are
 * preserved.
 *
 * Caller-sent attributes are treated as custom (product-level) attributes: id 0, plain-string
 * options, visible by default. The input schema only models custom attributes, so a global/taxonomy
 * attribute the caller does not name is preserved untouched but is never fabricated from strings.
 *
 * @param array<int,array<string,mixed>> $sanitized Sanitized {name, options[]} items from the input.
 * @param array<int|string,mixed>        $existing  The product's current attributes (objects or arrays).
 * @return array<int,\WC_Product_Attribute>
 */
function aafm_wc_build_product_attributes( array $sanitized, array $existing ): array {
	if ( ! class_exists( 'WC_Product_Attribute' ) ) {
		return array();
	}

	// Seed the merged set with the product's existing attributes, keyed by slug, so any the caller
	// does not resend outlives set_attributes()'s pre-null.
	$by_slug  = array();
	$next_pos = 0;
	foreach ( $existing as $attribute ) {
		$object = aafm_wc_coerce_product_attribute( $attribute );
		if ( null === $object || '' === (string) $object->get_name() ) {
			continue;
		}
		$by_slug[ sanitize_title( (string) $object->get_name() ) ] = $object;
		$next_pos = max( $next_pos, (int) $object->get_position() + 1 );
	}

	foreach ( $sanitized as $item ) {
		$name = (string) ( $item['name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}
		$slug     = sanitize_title( $name );
		$previous = $by_slug[ $slug ] ?? null;

		$object = new \WC_Product_Attribute();
		$object->set_id( 0 );
		$object->set_name( $name );
		$object->set_options( array_values( (array) ( $item['options'] ?? array() ) ) );
		if ( $previous instanceof \WC_Product_Attribute ) {
			// Replacing an existing attribute: keep its visibility, variation flag, and slot so a
			// value edit does not silently unhide it or drop it from the product's variations.
			$object->set_visible( (bool) $previous->get_visible() );
			$object->set_variation( (bool) $previous->get_variation() );
			$object->set_position( (int) $previous->get_position() );
		} else {
			$object->set_visible( true );
			$object->set_variation( false );
			$object->set_position( $next_pos );
			++$next_pos;
		}

		$by_slug[ $slug ] = $object;
	}

	return array_values( $by_slug );
}

/**
 * Coerce a stored attribute (a WC_Product_Attribute object or a WC-shaped array) into a
 * WC_Product_Attribute, so existing attributes can be carried through a merge write. Returns null
 * for anything that cannot become an attribute.
 *
 * @param mixed $attribute Stored attribute.
 * @return \WC_Product_Attribute|null
 */
function aafm_wc_coerce_product_attribute( $attribute ): ?\WC_Product_Attribute {
	if ( ! class_exists( 'WC_Product_Attribute' ) ) {
		return null;
	}
	if ( $attribute instanceof \WC_Product_Attribute ) {
		return $attribute;
	}
	if ( ! is_array( $attribute ) ) {
		return null;
	}

	$object = new \WC_Product_Attribute();
	$object->set_id( (int) ( $attribute['id'] ?? 0 ) );
	$object->set_name( (string) ( $attribute['name'] ?? '' ) );
	$object->set_options( array_values( (array) ( $attribute['options'] ?? array() ) ) );
	$object->set_position( (int) ( $attribute['position'] ?? 0 ) );
	$object->set_visible( (bool) ( $attribute['visible'] ?? true ) );
	$object->set_variation( (bool) ( $attribute['variation'] ?? false ) );
	return $object;
}

/**
 * Sanitize a list of attribute input items to {name, options[]} maps, dropping anything else.
 *
 * @param array<int,mixed> $attributes Raw attribute items.
 * @return array<int,array<string,mixed>>
 */
function aafm_wc_sanitize_attributes( array $attributes ): array {
	$clean = array();
	foreach ( $attributes as $attribute ) {
		if ( ! is_array( $attribute ) ) {
			continue;
		}
		$options = array();
		foreach ( (array) ( $attribute['options'] ?? array() ) as $option ) {
			$options[] = sanitize_text_field( (string) $option );
		}
		$clean[] = array(
			'name'    => sanitize_text_field( (string) ( $attribute['name'] ?? '' ) ),
			'options' => $options,
		);
	}
	return $clean;
}

/**
 * Args for aafm/wc-create-product.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_product(): array {
	$properties         = aafm_wc_product_write_properties();
	$properties['name'] = array(
		'type'        => 'string',
		'minLength'   => 1,
		'description' => __( "The product's title. Required; the product is not created without one.", 'agent-abilities-for-mcp' ),
	);
	// This ability only builds simple products (see aafm_exec_wc_create_product): a non-simple
	// request is rejected rather than silently downgraded, so the create-only restriction is
	// documented here rather than on the shared write-properties description, which is also the
	// base for the update ability's own type description (aafm_args_wc_update_product()).
	$properties['type']['description'] = __( "The product's type. This ability only creates simple products; requesting grouped, external, or variable fails.", 'agent-abilities-for-mcp' );

	return array(
		'label'               => aafm_ability_label( 'aafm/wc-create-product' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-product' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_product_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_product',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-create-product.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_create_product( array $input ) {
	if ( ! class_exists( 'WC_Product' ) ) {
		return aafm_generic_error();
	}
	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	if ( '' === $name ) {
		return aafm_generic_error();
	}

	// This generic create only builds simple products. A variable/grouped/external product is a
	// different construction (and, for variable, needs variations added afterward), so reject a
	// non-simple request rather than silently downgrading it to a simple product and reporting
	// success. The schema enumerates the other types, but they are not honored here.
	$requested_type = isset( $input['type'] ) ? (string) $input['type'] : 'simple';
	if ( 'simple' !== $requested_type ) {
		return aafm_generic_error();
	}

	$product = new \WC_Product();
	unset( $input['type'] );
	$error = aafm_wc_apply_product_input( $product, $input );
	if ( null !== $error ) {
		return $error;
	}
	$id = (int) $product->save();

	$saved = aafm_wc_get_product( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_product( $saved );
}

/**
 * Args for aafm/wc-update-product.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_product(): array {
	$properties               = aafm_wc_product_write_properties();
	$properties['product_id'] = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'The WooCommerce product id to update.', 'agent-abilities-for-mcp' ),
	);
	$properties['name']       = array(
		'type'        => 'string',
		'minLength'   => 1,
		'description' => __( "The product's title. Omit to leave the existing title unchanged.", 'agent-abilities-for-mcp' ),
	);
	// A product's type cannot be changed once created (converting between simple/grouped/
	// external/variable is a non-trivial operation this ability does not implement -- see
	// aafm_exec_wc_update_product()). Sending a value that matches the product's current type is
	// accepted as a no-op; a mismatched value is refused with an error rather than silently
	// discarded, so this override replaces the create-facing description (which does not apply
	// here) with one that is true for update, mirroring the `attributes` override in
	// aafm_args_wc_update_product_variation() (variations.php).
	$properties['type']['description'] = __( "The product's current type. A value matching the product's existing type is accepted as a no-op; a different value is rejected with an error, since converting a product between simple, grouped, external, and variable is not supported. Omit this field to leave the type unchanged.", 'agent-abilities-for-mcp' );

	return array(
		'label'               => aafm_ability_label( 'aafm/wc-update-product' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-product' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_product_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_product',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-update-product.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_update_product( array $input ) {
	$product = aafm_wc_get_product( (int) ( $input['product_id'] ?? 0 ) );
	if ( null === $product ) {
		return aafm_generic_error();
	}

	// `type` cannot be changed on update -- converting a product between simple/grouped/external/
	// variable is a non-trivial operation this ability does not implement. A caller-sent value
	// matching the product's actual current type is a harmless no-op; a mismatched value used to
	// be silently discarded (the caller saw success and an unconverted product). Refuse it instead
	// so a caller trying to change the type learns immediately, rather than papering over the gap.
	if ( array_key_exists( 'type', $input ) ) {
		$current_type = $product->get_type();
		if ( (string) $input['type'] !== $current_type ) {
			return aafm_wc_product_type_mismatch_error( $current_type, (string) $input['type'] );
		}
	}

	unset( $input['product_id'], $input['type'] );
	$error = aafm_wc_apply_product_input( $product, $input );
	if ( null !== $error ) {
		return $error;
	}
	$id = (int) $product->save();

	$saved = aafm_wc_get_product( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_product( $saved );
}

/**
 * Build the WP_Error returned when aafm/wc-update-product receives a `type` that does not match
 * the product's actual current type. Converting a product between simple/grouped/external/
 * variable is out of scope for this ability -- a matching value is treated as a no-op elsewhere in
 * aafm_exec_wc_update_product(); this error is only for a genuine mismatch.
 *
 * @param string $current_type   The product's actual current type.
 * @param string $requested_type The type the caller sent.
 * @return \WP_Error
 */
function aafm_wc_product_type_mismatch_error( string $current_type, string $requested_type ): \WP_Error {
	return new \WP_Error(
		'aafm_wc_product_type_mismatch',
		sprintf(
			/* translators: 1: the product's actual current type. 2: the type the caller requested. */
			__( 'This product is a %1$s product and cannot be changed to %2$s -- converting a product between types is not supported. Omit type, or send its current value, to leave it unchanged.', 'agent-abilities-for-mcp' ),
			$current_type,
			$requested_type
		)
	);
}

/**
 * Args for aafm/wc-delete-product.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_product(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-delete-product' ),
		'description'         => aafm_ability_description( 'aafm/wc-delete-product' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The WooCommerce product id to permanently delete.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_product',
		'permission_callback' => 'aafm_perm_wc_delete_product',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Whether the current user may delete this specific product object.
 *
 * Mirrors the per-object pattern the post abilities use for delete
 * (aafm_can_delete_post_object() in includes/helpers.php): gate on the object's
 * own relationship to the caller via the post type's meta capability, not only
 * a capability floor. WooCommerce registers 'product' with map_meta_cap => true
 * and its own capability_type ('product'), so current_user_can() resolves
 * delete_products (own) vs delete_others_products the same way core resolves
 * delete_posts vs delete_others_posts.
 *
 * @param WP_Post $product The product's backing post object.
 * @return bool
 */
function aafm_wc_can_delete_product_object( WP_Post $product ): bool {
	$type = get_post_type_object( $product->post_type );
	if ( ! $type instanceof WP_Post_Type || ! $type->map_meta_cap ) {
		return false;
	}
	return current_user_can( $type->cap->delete_post, $product->ID );
}

/**
 * Permission for aafm/wc-delete-product: the capability floor (manage_woocommerce) AND
 * the caller's own relationship to the specific product, not the floor alone.
 *
 * A nonexistent id, or a product with no real backing WP_Post to gate on, falls back to
 * the floor already checked: there is nothing more specific to authorize against, and the
 * WC data store (not a missing capability) is what reports "not found" from execute().
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_wc_delete_product( array $input ): bool {
	if ( ! aafm_wc_perm() ) {
		return false;
	}
	$id      = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
	$product = $id ? aafm_wc_get_product( $id ) : null;
	if ( null === $product ) {
		return true;
	}
	$post = get_post( $product->get_id() );
	if ( ! $post instanceof WP_Post ) {
		return true;
	}
	return aafm_wc_can_delete_product_object( $post );
}

/**
 * Execute aafm/wc-delete-product.
 *
 * Permanent removal through WooCommerce's own data store: $product->delete( true ). The force flag is
 * WC's permanent-delete semantics (a product has no recoverable WC trash for this surface). This is
 * the WooCommerce object's own delete method, NOT the core post force-delete primitive, so it never
 * touches that governed call site or the source-scan that bans it.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_delete_product( array $input ) {
	$id      = (int) ( $input['product_id'] ?? 0 );
	$product = aafm_wc_get_product( $id );
	if ( null === $product ) {
		return aafm_generic_error();
	}
	$product->delete( true );
	// WC_Data::delete() returns true whenever a data store exists, and a loaded product always has
	// one, so its return never signals a store-level failure. Verify the row is actually gone by
	// re-reading rather than trusting the return.
	if ( null !== aafm_wc_get_product( $id ) ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}
