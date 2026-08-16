<?php
/**
 * WooCommerce integration abilities - order, order-note, and order-refund reads and writes (sub-slice W4-WC2).
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
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_orders_registry_definitions(): array {
	return array(
		// Orders (sub-slice W4-WC2) - list is lean (no PII), get returns full billing/shipping PII
		// under the Integrations security disclaimer. Both gate on the flat, object-independent
		// manage_woocommerce capability and fall through to that callback at discovery (no server.php
		// case). PII exposure in wc-get-order is intentional: the revised WC PII stance in spec 48-
		// mandates full billing/shipping on the single-order read, gated by manage_woocommerce and
		// audited, not stripped.
		'aafm/wc-list-orders'         => array(
			'label'        => __( 'List WooCommerce orders', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists WooCommerce orders with their id, number, status, total, currency, date, and customer id, plus a total count. List rows are lean - no billing or shipping details. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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

		// Order writes (sub-slice W4-WC2.2) - create, update, focused status-only update.
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
 * customer_id. No billing/shipping/PII in list rows - lean for payload economy.
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
 * PII (billing email, phone, full address) is returned as-is - this is intentional per the
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
	// Line items: each raw item from get_items() is mapped to a clean scalar shape. The `id` is the
	// ORDER-ITEM id (not a product id) - it is the exact value wc-create-order-refund's
	// line_items[].line_item_id contract documents ("as returned by reading the order"), so the
	// read has to expose it or the documented per-line refund is unusable (B24).
	$line_items = array();
	foreach ( (array) $order->get_items() as $item ) {
		if ( is_array( $item ) ) {
			// Stub path: items are plain arrays seeded in WcOrderStubStore.
			$line_items[] = array(
				'id'         => (int) ( $item['id'] ?? 0 ),
				'name'       => (string) ( $item['name'] ?? '' ),
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'quantity'   => (int) ( $item['quantity'] ?? 1 ),
				'subtotal'   => (string) ( $item['subtotal'] ?? '0.00' ),
				'total'      => (string) ( $item['total'] ?? '0.00' ),
			);
		} elseif ( is_object( $item ) && method_exists( $item, 'get_name' ) ) {
			// Real WC_Order_Item_Product path.
			$line_items[] = array(
				'id'         => method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0,
				'name'       => (string) $item->get_name(),
				'product_id' => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
				'quantity'   => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1,
				'subtotal'   => method_exists( $item, 'get_subtotal' ) ? (string) $item->get_subtotal() : '0.00',
				'total'      => method_exists( $item, 'get_total' ) ? (string) $item->get_total() : '0.00',
			);
		}
	}

	// Billing address - full PII under the disclaimer; cast to (object) so empty map encodes as {}.
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

	// Shipping address - no email/phone (those are billing-only).
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
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => AAFM_LIST_PAGE_MAX,
					'description' => __( 'Page number of results to return, starting at 1. Defaults to 1.', 'agent-abilities-for-mcp' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Number of orders to return per page, from 1 to 100. Defaults to 20.', 'agent-abilities-for-mcp' ),
				),
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ),
					'description' => "Order status to filter by; 'any' (the default) covers every registered order status - including custom ones - but never the internal checkout-draft status, which must be requested explicitly. Uses the short form without the wc- prefix.",
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
 * returns only the lean fields - no billing/shipping/PII in list rows.
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

	// B57: 'any' passed through to wc_get_orders() is backend-dependent - HPOS resolves it to a
	// status set that INCLUDES the internal checkout-draft status while legacy CPT storage
	// excludes it, so the same call answered differently per backend. Expand 'any' to the
	// registered statuses from wc_get_order_statuses() explicitly (custom statuses included);
	// that map never carries the ephemeral checkout-draft, which stays reachable by requesting
	// it explicitly.
	if ( 'any' === $status && function_exists( 'wc_get_order_statuses' ) ) {
		$status = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $status_key ) {
			$status_key = (string) $status_key;
			$status[]   = str_starts_with( $status_key, 'wc-' ) ? substr( $status_key, 3 ) : $status_key;
		}
	}

	// 'type' is not optional. wc_get_orders() without it returns every order-ish record the store
	// holds, and under HPOS a refund is a row in wc_orders with type shop_order_refund carrying its
	// own status - a refund against a completed order is itself 'wc-completed'. So an untyped query
	// counts refunds as orders and reports a list total nobody can reconcile with the store.
	$query = wc_get_orders(
		array(
			'type'     => 'shop_order',
			'limit'    => $per_page,
			'page'     => $page,
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The order's post ID. Must reference an existing order or the request fails.", 'agent-abilities-for-mcp' ),
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
							'id'         => array(
								'type'        => 'integer',
								'description' => __( "The order's own line item id - the value wc-create-order-refund's line_items[].line_item_id expects. Not a product id.", 'agent-abilities-for-mcp' ),
							),
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
 * Resolves the order id through wc_get_order() - not the product wc_get_product(). An unknown
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
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( 'WordPress/WooCommerce user ID to attach the order to. Use 0 for a guest order with no linked customer account.', 'agent-abilities-for-mcp' ),
		),
		'customer_note' => array(
			'type'        => 'string',
			'description' => __( 'Free-text note attached to the order, visible to the customer on their My Account order view and order emails.', 'agent-abilities-for-mcp' ),
		),
		'billing'       => array(
			'type'                 => 'object',
			'description'          => __( 'Billing address to set on the order. Only the sub-fields included in the request are applied; other billing fields are left unchanged (on update) or blank (on create).', 'agent-abilities-for-mcp' ),
			// MEDIUM-4: close the nested billing object -- a smuggled key (e.g. billing.role) is rejected.
			'additionalProperties' => false,
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'First name for the billing address. Appears on invoices and order emails; does not need to match the account first name.', 'agent-abilities-for-mcp' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'Last name for the billing address. Appears on invoices and order emails.', 'agent-abilities-for-mcp' ),
				),
				'company'    => array(
					'type'        => 'string',
					'description' => __( 'Company name for the billing address. Optional; leave blank for a personal, non-business address.', 'agent-abilities-for-mcp' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'Primary billing street address (house or building number and street name).', 'agent-abilities-for-mcp' ),
				),
				'address_2'  => array(
					'type'        => 'string',
					'description' => __( 'Secondary billing address line for an apartment, suite, or unit number. Optional.', 'agent-abilities-for-mcp' ),
				),
				'city'       => array(
					'type'        => 'string',
					'description' => __( 'City or town for the billing address.', 'agent-abilities-for-mcp' ),
				),
				'state'      => array(
					'type'        => 'string',
					'description' => __( 'State, county, or province code for the billing address (e.g. "CA", not "California"). Only meaningful for countries WooCommerce tracks states for. Stored exactly as sent with no validation, so a full name will not match WooCommerce\'s state-based tax or shipping rules.', 'agent-abilities-for-mcp' ),
				),
				'postcode'   => array(
					'type'        => 'string',
					'description' => __( 'Postal or ZIP code for the billing address, in the format the destination country expects.', 'agent-abilities-for-mcp' ),
				),
				'country'    => array(
					'type'        => 'string',
					'description' => __( 'Two-letter ISO country code for the billing address (e.g. "US", not "United States"). Stored exactly as sent with no validation, so an unrecognized value will not match WooCommerce\'s country-based tax rates or shipping zones.', 'agent-abilities-for-mcp' ),
				),
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'Billing email address. Shipping has no email field; the closed shipping schema rejects one if sent there.', 'agent-abilities-for-mcp' ),
				),
				'phone'      => array(
					'type'        => 'string',
					'description' => __( 'Billing phone number. Shipping has no phone field; the closed shipping schema rejects one if sent there.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'shipping'      => array(
			'type'                 => 'object',
			'description'          => __( 'Shipping address to set on the order (no email or phone; those are billing-only fields). Only the sub-fields included in the request are applied; other shipping fields are left unchanged (on update) or blank (on create).', 'agent-abilities-for-mcp' ),
			// MEDIUM-4: close the nested shipping object.
			'additionalProperties' => false,
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'First name for the shipping address. Appears on packing slips; does not need to match the account first name.', 'agent-abilities-for-mcp' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'Last name for the shipping address. Appears on packing slips.', 'agent-abilities-for-mcp' ),
				),
				'company'    => array(
					'type'        => 'string',
					'description' => __( 'Company name for the shipping address. Optional; leave blank for a personal, non-business address.', 'agent-abilities-for-mcp' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'Primary shipping street address (house or building number and street name).', 'agent-abilities-for-mcp' ),
				),
				'address_2'  => array(
					'type'        => 'string',
					'description' => __( 'Secondary shipping address line for an apartment, suite, or unit number. Optional.', 'agent-abilities-for-mcp' ),
				),
				'city'       => array(
					'type'        => 'string',
					'description' => __( 'City or town for the shipping address.', 'agent-abilities-for-mcp' ),
				),
				'state'      => array(
					'type'        => 'string',
					'description' => __( 'State, county, or province code for the shipping address (e.g. "CA", not "California"). Only meaningful for countries WooCommerce tracks states for. Stored exactly as sent with no validation, so a full name will not match WooCommerce\'s state-based tax or shipping rules.', 'agent-abilities-for-mcp' ),
				),
				'postcode'   => array(
					'type'        => 'string',
					'description' => __( 'Postal or ZIP code for the shipping address, in the format the destination country expects.', 'agent-abilities-for-mcp' ),
				),
				'country'    => array(
					'type'        => 'string',
					'description' => __( 'Two-letter ISO country code for the shipping address (e.g. "US", not "United States"). Stored exactly as sent with no validation, so an unrecognized value will not match WooCommerce\'s country-based tax rates or shipping zones.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'line_items'    => array(
			'type'        => 'array',
			'description' => __( 'Products to add as line items, each given as a product_id and quantity pair. Items are always ADDED as new line items rather than replacing or editing what is already on the order; this ability has no way to modify or remove an existing line item. If any product_id cannot be resolved to a real product, the whole request fails with no partial write.', 'agent-abilities-for-mcp' ),
			'items'       => aafm_wc_order_line_item_schema(),
		),
	);
}

/**
 * The shared item schema for a single requested order line item: {product_id, quantity}, closed.
 *
 * Used by `line_items` (on both create and, as a deprecated alias, update) and by `add_line_items`
 * (update only), so every field that accepts a line-item list validates an identical shape.
 *
 * @return array<string,mixed>
 */
