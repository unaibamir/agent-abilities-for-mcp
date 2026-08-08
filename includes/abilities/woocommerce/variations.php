<?php
/**
 * WooCommerce integration abilities - product variation reads and writes (sub-slice W4-WC1b).
 *
 * Registers ONLY when WooCommerce is active (aafm_integration_active('woocommerce')); a host-inactive
 * site contributes zero entries to the registry. Every ability gates on the flat, object-independent
 * manage_woocommerce capability and falls through to its real permission_callback at discovery (no
 * server.php case); wc-delete-product-variation additionally checks the caller's own relationship to
 * the specific variation, mirroring wc-delete-product's aafm_perm_wc_delete_product() (products.php).
 * Shared helpers live in _shared.php, loaded before this file.
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
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_variations_registry_definitions(): array {
	return array(
		// Variations (sub-slice W4-WC1b) - a variable product's child variations, parent_id-scoped.
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
			'description'  => __( 'Reads one product variation by id, including its parent id, prices, stock, description, image, and its chosen attribute values. A variation that inherits its parent\'s stock reports manage_stock false and stock_quantity null, not the parent\'s number. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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
 * Variations (sub-slice W4-WC1b) - a variable product's child variations.
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
	// concrete WC_Product_Variation return so the declared return type is not seen as unused - under
	// the live WooCommerce types wc_get_product() already returns the variation above, making this a
	// belt-and-braces fallback rather than a second code path.
	if ( $variation instanceof \WC_Product && 'variation' === $variation->get_type() && class_exists( 'WC_Product_Variation' ) ) {
		return new \WC_Product_Variation( $id );
	}
	// Anything else at this id (a non-variation product, or false) is not a valid variation
	// target. A variation-type product is always returned as a WC_Product_Variation by
	// wc_get_product(), so the instanceof check above already covers it - there is no separate
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
 * as {}). Never a filesystem path - the image is an attachment id, not a file path.
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

	// WC_Product_Variation::get_manage_stock() (class-wc-product-variation.php:323-331) can
	// return the STRING 'parent' when the variation does not manage its own stock but its parent
	// product does. (bool) 'parent' is true, so a plain cast never trips the output_schema's
	// declared boolean type - the wire stays valid while lying: it claims the variation manages
	// its own stock while also reporting a stock_quantity the variation does not actually own
	// (the quantity that matters is the parent's). The schema stays boolean (widening it is a
	// breaking change, not this fix's call), so the honest answer for 'parent' is false, matching
	// what every other non-managing variation already reports.
	$manage_stock = $variation->get_manage_stock();

	// The same 'parent' state poisons stock_quantity too: real get_stock_quantity()
	// (class-wc-product-variation.php:339-351) returns the PARENT's number when the stock is
	// inherited, so passing it through would report a quantity that belongs to a different
	// object beside a manage_stock of false, with nothing saying so. The variation owns no
	// stock of its own in that state, and the schema already allows null, so report null.
	$stock_quantity = 'parent' === $manage_stock ? null : $variation->get_stock_quantity();

	return array_merge(
		$base,
		array(
			'description'    => (string) $variation->get_description(),
			'regular_price'  => (string) $variation->get_regular_price(),
			'sale_price'     => (string) $variation->get_sale_price(),
			'manage_stock'   => 'parent' === $manage_stock ? false : (bool) $manage_stock,
			'stock_quantity' => null === $stock_quantity ? null : (int) $stock_quantity,
			'image_id'       => (int) $variation->get_image_id(),
			// Cast so an empty attributes map encodes to "{}" (object) per the schema, never "[]".
			'attributes'     => (object) $attributes,
		)
	);
}

/**
 * The shared output_schema properties for the full single-variation shape - the exact field set
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Page number of variations to return, 1-indexed. Defaults to 1.', 'agent-abilities-for-mcp' ),
				),
				'per_page'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Number of variations per page, 1 to 100. Defaults to 20.', 'agent-abilities-for-mcp' ),
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The variation's own product id (not the parent product's id).", 'agent-abilities-for-mcp' ),
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
 * smuggled NESTED structure (an object/array value) is rejected before execute - the flat-map
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
		'description'    => array(
			'type'        => 'string',
			'description' => __( "The variation's own description. Shown instead of the parent product's description when this variation is selected.", 'agent-abilities-for-mcp' ),
		),
		'sku'            => array(
			'type'        => 'string',
			'description' => __( "The variation's own SKU. WooCommerce requires SKUs to be unique across products and variations.", 'agent-abilities-for-mcp' ),
		),
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
			'type'        => 'string',
			'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
			'description' => __( 'Whether this variation is in stock, out of stock, or on backorder.', 'agent-abilities-for-mcp' ),
		),
		'stock_quantity' => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'On-hand quantity (only applied when manage_stock is true).',
		),
		'manage_stock'   => array(
			'type'        => 'boolean',
			'description' => __( 'Whether WooCommerce tracks a numeric stock_quantity for this variation, independent of the parent product.', 'agent-abilities-for-mcp' ),
		),
		'image_id'       => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( "Attachment id for this variation's own image. Falls back to the parent product's image when unset.", 'agent-abilities-for-mcp' ),
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
 * WC_Product_Variation::set_sku() throws WC_Data_Exception when the SKU collides with another
 * product or variation (abstract-wc-product.php: wc_product_has_unique_sku() fails), the identical
 * contract aafm_wc_apply_product_input() (products.php) already guards. WP_Ability::execute() on
 * this plugin's WP 6.9 floor has no try/catch of its own (a later core hardened
 * invoke_callback() with one, but 6.9 does not have it), so an uncaught exception here is a fatal
 * over WordPress core's own Abilities REST route - caught here rather than left to surface as an
 * uncaught exception; every other setter in this function is not known to throw.
 *
 * @param \WC_Product_Variation $variation The variation to mutate.
 * @param array<string,mixed>   $input     Validated input (already schema-checked).
 * @return \WP_Error|null Null on success, or a WP_Error naming the conflicting SKU.
 */
