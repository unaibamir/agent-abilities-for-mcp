<?php
/**
 * WooCommerce integration abilities - shipping zone and method reads and writes (sub-slice W4-WC5).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_shipping_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_shipping_full_definitions' );

/**
 * Contribute the WooCommerce shipping definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_shipping_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_shipping_registry_definitions() );
}

/**
 * Contribute the WooCommerce shipping definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_shipping_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_shipping_registry_definitions() );
}

/**
 * The WooCommerce shipping registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_shipping_registry_definitions(): array {
	return array(
		// Shipping zones (sub-slice W4-WC5) - zone and method management gated on manage_woocommerce.
		'aafm/wc-list-shipping-zones'    => array(
			'label'        => __( 'List WooCommerce shipping zones', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists WooCommerce shipping zones with their id, name, and order. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_shipping_zones',
		),

		'aafm/wc-get-shipping-zone'      => array(
			'label'        => __( 'Get WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one WooCommerce shipping zone by id, including its name, order, and zone locations. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_shipping_zone',
		),

		'aafm/wc-create-shipping-zone'   => array(
			'label'        => __( 'Create WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a WooCommerce shipping zone from a name and optional order. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_shipping_zone',
		),

		'aafm/wc-update-shipping-zone'   => array(
			'label'        => __( 'Update WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a WooCommerce shipping zone by id, changing only the fields you send. An empty request body is a no-op success. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_shipping_zone',
		),

		// Shipping methods (sub-slice W4-WC5) - always scoped to a zone.
		'aafm/wc-list-shipping-methods'  => array(
			'label'        => __( 'List WooCommerce shipping methods', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the shipping methods configured in a WooCommerce shipping zone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_shipping_methods',
		),

		'aafm/wc-get-shipping-method'    => array(
			'label'        => __( 'Get WooCommerce shipping method', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one shipping method from a WooCommerce shipping zone by zone id and instance id. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_shipping_method',
		),

		'aafm/wc-create-shipping-method' => array(
			'label'        => __( 'Create WooCommerce shipping method', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Adds a shipping method to a WooCommerce shipping zone. Provide the zone id and method type (e.g. flat_rate, free_shipping, local_pickup). Returns the new method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_shipping_method',
		),

		'aafm/wc-update-shipping-method' => array(
			'label'        => __( 'Update WooCommerce shipping method', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a shipping method in a WooCommerce shipping zone by zone id and instance id, changing only the fields you send. Returns the updated method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_shipping_method',
		),

	);
}

// =============================================================================
// SHIPPING ZONES - helpers
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
 * Build the lean zone-list row from a WC_Shipping_Zones::get_zones() row.
 *
 * WC_Shipping_Zones::get_zones() hands back each zone as its get_data() array merged with a few
 * extra keys, not a zone object, so the lean fields are read straight off the row. The zone id is
 * resolved by the caller (the array is keyed by it) and passed in so an unresolvable row can be
 * rejected first.
 *
 * @param array<string,mixed> $row     One row from WC_Shipping_Zones::get_zones().
 * @param int                 $zone_id Resolved zone id for the row.
 * @return array<string,mixed>
 */