function aafm_wc_order_line_item_schema(): array {
	return array(
		'type'                 => 'object',
		// MEDIUM-4: close the line_items item object -- meta_data and any other key are rejected.
		'additionalProperties' => false,
		'properties'           => array(
			'product_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'ID of an existing WooCommerce product to add as a line item. Must resolve to a real product or the entire request fails with no partial write.', 'agent-abilities-for-mcp' ),
			),
			'quantity'   => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Quantity of the product to add. Minimum 1.', 'agent-abilities-for-mcp' ),
			),
		),
		'required'             => array( 'product_id', 'quantity' ),
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
 * aafm_sanitize_plain_text; customer_note -> aafm_sanitize_multiline_text; customer_id -> absint.
 * The nested billing/shipping arrays are sanitized leaf-by-leaf so structured data
 * is never flattened or corrupted.
 *
 * Line items are additive on BOTH create and update -- `add_line_items` (update only) and the
 * deprecated `line_items` alias (create, and update for backward compatibility) are collected into
 * one combined list and every item in it is added via add_product(). Neither field can edit,
 * replace, or remove an existing line item.
 *
 * @param \WC_Order           $order          The order to mutate.
 * @param array<string,mixed> $input          Validated input (already schema-checked).
 * @param array<int,int>      $added_item_ids Out-param: receives the order-item ids this call
 *                                            wrote, so a caller that keeps working on the order
 *                                            afterwards can undo them if its own step fails.
 * @return array<int,int>|\WP_Error Requested product IDs that could not be resolved to a product
 *                                  (empty when all resolved), or a WP_Error when adding threw
 *                                  mid-loop (already-written items rolled back; see B27 note below).
 */
function aafm_wc_apply_order_input( \WC_Order $order, array $input, array &$added_item_ids = array() ) {
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
		$order->set_customer_note( aafm_sanitize_multiline_text( (string) $input['customer_note'] ) );
	}

	// Billing address -- sanitize each leaf individually (never flatten the map).
	if ( array_key_exists( 'billing', $input ) && is_array( $input['billing'] ) ) {
		$billing = $input['billing'];
		if ( array_key_exists( 'first_name', $billing ) ) {
			$order->set_billing_first_name( aafm_sanitize_plain_text( (string) $billing['first_name'] ) );
		}
		if ( array_key_exists( 'last_name', $billing ) ) {
			$order->set_billing_last_name( aafm_sanitize_plain_text( (string) $billing['last_name'] ) );
		}
		if ( array_key_exists( 'company', $billing ) ) {
			$order->set_billing_company( aafm_sanitize_plain_text( (string) $billing['company'] ) );
		}
		if ( array_key_exists( 'address_1', $billing ) ) {
			$order->set_billing_address_1( aafm_sanitize_plain_text( (string) $billing['address_1'] ) );
		}
		if ( array_key_exists( 'address_2', $billing ) ) {
			$order->set_billing_address_2( aafm_sanitize_plain_text( (string) $billing['address_2'] ) );
		}
		if ( array_key_exists( 'city', $billing ) ) {
			$order->set_billing_city( aafm_sanitize_plain_text( (string) $billing['city'] ) );
		}
		if ( array_key_exists( 'state', $billing ) ) {
			$order->set_billing_state( aafm_sanitize_plain_text( (string) $billing['state'] ) );
		}
		if ( array_key_exists( 'postcode', $billing ) ) {
			$order->set_billing_postcode( aafm_sanitize_plain_text( (string) $billing['postcode'] ) );
		}
		if ( array_key_exists( 'country', $billing ) ) {
			$order->set_billing_country( aafm_sanitize_plain_text( (string) $billing['country'] ) );
		}
		if ( array_key_exists( 'email', $billing ) ) {
			$order->set_billing_email( sanitize_email( (string) $billing['email'] ) );
		}
		if ( array_key_exists( 'phone', $billing ) ) {
			$order->set_billing_phone( aafm_sanitize_plain_text( (string) $billing['phone'] ) );
		}
	}

	// Shipping address -- no email/phone (billing-only).
	if ( array_key_exists( 'shipping', $input ) && is_array( $input['shipping'] ) ) {
		$shipping = $input['shipping'];
		if ( array_key_exists( 'first_name', $shipping ) ) {
			$order->set_shipping_first_name( aafm_sanitize_plain_text( (string) $shipping['first_name'] ) );
		}
		if ( array_key_exists( 'last_name', $shipping ) ) {
			$order->set_shipping_last_name( aafm_sanitize_plain_text( (string) $shipping['last_name'] ) );
		}
		if ( array_key_exists( 'company', $shipping ) ) {
			$order->set_shipping_company( aafm_sanitize_plain_text( (string) $shipping['company'] ) );
		}
		if ( array_key_exists( 'address_1', $shipping ) ) {
			$order->set_shipping_address_1( aafm_sanitize_plain_text( (string) $shipping['address_1'] ) );
		}
		if ( array_key_exists( 'address_2', $shipping ) ) {
			$order->set_shipping_address_2( aafm_sanitize_plain_text( (string) $shipping['address_2'] ) );
		}
		if ( array_key_exists( 'city', $shipping ) ) {
			$order->set_shipping_city( aafm_sanitize_plain_text( (string) $shipping['city'] ) );
		}
		if ( array_key_exists( 'state', $shipping ) ) {
			$order->set_shipping_state( aafm_sanitize_plain_text( (string) $shipping['state'] ) );
		}
		if ( array_key_exists( 'postcode', $shipping ) ) {
			$order->set_shipping_postcode( aafm_sanitize_plain_text( (string) $shipping['postcode'] ) );
		}
		if ( array_key_exists( 'country', $shipping ) ) {
			$order->set_shipping_country( aafm_sanitize_plain_text( (string) $shipping['country'] ) );
		}
	}

	// Line items -- add each item via add_product(). This ALWAYS adds a new line item, on both
	// create and update; there is no way to edit or remove an existing one through this ability.
	// `add_line_items` is the honestly-named field for adding items to an existing order on
	// wc-update-order; `line_items` is kept as a deprecated alias with identical additive
	// behaviour so an existing caller that already sends it keeps working unchanged. When both
	// are sent (update only -- create has no add_line_items field), the two lists are combined
	// and every item in either is added; see the add_line_items/line_items descriptions in
	// aafm_args_wc_update_order() for the documented rule.
	//
	// Atomicity: this MUST run as two passes, never interleaved. WC_Order::add_product() calls
	// $item->save() immediately -- it persists the order item row right away, it does not wait for
	// $order->save(). Resolving and adding in a single loop would write every item that comes before
	// the first unresolvable id, then report failure as if nothing happened (the exact contract the
	// line_items/add_line_items schema promises: "the entire request fails with no partial write").
	// Pass 1 resolves every id to a WC_Product without touching the order at all. Pass 2 only runs,
	// and only calls add_product(), once every id in the combined list has resolved.
	$unresolved   = array();
	$items_to_add = array();
	if ( array_key_exists( 'line_items', $input ) && is_array( $input['line_items'] ) ) {
		$items_to_add = array_merge( $items_to_add, $input['line_items'] );
	}
	if ( array_key_exists( 'add_line_items', $input ) && is_array( $input['add_line_items'] ) ) {
		$items_to_add = array_merge( $items_to_add, $input['add_line_items'] );
	}

	$resolved = array();
	foreach ( $items_to_add as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$pid     = absint( $item['product_id'] ?? 0 );
		$qty     = max( 1, absint( $item['quantity'] ?? 1 ) );
		$product = ( $pid > 0 && function_exists( 'wc_get_product' ) ) ? wc_get_product( $pid ) : false;
		if ( $product instanceof \WC_Product ) {
			$resolved[] = array(
				'product' => $product,
				'qty'     => $qty,
			);
		} else {
			$unresolved[] = $pid;
		}
	}

	if ( array() !== $unresolved ) {
		return $unresolved;
	}

	// B27: resolution up front only covers unresolvable ids - add_product() itself can still throw
	// mid-loop, and because it persists each item row IMMEDIATELY ($item->save() runs inside it,
	// before $order->save()), a bare loop would leave every earlier item written (attached on
	// update; orphaned at order_id 0 on create) while the caller is told the request failed. Track
	// each new item id and, on a throw, delete the already-written rows so the "whole request
	// fails with no partial write" promise the schema documents stays true.
	$added_item_ids = array();
	// The ids are also reported to the caller (out-param) because add_product() is not the last
	// thing that can fail after these rows exist -- see aafm_exec_wc_update_order()'s recalculation.
	try {
		foreach ( $resolved as $to_add ) {
			$added_item_ids[] = (int) $order->add_product( $to_add['product'], $to_add['qty'] );
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
		return aafm_wc_rollback_added_order_items( $added_item_ids );
	}

	return $unresolved;
}

