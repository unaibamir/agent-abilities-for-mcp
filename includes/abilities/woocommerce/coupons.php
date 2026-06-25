<?php
/**
 * WooCommerce integration abilities — coupon reads and writes (sub-slice W4-WC4).
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

add_filter( 'oversio_abilities_registry', 'oversio_register_wc_coupons_definitions' );
add_filter( 'oversio_abilities_registry_integrations', 'oversio_register_wc_coupons_full_definitions' );

/**
 * Contribute the WooCommerce coupons definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_coupons_definitions( array $registry ): array {
	if ( ! oversio_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, oversio_wc_coupons_registry_definitions() );
}

/**
 * Contribute the WooCommerce coupon definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (oversio_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_coupons_full_definitions( array $registry ): array {
	return array_merge( $registry, oversio_wc_coupons_registry_definitions() );
}

/**
 * The WooCommerce coupon registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_wc_coupons_registry_definitions(): array {
	return array(
		// Coupons (sub-slice W4-WC4) — discount/promotion management gated on manage_woocommerce.
		'oversio/wc-list-coupons'  => array(
			'label'        => __( 'List WooCommerce coupons', 'oversio-agent-abilities' ),
			'description'  => __( 'Lists WooCommerce coupons with their id, code, amount, discount type, expiry date, and usage count, plus a total. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_list_coupons',
		),

		'oversio/wc-get-coupon'    => array(
			'label'        => __( 'Get WooCommerce coupon', 'oversio-agent-abilities' ),
			'description'  => __( 'Reads one WooCommerce coupon by id: code, amount, discount type, expiry, usage limits, spend limits, product and email restrictions, and other config. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_get_coupon',
		),

		'oversio/wc-create-coupon' => array(
			'label'        => __( 'Create WooCommerce coupon', 'oversio-agent-abilities' ),
			'description'  => __( 'Creates a WooCommerce coupon from a code and discount type, with optional amount, usage limits, spend limits, product restrictions, and email restrictions. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_create_coupon',
		),

		'oversio/wc-update-coupon' => array(
			'label'        => __( 'Update WooCommerce coupon', 'oversio-agent-abilities' ),
			'description'  => __( 'Updates a WooCommerce coupon by id, changing only the fields you send. An empty request body is a no-op success. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_update_coupon',
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
function oversio_wc_get_coupon_object( int $id ): ?\WC_Coupon {
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
function oversio_redact_wc_coupon( \WC_Coupon $coupon ): array {
	return array(
		'id'            => $coupon->get_id(),
		'code'          => $coupon->get_code(),
		'amount'        => $coupon->get_amount(),
		'discount_type' => $coupon->get_discount_type(),
		'date_expires'  => oversio_wc_date_string( $coupon->get_date_expires() ),
		'usage_count'   => $coupon->get_usage_count(),
	);
}

/**
 * Build the full coupon shape: all config fields including product/email restrictions.
 *
 * @param \WC_Coupon $coupon Coupon object.
 * @return array<string,mixed>
 */
