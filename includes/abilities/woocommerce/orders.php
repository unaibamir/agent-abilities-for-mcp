<?php
/**
 * WooCommerce integration abilities — order, order-note, and order-refund reads and writes (sub-slice W4-WC2).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_orders_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_orders_full_definitions' );

/**
 * Contribute the WooCommerce orders definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_orders_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_orders_registry_definitions() );
}

/**
 * Contribute the WooCommerce order definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_orders_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_orders_registry_definitions() );
}

/**
 * The WooCommerce order registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_orders_registry_definitions(): array {
	return array(
		// Orders (sub-slice W4-WC2) — list is lean (no PII), get returns full billing/shipping PII
		// under the Integrations security disclaimer. Both gate on the flat, object-independent
		// manage_woocommerce capability and fall through to that callback at discovery (no server.php
		// case). PII exposure in wc-get-order is intentional: the revised WC PII stance in spec 48-
		// mandates full billing/shipping on the single-order read, gated by manage_woocommerce and
		// audited, not stripped.
		'aafm/wc-list-orders'         => array(
			'label'        => __( 'List WooCommerce orders', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists WooCommerce orders with their id, number, status, total, currency, date, and customer id, plus a total count. List rows are lean — no billing or shipping details. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_orders',
		),

		'aafm/wc-get-order'           => array(
			'label'        => __( 'Get WooCommerce order', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one WooCommerce order by id: line items, totals, status, dates, customer note, and the full customer billing address (including email and phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_order',
		),

		// Order writes (sub-slice W4-WC2.2) — create, update, focused status-only update.
		'aafm/wc-create-order'        => array(
			'label'        => __( 'Create WooCommerce order', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a WooCommerce order from optional status, customer id, billing, shipping, and line items. Returns the full order shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_order',
		),

		'aafm/wc-update-order'        => array(
			'label'        => __( 'Update WooCommerce order', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a WooCommerce order by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_order',
		),

		'aafm/wc-update-order-status' => array(
			'label'        => __( 'Update WooCommerce order status', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets the status of a WooCommerce order by id. Accepts both the short form (e.g. "completed") and the wc-prefixed form (e.g. "wc-completed"). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_order_status',
		),

		// Order notes (sub-slice W4-WC2.3 Group B).
		'aafm/wc-list-order-notes'    => array(
			'label'        => __( 'List WooCommerce order notes', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists all notes on a WooCommerce order by order id. Returns each note\'s id, text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_order_notes',
		),

		'aafm/wc-create-order-note'   => array(
			'label'        => __( 'Create WooCommerce order note', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Adds a note to a WooCommerce order by order id. Optionally marks the note as customer-facing so it appears in the customer\'s account. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_order_note',
		),

		// Order refunds (sub-slice W4-WC2.3 Group C).
		'aafm/wc-list-order-refunds'  => array(
			'label'        => __( 'List WooCommerce order refunds', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists all refunds on a WooCommerce order by order id. Returns each refund\'s id, amount, reason, and date. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_order_refunds',
		),

		'aafm/wc-get-order-refund'    => array(
			'label'        => __( 'Get WooCommerce order refund', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads a single refund by refund id. Returns the refund amount, reason, and date. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_order_refund',
		),

		'aafm/wc-create-order-refund' => array(
			'label'        => __( 'Create WooCommerce order refund', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a refund on a WooCommerce order by order id. Accepts an amount, optional reason, and optional line-item breakdown. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_order_refund',
		),

	);
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
		'label'               => aafm_ability_label( 'aafm/wc-list-orders' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-orders' ),
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
					'enum'        => array( 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ),
					'description' => "Order status to filter by; 'any' (the default) returns all states. Uses the short form without the wc- prefix.",
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-get-order' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-order' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-create-order' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-order' ),
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
	// Recalculate line + cart totals so the order total reflects its items. Without this the order
	// total stays at 0.00 even when line_items were added (downstream refunds depend on it).
	$order->calculate_totals();
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
		'label'               => aafm_ability_label( 'aafm/wc-update-order' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-order' ),
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
		'label'               => aafm_ability_label( 'aafm/wc-update-order-status' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-order-status' ),
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
 * Order notes + refunds (sub-slice W4-WC2.3)
 *
 * Group B: wc-list-order-notes (R), wc-create-order-note (W)
 * Group C: wc-list-order-refunds (R), wc-get-order-refund (R),
 *          wc-create-order-refund (W)
 *
 * All gate on aafm_wc_perm() (manage_woocommerce). Every delete uses the
 * WooCommerce object's own ->delete() or wc_delete_order_note() — none is a
 * wp_delete_post/wp_delete_comment literal so the SecurityRegressionTest stays green.
 * --------------------------------------------------------------------------
 */

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
		// wc_get_order_notes() returns normalized objects whose id lives in ->id (not ->comment_ID).
		$id = isset( $note->id ) ? (int) $note->id : 0;
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
	// wc_get_order_notes() returns normalized objects: ->id and ->content (not the raw comment fields).
	$id            = isset( $note->id ) ? (int) $note->id : 0;
	$text          = isset( $note->content ) ? (string) $note->content : '';
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
		'label'               => aafm_ability_label( 'aafm/wc-list-order-notes' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-order-notes' ),
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
				'idempotent'  => true,
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

// aafm/wc-create-order-note (W).

/**
 * Args builder for aafm/wc-create-order-note.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_order_note(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-create-order-note' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-order-note' ),
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

	// Re-read the saved note so the response reflects WooCommerce's stored row (real date_created,
	// real added_by, normalized content) instead of fabricating a date and hardcoding
	// added_by_user (B2). Fall back to a minimal truthful shape only if the re-read fails.
	$saved = aafm_wc_get_order_note( $order_id, (int) $note_id );
	if ( $saved instanceof \stdClass || is_object( $saved ) ) {
		return aafm_wc_redact_note( $saved );
	}

	return array(
		'id'            => (int) $note_id,
		'note'          => $note_text,
		'added_by_user' => true,
		'customer_note' => $customer_note,
		'date_created'  => '',
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
		'label'               => aafm_ability_label( 'aafm/wc-list-order-refunds' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-order-refunds' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-get-order-refund' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-order-refund' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-create-order-refund' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-order-refund' ),
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