/**
 * Whether an order write request actually asks for at least one line item to be added.
 *
 * Mirrors the collection aafm_wc_apply_order_input() does -- both accepted fields, and the same
 * "skip anything that is not an item map" rule -- so the two can never disagree about whether a
 * request touched the order's items. Used by aafm_exec_wc_update_order() to decide whether the
 * order's totals have to be recalculated; see the comment at that call site for why the answer is
 * not simply "always".
 *
 * @param array<string,mixed> $input Validated input (order_id already removed).
 * @return bool
 */
function aafm_wc_input_adds_line_items( array $input ): bool {
	foreach ( array( 'line_items', 'add_line_items' ) as $field ) {
		if ( ! array_key_exists( $field, $input ) || ! is_array( $input[ $field ] ) ) {
			continue;
		}
		foreach ( $input[ $field ] as $item ) {
			if ( is_array( $item ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Delete order-item rows that were written before a mid-loop add_product() failure, and report
 * the outcome honestly.
 *
 * When every already-written row is removed, the returned error states the order is unchanged.
 * When a row cannot be removed (or wc_delete_order_item() is unavailable), the error instead
 * states exactly which order-item ids persisted, so the caller is never told "failed" about
 * state that actually changed (B27).
 *
 * @param array<int,int> $item_ids Order-item ids written before the failure.
 * @return \WP_Error
 */
function aafm_wc_rollback_added_order_items( array $item_ids ): \WP_Error {
	$kept = aafm_wc_delete_added_order_items( $item_ids );

	if ( array() !== $kept ) {
		return new \WP_Error(
			'aafm_wc_line_items_partially_applied',
			sprintf(
				/* translators: %s: comma-separated list of order item ids that could not be removed. */
				__( 'Adding the line items failed partway, and the items already written could not all be removed. Order item ids still persisted: %s. Read the order to see its current line items.', 'agent-abilities-for-mcp' ),
				implode( ', ', $kept )
			)
		);
	}

	return new \WP_Error(
		'aafm_wc_line_items_not_applied',
		__( 'Adding the line items failed. No line items from this request were kept and the order is unchanged.', 'agent-abilities-for-mcp' )
	);
}

/**
 * Delete the order-item rows a failed request wrote, returning the ids that survived.
 *
 * Shared by both rollback paths so they can never disagree about what "removed" means.
 *
 * @param array<int,int> $item_ids Order-item ids written before the failure.
 * @return array<int,int> The ids that could NOT be removed. Empty when every row went.
 */
function aafm_wc_delete_added_order_items( array $item_ids ): array {
	$kept = array();
	foreach ( $item_ids as $item_id ) {
		$item_id = absint( $item_id );
		if ( $item_id < 1 ) {
			continue;
		}
		// wc_delete_order_item() fires woocommerce_before_delete_order_item before it removes the
		// row, so an extension listening there can throw straight out of this loop. Uncaught, that
		// exception escaped the whole rollback: on create it meant the order-level delete never ran,
		// a raw Throwable left the ability, and the part-built order stayed -- both halves of the
		// defect the create fix was written to close, reappearing precisely when an extension
		// misbehaves during cleanup. A rollback that can itself crash is not a rollback.
		//
		// So a throw is recorded as an UNCONFIRMED survivor and the loop keeps going. Unconfirmed is
		// the honest word: the throw says the delete did not complete, not that the row is still
		// there, and the caller re-checks with aafm_wc_surviving_order_items() once the rest of the
		// cleanup has had its turn.
		$deleted = false;
		try {
			$deleted = function_exists( 'wc_delete_order_item' ) ? wc_delete_order_item( $item_id ) : false;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
			$deleted = false;
		}
		if ( ! $deleted ) {
			$kept[] = $item_id;
		}
	}
	return $kept;
}

/**
 * Narrow a list of unconfirmed survivors to the rows that are genuinely still there.
 *
 * Two things make the re-check necessary rather than tidy. A delete that threw may still have
 * removed the row, because woocommerce_delete_order_item fires AFTER the deletion; and on create the
 * order-level delete that runs afterwards takes its own items with it, so a row whose direct
 * deletion failed can be gone by the time the message is chosen. Reporting either as a survivor
 * would name ids the caller cannot find, which is its own kind of lie.
 *
 * @param array<int,int> $item_ids Ids whose deletion was not confirmed.
 * @return array<int,int> The subset that still exists.
 */
function aafm_wc_surviving_order_items( array $item_ids ): array {
	if ( array() === $item_ids || ! class_exists( 'WC_Order_Factory' ) ) {
		return $item_ids;
	}

	$surviving = array();
	foreach ( $item_ids as $item_id ) {
		try {
			if ( false !== \WC_Order_Factory::get_order_item( (int) $item_id ) ) {
				$surviving[] = (int) $item_id;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
			// Cannot tell: keep reporting it, since the honest failure is to over-report a survivor
			// the caller can check, never to quietly drop one.
			$surviving[] = (int) $item_id;
		}
	}
	return $surviving;
}

/**
 * Capture everything about an order that a totals recalculation moves, in a form that can be both
 * compared and written back.
 *
 * Taken BEFORE the request touches the order, so it describes the order as the caller found it.
 *
 * Coupon items are deliberately absent: calculate_totals() reads a coupon's recorded discount to
 * work out the order's totals, it does not rewrite the coupon row itself, so there is nothing there
 * to put back.
 *
 * @param \WC_Order $order The order, freshly read.
 * @return array<string,mixed>
 */
function aafm_wc_order_money_snapshot( \WC_Order $order ): array {
	// Every read is guarded, and the whole thing is wrapped. An order object that cannot answer
	// these questions yields NO snapshot, and a missing snapshot makes the rollback report the
	// weaker "could not be confirmed as restored" error rather than claiming a restore it never
	// performed. Failing honest is the point; a partial snapshot restored as if it were complete
	// would be the same class of lie this whole fix exists to remove.
	try {
		$items = array();
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_total' ) || ! method_exists( $item, 'get_taxes' ) ) {
				return array();
			}
			$items[ (int) $item_id ] = array(
				'total'        => (string) $item->get_total(),
				'subtotal'     => method_exists( $item, 'get_subtotal' ) ? (string) $item->get_subtotal() : null,
				'total_tax'    => method_exists( $item, 'get_total_tax' ) ? (string) $item->get_total_tax() : null,
				'subtotal_tax' => method_exists( $item, 'get_subtotal_tax' ) ? (string) $item->get_subtotal_tax() : null,
				'taxes'        => (array) $item->get_taxes(),
			);
		}

		$order_reads = array(
			'total'          => 'get_total',
			'cart_tax'       => 'get_cart_tax',
			'shipping_tax'   => 'get_shipping_tax',
			'shipping_total' => 'get_shipping_total',
			'discount_total' => 'get_discount_total',
			'discount_tax'   => 'get_discount_tax',
		);
		$order_money = array();
		foreach ( $order_reads as $key => $getter ) {
			if ( ! method_exists( $order, $getter ) ) {
				return array();
			}
			$order_money[ $key ] = (string) $order->{$getter}();
		}

		$taxes = array();
		foreach ( $order->get_taxes() as $tax_item ) {
			if ( ! is_object( $tax_item ) || ! method_exists( $tax_item, 'get_rate_id' ) ) {
				return array();
			}
			// update_taxes() rewrites a tax row's rate_code, label, compound flag and rate_percent
			// from the CURRENT rate, not just its amounts (abstract-wc-order.php, the existing-taxes
			// loop). All of it therefore has to be captured, or a restore puts the old money back
			// under the new rate's identity.
			$taxes[ (int) $tax_item->get_rate_id() ] = array(
				'rate_code'          => (string) $tax_item->get_rate_code(),
				'label'              => (string) $tax_item->get_label(),
				'compound'           => (bool) $tax_item->get_compound(),
				'rate_percent'       => $tax_item->get_rate_percent(),
				'tax_total'          => (string) $tax_item->get_tax_total(),
				'shipping_tax_total' => (string) $tax_item->get_shipping_tax_total(),
			);
		}

		return array(
			'order' => $order_money,
			'items' => $items,
			'taxes' => $taxes,
		);
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
		return array();
	}
}

/**
 * Normalize one money figure for COMPARISON only.
 *
 * Deliberately not applied when the snapshot is taken. A snapshot holds the exact strings
 * WooCommerce handed over, so restoring writes back byte-for-byte what was there; normalizing at
 * capture would store "20" back as "20.0000", which is the same money but not the same stored
 * value, and this rollback has no business reformatting a figure it was only meant to preserve.
 *
 * Four decimal places, independent of the shop's own display precision: this is only ever compared,
 * never read by anyone.
 *
 * @param mixed $value Raw figure off a WooCommerce getter.
 * @return string
 */
function aafm_wc_money_figure( $value ): string {
	return number_format( (float) $value, 4, '.', '' );
}

/**
 * Canonicalize an order item's tax map so two of them can be compared without their key TYPES
 * deciding the answer.
 *
 * WooCommerce hands this map back with rate ids as ints from one code path and numeric strings from
 * another, and the amounts as floats or strings depending on where they came from. Comparing the
 * raw arrays would report a perfectly good restore as a failure. Casting the keys to int, running
 * the amounts through the same normalizer as every other figure, and sorting removes all three
 * sources of noise while keeping every semantic value.
 *
 * The map used to be left out of the comparison for exactly that noise, which meant the strong
 * message covered less than it sounded like it did. Canonicalizing is the way to include it
 * honestly rather than exclude it quietly.
 *
 * @param mixed $taxes A WC_Order_Item::get_taxes() map.
 * @return array<string,array<int,string>>
 */
function aafm_wc_canonical_tax_map( $taxes ): array {
	$canonical = array();
	foreach ( array( 'total', 'subtotal' ) as $bucket ) {
		$rates    = ( is_array( $taxes ) && isset( $taxes[ $bucket ] ) && is_array( $taxes[ $bucket ] ) ) ? $taxes[ $bucket ] : array();
		$bucketed = array();
		foreach ( $rates as $rate_id => $amount ) {
			$bucketed[ (int) $rate_id ] = aafm_wc_money_figure( $amount );
		}
		ksort( $bucketed );
		$canonical[ $bucket ] = $bucketed;
	}
	return $canonical;
}

/**
 * Flatten a money snapshot into one comparable string.
 *
 * This is what decides whether the caller is told the money was put back, so it has to cover
 * everything the restore claims to have restored. It covers the order's own figures, each item's
 * amounts AND its canonicalized tax map, and each tax row's amounts AND its rate identity: rate
 * code, label, compound flag and rate percent.
 *
 * The rate identity is in here because leaving it out was a real defect. update_taxes() rewrites a
 * tax row's rate metadata from the current rate, so a shop that edited a rate between the order
 * being placed and this request could get its old tax AMOUNT restored under the NEW rate's percent
 * and label, and the signature, comparing only amounts, would still hand back the strong
 * "totals and taxes were put back" message over a row that was internally inconsistent. A
 * verification that checks less than the message asserts is the same false promise in a new place.
 *
 * Nothing is excluded from this comparison. If that changes, say here what is left out and why.
 *
 * @param array<string,mixed> $snapshot A snapshot from aafm_wc_order_money_snapshot().
 * @return string
 */
function aafm_wc_money_signature( array $snapshot ): string {
	$parts = array();

	$order = (array) ( $snapshot['order'] ?? array() );
	ksort( $order );
	foreach ( $order as $key => $value ) {
		$parts[] = 'o.' . $key . '=' . aafm_wc_money_figure( $value );
	}

	$items = (array) ( $snapshot['items'] ?? array() );
	ksort( $items );
	foreach ( $items as $item_id => $row ) {
		$map     = aafm_wc_canonical_tax_map( $row['taxes'] ?? array() );
		$parts[] = 'i.' . $item_id . '='
			. aafm_wc_money_figure( $row['total'] ) . '/'
			. aafm_wc_money_figure( $row['subtotal'] ) . '/'
			. aafm_wc_money_figure( $row['total_tax'] ) . '/'
			. aafm_wc_money_figure( $row['subtotal_tax'] )
			. '/map:' . (string) wp_json_encode( $map );
	}

	$taxes = (array) ( $snapshot['taxes'] ?? array() );
	ksort( $taxes );
	foreach ( $taxes as $rate_id => $row ) {
		$parts[] = 't.' . $rate_id . '='
			. aafm_wc_money_figure( $row['tax_total'] ) . '/'
			. aafm_wc_money_figure( $row['shipping_tax_total'] )
			. '/code:' . (string) ( $row['rate_code'] ?? '' )
			. '/label:' . (string) ( $row['label'] ?? '' )
			. '/compound:' . ( empty( $row['compound'] ) ? '0' : '1' )
			. '/percent:' . aafm_wc_money_figure( $row['rate_percent'] ?? 0 );
	}

	return implode( ';', $parts );
}

/**
 * Write a money snapshot back onto an order.
 *
 * Must be handed a FRESHLY READ order, never the half-recalculated object the exception came out
 * of: that one still holds the items this request added in its own item collection, and saving it
 * would write them straight back after they had been deleted.
 *
 * Tax rows are matched on RATE ID rather than order-item id, because update_taxes() is free to drop
 * a rate's row and create a new one with a different item id for the same rate. Matching on rate id
 * means a row the recalculation added is removed, a row it dropped is recreated, and a row it
 * merely rewrote is set back.
 *
 * @param \WC_Order           $order    A freshly read order.
 * @param array<string,mixed> $snapshot Snapshot from aafm_wc_order_money_snapshot().
 * @return bool True when every write went through, false when anything threw.
 */
function aafm_wc_restore_order_money( \WC_Order $order, array $snapshot ): bool {
	try {
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! isset( $snapshot['items'][ (int) $item_id ] ) ) {
				continue;
			}
			// Every type this iterates (line_item, shipping, fee) declares set_total/set_taxes/save in
			// real WooCommerce; only set_subtotal is product-only. An item that cannot be written
			// back leaves the restore incomplete, so it fails the whole thing rather than being
			// quietly skipped -- the caller then gets the weaker, truthful error.
			if ( ! method_exists( $item, 'set_total' ) || ! method_exists( $item, 'set_taxes' ) || ! method_exists( $item, 'save' ) ) {
				return false;
			}
			$row = $snapshot['items'][ (int) $item_id ];
			$item->set_total( $row['total'] );
			if ( null !== $row['subtotal'] && method_exists( $item, 'set_subtotal' ) ) {
				$item->set_subtotal( $row['subtotal'] );
			}
			$item->set_taxes( $row['taxes'] );
			$item->save();
		}

		$seen_rates = array();
		foreach ( $order->get_taxes() as $tax_item_id => $tax_item ) {
			$rate_id = (int) $tax_item->get_rate_id();
			if ( ! isset( $snapshot['taxes'][ $rate_id ] ) ) {
				$order->remove_item( $tax_item_id );
				continue;
			}
			// A SURVIVING row gets every captured field back, not just its amounts. update_taxes()
			// rewrote its rate code, label, compound flag and percent from the current rate before
			// the throw, so restoring the amounts alone leaves the old money sitting under the new
			// rate's identity: a row that is internally inconsistent and matches nothing the caller
			// started with. Recreated rows below get the same treatment, for the same reason.
			$row = $snapshot['taxes'][ $rate_id ];
			aafm_wc_apply_tax_row_snapshot( $tax_item, $row );
			$tax_item->save();
			$seen_rates[ $rate_id ] = true;
		}

		if ( class_exists( 'WC_Order_Item_Tax' ) ) {
			foreach ( $snapshot['taxes'] as $rate_id => $row ) {
				if ( isset( $seen_rates[ (int) $rate_id ] ) ) {
					continue;
				}
				$tax_item = new \WC_Order_Item_Tax();
				$tax_item->set_rate_id( (int) $rate_id );
				aafm_wc_apply_tax_row_snapshot( $tax_item, $row );
				$order->add_item( $tax_item );
			}
		}

		$order->set_shipping_total( $snapshot['order']['shipping_total'] );
		$order->set_discount_total( $snapshot['order']['discount_total'] );
		$order->set_discount_tax( $snapshot['order']['discount_tax'] );
		$order->set_cart_tax( $snapshot['order']['cart_tax'] );
		$order->set_shipping_tax( $snapshot['order']['shipping_tax'] );
		$order->set_total( $snapshot['order']['total'] );
		$order->save();

		return true;
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
		return false;
	}
}

/**
 * Write one captured tax row back onto a tax item, identity and all.
 *
 * Shared by the surviving-row and recreated-row branches so the two can never drift into restoring
 * different field sets, which is exactly how the amounts-only version came to be right in one place
 * and wrong in the other.
 *
 * rate_id is not set here: a surviving row already carries it, and the recreated branch sets it
 * before calling, because that is the key it was matched on in the first place.
 *
 * @param \WC_Order_Item_Tax  $tax_item The tax row to write to.
 * @param array<string,mixed> $row      One entry from a snapshot's `taxes` map.
 * @return void
 */
function aafm_wc_apply_tax_row_snapshot( \WC_Order_Item_Tax $tax_item, array $row ): void {
	$tax_item->set_rate_code( (string) ( $row['rate_code'] ?? '' ) );
	$tax_item->set_label( (string) ( $row['label'] ?? '' ) );
	$tax_item->set_compound( ! empty( $row['compound'] ) );
	if ( null !== ( $row['rate_percent'] ?? null ) ) {
		$tax_item->set_rate_percent( $row['rate_percent'] );
	}
	$tax_item->set_tax_total( $row['tax_total'] );
	$tax_item->set_shipping_tax_total( $row['shipping_tax_total'] );
}

/**
 * Undo a request whose totals recalculation threw, and report exactly what survived.
 *
 * WooCommerce's calculate_totals() is not a single atomic step. It runs calculate_taxes() first, and
 * WooCommerce's own comment on that call says "Note; this also triggers save()" -- so every item's tax map and
 * every order-level tax row is already ON DISK by the time the woocommerce_order_after_calculate_totals
 * hook fires. An extension throwing from that hook therefore leaves rewritten money behind, and
 * deleting the added item rows does not touch any of it. Reproduced against real WooCommerce: an
 * order went from tax 20 to a persisted tax 40 on a single unchanged 100 line while the response
 * said the order was unchanged.
 *
 * So this deletes the added rows, then writes the pre-request money state back, then RE-READS the
 * order and compares before deciding which error to return. The order of those steps matters: the
 * restore runs on a fresh read taken after the deletes, so it cannot resurrect the deleted rows.
 *
 * The re-read goes through wc_get_order(), which an object cache may serve. That confirms the
 * restore was applied and accepted rather than that the bytes reached MySQL, which is why the
 * message it unlocks says the totals were put back rather than making any broader claim about the
 * order as a whole. A third-party hook that wrote its own data before throwing is beyond anything
 * this can see or undo.
 *
 * @param int                 $order_id The order being updated.
 * @param array<int,int>      $item_ids Order-item ids this request wrote.
 * @param array<string,mixed> $snapshot Pre-request money snapshot.
 * @return \WP_Error
 */
function aafm_wc_rollback_recalculated_order( int $order_id, array $item_ids, array $snapshot ): \WP_Error {
	// An item deletion that throws is recorded, not propagated, and the money restore below still
	// runs. Letting cleanup crash here would abandon the restore entirely and hand the caller a raw
	// Throwable in place of any of these errors, which is strictly worse than a partial rollback
	// reported honestly.
	$unconfirmed = aafm_wc_delete_added_order_items( $item_ids );
	$kept        = aafm_wc_surviving_order_items( $unconfirmed );

	$restored = false;
	if ( array() !== $snapshot && $order_id > 0 && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && aafm_wc_restore_order_money( $order, $snapshot ) ) {
			$reread   = wc_get_order( $order_id );
			$restored = $reread instanceof \WC_Order
				&& aafm_wc_money_signature( aafm_wc_order_money_snapshot( $reread ) ) === aafm_wc_money_signature( $snapshot );
		}
	}

	if ( array() !== $kept ) {
		return new \WP_Error(
			'aafm_wc_line_items_partially_applied',
			sprintf(
				/* translators: %s: comma-separated list of order item ids that could not be removed. */
				__( 'Recalculating the order failed, and the items already written could not all be removed. Order item ids still persisted: %s. The order\'s totals and taxes may also have been changed. Read the order before acting on it.', 'agent-abilities-for-mcp' ),
				implode( ', ', $kept )
			)
		);
	}

	if ( ! $restored ) {
		return new \WP_Error(
			'aafm_wc_order_totals_not_restored',
			__( 'Recalculating the order failed after WooCommerce had already saved new tax figures, and those figures could not be confirmed as restored. The line items from this request were removed, but the order\'s totals and taxes may no longer match its line items. Read the order before acting on it.', 'agent-abilities-for-mcp' )
		);
	}

	return new \WP_Error(
		'aafm_wc_line_items_not_applied',
		__( 'Recalculating the order failed. The line items from this request were removed and the order\'s totals and taxes were put back to the values they had before the request.', 'agent-abilities-for-mcp' )
	);
}

/**
 * Whether an order row is still in the database.
 *
 * Used instead of trusting what delete() returned, because the whole point of this rollback is to
 * report what is actually there rather than what an API said it did.
 *
 * A lookup that throws is reported as "still exists": over-reporting a leftover the caller can go
 * and check is recoverable, while claiming a clean rollback that did not happen is exactly the
 * false promise this code exists to stop making.
 *
 * @param int $order_id Order id.
 * @return bool
 */
function aafm_wc_order_still_exists( int $order_id ): bool {
	if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
		return false;
	}
	try {
		return wc_get_order( $order_id ) instanceof \WC_Order;
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
		return true;
	}
}

