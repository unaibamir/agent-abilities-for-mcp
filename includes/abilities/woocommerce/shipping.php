<?php
/**
 * WooCommerce integration abilities — shipping zone and method reads and writes (sub-slice W4-WC5).
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
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_shipping_registry_definitions(): array {
	return array(
		// Shipping zones (sub-slice W4-WC5) — zone and method management gated on manage_woocommerce.
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

		'aafm/wc-delete-shipping-zone'   => array(
			'label'        => __( 'Delete WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Permanently deletes a WooCommerce shipping zone by id. The Rest of World zone (id 0) cannot be deleted. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_delete_shipping_zone',
		),

		// Shipping methods (sub-slice W4-WC5) — always scoped to a zone.
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

		'aafm/wc-delete-shipping-method' => array(
			'label'        => __( 'Delete WooCommerce shipping method', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Permanently removes a shipping method from a WooCommerce shipping zone by zone id and instance id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_delete_shipping_method',
		),
	);
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
// wc-delete-shipping-zone
// =============================================================================

/**
 * Args builder for aafm/wc-delete-shipping-zone.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_delete_shipping_zone(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-delete-shipping-zone' ),
		'description'         => aafm_ability_description( 'aafm/wc-delete-shipping-zone' ),
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
		'label'               => aafm_ability_label( 'aafm/wc-delete-shipping-method' ),
		'description'         => aafm_ability_description( 'aafm/wc-delete-shipping-method' ),
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
