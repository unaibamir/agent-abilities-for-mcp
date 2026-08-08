<?php
/**
 * WooCommerce integration abilities - tax rate and tax class reads and writes (sub-slice W4-WC6).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_tax_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_tax_full_definitions' );

/**
 * Contribute the WooCommerce tax definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_tax_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_tax_registry_definitions() );
}

/**
 * Contribute the WooCommerce tax definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_tax_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_tax_registry_definitions() );
}

/**
 * The WooCommerce tax registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_tax_registry_definitions(): array {
	return array(
		// Tax rates (W4-WC6).
		'aafm/wc-list-tax-rates'   => array(
			'label'        => __( 'List WooCommerce tax rates', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists WooCommerce tax rates across every tax class, paged (20 per page by default, up to 100), returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug for each, plus the grand total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_tax_rates',
		),

		'aafm/wc-get-tax-rate'     => array(
			'label'        => __( 'Get WooCommerce tax rate', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one WooCommerce tax rate by id, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_tax_rate',
		),

		'aafm/wc-create-tax-rate'  => array(
			'label'        => __( 'Create WooCommerce tax rate', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a WooCommerce tax rate. Required fields: rate (decimal string). Optional: country, state, name, priority, compound, shipping, order, class slug. Returns the full rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_tax_rate',
		),

		'aafm/wc-update-tax-rate'  => array(
			'label'        => __( 'Update WooCommerce tax rate', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a WooCommerce tax rate by id, changing only the fields you send. An empty body (only id) is a no-op success. Returns the updated rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_tax_rate',
		),

		// Tax classes (W4-WC6).
		'aafm/wc-list-tax-classes' => array(
			'label'        => __( 'List WooCommerce tax classes', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists all WooCommerce tax classes including the Standard class, returning name and slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_tax_classes',
		),

		'aafm/wc-create-tax-class' => array(
			'label'        => __( 'Create WooCommerce tax class', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a WooCommerce tax class from a name, with an optional slug. Returns the new class shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_tax_class',
		),

	);
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
		'rate'     => (string) ( $row['rate'] ?? $row['tax_rate'] ?? '0.0000' ),
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
	$table = $wpdb->prefix . 'woocommerce_tax_rates';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct read of WC's own woocommerce_tax_rates table; no caching layer for admin-driven reads.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT tax_rate_id AS id, tax_rate_country AS country, tax_rate_state AS state,
			tax_rate AS rate, tax_rate_name AS name, tax_rate_priority AS priority,
			tax_rate_compound AS compound, tax_rate_shipping AS shipping,
			tax_rate_order AS `order`, tax_rate_class AS class
			FROM %i ORDER BY tax_rate_order, tax_rate_id',
			$table
		),
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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct read of WC's own woocommerce_tax_rates table; no caching layer for admin-driven reads.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT tax_rate_id AS id, tax_rate_country AS country, tax_rate_state AS state,
			tax_rate AS rate, tax_rate_name AS name, tax_rate_priority AS priority,
			tax_rate_compound AS compound, tax_rate_shipping AS shipping,
			tax_rate_order AS `order`, tax_rate_class AS class
			FROM %i WHERE tax_rate_id = %d',
			$table,
			$rate_id
		),
		ARRAY_A
	);
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
		'label'               => aafm_ability_label( 'aafm/wc-list-tax-rates' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-tax-rates' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			// B54: this was the one unbounded list while every sibling pages; it now takes the
			// standard page/per_page pair (same shapes as wc-list-orders).
			'properties'           => array(
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Page number of results to return, starting at 1. Defaults to 1.', 'agent-abilities-for-mcp' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Number of tax rates to return per page, from 1 to 100. Defaults to 20.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'rates' => array(
					'type'  => 'array',
					'items' => array(
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
				'idempotent'  => true,
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
function aafm_exec_wc_list_tax_rates( array $input ) {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$rates = aafm_wc_get_all_tax_rates();
	$total = count( $rates );

	// B54: page over the full rate list; total stays the grand total, like every sibling list.
	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$rates    = array_slice( $rates, ( $page - 1 ) * $per_page, $per_page );

	return array(
		'rates' => array_values( $rates ),
		'total' => $total,
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
		'label'               => aafm_ability_label( 'aafm/wc-get-tax-rate' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-tax-rate' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate_id' ),
			'properties'           => array(
				'rate_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The tax rate's numeric id (tax_rate_id), from wc-list-tax-rates or the id returned by wc-create-tax-rate.", 'agent-abilities-for-mcp' ),
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
				'idempotent'  => true,
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
function aafm_exec_wc_get_tax_rate( array $input ) {
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
		'label'               => aafm_ability_label( 'aafm/wc-create-tax-rate' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-tax-rate' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate' ),
			'properties'           => array(
				'rate'     => array(
					'type'        => 'string',
					'pattern'     => '^\\d+(\\.\\d{1,4})?$',
					'description' => 'Tax rate percentage as a decimal string, up to 4 decimal places (e.g. "8.25").',
				),
				'country'  => array(
					'type'        => 'string',
					'description' => 'ISO 3166-1 alpha-2 country code (e.g. "US", "GB"). An empty string means the rate applies to all countries.',
				),
				'state'    => array(
					'type'        => 'string',
					'description' => 'WooCommerce state/region code (e.g. the 2-letter US state code "CA"). An empty string means the rate applies to all states within the country.',
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Display name for the tax rate shown in the WooCommerce admin (e.g. "State Tax"). Does not affect calculation, only labeling.', 'agent-abilities-for-mcp' ),
				),
				'priority' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Priority group the rate is evaluated in. Rates that share a priority are summed; rates in different priorities are compounded in order. Defaults to 1.', 'agent-abilities-for-mcp' ),
				),
				'compound' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, this rate is calculated on top of the order total after other (lower-priority) taxes have already been applied, rather than on the pre-tax subtotal. Defaults to false.', 'agent-abilities-for-mcp' ),
				),
				'shipping' => array(
					'type'        => 'boolean',
					'description' => __( "Whether this rate also applies to the order's shipping cost. Defaults to true.", 'agent-abilities-for-mcp' ),
				),
				'order'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Sort position among rates that share the same country, state, and class, lowest first. Defaults to 0.', 'agent-abilities-for-mcp' ),
				),
				'class'    => array(
					'type'        => 'string',
					'description' => __( 'Tax class slug this rate belongs to (e.g. "reduced-rate"). An empty string (the default) is the Standard class. The slug must match an existing tax class; an unknown slug is refused rather than silently filed under Standard.', 'agent-abilities-for-mcp' ),
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
 * Normalise a user-supplied tax rate to a clean decimal string, rejecting non-numeric values.
 *
 * The input schema constrains 'rate' to a numeric pattern, but a value that slips past validation
 * would be stored verbatim and then silently evaluate to ~0% at checkout. This rejects anything
 * non-numeric and routes valid values through wc_format_decimal() so the stored figure matches
 * WooCommerce's own normalisation (localised separators handled, precision preserved).
 *
 * @param mixed $raw Raw rate value from input.
 * @return string|\WP_Error Clean decimal string, or WP_Error when the value is not numeric.
 */
