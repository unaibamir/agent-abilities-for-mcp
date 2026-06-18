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

	// Orders (sub-slice W4-WC2) — list is lean (no PII), get returns full billing/shipping PII
	// under the Integrations security disclaimer. Both gate on the flat, object-independent
	// manage_woocommerce capability and fall through to that callback at discovery (no server.php
	// case). PII exposure in wc-get-order is intentional: the revised WC PII stance in spec 48-
	// mandates full billing/shipping on the single-order read, gated by manage_woocommerce and
	// audited, not stripped.
	$registry['aafm/wc-list-orders'] = array(
		'label'        => __( 'List WooCommerce orders', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists WooCommerce orders with their id, number, status, total, currency, date, and customer id, plus a total count. List rows are lean — no billing or shipping details. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_orders',
	);
	$registry['aafm/wc-get-order']   = array(
		'label'        => __( 'Get WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce order by id: line items, totals, status, dates, customer note, and the full customer billing address (including email and phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_order',
	);

	// Order writes (sub-slice W4-WC2.2) — create, update, focused status-only update.
	$registry['aafm/wc-create-order']        = array(
		'label'        => __( 'Create WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce order from optional status, customer id, billing, shipping, and line items. Returns the full order shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_order',
	);
	$registry['aafm/wc-update-order']        = array(
		'label'        => __( 'Update WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce order by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_order',
	);
	$registry['aafm/wc-update-order-status'] = array(
		'label'        => __( 'Update WooCommerce order status', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Sets the status of a WooCommerce order by id. Accepts both the short form (e.g. "completed") and the wc-prefixed form (e.g. "wc-completed"). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_order_status',
	);

	// Order delete (sub-slice W4-WC2.3 Group A).
	$registry['aafm/wc-delete-order'] = array(
		'label'        => __( 'Delete WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce order by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_order',
	);

	// Order notes (sub-slice W4-WC2.3 Group B).
	$registry['aafm/wc-list-order-notes']  = array(
		'label'        => __( 'List WooCommerce order notes', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists all notes on a WooCommerce order by order id. Returns each note\'s id, text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_order_notes',
	);
	$registry['aafm/wc-get-order-note']    = array(
		'label'        => __( 'Get WooCommerce order note', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads a single note on a WooCommerce order by order id and note id. Returns the note text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_order_note',
	);
	$registry['aafm/wc-create-order-note'] = array(
		'label'        => __( 'Create WooCommerce order note', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Adds a note to a WooCommerce order by order id. Optionally marks the note as customer-facing so it appears in the customer\'s account. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_order_note',
	);
	$registry['aafm/wc-delete-order-note'] = array(
		'label'        => __( 'Delete WooCommerce order note', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce order note by note id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_order_note',
	);

	// Order refunds (sub-slice W4-WC2.3 Group C).
	$registry['aafm/wc-list-order-refunds']  = array(
		'label'        => __( 'List WooCommerce order refunds', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists all refunds on a WooCommerce order by order id. Returns each refund\'s id, amount, reason, and date. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_order_refunds',
	);
	$registry['aafm/wc-get-order-refund']    = array(
		'label'        => __( 'Get WooCommerce order refund', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads a single refund by refund id. Returns the refund amount, reason, and date. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_order_refund',
	);
	$registry['aafm/wc-create-order-refund'] = array(
		'label'        => __( 'Create WooCommerce order refund', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a refund on a WooCommerce order by order id. Accepts an amount, optional reason, and optional line-item breakdown. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_order_refund',
	);
	$registry['aafm/wc-delete-order-refund'] = array(
		'label'        => __( 'Delete WooCommerce order refund', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce order refund by refund id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_order_refund',
	);

	// Customers (sub-slice W4-WC3) — PII-exposing abilities gated on manage_woocommerce.
	$registry['aafm/wc-list-customers']  = array(
		'label'        => __( 'List WooCommerce customers', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists WooCommerce customers with their id, email, name, username, order count, and total spent. Customer email is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_customers',
	);
	$registry['aafm/wc-get-customer']    = array(
		'label'        => __( 'Get WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce customer by id, including email, name, username, order count, total spent, date created, and the full billing address (including phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_customer',
	);
	$registry['aafm/wc-create-customer'] = array(
		'label'        => __( 'Create WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce customer from an email and username, with optional first name, last name, and billing/shipping address. Returns the full customer shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_customer',
	);
	$registry['aafm/wc-update-customer'] = array(
		'label'        => __( 'Update WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce customer by id, changing only the fields you send. An empty request body is a no-op success. Returns the full customer shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_customer',
	);
	$registry['aafm/wc-delete-customer'] = array(
		'label'        => __( 'Delete WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce customer (WordPress user) by id and reassigns their content to another user. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_customer',
	);

	// Coupons (sub-slice W4-WC4) — discount/promotion management gated on manage_woocommerce.
	$registry['aafm/wc-list-coupons']  = array(
		'label'        => __( 'List WooCommerce coupons', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists WooCommerce coupons with their id, code, amount, discount type, expiry date, and usage count, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_coupons',
	);
	$registry['aafm/wc-get-coupon']    = array(
		'label'        => __( 'Get WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce coupon by id: code, amount, discount type, expiry, usage limits, spend limits, product and email restrictions, and other config. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_coupon',
	);
	$registry['aafm/wc-create-coupon'] = array(
		'label'        => __( 'Create WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce coupon from a code and discount type, with optional amount, usage limits, spend limits, product restrictions, and email restrictions. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_coupon',
	);
	$registry['aafm/wc-update-coupon'] = array(
		'label'        => __( 'Update WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce coupon by id, changing only the fields you send. An empty request body is a no-op success. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_coupon',
	);
	$registry['aafm/wc-delete-coupon'] = array(
		'label'        => __( 'Delete WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce coupon by id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_coupon',
	);

	// Shipping zones (sub-slice W4-WC5) — zone and method management gated on manage_woocommerce.
	$registry['aafm/wc-list-shipping-zones']  = array(
		'label'        => __( 'List WooCommerce shipping zones', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists WooCommerce shipping zones with their id, name, and order. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_shipping_zones',
	);
	$registry['aafm/wc-get-shipping-zone']    = array(
		'label'        => __( 'Get WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce shipping zone by id, including its name, order, and zone locations. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_shipping_zone',
	);
	$registry['aafm/wc-create-shipping-zone'] = array(
		'label'        => __( 'Create WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce shipping zone from a name and optional order. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_shipping_zone',
	);
	$registry['aafm/wc-update-shipping-zone'] = array(
		'label'        => __( 'Update WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce shipping zone by id, changing only the fields you send. An empty request body is a no-op success. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_shipping_zone',
	);
	$registry['aafm/wc-delete-shipping-zone'] = array(
		'label'        => __( 'Delete WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a WooCommerce shipping zone by id. The Rest of World zone (id 0) cannot be deleted. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_shipping_zone',
	);

	// Shipping methods (sub-slice W4-WC5) — always scoped to a zone.
	$registry['aafm/wc-list-shipping-methods']  = array(
		'label'        => __( 'List WooCommerce shipping methods', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the shipping methods configured in a WooCommerce shipping zone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_shipping_methods',
	);
	$registry['aafm/wc-get-shipping-method']    = array(
		'label'        => __( 'Get WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one shipping method from a WooCommerce shipping zone by zone id and instance id. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_shipping_method',
	);
	$registry['aafm/wc-create-shipping-method'] = array(
		'label'        => __( 'Create WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Adds a shipping method to a WooCommerce shipping zone. Provide the zone id and method type (e.g. flat_rate, free_shipping, local_pickup). Returns the new method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_shipping_method',
	);
	$registry['aafm/wc-update-shipping-method'] = array(
		'label'        => __( 'Update WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a shipping method in a WooCommerce shipping zone by zone id and instance id, changing only the fields you send. Returns the updated method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_shipping_method',
	);
	$registry['aafm/wc-delete-shipping-method'] = array(
		'label'        => __( 'Delete WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently removes a shipping method from a WooCommerce shipping zone by zone id and instance id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_shipping_method',
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
 * Takes no input (the global attribute list is unscoped and unpaged), so it declares no parameter —
 * matching the no-arg read execs elsewhere (e.g. aafm_exec_list_themes).
 *
 * @return array<string,mixed>
 */
function aafm_exec_wc_list_product_attributes(): array {
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
	$write_props  = aafm_wc_attribute_write_properties();
	$all_props    = array_merge(
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
		if ( is_wp_error( $result ) || ! $result ) {
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
 * wc_delete_attribute() — WooCommerce's own taxonomy-removal function — never the
 * force-delete post primitive, so the source-scan remains clean.
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
	$result = wc_delete_attribute( $id );
	if ( is_wp_error( $result ) || false === $result ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}

// =============================================================================
// Orders (sub-slice W4-WC2) — reads only (WC2.1)
// =============================================================================

/**
 * Normalise a WooCommerce date value (WC_DateTime object, ISO string, or null) to a plain string.
 *
 * WooCommerce date getters (get_date_created, get_date_paid, …) return a WC_DateTime instance at
 * runtime, but their PHPStan signature is typed as string|object|null because WC_DateTime is not
 * present in the static-analysis stubs. This helper accepts all three variants and always returns
 * a string or null — avoiding unsafe casts on raw object|null values.
 *
 * @param string|object|null $date Raw date value from a WC_Order getter.
 * @return string|null
 */
function aafm_wc_date_string( $date ): ?string {
	if ( null === $date ) {
		return null;
	}
	if ( is_object( $date ) && method_exists( $date, '__toString' ) ) {
		return (string) $date;
	}
	return is_string( $date ) ? $date : null;
}

/**
 * Resolve an order id to a WC_Order, or null when WooCommerce is unavailable or the id is unknown.
 *
 * @param int $id Order id.
 * @return \WC_Order|null
 */
function aafm_wc_get_order_object( int $id ): ?\WC_Order {
	if ( $id < 1 || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}
	$order = wc_get_order( $id );
	return $order instanceof \WC_Order ? $order : null;
}

/**
 * The lean list shape for an order: id, number, status, total, currency, date_created,
 * customer_id. No billing/shipping/PII in list rows — lean for payload economy.
 *
 * @param \WC_Order $order Order.
 * @return array<string,mixed>
 */
function aafm_redact_wc_order( \WC_Order $order ): array {
	return array(
		'id'           => (int) $order->get_id(),
		'number'       => (string) $order->get_order_number(),
		'status'       => (string) $order->get_status(),
		'total'        => (string) $order->get_total(),
		'currency'     => (string) $order->get_currency(),
		'date_created' => aafm_wc_date_string( $order->get_date_created() ),
		'customer_id'  => (int) $order->get_customer_id(),
	);
}

/**
 * The full single-order shape including customer billing/shipping PII.
 *
 * PII (billing email, phone, full address) is returned as-is — this is intentional per the
 * revised WC PII stance in spec 48-: full PII on order reads, under the Integrations security
 * disclaimer, gated by manage_woocommerce and audited. Do NOT strip or opt-in-gate it.
 *
 * Billing and shipping maps are cast with (object) so an empty address block encodes as {}
 * not [] in JSON (the same pattern as aafm_rich_wc_product's attributes map).
 *
 * @param \WC_Order $order Order.
 * @return array<string,mixed>
 */
function aafm_rich_wc_order( \WC_Order $order ): array {
	// Line items: each raw item from get_items() is mapped to a clean scalar shape.
	$line_items = array();
	foreach ( (array) $order->get_items() as $item ) {
		if ( is_array( $item ) ) {
			// Stub path: items are plain arrays seeded in WcOrderStubStore.
			$line_items[] = array(
				'name'       => (string) ( $item['name'] ?? '' ),
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'quantity'   => (int) ( $item['quantity'] ?? 1 ),
				'subtotal'   => (string) ( $item['subtotal'] ?? '0.00' ),
				'total'      => (string) ( $item['total'] ?? '0.00' ),
			);
		} elseif ( is_object( $item ) && method_exists( $item, 'get_name' ) ) {
			// Real WC_Order_Item_Product path.
			$line_items[] = array(
				'name'       => (string) $item->get_name(),
				'product_id' => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
				'quantity'   => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1,
				'subtotal'   => method_exists( $item, 'get_subtotal' ) ? (string) $item->get_subtotal() : '0.00',
				'total'      => method_exists( $item, 'get_total' ) ? (string) $item->get_total() : '0.00',
			);
		}
	}

	// Billing address — full PII under the disclaimer; cast to (object) so empty map encodes as {}.
	$billing_raw = array(
		'first_name' => (string) $order->get_billing_first_name(),
		'last_name'  => (string) $order->get_billing_last_name(),
		'company'    => (string) $order->get_billing_company(),
		'address_1'  => (string) $order->get_billing_address_1(),
		'address_2'  => (string) $order->get_billing_address_2(),
		'city'       => (string) $order->get_billing_city(),
		'state'      => (string) $order->get_billing_state(),
		'postcode'   => (string) $order->get_billing_postcode(),
		'country'    => (string) $order->get_billing_country(),
		'email'      => (string) $order->get_billing_email(),
		'phone'      => (string) $order->get_billing_phone(),
	);
	$billing     = array_filter( $billing_raw, static fn( string $v ): bool => '' !== $v );
	// Cast: non-empty maps stay as arrays (PHP arrays encode as JSON objects when keys are strings);
	// empty maps are cast to (object) so they encode as {} rather than [].
	$billing_out = empty( $billing ) ? (object) array() : $billing;

	// Shipping address — no email/phone (those are billing-only).
	$shipping_raw = array(
		'first_name' => (string) $order->get_shipping_first_name(),
		'last_name'  => (string) $order->get_shipping_last_name(),
		'company'    => (string) $order->get_shipping_company(),
		'address_1'  => (string) $order->get_shipping_address_1(),
		'address_2'  => (string) $order->get_shipping_address_2(),
		'city'       => (string) $order->get_shipping_city(),
		'state'      => (string) $order->get_shipping_state(),
		'postcode'   => (string) $order->get_shipping_postcode(),
		'country'    => (string) $order->get_shipping_country(),
	);
	$shipping     = array_filter( $shipping_raw, static fn( string $v ): bool => '' !== $v );
	$shipping_out = empty( $shipping ) ? (object) array() : $shipping;

	return array(
		'id'            => (int) $order->get_id(),
		'number'        => (string) $order->get_order_number(),
		'status'        => (string) $order->get_status(),
		'currency'      => (string) $order->get_currency(),
		'date_created'  => aafm_wc_date_string( $order->get_date_created() ),
		'date_paid'     => aafm_wc_date_string( $order->get_date_paid() ),
		'customer_id'   => (int) $order->get_customer_id(),
		'customer_note' => (string) $order->get_customer_note(),
		'line_items'    => $line_items,
		'totals'        => array(
			'total'    => (string) $order->get_total(),
			'subtotal' => (string) $order->get_subtotal(),
			'tax'      => (string) $order->get_total_tax(),
			'shipping' => (string) $order->get_shipping_total(),
		),
		'billing'       => $billing_out,
		'shipping'      => $shipping_out,
	);
}

/**
 * Args for aafm/wc-list-orders.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_orders(): array {
	return array(
		'label'               => __( 'List WooCommerce orders', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists WooCommerce orders (id, number, status, total, currency, date, customer id) plus a total count. List rows carry no billing or shipping details. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'orders' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => array( 'type' => 'integer' ),
							'number'       => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
							'total'        => array( 'type' => 'string' ),
							'currency'     => array( 'type' => 'string' ),
							'date_created' => array( 'type' => array( 'string', 'null' ) ),
							'customer_id'  => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					),
				),
				'total'  => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_orders',
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
 * Execute aafm/wc-list-orders.
 *
 * Queries orders via wc_get_orders() with paginate=>true to get the grand total separate from
 * the page slice. Each order in the result is mapped through aafm_redact_wc_order() which
 * returns only the lean fields — no billing/shipping/PII in list rows.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_wc_list_orders( array $input ): array {
	$out = array(
		'orders' => array(),
		'total'  => 0,
	);

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $out;
	}

	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';

	$query = wc_get_orders(
		array(
			'limit'    => $per_page,
			'paged'    => $page,
			'status'   => $status,
			'paginate' => true,
		)
	);

	// With paginate => true WooCommerce returns an object carrying ->orders (the page) and ->total
	// (the full matching count); total is the grand total, not the page row count.
	if ( is_object( $query ) && property_exists( $query, 'orders' ) ) {
		$orders = (array) $query->orders; // @phpstan-ignore-line property.dynamicName
		$total  = property_exists( $query, 'total' ) ? (int) $query->total : count( $orders ); // @phpstan-ignore-line property.dynamicName
	} else {
		$orders = (array) $query;
		$total  = count( $orders );
	}

	foreach ( $orders as $order ) {
		if ( $order instanceof \WC_Order ) {
			$out['orders'][] = aafm_redact_wc_order( $order );
		}
	}
	$out['total'] = $total;

	return $out;
}

/**
 * Args for aafm/wc-get-order.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_order(): array {
	return array(
		'label'               => __( 'Get WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce order by id: line items, totals (total, subtotal, tax, shipping), status, dates, customer note, and the full customer billing address — including email and phone — plus the shipping address. Customer billing PII (email, phone, full address) is returned in full under the Integrations security disclaimer and is always gated by the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'number'        => array( 'type' => 'string' ),
				'status'        => array( 'type' => 'string' ),
				'currency'      => array( 'type' => 'string' ),
				'date_created'  => array( 'type' => array( 'string', 'null' ) ),
				'date_paid'     => array( 'type' => array( 'string', 'null' ) ),
				'customer_id'   => array( 'type' => 'integer' ),
				'customer_note' => array( 'type' => 'string' ),
				'line_items'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'       => array( 'type' => 'string' ),
							'product_id' => array( 'type' => 'integer' ),
							'quantity'   => array( 'type' => 'integer' ),
							'subtotal'   => array( 'type' => 'string' ),
							'total'      => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'totals'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'total'    => array( 'type' => 'string' ),
						'subtotal' => array( 'type' => 'string' ),
						'tax'      => array( 'type' => 'string' ),
						'shipping' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'billing'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'company'    => array( 'type' => 'string' ),
						'address_1'  => array( 'type' => 'string' ),
						'address_2'  => array( 'type' => 'string' ),
						'city'       => array( 'type' => 'string' ),
						'state'      => array( 'type' => 'string' ),
						'postcode'   => array( 'type' => 'string' ),
						'country'    => array( 'type' => 'string' ),
						'email'      => array( 'type' => 'string' ),
						'phone'      => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'shipping'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'company'    => array( 'type' => 'string' ),
						'address_1'  => array( 'type' => 'string' ),
						'address_2'  => array( 'type' => 'string' ),
						'city'       => array( 'type' => 'string' ),
						'state'      => array( 'type' => 'string' ),
						'postcode'   => array( 'type' => 'string' ),
						'country'    => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_order',
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
 * Execute aafm/wc-get-order.
 *
 * Resolves the order id through wc_get_order() — not the product wc_get_product(). An unknown
 * id or a non-WC_Order return falls through to aafm_generic_error(). The full shape including
 * customer billing/shipping PII is assembled by aafm_rich_wc_order().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_order( array $input ) {
	$order = aafm_wc_get_order_object( (int) ( $input['order_id'] ?? 0 ) );
	if ( null === $order ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_order( $order );
}

// =============================================================================
// WC2.2 -- Order writes: create, update
// =============================================================================

/**
 * The shared writable order-field properties for the create/update input schemas.
 *
 * MEDIUM-4: billing{} and shipping{} each set additionalProperties:false, and the
 * line_items[] item object also sets additionalProperties:false. A smuggled key inside
 * any of these nested objects is therefore rejected before execute runs.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_order_write_properties(): array {
	return array(
		'status'        => array(
			'type'        => 'string',
			'description' => 'Order status slug (e.g. processing, completed, on-hold). Must match a key returned by wc_get_order_statuses().',
		),
		'customer_id'   => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'customer_note' => array( 'type' => 'string' ),
		'billing'       => array(
			'type'                 => 'object',
			// MEDIUM-4: close the nested billing object -- a smuggled key (e.g. billing.role) is rejected.
			'additionalProperties' => false,
			'properties'           => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'address_1'  => array( 'type' => 'string' ),
				'address_2'  => array( 'type' => 'string' ),
				'city'       => array( 'type' => 'string' ),
				'state'      => array( 'type' => 'string' ),
				'postcode'   => array( 'type' => 'string' ),
				'country'    => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
				'phone'      => array( 'type' => 'string' ),
			),
		),
		'shipping'      => array(
			'type'                 => 'object',
			// MEDIUM-4: close the nested shipping object.
			'additionalProperties' => false,
			'properties'           => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'address_1'  => array( 'type' => 'string' ),
				'address_2'  => array( 'type' => 'string' ),
				'city'       => array( 'type' => 'string' ),
				'state'      => array( 'type' => 'string' ),
				'postcode'   => array( 'type' => 'string' ),
				'country'    => array( 'type' => 'string' ),
			),
		),
		'line_items'    => array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				// MEDIUM-4: close the line_items item object -- meta_data and any other key are rejected.
				'additionalProperties' => false,
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'quantity'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'product_id', 'quantity' ),
			),
		),
	);
}

/**
 * Validate that a status slug is a known WooCommerce order status.
 *
 * The wc_get_order_statuses() function returns keys like 'wc-processing'; WooCommerce also
 * accepts the shorter form without the 'wc-' prefix. Both are checked here.
 *
 * @param string $status Status slug to test.
 * @return bool
 */
function aafm_wc_order_status_valid( string $status ): bool {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return false;
	}
	$statuses = wc_get_order_statuses();
	if ( array_key_exists( $status, $statuses ) ) {
		return true;
	}
	// Also accept the form without the 'wc-' prefix ('processing' matches 'wc-processing').
	return array_key_exists( 'wc-' . $status, $statuses );
}

/**
 * Apply sanitized order input onto a WC_Order via its setters (PATCH semantics -- only
 * keys present in $input are applied; unsent fields are left untouched).
 *
 * Sanitize policy: billing.email -> sanitize_email; all other address leaves ->
 * sanitize_text_field; customer_note -> sanitize_textarea_field; customer_id -> absint.
 * The nested billing/shipping arrays are sanitized leaf-by-leaf so structured data
 * is never flattened or corrupted.
 *
 * @param \WC_Order           $order The order to mutate.
 * @param array<string,mixed> $input Validated input (already schema-checked).
 * @return void
 */
function aafm_wc_apply_order_input( \WC_Order $order, array $input ): void {
	if ( array_key_exists( 'status', $input ) ) {
		// Normalise to short form before handing to set_status() -- strip any 'wc-' prefix so
		// both 'processing' and 'wc-processing' produce the same stored/returned value (matching
		// the real WC_Order convention where get_status() always returns the short form).
		$raw_status   = sanitize_text_field( (string) $input['status'] );
		$short_status = str_starts_with( $raw_status, 'wc-' ) ? substr( $raw_status, 3 ) : $raw_status;
		$order->set_status( $short_status );
	}
	if ( array_key_exists( 'customer_id', $input ) ) {
		$order->set_customer_id( absint( $input['customer_id'] ) );
	}
	if ( array_key_exists( 'customer_note', $input ) ) {
		$order->set_customer_note( sanitize_textarea_field( (string) $input['customer_note'] ) );
	}

	// Billing address -- sanitize each leaf individually (never flatten the map).
	if ( array_key_exists( 'billing', $input ) && is_array( $input['billing'] ) ) {
		$billing = $input['billing'];
		if ( array_key_exists( 'first_name', $billing ) ) {
			$order->set_billing_first_name( sanitize_text_field( (string) $billing['first_name'] ) );
		}
		if ( array_key_exists( 'last_name', $billing ) ) {
			$order->set_billing_last_name( sanitize_text_field( (string) $billing['last_name'] ) );
		}
		if ( array_key_exists( 'company', $billing ) ) {
			$order->set_billing_company( sanitize_text_field( (string) $billing['company'] ) );
		}
		if ( array_key_exists( 'address_1', $billing ) ) {
			$order->set_billing_address_1( sanitize_text_field( (string) $billing['address_1'] ) );
		}
		if ( array_key_exists( 'address_2', $billing ) ) {
			$order->set_billing_address_2( sanitize_text_field( (string) $billing['address_2'] ) );
		}
		if ( array_key_exists( 'city', $billing ) ) {
			$order->set_billing_city( sanitize_text_field( (string) $billing['city'] ) );
		}
		if ( array_key_exists( 'state', $billing ) ) {
			$order->set_billing_state( sanitize_text_field( (string) $billing['state'] ) );
		}
		if ( array_key_exists( 'postcode', $billing ) ) {
			$order->set_billing_postcode( sanitize_text_field( (string) $billing['postcode'] ) );
		}
		if ( array_key_exists( 'country', $billing ) ) {
			$order->set_billing_country( sanitize_text_field( (string) $billing['country'] ) );
		}
		if ( array_key_exists( 'email', $billing ) ) {
			$order->set_billing_email( sanitize_email( (string) $billing['email'] ) );
		}
		if ( array_key_exists( 'phone', $billing ) ) {
			$order->set_billing_phone( sanitize_text_field( (string) $billing['phone'] ) );
		}
	}

	// Shipping address -- no email/phone (billing-only).
	if ( array_key_exists( 'shipping', $input ) && is_array( $input['shipping'] ) ) {
		$shipping = $input['shipping'];
		if ( array_key_exists( 'first_name', $shipping ) ) {
			$order->set_shipping_first_name( sanitize_text_field( (string) $shipping['first_name'] ) );
		}
		if ( array_key_exists( 'last_name', $shipping ) ) {
			$order->set_shipping_last_name( sanitize_text_field( (string) $shipping['last_name'] ) );
		}
		if ( array_key_exists( 'company', $shipping ) ) {
			$order->set_shipping_company( sanitize_text_field( (string) $shipping['company'] ) );
		}
		if ( array_key_exists( 'address_1', $shipping ) ) {
			$order->set_shipping_address_1( sanitize_text_field( (string) $shipping['address_1'] ) );
		}
		if ( array_key_exists( 'address_2', $shipping ) ) {
			$order->set_shipping_address_2( sanitize_text_field( (string) $shipping['address_2'] ) );
		}
		if ( array_key_exists( 'city', $shipping ) ) {
			$order->set_shipping_city( sanitize_text_field( (string) $shipping['city'] ) );
		}
		if ( array_key_exists( 'state', $shipping ) ) {
			$order->set_shipping_state( sanitize_text_field( (string) $shipping['state'] ) );
		}
		if ( array_key_exists( 'postcode', $shipping ) ) {
			$order->set_shipping_postcode( sanitize_text_field( (string) $shipping['postcode'] ) );
		}
		if ( array_key_exists( 'country', $shipping ) ) {
			$order->set_shipping_country( sanitize_text_field( (string) $shipping['country'] ) );
		}
	}

	// Line items -- add each item via add_product (create path only; update does not re-add items).
	if ( array_key_exists( 'line_items', $input ) && is_array( $input['line_items'] ) ) {
		foreach ( $input['line_items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pid = absint( $item['product_id'] ?? 0 );
			$qty = max( 1, absint( $item['quantity'] ?? 1 ) );
			if ( $pid > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $pid );
				if ( $product ) {
					$order->add_product( $product, $qty );
				}
			}
		}
	}
}

/**
 * The shared output shape for order write results -- mirrors aafm_rich_wc_order() exactly.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_order_output_properties(): array {
	return array(
		'id'            => array( 'type' => 'integer' ),
		'number'        => array( 'type' => 'string' ),
		'status'        => array( 'type' => 'string' ),
		'currency'      => array( 'type' => 'string' ),
		'date_created'  => array( 'type' => array( 'string', 'null' ) ),
		'date_paid'     => array( 'type' => array( 'string', 'null' ) ),
		'customer_id'   => array( 'type' => 'integer' ),
		'customer_note' => array( 'type' => 'string' ),
		'line_items'    => array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'       => array( 'type' => 'string' ),
					'product_id' => array( 'type' => 'integer' ),
					'quantity'   => array( 'type' => 'integer' ),
					'subtotal'   => array( 'type' => 'string' ),
					'total'      => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		),
		'totals'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'total'    => array( 'type' => 'string' ),
				'subtotal' => array( 'type' => 'string' ),
				'tax'      => array( 'type' => 'string' ),
				'shipping' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'billing'       => array(
			'type'                 => 'object',
			'properties'           => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'address_1'  => array( 'type' => 'string' ),
				'address_2'  => array( 'type' => 'string' ),
				'city'       => array( 'type' => 'string' ),
				'state'      => array( 'type' => 'string' ),
				'postcode'   => array( 'type' => 'string' ),
				'country'    => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
				'phone'      => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'shipping'      => array(
			'type'                 => 'object',
			'properties'           => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'address_1'  => array( 'type' => 'string' ),
				'address_2'  => array( 'type' => 'string' ),
				'city'       => array( 'type' => 'string' ),
				'state'      => array( 'type' => 'string' ),
				'postcode'   => array( 'type' => 'string' ),
				'country'    => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
	);
}

/**
 * Args for aafm/wc-create-order.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_order(): array {
	return array(
		'label'               => __( 'Create WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce order from optional status, customer id, customer note, billing address, shipping address, and line items. Returns the full order shape including billing and shipping PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_wc_order_write_properties(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_order_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_order',
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
 * Execute aafm/wc-create-order.
 *
 * Creates a new WC_Order, applies validated input via aafm_wc_apply_order_input(),
 * saves, then returns the full rich shape via aafm_rich_wc_order(). An invalid status
 * (not in wc_get_order_statuses()) returns WP_Error before the order is created.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_order( array $input ) {
	if ( ! class_exists( 'WC_Order' ) ) {
		return aafm_generic_error();
	}

	// Validate status before creating the order.
	if ( array_key_exists( 'status', $input ) ) {
		if ( ! aafm_wc_order_status_valid( (string) $input['status'] ) ) {
			return aafm_generic_error();
		}
	}

	$order = new \WC_Order();
	aafm_wc_apply_order_input( $order, $input );
	$id = (int) $order->save();

	$saved = aafm_wc_get_order_object( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_order( $saved );
}

/**
 * Args for aafm/wc-update-order.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_order(): array {
	$properties             = aafm_wc_order_write_properties();
	$properties['order_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);

	return array(
		'label'               => __( 'Update WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce order by id, changing only the fields you send. An empty request body is a no-op success. Returns the full order shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_order_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_order',
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
 * Execute aafm/wc-update-order.
 *
 * Resolves order_id via aafm_wc_get_order_object() (null = generic error), applies
 * only the sent fields (PATCH semantics -- unsent fields are untouched), saves, then
 * returns the full rich shape. An invalid status returns WP_Error before saving.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_order( array $input ) {
	$order = aafm_wc_get_order_object( (int) ( $input['order_id'] ?? 0 ) );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	// Validate status before applying changes.
	if ( array_key_exists( 'status', $input ) ) {
		if ( ! aafm_wc_order_status_valid( (string) $input['status'] ) ) {
			return aafm_generic_error();
		}
	}

	// Remove order_id from the input map before passing to apply (it is not a field setter).
	$fields = $input;
	unset( $fields['order_id'] );

	aafm_wc_apply_order_input( $order, $fields );
	$order->save();

	$saved = aafm_wc_get_order_object( $order->get_id() );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_order( $saved );
}

// =============================================================================
// aafm/wc-update-order-status
// =============================================================================

/**
 * Args for aafm/wc-update-order-status.
 *
 * Closed schema: only order_id and status are accepted. Both are required.
 * Status accepts both the short form (e.g. "completed") and the wc-prefixed
 * form (e.g. "wc-completed") -- aafm_wc_order_status_valid() handles both.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_order_status(): array {
	return array(
		'label'               => __( 'Update WooCommerce order status', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Sets the status of a WooCommerce order by id. Accepts the short form (e.g. "completed") or the wc-prefixed form (e.g. "wc-completed"). Returns the full order shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'status'   => array(
					'type' => 'string',
				),
			),
			'required'             => array( 'order_id', 'status' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_order_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_order_status',
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
 * Execute aafm/wc-update-order-status.
 *
 * Resolves the order by order_id (null = generic error), validates the status
 * slug against the registered WooCommerce statuses (both short and wc-prefixed
 * forms are accepted), then calls update_status() + save() and returns the
 * full rich order shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_order_status( array $input ) {
	$order = aafm_wc_get_order_object( (int) ( $input['order_id'] ?? 0 ) );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$status = (string) ( $input['status'] ?? '' );
	if ( ! aafm_wc_order_status_valid( $status ) ) {
		return aafm_generic_error();
	}

	// Strip the wc- prefix before handing to update_status() -- the stub and
	// real WC_Order::update_status() both accept the short form.
	$short = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

	$order->update_status( $short );
	// save() is technically redundant on real WC (update_status() persists internally), but
	// is required here so the stub's save() flushes the in-memory data back to WcOrderStubStore.
	$order->save();

	$saved = aafm_wc_get_order_object( $order->get_id() );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_order( $saved );
}

/*
 * --------------------------------------------------------------------------
 * Order delete + notes + refunds (sub-slice W4-WC2.3)
 *
 * Group A: wc-delete-order (D)
 * Group B: wc-list-order-notes (R), wc-get-order-note (R),
 *          wc-create-order-note (W), wc-delete-order-note (D)
 * Group C: wc-list-order-refunds (R), wc-get-order-refund (R),
 *          wc-create-order-refund (W), wc-delete-order-refund (D)
 *
 * All nine gate on aafm_wc_perm() (manage_woocommerce). Every delete uses the
 * WooCommerce object's own ->delete() or wc_delete_order_note() — none is a
 * wp_delete_post/wp_delete_comment literal so the SecurityRegressionTest stays green.
 * --------------------------------------------------------------------------
 */

// ============================================================================
// Group A — aafm/wc-delete-order
// ============================================================================

/**
 * Args builder for aafm/wc-delete-order.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_order(): array {
	return array(
		'label'               => __( 'Delete WooCommerce order', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a WooCommerce order by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_order',
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
 * Execute aafm/wc-delete-order.
 *
 * Permanent removal through WooCommerce's own data store: $order->delete( true ).
 * The return value of delete() is surfaced — a false return becomes WP_Error so the
 * caller is never told deleted:true when the underlying operation failed.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_order( array $input ) {
	$order = aafm_wc_get_order_object( (int) ( $input['order_id'] ?? 0 ) );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$id = $order->get_id();
	$ok = $order->delete( true );
	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}

// ============================================================================
// Group B — order notes
// ============================================================================

/**
 * Resolve a single note from wc_get_order_notes() by note id.
 *
 * Scans all notes for the given order to find the matching note id. Returns null
 * when the order doesn't exist or the note id isn't found.
 *
 * @param int $order_id Order id.
 * @param int $note_id  Note id.
 * @return object|null stdClass note object or null.
 */
function aafm_wc_get_order_note( int $order_id, int $note_id ): ?object {
	$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
	foreach ( $notes as $note ) {
		// Real WC notes use comment_ID; our stub sets the same property.
		$id = isset( $note->comment_ID ) ? (int) $note->comment_ID : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mirrors real WP comment property name.
		if ( $id === $note_id ) {
			return $note;
		}
	}
	return null;
}

/**
 * Redact a note stdClass to the lean shape the ability surface exposes.
 *
 * @param object $note Note stdClass from wc_get_order_notes().
 * @return array<string,mixed>
 */
function aafm_wc_redact_note( object $note ): array {
	$id            = isset( $note->comment_ID ) ? (int) $note->comment_ID : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mirrors real WP comment property name.
	$text          = isset( $note->comment_content ) ? (string) $note->comment_content : '';
	$date_created  = isset( $note->date_created ) ? (string) $note->date_created : '';
	$customer_note = ! empty( $note->customer_note );
	$added_by_user = isset( $note->added_by ) && 'user' === (string) $note->added_by;

	return array(
		'id'            => $id,
		'note'          => $text,
		'added_by_user' => $added_by_user,
		'date_created'  => $date_created,
		'customer_note' => $customer_note,
	);
}

// ---------------------------------------------------------------------------
// Shared output-property helpers — notes and refunds.
// Used by both list and get schemas so they stay in lockstep.
// ---------------------------------------------------------------------------

/**
 * Shared output properties for a single order note.
 *
 * @return array<string,array<string,string>>
 */
function aafm_wc_note_output_properties(): array {
	return array(
		'id'            => array( 'type' => 'integer' ),
		'note'          => array( 'type' => 'string' ),
		'added_by_user' => array( 'type' => 'boolean' ),
		'date_created'  => array( 'type' => 'string' ),
		'customer_note' => array( 'type' => 'boolean' ),
	);
}

/**
 * Shared output properties for a single order refund.
 *
 * @return array<string,array<string,string>>
 */
function aafm_wc_refund_output_properties(): array {
	return array(
		'id'           => array( 'type' => 'integer' ),
		'amount'       => array( 'type' => 'string' ),
		'reason'       => array( 'type' => 'string' ),
		'date_created' => array( 'type' => 'string' ),
	);
}

// aafm/wc-list-order-notes (R).

/**
 * Args builder for aafm/wc-list-order-notes.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_order_notes(): array {
	return array(
		'label'               => __( 'List WooCommerce order notes', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all notes on a WooCommerce order by order id. Returns each note\'s id, text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'notes' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_wc_note_output_properties(),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_order_notes',
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
 * Execute aafm/wc-list-order-notes.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_order_notes( array $input ) {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$order    = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$raw   = wc_get_order_notes( array( 'order_id' => $order_id ) );
	$notes = array();
	foreach ( $raw as $note ) {
		$notes[] = aafm_wc_redact_note( $note );
	}

	return array( 'notes' => $notes );
}

// aafm/wc-get-order-note (R).

/**
 * Args builder for aafm/wc-get-order-note.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_order_note(): array {
	return array(
		'label'               => __( 'Get WooCommerce order note', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads a single note on a WooCommerce order by order id and note id. Returns the note text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'note_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'order_id', 'note_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_note_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_order_note',
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
 * Execute aafm/wc-get-order-note.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_order_note( array $input ) {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$note_id  = (int) ( $input['note_id'] ?? 0 );

	$order = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$note = aafm_wc_get_order_note( $order_id, $note_id );
	if ( null === $note ) {
		return aafm_generic_error();
	}

	return aafm_wc_redact_note( $note );
}

// aafm/wc-create-order-note (W).

/**
 * Args builder for aafm/wc-create-order-note.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_order_note(): array {
	return array(
		'label'               => __( 'Create WooCommerce order note', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Adds a note to a WooCommerce order by order id. Optionally marks the note as customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'note'          => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'customer_note' => array(
					'type' => 'boolean',
				),
			),
			'required'             => array( 'order_id', 'note' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'note'          => array( 'type' => 'string' ),
				'added_by_user' => array( 'type' => 'boolean' ),
				'customer_note' => array( 'type' => 'boolean' ),
				'date_created'  => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_create_order_note',
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
 * Execute aafm/wc-create-order-note.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_order_note( array $input ) {
	$order_id      = (int) ( $input['order_id'] ?? 0 );
	$note_text     = sanitize_text_field( (string) ( $input['note'] ?? '' ) );
	$customer_note = ! empty( $input['customer_note'] );

	$order = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$note_id = $order->add_order_note( $note_text, $customer_note, true );
	if ( ! $note_id ) {
		return aafm_generic_error();
	}

	return array(
		'id'            => (int) $note_id,
		'note'          => $note_text,
		'added_by_user' => true,
		'customer_note' => $customer_note,
		'date_created'  => gmdate( 'Y-m-d\TH:i:s' ),
	);
}

// aafm/wc-delete-order-note (D).

/**
 * Args builder for aafm/wc-delete-order-note.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_order_note(): array {
	return array(
		'label'               => __( 'Delete WooCommerce order note', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a note from a WooCommerce order. Requires both the order id and the note id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'note_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'order_id', 'note_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_order_note',
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
 * Execute aafm/wc-delete-order-note.
 *
 * Permanent removal through wc_delete_order_note() — WooCommerce's own function,
 * not wp_delete_comment(), so the SecurityRegressionTest grep stays green.
 * The return value is surfaced: false becomes WP_Error.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_order_note( array $input ) {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$note_id  = (int) ( $input['note_id'] ?? 0 );

	$order = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$ok = wc_delete_order_note( $note_id );
	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $note_id,
		'deleted' => true,
	);
}

// ============================================================================
// Group C — order refunds
// ============================================================================

/**
 * Resolve a refund object by refund id, or null when not found.
 *
 * On a real WooCommerce site, wc_get_order() with the refund post id returns a
 * WC_Order_Refund. In tests the WcOrderStubStore cross-order map provides the
 * same resolution. Returns null when the id is unknown.
 *
 * @param int $refund_id Refund id.
 * @return \WC_Order_Refund|null
 */
function aafm_wc_get_refund_object( int $refund_id ): ?\WC_Order_Refund {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return null;
	}
	$refund = wc_get_order( $refund_id );
	if ( ! ( $refund instanceof \WC_Order_Refund ) ) {
		return null;
	}
	return $refund;
}

/**
 * Redact a WC_Order_Refund to the lean shape the ability surface exposes.
 *
 * @param \WC_Order_Refund $refund Refund object.
 * @return array<string,mixed>
 */
function aafm_wc_redact_refund( \WC_Order_Refund $refund ): array {
	$date = $refund->get_date_created();
	return array(
		'id'           => $refund->get_id(),
		'amount'       => $refund->get_amount(),
		'reason'       => $refund->get_reason(),
		'date_created' => is_object( $date ) && method_exists( $date, 'format' ) ? $date->format( 'Y-m-d\TH:i:s' ) : (string) $date,
	);
}

// aafm/wc-list-order-refunds (R).

/**
 * Args builder for aafm/wc-list-order-refunds.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_order_refunds(): array {
	return array(
		'label'               => __( 'List WooCommerce order refunds', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all refunds on a WooCommerce order by order id. Returns each refund\'s id, amount, reason, and date. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'refunds' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_wc_refund_output_properties(),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_order_refunds',
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
 * Execute aafm/wc-list-order-refunds.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_order_refunds( array $input ) {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$order    = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$raw     = $order->get_refunds();
	$refunds = array();
	foreach ( $raw as $refund ) {
		if ( $refund instanceof \WC_Order_Refund ) {
			$refunds[] = aafm_wc_redact_refund( $refund );
		}
	}

	return array( 'refunds' => $refunds );
}

// aafm/wc-get-order-refund (R).

/**
 * Args builder for aafm/wc-get-order-refund.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_order_refund(): array {
	return array(
		'label'               => __( 'Get WooCommerce order refund', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads a single refund by refund id. Returns the refund amount, reason, and date. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'refund_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'refund_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_refund_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_order_refund',
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
 * Execute aafm/wc-get-order-refund.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_order_refund( array $input ) {
	$refund_id = (int) ( $input['refund_id'] ?? 0 );
	$refund    = aafm_wc_get_refund_object( $refund_id );
	if ( null === $refund ) {
		return aafm_generic_error();
	}
	return aafm_wc_redact_refund( $refund );
}

// aafm/wc-create-order-refund (W).

/**
 * Args builder for aafm/wc-create-order-refund.
 *
 * The line_items[] sub-schema also carries additionalProperties:false (MED-4) so
 * smuggled keys inside a line-item are rejected before execute is ever called.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_order_refund(): array {
	return array(
		'label'               => __( 'Create WooCommerce order refund', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a refund on a WooCommerce order by order id. Accepts an amount, optional reason, and optional line-item breakdown. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'amount'     => array(
					'type'    => 'string',
					'pattern' => '^\d+(\.\d{1,2})?$',
				),
				'reason'     => array(
					'type' => 'string',
				),
				'line_items' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'line_item_id' => array( 'type' => 'integer' ),
							'refund_total' => array( 'type' => 'string' ),
							'refund_tax'   => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'order_id', 'amount' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'amount'       => array( 'type' => 'string' ),
				'reason'       => array( 'type' => 'string' ),
				'date_created' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_create_order_refund',
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
 * Execute aafm/wc-create-order-refund.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_order_refund( array $input ) {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$amount   = sanitize_text_field( (string) ( $input['amount'] ?? '0.00' ) );
	$reason   = sanitize_text_field( (string) ( $input['reason'] ?? '' ) );

	$order = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$refund_args = array(
		'order_id' => $order_id,
		'amount'   => $amount,
		'reason'   => $reason,
	);

	// Pass line_items through when provided — wc_create_refund() accepts them.
	if ( ! empty( $input['line_items'] ) && is_array( $input['line_items'] ) ) {
		$line_items = array();
		foreach ( $input['line_items'] as $item ) {
			$item                        = (array) $item;
			$line_item_id                = isset( $item['line_item_id'] ) ? (int) $item['line_item_id'] : 0;
			$refund_total                = isset( $item['refund_total'] ) ? (string) $item['refund_total'] : '0.00';
			$refund_tax                  = isset( $item['refund_tax'] ) ? (string) $item['refund_tax'] : '0.00';
			$line_items[ $line_item_id ] = array(
				'refund_total' => $refund_total,
				'refund_tax'   => array( $refund_tax ),
			);
		}
		$refund_args['line_items'] = $line_items;
	}

	$refund = wc_create_refund( $refund_args );

	if ( is_wp_error( $refund ) || ! ( $refund instanceof \WC_Order_Refund ) ) {
		return aafm_generic_error();
	}

	return aafm_wc_redact_refund( $refund );
}

// aafm/wc-delete-order-refund (D).

/**
 * Args builder for aafm/wc-delete-order-refund.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_order_refund(): array {
	return array(
		'label'               => __( 'Delete WooCommerce order refund', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a WooCommerce order refund by refund id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'refund_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'refund_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_order_refund',
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
 * Execute aafm/wc-delete-order-refund.
 *
 * Permanent removal through the refund object's own ->delete( true ) — this is
 * WooCommerce's own data-store method, not wp_delete_post(), so the
 * SecurityRegressionTest grep stays green. The return value is surfaced: false
 * becomes WP_Error.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_order_refund( array $input ) {
	$refund_id = (int) ( $input['refund_id'] ?? 0 );
	$refund    = aafm_wc_get_refund_object( $refund_id );
	if ( null === $refund ) {
		return aafm_generic_error();
	}

	$ok = $refund->delete( true );
	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array(
		'id'      => $refund_id,
		'deleted' => true,
	);
}

// =============================================================================
// WC3 -- Customers: list, get, create, update, delete
// All five abilities gate on the flat, object-independent manage_woocommerce cap
// (aafm_wc_perm). None needs a server.php case — they fall through at discovery.
// Customer PII (email, billing phone, billing/shipping addresses) is returned in
// full under the Integrations security disclaimer (aafm_woocommerce_disclaimer).
// =============================================================================

// -------------------------------------------------------------------------
// Shared helpers
// -------------------------------------------------------------------------

/**
 * Resolve a customer id to a WC_Customer object, or null when the id is unknown or WooCommerce
 * is unavailable.
 *
 * @param int $id Customer id.
 * @return \WC_Customer|null
 */
function aafm_wc_get_customer_object( int $id ): ?\WC_Customer {
	if ( ! function_exists( 'wc_get_customer' ) ) {
		return null;
	}
	$customer = wc_get_customer( $id );
	return ( $customer instanceof \WC_Customer ) ? $customer : null;
}

/**
 * The shared billing address schema properties (used in input and output schemas).
 *
 * @return array<string,mixed>
 */
function aafm_wc_customer_billing_properties(): array {
	return array(
		'first_name' => array( 'type' => 'string' ),
		'last_name'  => array( 'type' => 'string' ),
		'company'    => array( 'type' => 'string' ),
		'address_1'  => array( 'type' => 'string' ),
		'address_2'  => array( 'type' => 'string' ),
		'city'       => array( 'type' => 'string' ),
		'state'      => array( 'type' => 'string' ),
		'postcode'   => array( 'type' => 'string' ),
		'country'    => array( 'type' => 'string' ),
		'email'      => array( 'type' => 'string' ),
		'phone'      => array( 'type' => 'string' ),
	);
}

/**
 * The shared shipping address schema properties (used in input and output schemas).
 *
 * @return array<string,mixed>
 */
function aafm_wc_customer_shipping_properties(): array {
	return array(
		'first_name' => array( 'type' => 'string' ),
		'last_name'  => array( 'type' => 'string' ),
		'company'    => array( 'type' => 'string' ),
		'address_1'  => array( 'type' => 'string' ),
		'address_2'  => array( 'type' => 'string' ),
		'city'       => array( 'type' => 'string' ),
		'state'      => array( 'type' => 'string' ),
		'postcode'   => array( 'type' => 'string' ),
		'country'    => array( 'type' => 'string' ),
	);
}

/**
 * The shared writable customer properties (create + update input schemas).
 *
 * Both billing{} and shipping{} carry additionalProperties:false (MEDIUM-4 closed-schema
 * security control): any key outside the declared set — e.g. billing.role — is rejected by
 * the Abilities API before the executor runs, so a nested-smuggle attack cannot bypass the
 * field-level sanitise layer.
 *
 * @return array<string,mixed>
 */
function aafm_wc_customer_write_properties(): array {
	return array(
		'first_name' => array( 'type' => 'string' ),
		'last_name'  => array( 'type' => 'string' ),
		'billing'    => array(
			'type'                 => 'object',
			'properties'           => aafm_wc_customer_billing_properties(),
			'additionalProperties' => false,
		),
		'shipping'   => array(
			'type'                 => 'object',
			'properties'           => aafm_wc_customer_shipping_properties(),
			'additionalProperties' => false,
		),
	);
}

/**
 * The full customer output shape (list row is a lean subset of this).
 *
 * @return array<string,mixed>
 */
function aafm_wc_customer_output_properties(): array {
	return array(
		'id'           => array( 'type' => 'integer' ),
		'email'        => array( 'type' => 'string' ),
		'first_name'   => array( 'type' => 'string' ),
		'last_name'    => array( 'type' => 'string' ),
		'username'     => array( 'type' => 'string' ),
		'orders_count' => array( 'type' => 'integer' ),
		'total_spent'  => array( 'type' => 'string' ),
		'date_created' => array( 'type' => array( 'string', 'null' ) ),
		'billing'      => array(
			'type'                 => 'object',
			'properties'           => aafm_wc_customer_billing_properties(),
			'additionalProperties' => false,
		),
		'shipping'     => array(
			'type'                 => 'object',
			'properties'           => aafm_wc_customer_shipping_properties(),
			'additionalProperties' => false,
		),
	);
}

/**
 * Assemble the lean list-row shape for one customer (no address details).
 *
 * @param \WC_Customer $customer Customer object.
 * @return array<string,mixed>
 */
function aafm_redact_wc_customer( \WC_Customer $customer ): array {
	return array(
		'id'           => $customer->get_id(),
		'email'        => $customer->get_email(),
		'first_name'   => $customer->get_first_name(),
		'last_name'    => $customer->get_last_name(),
		'username'     => $customer->get_username(),
		'orders_count' => $customer->get_order_count(),
		'total_spent'  => $customer->get_total_spent(),
	);
}

/**
 * Assemble the full rich customer shape including billing/shipping addresses.
 *
 * Billing and shipping objects always return as objects ({}) even when all fields are empty,
 * matching the empty-map contract: (object) array() prevents JSON serialisation as [].
 *
 * @param \WC_Customer $customer Customer object.
 * @return array<string,mixed>
 */
function aafm_rich_wc_customer( \WC_Customer $customer ): array {
	$date_created = $customer->get_date_created();

	$billing          = array(
		'first_name' => $customer->get_billing_first_name(),
		'last_name'  => $customer->get_billing_last_name(),
		'company'    => $customer->get_billing_company(),
		'address_1'  => $customer->get_billing_address_1(),
		'address_2'  => $customer->get_billing_address_2(),
		'city'       => $customer->get_billing_city(),
		'state'      => $customer->get_billing_state(),
		'postcode'   => $customer->get_billing_postcode(),
		'country'    => $customer->get_billing_country(),
		'email'      => $customer->get_billing_email(),
		'phone'      => $customer->get_billing_phone(),
	);
	$is_billing_empty = '' === implode( '', $billing );

	$shipping          = array(
		'first_name' => $customer->get_shipping_first_name(),
		'last_name'  => $customer->get_shipping_last_name(),
		'company'    => $customer->get_shipping_company(),
		'address_1'  => $customer->get_shipping_address_1(),
		'address_2'  => $customer->get_shipping_address_2(),
		'city'       => $customer->get_shipping_city(),
		'state'      => $customer->get_shipping_state(),
		'postcode'   => $customer->get_shipping_postcode(),
		'country'    => $customer->get_shipping_country(),
	);
	$is_shipping_empty = '' === implode( '', $shipping );

	return array(
		'id'           => $customer->get_id(),
		'email'        => $customer->get_email(),
		'first_name'   => $customer->get_first_name(),
		'last_name'    => $customer->get_last_name(),
		'username'     => $customer->get_username(),
		'orders_count' => $customer->get_order_count(),
		'total_spent'  => $customer->get_total_spent(),
		'date_created' => aafm_wc_date_string( $customer->get_date_created() ),
		'billing'      => $is_billing_empty ? (object) array() : $billing,
		'shipping'     => $is_shipping_empty ? (object) array() : $shipping,
	);
}

/**
 * Apply validated write-input fields to a WC_Customer instance (shared by create + update).
 *
 * Only keys present in $input are applied — missing keys are not zeroed — so update is a true
 * partial PATCH and create only sets what was provided.
 *
 * @param \WC_Customer        $customer Customer object to mutate.
 * @param array<string,mixed> $input    Validated input.
 * @return void
 */
function aafm_wc_apply_customer_input( \WC_Customer $customer, array $input ): void {
	if ( array_key_exists( 'first_name', $input ) ) {
		$customer->set_first_name( sanitize_text_field( (string) $input['first_name'] ) );
	}
	if ( array_key_exists( 'last_name', $input ) ) {
		$customer->set_last_name( sanitize_text_field( (string) $input['last_name'] ) );
	}

	if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
		$b = $input['billing'];
		if ( array_key_exists( 'first_name', $b ) ) {
			$customer->set_billing_first_name( sanitize_text_field( (string) $b['first_name'] ) ); }
		if ( array_key_exists( 'last_name', $b ) ) {
			$customer->set_billing_last_name( sanitize_text_field( (string) $b['last_name'] ) ); }
		if ( array_key_exists( 'company', $b ) ) {
			$customer->set_billing_company( sanitize_text_field( (string) $b['company'] ) ); }
		if ( array_key_exists( 'address_1', $b ) ) {
			$customer->set_billing_address_1( sanitize_text_field( (string) $b['address_1'] ) ); }
		if ( array_key_exists( 'address_2', $b ) ) {
			$customer->set_billing_address_2( sanitize_text_field( (string) $b['address_2'] ) ); }
		if ( array_key_exists( 'city', $b ) ) {
			$customer->set_billing_city( sanitize_text_field( (string) $b['city'] ) ); }
		if ( array_key_exists( 'state', $b ) ) {
			$customer->set_billing_state( sanitize_text_field( (string) $b['state'] ) ); }
		if ( array_key_exists( 'postcode', $b ) ) {
			$customer->set_billing_postcode( sanitize_text_field( (string) $b['postcode'] ) ); }
		if ( array_key_exists( 'country', $b ) ) {
			$customer->set_billing_country( sanitize_text_field( (string) $b['country'] ) ); }
		if ( array_key_exists( 'email', $b ) ) {
			$customer->set_billing_email( sanitize_email( (string) $b['email'] ) ); }
		if ( array_key_exists( 'phone', $b ) ) {
			$customer->set_billing_phone( sanitize_text_field( (string) $b['phone'] ) ); }
	}

	if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
		$s = $input['shipping'];
		if ( array_key_exists( 'first_name', $s ) ) {
			$customer->set_shipping_first_name( sanitize_text_field( (string) $s['first_name'] ) ); }
		if ( array_key_exists( 'last_name', $s ) ) {
			$customer->set_shipping_last_name( sanitize_text_field( (string) $s['last_name'] ) ); }
		if ( array_key_exists( 'company', $s ) ) {
			$customer->set_shipping_company( sanitize_text_field( (string) $s['company'] ) ); }
		if ( array_key_exists( 'address_1', $s ) ) {
			$customer->set_shipping_address_1( sanitize_text_field( (string) $s['address_1'] ) ); }
		if ( array_key_exists( 'address_2', $s ) ) {
			$customer->set_shipping_address_2( sanitize_text_field( (string) $s['address_2'] ) ); }
		if ( array_key_exists( 'city', $s ) ) {
			$customer->set_shipping_city( sanitize_text_field( (string) $s['city'] ) ); }
		if ( array_key_exists( 'state', $s ) ) {
			$customer->set_shipping_state( sanitize_text_field( (string) $s['state'] ) ); }
		if ( array_key_exists( 'postcode', $s ) ) {
			$customer->set_shipping_postcode( sanitize_text_field( (string) $s['postcode'] ) ); }
		if ( array_key_exists( 'country', $s ) ) {
			$customer->set_shipping_country( sanitize_text_field( (string) $s['country'] ) ); }
	}
}

// aafm/wc-list-customers (R).

/**
 * Args builder for aafm/wc-list-customers.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_customers(): array {
	return array(
		'label'               => __( 'List WooCommerce customers', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists WooCommerce customers (id, email, name, username, order count, total spent). Customer email is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'customers' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => array( 'type' => 'integer' ),
							'email'        => array( 'type' => 'string' ),
							'first_name'   => array( 'type' => 'string' ),
							'last_name'    => array( 'type' => 'string' ),
							'username'     => array( 'type' => 'string' ),
							'orders_count' => array( 'type' => 'integer' ),
							'total_spent'  => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'total'     => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_customers',
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
 * Execute aafm/wc-list-customers.
 *
 * Queries customers via wc_get_customers() (real WooCommerce) or WcCustomerStubStore (tests).
 * Each customer is mapped through the lean aafm_redact_wc_customer() shape — no addresses.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_wc_list_customers( array $input ): array {
	if ( ! function_exists( 'wc_get_customers' ) ) {
		return array(
			'customers' => array(),
			'total'     => 0,
		);
	}

	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 10;

	$args = array(
		'limit'    => $per_page,
		'paged'    => $page,
		'paginate' => true,
	);

	$query   = wc_get_customers( $args );
	$objects = is_object( $query ) && isset( $query->results ) ? $query->results : ( is_array( $query ) ? $query : array() );
	$total   = is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $objects );

	$customers = array();
	foreach ( $objects as $customer ) {
		if ( $customer instanceof \WC_Customer ) {
			$customers[] = aafm_redact_wc_customer( $customer );
		}
	}

	return array(
		'customers' => $customers,
		'total'     => $total,
	);
}

// aafm/wc-get-customer (R).

/**
 * Args builder for aafm/wc-get-customer.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_customer(): array {
	return array(
		'label'               => __( 'Get WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce customer by id: email, name, username, order count, total spent, date created, and the full billing address (including phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'customer_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'customer_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_customer_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_customer',
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
 * Execute aafm/wc-get-customer.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_customer( array $input ) {
	$customer = aafm_wc_get_customer_object( (int) ( $input['customer_id'] ?? 0 ) );
	if ( null === $customer ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_customer( $customer );
}

// aafm/wc-create-customer (W).

/**
 * Args builder for aafm/wc-create-customer.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_customer(): array {
	$properties             = aafm_wc_customer_write_properties();
	$properties['email']    = array( 'type' => 'string' );
	$properties['username'] = array( 'type' => 'string' );

	return array(
		'label'               => __( 'Create WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce customer from an email (required) and optional username, first name, last name, and billing/shipping address. Returns the full customer shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'email' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_customer_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_customer',
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
 * Execute aafm/wc-create-customer.
 *
 * Creates via wc_create_customer(), then applies optional address fields and saves.
 * Returns WP_Error on any failure.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_customer( array $input ) {
	if ( ! function_exists( 'wc_create_customer' ) ) {
		return aafm_generic_error();
	}

	$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$username = sanitize_user( (string) ( $input['username'] ?? $email ) );

	$customer = wc_create_customer( $email, $username, wp_generate_password() );
	if ( $customer instanceof \WP_Error ) {
		return aafm_generic_error();
	}
	if ( ! ( $customer instanceof \WC_Customer ) ) {
		return aafm_generic_error();
	}

	aafm_wc_apply_customer_input( $customer, $input );
	$id = (int) $customer->save();
	if ( $id < 1 ) {
		return aafm_generic_error();
	}

	$saved = aafm_wc_get_customer_object( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_customer( $saved );
}

// aafm/wc-update-customer (W).

/**
 * Args builder for aafm/wc-update-customer.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_customer(): array {
	$properties                = aafm_wc_customer_write_properties();
	$properties['customer_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);

	return array(
		'label'               => __( 'Update WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce customer by id, changing only the fields you send (name, billing, and shipping). Account email and username are not updatable here — use customer management tools for account-level changes. An empty request body (with only customer_id) is a no-op success. Returns the full customer shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'customer_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_customer_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_customer',
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
 * Execute aafm/wc-update-customer.
 *
 * Loads the existing customer, applies only the keys present in $input, saves, then
 * returns the fresh rich shape. An input carrying only customer_id is a no-op success.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_customer( array $input ) {
	$id       = (int) ( $input['customer_id'] ?? 0 );
	$customer = aafm_wc_get_customer_object( $id );
	if ( null === $customer ) {
		return aafm_generic_error();
	}

	aafm_wc_apply_customer_input( $customer, $input );
	$saved_id = (int) $customer->save();
	if ( $saved_id < 1 ) {
		return aafm_generic_error();
	}

	$saved = aafm_wc_get_customer_object( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_customer( $saved );
}

// aafm/wc-delete-customer (W/destructive).

/**
 * Args builder for aafm/wc-delete-customer.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_customer(): array {
	return array(
		'label'               => __( 'Delete WooCommerce customer', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a WooCommerce customer (WordPress user) by id and reassigns their content to another user. The current user cannot delete their own account. Cannot delete the last administrator. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'customer_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'reassign_to' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'customer_id', 'reassign_to' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_customer',
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
 * Execute aafm/wc-delete-customer.
 *
 * Mirrors the delete-user invariants exactly (same guards, same order):
 *   1. Victim exists (valid WP_User).
 *   2. Cannot delete the current user.
 *   3. Reassign id is provided, differs from victim, and refers to a valid WP_User.
 *   4. Cannot remove the last administrator.
 * Then calls wp_delete_user() — WooCommerce's own customer delete path — with the
 * reassign id. Returns WP_Error on any failure.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_customer( array $input ) {
	$id       = isset( $input['customer_id'] ) ? absint( $input['customer_id'] ) : 0;
	$reassign = isset( $input['reassign_to'] ) ? absint( $input['reassign_to'] ) : 0;
	$victim   = $id ? get_userdata( $id ) : false;

	if ( ! $victim instanceof \WP_User ) {
		return aafm_generic_error();
	}
	if ( get_current_user_id() === $id ) {
		return aafm_generic_error();
	}
	if ( ! $reassign || $reassign === $id || ! get_userdata( $reassign ) instanceof \WP_User ) {
		return aafm_generic_error();
	}
	if ( in_array( 'administrator', (array) $victim->roles, true ) ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 2,
			)
		);
		if ( count( $admins ) <= 1 ) {
			return aafm_generic_error();
		}
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	$ok = wp_delete_user( $id, $reassign );
	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}

// =============================================================================
// WC4 -- Coupons: list, get, create, update, delete
// =============================================================================

/**
 * Resolve a coupon id to a WC_Coupon object, or null when the id is unknown or WooCommerce
 * is unavailable.
 *
 * @param int $id Coupon id.
 * @return \WC_Coupon|null
 */
function aafm_wc_get_coupon_object( int $id ): ?\WC_Coupon {
	if ( $id <= 0 || ! class_exists( 'WC_Coupon' ) ) {
		return null;
	}
	$coupon = new \WC_Coupon( $id );
	return ( $coupon->get_id() > 0 ) ? $coupon : null;
}

/**
 * Build the lean coupon shape used in list rows: id, code, amount, discount_type,
 * date_expires, usage_count only.
 *
 * @param \WC_Coupon $coupon Coupon object.
 * @return array<string,mixed>
 */
function aafm_redact_wc_coupon( \WC_Coupon $coupon ): array {
	return array(
		'id'            => $coupon->get_id(),
		'code'          => $coupon->get_code(),
		'amount'        => $coupon->get_amount(),
		'discount_type' => $coupon->get_discount_type(),
		'date_expires'  => aafm_wc_date_string( $coupon->get_date_expires() ),
		'usage_count'   => $coupon->get_usage_count(),
	);
}

/**
 * Build the full coupon shape: all config fields including product/email restrictions.
 *
 * @param \WC_Coupon $coupon Coupon object.
 * @return array<string,mixed>
 */
function aafm_rich_wc_coupon( \WC_Coupon $coupon ): array {
	return array(
		'id'                   => $coupon->get_id(),
		'code'                 => $coupon->get_code(),
		'amount'               => $coupon->get_amount(),
		'discount_type'        => $coupon->get_discount_type(),
		'description'          => $coupon->get_description(),
		'date_expires'         => aafm_wc_date_string( $coupon->get_date_expires() ),
		'usage_count'          => $coupon->get_usage_count(),
		'usage_limit'          => $coupon->get_usage_limit(),
		'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
		'minimum_amount'       => $coupon->get_minimum_amount(),
		'maximum_amount'       => $coupon->get_maximum_amount(),
		'individual_use'       => $coupon->get_individual_use(),
		'exclude_sale_items'   => $coupon->get_exclude_sale_items(),
		'product_ids'          => array_values( array_map( 'intval', $coupon->get_product_ids() ) ),
		'excluded_product_ids' => array_values( array_map( 'intval', $coupon->get_excluded_product_ids() ) ),
		'email_restrictions'   => array_values( $coupon->get_email_restrictions() ),
	);
}

/**
 * Shared output properties for the coupon full shape (used in create and update response schemas).
 *
 * @return array<string,mixed>
 */
function aafm_wc_coupon_output_properties(): array {
	return array(
		'id'                   => array( 'type' => 'integer' ),
		'code'                 => array( 'type' => 'string' ),
		'amount'               => array( 'type' => 'string' ),
		'discount_type'        => array( 'type' => 'string' ),
		'description'          => array( 'type' => 'string' ),
		'date_expires'         => array( 'type' => array( 'string', 'null' ) ),
		'usage_count'          => array( 'type' => 'integer' ),
		'usage_limit'          => array( 'type' => array( 'integer', 'null' ) ),
		'usage_limit_per_user' => array( 'type' => array( 'integer', 'null' ) ),
		'minimum_amount'       => array( 'type' => 'string' ),
		'maximum_amount'       => array( 'type' => 'string' ),
		'individual_use'       => array( 'type' => 'boolean' ),
		'exclude_sale_items'   => array( 'type' => 'boolean' ),
		'product_ids'          => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'excluded_product_ids' => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'email_restrictions'   => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
	);
}

/**
 * Shared write-input properties for create and update (everything except coupon_id).
 *
 * @return array<string,mixed>
 */
function aafm_wc_coupon_write_properties(): array {
	return array(
		'code'                 => array( 'type' => 'string' ),
		'amount'               => array( 'type' => 'string' ),
		'discount_type'        => array(
			'type' => 'string',
			'enum' => array( 'percent', 'fixed_cart', 'fixed_product' ),
		),
		'description'          => array( 'type' => 'string' ),
		'date_expires'         => array( 'type' => array( 'string', 'null' ) ),
		'usage_limit'          => array( 'type' => array( 'integer', 'null' ) ),
		'usage_limit_per_user' => array( 'type' => array( 'integer', 'null' ) ),
		'minimum_amount'       => array( 'type' => 'string' ),
		'maximum_amount'       => array( 'type' => 'string' ),
		'individual_use'       => array( 'type' => 'boolean' ),
		'exclude_sale_items'   => array( 'type' => 'boolean' ),
		'product_ids'          => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'excluded_product_ids' => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'email_restrictions'   => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
	);
}

/**
 * Apply validated write-input fields to a WC_Coupon instance (shared by create and update).
 *
 * Only the keys present in $input are applied; absent keys leave the coupon unchanged, which is
 * what makes an empty PATCH a no-op.
 *
 * @param \WC_Coupon          $coupon Coupon object to mutate.
 * @param array<string,mixed> $input  Validated input fields (coupon_id already extracted).
 * @return void
 */
function aafm_wc_apply_coupon_input( \WC_Coupon $coupon, array $input ): void {
	if ( array_key_exists( 'code', $input ) ) {
		$coupon->set_code( sanitize_text_field( (string) $input['code'] ) );
	}
	if ( array_key_exists( 'amount', $input ) ) {
		$coupon->set_amount( sanitize_text_field( (string) $input['amount'] ) );
	}
	if ( array_key_exists( 'discount_type', $input ) ) {
		$coupon->set_discount_type( sanitize_text_field( (string) $input['discount_type'] ) );
	}
	if ( array_key_exists( 'description', $input ) ) {
		$coupon->set_description( sanitize_text_field( (string) $input['description'] ) );
	}
	if ( array_key_exists( 'date_expires', $input ) ) {
		$val = $input['date_expires'];
		$coupon->set_date_expires( null === $val ? null : sanitize_text_field( (string) $val ) );
	}
	if ( array_key_exists( 'usage_limit', $input ) ) {
		$val = $input['usage_limit'];
		$coupon->set_usage_limit( null === $val ? null : absint( $val ) );
	}
	if ( array_key_exists( 'usage_limit_per_user', $input ) ) {
		$val = $input['usage_limit_per_user'];
		$coupon->set_usage_limit_per_user( null === $val ? null : absint( $val ) );
	}
	if ( array_key_exists( 'minimum_amount', $input ) ) {
		$coupon->set_minimum_amount( sanitize_text_field( (string) $input['minimum_amount'] ) );
	}
	if ( array_key_exists( 'maximum_amount', $input ) ) {
		$coupon->set_maximum_amount( sanitize_text_field( (string) $input['maximum_amount'] ) );
	}
	if ( array_key_exists( 'individual_use', $input ) ) {
		$coupon->set_individual_use( (bool) $input['individual_use'] );
	}
	if ( array_key_exists( 'exclude_sale_items', $input ) ) {
		$coupon->set_exclude_sale_items( (bool) $input['exclude_sale_items'] );
	}
	if ( array_key_exists( 'product_ids', $input ) ) {
		$coupon->set_product_ids( array_map( 'absint', (array) $input['product_ids'] ) );
	}
	if ( array_key_exists( 'excluded_product_ids', $input ) ) {
		$coupon->set_excluded_product_ids( array_map( 'absint', (array) $input['excluded_product_ids'] ) );
	}
	if ( array_key_exists( 'email_restrictions', $input ) ) {
		$coupon->set_email_restrictions(
			array_map(
				static fn( $v ): string => sanitize_email( (string) $v ),
				(array) $input['email_restrictions']
			)
		);
	}
}

// =============================================================================
// wc-list-coupons
// =============================================================================

/**
 * Args builder for aafm/wc-list-coupons.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_coupons(): array {
	return array(
		'label'               => __( 'List WooCommerce coupons', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists WooCommerce coupons (id, code, amount, discount type, expiry date, usage count), plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'coupons' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'            => array( 'type' => 'integer' ),
							'code'          => array( 'type' => 'string' ),
							'amount'        => array( 'type' => 'string' ),
							'discount_type' => array( 'type' => 'string' ),
							'date_expires'  => array( 'type' => array( 'string', 'null' ) ),
							'usage_count'   => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					),
				),
				'total'   => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_coupons',
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
 * Execute aafm/wc-list-coupons.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_coupons( array $input ) {
	if ( ! function_exists( 'wc_get_coupons' ) ) {
		return aafm_generic_error();
	}

	$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 10;
	$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;

	$result = wc_get_coupons(
		array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
		)
	);

	$coupons_raw = is_object( $result ) && isset( $result->coupons ) ? $result->coupons : array();
	$total       = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;

	$rows = array();
	foreach ( $coupons_raw as $coupon ) {
		if ( $coupon instanceof \WC_Coupon ) {
			$rows[] = aafm_redact_wc_coupon( $coupon );
		}
	}

	return array(
		'coupons' => $rows,
		'total'   => $total,
	);
}

// =============================================================================
// wc-get-coupon
// =============================================================================

/**
 * Args builder for aafm/wc-get-coupon.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_coupon(): array {
	return array(
		'label'               => __( 'Get WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce coupon by id: full config including code, discount type, amount, expiry, usage limits, spend limits, product restrictions, and email restrictions. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'coupon_id' ),
			'properties'           => array(
				'coupon_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_coupon_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_coupon',
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
 * Execute aafm/wc-get-coupon.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_coupon( array $input ) {
	$coupon = aafm_wc_get_coupon_object( absint( $input['coupon_id'] ?? 0 ) );
	if ( null === $coupon ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_coupon( $coupon );
}

// =============================================================================
// wc-create-coupon
// =============================================================================

/**
 * Args builder for aafm/wc-create-coupon.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_coupon(): array {
	return array(
		'label'               => __( 'Create WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce coupon. Provide a code and discount type (percent, fixed_cart, or fixed_product); all other fields are optional. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'code' ),
			'properties'           => aafm_wc_coupon_write_properties(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_coupon_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_coupon',
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
 * Execute aafm/wc-create-coupon.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_coupon( array $input ) {
	if ( ! class_exists( 'WC_Coupon' ) ) {
		return aafm_generic_error();
	}

	$coupon = new \WC_Coupon();
	aafm_wc_apply_coupon_input( $coupon, $input );

	$id = $coupon->save();
	if ( ! $id ) {
		return aafm_generic_error();
	}

	$saved = aafm_wc_get_coupon_object( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_coupon( $saved );
}

// =============================================================================
// wc-update-coupon
// =============================================================================

/**
 * Args builder for aafm/wc-update-coupon.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_coupon(): array {
	$write_props              = aafm_wc_coupon_write_properties();
	$write_props['coupon_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);

	return array(
		'label'               => __( 'Update WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce coupon by id. Only the fields you include are changed; everything else stays as-is. An empty request body (only coupon_id) is a no-op success. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'coupon_id' ),
			'properties'           => $write_props,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_coupon_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_coupon',
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
 * Execute aafm/wc-update-coupon.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_coupon( array $input ) {
	$id     = absint( $input['coupon_id'] ?? 0 );
	$coupon = aafm_wc_get_coupon_object( $id );
	if ( null === $coupon ) {
		return aafm_generic_error();
	}

	// Strip the routing key before diffing so an id-only PATCH is a genuine no-op.
	$fields = $input;
	unset( $fields['coupon_id'] );
	aafm_wc_apply_coupon_input( $coupon, $fields );
	$saved_id = (int) $coupon->save();
	if ( $saved_id < 1 ) {
		return aafm_generic_error();
	}

	$saved = aafm_wc_get_coupon_object( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_coupon( $saved );
}

// =============================================================================
// wc-delete-coupon
// =============================================================================

/**
 * Args builder for aafm/wc-delete-coupon.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_coupon(): array {
	return array(
		'label'               => __( 'Delete WooCommerce coupon', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a WooCommerce coupon by id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'coupon_id' ),
			'properties'           => array(
				'coupon_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_coupon',
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
 * Execute aafm/wc-delete-coupon.
 *
 * Uses $coupon->delete(true) — WooCommerce's own object delete — to permanently remove the
 * coupon. Surfaces the return value and converts false to WP_Error so we never lie success.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_coupon( array $input ) {
	$coupon = aafm_wc_get_coupon_object( absint( $input['coupon_id'] ?? 0 ) );
	if ( null === $coupon ) {
		return aafm_generic_error();
	}

	$ok = $coupon->delete( true );
	if ( false === $ok || is_wp_error( $ok ) ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}

// =============================================================================
// SHIPPING ZONES — helpers
// =============================================================================

/**
 * Resolve a zone_id to a WC_Shipping_Zone, or null when unavailable or unknown.
 *
 * @param int $zone_id Zone id (0 = Rest of World).
 * @return \WC_Shipping_Zone|null
 */
function aafm_wc_get_shipping_zone_object( int $zone_id ): ?\WC_Shipping_Zone {
	if ( $zone_id < 0 || ! class_exists( 'WC_Shipping_Zone' ) ) {
		return null;
	}
	$zone = new \WC_Shipping_Zone( $zone_id );
	// A zone is valid when its data() id matches what we requested, OR for zone 0 (Rest of World)
	// which always exists in WooCommerce. We check via get_data() to avoid an extra store read.
	$data = $zone->get_data();
	return ( (int) ( $data['id'] ?? -1 ) === $zone_id ) ? $zone : null;
}

/**
 * Build the lean zone shape for list rows: id, name, order only.
 *
 * @param \WC_Shipping_Zone $zone Zone object.
 * @return array<string,mixed>
 */
function aafm_redact_wc_shipping_zone( \WC_Shipping_Zone $zone ): array {
	$data = $zone->get_data();
	return array(
		'id'         => (int) ( $data['id'] ?? $zone->get_id() ),
		'zone_name'  => (string) ( $data['zone_name'] ?? '' ),
		'zone_order' => (int) ( $data['zone_order'] ?? 0 ),
	);
}

/**
 * Build the full zone shape: id, name, order, and zone_locations.
 *
 * @param \WC_Shipping_Zone $zone Zone object.
 * @return array<string,mixed>
 */
function aafm_rich_wc_shipping_zone( \WC_Shipping_Zone $zone ): array {
	$data = $zone->get_data();
	return array(
		'id'             => (int) ( $data['id'] ?? $zone->get_id() ),
		'zone_name'      => (string) ( $data['zone_name'] ?? '' ),
		'zone_order'     => (int) ( $data['zone_order'] ?? 0 ),
		'zone_locations' => (array) ( $data['zone_locations'] ?? array() ),
	);
}

/**
 * Shared output properties for the zone full shape.
 *
 * @return array<string,mixed>
 */
function aafm_wc_shipping_zone_output_properties(): array {
	return array(
		'id'             => array( 'type' => 'integer' ),
		'zone_name'      => array( 'type' => 'string' ),
		'zone_order'     => array( 'type' => 'integer' ),
		'zone_locations' => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
	);
}

/**
 * Shared write-input properties for shipping zone create and update.
 *
 * @return array<string,mixed>
 */
function aafm_wc_shipping_zone_write_properties(): array {
	return array(
		'zone_name'  => array( 'type' => 'string' ),
		'zone_order' => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
	);
}

/**
 * Apply validated write-input fields to a WC_Shipping_Zone instance.
 *
 * Only keys present in $input are applied; absent keys leave the zone unchanged.
 *
 * @param \WC_Shipping_Zone   $zone  Zone object to mutate.
 * @param array<string,mixed> $input Validated input fields (zone_id already extracted).
 * @return void
 */
function aafm_wc_apply_shipping_zone_input( \WC_Shipping_Zone $zone, array $input ): void {
	if ( array_key_exists( 'zone_name', $input ) ) {
		$zone->set_zone_name( sanitize_text_field( (string) $input['zone_name'] ) );
	}
	if ( array_key_exists( 'zone_order', $input ) ) {
		$zone->set_zone_order( absint( $input['zone_order'] ) );
	}
}

// =============================================================================
// wc-list-shipping-zones
// =============================================================================

/**
 * Args builder for aafm/wc-list-shipping-zones.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_shipping_zones(): array {
	return array(
		'label'               => __( 'List WooCommerce shipping zones', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all WooCommerce shipping zones with their id, name, and order. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'zones' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'         => array( 'type' => 'integer' ),
							'zone_name'  => array( 'type' => 'string' ),
							'zone_order' => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_shipping_zones',
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
 * Execute aafm/wc-list-shipping-zones.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_shipping_zones( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return aafm_generic_error();
	}

	$zones_raw = \WC_Shipping_Zones::get_zones();
	$rows      = array();
	foreach ( $zones_raw as $zone_data ) {
		$zone_obj = $zone_data['zone_object'] ?? null;
		if ( $zone_obj instanceof \WC_Shipping_Zone ) {
			$rows[] = aafm_redact_wc_shipping_zone( $zone_obj );
		}
	}

	return array(
		'zones' => $rows,
		'total' => count( $rows ),
	);
}

// =============================================================================
// wc-get-shipping-zone
// =============================================================================

/**
 * Args builder for aafm/wc-get-shipping-zone.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_shipping_zone(): array {
	return array(
		'label'               => __( 'Get WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce shipping zone by id, including its name, order, and zone locations. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id' ),
			'properties'           => array(
				'zone_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_shipping_zone_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_shipping_zone',
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
 * Execute aafm/wc-get-shipping-zone.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_shipping_zone( array $input ) {
	$zone_id = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$zone    = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_shipping_zone( $zone );
}

// =============================================================================
// wc-create-shipping-zone
// =============================================================================

/**
 * Args builder for aafm/wc-create-shipping-zone.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_shipping_zone(): array {
	return array(
		'label'               => __( 'Create WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce shipping zone from a name and optional order. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_name' ),
			'properties'           => aafm_wc_shipping_zone_write_properties(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_shipping_zone_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_shipping_zone',
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
 * Execute aafm/wc-create-shipping-zone.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_shipping_zone( array $input ) {
	if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
		return aafm_generic_error();
	}

	$zone = new \WC_Shipping_Zone();
	aafm_wc_apply_shipping_zone_input( $zone, $input );

	$id = (int) $zone->save();
	if ( $id < 1 ) {
		return aafm_generic_error();
	}

	$saved = aafm_wc_get_shipping_zone_object( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_shipping_zone( $saved );
}

// =============================================================================
// wc-update-shipping-zone
// =============================================================================

/**
 * Args builder for aafm/wc-update-shipping-zone.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_shipping_zone(): array {
	$write_props            = aafm_wc_shipping_zone_write_properties();
	$write_props['zone_id'] = array(
		'type'    => 'integer',
		'minimum' => 0,
	);

	return array(
		'label'               => __( 'Update WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce shipping zone by id, changing only the fields you send. An empty request body is a no-op success. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id' ),
			'properties'           => $write_props,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_shipping_zone_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_shipping_zone',
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
 * Execute aafm/wc-update-shipping-zone.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_shipping_zone( array $input ) {
	$zone_id = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$zone    = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}

	$fields = $input;
	unset( $fields['zone_id'] );
	aafm_wc_apply_shipping_zone_input( $zone, $fields );
	$saved_id = (int) $zone->save();
	if ( $saved_id < 1 ) {
		return aafm_generic_error();
	}

	$saved = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_shipping_zone( $saved );
}

// =============================================================================
// wc-delete-shipping-zone
// =============================================================================

/**
 * Args builder for aafm/wc-delete-shipping-zone.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_shipping_zone(): array {
	return array(
		'label'               => __( 'Delete WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently deletes a WooCommerce shipping zone by id. The Rest of World zone (id 0) cannot be deleted. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id' ),
			'properties'           => array(
				'zone_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_shipping_zone',
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
 * Execute aafm/wc-delete-shipping-zone.
 *
 * Zone id 0 (Rest of World) is immutable in WooCommerce and is rejected with an error.
 * All other zones are permanently removed via $zone->delete().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_shipping_zone( array $input ) {
	$zone_id = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;

	// The Rest of World zone (id 0) cannot be deleted in WooCommerce.
	if ( 0 === $zone_id ) {
		return aafm_generic_error();
	}

	$zone = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}

	$ok = $zone->delete();
	if ( false === $ok || is_wp_error( $ok ) ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}

// =============================================================================
// SHIPPING METHODS — helpers
// =============================================================================

/**
 * Resolve a zone_id + instance_id to a WC_Shipping_Method from the zone, or null.
 *
 * @param int $zone_id     Zone id.
 * @param int $instance_id Instance id.
 * @return \WC_Shipping_Method|null
 */
function aafm_wc_get_shipping_method_object( int $zone_id, int $instance_id ): ?\WC_Shipping_Method {
	if ( $zone_id < 0 || $instance_id < 1 ) {
		return null;
	}
	$zone = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return null;
	}
	$methods = $zone->get_shipping_methods();
	return $methods[ $instance_id ] ?? null;
}

/**
 * Build the lean + full method shape (list and get share the same shape).
 *
 * @param \WC_Shipping_Method $method Method object.
 * @return array<string,mixed>
 */
function aafm_rich_wc_shipping_method( \WC_Shipping_Method $method ): array {
	return array(
		'instance_id'  => (int) $method->instance_id,
		'id'           => (string) $method->id,
		'method_title' => (string) $method->method_title,
		'enabled'      => (string) $method->enabled,
		'settings'     => (array) $method->settings,
	);
}

/**
 * Shared output properties for a shipping method shape.
 *
 * @return array<string,mixed>
 */
function aafm_wc_shipping_method_output_properties(): array {
	return array(
		'instance_id'  => array( 'type' => 'integer' ),
		'id'           => array( 'type' => 'string' ),
		'method_title' => array( 'type' => 'string' ),
		'enabled'      => array(
			'type' => 'string',
			'enum' => array( 'yes', 'no' ),
		),
		'settings'     => array( 'type' => 'object' ),
	);
}

// =============================================================================
// wc-list-shipping-methods
// =============================================================================

/**
 * Args builder for aafm/wc-list-shipping-methods.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_shipping_methods(): array {
	return array(
		'label'               => __( 'List WooCommerce shipping methods', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists the shipping methods configured in a WooCommerce shipping zone by zone id. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id' ),
			'properties'           => array(
				'zone_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'methods' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => aafm_wc_shipping_method_output_properties(),
						'additionalProperties' => false,
					),
				),
				'total'   => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_shipping_methods',
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
 * Execute aafm/wc-list-shipping-methods.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_shipping_methods( array $input ) {
	$zone_id = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$zone    = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}

	$methods = $zone->get_shipping_methods();
	$rows    = array();
	foreach ( $methods as $method ) {
		if ( $method instanceof \WC_Shipping_Method ) {
			$rows[] = aafm_rich_wc_shipping_method( $method );
		}
	}

	return array(
		'methods' => $rows,
		'total'   => count( $rows ),
	);
}

// =============================================================================
// wc-get-shipping-method
// =============================================================================

/**
 * Args builder for aafm/wc-get-shipping-method.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_shipping_method(): array {
	return array(
		'label'               => __( 'Get WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one shipping method from a WooCommerce shipping zone by zone id and instance id. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id', 'instance_id' ),
			'properties'           => array(
				'zone_id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'instance_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_shipping_method_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_get_shipping_method',
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
 * Execute aafm/wc-get-shipping-method.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_shipping_method( array $input ) {
	$zone_id     = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$instance_id = isset( $input['instance_id'] ) ? (int) $input['instance_id'] : 0;
	$method      = aafm_wc_get_shipping_method_object( $zone_id, $instance_id );
	if ( null === $method ) {
		return aafm_generic_error();
	}
	return aafm_rich_wc_shipping_method( $method );
}

// =============================================================================
// wc-create-shipping-method
// =============================================================================

/**
 * Args builder for aafm/wc-create-shipping-method.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_shipping_method(): array {
	return array(
		'label'               => __( 'Create WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Adds a shipping method to a WooCommerce shipping zone. Provide the zone id and method type (e.g. flat_rate, free_shipping, local_pickup). Returns the new method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id', 'method_type' ),
			'properties'           => array(
				'zone_id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'method_type' => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_shipping_method_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_create_shipping_method',
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
 * Execute aafm/wc-create-shipping-method.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_shipping_method( array $input ) {
	$zone_id     = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$method_type = sanitize_text_field( (string) ( $input['method_type'] ?? '' ) );

	$zone = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}

	$instance_id = (int) $zone->add_shipping_method( $method_type );
	if ( $instance_id < 1 ) {
		return aafm_generic_error();
	}

	$method = aafm_wc_get_shipping_method_object( $zone_id, $instance_id );
	if ( null === $method ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_shipping_method( $method );
}

// =============================================================================
// wc-update-shipping-method
// =============================================================================

/**
 * Args builder for aafm/wc-update-shipping-method.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_shipping_method(): array {
	return array(
		'label'               => __( 'Update WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a shipping method in a WooCommerce shipping zone by zone id and instance id, changing only the fields you send. Returns the updated method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id', 'instance_id' ),
			'properties'           => array(
				'zone_id'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'instance_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'method_title' => array( 'type' => 'string' ),
				'enabled'      => array(
					'type' => 'string',
					'enum' => array( 'yes', 'no' ),
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_wc_shipping_method_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_wc_update_shipping_method',
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
 * Execute aafm/wc-update-shipping-method.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_shipping_method( array $input ) {
	$zone_id     = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$instance_id = isset( $input['instance_id'] ) ? (int) $input['instance_id'] : 0;

	// Verify the zone exists and the method exists within it.
	$zone = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}
	$method = aafm_wc_get_shipping_method_object( $zone_id, $instance_id );
	if ( null === $method ) {
		return aafm_generic_error();
	}

	// Apply only the supplied optional fields via the method's own property setters.
	if ( array_key_exists( 'method_title', $input ) ) {
		$method->method_title = sanitize_text_field( (string) $input['method_title'] );
	}
	if ( array_key_exists( 'enabled', $input ) ) {
		$method->enabled = in_array( $input['enabled'], array( 'yes', 'no' ), true ) ? $input['enabled'] : 'yes';
	}

	// Persist via the method's own save() — writes the WC instance option row in production;
	// delegates to WcShippingStubStore::update_method() in tests via the stub class.
	$ok = $method->save();
	if ( false === $ok || is_wp_error( $ok ) ) {
		return aafm_generic_error();
	}

	$updated = aafm_wc_get_shipping_method_object( $zone_id, $instance_id );
	if ( null === $updated ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_shipping_method( $updated );
}

// =============================================================================
// wc-delete-shipping-method
// =============================================================================

/**
 * Args builder for aafm/wc-delete-shipping-method.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_shipping_method(): array {
	return array(
		'label'               => __( 'Delete WooCommerce shipping method', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently removes a shipping method from a WooCommerce shipping zone by zone id and instance id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'zone_id', 'instance_id' ),
			'properties'           => array(
				'zone_id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'instance_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_shipping_method',
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
 * Execute aafm/wc-delete-shipping-method.
 *
 * Uses $zone->delete_shipping_method($instance_id) — WooCommerce's own method removal.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_shipping_method( array $input ) {
	$zone_id     = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
	$instance_id = isset( $input['instance_id'] ) ? (int) $input['instance_id'] : 0;

	$zone = aafm_wc_get_shipping_zone_object( $zone_id );
	if ( null === $zone ) {
		return aafm_generic_error();
	}

	// Verify the method exists before attempting deletion.
	$existing = aafm_wc_get_shipping_method_object( $zone_id, $instance_id );
	if ( null === $existing ) {
		return aafm_generic_error();
	}

	$ok = $zone->delete_shipping_method( $instance_id );
	if ( false === $ok || is_wp_error( $ok ) ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}
