<?php
/**
 * WooCommerce integration abilities — product variation reads and writes (sub-slice W4-WC1b).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_variations_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_variations_full_definitions' );

/**
 * Contribute the WooCommerce variations definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_variations_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_variations_registry_definitions() );
}

/**
 * Contribute the WooCommerce product variation definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_variations_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_variations_registry_definitions() );
}

/**
 * The WooCommerce product variation registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_variations_registry_definitions(): array {
	return array(
		// Variations (sub-slice W4-WC1b) — a variable product's child variations, parent_id-scoped.
		'aafm/wc-list-product-variations'  => array(
			'label'        => __( 'List WooCommerce product variations', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists a variable product\'s variations by parent product id, each with its id, parent id, SKU, price, stock status, and status, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_product_variations',
		),

		'aafm/wc-get-product-variation'    => array(
			'label'        => __( 'Get WooCommerce product variation', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one product variation by id, including its parent id, prices, stock, description, image, and its chosen attribute values. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_product_variation',
		),

		'aafm/wc-create-product-variation' => array(
			'label'        => __( 'Create WooCommerce product variation', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a variation under a variable product (parent product id required) from optional status, description, prices, SKU, stock, image, and attribute values. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_product_variation',
		),

		'aafm/wc-update-product-variation' => array(
			'label'        => __( 'Update WooCommerce product variation', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a product variation by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_product_variation',
		),

		'aafm/wc-delete-product-variation' => array(
			'label'        => __( 'Delete WooCommerce product variation', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Permanently deletes a product variation by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_delete_product_variation',
		),
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
 * The WooCommerce PHPStan stub types wc_get_product() without WC_Product_Variation in its return
 * union, so PHPStan reports this function's WC_Product_Variation return arm as unused. At runtime
 * wc_get_product() genuinely returns a WC_Product_Variation for a variation id, so the declared
 * type is correct. The runtime guard re-asserts the type for PHPStan via the @var below.
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
	// A variation id that resolved to a plain WC_Product (the parent or another type) still yields a
	// genuine WC_Product_Variation when constructed directly. This branch also gives PHPStan a
	// concrete WC_Product_Variation return so the declared return type is not seen as unused — under
	// the live WooCommerce types wc_get_product() already returns the variation above, making this a
	// belt-and-braces fallback rather than a second code path.
	if ( $variation instanceof \WC_Product && 'variation' === $variation->get_type() && class_exists( 'WC_Product_Variation' ) ) {
		return new \WC_Product_Variation( $id );
	}
	// Anything else at this id (a non-variation product, or false) is not a valid variation
	// target. A variation-type product is always returned as a WC_Product_Variation by
	// wc_get_product(), so the instanceof check above already covers it — there is no separate
	// "WC_Product whose type is variation" case to handle (B11).
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
		'label'               => aafm_ability_label( 'aafm/wc-list-product-variations' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-product-variations' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-get-product-variation' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-product-variation' ),
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
				'idempotent'  => true,
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
		'regular_price'  => array(
			'type'        => 'string',
			'pattern'     => '^\\d+(\\.\\d{1,2})?$',
			'description' => 'A decimal price as a string, e.g. "19.99" (no currency symbol or thousands separator).',
		),
		'sale_price'     => array(
			'type'        => 'string',
			'pattern'     => '^\\d+(\\.\\d{1,2})?$',
			'description' => 'A decimal price as a string, e.g. "14.99". Must be at or below regular_price to take effect.',
		),
		'stock_status'   => array(
			'type' => 'string',
			'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
		),
		'stock_quantity' => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'On-hand quantity (only applied when manage_stock is true).',
		),
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
		'label'               => aafm_ability_label( 'aafm/wc-create-product-variation' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-product-variation' ),
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
		'label'               => aafm_ability_label( 'aafm/wc-update-product-variation' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-product-variation' ),
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
		'label'               => aafm_ability_label( 'aafm/wc-delete-product-variation' ),
		'description'         => aafm_ability_description( 'aafm/wc-delete-product-variation' ),
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
	// WC_Data::delete( true ) returns false when the data store could not remove the row.
	if ( false === $variation->delete( true ) ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}