function aafm_wc_normalize_tax_rate( $raw ) {
	$value = trim( (string) $raw );
	if ( '' === $value || ! is_numeric( $value ) ) {
		return new \WP_Error(
			'aafm_invalid_tax_rate',
			__( 'Tax rate must be a numeric value (for example "8.25").', 'agent-abilities-for-mcp' )
		);
	}
	return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $value ) : $value;
}

/**
 * Validate a requested tax-class slug against the classes that actually exist.
 *
 * B30: both rate write paths run WC_Tax::format_tax_rate_class(), which maps ANY unknown slug to
 * '' - so a rate meant for "reduced-rate" (typo, or a class not yet created) silently lands in the
 * Standard class, changes checkout tax, and reports success. An empty string and 'standard' are
 * the Standard class by convention; anything else must match an existing class slug.
 *
 * @param string $slug Sanitized requested class slug.
 * @return \WP_Error|null WP_Error naming the unknown slug and listing the real ones, or null when valid.
 */
function aafm_wc_tax_class_slug_error( string $slug ): ?\WP_Error {
	if ( '' === $slug || 'standard' === $slug ) {
		return null;
	}
	$known = class_exists( '\WC_Tax' ) ? array_map( 'strval', \WC_Tax::get_tax_class_slugs() ) : array();
	if ( in_array( $slug, $known, true ) ) {
		return null;
	}
	return new \WP_Error(
		'aafm_wc_unknown_tax_class',
		sprintf(
			/* translators: 1: the unknown tax class slug, 2: comma-separated list of existing class slugs. */
			__( 'Unknown tax class "%1$s" - WooCommerce would silently file this rate under the Standard class. Existing class slugs: %2$s. Create the class first with wc-create-tax-class if it should exist.', 'agent-abilities-for-mcp' ),
			$slug,
			implode( ', ', array_merge( array( 'standard' ), $known ) )
		)
	);
}

