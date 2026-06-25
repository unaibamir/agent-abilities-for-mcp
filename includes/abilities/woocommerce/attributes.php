<?php
/**
 * WooCommerce integration abilities — global product attribute taxonomy reads and writes (sub-slice W4-WC1c).
 *
 * Registers ONLY when WooCommerce is active (oversio_integration_active('woocommerce')); a host-inactive
 * site contributes zero entries to the registry. Every ability gates on the flat, object-independent
 * manage_woocommerce capability and falls through to its real permission_callback at discovery (no
 * server.php case). Shared helpers live in _shared.php, loaded before this file.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_wc_attributes_definitions' );
add_filter( 'oversio_abilities_registry_integrations', 'oversio_register_wc_attributes_full_definitions' );

/**
 * Contribute the WooCommerce attributes definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_attributes_definitions( array $registry ): array {
	if ( ! oversio_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, oversio_wc_attributes_registry_definitions() );
}

/**
 * Contribute the WooCommerce product attribute definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (oversio_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_attributes_full_definitions( array $registry ): array {
	return array_merge( $registry, oversio_wc_attributes_registry_definitions() );
}

/**
 * The WooCommerce product attribute registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_wc_attributes_registry_definitions(): array {
	return array(
		// Global product attributes (sub-slice W4-WC1c) — the attribute taxonomy surface reached through
		// wc_get_attribute_taxonomies() / wc_create_attribute() / wc_update_attribute() / wc_delete_attribute().
		// Every ability gates on the flat, object-independent manage_woocommerce capability and falls through
		// to its real permission_callback at discovery, so none needs a server.php case.
		'oversio/wc-list-product-attributes'  => array(
			'label'        => __( 'List WooCommerce product attributes', 'oversio-agent-abilities' ),
			'description'  => __( 'Lists all global WooCommerce product attribute taxonomies with their id, name (label), slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_list_product_attributes',
		),

		'oversio/wc-create-product-attribute' => array(
			'label'        => __( 'Create WooCommerce product attribute', 'oversio-agent-abilities' ),
			'description'  => __( 'Creates a new global WooCommerce product attribute taxonomy from a name (required) plus optional slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_create_product_attribute',
		),

		'oversio/wc-update-product-attribute' => array(
			'label'        => __( 'Update WooCommerce product attribute', 'oversio-agent-abilities' ),
			'description'  => __( 'Updates a global WooCommerce product attribute taxonomy by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_update_product_attribute',
		),

	);
}

// =============================================================================
// Global product attributes (sub-slice W4-WC1c)
// =============================================================================
//
// These abilities manage GLOBAL product attribute taxonomies — the
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
function oversio_wc_get_attribute( int $id ): ?\stdClass {
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
function oversio_wc_attribute_output_properties(): array {
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
function oversio_redact_wc_attribute( \stdClass $attr ): array {
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
// oversio/wc-list-product-attributes (R)
// -----------------------------------------------------------------------------

/**
 * Args builder for oversio/wc-list-product-attributes.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_list_product_attributes(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-list-product-attributes' ),
		'description'         => oversio_ability_description( 'oversio/wc-list-product-attributes' ),
		'category'            => 'oversio-reads',
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
						'properties' => oversio_wc_attribute_output_properties(),
					),
				),
				'total'      => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_wc_list_product_attributes',
		'permission_callback' => 'oversio_wc_perm',
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
 * Execute oversio/wc-list-product-attributes.
 *
 * Takes no input (the global attribute list is unscoped and unpaged), so it declares no parameter —
 * matching the no-arg read execs elsewhere (e.g. oversio_exec_list_themes).
 *
 * @return array<string,mixed>
 */
function oversio_exec_wc_list_product_attributes(): array {
	$all  = wc_get_attribute_taxonomies();
	$rows = array_map( 'oversio_redact_wc_attribute', $all );
	return array(
		'attributes' => array_values( $rows ),
		'total'      => count( $rows ),
	);
}

// -----------------------------------------------------------------------------
// oversio/wc-create-product-attribute (W)
// -----------------------------------------------------------------------------

/**
 * The writable input properties shared by create and update.
 *
 * @return array<string,mixed>
 */
function oversio_wc_attribute_write_properties(): array {
	return array(
		'name'         => array( 'type' => 'string' ),
		'slug'         => array( 'type' => 'string' ),
		'type'         => array(
			'type'        => 'string',
			'enum'        => array( 'select', 'text' ),
			'description' => 'Attribute input type: "select" (predefined terms) or "text" (free text). Defaults to select.',
		),
		'order_by'     => array(
			'type'        => 'string',
			'enum'        => array( 'menu_order', 'name', 'name_num', 'id' ),
			'description' => 'Default term sort order for this attribute: menu_order (custom), name, name_num (name treated numerically), or id. Defaults to menu_order.',
		),
		'has_archives' => array( 'type' => 'boolean' ),
	);
}

/**
 * Args builder for oversio/wc-create-product-attribute.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_create_product_attribute(): array {
	$props        = oversio_wc_attribute_write_properties();
	$output_props = oversio_wc_attribute_output_properties();
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-create-product-attribute' ),
		'description'         => oversio_ability_description( 'oversio/wc-create-product-attribute' ),
		'category'            => 'oversio-writes',
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
		'execute_callback'    => 'oversio_exec_wc_create_product_attribute',
		'permission_callback' => 'oversio_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/wc-create-product-attribute.
 *
 * Sanitizes all inputs, delegates to wc_create_attribute(), then re-reads the
 * created row via oversio_wc_get_attribute() and returns the rich shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_create_product_attribute( array $input ) {
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
		return oversio_generic_error();
	}
	$id   = (int) $result;
	$attr = oversio_wc_get_attribute( $id );
	if ( null === $attr ) {
		return oversio_generic_error();
	}
	return oversio_redact_wc_attribute( $attr );
}

// -----------------------------------------------------------------------------
// oversio/wc-update-product-attribute (W)
// -----------------------------------------------------------------------------

/**
 * Args builder for oversio/wc-update-product-attribute.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_update_product_attribute(): array {
	$write_props  = oversio_wc_attribute_write_properties();
	$all_props    = array_merge(
		array(
			'attribute_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		$write_props
	);
	$output_props = oversio_wc_attribute_output_properties();
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-update-product-attribute' ),
		'description'         => oversio_ability_description( 'oversio/wc-update-product-attribute' ),
		'category'            => 'oversio-writes',
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
		'execute_callback'    => 'oversio_exec_wc_update_product_attribute',
		'permission_callback' => 'oversio_wc_perm',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/wc-update-product-attribute.
 *
 * Resolve-before-mutate: unknown id returns a generic error. Only fields present
 * in $input are included in the update args (PATCH semantics). Re-reads the row
 * after update and returns the rich shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_update_product_attribute( array $input ) {
	$id   = (int) ( $input['attribute_id'] ?? 0 );
	$attr = oversio_wc_get_attribute( $id );
	if ( null === $attr ) {
		return oversio_generic_error();
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
			return oversio_generic_error();
		}
	}

	$updated = oversio_wc_get_attribute( $id );
	if ( null === $updated ) {
		return oversio_generic_error();
	}
	return oversio_redact_wc_attribute( $updated );
}