function aafm_wc_apply_variation_input( \WC_Product_Variation $variation, array $input ): ?\WP_Error {
	if ( array_key_exists( 'status', $input ) ) {
		$variation->set_status( sanitize_key( (string) $input['status'] ) );
	}
	if ( array_key_exists( 'sku', $input ) ) {
		$sku = sanitize_text_field( (string) $input['sku'] );
		try {
			$variation->set_sku( $sku );
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error(
				'aafm_wc_duplicate_sku',
				sprintf(
					/* translators: %s: the conflicting SKU value. */
					__( 'The SKU "%s" is already in use by another product or variation.', 'agent-abilities-for-mcp' ),
					$sku
				)
			);
		}
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
	return null;
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
	$error = aafm_wc_apply_variation_input( $variation, $input );
	if ( null !== $error ) {
		return $error;
	}
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
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'The variation id to update.', 'agent-abilities-for-mcp' ),
	);
	// Unlike every other field here, attributes is a full replace on update - set_attributes() is
	// called with only the sanitized input map, never merged against the variation's existing
	// attributes (contrast aafm_wc_build_product_attributes() for the parent product, which does
	// merge). Override the shared description for this ability only, so the PATCH-style wording
	// on every other field does not silently imply the same semantics here.
	$properties['attributes']['description'] = __( 'A flat map of attribute name to the chosen value (strings only). Unlike every other field on this ability, this replaces the variation\'s entire attribute map: any attribute not included here is cleared, not preserved.', 'agent-abilities-for-mcp' );

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
	$error = aafm_wc_apply_variation_input( $variation, $input );
	if ( null !== $error ) {
		return $error;
	}
	$id = (int) $variation->save();

	$saved = aafm_wc_get_variation( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_variation( $saved );
}

/**
 * Whether the current user may delete this specific variation object.
 *
 * Deliberately its own helper rather than a reuse of aafm_wc_can_delete_product_object()
 * (products.php): that helper returns false outright unless the resolved post type's own
 * map_meta_cap is true, which is correct for the 'product' post type - WooCommerce registers it
 * with map_meta_cap => true - but would make this always return false for 'product_variation'.
 * WooCommerce registers product_variation with capability_type => 'product' and no explicit
 * map_meta_cap (WC_Post_Types::register_post_types()), so that post type's own WP_Post_Type object
 * always has map_meta_cap === false, unlike 'product'.
 *
 * That gap in WooCommerce's own registration means WordPress's map_meta_cap() cannot resolve
 * delete_product against a specific variation into the own-vs-others distinction it gives the
 * 'product' post type (see the `! $post_type->map_meta_cap` branch of the delete_post case in
 * wp-includes/capabilities.php - it short-circuits straight to the flat capability instead of
 * checking post_author). current_user_can( 'delete_product', $variation_id ) still runs and still
 * requires the caller to hold the delete_product capability; it just cannot tell "your own
 * variation" from "someone else's" the way the product check can. That is still real value over the
 * bare manage_woocommerce floor - a custom role holding manage_woocommerce without WooCommerce's
 * product capabilities is denied here - it is just not the identical per-object guarantee
 * aafm_wc_can_delete_product_object() gives, because that guarantee is a property of how
 * WooCommerce itself registered the post type, not something this callback controls.
 *
 * @param WP_Post $variation The variation's backing post object.
 * @return bool
 */
function aafm_wc_can_delete_variation_object( WP_Post $variation ): bool {
	$type = get_post_type_object( $variation->post_type );
	if ( ! $type instanceof WP_Post_Type ) {
		return false;
	}
	return current_user_can( $type->cap->delete_post, $variation->ID );
}

/**
 * Permission for aafm/wc-delete-product-variation: the capability floor (manage_woocommerce) AND
 * the caller's own relationship to the specific variation, mirroring aafm_perm_wc_delete_product()
 * (products.php) so delete-product and delete-product-variation share the same floor shape. See
 * aafm_wc_can_delete_variation_object() above for why the variation check cannot be the identical
 * per-object guarantee the product check gets.
 *
 * A nonexistent id, or a variation with no real backing WP_Post to gate on, falls back to the
 * floor already checked: there is nothing more specific to authorize against, and the WC data
 * store (not a missing capability) is what reports "not found" from execute().
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function aafm_perm_wc_delete_product_variation( array $input ): bool {
	if ( ! aafm_wc_perm() ) {
		return false;
	}
	$id        = isset( $input['variation_id'] ) ? absint( $input['variation_id'] ) : 0;
	$variation = $id ? aafm_wc_get_variation( $id ) : null;
	if ( null === $variation ) {
		return true;
	}
	$post = get_post( $variation->get_id() );
	if ( ! $post instanceof WP_Post ) {
		return true;
	}
	return aafm_wc_can_delete_variation_object( $post );
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The variation id to permanently delete.', 'agent-abilities-for-mcp' ),
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
		'permission_callback' => 'aafm_perm_wc_delete_product_variation',
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
	// WC_Data::delete() returns true whenever a data store exists, and a loaded variation always has
	// one, so its return never signals a store-level failure. Verify the row is actually gone by
	// re-reading rather than trusting the return.
	if ( null !== aafm_wc_get_variation( $id ) ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}
