<?php
/**
 * WooCommerce integration abilities — customer reads and writes (sub-slice W4-WC3).
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

add_filter( 'oversio_abilities_registry', 'oversio_register_wc_customers_definitions' );
add_filter( 'oversio_abilities_registry_integrations', 'oversio_register_wc_customers_full_definitions' );

/**
 * Contribute the WooCommerce customers definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_customers_definitions( array $registry ): array {
	if ( ! oversio_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, oversio_wc_customers_registry_definitions() );
}

/**
 * Contribute the WooCommerce customer definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (oversio_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_wc_customers_full_definitions( array $registry ): array {
	return array_merge( $registry, oversio_wc_customers_registry_definitions() );
}

/**
 * The WooCommerce customer registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_wc_customers_registry_definitions(): array {
	return array(
		// Customers (sub-slice W4-WC3) — PII-exposing abilities gated on manage_woocommerce.
		'oversio/wc-list-customers'  => array(
			'label'        => __( 'List WooCommerce customers', 'oversio-agent-abilities' ),
			'description'  => __( 'Lists WooCommerce customers with their id, email, name, username, order count, and total spent. Customer email is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_list_customers',
		),

		'oversio/wc-get-customer'    => array(
			'label'        => __( 'Get WooCommerce customer', 'oversio-agent-abilities' ),
			'description'  => __( 'Reads one WooCommerce customer by id, including email, name, username, order count, total spent, date created, and the full billing address (including phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_get_customer',
		),

		'oversio/wc-create-customer' => array(
			'label'        => __( 'Create WooCommerce customer', 'oversio-agent-abilities' ),
			'description'  => __( 'Creates a WooCommerce customer from an email and username, with optional first name, last name, and billing/shipping address. Returns the full customer shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_create_customer',
		),

		'oversio/wc-update-customer' => array(
			'label'        => __( 'Update WooCommerce customer', 'oversio-agent-abilities' ),
			'description'  => __( 'Updates a WooCommerce customer by id, changing only the fields you send. An empty request body is a no-op success. Returns the full customer shape. Requires the manage-WooCommerce capability.', 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'oversio_args_wc_update_customer',
		),

	);
}

// =============================================================================
// WC3 -- Customers: list, get, create, update
// All abilities gate on the flat, object-independent manage_woocommerce cap
// (oversio_wc_perm). None needs a server.php case — they fall through at discovery.
// Customer PII (email, billing phone, billing/shipping addresses) is returned in
// full under the Integrations security disclaimer (oversio_woocommerce_disclaimer).
// =============================================================================

// -------------------------------------------------------------------------
// Shared helpers
// -------------------------------------------------------------------------

/**
 * Resolve a customer id to a WC_Customer object, or null when the id is unknown or WooCommerce
 * is unavailable.
 *
 * @param int $id Customer id.
 * @return \WC_Customer|null
 */
function oversio_wc_get_customer_object( int $id ): ?\WC_Customer {
	if ( ! class_exists( 'WC_Customer' ) ) {
		return null;
	}
	// WooCommerce exposes no wc_get_customer() helper; instantiate WC_Customer directly. A
	// non-existent id leaves the object empty, so get_id() returns 0 — treat that as "not found".
	// WC_Customer's constructor already catches the data-store "Invalid customer" exception and
	// zeroes the id, but guard the call so any other WC version/edge case returns not-found rather
	// than surfacing a raw exception.
	try {
		$customer = new \WC_Customer( $id );
	} catch ( \Exception $e ) {
		return null;
	}
	if ( $customer->get_id() !== $id ) {
		return null;
	}
	return $customer;
}

/**
 * The shared billing address schema properties (used in input and output schemas).
 *
 * @return array<string,mixed>
 */
function oversio_wc_customer_billing_properties(): array {
	return array(
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
	);
}

/**
 * The shared shipping address schema properties (used in input and output schemas).
 *
 * @return array<string,mixed>
 */
function oversio_wc_customer_shipping_properties(): array {
	return array(
		'first_name' => array( 'type' => 'string' ),
		'last_name'  => array( 'type' => 'string' ),
		'company'    => array( 'type' => 'string' ),
		'address_1'  => array( 'type' => 'string' ),
		'address_2'  => array( 'type' => 'string' ),
		'city'       => array( 'type' => 'string' ),
		'state'      => array( 'type' => 'string' ),
		'postcode'   => array( 'type' => 'string' ),
		'country'    => array( 'type' => 'string' ),
	);
}

/**
 * The shared writable customer properties (create + update input schemas).
 *
 * Both billing{} and shipping{} carry additionalProperties:false (MEDIUM-4 closed-schema
 * security control): any key outside the declared set — e.g. billing.role — is rejected by
 * the Abilities API before the executor runs, so a nested-smuggle attack cannot bypass the
 * field-level sanitise layer.
 *
 * @return array<string,mixed>
 */
