<?php
/**
 * WooCommerce integration abilities - coupon reads and writes (sub-slice W4-WC4).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_coupons_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_coupons_full_definitions' );

/**
 * Contribute the WooCommerce coupons definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_coupons_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_coupons_registry_definitions() );
}

/**
 * Contribute the WooCommerce coupon definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_coupons_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_coupons_registry_definitions() );
}

/**
 * The WooCommerce coupon registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder - consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_coupons_registry_definitions(): array {
	return array(
		// Coupons (sub-slice W4-WC4) - discount/promotion management gated on manage_woocommerce.
		'aafm/wc-list-coupons'  => array(
			'label'        => __( 'List WooCommerce coupons', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists WooCommerce coupons with their id, code, amount, discount type, expiry date, and usage count, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_coupons',
		),

		'aafm/wc-get-coupon'    => array(
			'label'        => __( 'Get WooCommerce coupon', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one WooCommerce coupon by id: code, amount, discount type, expiry, usage limits, spend limits, product and email restrictions, and other config. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_coupon',
		),

		'aafm/wc-create-coupon' => array(
			'label'        => __( 'Create WooCommerce coupon', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Creates a WooCommerce coupon from a code and discount type, with optional amount, usage limits, spend limits, product restrictions, and email restrictions. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_create_coupon',
		),

		'aafm/wc-update-coupon' => array(
			'label'        => __( 'Update WooCommerce coupon', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a WooCommerce coupon by id, changing only the fields you send. An empty request body is a no-op success. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_coupon',
		),

	);
}

// =============================================================================
// WC4 -- Coupons: list, get, create, update
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
		'code'                 => array(
			'type'        => 'string',
			'description' => __( 'The coupon code customers enter at checkout. WooCommerce requires codes to be unique.', 'agent-abilities-for-mcp' ),
		),
		'amount'               => array(
			'type'        => 'string',
			'description' => __( 'The discount amount as a decimal string, e.g. "10" for a 10% discount when discount_type is percent, or a currency amount for fixed_cart / fixed_product.', 'agent-abilities-for-mcp' ),
		),
		'discount_type'        => array(
			'type'        => 'string',
			'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
			'description' => 'How the coupon discounts: percent (a percentage of the cart), fixed_cart (a fixed amount off the whole cart), or fixed_product (a fixed amount off each matching product).',
		),
		'description'          => array(
			'type'        => 'string',
			'description' => __( 'A description of the coupon, for admin reference.', 'agent-abilities-for-mcp' ),
		),
		'date_expires'         => array(
			'type'        => array( 'string', 'null' ),
			'description' => 'Expiry date as a YYYY-MM-DD string (the coupon expires at the start of that day, site timezone). Pass null or an empty string for no expiry.',
		),
		'usage_limit'          => array(
			'type'        => array( 'integer', 'null' ),
			'description' => __( 'Maximum number of times this coupon can be used in total, across all customers. Pass null for unlimited.', 'agent-abilities-for-mcp' ),
		),
		'usage_limit_per_user' => array(
			'type'        => array( 'integer', 'null' ),
			'description' => __( 'Maximum number of times a single customer can use this coupon. Pass null for unlimited.', 'agent-abilities-for-mcp' ),
		),
		'minimum_amount'       => array(
			'type'        => 'string',
			'description' => __( 'Minimum cart subtotal required for the coupon to apply, as a decimal string, e.g. "50.00".', 'agent-abilities-for-mcp' ),
		),
		'maximum_amount'       => array(
			'type'        => 'string',
			'description' => __( 'Maximum cart subtotal allowed for the coupon to apply, as a decimal string.', 'agent-abilities-for-mcp' ),
		),
		'individual_use'       => array(
			'type'        => 'boolean',
			'description' => __( 'When true, this coupon cannot be combined with other coupons in the same order.', 'agent-abilities-for-mcp' ),
		),
		'exclude_sale_items'   => array(
			'type'        => 'boolean',
			'description' => __( "When true, products already on sale are excluded from this coupon's discount.", 'agent-abilities-for-mcp' ),
		),
		'product_ids'          => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( 'Product ids this coupon is restricted to. Leave empty for no product restriction.', 'agent-abilities-for-mcp' ),
		),
		'excluded_product_ids' => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( "Product ids excluded from this coupon's discount.", 'agent-abilities-for-mcp' ),
		),
		'email_restrictions'   => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Email addresses allowed to use this coupon. Leave empty for no restriction.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Apply validated write-input fields to a WC_Coupon instance (shared by create and update).
 *
 * Only the keys present in $input are applied; absent keys leave the coupon unchanged, which is
 * what makes an empty PATCH a no-op.
 *
 * WC_Coupon::set_code() does NOT throw on a duplicate code - verified directly against this
 * WooCommerce version: it only calls set_prop(). The duplicate-code guard core itself uses lives
 * solely in WC_REST_Coupons_V1/V2_Controller, never in the WC_Coupon object this ability calls
 * directly, so two coupon posts with the identical code both save without error today. This
 * ports that REST-controller check (wc_get_coupon_id_by_code(), excluding the coupon's own id so
 * an update that keeps its own code is not a false collision) rather than catching an exception
 * that does not exist here.
 *
 * @param \WC_Coupon          $coupon Coupon object to mutate.
 * @param array<string,mixed> $input  Validated input fields (coupon_id already extracted).
 * @return \WP_Error|null Null on success, or a WP_Error naming the conflicting code.
 */
