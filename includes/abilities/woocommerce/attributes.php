<?php
/**
 * WooCommerce integration abilities - global product attribute taxonomy reads and writes (sub-slice W4-WC1c).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_attributes_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_attributes_full_definitions' );

/**
 * Contribute the WooCommerce attributes definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_attributes_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_attributes_registry_definitions() );
}

/**
 * Contribute the WooCommerce product attribute definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_attributes_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_attributes_registry_definitions() );
}

/**
 * The WooCommerce product attribute registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_attributes_registry_definitions(): array {
	return array(
		// Global product attributes (sub-slice W4-WC1c) - the attribute taxonomy surface reached through
		// wc_get_attribute_taxonomies() / wc_create_attribute() / wc_update_attribute() / wc_delete_attribute().
		// Every ability gates on the flat, object-independent manage_woocommerce capability and falls through
		// to its real permission_callback at discovery, so none needs a server.php case.
		'aafm/wc-list-product-attributes'  => array(
			'label'        => __( 'List WooCommerce product attributes', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists all global WooCommerce product attribute taxonomies with their id, name (label), slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_product_attributes',
		),

		'aafm/wc-create-product-attribute' => array(
			'label'        => __( 'Create WooCommerce product attribute', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a new global WooCommerce product attribute taxonomy from a name (required) plus optional slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_product_attribute',
		),

		'aafm/wc-update-product-attribute' => array(
			'label'        => __( 'Update WooCommerce product attribute', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a global WooCommerce product attribute taxonomy by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_product_attribute',
		),

	);
}

// =============================================================================
// Global product attributes (sub-slice W4-WC1c)
// =============================================================================
//
// These abilities manage GLOBAL product attribute taxonomies - the
// wc_get_attribute_taxonomies() surface - not per-product attributes. Each is
// object-independent and gates on manage_woocommerce, so none needs a server.php
// case; all fall through to the real permission_callback at discovery.
//
// Every attribute in the WooCommerce data store is a stdClass with the following
// field names:  attribute_id, attribute_name (the raw slug, e.g. "color"),
// attribute_label (the human label, e.g. "Color"), attribute_type (e.g. "select"),
// attribute_orderby (e.g. "menu_order"), attribute_public (bool archive flag).
// The redactor maps these to the API's flat shape.

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
 * Sweep finding A (208 FIX-2 item 1): this is now used ONLY by wc-list-product-attributes' bulk
 * row mapping. The two by-id lookups (create's post-write re-read, update's before/after reads)
 * were rewritten to call wc_get_attribute( $id ) directly - WooCommerce's own by-id lookup already
 * returns a stdClass in this exact renamed shape (wc-attribute-functions.php:472-488), including
 * wc_attribute_taxonomy_name() for slug, so redacting its output here would just repeat the same
 * mapping a second time. No vendor equivalent exists for a bulk cross-attribute row map, which is
 * why this function stays for the list path.
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
		'label'               => aafm_ability_label( 'aafm/wc-list-product-attributes' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-product-attributes' ),
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
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/wc-list-product-attributes.
 *
 * Takes no input (the global attribute list is unscoped and unpaged), so it declares no parameter -
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
// aafm/wc-create-product-attribute (W)
// -----------------------------------------------------------------------------

/**
 * The writable input properties shared by create and update.
 *
 * @return array<string,mixed>
 */