function oversio_wc_customer_write_properties(): array {
	return array(
		'first_name' => array( 'type' => 'string' ),
		'last_name'  => array( 'type' => 'string' ),
		'billing'    => array(
			'type'                 => 'object',
			'properties'           => oversio_wc_customer_billing_properties(),
			'additionalProperties' => false,
		),
		'shipping'   => array(
			'type'                 => 'object',
			'properties'           => oversio_wc_customer_shipping_properties(),
			'additionalProperties' => false,
		),
	);
}

/**
 * The full customer output shape (list row is a lean subset of this).
 *
 * @return array<string,mixed>
 */
function oversio_wc_customer_output_properties(): array {
	return array(
		'id'           => array( 'type' => 'integer' ),
		'email'        => array( 'type' => 'string' ),
		'first_name'   => array( 'type' => 'string' ),
		'last_name'    => array( 'type' => 'string' ),
		'username'     => array( 'type' => 'string' ),
		'orders_count' => array( 'type' => 'integer' ),
		'total_spent'  => array( 'type' => 'string' ),
		'date_created' => array( 'type' => array( 'string', 'null' ) ),
		'billing'      => array(
			'type'                 => 'object',
			'properties'           => oversio_wc_customer_billing_properties(),
			'additionalProperties' => false,
		),
		'shipping'     => array(
			'type'                 => 'object',
			'properties'           => oversio_wc_customer_shipping_properties(),
			'additionalProperties' => false,
		),
	);
}

/**
 * Assemble the lean list-row shape for one customer (no address details).
 *
 * @param \WC_Customer $customer Customer object.
 * @return array<string,mixed>
 */
function oversio_redact_wc_customer( \WC_Customer $customer ): array {
	return array(
		'id'           => $customer->get_id(),
		'email'        => $customer->get_email(),
		'first_name'   => $customer->get_first_name(),
		'last_name'    => $customer->get_last_name(),
		'username'     => $customer->get_username(),
		'orders_count' => $customer->get_order_count(),
		'total_spent'  => $customer->get_total_spent(),
	);
}

/**
 * Assemble the full rich customer shape including billing/shipping addresses.
 *
 * Billing and shipping objects always return as objects ({}) even when all fields are empty,
 * matching the empty-map contract: (object) array() prevents JSON serialisation as [].
 *
 * @param \WC_Customer $customer Customer object.
 * @return array<string,mixed>
 */
function oversio_rich_wc_customer( \WC_Customer $customer ): array {
	$date_created = $customer->get_date_created();

	$billing          = array(
		'first_name' => $customer->get_billing_first_name(),
		'last_name'  => $customer->get_billing_last_name(),
		'company'    => $customer->get_billing_company(),
		'address_1'  => $customer->get_billing_address_1(),
		'address_2'  => $customer->get_billing_address_2(),
		'city'       => $customer->get_billing_city(),
		'state'      => $customer->get_billing_state(),
		'postcode'   => $customer->get_billing_postcode(),
		'country'    => $customer->get_billing_country(),
		'email'      => $customer->get_billing_email(),
		'phone'      => $customer->get_billing_phone(),
	);
	$is_billing_empty = '' === implode( '', $billing );

	$shipping          = array(
		'first_name' => $customer->get_shipping_first_name(),
		'last_name'  => $customer->get_shipping_last_name(),
		'company'    => $customer->get_shipping_company(),
		'address_1'  => $customer->get_shipping_address_1(),
		'address_2'  => $customer->get_shipping_address_2(),
		'city'       => $customer->get_shipping_city(),
		'state'      => $customer->get_shipping_state(),
		'postcode'   => $customer->get_shipping_postcode(),
		'country'    => $customer->get_shipping_country(),
	);
	$is_shipping_empty = '' === implode( '', $shipping );

	return array(
		'id'           => $customer->get_id(),
		'email'        => $customer->get_email(),
		'first_name'   => $customer->get_first_name(),
		'last_name'    => $customer->get_last_name(),
		'username'     => $customer->get_username(),
		'orders_count' => $customer->get_order_count(),
		'total_spent'  => $customer->get_total_spent(),
		'date_created' => oversio_wc_date_string( $customer->get_date_created() ),
		'billing'      => $is_billing_empty ? (object) array() : $billing,
		'shipping'     => $is_shipping_empty ? (object) array() : $shipping,
	);
}

