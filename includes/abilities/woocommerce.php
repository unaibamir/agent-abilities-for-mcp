<?php
/**
 * WooCommerce integration abilities — product reads and writes (sub-slice W4-WC1a).
 *
 * Registers ONLY when WooCommerce is active (aafm_integration_active('woocommerce')); a host-inactive
 * site contributes zero entries to the registry. Every product ability gates on the flat,
 * object-independent manage_woocommerce capability — the same capability WordPress puts on the
 * WooCommerce admin screens — so each is object-independent and falls through to its real
 * permission_callback at discovery (no server.php case). All product data is read and written through
 * WooCommerce's own CRUD layer (wc_get_products / wc_get_product / WC_Product getters + setters +
 * save/delete), never a raw $wpdb query, and the redactor/assembler never returns a filesystem path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_woocommerce_definitions' );

/**
 * Contribute the WooCommerce product definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_woocommerce_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	$registry['aafm/wc-list-products']  = array(
		'label'        => __( 'List WooCommerce products', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists WooCommerce products with their id, name, SKU, price, stock status, status, categories, and featured flag, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_products',
	);
	$registry['aafm/wc-get-product']    = array(
		'label'        => __( 'Get WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce product by id, including its description, prices, stock, images, attributes, variation ids, and categories. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_product',
	);
	$registry['aafm/wc-create-product'] = array(
		'label'        => __( 'Create WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce product from a name (required) plus optional type, status, description, prices, SKU, stock, categories, tags, images, and attributes. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_product',
	);
	$registry['aafm/wc-update-product'] = array(
		'label'        => __( 'Update WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce product by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_product',
	);
	$registry['aafm/wc-delete-product'] = array(
		'label'        => __( 'Delete WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce product by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_product',
	);

	// Variations (sub-slice W4-WC1b) — a variable product's child variations, parent_id-scoped.
	$registry['aafm/wc-list-product-variations']  = array(
		'label'        => __( 'List WooCommerce product variations', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists a variable product\'s variations by parent product id, each with its id, parent id, SKU, price, stock status, and status, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_product_variations',
	);
	$registry['aafm/wc-get-product-variation']    = array(
		'label'        => __( 'Get WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one product variation by id, including its parent id, prices, stock, description, image, and its chosen attribute values. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_product_variation',
	);
	$registry['aafm/wc-create-product-variation'] = array(
		'label'        => __( 'Create WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a variation under a variable product (parent product id required) from optional status, description, prices, SKU, stock, image, and attribute values. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_product_variation',
	);
	$registry['aafm/wc-update-product-variation'] = array(
		'label'        => __( 'Update WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a product variation by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_product_variation',
	);
	$registry['aafm/wc-delete-product-variation'] = array(
		'label'        => __( 'Delete WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a product variation by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_product_variation',
	);

	// Global product attributes (sub-slice W4-WC1c) — the attribute taxonomy surface reached through
	// wc_get_attribute_taxonomies() / wc_create_attribute() / wc_update_attribute() / wc_delete_attribute().
	// Every ability gates on the flat, object-independent manage_woocommerce capability and falls through
	// to its real permission_callback at discovery, so none needs a server.php case.
	$registry['aafm/wc-list-product-attributes']  = array(
		'label'        => __( 'List WooCommerce product attributes', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists all global WooCommerce product attribute taxonomies with their id, name (label), slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_product_attributes',
	);
	$registry['aafm/wc-get-product-attribute']    = array(
		'label'        => __( 'Get WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one global WooCommerce product attribute taxonomy by id, including its name, slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_product_attribute',
	);
	$registry['aafm/wc-create-product-attribute'] = array(
		'label'        => __( 'Create WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a new global WooCommerce product attribute taxonomy from a name (required) plus optional slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_product_attribute',
	);
	$registry['aafm/wc-update-product-attribute'] = array(
		'label'        => __( 'Update WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a global WooCommerce product attribute taxonomy by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_product_attribute',
	);
	$registry['aafm/wc-delete-product-attribute'] = array(
		'label'        => __( 'Delete WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently removes a global WooCommerce product attribute taxonomy by id. This deletes the taxonomy and all terms within it and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_product_attribute',
	);

	return $registry;
}

/**
 * The object-independent permission floor for every WooCommerce product ability: the caller holds
 * the manage_woocommerce capability (the cap WordPress puts on the WooCommerce admin screens).
 *
 * Used as each ability's permission_callback directly. Because it takes no object id, the abilities
 * are object-independent and fall through to this callback at discovery with empty input — the
 * correct discovery answer — so none needs a server.php case.
 *
 * @return bool
 */
