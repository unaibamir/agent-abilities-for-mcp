<?php
/**
 * WooCommerce integration abilities — payment gateway reads and writes (sub-slice W4-WC7).
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

add_filter( 'aafm_abilities_registry', 'aafm_register_wc_gateways_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wc_gateways_full_definitions' );

/**
 * Contribute the WooCommerce gateways definitions to the registry, but only when WooCommerce is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_gateways_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_wc_gateways_registry_definitions() );
}

/**
 * Contribute the WooCommerce payment gateway definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every
 * WooCommerce ability even when WooCommerce is inactive, for the Integrations tab and the manifest.
 * The live registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wc_gateways_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wc_gateways_registry_definitions() );
}

/**
 * The WooCommerce payment gateway registry rows, keyed by ability name. The single source of truth for
 * these abilities' label, description, group, risk, and args builder — consumed by both the
 * host-guarded live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wc_gateways_registry_definitions(): array {
	return array(
		'aafm/wc-list-payment-gateways'  => array(
			'label'        => __( 'List WooCommerce payment gateways', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists all registered WooCommerce payment gateways with their id, title, and enabled state. Secret or credential settings are never returned. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_list_payment_gateways',
		),

		'aafm/wc-get-payment-gateway'    => array(
			'label'        => __( 'Get WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads one WooCommerce payment gateway by id, including its title, description, enabled state, order, and non-secret settings. Credential and key fields are always redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_get_payment_gateway',
		),

		'aafm/wc-update-payment-gateway' => array(
			'label'        => __( 'Update WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Updates a WooCommerce payment gateway by id, changing only the fields you send: enabled state, title, description, or display order. Returns the updated gateway shape with secrets redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'woocommerce',
			'args_builder' => 'aafm_args_wc_update_payment_gateway',
		),
	);
}

// =============================================================================
// WC7 helpers — redaction + gateway shape
// =============================================================================

/**
 * Recursively strip secret/credential fields from an arbitrary settings array.
 *
 * Deny-by-default at every depth: any key whose name matches the secret pattern is dropped,
 * and the walk recurses into nested arrays so a credential hidden under a benign parent key
 * (or several levels down) can't slip through. The pattern covers the obvious credential
 * tokens (key, secret, token, password/pwd, api, private, auth, credential, signature/sign,
 * client_id) so a value stored under a slightly unconventional name is still caught.
 *
 * @param array<int|string,mixed> $settings Raw settings array (may be nested).
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_settings_deep( array $settings ): array {
	$secret_pattern = '/(?:key|secret|token|password|pwd|api|private|auth|credential|signature|sign|client[_-]?id)/i';
	$redacted       = array();
	foreach ( $settings as $key => $value ) {
		if ( preg_match( $secret_pattern, (string) $key ) ) {
			continue;
		}
		$redacted[ $key ] = is_array( $value ) ? aafm_wc_redact_settings_deep( $value ) : $value;
	}
	return $redacted;
}

/**
 * Redact secret/key/token/password fields from a gateway's settings array.
 *
 * Thin wrapper over the recursive deny-by-default redactor.
 *
 * @param array<string,mixed> $settings Raw gateway settings array.
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_gateway_settings( array $settings ): array {
	return aafm_wc_redact_settings_deep( $settings );
}

/**
 * Build the safe output shape for a payment gateway.
 *
 * Returns id, title, description, enabled (bool), order, and redacted settings.
 * Credential fields are stripped by aafm_wc_redact_gateway_settings().
 *
 * @param \WC_Payment_Gateway $gateway Payment gateway object.
 * @return array<string,mixed>
 */
function aafm_wc_gateway_shape( \WC_Payment_Gateway $gateway ): array {
	return array(
		'id'          => $gateway->id,
		'title'       => $gateway->title,
		'description' => $gateway->description,
		'enabled'     => 'yes' === $gateway->enabled,
		'order'       => (int) $gateway->order,
		'settings'    => aafm_wc_redact_gateway_settings( $gateway->settings ),
	);
}

// =============================================================================
// wc-list-payment-gateways
// =============================================================================