/**
 * Apply validated write-input fields to a WC_Customer instance (shared by create + update).
 *
 * Only keys present in $input are applied — missing keys are not zeroed — so update is a true
 * partial PATCH and create only sets what was provided.
 *
 * @param \WC_Customer        $customer Customer object to mutate.
 * @param array<string,mixed> $input    Validated input.
 * @return void
 */
function oversio_wc_apply_customer_input( \WC_Customer $customer, array $input ): void {
	if ( array_key_exists( 'first_name', $input ) ) {
		$customer->set_first_name( sanitize_text_field( (string) $input['first_name'] ) );
	}
	if ( array_key_exists( 'last_name', $input ) ) {
		$customer->set_last_name( sanitize_text_field( (string) $input['last_name'] ) );
	}

	if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
		$b = $input['billing'];
		if ( array_key_exists( 'first_name', $b ) ) {
			$customer->set_billing_first_name( sanitize_text_field( (string) $b['first_name'] ) ); }
		if ( array_key_exists( 'last_name', $b ) ) {
			$customer->set_billing_last_name( sanitize_text_field( (string) $b['last_name'] ) ); }
		if ( array_key_exists( 'company', $b ) ) {
			$customer->set_billing_company( sanitize_text_field( (string) $b['company'] ) ); }
		if ( array_key_exists( 'address_1', $b ) ) {
			$customer->set_billing_address_1( sanitize_text_field( (string) $b['address_1'] ) ); }
		if ( array_key_exists( 'address_2', $b ) ) {
			$customer->set_billing_address_2( sanitize_text_field( (string) $b['address_2'] ) ); }
		if ( array_key_exists( 'city', $b ) ) {
			$customer->set_billing_city( sanitize_text_field( (string) $b['city'] ) ); }
		if ( array_key_exists( 'state', $b ) ) {
			$customer->set_billing_state( sanitize_text_field( (string) $b['state'] ) ); }
		if ( array_key_exists( 'postcode', $b ) ) {
			$customer->set_billing_postcode( sanitize_text_field( (string) $b['postcode'] ) ); }
		if ( array_key_exists( 'country', $b ) ) {
			$customer->set_billing_country( sanitize_text_field( (string) $b['country'] ) ); }
		if ( array_key_exists( 'email', $b ) ) {
			$customer->set_billing_email( sanitize_email( (string) $b['email'] ) ); }
		if ( array_key_exists( 'phone', $b ) ) {
			$customer->set_billing_phone( sanitize_text_field( (string) $b['phone'] ) ); }
	}

	if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
		$s = $input['shipping'];
		if ( array_key_exists( 'first_name', $s ) ) {
			$customer->set_shipping_first_name( sanitize_text_field( (string) $s['first_name'] ) ); }
		if ( array_key_exists( 'last_name', $s ) ) {
			$customer->set_shipping_last_name( sanitize_text_field( (string) $s['last_name'] ) ); }
		if ( array_key_exists( 'company', $s ) ) {
			$customer->set_shipping_company( sanitize_text_field( (string) $s['company'] ) ); }
		if ( array_key_exists( 'address_1', $s ) ) {
			$customer->set_shipping_address_1( sanitize_text_field( (string) $s['address_1'] ) ); }
		if ( array_key_exists( 'address_2', $s ) ) {
			$customer->set_shipping_address_2( sanitize_text_field( (string) $s['address_2'] ) ); }
		if ( array_key_exists( 'city', $s ) ) {
			$customer->set_shipping_city( sanitize_text_field( (string) $s['city'] ) ); }
		if ( array_key_exists( 'state', $s ) ) {
			$customer->set_shipping_state( sanitize_text_field( (string) $s['state'] ) ); }
		if ( array_key_exists( 'postcode', $s ) ) {
			$customer->set_shipping_postcode( sanitize_text_field( (string) $s['postcode'] ) ); }
		if ( array_key_exists( 'country', $s ) ) {
			$customer->set_shipping_country( sanitize_text_field( (string) $s['country'] ) ); }
	}
}

// oversio/wc-list-customers (R).

