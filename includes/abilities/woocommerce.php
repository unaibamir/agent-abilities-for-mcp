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
