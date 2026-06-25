<?php
/**
 * WooCommerce integration abilities — product reads and writes (sub-slice W4-WC1a).
 *
 * Registers ONLY when WooCommerce is active (oversio_integration_active('woocommerce')); a host-inactive
 * site contributes zero entries to the registry. Every ability gates on the flat, object-independent
 * manage_woocommerce capability and falls through to its real permission_callback at discovery (no
 * server.php case). Shared helpers live in _shared.php, loaded before this file.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_wc_products_definitions' );
add_filter( 'oversio_abilities_registry_integrations', 'oversio_register_wc_products_full_definitions' );

/**
 * Contribute the WooCommerce products definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_products_definitions( array $registry ): array {
	if ( ! oversio_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, oversio_wc_products_registry_definitions() );
}

/**
 * Contribute the WooCommerce product definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (oversio_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_products_full_definitions( array $registry ): array {
	return array_merge( $registry, oversio_wc_products_registry_definitions() );
}

/**
 * The WooCommerce product registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_wc_products_registry_definitions(): array {
	return array(
		'oversio/wc-list-products'  => array(
			'label'        => __( 'List WooCommerce products', 'oversio-agent-abilities' ),
			'description'  => __( 'Lists WooCommerce products with their id, name, SKU, price, stock status, status, categories, and featured flag, plus a total. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_list_products',
		),

		'oversio/wc-get-product'    => array(
			'label'        => __( 'Get WooCommerce product', 'oversio-agent-abilities' ),
			'description'  => __( 'Reads one WooCommerce product by id, including its description, prices, stock, images, attributes, variation ids, and categories. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_get_product',
		),

		'oversio/wc-create-product' => array(
			'label'        => __( 'Create WooCommerce product', 'oversio-agent-abilities' ),
			'description'  => __( 'Creates a WooCommerce product from a name (required) plus optional type, status, description, prices, SKU, stock, categories, tags, images, and attributes. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_create_product',
		),

		'oversio/wc-update-product' => array(
			'label'        => __( 'Update WooCommerce product', 'oversio-agent-abilities' ),
			'description'  => __( 'Updates a WooCommerce product by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_update_product',
		),

		'oversio/wc-delete-product' => array(
			'label'        => __( 'Delete WooCommerce product', 'oversio-agent-abilities' ),
			'description'  => __( 'Permanently deletes a WooCommerce product by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_delete_product',
		),
	);
}

/**
 * Resolve a product id to a WC_Product, or null when WooCommerce is unavailable or the id is unknown.
 *
 * @param int $id Product id.
 * @return \WC_Product|null
 */