/**
 * Undo a CREATE whose totals recalculation threw, and report what survived.
 *
 * Deliberately not the update path's rollback, because the two failures are not the same shape.
 * An update has an order that existed before the request and has to be put back the way it was. A
 * create has no earlier state at all: the right answer is not to restore anything but to remove
 * everything the request brought into being, so the caller's "no order was created" is true.
 *
 * There IS an order to remove, which is the part that surprises. `new WC_Order()` persists nothing,
 * but calculate_totals() runs calculate_taxes() and WooCommerce saves inside it, so an order that
 * did not exist when the request began exists by the time an after-hook throw is caught. Confirmed
 * against real WooCommerce: a create that threw left the order table one row heavier while a raw
 * Throwable escaped the ability entirely.
 *
 * Items are deleted before the order. Deleting the order takes its own items with it, but a row
 * written by add_product() while the order id was still 0 belongs to nothing and would outlive it.
 *
 * Every step here assumes cleanup can fail, including the cleanup of the cleanup. Item deletion no
 * longer throws out of the helper, the order delete is wrapped, and neither is believed on its own
 * word: what gets reported is what a fresh look at the database finds. That ordering matters for
 * accuracy as well as safety, because deleting the order removes items whose own deletion failed,
 * so "the item delete threw" does not mean "the item is still there".
 *
 * @param \WC_Order      $order    The part-built order.
 * @param array<int,int> $item_ids Order-item ids this request wrote.
 * @return \WP_Error
 */