function oversio_rich_wc_coupon( \WC_Coupon $coupon ): array {
	return array(
		'id'                   => $coupon->get_id(),
		'code'                 => $coupon->get_code(),
		'amount'               => $coupon->get_amount(),
		'discount_type'        => $coupon->get_discount_type(),
		'description'          => $coupon->get_description(),
		'date_expires'         => oversio_wc_date_string( $coupon->get_date_expires() ),
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
function oversio_wc_coupon_output_properties(): array {
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
function oversio_wc_coupon_write_properties(): array {
	return array(
		'code'                 => array( 'type' => 'string' ),
		'amount'               => array( 'type' => 'string' ),
		'discount_type'        => array(
			'type'        => 'string',
			'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
			'description' => 'How the coupon discounts: percent (a percentage of the cart), fixed_cart (a fixed amount off the whole cart), or fixed_product (a fixed amount off each matching product).',
		),
		'description'          => array( 'type' => 'string' ),
		'date_expires'         => array(
			'type'        => array( 'string', 'null' ),
			'description' => 'Expiry date as a YYYY-MM-DD string (the coupon expires at the start of that day, site timezone). Pass null or an empty string for no expiry.',
		),
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
 * Apply validated write-input fields to a WC_Coupon instance (shared by create and update).
 *
 * Only the keys present in $input are applied; absent keys leave the coupon unchanged, which is
 * what makes an empty PATCH a no-op.
 *
 * @param \WC_Coupon          $coupon Coupon object to mutate.
 * @param array<string,mixed> $input  Validated input fields (coupon_id already extracted).
 * @return void
 */
function oversio_wc_apply_coupon_input( \WC_Coupon $coupon, array $input ): void {
	if ( array_key_exists( 'code', $input ) ) {
		$coupon->set_code( sanitize_text_field( (string) $input['code'] ) );
	}
	if ( array_key_exists( 'amount', $input ) ) {
		$coupon->set_amount( sanitize_text_field( (string) $input['amount'] ) );
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
}

// =============================================================================
// wc-list-coupons
// =============================================================================

/**
 * Args builder for oversio/wc-list-coupons.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_list_coupons(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-list-coupons' ),
		'description'         => oversio_ability_description( 'oversio/wc-list-coupons' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		'execute_callback'    => 'oversio_exec_wc_list_coupons',
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
 * Execute oversio/wc-list-coupons.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_list_coupons( array $input ) {
	if ( ! class_exists( 'WC_Coupon' ) ) {
		return oversio_generic_error();
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
		$coupon = oversio_wc_get_coupon_object( is_object( $coupon_id ) ? (int) $coupon_id->ID : absint( $coupon_id ) );
		if ( null !== $coupon ) {
			$rows[] = oversio_redact_wc_coupon( $coupon );
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
 * Args builder for oversio/wc-get-coupon.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_get_coupon(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-get-coupon' ),
		'description'         => oversio_ability_description( 'oversio/wc-get-coupon' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'coupon_id' ),
			'properties'           => array(
				'coupon_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_coupon_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_get_coupon',
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
 * Execute oversio/wc-get-coupon.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_get_coupon( array $input ) {
	$coupon = oversio_wc_get_coupon_object( absint( $input['coupon_id'] ?? 0 ) );
	if ( null === $coupon ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_coupon( $coupon );
}

// =============================================================================
// wc-create-coupon
// =============================================================================

/**
 * Args builder for oversio/wc-create-coupon.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_create_coupon(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-create-coupon' ),
		'description'         => oversio_ability_description( 'oversio/wc-create-coupon' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'code' ),
			'properties'           => oversio_wc_coupon_write_properties(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_coupon_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_create_coupon',
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
 * Execute oversio/wc-create-coupon.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_create_coupon( array $input ) {
	if ( ! class_exists( 'WC_Coupon' ) ) {
		return oversio_generic_error();
	}

	$coupon = new \WC_Coupon();
	oversio_wc_apply_coupon_input( $coupon, $input );

	$id = $coupon->save();
	if ( ! $id ) {
		return oversio_generic_error();
	}

	$saved = oversio_wc_get_coupon_object( $id );
	if ( null === $saved ) {
		return oversio_generic_error();
	}

	return oversio_rich_wc_coupon( $saved );
}

// =============================================================================
// wc-update-coupon
// =============================================================================

/**
 * Args builder for oversio/wc-update-coupon.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_update_coupon(): array {
	$write_props              = oversio_wc_coupon_write_properties();
	$write_props['coupon_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);

	return array(
		'label'               => oversio_ability_label( 'oversio/wc-update-coupon' ),
		'description'         => oversio_ability_description( 'oversio/wc-update-coupon' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'coupon_id' ),
			'properties'           => $write_props,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_coupon_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_update_coupon',
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
 * Execute oversio/wc-update-coupon.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_update_coupon( array $input ) {
	$id     = absint( $input['coupon_id'] ?? 0 );
	$coupon = oversio_wc_get_coupon_object( $id );
	if ( null === $coupon ) {
		return oversio_generic_error();
	}

	// Strip the routing key before diffing so an id-only PATCH is a genuine no-op.
	$fields = $input;
	unset( $fields['coupon_id'] );
	oversio_wc_apply_coupon_input( $coupon, $fields );
	$saved_id = (int) $coupon->save();
	if ( $saved_id < 1 ) {
		return oversio_generic_error();
	}

	$saved = oversio_wc_get_coupon_object( $id );
	if ( null === $saved ) {
		return oversio_generic_error();
	}

	return oversio_rich_wc_coupon( $saved );
}