function aafm_wc_perm(): bool {
	return current_user_can( 'manage_woocommerce' );
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
 * ids, and tags. Never a filesystem path — images are attachment ids, not file paths.
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
			'images'            => array_map( 'intval', (array) $product->get_gallery_image_ids() ),
			// Cast so an empty attributes map encodes to "{}" (object) per the schema, never "[]".
			'attributes'        => (object) $attributes,
			'variation_ids'     => array_map( 'intval', (array) $product->get_children() ),
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
 * The shared output_schema properties for the full single-product shape — the exact field set
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
		'attributes'        => array( 'type' => 'object' ),
		'variation_ids'     => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
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
		'label'               => __( 'List WooCommerce products', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists WooCommerce products (id, name, SKU, price, stock status, status, categories, featured) plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'status'   => array(
					'type'        => 'string',
					'description' => "Status filter; 'any' returns all states.",
					'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private' ),
				),
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
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_products',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-list-products.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_wc_list_products( array $input ): array {
	$out = array(
		'products' => array(),
		'total'    => 0,
	);

	if ( ! function_exists( 'wc_get_products' ) ) {
		return $out;
	}

	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';

	$query = wc_get_products(
		array(
			'limit'    => $per_page,
			'page'     => $page,
			'status'   => $status,
			'paginate' => true,
		)
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
		'label'               => __( 'Get WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce product by id (full shape incl. images, attributes, variation ids). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		'description'       => array( 'type' => 'string' ),
		'short_description' => array( 'type' => 'string' ),
		'sku'               => array( 'type' => 'string' ),
		'regular_price'     => array( 'type' => 'string' ),
		'sale_price'        => array( 'type' => 'string' ),
		'stock_status'      => array(
			'type' => 'string',
			'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
		),
		'stock_quantity'    => array( 'type' => 'integer' ),
		'manage_stock'      => array( 'type' => 'boolean' ),
		'featured'          => array( 'type' => 'boolean' ),
		'categories'        => array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		'tags'              => array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		'image_id'          => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'images'            => array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		'attributes'        => array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'    => array( 'type' => 'string' ),
					'options' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'             => array( 'name' ),
				// MEDIUM-4: close the nested attribute object so a smuggled key is rejected.
				'additionalProperties' => false,
			),
		),
	);
}

/**
 * Apply a sanitized, validated input map onto a WC_Product via its setters. Only the keys present in
 * $input are written (PATCH semantics), so an update leaves unsent fields intact. Every value is
 * sanitized for its kind before it reaches a setter; caller input never reaches a setter raw.
 *
 * @param \WC_Product         $product The product to mutate.
 * @param array<string,mixed> $input   Validated input (already schema-checked).
 * @return void
 */
function aafm_wc_apply_product_input( \WC_Product $product, array $input ): void {
	if ( array_key_exists( 'name', $input ) ) {
		$product->set_name( sanitize_text_field( (string) $input['name'] ) );
	}
	if ( array_key_exists( 'status', $input ) ) {
		$product->set_status( sanitize_key( (string) $input['status'] ) );
	}
	if ( array_key_exists( 'sku', $input ) ) {
		$product->set_sku( sanitize_text_field( (string) $input['sku'] ) );
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
		$product->set_attributes( aafm_wc_sanitize_attributes( (array) $input['attributes'] ) );
	}
}

/**
 * Sanitize a price-like string to a bare decimal: strips every character except digits and the
 * decimal point (currency symbols, spaces, thousands separators, and any minus sign all go).
 *
 * @param mixed $value Raw price.
 * @return string
 */