function aafm_wc_apply_coupon_input( \WC_Coupon $coupon, array $input ): ?\WP_Error {
	if ( array_key_exists( 'code', $input ) ) {
		$code           = wc_format_coupon_code( sanitize_text_field( (string) $input['code'] ) );
		$conflicting_id = wc_get_coupon_id_by_code( $code, $coupon->get_id() );
		if ( $conflicting_id ) {
			return new \WP_Error(
				'aafm_wc_duplicate_coupon_code',
				sprintf(
					/* translators: %s: the conflicting coupon code. */
					__( 'The coupon code "%s" is already in use by another coupon.', 'agent-abilities-for-mcp' ),
					$code
				)
			);
		}
		$coupon->set_code( $code );
	}
	if ( array_key_exists( 'amount', $input ) ) {
		$amount = sanitize_text_field( (string) $input['amount'] );
		try {
			// WooCommerce's own set_amount() (class-wc-coupon.php:617-632) uses a throw as
			// ordinary input validation: it rejects a negative amount outright, and separately
			// rejects an amount over 100 once the coupon's discount_type is 'percent'. The
			// amount input schema is a bare string with no pattern/minimum/maximum, so both
			// conditions can reach this setter unfiltered. Catch here and return a WP_Error
			// instead of letting WC_Data_Exception escape - the same pattern already used for
			// set_sku() in aafm_wc_apply_variation_input() (variations.php) and for the
			// duplicate-code guard a few lines above in this same function.
			$coupon->set_amount( $amount );
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error(
				'aafm_wc_invalid_coupon_amount',
				sprintf(
					/* translators: %s: the rejected amount value. */
					__( 'The discount amount "%s" is not valid. It must not be negative, and a percentage coupon cannot exceed 100.', 'agent-abilities-for-mcp' ),
					$amount
				)
			);
		}
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
	return null;
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
		'label'               => aafm_ability_label( 'aafm/wc-list-coupons' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-coupons' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Number of coupons per page, 1 to 100. Defaults to 10.', 'agent-abilities-for-mcp' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Page number of coupons to return, 1-indexed. Defaults to 1.', 'agent-abilities-for-mcp' ),
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
				'idempotent'  => true,
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
	if ( ! class_exists( 'WC_Coupon' ) ) {
		return aafm_generic_error();
	}

	$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 10;
	$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;

	// WooCommerce has no wc_get_coupons(): coupons are the 'shop_coupon' post type. Query the ids,
	// then hydrate each through WC_Coupon to read fields via the public getter API.
	$query = new \WP_Query(
		array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);

	$rows = array();
	foreach ( $query->posts as $coupon_id ) {
		// fields => 'ids' yields post ids; absint() also normalises the int|WP_Post union safely.
		$coupon = aafm_wc_get_coupon_object( is_object( $coupon_id ) ? (int) $coupon_id->ID : absint( $coupon_id ) );
		if ( null !== $coupon ) {
			$rows[] = aafm_redact_wc_coupon( $coupon );
		}
	}

	return array(
		'coupons' => $rows,
		'total'   => (int) $query->found_posts,
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
		'label'               => aafm_ability_label( 'aafm/wc-get-coupon' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-coupon' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'coupon_id' ),
			'properties'           => array(
				'coupon_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( "The WooCommerce coupon's post id.", 'agent-abilities-for-mcp' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/wc-create-coupon' ),
		'description'         => aafm_ability_description( 'aafm/wc-create-coupon' ),
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
	$error  = aafm_wc_apply_coupon_input( $coupon, $input );
	if ( null !== $error ) {
		return $error;
	}

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
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'The coupon to update. An empty request body besides this id is a no-op success.', 'agent-abilities-for-mcp' ),
	);

	return array(
		'label'               => aafm_ability_label( 'aafm/wc-update-coupon' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-coupon' ),
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
	$error = aafm_wc_apply_coupon_input( $coupon, $fields );
	if ( null !== $error ) {
		return $error;
	}
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