function aafm_wc_rollback_created_order( \WC_Order $order, array $item_ids ): \WP_Error {
	$unconfirmed = aafm_wc_delete_added_order_items( $item_ids );
	$order_id    = (int) $order->get_id();

	if ( $order_id > 0 ) {
		try {
			$order->delete( true );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a throw here is answered by the existence check below, which is the authority.
			unset( $e );
		}
	}

	// Deliberately NOT the return value of delete(). The question is whether the order is gone, and
	// the only answer worth reporting comes from looking.
	$removed = 0 === $order_id || ! aafm_wc_order_still_exists( $order_id );

	// Re-check the survivors only now, after the order delete has had its chance to take them.
	$kept = aafm_wc_surviving_order_items( $unconfirmed );

	if ( ! $removed ) {
		return new \WP_Error(
			'aafm_wc_order_partially_created',
			sprintf(
				/* translators: %d: the id of the order that was created and could not be removed. */
				__( 'Creating the order failed while its totals were being calculated, and the part-built order could not be removed. Order %d exists and its totals may be wrong. Read or delete it before trying again; do not simply retry, or you will end up with two.', 'agent-abilities-for-mcp' ),
				$order_id
			)
		);
	}

	if ( array() !== $kept ) {
		return new \WP_Error(
			'aafm_wc_order_partially_created',
			sprintf(
				/* translators: %s: comma-separated list of order item ids that could not be removed. */
				__( 'Creating the order failed while its totals were being calculated. No order was kept, but these order item ids could not be removed: %s. They belong to no order.', 'agent-abilities-for-mcp' ),
				implode( ', ', $kept )
			)
		);
	}

	return new \WP_Error(
		'aafm_wc_order_not_created',
		__( 'Creating the order failed while its totals were being calculated. No order was created and nothing from this request was kept, so it is safe to try again.', 'agent-abilities-for-mcp' )
	);
}