function aafm_redact_wc_shipping_zone_row( array $row, int $zone_id ): array {
	return array(
		'id'         => $zone_id,
		'zone_name'  => (string) ( $row['zone_name'] ?? '' ),
		'zone_order' => (int) ( $row['zone_order'] ?? 0 ),
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
		'label'               => aafm_ability_label( 'aafm/wc-list-shipping-zones' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-shipping-zones' ),
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
				'idempotent'  => true,
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

	// WC_Shipping_Zones::get_zones() returns each zone as its get_data() array (id, zone_name,
	// zone_order, zone_locations) merged with zone_id, formatted_zone_location, and
	// shipping_methods, keyed by zone id. There is NO zone object on the row - the lean list
	// fields are read straight off it. A row that carries no usable zone id is a data error, not
	// a reason to silently drop a real zone into an empty list.
	$zones_raw = \WC_Shipping_Zones::get_zones();
	$rows      = array();
	foreach ( $zones_raw as $zone_key => $zone_data ) {
		if ( ! is_array( $zone_data ) ) {
			return aafm_generic_error();
		}
		$zone_id = (int) ( $zone_data['id'] ?? $zone_data['zone_id'] ?? $zone_key );
		if ( $zone_id < 1 ) {
			return aafm_generic_error();
		}
		$rows[] = aafm_redact_wc_shipping_zone_row( $zone_data, $zone_id );
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
		'label'               => aafm_ability_label( 'aafm/wc-get-shipping-zone' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-shipping-zone' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-create-shipping-zone' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-shipping-zone' ),
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
		'label'               => aafm_ability_label( 'aafm/wc-update-shipping-zone' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-shipping-zone' ),
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
// SHIPPING METHODS - helpers
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
	// The per-instance display title is the "title" instance setting (WC populates
	// $method->title from it). Fall back to the generic method label when no instance
	// title has been saved. update_shipping_method persists to the same place, so a
	// write -> read round-trip is consistent.
	$instance_title = (string) $method->title;
	$display_title  = ( '' !== $instance_title ) ? $instance_title : (string) $method->method_title;

	return array(
		'instance_id'  => (int) $method->instance_id,
		'id'           => (string) $method->id,
		'method_title' => $display_title,
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
		'label'               => aafm_ability_label( 'aafm/wc-list-shipping-methods' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-shipping-methods' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-get-shipping-method' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-shipping-method' ),
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'The shipping method INSTANCE id within the zone (a method added to a zone, not the method type). Get it from wc-list-shipping-methods for the zone, or from the instance_id returned by wc-create-shipping-method.',
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-create-shipping-method' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-shipping-method' ),
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
				'method_type' => array(
					'type'        => 'string',
					'enum'        => array( 'flat_rate', 'free_shipping', 'local_pickup' ),
					'description' => 'The WooCommerce shipping method type to add to the zone: flat_rate, free_shipping, or local_pickup.',
				),
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
		'label'               => aafm_ability_label( 'aafm/wc-update-shipping-method' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-shipping-method' ),
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'The shipping method INSTANCE id within the zone (from wc-list-shipping-methods or the instance_id returned by wc-create-shipping-method).',
				),
				'method_title' => array( 'type' => 'string' ),
				'enabled'      => array(
					'type'        => 'string',
					'enum'        => array( 'yes', 'no' ),
					'description' => 'Whether the shipping method is enabled, as the string "yes" or "no". This differs from the payment-gateway abilities, where the equivalent enabled flag is a boolean (true/false); shipping keeps a string to match WooCommerce\'s own shipping-method storage.',
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

	// Persist the per-instance display title ("method_title" in this ability) into the
	// method's "title" instance setting. WC_Shipping_Method has no per-object save();
	// instance settings are written by update_option() on the method's instance option
	// key, mirroring WC core's WC_REST_Shipping_Zone_Methods_V2_Controller::update_fields().
	if ( array_key_exists( 'method_title', $input ) ) {
		$title = sanitize_text_field( (string) $input['method_title'] );

		$method->init_instance_settings();
		$instance_settings          = is_array( $method->instance_settings ) ? $method->instance_settings : array();
		$instance_settings['title'] = $title;

		// This filter is documented in WooCommerce: includes/abstracts/abstract-wc-settings-api.php.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-core filter; we mirror WC's own write so extending plugins still fire.
		$instance_settings = apply_filters( 'woocommerce_shipping_' . $method->id . '_instance_settings_values', $instance_settings, $method );

		update_option( $method->get_instance_option_key(), $instance_settings );
	}

	// Persist the enabled flag. For a zone method this is NOT an instance setting - it
	// lives in the is_enabled column of the woocommerce_shipping_zone_methods table, keyed
	// by instance_id. WC core toggles it with a direct $wpdb->update() and then bumps the
	// shipping transient version; there is no higher-level API for this column.
	if ( array_key_exists( 'enabled', $input ) ) {
		global $wpdb;

		$is_enabled = ( 'no' === $input['enabled'] ) ? 0 : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API exists for the is_enabled column; this mirrors WC_REST_Shipping_Zone_Methods_V2_Controller::update_fields().
		$updated_rows = $wpdb->update(
			$wpdb->prefix . 'woocommerce_shipping_zone_methods',
			array( 'is_enabled' => $is_enabled ),
			array( 'instance_id' => $instance_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated_rows ) {
			return aafm_generic_error();
		}

		// Invalidate the shipping cache so the toggle is reflected on the next read.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	// Re-resolve from the zone so the returned shape reflects what was just persisted.
	$updated = aafm_wc_get_shipping_method_object( $zone_id, $instance_id );
	if ( null === $updated ) {
		return aafm_generic_error();
	}

	return aafm_rich_wc_shipping_method( $updated );
}