/**
 * Execute aafm/wc-create-tax-rate.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_tax_rate( array $input ) {
	if ( ! aafm_integration_active( 'woocommerce' ) || ! class_exists( '\WC_Tax' ) ) {
		return aafm_generic_error();
	}

	$rate = aafm_wc_normalize_tax_rate( $input['rate'] ?? '' );
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}

	$class_slug  = isset( $input['class'] ) ? sanitize_title( (string) $input['class'] ) : '';
	$class_error = aafm_wc_tax_class_slug_error( $class_slug );
	if ( $class_error instanceof \WP_Error ) {
		return $class_error;
	}

	$data = array(
		'tax_rate_country'  => isset( $input['country'] ) ? sanitize_text_field( (string) $input['country'] ) : '',
		'tax_rate_state'    => isset( $input['state'] ) ? sanitize_text_field( (string) $input['state'] ) : '',
		'tax_rate'          => $rate,
		'tax_rate_name'     => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
		'tax_rate_priority' => isset( $input['priority'] ) ? absint( $input['priority'] ) : 1,
		'tax_rate_compound' => isset( $input['compound'] ) ? ( (bool) $input['compound'] ? 1 : 0 ) : 0,
		'tax_rate_shipping' => isset( $input['shipping'] ) ? ( (bool) $input['shipping'] ? 1 : 0 ) : 1,
		'tax_rate_order'    => isset( $input['order'] ) ? absint( $input['order'] ) : 0,
		'tax_rate_class'    => $class_slug,
	);

	// Route through WC_Tax rather than a raw $wpdb->insert: _insert_tax_rate() writes the same
	// single woocommerce_tax_rates row, but also invalidates the 'taxes' cache group and fires the
	// woocommerce_tax_rate_added action. A direct insert leaves WC's cached rates stale, so checkout
	// keeps charging the old rate until the cache happens to clear (mirrors shipping.php's fix).
	$new_id = (int) \WC_Tax::_insert_tax_rate( $data );
	if ( $new_id <= 0 ) {
		return aafm_generic_error();
	}

	$rate_out = aafm_wc_get_tax_rate_by_id( $new_id );
	if ( null === $rate_out ) {
		return aafm_generic_error();
	}
	return $rate_out;
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
		'label'               => aafm_ability_label( 'aafm/wc-update-tax-rate' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-tax-rate' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rate_id' ),
			'properties'           => array(
				'rate_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The tax rate's numeric id (tax_rate_id) to update. Must reference an existing rate or the request fails.", 'agent-abilities-for-mcp' ),
				),
				'rate'     => array(
					'type'        => 'string',
					'pattern'     => '^\\d+(\\.\\d{1,4})?$',
					'description' => 'Tax rate percentage as a decimal string, up to 4 decimal places (e.g. "8.25").',
				),
				'country'  => array(
					'type'        => 'string',
					'description' => 'ISO 3166-1 alpha-2 country code (e.g. "US", "GB"). An empty string means the rate applies to all countries.',
				),
				'state'    => array(
					'type'        => 'string',
					'description' => 'WooCommerce state/region code (e.g. the 2-letter US state code "CA"). An empty string means the rate applies to all states within the country.',
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Display name for the tax rate shown in the WooCommerce admin (e.g. "State Tax"). Does not affect calculation, only labeling.', 'agent-abilities-for-mcp' ),
				),
				'priority' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Priority group the rate is evaluated in. Rates that share a priority are summed; rates in different priorities are compounded in order.', 'agent-abilities-for-mcp' ),
				),
				'compound' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, this rate is calculated on top of the order total after other (lower-priority) taxes have already been applied, rather than on the pre-tax subtotal.', 'agent-abilities-for-mcp' ),
				),
				'shipping' => array(
					'type'        => 'boolean',
					'description' => __( "Whether this rate also applies to the order's shipping cost.", 'agent-abilities-for-mcp' ),
				),
				'order'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Sort position among rates that share the same country, state, and class, lowest first.', 'agent-abilities-for-mcp' ),
				),
				'class'    => array(
					'type'        => 'string',
					'description' => __( 'Tax class slug this rate belongs to (e.g. "reduced-rate"). An empty string is the Standard class. The slug must match an existing tax class; an unknown slug is refused rather than silently filed under Standard.', 'agent-abilities-for-mcp' ),
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
function aafm_exec_wc_update_tax_rate( array $input ) {
	if ( ! aafm_integration_active( 'woocommerce' ) || ! class_exists( '\WC_Tax' ) ) {
		return aafm_generic_error();
	}

	$rate_id  = isset( $input['rate_id'] ) ? (int) $input['rate_id'] : 0;
	$existing = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $existing ) {
		return new \WP_Error( 'aafm_not_found', __( 'Tax rate not found.', 'agent-abilities-for-mcp' ) );
	}

	$fields = array();

	if ( array_key_exists( 'rate', $input ) ) {
		$rate = aafm_wc_normalize_tax_rate( $input['rate'] );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		$fields['tax_rate'] = $rate;
	}
	if ( array_key_exists( 'country', $input ) ) {
		$fields['tax_rate_country'] = sanitize_text_field( (string) $input['country'] );
	}
	if ( array_key_exists( 'state', $input ) ) {
		$fields['tax_rate_state'] = sanitize_text_field( (string) $input['state'] );
	}
	if ( array_key_exists( 'name', $input ) ) {
		$fields['tax_rate_name'] = sanitize_text_field( (string) $input['name'] );
	}
	if ( array_key_exists( 'priority', $input ) ) {
		$fields['tax_rate_priority'] = absint( $input['priority'] );
	}
	if ( array_key_exists( 'compound', $input ) ) {
		$fields['tax_rate_compound'] = (bool) $input['compound'] ? 1 : 0;
	}
	if ( array_key_exists( 'shipping', $input ) ) {
		$fields['tax_rate_shipping'] = (bool) $input['shipping'] ? 1 : 0;
	}
	if ( array_key_exists( 'order', $input ) ) {
		$fields['tax_rate_order'] = absint( $input['order'] );
	}
	if ( array_key_exists( 'class', $input ) ) {
		$class_slug  = sanitize_title( (string) $input['class'] );
		$class_error = aafm_wc_tax_class_slug_error( $class_slug );
		if ( $class_error instanceof \WP_Error ) {
			return $class_error;
		}
		$fields['tax_rate_class'] = $class_slug;
	}

	if ( ! empty( $fields ) ) {
		// Route through WC_Tax so the write invalidates the 'taxes' cache group and fires
		// woocommerce_tax_rate_updated. A raw $wpdb->update would leave WC's cached rates stale,
		// so checkout could keep charging the old rate (mirrors shipping.php's cache fix).
		\WC_Tax::_update_tax_rate( $rate_id, $fields );
	}

	$updated = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $updated ) {
		return aafm_generic_error();
	}
	return $updated;
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

	// get_tax_classes() returns display NAMES; get_tax_class_slugs() returns the matching
	// sanitized slugs in the same order. Pair them so the exposed slug is the real lookup key
	// (using a name as a slug made get-tax-class fail to match its own created class).
	$names = \WC_Tax::get_tax_classes();
	$slugs = \WC_Tax::get_tax_class_slugs();

	foreach ( array_values( $names ) as $i => $name ) {
		$slug  = isset( $slugs[ $i ] ) ? (string) $slugs[ $i ] : sanitize_title( (string) $name );
		$out[] = array(
			'name' => (string) $name,
			'slug' => $slug,
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
		'label'               => aafm_ability_label( 'aafm/wc-list-tax-classes' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-tax-classes' ),
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
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array( 'type' => 'string' ),
							'slug' => array( 'type' => 'string' ),
						),
					),
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
				'idempotent'  => true,
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
function aafm_exec_wc_list_tax_classes( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
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
// wc-create-tax-class
// =============================================================================

/**
 * Args builder for aafm/wc-create-tax-class.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_create_tax_class(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-create-tax-class' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-tax-class' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name' ),
			'properties'           => array(
				'name' => array(
					'type'        => 'string',
					'description' => __( 'Display name for the new tax class (e.g. "Reduced rate").', 'agent-abilities-for-mcp' ),
				),
				'slug' => array(
					'type'        => 'string',
					'description' => __( 'Optional slug for the class. When omitted, WooCommerce derives one from name. A derived or requested slug that already belongs to an existing class (including Standard) is refused with an error naming the collision; WooCommerce never de-duplicates it.', 'agent-abilities-for-mcp' ),
				),
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
function aafm_exec_wc_create_tax_class( array $input ) {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	if ( ! class_exists( 'WC_Tax' ) ) {
		return aafm_generic_error();
	}

	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';

	// B29: WC_Tax::create_tax_class() does NOT de-duplicate a colliding slug (the old description
	// claimed it did) - it returns a tax_class_slug_exists / tax_class_exists WP_Error. Surface the
	// collision as one clean, actionable error before calling WC, checking the same effective slug
	// WC would derive ('' slug falls back to the sanitized name) against Standard plus every
	// existing class slug and name.
	$effective_slug = '' !== $slug ? $slug : sanitize_title( $name );
	$existing_slugs = array_merge( array( 'standard' ), array_map( 'strval', \WC_Tax::get_tax_class_slugs() ) );
	$existing_names = array_map( 'strval', \WC_Tax::get_tax_classes() );
	if ( in_array( $effective_slug, $existing_slugs, true ) || in_array( $name, $existing_names, true ) ) {
		return new \WP_Error(
			'aafm_wc_tax_class_exists',
			sprintf(
				/* translators: %s: the colliding tax class slug. */
				__( 'A tax class with the slug "%s" already exists. Choose a different name or slug; WooCommerce does not de-duplicate colliding tax classes.', 'agent-abilities-for-mcp' ),
				$effective_slug
			)
		);
	}

	$result = \WC_Tax::create_tax_class( $name, $slug );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// WC_Tax::create_tax_class() returns the CANONICAL stored slug in $result['slug'], which WC may
	// have de-duplicated (e.g. a second "Reduced rate" becomes "reduced-rate-1"). Always report that
	// slug so the response is the real lookup key. Only when WC omits it do we fall back - to the
	// requested slug if one was given, else the name-derived slug (B12: the old code fell back to
	// sanitize_title($name) unconditionally, which dropped an explicit slug and could mismatch the
	// de-duplicated slug WC actually stored).
	$stored_slug = isset( $result['slug'] ) && '' !== (string) $result['slug']
		? (string) $result['slug']
		: ( '' !== $slug ? $slug : sanitize_title( $name ) );

	return array(
		'name' => (string) ( $result['name'] ?? $name ),
		'slug' => $stored_slug,
	);
}