/**
 * Build the WP_Error returned when one or more line-item product IDs cannot be resolved.
 *
 * Keeps create and update reporting identical and lets the caller see exactly which IDs failed
 * instead of receiving a "successful" but incomplete order.
 *
 * @param array<int,int> $unresolved Requested product IDs that did not resolve to a product.
 * @return \WP_Error
 */
function aafm_wc_unresolved_line_items_error( array $unresolved ): \WP_Error {
	$ids = implode( ', ', array_map( 'absint', $unresolved ) );
	return new \WP_Error(
		'aafm_unresolved_line_items',
		sprintf(
			/* translators: %s: comma-separated list of product IDs that could not be found. */
			__( 'One or more line item products could not be found: %s', 'agent-abilities-for-mcp' ),
			$ids
		)
	);
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
					'id'         => array(
						'type'        => 'integer',
						'description' => __( "The order's own line item id - the value wc-create-order-refund's line_items[].line_item_id expects. Not a product id.", 'agent-abilities-for-mcp' ),
					),
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

	$email_error = aafm_wc_billing_email_error( $input );
	if ( $email_error instanceof \WP_Error ) {
		return $email_error;
	}

	$order          = new \WC_Order();
	$added_item_ids = array();
	$unresolved     = aafm_wc_apply_order_input( $order, $input, $added_item_ids );
	if ( is_wp_error( $unresolved ) ) {
		return $unresolved;
	}
	if ( array() !== $unresolved ) {
		return aafm_wc_unresolved_line_items_error( $unresolved );
	}
	// Recalculate line + cart totals so the order total reflects its items. Without this the order
	// total stays at 0.00 even when line_items were added (downstream refunds depend on it).
	//
	// Guarded for the same reason the update path is, and the create case is worse in one respect.
	// With no catch at all an extension throwing from woocommerce_order_after_calculate_totals sent
	// a raw Throwable straight out of the ability: not a wrong answer but an unhandled crash, so the
	// agent got no structured error to act on. That is exactly what AbilityCrashSafetyTest exists to
	// prevent, and fixing the update path while leaving its sibling uncaught is this project's
	// signature archetype. The rollback differs from the update path's because a create has no
	// earlier state to restore -- see aafm_wc_rollback_created_order().
	try {
		$order->calculate_totals();
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
		return aafm_wc_rollback_created_order( $order, $added_item_ids );
	}
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
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( "The order's post ID to update. Must reference an existing order or the request fails.", 'agent-abilities-for-mcp' ),
	);

	// `add_line_items` is the honestly-named field for adding items to an existing order --
	// unlike `line_items` (below), its name does not imply it edits or replaces what is already
	// there. Both fields behave identically (additive only); this one is update-only because a
	// wc-create-order request has no existing order to add to.
	$properties['add_line_items'] = array(
		'type'        => 'array',
		'description' => __( 'Products to add as NEW line items on the existing order, each given as a product_id and quantity pair. Items are always ADDED; this ability has no way to modify, replace, or remove an existing line item. If any product_id cannot be resolved to a real product, the whole request fails with no partial write. Sending the deprecated line_items field at the same time combines both lists -- every item in either is added.', 'agent-abilities-for-mcp' ),
		'items'       => aafm_wc_order_line_item_schema(),
	);
	// line_items predates add_line_items and its original name reads as if it replaces an order's
	// items rather than adding to them. It is kept, unchanged in behaviour, as a deprecated alias
	// so an existing caller already sending it is never rejected -- override the create-facing
	// description (which does not need the alias/combine wording) with the truthful update-only one.
	$properties['line_items']['description'] = __( 'Deprecated alias for add_line_items: items sent here are ADDED as new line items on the existing order, exactly like add_line_items, never replacing or editing what is already there. Kept for backward compatibility with existing callers; prefer add_line_items in new integrations. If both line_items and add_line_items are sent, every item in both lists is added.', 'agent-abilities-for-mcp' );

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

	$email_error = aafm_wc_billing_email_error( $input );
	if ( $email_error instanceof \WP_Error ) {
		return $email_error;
	}

	// Remove order_id from the input map before passing to apply (it is not a field setter).
	$fields = $input;
	unset( $fields['order_id'] );

	// Whether this request adds line items has to be read from the input BEFORE it is applied,
	// because add_product() writes each item row immediately and leaves nothing to compare against
	// afterwards.
	$adds_line_items = aafm_wc_input_adds_line_items( $fields );

	// Goods can only be added while WooCommerce still considers the order editable, and the check
	// has to happen HERE, before aafm_wc_apply_order_input() runs, because add_product() writes each
	// item row the moment it is called.
	//
	// The reason is tax. A new order item is created with an empty tax map, and add_product() sets
	// price, quantity and tax class but calculates nothing; the tax only appears when
	// calculate_totals() is allowed to run calculate_taxes(). On an order that is no longer
	// editable, running it would restate the historical tax on every EXISTING item at today's
	// rates and rewrite what the customer was actually charged, so it must not run -- and without
	// it the new goods are recorded with zero tax and the order under-bills. Neither answer is
	// acceptable, so the request is refused instead of being silently half-applied.
	//
	// WooCommerce draws exactly this line itself: its order screen gates the "Add item(s)" and
	// "Recalculate" buttons on is_editable() (html-order-items.php), so a status this refuses is a
	// status where WooCommerce's own UI offers no way to add an item either.
	if ( $adds_line_items && ! $order->is_editable() ) {
		return new \WP_Error(
			'aafm_wc_order_not_editable',
			sprintf(
				/* translators: %s: the order's current status, e.g. "completed". */
				__( 'Line items cannot be added to this order because WooCommerce no longer treats an order with status "%s" as editable. Adding goods to it would either record them with no tax, understating what the customer is charged, or rewrite the tax already recorded against the existing items. Add the goods to a new order instead, or move this one back to an editable status first.', 'agent-abilities-for-mcp' ),
				$order->get_status()
			)
		);
	}

	// Snapshot the order's money BEFORE anything is applied, so it records the order as the caller
	// found it. It is only needed if the recalculation below can run at all.
	$money_snapshot = $adds_line_items ? aafm_wc_order_money_snapshot( $order ) : array();

	$added_item_ids = array();
	$unresolved     = aafm_wc_apply_order_input( $order, $fields, $added_item_ids );
	if ( is_wp_error( $unresolved ) ) {
		return $unresolved;
	}
	if ( array() !== $unresolved ) {
		return aafm_wc_unresolved_line_items_error( $unresolved );
	}

	// Recalculate ONLY when the request actually added line items. add_product() writes the item
	// rows but never touches the order's own total, so without this an order that gained 14.99 of
	// goods still bills the old figure -- the store ships the item and never charges for it, every
	// report that sums order totals is understated, and WooCommerce caps any later refund at the
	// stale total. Create has the same need and does the same thing (see aafm_exec_wc_create_order).
	//
	// Deliberately NOT unconditional. WooCommerce lets a shop owner override an order's total by
	// hand, and calculate_totals() throws that away and recomputes from the line items. A request
	// that only corrects a postcode or a customer note must not silently rewrite a figure someone
	// set on purpose; WooCommerce's own order screen takes the same position, offering Recalculate
	// as an explicit action rather than running it on every save. Line items are the only thing
	// aafm_wc_apply_order_input() can change that moves the money, so they are the only trigger.
	//
	// Taxes are always recomputed here, and can be, because the not-editable refusal above means
	// this line is only ever reached for an order WooCommerce still considers editable -- one that
	// has not been paid, whose tax is provisional rather than historical. Recomputing every item at
	// today's rates is the right answer for such an order and is exactly what WooCommerce's own
	// Recalculate button does.
	//
	// Passing true UNCONDITIONALLY rather than $order->is_editable() is deliberate. A single request
	// may both add items and set a status, and by this point the status field has already been
	// applied, so re-reading editability here would consult the NEW status: a request that added
	// goods and completed the order in one call would take the false branch and record those goods
	// with no tax at all. The question "may items be added to this order" is about the order as it
	// stood when the request arrived, and it has already been answered above.
	//
	// The recalculation is its own failure point, and it runs AFTER add_product() has already
	// written each item row. B27's rollback wraps the add loop only, so a throw here used to leave
	// the new item on the order, the total still at the old figure, and a raw Throwable escaping
	// the ability -- goods the order carries but never bills for, which is the exact harm this
	// recalculation was added to stop.
	//
	// Deleting the added rows is not enough on its own, because calculate_totals() is not atomic.
	// It runs calculate_taxes() first, which persists as it goes, and only fires
	// woocommerce_order_after_calculate_totals afterwards. An extension throwing from that later
	// hook leaves rewritten tax already on disk, so the rollback has to put the money back too --
	// and say so honestly when it cannot. See aafm_wc_rollback_recalculated_order().
	if ( $adds_line_items ) {
		try {
			$order->calculate_totals( true );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- $e unused; a catch variable is required on the PHP 7.4 floor.
			return aafm_wc_rollback_recalculated_order( (int) $order->get_id(), $added_item_ids, $money_snapshot );
		}
	}
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The order's post ID whose status to change.", 'agent-abilities-for-mcp' ),
				),
				'status'   => array(
					'type'        => 'string',
					'description' => __( 'New order status. Accepts either the short slug (e.g. "completed") or the wc- prefixed form (e.g. "wc-completed"); must match a status registered with wc_get_order_statuses(). No transition is blocked based on the order\'s current status.', 'agent-abilities-for-mcp' ),
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

	// B55: WC_Order::update_status() catches its own exceptions internally and returns FALSE on a
	// failed transition (class-wc-order.php:402-426), so ignoring the return turned a failed
	// transition into a success payload carrying the old status. Check it, and verify the
	// re-read order actually carries the requested status before reporting success.
	if ( true !== $order->update_status( $short ) ) {
		return new \WP_Error(
			'aafm_wc_status_update_failed',
			sprintf(
				/* translators: %s: the requested order status. */
				__( 'The order status could not be changed to "%s". The order keeps its previous status.', 'agent-abilities-for-mcp' ),
				$short
			)
		);
	}
	// save() is technically redundant on real WC (update_status() persists internally), but
	// is required here so the stub's save() flushes the in-memory data back to WcOrderStubStore.
	$order->save();

	$saved = aafm_wc_get_order_object( $order->get_id() );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	if ( (string) $saved->get_status() !== $short ) {
		return new \WP_Error(
			'aafm_wc_status_update_failed',
			sprintf(
				/* translators: 1: the requested order status, 2: the status the order actually holds. */
				__( 'The order status did not persist as "%1$s"; it currently reads "%2$s".', 'agent-abilities-for-mcp' ),
				$short,
				(string) $saved->get_status()
			)
		);
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
 * WooCommerce object's own ->delete() or wc_delete_order_note() - none is a
 * wp_delete_post/wp_delete_comment literal so the SecurityRegressionTest stays green.
 * --------------------------------------------------------------------------
 */

// ============================================================================
// Group B - order notes
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
	// wc_get_order_notes() normalizes ->added_by to the literal 'system' for a programmatic note
	// (comment_author 'WooCommerce'), or the acting user's display name for a human-authored one -
	// it never emits the literal string 'user' (M12). A note is user-authored when it is NOT 'system'.
	$added_by_user = isset( $note->added_by ) && 'system' !== (string) $note->added_by;

	return array(
		'id'            => $id,
		'note'          => $text,
		'added_by_user' => $added_by_user,
		'date_created'  => $date_created,
		'customer_note' => $customer_note,
	);
}

// ---------------------------------------------------------------------------
// Shared output-property helpers - notes and refunds.
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The order's post ID whose notes to list.", 'agent-abilities-for-mcp' ),
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The order's post ID to attach the note to.", 'agent-abilities-for-mcp' ),
				),
				'note'          => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The note text to add to the order.', 'agent-abilities-for-mcp' ),
				),
				'customer_note' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, the note is customer-facing and appears in the customer\'s My Account order view and order emails. When false (the default), it is a private, admin-only note.', 'agent-abilities-for-mcp' ),
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
	$order_id = (int) ( $input['order_id'] ?? 0 );
	// B58: textarea sanitizer, matching the customer_note sibling - sanitize_text_field() would
	// collapse the newlines out of a multi-line note.
	$note_text     = aafm_sanitize_multiline_text( (string) ( $input['note'] ?? '' ) );
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
// Group C - order refunds
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The order's post ID whose refunds to list.", 'agent-abilities-for-mcp' ),
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The refund's own post ID (not the order id). Get it from wc-list-order-refunds or the id returned by wc-create-order-refund.", 'agent-abilities-for-mcp' ),
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The order's post ID to refund.", 'agent-abilities-for-mcp' ),
				),
				'amount'     => array(
					'type'        => 'string',
					'pattern'     => '^\d+(\.\d{1,2})?$',
					'description' => __( 'Total refund amount as a decimal string, e.g. "12.50" (no currency symbol or thousands separator). Required even when line_items is also sent.', 'agent-abilities-for-mcp' ),
				),
				'reason'     => array(
					'type'        => 'string',
					'description' => __( 'Optional free-text reason recorded on the refund and returned verbatim under the Integrations security disclaimer.', 'agent-abilities-for-mcp' ),
				),
				'line_items' => array(
					'type'        => 'array',
					'description' => __( 'Optional per-line-item refund breakdown, each with line_item_id (the order\'s own line item id), refund_total, and refund_tax as decimal strings. When omitted, the refund is recorded against the order as a whole with no per-line allocation. A non-numeric or negative refund_total or refund_tax on any line, or a line_item_id that does not exist on the order, fails the entire request.', 'agent-abilities-for-mcp' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'line_item_id' => array(
								'type'        => 'integer',
								'description' => __( "The order's own line item id, from the id field of the line_items returned by wc-get-order. This is not a product id. An id that does not exist on the order fails the entire request.", 'agent-abilities-for-mcp' ),
							),
							'refund_total' => array(
								'type'        => 'string',
								'description' => __( 'Amount to refund against this line, as a decimal string, e.g. "12.50" (no currency symbol or thousands separator). Unlike the top-level amount this is not constrained by the schema, so it is checked when the refund runs: a non-numeric or negative value fails the whole request before any refund is created.', 'agent-abilities-for-mcp' ),
							),
							'refund_tax'   => array(
								'type'        => 'string',
								'description' => __( 'Tax portion to refund against this line, as a decimal string. Same validation as refund_total: non-numeric or negative fails the whole request before any refund is created.', 'agent-abilities-for-mcp' ),
							),
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
	// B58 sweep: the refund reason is the same class of free-form text as an order note, so it
	// gets the textarea sanitizer too - line breaks survive.
	$reason = aafm_sanitize_multiline_text( (string) ( $input['reason'] ?? '' ) );

	$order = aafm_wc_get_order_object( $order_id );
	if ( null === $order ) {
		return aafm_generic_error();
	}

	$refund_args = array(
		'order_id' => $order_id,
		'amount'   => $amount,
		'reason'   => $reason,
	);

	// Pass line_items through when provided - wc_create_refund() accepts them.
	if ( ! empty( $input['line_items'] ) && is_array( $input['line_items'] ) ) {
		$line_items = array();
		foreach ( $input['line_items'] as $item ) {
			$item         = (array) $item;
			$line_item_id = isset( $item['line_item_id'] ) ? (int) $item['line_item_id'] : 0;
			$refund_total = isset( $item['refund_total'] ) ? trim( (string) $item['refund_total'], " \t\n\r\0\x0B\f" ) : '0.00';
			$refund_tax   = isset( $item['refund_tax'] ) ? trim( (string) $item['refund_tax'], " \t\n\r\0\x0B\f" ) : '0.00';

			// B24: wc_create_refund() silently SKIPS any line_items key that does not match an item
			// on the order (it iterates the order's own items and ignores unmatched ids), which would
			// turn this documented per-line refund into a full-amount refund with no per-line record
			// and no download-permission revocation. Refuse an unresolvable id before any refund runs.
			$order_item = $order->get_item( $line_item_id );
			if ( ! ( $order_item instanceof \WC_Order_Item ) ) {
				return new \WP_Error(
					'aafm_wc_unknown_refund_line_item',
					sprintf(
						/* translators: %d: the line item id that does not exist on the order. */
						__( 'Line item %d does not exist on this order. Use an id from the line_items returned by reading the order.', 'agent-abilities-for-mcp' ),
						$line_item_id
					)
				);
			}

			// MONEY SAFETY: the input schema constrains the per-line refund_total/refund_tax only to
			// `type: string`, not to a non-negative number (unlike the top-level `amount`, which has a
			// `^\d+(\.\d{1,2})?$` pattern). Reject a non-numeric or negative value here, before
			// wc_create_refund() ever sees it, so a malformed line can never drive a garbage/negative
			// refund amount. Trimmed above (same treatment as aafm_wc_normalize_tax_rate()) because
			// is_numeric() only accepts trailing whitespace in a numeric string since PHP 8.0; without
			// the trim, an identical trailing-space value validates on 8.x and fails on the plugin's
			// PHP 7.4 floor. The charlist adds \f: trim()'s default charlist (" \t\n\r\0\x0B") strips
			// a vertical tab but not a form feed, while is_numeric()'s own whitespace set includes
			// both, so a trailing \f still diverged between 7.4 and 8.x without it - rejected on 7.4,
			// accepted on 8.x, for the exact same input.
			if ( ! is_numeric( $refund_total ) || (float) $refund_total < 0
				|| ! is_numeric( $refund_tax ) || (float) $refund_tax < 0
			) {
				return aafm_generic_error();
			}

			$refund_line = array( 'refund_total' => $refund_total );

			// wc_create_refund() keys refund_tax by the *tax rate id*, not by position. A line
			// item can be taxed under several rate ids at once (compound state+county, EU
			// multi-jurisdiction); get_taxes()['total'] is a rate_id => tax_amount map. Spread
			// the requested refund_tax across every rate id in proportion to that rate's share
			// of the line's total tax, rather than dumping the whole amount on the first rate.
			// get_taxes() lives on WC_Order_Item_Product (and the other line-item subtypes with tax),
			// NOT on the base WC_Order_Item - a coupon or base-shaped line id would fatal without this
			// guard (Info: refund crash risk, pinned by
			// WooCommerceContractTest::test_get_taxes_is_not_on_base_order_item()). The item itself
			// was already resolved (and its existence enforced) above.
			if ( method_exists( $order_item, 'get_taxes' ) ) {
				$item_taxes = $order_item->get_taxes();
				if ( isset( $item_taxes['total'] ) && is_array( $item_taxes['total'] ) && array() !== $item_taxes['total'] ) {
					$line_taxes       = array_map( 'floatval', $item_taxes['total'] );
					$total_line_tax   = array_sum( $line_taxes );
					$refund_tax_total = (float) wc_format_decimal( $refund_tax );

					// Skip a zero/empty-tax line (avoids dividing by zero) and skip when there
					// is nothing to refund; either way emit no refund_tax for this line.
					if ( $total_line_tax > 0 && $refund_tax_total > 0 ) {
						$decimals = wc_get_price_decimals();
						$scale    = 10 ** $decimals;

						// Allocate in integer minor units (e.g. cents) using the largest-remainder
						// method so every part is >= 0 and the parts sum to the requested refund_tax
						// exactly. Rounding each proportional share independently can overshoot the
						// total and drive the balancing rate negative, which WooCommerce rejects.
						$total_units = (int) round( $refund_tax_total * $scale );

						// Nothing meaningful to refund once quantised to the store's precision.
						if ( $total_units > 0 ) {
							$floors     = array();
							$remainders = array();
							$order_keys = array();
							$floor_sum  = 0;

							foreach ( $line_taxes as $rate_id => $tax_amount ) {
								$ideal              = $total_units * ( $tax_amount / $total_line_tax );
								$floor              = (int) floor( $ideal );
								$floors[ $rate_id ] = $floor;
								$remainders[]       = array(
									'rate_id' => $rate_id,
									'frac'    => $ideal - $floor,
								);
								$order_keys[]       = $rate_id;
								$floor_sum         += $floor;
							}

							// Hand out the leftover units one at a time to the largest fractional
							// remainders. Ties break toward the earlier rate so allocation is stable.
							$leftover = $total_units - $floor_sum;
							usort(
								$remainders,
								static function ( array $a, array $b ) use ( $order_keys ): int {
									$cmp = $b['frac'] <=> $a['frac'];
									if ( 0 !== $cmp ) {
										return $cmp;
									}
									return array_search( $a['rate_id'], $order_keys, true ) <=> array_search( $b['rate_id'], $order_keys, true );
								}
							);
							for ( $i = 0; $i < $leftover; $i++ ) {
								++$floors[ $remainders[ $i ]['rate_id'] ];
							}

							$refund_taxes = array();
							foreach ( $floors as $rate_id => $units ) {
								$refund_taxes[ $rate_id ] = wc_format_decimal( $units / $scale, $decimals );
							}

							$refund_line['refund_tax'] = $refund_taxes;
						}
					}
				}
			}

			$line_items[ $line_item_id ] = $refund_line;
		}
		$refund_args['line_items'] = $line_items;
	}

	$refund = wc_create_refund( $refund_args );

	if ( is_wp_error( $refund ) || ! ( $refund instanceof \WC_Order_Refund ) ) {
		return aafm_generic_error();
	}

	return aafm_wc_redact_refund( $refund );
}
