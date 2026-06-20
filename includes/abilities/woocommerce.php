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

	// Tax rates (W4-WC6).
	$registry['aafm/wc-list-tax-rates']  = array(
		'label'        => __( 'List WooCommerce tax rates', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists all WooCommerce tax rates across every tax class, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_tax_rates',
	);
	$registry['aafm/wc-get-tax-rate']    = array(
		'label'        => __( 'Get WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce tax rate by id, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_tax_rate',
	);
	$registry['aafm/wc-create-tax-rate'] = array(
		'label'        => __( 'Create WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce tax rate. Required fields: rate (decimal string). Optional: country, state, name, priority, compound, shipping, order, class slug. Returns the full rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_tax_rate',
	);
	$registry['aafm/wc-update-tax-rate'] = array(
		'label'        => __( 'Update WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce tax rate by id, changing only the fields you send. An empty body (only id) is a no-op success. Returns the updated rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_tax_rate',
	);
	$registry['aafm/wc-delete-tax-rate'] = array(
		'label'        => __( 'Delete WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently removes a WooCommerce tax rate by id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_tax_rate',
	);

	// Tax classes (W4-WC6).
	$registry['aafm/wc-list-tax-classes'] = array(
		'label'        => __( 'List WooCommerce tax classes', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists all WooCommerce tax classes including the Standard class, returning name and slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_tax_classes',
	);
	$registry['aafm/wc-get-tax-class']    = array(
		'label'        => __( 'Get WooCommerce tax class', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce tax class by slug, returning name and slug. Use slug "standard" for the built-in Standard class. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_tax_class',
	);
	$registry['aafm/wc-create-tax-class'] = array(
		'label'        => __( 'Create WooCommerce tax class', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a WooCommerce tax class from a name, with an optional slug. Returns the new class shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_create_tax_class',
	);
	$registry['aafm/wc-delete-tax-class'] = array(
		'label'        => __( 'Delete WooCommerce tax class', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently removes a WooCommerce tax class by slug. This cannot be undone. The Standard class cannot be deleted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_delete_tax_class',
	);

	// Reports, counts, and payment gateways (sub-slice W4-WC7).
	$registry['aafm/wc-get-sales-report']       = array(
		'label'        => __( 'Get WooCommerce sales report', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Returns a sales summary for a date range: total sales, order count, net sales, and average order value. Defaults to the current calendar month. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_sales_report',
	);
	$registry['aafm/wc-get-top-sellers-report'] = array(
		'label'        => __( 'Get WooCommerce top sellers report', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Returns the best-selling products for a period (week, month, or year) ordered by quantity sold. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_top_sellers_report',
	);
	$registry['aafm/wc-count-orders']           = array(
		'label'        => __( 'Count WooCommerce orders', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Returns order counts broken down by WooCommerce status (pending, processing, on-hold, completed, cancelled, refunded, failed) plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_count_orders',
	);
	$registry['aafm/wc-count-products']         = array(
		'label'        => __( 'Count WooCommerce products', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Returns product counts broken down by post status (publish, draft, private, pending, trash) plus a total of active (non-trash) products. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_count_products',
	);
	$registry['aafm/wc-count-customers']        = array(
		'label'        => __( 'Count WooCommerce customers', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Returns the count of registered users on the site. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_count_customers',
	);
	$registry['aafm/wc-list-payment-gateways']  = array(
		'label'        => __( 'List WooCommerce payment gateways', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists all registered WooCommerce payment gateways with their id, title, and enabled state. Secret or credential settings are never returned. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_list_payment_gateways',
	);
	$registry['aafm/wc-get-payment-gateway']    = array(
		'label'        => __( 'Get WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one WooCommerce payment gateway by id, including its title, description, enabled state, order, and non-secret settings. Credential and key fields are always redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_get_payment_gateway',
	);
	$registry['aafm/wc-count-coupons']          = array(
		'label'        => __( 'Count WooCommerce coupons', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Returns coupon counts broken down by post status (publish, draft, private, pending, trash) plus a total of active coupons. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_count_coupons',
	);
	$registry['aafm/wc-update-payment-gateway'] = array(
		'label'        => __( 'Update WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a WooCommerce payment gateway by id, changing only the fields you send: enabled state, title, description, or display order. Returns the updated gateway shape with secrets redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'woocommerce',
		'args_builder' => 'aafm_args_wc_update_payment_gateway',
	);

	return $registry;
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
		// Shipping plugins store carrier API keys / account creds / license keys in settings.
		// Run them through the recursive deny-by-default redactor before returning.
		'settings'     => aafm_wc_redact_settings_deep( (array) $method->settings ),
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

// =============================================================================
// Tax rate helpers
// =============================================================================

/**
 * Normalise a raw DB row from woocommerce_tax_rates into the canonical rate shape.
 *
 * @param array<string,mixed> $row Raw DB row.
 * @return array<string,mixed>
 */
function aafm_wc_tax_rate_shape( array $row ): array {
	return array(
		'id'       => (int) ( $row['id'] ?? $row['tax_rate_id'] ?? 0 ),
		'country'  => (string) ( $row['country'] ?? $row['tax_rate_country'] ?? '' ),
		'state'    => (string) ( $row['state'] ?? $row['tax_rate_state'] ?? '' ),
		'rate'     => (string) ( $row['rate'] ?? $row['tax_rate_rate'] ?? '0.0000' ),
		'name'     => (string) ( $row['name'] ?? $row['tax_rate_name'] ?? '' ),
		'priority' => (int) ( $row['priority'] ?? $row['tax_rate_priority'] ?? 1 ),
		'compound' => (bool) ( $row['compound'] ?? $row['tax_rate_compound'] ?? false ),
		'shipping' => (bool) ( $row['shipping'] ?? $row['tax_rate_shipping'] ?? true ),
		'order'    => (int) ( $row['order'] ?? $row['tax_rate_order'] ?? 0 ),
		'class'    => (string) ( $row['class'] ?? $row['tax_rate_class'] ?? '' ),
	);
}

/**
 * Return all rows from the woocommerce_tax_rates table, normalised.
 *
 * Uses a direct DB query, the same strategy as the WooCommerce REST API v3 /taxes endpoint.
 *
 * @return array<int,array<string,mixed>>
 */
function aafm_wc_get_all_tax_rates(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT tax_rate_id AS id, tax_rate_country AS country, tax_rate_state AS state,
		        tax_rate_rate AS rate, tax_rate_name AS name, tax_rate_priority AS priority,
		        tax_rate_compound AS compound, tax_rate_shipping AS shipping,
		        tax_rate_order AS `order`, tax_rate_class AS class
		 FROM {$wpdb->prefix}woocommerce_tax_rates
		 ORDER BY tax_rate_order, tax_rate_id",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return array();
	}
	return array_values( array_map( 'aafm_wc_tax_rate_shape', $rows ) );
}

/**
 * Return one normalised rate row by id, or null when not found.
 *
 * @param int $rate_id Rate id.
 * @return array<string,mixed>|null
 */
function aafm_wc_get_tax_rate_by_id( int $rate_id ): ?array {
	global $wpdb;
	$table = $wpdb->prefix . 'woocommerce_tax_rates';
	$sql   = "SELECT tax_rate_id AS id, tax_rate_country AS country, tax_rate_state AS state,
		tax_rate_rate AS rate, tax_rate_name AS name, tax_rate_priority AS priority,
		tax_rate_compound AS compound, tax_rate_shipping AS shipping,
		tax_rate_order AS `order`, tax_rate_class AS class
		FROM `{$table}` WHERE tax_rate_id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant; all values are bound.
	$row   = $wpdb->get_row( $wpdb->prepare( $sql, $rate_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- direct read of WC's own woocommerce_tax_rates table; no caching layer for admin-driven reads.
	if ( ! is_array( $row ) ) {
		return null;
	}
	return aafm_wc_tax_rate_shape( $row );
}

// =============================================================================
// wc-list-tax-rates
// =============================================================================

/**
 * Args builder for aafm/wc-list-tax-rates.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_tax_rates(): array {
	return array(
		'label'               => __( 'List WooCommerce tax rates', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all WooCommerce tax rates across every tax class, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'rates' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_tax_rates',
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
 * Execute aafm/wc-list-tax-rates.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_tax_rates( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$rates = aafm_wc_get_all_tax_rates();
	return array(
		'rates' => $rates,
		'total' => count( $rates ),
	);
}

// =============================================================================
// wc-get-tax-rate
// =============================================================================

/**
 * Args builder for aafm/wc-get-tax-rate.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_tax_rate(): array {
	return array(
		'label'               => __( 'Get WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce tax rate by id, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate_id' ),
			'properties'           => array(
				'rate_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'country'  => array( 'type' => 'string' ),
				'state'    => array( 'type' => 'string' ),
				'rate'     => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'priority' => array( 'type' => 'integer' ),
				'compound' => array( 'type' => 'boolean' ),
				'shipping' => array( 'type' => 'boolean' ),
				'order'    => array( 'type' => 'integer' ),
				'class'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_tax_rate',
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
 * Execute aafm/wc-get-tax-rate.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_tax_rate( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$rate_id = isset( $input['rate_id'] ) ? (int) $input['rate_id'] : 0;
	$rate    = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $rate ) {
		return new \WP_Error( 'aafm_not_found', __( 'Tax rate not found.', 'agent-abilities-for-mcp' ) );
	}
	return $rate;
}

// =============================================================================
// wc-create-tax-rate
// =============================================================================

/**
 * Args builder for aafm/wc-create-tax-rate.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_tax_rate(): array {
	return array(
		'label'               => __( 'Create WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce tax rate. Required fields: rate (decimal string). Optional: country, state, name, priority, compound, shipping, order, class slug. Returns the full rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate' ),
			'properties'           => array(
				'rate'     => array( 'type' => 'string' ),
				'country'  => array( 'type' => 'string' ),
				'state'    => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'priority' => array( 'type' => 'integer' ),
				'compound' => array( 'type' => 'boolean' ),
				'shipping' => array( 'type' => 'boolean' ),
				'order'    => array( 'type' => 'integer' ),
				'class'    => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'country'  => array( 'type' => 'string' ),
				'state'    => array( 'type' => 'string' ),
				'rate'     => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'priority' => array( 'type' => 'integer' ),
				'compound' => array( 'type' => 'boolean' ),
				'shipping' => array( 'type' => 'boolean' ),
				'order'    => array( 'type' => 'integer' ),
				'class'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_create_tax_rate',
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
 * Execute aafm/wc-create-tax-rate.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_tax_rate( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	global $wpdb;
	$table = $wpdb->prefix . 'woocommerce_tax_rates';
	$data  = array(
		'tax_rate_country'  => isset( $input['country'] ) ? sanitize_text_field( (string) $input['country'] ) : '',
		'tax_rate_state'    => isset( $input['state'] ) ? sanitize_text_field( (string) $input['state'] ) : '',
		'tax_rate_rate'     => sanitize_text_field( (string) $input['rate'] ),
		'tax_rate_name'     => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
		'tax_rate_priority' => isset( $input['priority'] ) ? absint( $input['priority'] ) : 1,
		'tax_rate_compound' => isset( $input['compound'] ) ? ( (bool) $input['compound'] ? 1 : 0 ) : 0,
		'tax_rate_shipping' => isset( $input['shipping'] ) ? ( (bool) $input['shipping'] ? 1 : 0 ) : 1,
		'tax_rate_order'    => isset( $input['order'] ) ? absint( $input['order'] ) : 0,
		'tax_rate_class'    => isset( $input['class'] ) ? sanitize_title( (string) $input['class'] ) : '',
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ) );
	if ( false === $ok ) {
		return aafm_generic_error();
	}

	$new_id = (int) $wpdb->insert_id;
	if ( $new_id <= 0 ) {
		return aafm_generic_error();
	}

	$rate = aafm_wc_get_tax_rate_by_id( $new_id );
	if ( null === $rate ) {
		return aafm_generic_error();
	}
	return $rate;
}

// =============================================================================
// wc-update-tax-rate
// =============================================================================

/**
 * Args builder for aafm/wc-update-tax-rate.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_tax_rate(): array {
	return array(
		'label'               => __( 'Update WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce tax rate by id, changing only the fields you send. An empty body (only id) is a no-op success. Returns the updated rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate_id' ),
			'properties'           => array(
				'rate_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'rate'     => array( 'type' => 'string' ),
				'country'  => array( 'type' => 'string' ),
				'state'    => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'priority' => array( 'type' => 'integer' ),
				'compound' => array( 'type' => 'boolean' ),
				'shipping' => array( 'type' => 'boolean' ),
				'order'    => array( 'type' => 'integer' ),
				'class'    => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'country'  => array( 'type' => 'string' ),
				'state'    => array( 'type' => 'string' ),
				'rate'     => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'priority' => array( 'type' => 'integer' ),
				'compound' => array( 'type' => 'boolean' ),
				'shipping' => array( 'type' => 'boolean' ),
				'order'    => array( 'type' => 'integer' ),
				'class'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_update_tax_rate',
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
 * Execute aafm/wc-update-tax-rate.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_tax_rate( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$rate_id  = isset( $input['rate_id'] ) ? (int) $input['rate_id'] : 0;
	$existing = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $existing ) {
		return new \WP_Error( 'aafm_not_found', __( 'Tax rate not found.', 'agent-abilities-for-mcp' ) );
	}

	global $wpdb;
	$table  = $wpdb->prefix . 'woocommerce_tax_rates';
	$fields = array();
	$format = array();

	if ( array_key_exists( 'rate', $input ) ) {
		$fields['tax_rate_rate'] = sanitize_text_field( (string) $input['rate'] );
		$format[]                = '%s';
	}
	if ( array_key_exists( 'country', $input ) ) {
		$fields['tax_rate_country'] = sanitize_text_field( (string) $input['country'] );
		$format[]                   = '%s';
	}
	if ( array_key_exists( 'state', $input ) ) {
		$fields['tax_rate_state'] = sanitize_text_field( (string) $input['state'] );
		$format[]                 = '%s';
	}
	if ( array_key_exists( 'name', $input ) ) {
		$fields['tax_rate_name'] = sanitize_text_field( (string) $input['name'] );
		$format[]                = '%s';
	}
	if ( array_key_exists( 'priority', $input ) ) {
		$fields['tax_rate_priority'] = absint( $input['priority'] );
		$format[]                    = '%d';
	}
	if ( array_key_exists( 'compound', $input ) ) {
		$fields['tax_rate_compound'] = (bool) $input['compound'] ? 1 : 0;
		$format[]                    = '%d';
	}
	if ( array_key_exists( 'shipping', $input ) ) {
		$fields['tax_rate_shipping'] = (bool) $input['shipping'] ? 1 : 0;
		$format[]                    = '%d';
	}
	if ( array_key_exists( 'order', $input ) ) {
		$fields['tax_rate_order'] = absint( $input['order'] );
		$format[]                 = '%d';
	}
	if ( array_key_exists( 'class', $input ) ) {
		$fields['tax_rate_class'] = sanitize_title( (string) $input['class'] );
		$format[]                 = '%s';
	}

	if ( ! empty( $fields ) ) {
		$ok = $wpdb->update( $table, $fields, array( 'tax_rate_id' => $rate_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct write to WC's own woocommerce_tax_rates table; no caching layer for admin-driven reads.
		if ( false === $ok ) {
			return aafm_generic_error();
		}
	}

	$updated = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $updated ) {
		return aafm_generic_error();
	}
	return $updated;
}

// =============================================================================
// wc-delete-tax-rate
// =============================================================================

/**
 * Args builder for aafm/wc-delete-tax-rate.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_tax_rate(): array {
	return array(
		'label'               => __( 'Delete WooCommerce tax rate', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently removes a WooCommerce tax rate by id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate_id' ),
			'properties'           => array(
				'rate_id' => array(
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
		'execute_callback'    => 'aafm_exec_wc_delete_tax_rate',
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
 * Execute aafm/wc-delete-tax-rate.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_tax_rate( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$rate_id  = isset( $input['rate_id'] ) ? (int) $input['rate_id'] : 0;
	$existing = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $existing ) {
		return new \WP_Error( 'aafm_not_found', __( 'Tax rate not found.', 'agent-abilities-for-mcp' ) );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'woocommerce_tax_rates';
	$ok    = $wpdb->delete( $table, array( 'tax_rate_id' => $rate_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct write to WC's own woocommerce_tax_rates table; no caching layer for admin-driven reads.
	if ( false === $ok ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}

// =============================================================================
// Tax class helpers
// =============================================================================

/**
 * Build the full tax class list including the implicit Standard class.
 *
 * @return array<int,array<string,string>>
 */
function aafm_wc_build_tax_class_list(): array {
	$out = array(
		array(
			'name' => 'Standard',
			'slug' => 'standard',
		),
	);

	if ( ! class_exists( 'WC_Tax' ) ) {
		return $out;
	}

	$slugs = \WC_Tax::get_tax_classes();

	foreach ( $slugs as $slug ) {
		$out[] = array(
			'name' => ucwords( str_replace( '-', ' ', (string) $slug ) ),
			'slug' => (string) $slug,
		);
	}

	return $out;
}

// =============================================================================
// wc-list-tax-classes
// =============================================================================

/**
 * Args builder for aafm/wc-list-tax-classes.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_tax_classes(): array {
	return array(
		'label'               => __( 'List WooCommerce tax classes', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all WooCommerce tax classes including the Standard class, returning name and slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'classes' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total'   => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_tax_classes',
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
 * Execute aafm/wc-list-tax-classes.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_tax_classes( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$classes = aafm_wc_build_tax_class_list();
	return array(
		'classes' => $classes,
		'total'   => count( $classes ),
	);
}

// =============================================================================
// wc-get-tax-class
// =============================================================================

/**
 * Args builder for aafm/wc-get-tax-class.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_tax_class(): array {
	return array(
		'label'               => __( 'Get WooCommerce tax class', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce tax class by slug, returning name and slug. Use slug "standard" for the built-in Standard class. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'slug' ),
			'properties'           => array(
				'slug' => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'name' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_tax_class',
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
 * Execute aafm/wc-get-tax-class.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_tax_class( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$slug    = sanitize_title( (string) ( $input['slug'] ?? '' ) );
	$classes = aafm_wc_build_tax_class_list();
	foreach ( $classes as $class ) {
		if ( $class['slug'] === $slug ) {
			return $class;
		}
	}
	return new \WP_Error( 'aafm_not_found', __( 'Tax class not found.', 'agent-abilities-for-mcp' ) );
}

// =============================================================================
// wc-create-tax-class
// =============================================================================

/**
 * Args builder for aafm/wc-create-tax-class.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_tax_class(): array {
	return array(
		'label'               => __( 'Create WooCommerce tax class', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Creates a WooCommerce tax class from a name, with an optional slug. Returns the new class shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name' ),
			'properties'           => array(
				'name' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'name' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_create_tax_class',
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
 * Execute aafm/wc-create-tax-class.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_tax_class( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	if ( ! class_exists( 'WC_Tax' ) ) {
		return aafm_generic_error();
	}

	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';

	$result = \WC_Tax::create_tax_class( $name, $slug );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'name' => (string) ( $result['name'] ?? $name ),
		'slug' => (string) ( $result['slug'] ?? sanitize_title( $name ) ),
	);
}

// =============================================================================
// wc-delete-tax-class
// =============================================================================

/**
 * Args builder for aafm/wc-delete-tax-class.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_tax_class(): array {
	return array(
		'label'               => __( 'Delete WooCommerce tax class', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently removes a WooCommerce tax class by slug. This cannot be undone. The Standard class cannot be deleted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'slug' ),
			'properties'           => array(
				'slug' => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_delete_tax_class',
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
 * Execute aafm/wc-delete-tax-class.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_delete_tax_class( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	if ( ! class_exists( 'WC_Tax' ) ) {
		return aafm_generic_error();
	}

	$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
	if ( 'standard' === $slug || '' === $slug ) {
		return new \WP_Error( 'aafm_invalid', __( 'The Standard tax class cannot be deleted.', 'agent-abilities-for-mcp' ) );
	}

	$result = \WC_Tax::delete_tax_class_by( 'slug', $slug );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( false === $result ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}

// =============================================================================
// WC7 helpers — redaction + gateway shape
// =============================================================================

/**
 * Recursively strip secret/credential fields from an arbitrary settings array.
 *
 * Deny-by-default at every depth: any key whose name matches the secret pattern is dropped,
 * and the walk recurses into nested arrays so a credential hidden under a benign parent key
 * (or several levels down) can't slip through. The pattern covers the obvious credential
 * tokens (key, secret, token, password/pwd, api, private, auth, credential, signature/sign,
 * client_id) so a value stored under a slightly unconventional name is still caught.
 *
 * @param array<int|string,mixed> $settings Raw settings array (may be nested).
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_settings_deep( array $settings ): array {
	$secret_pattern = '/(?:key|secret|token|password|pwd|api|private|auth|credential|signature|sign|client[_-]?id)/i';
	$redacted       = array();
	foreach ( $settings as $key => $value ) {
		if ( preg_match( $secret_pattern, (string) $key ) ) {
			continue;
		}
		$redacted[ $key ] = is_array( $value ) ? aafm_wc_redact_settings_deep( $value ) : $value;
	}
	return $redacted;
}

/**
 * Redact secret/key/token/password fields from a gateway's settings array.
 *
 * Thin wrapper over the recursive deny-by-default redactor.
 *
 * @param array<string,mixed> $settings Raw gateway settings array.
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_gateway_settings( array $settings ): array {
	return aafm_wc_redact_settings_deep( $settings );
}

/**
 * Build the safe output shape for a payment gateway.
 *
 * Returns id, title, description, enabled (bool), order, and redacted settings.
 * Credential fields are stripped by aafm_wc_redact_gateway_settings().
 *
 * @param \WC_Payment_Gateway $gateway Payment gateway object.
 * @return array<string,mixed>
 */
function aafm_wc_gateway_shape( \WC_Payment_Gateway $gateway ): array {
	return array(
		'id'          => $gateway->id,
		'title'       => $gateway->title,
		'description' => $gateway->description,
		'enabled'     => 'yes' === $gateway->enabled,
		'order'       => (int) $gateway->order,
		'settings'    => aafm_wc_redact_gateway_settings( $gateway->settings ),
	);
}

// =============================================================================
// wc-get-sales-report
// =============================================================================

/**
 * Args builder for aafm/wc-get-sales-report.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_sales_report(): array {
	return array(
		'label'               => __( 'Get WooCommerce sales report', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Returns a sales summary for a date range: total sales, order count, net sales, and average order value. Defaults to the current calendar month. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'start_date' => array(
					'type'        => 'string',
					'description' => 'Start date in Y-m-d format. Defaults to the first day of the current month.',
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => 'End date in Y-m-d format. Defaults to today.',
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total_sales'   => array( 'type' => 'string' ),
				'order_count'   => array( 'type' => 'integer' ),
				'net_sales'     => array( 'type' => 'string' ),
				'average_sales' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_sales_report',
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
 * Execute aafm/wc-get-sales-report.
 *
 * Queries shop_order posts with completed/processing statuses in the given date window and
 * sums the _order_total post-meta value. Uses $wpdb->prepare() with positional placeholders
 * to stay PHPCS-clean.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_sales_report( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	global $wpdb;
	$start = sanitize_text_field( (string) ( $input['start_date'] ?? gmdate( 'Y-m-01' ) ) );
	$end   = sanitize_text_field( (string) ( $input['end_date'] ?? gmdate( 'Y-m-d' ) ) );

	$statuses     = array( 'wc-completed', 'wc-processing' );
	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT SUM( pm.meta_value + 0 ) AS total_sales, COUNT( DISTINCT p.ID ) AS order_count
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ( {$placeholders} )
			   AND pm.meta_key = '_order_total'
			   AND p.post_date >= %s
			   AND p.post_date <= %s",
			array_merge( $statuses, array( $start . ' 00:00:00', $end . ' 23:59:59' ) )
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$total_sales = round( (float) ( $row['total_sales'] ?? 0 ), 2 );
	$order_count = (int) ( $row['order_count'] ?? 0 );
	$avg         = $order_count > 0 ? round( $total_sales / $order_count, 2 ) : 0.0;

	return array(
		'total_sales'   => number_format( $total_sales, 2, '.', '' ),
		'order_count'   => $order_count,
		'net_sales'     => number_format( $total_sales, 2, '.', '' ),
		'average_sales' => number_format( $avg, 2, '.', '' ),
	);
}

// =============================================================================
// wc-get-top-sellers-report
// =============================================================================

/**
 * Args builder for aafm/wc-get-top-sellers-report.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_top_sellers_report(): array {
	return array(
		'label'               => __( 'Get WooCommerce top sellers report', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Returns the best-selling products for a period (week, month, or year) ordered by quantity sold. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'period' => array(
					'type'    => 'string',
					'enum'    => array( 'week', 'month', 'year' ),
					'default' => 'month',
				),
				'limit'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 10,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'product_id' => array( 'type' => 'integer' ),
							'name'       => array( 'type' => 'string' ),
							'quantity'   => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_top_sellers_report',
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
 * Execute aafm/wc-get-top-sellers-report.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_top_sellers_report( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	$period = sanitize_text_field( (string) ( $input['period'] ?? 'month' ) );
	$limit  = max( 1, min( 100, (int) ( $input['limit'] ?? 10 ) ) );

	$start = match ( $period ) {
		'week'  => gmdate( 'Y-m-d', strtotime( '-1 week' ) ),
		'year'  => gmdate( 'Y-01-01' ),
		default => gmdate( 'Y-m-01' ),
	};

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return aafm_generic_error();
	}

	// Product ids live in ORDER ITEM meta, not shop_order post meta, so aggregate quantities
	// from each order's line items via the WC CRUD layer (HPOS-aware). The previous postmeta
	// join keyed on _product_id, which never exists on the order post, so it returned nothing.
	$start_ts = (int) strtotime( $start . ' 00:00:00' );

	// Push the date window into the query (date_created lower bound) and page through results
	// instead of pulling every order with limit => -1, which can time out or exhaust memory on a
	// large order history. wc_get_orders() applies the window in storage (HPOS or legacy), so only
	// in-window orders are loaded.
	$page           = 1;
	$per_page       = 200;
	$qty_by_product = array();

	do {
		$result = wc_get_orders(
			array(
				'status'       => array( 'completed', 'processing' ),
				'date_created' => '>=' . $start_ts,
				'limit'        => $per_page,
				'paged'        => $page,
				'paginate'     => true,
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		$orders = is_object( $result ) && isset( $result->orders ) && is_array( $result->orders )
			? $result->orders
			: ( is_array( $result ) ? $result : array() );

		$page_count = count( $orders );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( is_array( $item ) ) {
					$product_id = (int) ( $item['product_id'] ?? 0 );
					$quantity   = (int) ( $item['quantity'] ?? 0 );
				} elseif ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
					$product_id = (int) $item->get_product_id();
					$quantity   = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
				} else {
					continue;
				}

				if ( $product_id < 1 ) {
					continue;
				}
				$qty_by_product[ $product_id ] = ( $qty_by_product[ $product_id ] ?? 0 ) + max( 0, $quantity );
			}
		}

		++$page;
		// Stop once a short (or empty) page is returned: that is the last page of the window.
	} while ( $page_count === $per_page );

	arsort( $qty_by_product );
	$qty_by_product = array_slice( $qty_by_product, 0, $limit, true );

	$items = array();
	foreach ( $qty_by_product as $product_id => $quantity ) {
		$product = aafm_wc_get_product( (int) $product_id );
		$items[] = array(
			'product_id' => (int) $product_id,
			'name'       => null !== $product ? (string) $product->get_name() : '',
			'quantity'   => (int) $quantity,
		);
	}

	return array( 'items' => $items );
}

// =============================================================================
// wc-count-orders
// =============================================================================

/**
 * Args builder for aafm/wc-count-orders.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_count_orders(): array {
	return array(
		'label'               => __( 'Count WooCommerce orders', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Returns order counts broken down by WooCommerce status (pending, processing, on-hold, completed, cancelled, refunded, failed) plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'pending'    => array( 'type' => 'integer' ),
				'processing' => array( 'type' => 'integer' ),
				'on_hold'    => array( 'type' => 'integer' ),
				'completed'  => array( 'type' => 'integer' ),
				'cancelled'  => array( 'type' => 'integer' ),
				'refunded'   => array( 'type' => 'integer' ),
				'failed'     => array( 'type' => 'integer' ),
				'total'      => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_count_orders',
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
 * Execute aafm/wc-count-orders.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_count_orders( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	$counts     = wp_count_posts( 'shop_order' );
	$pending    = (int) ( $counts->{'wc-pending'} ?? 0 );
	$processing = (int) ( $counts->{'wc-processing'} ?? 0 );
	$on_hold    = (int) ( $counts->{'wc-on-hold'} ?? 0 );
	$completed  = (int) ( $counts->{'wc-completed'} ?? 0 );
	$cancelled  = (int) ( $counts->{'wc-cancelled'} ?? 0 );
	$refunded   = (int) ( $counts->{'wc-refunded'} ?? 0 );
	$failed     = (int) ( $counts->{'wc-failed'} ?? 0 );
	return array(
		'pending'    => $pending,
		'processing' => $processing,
		'on_hold'    => $on_hold,
		'completed'  => $completed,
		'cancelled'  => $cancelled,
		'refunded'   => $refunded,
		'failed'     => $failed,
		'total'      => $pending + $processing + $on_hold + $completed + $cancelled + $refunded + $failed,
	);
}

// =============================================================================
// wc-count-products
// =============================================================================

/**
 * Args builder for aafm/wc-count-products.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_count_products(): array {
	return array(
		'label'               => __( 'Count WooCommerce products', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Returns product counts broken down by post status (publish, draft, private, pending, trash) plus a total of active (non-trash) products. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'publish' => array( 'type' => 'integer' ),
				'draft'   => array( 'type' => 'integer' ),
				'private' => array( 'type' => 'integer' ),
				'pending' => array( 'type' => 'integer' ),
				'trash'   => array( 'type' => 'integer' ),
				'total'   => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_count_products',
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
 * Execute aafm/wc-count-products.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_count_products( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	$counts  = wp_count_posts( 'product' );
	$publish = (int) ( $counts->publish ?? 0 );
	$draft   = (int) ( $counts->draft ?? 0 );
	$private = (int) ( $counts->private ?? 0 );
	$pending = (int) ( $counts->pending ?? 0 );
	$trash   = (int) ( $counts->trash ?? 0 );
	return array(
		'publish' => $publish,
		'draft'   => $draft,
		'private' => $private,
		'pending' => $pending,
		'trash'   => $trash,
		'total'   => $publish + $draft + $private + $pending,
	);
}

// =============================================================================
// wc-count-customers
// =============================================================================

/**
 * Args builder for aafm/wc-count-customers.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_count_customers(): array {
	return array(
		'label'               => __( 'Count WooCommerce customers', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Returns the count of registered users on the site. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'registered' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_count_customers',
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
 * Execute aafm/wc-count-customers.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_count_customers( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	$counts = count_users();
	return array(
		'registered' => (int) ( $counts['total_users'] ?? 0 ),
	);
}

// =============================================================================
// wc-list-payment-gateways
// =============================================================================

/**
 * Args builder for aafm/wc-list-payment-gateways.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_payment_gateways(): array {
	return array(
		'label'               => __( 'List WooCommerce payment gateways', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists all registered WooCommerce payment gateways with their id, title, and enabled state. Secret or credential settings are never returned. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'gateways' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'string' ),
							'title'   => array( 'type' => 'string' ),
							'enabled' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_payment_gateways',
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
 * Execute aafm/wc-list-payment-gateways.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_payment_gateways( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		return aafm_generic_error();
	}
	$gateways = \WC_Payment_Gateways::instance()->payment_gateways();
	$items    = array();
	foreach ( $gateways as $gateway ) {
		$items[] = array(
			'id'      => $gateway->id,
			'title'   => $gateway->title,
			'enabled' => 'yes' === $gateway->enabled,
		);
	}
	return array( 'gateways' => $items );
}

// =============================================================================
// wc-get-payment-gateway
// =============================================================================

/**
 * Args builder for aafm/wc-get-payment-gateway.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_payment_gateway(): array {
	return array(
		'label'               => __( 'Get WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one WooCommerce payment gateway by id, including its title, description, enabled state, order, and non-secret settings. Credential and key fields are always redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'gateway_id' ),
			'properties'           => array(
				'gateway_id' => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'order'       => array( 'type' => 'integer' ),
				'settings'    => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_payment_gateway',
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
 * Execute aafm/wc-get-payment-gateway.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_payment_gateway( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		return aafm_generic_error();
	}
	$gateway_id = sanitize_text_field( (string) ( $input['gateway_id'] ?? '' ) );
	$gateways   = \WC_Payment_Gateways::instance()->payment_gateways();
	if ( ! isset( $gateways[ $gateway_id ] ) ) {
		return new \WP_Error( 'aafm_not_found', __( 'Payment gateway not found.', 'agent-abilities-for-mcp' ) );
	}
	return aafm_wc_gateway_shape( $gateways[ $gateway_id ] );
}

// =============================================================================
// wc-count-coupons
// =============================================================================

/**
 * Args builder for aafm/wc-count-coupons.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_count_coupons(): array {
	return array(
		'label'               => __( 'Count WooCommerce coupons', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Returns coupon counts broken down by post status (publish, draft, private, pending, trash) plus a total of active coupons. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'publish' => array( 'type' => 'integer' ),
				'draft'   => array( 'type' => 'integer' ),
				'private' => array( 'type' => 'integer' ),
				'pending' => array( 'type' => 'integer' ),
				'trash'   => array( 'type' => 'integer' ),
				'total'   => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_count_coupons',
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
 * Execute aafm/wc-count-coupons.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_count_coupons( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	$counts  = wp_count_posts( 'shop_coupon' );
	$publish = (int) ( $counts->publish ?? 0 );
	$draft   = (int) ( $counts->draft ?? 0 );
	$private = (int) ( $counts->private ?? 0 );
	$pending = (int) ( $counts->pending ?? 0 );
	$trash   = (int) ( $counts->trash ?? 0 );
	return array(
		'publish' => $publish,
		'draft'   => $draft,
		'private' => $private,
		'pending' => $pending,
		'trash'   => $trash,
		'total'   => $publish + $draft + $private + $pending,
	);
}

// =============================================================================
// wc-update-payment-gateway
// =============================================================================

/**
 * Args builder for aafm/wc-update-payment-gateway.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_payment_gateway(): array {
	return array(
		'label'               => __( 'Update WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Updates a WooCommerce payment gateway by id, changing only the fields you send: enabled state, title, description, or display order. Returns the updated gateway shape with secrets redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'gateway_id' ),
			'properties'           => array(
				'gateway_id'  => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'order'       => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'order'       => array( 'type' => 'integer' ),
				'settings'    => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_update_payment_gateway',
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
 * Execute aafm/wc-update-payment-gateway.
 *
 * Updates only the fields provided: enabled, title, description, order. Audits deny when
 * the gateway id is unknown, success on a clean save. Secrets are redacted from the returned shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_payment_gateway( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		return aafm_generic_error();
	}
	$gateway_id = sanitize_text_field( (string) ( $input['gateway_id'] ?? '' ) );
	$gateways   = \WC_Payment_Gateways::instance()->payment_gateways();
	if ( ! isset( $gateways[ $gateway_id ] ) ) {
			return new \WP_Error( 'aafm_not_found', __( 'Payment gateway not found.', 'agent-abilities-for-mcp' ) );
	}
	$gateway = $gateways[ $gateway_id ];
	if ( isset( $input['enabled'] ) ) {
		$enabled_val      = $input['enabled'] ? 'yes' : 'no';
		$gateway->enabled = $enabled_val;
		$gateway->update_option( 'enabled', $enabled_val );
	}
	if ( isset( $input['title'] ) ) {
		$title_val      = sanitize_text_field( (string) $input['title'] );
		$gateway->title = $title_val;
		$gateway->update_option( 'title', $title_val );
	}
	if ( isset( $input['description'] ) ) {
		$desc_val             = sanitize_textarea_field( (string) $input['description'] );
		$gateway->description = $desc_val;
		$gateway->update_option( 'description', $desc_val );
	}
	if ( isset( $input['order'] ) ) {
		$gateway->order = (int) $input['order'];
	}
	$saved = $gateway->save();
	if ( false === $saved ) {
		return aafm_generic_error();
	}
	return aafm_wc_gateway_shape( $gateway );
}