/**
 * Args builder for oversio/wc-list-customers.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_list_customers(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-list-customers' ),
		'description'         => oversio_ability_description( 'oversio/wc-list-customers' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'customers' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => array( 'type' => 'integer' ),
							'email'        => array( 'type' => 'string' ),
							'first_name'   => array( 'type' => 'string' ),
							'last_name'    => array( 'type' => 'string' ),
							'username'     => array( 'type' => 'string' ),
							'orders_count' => array( 'type' => 'integer' ),
							'total_spent'  => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'total'     => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_wc_list_customers',
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
 * Execute oversio/wc-list-customers.
 *
 * Queries customers via wc_get_customers() (real WooCommerce) or WcCustomerStubStore (tests).
 * Each customer is mapped through the lean oversio_redact_wc_customer() shape — no addresses.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function oversio_exec_wc_list_customers( array $input ): array {
	if ( ! function_exists( 'wc_get_customers' ) ) {
		return array(
			'customers' => array(),
			'total'     => 0,
		);
	}

	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 10;

	$args = array(
		'limit'    => $per_page,
		'paged'    => $page,
		'paginate' => true,
	);

	$query   = wc_get_customers( $args );
	$objects = is_object( $query ) && isset( $query->results ) ? $query->results : ( is_array( $query ) ? $query : array() );
	$total   = is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $objects );

	$customers = array();
	foreach ( $objects as $customer ) {
		if ( $customer instanceof \WC_Customer ) {
			$customers[] = oversio_redact_wc_customer( $customer );
		}
	}

	return array(
		'customers' => $customers,
		'total'     => $total,
	);
}

// oversio/wc-get-customer (R).

/**
 * Args builder for oversio/wc-get-customer.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_get_customer(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/wc-get-customer' ),
		'description'         => oversio_ability_description( 'oversio/wc-get-customer' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'customer_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'customer_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_customer_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_get_customer',
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
 * Execute oversio/wc-get-customer.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_get_customer( array $input ) {
	$customer = oversio_wc_get_customer_object( (int) ( $input['customer_id'] ?? 0 ) );
	if ( null === $customer ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_customer( $customer );
}

// oversio/wc-create-customer (W).

/**
 * Args builder for oversio/wc-create-customer.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_create_customer(): array {
	$properties             = oversio_wc_customer_write_properties();
	$properties['email']    = array( 'type' => 'string' );
	$properties['username'] = array( 'type' => 'string' );

	return array(
		'label'               => oversio_ability_label( 'oversio/wc-create-customer' ),
		'description'         => oversio_ability_description( 'oversio/wc-create-customer' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'email' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_customer_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_create_customer',
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
 * Execute oversio/wc-create-customer.
 *
 * Creates via wc_create_new_customer(), then applies optional address fields and saves.
 * Returns WP_Error on any failure.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_create_customer( array $input ) {
	if ( ! function_exists( 'wc_create_new_customer' ) ) {
		return oversio_generic_error();
	}

	$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$username = sanitize_user( (string) ( $input['username'] ?? $email ) );

	// wc_create_new_customer() returns the new user id as an int, or a WP_Error — never a
	// WC_Customer object. Treat any non-positive / WP_Error result as a failure so a real
	// create error can't be misread as success (and a real success can't be misread as a
	// failure after the account is already persisted).
	$created = wc_create_new_customer( $email, $username, wp_generate_password() );
	if ( $created instanceof \WP_Error ) {
		return oversio_generic_error();
	}
	$id = (int) $created;
	if ( $id < 1 ) {
		return oversio_generic_error();
	}

	// Hydrate the persisted customer, layer on the optional address fields, and save once.
	$customer = oversio_wc_get_customer_object( $id );
	if ( null === $customer ) {
		return oversio_generic_error();
	}
	oversio_wc_apply_customer_input( $customer, $input );
	if ( (int) $customer->save() < 1 ) {
		return oversio_generic_error();
	}

	$saved = oversio_wc_get_customer_object( $id );
	if ( null === $saved ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_customer( $saved );
}

// oversio/wc-update-customer (W).

/**
 * Args builder for oversio/wc-update-customer.
 *
 * @return array<string,mixed>
 */
function oversio_args_wc_update_customer(): array {
	$properties                = oversio_wc_customer_write_properties();
	$properties['customer_id'] = array(
		'type'    => 'integer',
		'minimum' => 1,
	);

	return array(
		'label'               => oversio_ability_label( 'oversio/wc-update-customer' ),
		'description'         => oversio_ability_description( 'oversio/wc-update-customer' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'customer_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_wc_customer_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_wc_update_customer',
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
 * Execute oversio/wc-update-customer.
 *
 * Loads the existing customer, applies only the keys present in $input, saves, then
 * returns the fresh rich shape. An input carrying only customer_id is a no-op success.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function oversio_exec_wc_update_customer( array $input ) {
	$id       = (int) ( $input['customer_id'] ?? 0 );
	$customer = oversio_wc_get_customer_object( $id );
	if ( null === $customer ) {
		return oversio_generic_error();
	}

	oversio_wc_apply_customer_input( $customer, $input );
	$saved_id = (int) $customer->save();
	if ( $saved_id < 1 ) {
		return oversio_generic_error();
	}

	$saved = oversio_wc_get_customer_object( $id );
	if ( null === $saved ) {
		return oversio_generic_error();
	}
	return oversio_rich_wc_customer( $saved );
}
