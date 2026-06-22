<?php
/**
 * WooCommerce integration abilities — sales reports and entity counts (sub-slice W4-WC7).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_reports_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_reports_full_definitions' );

/**
 * Contribute the WooCommerce reports definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_reports_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_reports_registry_definitions() );
}

/**
 * Contribute the WooCommerce reports definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_reports_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_reports_registry_definitions() );
}

/**
 * The WooCommerce reports registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_reports_registry_definitions(): array {
	return array(
		// Reports, counts, and payment gateways (sub-slice W4-WC7).
		'aafm/wc-get-sales-report'       => array(
			'label'        => __( 'Get WooCommerce sales report', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Returns a sales summary for a date range: total sales, order count, net sales, and average order value. Defaults to the current calendar month. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_sales_report',
		),

		'aafm/wc-get-top-sellers-report' => array(
			'label'        => __( 'Get WooCommerce top sellers report', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Returns the best-selling products for a period (week, month, or year) ordered by quantity sold. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_top_sellers_report',
		),

		'aafm/wc-count-orders'           => array(
			'label'        => __( 'Count WooCommerce orders', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Returns order counts broken down by WooCommerce status (pending, processing, on-hold, completed, cancelled, refunded, failed) plus a total of active (non-trashed) orders. HPOS-aware. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_count_orders',
		),

		'aafm/wc-count-products'         => array(
			'label'        => __( 'Count WooCommerce products', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Returns product counts broken down by post status (publish, draft, private, pending, trash) plus a total of active (non-trash) products. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_count_products',
		),

		'aafm/wc-count-customers'        => array(
			'label'        => __( 'Count WooCommerce customers', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Returns the count of registered users on the site. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_count_customers',
		),

		'aafm/wc-count-coupons'          => array(
			'label'        => __( 'Count WooCommerce coupons', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Returns coupon counts broken down by post status (publish, draft, private, pending, trash) plus a total of active coupons. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_count_coupons',
		),
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
		'label'               => aafm_ability_label( 'aafm/wc-get-sales-report' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-sales-report' ),
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
				'idempotent'  => true,
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
	if ( ! aafm_integration_active( 'woocommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
		return aafm_generic_error();
	}
	$start = sanitize_text_field( (string) ( $input['start_date'] ?? gmdate( 'Y-m-01' ) ) );
	$end   = sanitize_text_field( (string) ( $input['end_date'] ?? gmdate( 'Y-m-d' ) ) );

	// HPOS-aware: aggregate through wc_get_orders() so the totals come from WooCommerce's own
	// order storage (custom order tables OR legacy posts) instead of a raw shop_order/postmeta
	// join that only ever sees the legacy tables (B3). The date window is pushed into the query
	// and results are paged so a large order history never loads in one unbounded fetch (mirrors
	// the top-sellers path).
	$start_ts = (int) strtotime( $start . ' 00:00:00' );
	$end_ts   = (int) strtotime( $end . ' 23:59:59' );

	$page        = 1;
	$per_page    = 200;
	$total_sales = 0.0;
	$order_count = 0;

	do {
		$result = wc_get_orders(
			array(
				'status'       => array( 'completed', 'processing' ),
				'date_created' => $start_ts . '...' . $end_ts,
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
			$total_sales += (float) $order->get_total();
			++$order_count;
		}

		++$page;
		// Stop once a short (or empty) page is returned: that is the last page of the window.
	} while ( $page_count === $per_page );

	$total_sales = round( $total_sales, 2 );
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
		'label'               => aafm_ability_label( 'aafm/wc-get-top-sellers-report' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-top-sellers-report' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-count-orders' ),
		'description'         => aafm_ability_description( 'aafm/wc-count-orders' ),
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
				'idempotent'  => true,
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
	if ( ! aafm_integration_active( 'woocommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
		return aafm_generic_error();
	}

	// HPOS-aware: count per status through wc_get_orders() (which targets the custom order tables
	// when HPOS is on, and the legacy posts otherwise) rather than wp_count_posts('shop_order'),
	// which never sees the HPOS tables and would under-report on an HPOS site (B3). Each status is
	// a paginated 1-row probe so only the storage-side total is read, not the order rows.
	$by_status = array();
	foreach ( array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) as $status ) {
		$by_status[ $status ] = aafm_wc_count_orders_by_status( $status );
	}

	// total sums these active order statuses; trashed orders are deliberately excluded, matching
	// the non-trash "active" total convention shared by count-products and count-coupons (B4).
	$total = array_sum( $by_status );

	return array(
		'pending'    => $by_status['pending'],
		'processing' => $by_status['processing'],
		'on_hold'    => $by_status['on-hold'],
		'completed'  => $by_status['completed'],
		'cancelled'  => $by_status['cancelled'],
		'refunded'   => $by_status['refunded'],
		'failed'     => $by_status['failed'],
		'total'      => $total,
	);
}

/**
 * HPOS-aware count of orders in a single WooCommerce status.
 *
 * Runs a paginated wc_get_orders() probe (limit 1, ids only) and reads the storage-reported
 * total, so the count is correct under both High-Performance Order Storage and the legacy
 * post-based storage. Returns 0 when the query shape is unexpected.
 *
 * @param string $status WooCommerce order status slug, without the wc- prefix (e.g. 'completed').
 * @return int
 */
function aafm_wc_count_orders_by_status( string $status ): int {
	$result = wc_get_orders(
		array(
			'status'   => $status,
			'limit'    => 1,
			'paginate' => true,
			'return'   => 'ids',
		)
	);
	return ( is_object( $result ) && isset( $result->total ) ) ? (int) $result->total : 0;
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
		'label'               => aafm_ability_label( 'aafm/wc-count-products' ),
		'description'         => aafm_ability_description( 'aafm/wc-count-products' ),
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
				'idempotent'  => true,
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
	// total counts ACTIVE (non-trashed) products only — trash is reported as its own line but is
	// deliberately excluded from total, the shared count convention across the count siblings (B4).
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
		'label'               => aafm_ability_label( 'aafm/wc-count-customers' ),
		'description'         => aafm_ability_description( 'aafm/wc-count-customers' ),
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
				'idempotent'  => true,
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
// wc-count-coupons
// =============================================================================

/**
 * Args builder for aafm/wc-count-coupons.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_count_coupons(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-count-coupons' ),
		'description'         => aafm_ability_description( 'aafm/wc-count-coupons' ),
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
				'idempotent'  => true,
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
	// total counts ACTIVE (non-trashed) coupons only — trash is reported as its own line but is
	// deliberately excluded from total, the shared count convention across the count siblings (B4).
	return array(
		'publish' => $publish,
		'draft'   => $draft,
		'private' => $private,
		'pending' => $pending,
		'trash'   => $trash,
		'total'   => $publish + $draft + $private + $pending,
	);
}