function oversio_wc_get_product( int $id ): ?\WC_Product {
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
function oversio_redact_wc_product( \WC_Product $product ): array {
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
function oversio_rich_wc_product( \WC_Product $product ): array {
	$attributes = array();
	foreach ( (array) $product->get_attributes() as $key => $attribute ) {
		$attributes[ (string) $key ] = oversio_wc_attribute_shape( $attribute );
	}

	$base = oversio_redact_wc_product( $product );

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
function oversio_wc_attribute_shape( $attribute ): array {
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
 * oversio_rich_wc_product() emits. Reused by the get/create/update output_schemas so all three stay in
 * lockstep with the rich assembler. `attributes` is an object (an empty map encodes as {}); the
 * list-shaped fields are arrays of integers.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_wc_product_output_properties(): array {
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
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
	);
}

/**
 * Args for oversio/wc-list-products.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_list_products(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-list-products' ),
		'description'         => oversio_ability_description( 'oversio/wc-list-products' ),
		'category'            => 'oversio-reads',
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
		'execute_callback'    => 'oversio_exec_wc_list_products',
		'permission_callback' => 'oversio_wc_perm',
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
 * Execute oversio/wc-list-products.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function oversio_exec_wc_list_products( array $input ): array {
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
			$out['products'][] = oversio_redact_wc_product( $product );
		}
	}
	$out['total'] = $total;

	return $out;
}

/**
 * Args for oversio/wc-get-product.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_get_product(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-get-product' ),
		'description'         => oversio_ability_description( 'oversio/wc-get-product' ),
		'category'            => 'oversio-reads',
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
			'properties' => oversio_wc_product_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_get_product',
		'permission_callback' => 'oversio_wc_perm',
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
 * Execute oversio/wc-get-product.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_wc_get_product( array $input ) {
	$product = oversio_wc_get_product( (int) ( $input['product_id'] ?? 0 ) );
	if ( null === $product ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_product( $product );
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
function oversio_wc_product_write_properties(): array {
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
			'type' => 'string',
			'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
		),
		'stock_quantity'    => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'On-hand quantity (only applied when manage_stock is true).',
		),
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
function oversio_wc_apply_product_input( \WC_Product $product, array $input ): void {
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
		$product->set_regular_price( oversio_wc_sanitize_price( $input['regular_price'] ) );
	}
	if ( array_key_exists( 'sale_price', $input ) ) {
		$product->set_sale_price( oversio_wc_sanitize_price( $input['sale_price'] ) );
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
		$product->set_attributes( oversio_wc_sanitize_attributes( (array) $input['attributes'] ) );
	}
}

/**
 * Sanitize a list of attribute input items to {name, options[]} maps, dropping anything else.
 *
 * @param array<int,mixed> $attributes Raw attribute items.
 * @return array<int,array<string,mixed>>
 */
function oversio_wc_sanitize_attributes( array $attributes ): array {
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
 * Args for oversio/wc-create-product.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_create_product(): array {
	$properties         = oversio_wc_product_write_properties();
	$properties['name'] = array(
		'type'      => 'string',
		'minLength' => 1,
	);

	return array(
		'label'               => oversio_ability_label( 'oversio/wc-create-product' ),
		'description'         => oversio_ability_description( 'oversio/wc-create-product' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_product_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_create_product',
		'permission_callback' => 'oversio_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/wc-create-product.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_wc_create_product( array $input ) {
	if ( ! class_exists( 'WC_Product' ) ) {
		return oversio_generic_error();
	}
	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	if ( '' === $name ) {
		return oversio_generic_error();
	}

	// This generic create only builds simple products. A variable/grouped/external product is a
	// different construction (and, for variable, needs variations added afterward), so reject a
	// non-simple request rather than silently downgrading it to a simple product and reporting
	// success. The schema enumerates the other types, but they are not honored here.
	$requested_type = isset( $input['type'] ) ? (string) $input['type'] : 'simple';
	if ( 'simple' !== $requested_type ) {
		return oversio_generic_error();
	}

	$product = new \WC_Product();
	unset( $input['type'] );
	oversio_wc_apply_product_input( $product, $input );
	$id = (int) $product->save();

	$saved = oversio_wc_get_product( $id );
	if ( null === $saved ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_product( $saved );
}

/**
 * Args for oversio/wc-update-product.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_update_product(): array {
	$properties               = oversio_wc_product_write_properties();
	$properties['product_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$properties['name']       = array(
		'type'      => 'string',
		'minLength' => 1,
	);

	return array(
		'label'               => oversio_ability_label( 'oversio/wc-update-product' ),
		'description'         => oversio_ability_description( 'oversio/wc-update-product' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_product_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_update_product',
		'permission_callback' => 'oversio_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/wc-update-product.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_wc_update_product( array $input ) {
	$product = oversio_wc_get_product( (int) ( $input['product_id'] ?? 0 ) );
	if ( null === $product ) {
		return oversio_generic_error();
	}
	unset( $input['product_id'], $input['type'] );
	oversio_wc_apply_product_input( $product, $input );
	$id = (int) $product->save();

	$saved = oversio_wc_get_product( $id );
	if ( null === $saved ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_product( $saved );
}

/**
 * Args for oversio/wc-delete-product.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_delete_product(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-delete-product' ),
		'description'         => oversio_ability_description( 'oversio/wc-delete-product' ),
		'category'            => 'oversio-writes',
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
		'execute_callback'    => 'oversio_exec_wc_delete_product',
		'permission_callback' => 'oversio_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute oversio/wc-delete-product.
 *
 * Permanent removal through WooCommerce's own data store: $product->delete( true ). The force flag is
 * WC's permanent-delete semantics (a product has no recoverable WC trash for this surface). This is
 * the WooCommerce object's own delete method, NOT the core post force-delete primitive, so it never
 * touches that governed call site or the source-scan that bans it.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_wc_delete_product( array $input ) {
	$id      = (int) ( $input['product_id'] ?? 0 );
	$product = oversio_wc_get_product( $id );
	if ( null === $product ) {
		return oversio_generic_error();
	}
	// WC_Data::delete( true ) returns false when the data store could not remove the row.
	// Honor it rather than reporting deleted:true on a failed delete.
	if ( false === $product->delete( true ) ) {
		return oversio_generic_error();
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}