function aafm_wc_attribute_write_properties(): array {
	// B31: the accepted attribute types come from WooCommerce's own wc_get_attribute_types() -
	// for core that is ONLY 'select'. The old enum also advertised a phantom 'text' type, which
	// wc_create_attribute() silently coerced to select. Sourcing the enum from the vendor keeps
	// the schema honest, including on a site whose features register extra types; the fallback
	// pins to select when WooCommerce is unavailable (the manifest's full registry view).
	$types = function_exists( 'wc_get_attribute_types' )
		? array_map( 'strval', array_keys( wc_get_attribute_types() ) )
		: array( 'select' );

	return array(
		'name'         => array(
			'type'        => 'string',
			'description' => __( 'Human-readable label for the attribute shown in the WooCommerce admin and on the storefront (e.g. "Color").', 'agent-abilities-for-mcp' ),
		),
		'slug'         => array(
			'type'        => 'string',
			'description' => __( 'Attribute taxonomy slug. When omitted, WooCommerce derives one from name. Do not include the pa_ prefix; WooCommerce adds it automatically when building the taxonomy name.', 'agent-abilities-for-mcp' ),
		),
		'type'         => array(
			'type'        => 'string',
			'enum'        => $types,
			'description' => 'Attribute input type. The accepted values are sourced from wc_get_attribute_types(); WooCommerce core supports only "select" (terms chosen from a predefined list). Defaults to select.',
		),
		'order_by'     => array(
			'type'        => 'string',
			'enum'        => array( 'menu_order', 'name', 'name_num', 'id' ),
			'description' => 'Default term sort order for this attribute: menu_order (custom), name, name_num (name treated numerically), or id. Defaults to menu_order.',
		),
		'has_archives' => array(
			'type'        => 'boolean',
			'description' => __( 'Whether values of this attribute get their own public archive page (like a taxonomy term archive). New attributes default to false when omitted; on update, an omitted value leaves the existing setting unchanged.', 'agent-abilities-for-mcp' ),
		),
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
		'label'               => aafm_ability_label( 'aafm/wc-create-product-attribute' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-product-attribute' ),
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
 * Sanitizes all inputs, delegates to wc_create_attribute(), then re-reads the created row via
 * wc_get_attribute() - WooCommerce's own by-id lookup, already in the API's exact field shape
 * (sweep finding A, 208 FIX-2 item 1) - and returns it directly.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_create_product_attribute( array $input ) {
	$name  = aafm_sanitize_plain_text( (string) ( $input['name'] ?? '' ) );
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
	$attr = wc_get_attribute( $id );
	if ( null === $attr ) {
		return aafm_generic_error();
	}
	return (array) $attr;
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
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( "The global attribute's id, from wc-list-product-attributes or the id returned by wc-create-product-attribute. Must reference an existing attribute or the request fails.", 'agent-abilities-for-mcp' ),
			),
		),
		$write_props
	);
	$output_props = aafm_wc_attribute_output_properties();
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-update-product-attribute' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-product-attribute' ),
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
 * Resolve-before-mutate: unknown id returns a generic error. Passing an unresolvable id straight
 * to wc_update_attribute() would fall through to its own `$args['id'] = $attribute ? $attribute->id
 * : 0` branch and silently CREATE a new attribute instead of failing (wc-attribute-functions.php:701),
 * so this check stays regardless of what else changes here.
 *
 * Sweep finding B (208 FIX-2 item 2): a prior version of this function manually rebuilt the full
 * field set from the resolved attribute before calling wc_update_attribute(), guarding against
 * that function's own backfill being "only present from WC 9.1.0". Verified that guard is now
 * unreachable dead code: wc_update_attribute() (wc-attribute-functions.php:696-720) has done this
 * exact backfill natively since exactly 9.1.0, and AAFM_WOOCOMMERCE_MIN_VERSION
 * (includes/integrations.php:233-234) is pinned to that release for precisely this reason - the
 * WooCommerce abilities, this one included, never register at all below that floor, so the
 * "below 9.1 it wipes fields" case this guard defended against cannot occur on any WooCommerce
 * version this code can actually run on. $args is now built from only the keys the caller sent;
 * wc_update_attribute() backfills the rest from its own resolved current row, the same way its
 * own callers (including WooCommerce's own REST controller) already rely on it to. $changed still
 * tracks whether the caller sent anything at all, so an empty patch stays a genuine no-op.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_product_attribute( array $input ) {
	$id   = (int) ( $input['attribute_id'] ?? 0 );
	$attr = wc_get_attribute( $id );
	if ( null === $attr ) {
		return aafm_generic_error();
	}

	$args = array();

	$changed = false;
	if ( array_key_exists( 'name', $input ) ) {
		$args['name'] = aafm_sanitize_plain_text( (string) $input['name'] );
		$changed      = true;
	}
	if ( array_key_exists( 'slug', $input ) ) {
		$args['slug'] = wc_sanitize_taxonomy_name( sanitize_title( (string) $input['slug'] ) );
		$changed      = true;
	}
	if ( array_key_exists( 'type', $input ) ) {
		$args['type'] = sanitize_key( (string) $input['type'] );
		$changed      = true;
	}
	if ( array_key_exists( 'order_by', $input ) ) {
		$args['order_by'] = sanitize_key( (string) $input['order_by'] );
		$changed          = true;
	}
	if ( array_key_exists( 'has_archives', $input ) ) {
		$args['has_archives'] = (bool) $input['has_archives'];
		$changed              = true;
	}

	if ( $changed ) {
		$result = wc_update_attribute( $id, $args );
		if ( is_wp_error( $result ) || ! $result ) {
			return aafm_generic_error();
		}
	}

	$updated = wc_get_attribute( $id );
	if ( null === $updated ) {
		return aafm_generic_error();
	}
	return (array) $updated;
}