function aafm_wc_sanitize_price( $value ): string {
	$clean = preg_replace( '/[^0-9.]/', '', (string) $value );
	return is_string( $clean ) ? $clean : '';
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
		'type'      => 'string',
		'minLength' => 1,
	);

	return array(
		'label'               => __( 'Create WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce product (name required; type, status, description, prices, SKU, stock, categories, tags, images, attributes optional). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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

	$product = new \WC_Product();
	// The product type is fixed by the WC_Product subclass at construction in real WooCommerce, so the
	// generic create here applies the remaining fields onto a simple product and ignores `type`.
	unset( $input['type'] );
	aafm_wc_apply_product_input( $product, $input );
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
		'type'    => 'integer',
		'minimum' => 1,
	);
	$properties['name']       = array(
		'type'      => 'string',
		'minLength' => 1,
	);

	return array(
		'label'               => __( 'Update WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce product by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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
	unset( $input['product_id'], $input['type'] );
	aafm_wc_apply_product_input( $product, $input );
	$id = (int) $product->save();

	$saved = aafm_wc_get_product( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_product( $saved );
}

/**
 * Args for aafm/wc-delete-product.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_product(): array {
	return array(
		'label'               => __( 'Delete WooCommerce product', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a WooCommerce product by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
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

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}

/*
 * --------------------------------------------------------------------------
 * Variations (sub-slice W4-WC1b) — a variable product's child variations.
 *
 * A variation is parent_id-scoped: list takes the parent product id and returns its children; get /
 * create / update / delete operate on a single variation by its own id. Everything flows through the
 * WC CRUD layer (wc_get_product / new WC_Product_Variation + getters/setters/save/delete), never a
 * raw $wpdb query. A variation's attributes are a flat name=>value map (the variation's chosen
 * values), unlike a variable parent's attribute objects.
 * --------------------------------------------------------------------------
 */

/**
 * Resolve a variation id to a WC_Product_Variation, or null when WooCommerce is unavailable, the id
 * is unknown, or the product at that id is not actually a variation.
 *
 * @param int $id Variation id.
 * @return \WC_Product_Variation|null
 */
function aafm_wc_get_variation( int $id ): ?\WC_Product_Variation {
	if ( $id < 1 || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}
	$variation = wc_get_product( $id );
	if ( $variation instanceof \WC_Product_Variation ) {
		return $variation;
	}
	// A non-variation product (or false) at this id is not a valid variation target.
	if ( $variation instanceof \WC_Product && 'variation' === $variation->get_type() && class_exists( 'WC_Product_Variation' ) ) {
		return new \WC_Product_Variation( $id );
	}
	return null;
}

/**
 * The lean list shape for a variation: id, parent_id, sku, price, stock_status, status. No
 * description, no attributes (list rows stay lean).
 *
 * @param \WC_Product_Variation $variation Variation.
 * @return array<string,mixed>
 */
function aafm_redact_wc_variation( \WC_Product_Variation $variation ): array {
	return array(
		'id'           => (int) $variation->get_id(),
		'parent_id'    => (int) $variation->get_parent_id(),
		'sku'          => (string) $variation->get_sku(),
		'price'        => (string) $variation->get_price(),
		'stock_status' => (string) $variation->get_stock_status(),
		'status'       => (string) $variation->get_status(),
	);
}

/**
 * The full single-variation shape: the lean fields plus description, prices, stock, image, and the
 * variation's chosen attribute values (a flat name=>value map cast to object so an empty one encodes
 * as {}). Never a filesystem path — the image is an attachment id, not a file path.
 *
 * @param \WC_Product_Variation $variation Variation.
 * @return array<string,mixed>
 */
function aafm_rich_wc_variation( \WC_Product_Variation $variation ): array {
	$attributes = array();
	foreach ( (array) $variation->get_attributes() as $key => $value ) {
		$attributes[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
	}

	$base = aafm_redact_wc_variation( $variation );

	return array_merge(
		$base,
		array(
			'description'    => (string) $variation->get_description(),
			'regular_price'  => (string) $variation->get_regular_price(),
			'sale_price'     => (string) $variation->get_sale_price(),
			'manage_stock'   => (bool) $variation->get_manage_stock(),
			'stock_quantity' => null === $variation->get_stock_quantity() ? null : (int) $variation->get_stock_quantity(),
			'image_id'       => (int) $variation->get_image_id(),
			// Cast so an empty attributes map encodes to "{}" (object) per the schema, never "[]".
			'attributes'     => (object) $attributes,
		)
	);
}

/**
 * The shared output_schema properties for the full single-variation shape — the exact field set
 * aafm_rich_wc_variation() emits. Reused by the get/create/update output_schemas so all three stay in
 * lockstep with the rich assembler. `attributes` is an object (an empty map encodes as {}).
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_variation_output_properties(): array {
	return array(
		'id'             => array( 'type' => 'integer' ),
		'parent_id'      => array( 'type' => 'integer' ),
		'sku'            => array( 'type' => 'string' ),
		'price'          => array( 'type' => 'string' ),
		'stock_status'   => array( 'type' => 'string' ),
		'status'         => array( 'type' => 'string' ),
		'description'    => array( 'type' => 'string' ),
		'regular_price'  => array( 'type' => 'string' ),
		'sale_price'     => array( 'type' => 'string' ),
		'manage_stock'   => array( 'type' => 'boolean' ),
		'stock_quantity' => array( 'type' => array( 'integer', 'null' ) ),
		'image_id'       => array( 'type' => 'integer' ),
		'attributes'     => array( 'type' => 'object' ),
	);
}

/**
 * Args for aafm/wc-list-product-variations.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_product_variations(): array {
	return array(
		'label'               => __( 'List WooCommerce product variations', 'agent-abilities-for-mcp' ),
		'description'         => __( "Lists a variable product's variations by parent product id (id, parent id, SKU, price, stock status, status) plus a total. Requires the manage-WooCommerce capability.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'The parent (variable) product id.',
				),
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'variations' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => array( 'type' => 'integer' ),
							'parent_id'    => array( 'type' => 'integer' ),
							'sku'          => array( 'type' => 'string' ),
							'price'        => array( 'type' => 'string' ),
							'stock_status' => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'total'      => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_product_variations',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-list-product-variations.
 *
 * Resolves the parent product, then loads each child id as a variation. Supports page/per_page
 * paging over the child id list, with `total` reporting the full child count for pagination.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_list_product_variations( array $input ) {
	$parent = aafm_wc_get_product( (int) ( $input['product_id'] ?? 0 ) );
	if ( null === $parent ) {
		// Deliberately unlike wc-list-products: a missing parent is a genuine error here because
		// variations require a parent to scope to, whereas a bare product list can legitimately be empty.
		return aafm_generic_error();
	}

	$child_ids = array_map( 'intval', (array) $parent->get_children() );
	$total     = count( $child_ids );

	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$offset   = ( $page - 1 ) * $per_page;
	$page_ids = array_slice( $child_ids, $offset, $per_page );

	$variations = array();
	foreach ( $page_ids as $child_id ) {
		$variation = aafm_wc_get_variation( (int) $child_id );
		if ( null !== $variation ) {
			$variations[] = aafm_redact_wc_variation( $variation );
		}
	}

	return array(
		'variations' => $variations,
		'total'      => $total,
	);
}

/**
 * Args for aafm/wc-get-product-variation.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_product_variation(): array {
	return array(
		'label'               => __( 'Get WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one product variation by id (full shape incl. parent id, prices, stock, image, attribute values). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'variation_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'variation_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_variation_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_product_variation',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-get-product-variation.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_get_product_variation( array $input ) {
	$variation = aafm_wc_get_variation( (int) ( $input['variation_id'] ?? 0 ) );
	if ( null === $variation ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_variation( $variation );
}

/**
 * The shared writable variation-field properties for the create/update input schemas.
 *
 * MEDIUM-4: the one nested structure here is `attributes`, a free-key map of attribute name => chosen
 * value. It is closed to string values only (`additionalProperties` is a string sub-schema), so a
 * smuggled NESTED structure (an object/array value) is rejected before execute — the flat-map
 * equivalent of additionalProperties:false on a fixed object. The top-level schema layered on top is
 * also closed by each args builder.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_variation_write_properties(): array {
	return array(
		'status'         => array(
			'type'        => 'string',
			'description' => "The variation's publish state.",
			'enum'        => array( 'publish', 'private' ),
		),
		'description'    => array( 'type' => 'string' ),
		'sku'            => array( 'type' => 'string' ),
		'regular_price'  => array( 'type' => 'string' ),
		'sale_price'     => array( 'type' => 'string' ),
		'stock_status'   => array(
			'type' => 'string',
			'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
		),
		'stock_quantity' => array( 'type' => 'integer' ),
		'manage_stock'   => array( 'type' => 'boolean' ),
		'image_id'       => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'attributes'     => array(
			'type'                 => 'object',
			'description'          => 'A flat map of attribute name to the chosen value (strings only).',
			// MEDIUM-4: close the free-key map to string values so a smuggled nested structure is
			// rejected. A fixed additionalProperties:false is impossible on a free-key map, so the
			// string sub-schema is the equivalent closure.
			'additionalProperties' => array( 'type' => 'string' ),
		),
	);
}

/**
 * Apply a sanitized, validated input map onto a WC_Product_Variation via its setters. Only the keys
 * present in $input are written (PATCH semantics). Every value is sanitized for its kind before it
 * reaches a setter; caller input never reaches a setter raw.
 *
 * @param \WC_Product_Variation $variation The variation to mutate.
 * @param array<string,mixed>   $input     Validated input (already schema-checked).
 * @return void
 */
function aafm_wc_apply_variation_input( \WC_Product_Variation $variation, array $input ): void {
	if ( array_key_exists( 'status', $input ) ) {
		$variation->set_status( sanitize_key( (string) $input['status'] ) );
	}
	if ( array_key_exists( 'sku', $input ) ) {
		$variation->set_sku( sanitize_text_field( (string) $input['sku'] ) );
	}
	if ( array_key_exists( 'description', $input ) ) {
		$variation->set_description( wp_kses_post( (string) $input['description'] ) );
	}
	if ( array_key_exists( 'regular_price', $input ) ) {
		$variation->set_regular_price( aafm_wc_sanitize_price( $input['regular_price'] ) );
	}
	if ( array_key_exists( 'sale_price', $input ) ) {
		$variation->set_sale_price( aafm_wc_sanitize_price( $input['sale_price'] ) );
	}
	if ( array_key_exists( 'stock_status', $input ) ) {
		$variation->set_stock_status( sanitize_key( (string) $input['stock_status'] ) );
	}
	if ( array_key_exists( 'stock_quantity', $input ) ) {
		$variation->set_stock_quantity( (int) $input['stock_quantity'] );
	}
	if ( array_key_exists( 'manage_stock', $input ) ) {
		$variation->set_manage_stock( (bool) $input['manage_stock'] );
	}
	if ( array_key_exists( 'image_id', $input ) ) {
		$variation->set_image_id( absint( $input['image_id'] ) );
	}
	if ( array_key_exists( 'attributes', $input ) ) {
		$variation->set_attributes( aafm_wc_sanitize_variation_attributes( (array) $input['attributes'] ) );
	}
}

/**
 * Sanitize a variation's flat attribute map: each key is a taxonomy-like slug (sanitize_title) and
 * each value a plain string (sanitize_text_field). Non-scalar values are dropped (the closed schema
 * already rejects them before execute; this is defence in depth).
 *
 * @param array<int|string,mixed> $attributes Raw attribute map.
 * @return array<string,string>
 */
function aafm_wc_sanitize_variation_attributes( array $attributes ): array {
	$clean = array();
	foreach ( $attributes as $key => $value ) {
		if ( ! is_scalar( $value ) ) {
			continue;
		}
		$clean[ sanitize_title( (string) $key ) ] = sanitize_text_field( (string) $value );
	}
	return $clean;
}

/**
 * Args for aafm/wc-create-product-variation.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_product_variation(): array {
	$properties               = aafm_wc_variation_write_properties();
	$properties['product_id'] = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => 'The parent (variable) product id the variation attaches to.',
	);

	return array(
		'label'               => __( 'Create WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a variation under a variable product (parent product id required; status, description, prices, SKU, stock, image, attributes optional). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_variation_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_product_variation',
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
 * Execute aafm/wc-create-product-variation.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_create_product_variation( array $input ) {
	if ( ! class_exists( 'WC_Product_Variation' ) ) {
		return aafm_generic_error();
	}
	$parent = aafm_wc_get_product( (int) ( $input['product_id'] ?? 0 ) );
	if ( null === $parent ) {
		return aafm_generic_error();
	}
	// MCP LOW-1: a variation only belongs under a variable parent; attaching to a simple/grouped/
	// external parent silently no-ops downstream, so refuse a non-variable parent up front.
	if ( 'variable' !== $parent->get_type() ) {
		return aafm_generic_error();
	}

	unset( $input['product_id'] );
	$variation = new \WC_Product_Variation();
	$variation->set_parent_id( (int) $parent->get_id() );
	aafm_wc_apply_variation_input( $variation, $input );
	$id = (int) $variation->save();

	$saved = aafm_wc_get_variation( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_variation( $saved );
}

/**
 * Args for aafm/wc-update-product-variation.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_product_variation(): array {
	$properties                 = aafm_wc_variation_write_properties();
	$properties['variation_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);

	return array(
		'label'               => __( 'Update WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a product variation by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'variation_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_variation_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_product_variation',
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
 * Execute aafm/wc-update-product-variation.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_update_product_variation( array $input ) {
	$variation = aafm_wc_get_variation( (int) ( $input['variation_id'] ?? 0 ) );
	if ( null === $variation ) {
		return aafm_generic_error();
	}
	unset( $input['variation_id'] );
	aafm_wc_apply_variation_input( $variation, $input );
	$id = (int) $variation->save();

	$saved = aafm_wc_get_variation( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_variation( $saved );
}

/**
 * Args for aafm/wc-delete-product-variation.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_product_variation(): array {
	return array(
		'label'               => __( 'Delete WooCommerce product variation', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a product variation by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'variation_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'variation_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_product_variation',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/wc-delete-product-variation.
 *
 * Permanent removal through the variation object's own data store: $variation->delete( true ). The
 * force flag is WooCommerce's permanent-delete semantics (a variation has no recoverable WC trash for
 * this surface). This is the WooCommerce object's own delete method, NOT the core post force-delete
 * primitive, so it never touches that governed call site or the source-scan that bans it.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wc_delete_product_variation( array $input ) {
	$id        = (int) ( $input['variation_id'] ?? 0 );
	$variation = aafm_wc_get_variation( $id );
	if ( null === $variation ) {
		return aafm_generic_error();
	}
	$variation->delete( true );

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}

// =============================================================================
// Global product attributes (sub-slice W4-WC1c)
// =============================================================================
//
// These five abilities manage GLOBAL product attribute taxonomies — the
// wc_get_attribute_taxonomies() surface — not per-product attributes. Each is
// object-independent and gates on manage_woocommerce, so none needs a server.php
// case; all fall through to the real permission_callback at discovery.
//
// Every attribute in the WooCommerce data store is a stdClass with the following
// field names:  attribute_id, attribute_name (the raw slug, e.g. "color"),
// attribute_label (the human label, e.g. "Color"), attribute_type (e.g. "select"),
// attribute_orderby (e.g. "menu_order"), attribute_public (bool archive flag).
// The redactor maps these to the API's flat shape.

/**
 * Resolve a global attribute id to its stdClass row, or null when not found.
 *
 * Iterates over wc_get_attribute_taxonomies() so it always reflects the live
 * store (no separate index to keep in sync).
 *
 * @param int $id Attribute id.
 * @return \stdClass|null
 */
function aafm_wc_get_attribute( int $id ): ?\stdClass {
	if ( $id <= 0 ) {
		return null;
	}
	foreach ( wc_get_attribute_taxonomies() as $attr ) {
		if ( (int) ( $attr->attribute_id ?? 0 ) === $id ) {
			return $attr;
		}
	}
	return null;
}

/**
 * The output properties shared by every attribute ability (list row, get, create, update).
 *
 * @return array<string,mixed>
 */
function aafm_wc_attribute_output_properties(): array {
	return array(
		'id'           => array( 'type' => 'integer' ),
		'name'         => array( 'type' => 'string' ),
		'slug'         => array( 'type' => 'string' ),
		'type'         => array( 'type' => 'string' ),
		'order_by'     => array( 'type' => 'string' ),
		'has_archives' => array( 'type' => 'boolean' ),
	);
}

/**
 * Redact one WooCommerce global attribute stdClass into the API row shape.
 *
 * @param \stdClass $attr Raw attribute object from wc_get_attribute_taxonomies().
 * @return array<string,mixed>
 */
function aafm_redact_wc_attribute( \stdClass $attr ): array {
	$raw_name = (string) ( $attr->attribute_name ?? '' );
	return array(
		'id'           => (int) ( $attr->attribute_id ?? 0 ),
		'name'         => (string) ( $attr->attribute_label ?? '' ),
		'slug'         => wc_attribute_taxonomy_name( $raw_name ),
		'type'         => (string) ( $attr->attribute_type ?? 'select' ),
		'order_by'     => (string) ( $attr->attribute_orderby ?? 'menu_order' ),
		'has_archives' => (bool) ( $attr->attribute_public ?? false ),
	);
}

// -----------------------------------------------------------------------------
// aafm/wc-list-product-attributes (R)
// -----------------------------------------------------------------------------

/**
 * Args builder for aafm/wc-list-product-attributes.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_product_attributes(): array {
	return array(
		'label'               => __( 'List WooCommerce product attributes', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all global WooCommerce product attribute taxonomies with their id, name, slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'attributes' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_wc_attribute_output_properties(),
					),
				),
				'total'      => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_product_attributes',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-list-product-attributes.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_wc_list_product_attributes( array $input ): array {
	$all  = wc_get_attribute_taxonomies();
	$rows = array_map( 'aafm_redact_wc_attribute', $all );
	return array(
		'attributes' => array_values( $rows ),
		'total'      => count( $rows ),
	);
}

// -----------------------------------------------------------------------------
// aafm/wc-get-product-attribute (R)
// -----------------------------------------------------------------------------

/**
 * Args builder for aafm/wc-get-product-attribute.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_product_attribute(): array {
	return array(
		'label'               => __( 'Get WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one global WooCommerce product attribute taxonomy by id, including its name, slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attribute_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'attribute_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_attribute_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_product_attribute',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/wc-get-product-attribute.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_product_attribute( array $input ) {
	$id   = (int) ( $input['attribute_id'] ?? 0 );
	$attr = aafm_wc_get_attribute( $id );
	if ( null === $attr ) {
		return aafm_generic_error();
	}
	return aafm_redact_wc_attribute( $attr );
}

// -----------------------------------------------------------------------------
// aafm/wc-create-product-attribute (W)
// -----------------------------------------------------------------------------

/**
 * The writable input properties shared by create and update.
 *
 * @return array<string,mixed>
 */
function aafm_wc_attribute_write_properties(): array {
	return array(
		'name'         => array( 'type' => 'string' ),
		'slug'         => array( 'type' => 'string' ),
		'type'         => array( 'type' => 'string' ),
		'order_by'     => array( 'type' => 'string' ),
		'has_archives' => array( 'type' => 'boolean' ),
	);
}

/**
 * Args builder for aafm/wc-create-product-attribute.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_product_attribute(): array {
	$props        = aafm_wc_attribute_write_properties();
	$output_props = aafm_wc_attribute_output_properties();
	return array(
		'label'               => __( 'Create WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a new global WooCommerce product attribute taxonomy from a name (required) plus optional slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => $output_props,
		),
		'execute_callback'    => 'aafm_exec_wc_create_product_attribute',
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
 * Execute aafm/wc-create-product-attribute.
 *
 * Sanitizes all inputs, delegates to wc_create_attribute(), then re-reads the
 * created row via aafm_wc_get_attribute() and returns the rich shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_product_attribute( array $input ) {
	$name  = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$slug  = isset( $input['slug'] ) ? wc_sanitize_taxonomy_name( sanitize_title( (string) $input['slug'] ) ) : sanitize_title( $name );
	$type  = sanitize_key( (string) ( $input['type'] ?? 'select' ) );
	$order = sanitize_key( (string) ( $input['order_by'] ?? 'menu_order' ) );
	$arch  = isset( $input['has_archives'] ) ? (bool) $input['has_archives'] : false;

	$args   = array(
		'name'         => $name,
		'slug'         => $slug,
		'type'         => $type,
		'order_by'     => $order,
		'has_archives' => $arch,
	);
	$result = wc_create_attribute( $args );
	if ( is_wp_error( $result ) || ! $result ) {
		return aafm_generic_error();
	}
	$id   = (int) $result;
	$attr = aafm_wc_get_attribute( $id );
	if ( null === $attr ) {
		return aafm_generic_error();
	}
	return aafm_redact_wc_attribute( $attr );
}

// -----------------------------------------------------------------------------
// aafm/wc-update-product-attribute (W)
// -----------------------------------------------------------------------------

/**
 * Args builder for aafm/wc-update-product-attribute.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_product_attribute(): array {
	$write_props = aafm_wc_attribute_write_properties();
	$all_props   = array_merge(
		array(
			'attribute_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		$write_props
	);
	$output_props = aafm_wc_attribute_output_properties();
	return array(
		'label'               => __( 'Update WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a global WooCommerce product attribute taxonomy by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $all_props,
			'required'             => array( 'attribute_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => $output_props,
		),
		'execute_callback'    => 'aafm_exec_wc_update_product_attribute',
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
 * Execute aafm/wc-update-product-attribute.
 *
 * Resolve-before-mutate: unknown id returns a generic error. Only fields present
 * in $input are included in the update args (PATCH semantics). Re-reads the row
 * after update and returns the rich shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_product_attribute( array $input ) {
	$id   = (int) ( $input['attribute_id'] ?? 0 );
	$attr = aafm_wc_get_attribute( $id );
	if ( null === $attr ) {
		return aafm_generic_error();
	}

	$args = array();
	if ( array_key_exists( 'name', $input ) ) {
		$args['name'] = sanitize_text_field( (string) $input['name'] );
	}
	if ( array_key_exists( 'slug', $input ) ) {
		$args['slug'] = wc_sanitize_taxonomy_name( sanitize_title( (string) $input['slug'] ) );
	}
	if ( array_key_exists( 'type', $input ) ) {
		$args['type'] = sanitize_key( (string) $input['type'] );
	}
	if ( array_key_exists( 'order_by', $input ) ) {
		$args['order_by'] = sanitize_key( (string) $input['order_by'] );
	}
	if ( array_key_exists( 'has_archives', $input ) ) {
		$args['has_archives'] = (bool) $input['has_archives'];
	}

	if ( ! empty( $args ) ) {
		$result = wc_update_attribute( $id, $args );
		if ( is_wp_error( $result ) ) {
			return aafm_generic_error();
		}
	}

	$updated = aafm_wc_get_attribute( $id );
	if ( null === $updated ) {
		return aafm_generic_error();
	}
	return aafm_redact_wc_attribute( $updated );
}

// -----------------------------------------------------------------------------
// aafm/wc-delete-product-attribute (D)
// -----------------------------------------------------------------------------

/**
 * Args builder for aafm/wc-delete-product-attribute.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_product_attribute(): array {
	return array(
		'label'               => __( 'Delete WooCommerce product attribute', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently removes a global WooCommerce product attribute taxonomy by id. This deletes the taxonomy and all terms within it and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attribute_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'attribute_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_product_attribute',
		'permission_callback' => 'aafm_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/wc-delete-product-attribute.
 *
 * Resolve-before-mutate: unknown id returns a generic error. Deletion goes through
 * wc_delete_attribute() — WooCommerce's own taxonomy-removal function — NOT a
 * wp_delete_post(...,true) primitive, so the source-scan that bans that call remains
 * clean (verified by grep on this file).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_product_attribute( array $input ) {
	$id   = (int) ( $input['attribute_id'] ?? 0 );
	$attr = aafm_wc_get_attribute( $id );
	if ( null === $attr ) {
		return aafm_generic_error();
	}
	wc_delete_attribute( $id );

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}