/**
 * Args builder for aafm/wc-list-payment-gateways.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_list_payment_gateways(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-list-payment-gateways' ),
		'description'         => aafm_ability_description( 'aafm/wc-list-payment-gateways' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'gateways' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'string' ),
							'title'   => array( 'type' => 'string' ),
							'enabled' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_list_payment_gateways',
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
 * Execute aafm/wc-list-payment-gateways.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_list_payment_gateways( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		return aafm_generic_error();
	}
	$gateways = \WC_Payment_Gateways::instance()->payment_gateways();
	$items    = array();
	foreach ( $gateways as $gateway ) {
		$items[] = array(
			'id'      => $gateway->id,
			'title'   => $gateway->title,
			'enabled' => 'yes' === $gateway->enabled,
		);
	}
	return array( 'gateways' => $items );
}

// =============================================================================
// wc-get-payment-gateway
// =============================================================================

/**
 * Args builder for aafm/wc-get-payment-gateway.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_get_payment_gateway(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-get-payment-gateway' ),
		'description'         => aafm_ability_description( 'aafm/wc-get-payment-gateway' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'gateway_id' ),
			'properties'           => array(
				'gateway_id' => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'order'       => array( 'type' => 'integer' ),
				'settings'    => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_get_payment_gateway',
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
 * Execute aafm/wc-get-payment-gateway.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_payment_gateway( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		return aafm_generic_error();
	}
	$gateway_id = sanitize_text_field( (string) ( $input['gateway_id'] ?? '' ) );
	$gateways   = \WC_Payment_Gateways::instance()->payment_gateways();
	if ( ! isset( $gateways[ $gateway_id ] ) ) {
		return new \WP_Error( 'aafm_not_found', __( 'Payment gateway not found.', 'agent-abilities-for-mcp' ) );
	}
	return aafm_wc_gateway_shape( $gateways[ $gateway_id ] );
}

// =============================================================================
// wc-update-payment-gateway
// =============================================================================

/**
 * Args builder for aafm/wc-update-payment-gateway.
 *
 * @return array<string,mixed>
 */
function aafm_args_wc_update_payment_gateway(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wc-update-payment-gateway' ),
		'description'         => aafm_ability_description( 'aafm/wc-update-payment-gateway' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'gateway_id' ),
			'properties'           => array(
				'gateway_id'  => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'order'       => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'order'       => array( 'type' => 'integer' ),
				'settings'    => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wc_update_payment_gateway',
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
 * Execute aafm/wc-update-payment-gateway.
 *
 * Updates only the fields provided: enabled, title, description, order. Audits deny when
 * the gateway id is unknown, success on a clean save. Secrets are redacted from the returned shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_payment_gateway( array $input ): array|\WP_Error {
	if ( ! aafm_integration_active( 'woocommerce' ) ) {
		return aafm_generic_error();
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		return aafm_generic_error();
	}
	$gateway_id = sanitize_text_field( (string) ( $input['gateway_id'] ?? '' ) );
	$gateways   = \WC_Payment_Gateways::instance()->payment_gateways();
	if ( ! isset( $gateways[ $gateway_id ] ) ) {
			return new \WP_Error( 'aafm_not_found', __( 'Payment gateway not found.', 'agent-abilities-for-mcp' ) );
	}
	$gateway = $gateways[ $gateway_id ];
	if ( isset( $input['enabled'] ) ) {
		$enabled_val      = $input['enabled'] ? 'yes' : 'no';
		$gateway->enabled = $enabled_val;
		$gateway->update_option( 'enabled', $enabled_val );
	}
	if ( isset( $input['title'] ) ) {
		$title_val      = sanitize_text_field( (string) $input['title'] );
		$gateway->title = $title_val;
		$gateway->update_option( 'title', $title_val );
	}
	if ( isset( $input['description'] ) ) {
		$desc_val             = sanitize_textarea_field( (string) $input['description'] );
		$gateway->description = $desc_val;
		$gateway->update_option( 'description', $desc_val );
	}
	if ( isset( $input['order'] ) ) {
		$gateway->order = (int) $input['order'];
	}
	$saved = $gateway->save();
	if ( false === $saved ) {
		return aafm_generic_error();
	}
	return aafm_wc_gateway_shape( $gateway );
}
