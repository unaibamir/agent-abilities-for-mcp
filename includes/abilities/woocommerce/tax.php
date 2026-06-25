<?php
/**
 * WooCommerce integration abilities — tax rate and tax class reads and writes (sub-slice W4-WC6).
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
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_tax_registry_definitions(): array {
	return array(
		// Tax rates (W4-WC6).
		'aafm/wc-list-tax-rates'   => array(
			'label'        => __( 'List WooCommerce tax rates', 'oversio-agent-abilities' ),
			'description'  => __( 'Lists all WooCommerce tax rates across every tax class, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug for each. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_tax_rates',
		),

		'aafm/wc-get-tax-rate'     => array(
			'label'        => __( 'Get WooCommerce tax rate', 'oversio-agent-abilities' ),
			'description'  => __( 'Reads one WooCommerce tax rate by id, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_tax_rate',
		),

		'aafm/wc-create-tax-rate'  => array(
			'label'        => __( 'Create WooCommerce tax rate', 'oversio-agent-abilities' ),
			'description'  => __( 'Creates a WooCommerce tax rate. Required fields: rate (decimal string). Optional: country, state, name, priority, compound, shipping, order, class slug. Returns the full rate shape. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_tax_rate',
		),

		'aafm/wc-update-tax-rate'  => array(
			'label'        => __( 'Update WooCommerce tax rate', 'oversio-agent-abilities' ),
			'description'  => __( 'Updates a WooCommerce tax rate by id, changing only the fields you send. An empty body (only id) is a no-op success. Returns the updated rate shape. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_tax_rate',
		),

		// Tax classes (W4-WC6).
		'aafm/wc-list-tax-classes' => array(
			'label'        => __( 'List WooCommerce tax classes', 'oversio-agent-abilities' ),
			'description'  => __( 'Lists all WooCommerce tax classes including the Standard class, returning name and slug for each. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_tax_classes',
		),

		'aafm/wc-create-tax-class' => array(
			'label'        => __( 'Create WooCommerce tax class', 'oversio-agent-abilities' ),
			'description'  => __( 'Creates a WooCommerce tax class from a name, with an optional slug. Returns the new class shape. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT tax_rate_id AS id, tax_rate_country AS country, tax_rate_state AS state,
		        tax_rate AS rate, tax_rate_name AS name, tax_rate_priority AS priority,
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
		tax_rate AS rate, tax_rate_name AS name, tax_rate_priority AS priority,
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
		'label'               => aafm_ability_label( 'aafm/wc-list-tax-rates' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-tax-rates' ),
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
		'label'               => aafm_ability_label( 'aafm/wc-get-tax-rate' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-tax-rate' ),
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
function aafm_exec_wc_get_tax_rate( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}

	$rate_id = isset( $input['rate_id'] ) ? (int) $input['rate_id'] : 0;
	$rate    = aafm_wc_get_tax_rate_by_id( $rate_id );
	if ( null === $rate ) {
		return new \WP_Error( 'aafm_not_found', __( 'Tax rate not found.', 'oversio-agent-abilities' ) );
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
				'rate'     => array( 'type' => 'string' ),
				'country'  => array(
					'type'        => 'string',
					'description' => 'ISO 3166-1 alpha-2 country code (e.g. "US", "GB"). An empty string means the rate applies to all countries.',
				),
				'state'    => array(
					'type'        => 'string',
					'description' => 'WooCommerce state/region code (e.g. the 2-letter US state code "CA"). An empty string means the rate applies to all states within the country.',
				),
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
		'tax_rate'          => sanitize_text_field( (string) $input['rate'] ),
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
		'label'               => aafm_ability_label( 'aafm/wc-update-tax-rate' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-tax-rate' ),
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
				'country'  => array(
					'type'        => 'string',
					'description' => 'ISO 3166-1 alpha-2 country code (e.g. "US", "GB"). An empty string means the rate applies to all countries.',
				),
				'state'    => array(
					'type'        => 'string',
					'description' => 'WooCommerce state/region code (e.g. the 2-letter US state code "CA"). An empty string means the rate applies to all states within the country.',
				),
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
		return new \WP_Error( 'aafm_not_found', __( 'Tax rate not found.', 'oversio-agent-abilities' ) );
	}

	global $wpdb;
	$table  = $wpdb->prefix . 'woocommerce_tax_rates';
	$fields = array();
	$format = array();

	if ( array_key_exists( 'rate', $input ) ) {
		$fields['tax_rate'] = sanitize_text_field( (string) $input['rate'] );
		$format[]           = '%s';
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

	// WC_Tax::create_tax_class() returns the CANONICAL stored slug in $result['slug'], which WC may
	// have de-duplicated (e.g. a second "Reduced rate" becomes "reduced-rate-1"). Always report that
	// slug so the response is the real lookup key. Only when WC omits it do we fall back — to the
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
