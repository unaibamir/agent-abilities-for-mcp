<?php
/**
 * WooCommerce integration abilities - payment gateway reads and writes (sub-slice W4-WC7).
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
 * these abilities' label, description, group, risk, and args builder - consumed by both the
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
			'description'  => __( 'Reads one WooCommerce payment gateway by id, including its title, description, enabled state, order, and non-secret settings. Common credential and key field names are redacted on a best-effort basis; a gateway that stores a secret under an unusual field name may not be caught. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
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
// WC7 helpers - redaction + gateway shape
// =============================================================================

/**
 * Recursively strip secret/credential fields from an arbitrary settings array.
 *
 * Deny-by-default at every depth: any key whose name matches the secret pattern is dropped,
 * and the walk recurses into nested arrays so a credential hidden under a benign parent key
 * (or several levels down) can't slip through. The pattern covers the obvious credential
 * tokens (key, secret, token, password/pwd, api, private, auth, credential, signature/sign,
 * client_id) plus a second, boundary-anchored group (passphrase, passwd/pass, salt, pin,
 * certificate/cert, pem, account, merchant, license, username/user, number). The group started
 * as (passphrase, salt, pin, certificate/cert, pem), added after an audit found the official
 * PayFast gateway storing its IPN-signing secret under the literal field name "passphrase"
 * (which the original pattern misses - it matches "password", not "pass") and PayU-style
 * gateways using "salt". Those two field names are live, not hypothetical: they are reachable
 * today through wc-get-payment-gateway, which is a read and is not in the high-risk locked set.
 * The 1.6.2 additions (passwd, pass, account, merchant, license, username, user, number) close
 * the gaps a security review found: the loose group's "pwd" does not match "passwd" (no
 * contiguous p-w-d), and nothing caught account_number, merchant_id, license, or username -
 * names carrier and gateway plugins actually use for credentials. Note the anchoring means the
 * longer forms are not redundant: anchored "pass" does not match "passwd" (the next character
 * is "w", not a separator), and anchored "user" does not match "username".
 *
 * The second group is boundary-anchored (start/end of the key, or an underscore/hyphen) rather
 * than a loose substring match, because "pin" as a bare substring also matches "shipping"
 * (s-h-i-PIN-g) and would silently strip a benign field on every shipping-method settings row
 * that reuses this same redactor. The original token group stays a loose substring match on
 * purpose: over-redacting a benign field is the safe direction, so it is left as-is rather than
 * risk narrowing it and un-redacting something a wp.org reviewer or a past audit already relied
 * on being caught.
 *
 * Honesty about the limit: this is a denylist over field NAMES chosen by third-party gateway and
 * shipping-method plugins this codebase has no visibility into, so it cannot be exhaustive - a
 * plugin that stores a live secret under a name outside this list still leaks it through. An
 * allowlist of "known-safe" field names was considered instead and rejected: a gateway or
 * shipping method's settings array has no fixed schema, so a safe allowlist would have to
 * enumerate every WooCommerce extension ever installed, and would go stale the moment a new one
 * ships. Treat this as best-effort, expanded as new gaps are found, not a completeness guarantee.
 *
 * @param array<int|string,mixed> $settings Raw settings array (may be nested).
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_settings_deep( array $settings ): array {
	$secret_pattern = '/(?:key|secret|token|password|pwd|api|private|auth|credential|signature|sign|client[_-]?id)|(?:^|[_-])(?:passphrase|passwd|pass|salt|pin|certificate|cert|pem|account|merchant|license|username|user|number)(?:[_-]|$)/i';
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
 * Thin wrapper over aafm_wc_redact_settings_deep() - a recursive DENYLIST over field names
 * (see that function's docblock above), not a deny-by-default redactor. Best-effort: it catches
 * names matching a known secret pattern and cannot be exhaustive.
 *
 * @param array<string,mixed> $settings Raw gateway settings array.
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_gateway_settings( array $settings ): array {
	return aafm_wc_redact_settings_deep( $settings );
}

/**
 * Compute a gateway's display order from its position in WooCommerce's own sorted gateway list.
 *
 * M13: WC_Payment_Gateway declares no `order` property - reading $gateway->order was always an
 * undefined dynamic property, so every gateway reported order:0. WooCommerce never stores order on
 * the gateway object either; WC_Payment_Gateways::init() derives it from the `woocommerce_gateway_order`
 * option (gateways with no stored preference are appended at the end) and hands back payment_gateways()
 * already sorted by it. Reading the real order back means locating this gateway's zero-based position
 * in that same sorted list, not re-deriving the option ourselves.
 *
 * @param string                            $gateway_id Gateway id to locate.
 * @param array<string,\WC_Payment_Gateway> $gateways   Sorted gateways keyed by id, from payment_gateways().
 * @return int Zero-based position, or 0 if the gateway id is not present.
 */
function aafm_wc_gateway_order( string $gateway_id, array $gateways ): int {
	$position = array_search( $gateway_id, array_keys( $gateways ), true );
	return false === $position ? 0 : (int) $position;
}

/**
 * Build the safe output shape for a payment gateway.
 *
 * Returns id, title, description, enabled (bool), order, and redacted settings.
 * Credential fields are stripped by aafm_wc_redact_gateway_settings().
 *
 * @param \WC_Payment_Gateway $gateway Payment gateway object.
 * @param int                 $order   Display order, from aafm_wc_gateway_order().
 * @return array<string,mixed>
 */
function aafm_wc_gateway_shape( \WC_Payment_Gateway $gateway, int $order ): array {
	// Strip credential fields before the settings ever reach the shape (denylist walk, see
	// aafm_wc_redact_settings_deep()'s docblock above).
	$settings = aafm_wc_redact_gateway_settings( $gateway->settings );
	return array(
		'id'          => $gateway->id,
		// WC_Payment_Gateway declares $title and $description with no default; a gateway that
		// never assigns them (a third-party gateway that skips the usual __construct wiring) reads
		// back as null, which would violate the declared string schema. Cast defensively.
		'title'       => (string) $gateway->title,
		'description' => (string) $gateway->description,
		'enabled'     => 'yes' === $gateway->enabled,
		'order'       => $order,
		// A gateway that never calls init_settings() (again, a non-conforming third-party
		// gateway) leaves $settings as an empty array, which encodes as [] rather than the {}
		// the declared object schema needs.
		'settings'    => array() === $settings ? (object) $settings : $settings,
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
				'idempotent'  => true,
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
function aafm_exec_wc_list_payment_gateways( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- no input params used; signature required by abilities API.
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
			// Same missing-default risk as aafm_wc_gateway_shape(): WC_Payment_Gateway declares
			// no default for $title, so an unassigned one would read back as null against the
			// declared string schema.
			'title'   => (string) $gateway->title,
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
				'gateway_id' => array(
					'type'        => 'string',
					'description' => __( 'The payment gateway\'s id slug (e.g. "bacs", "cod", "stripe"), from wc-list-payment-gateways.', 'agent-abilities-for-mcp' ),
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
		'execute_callback'    => 'aafm_exec_wc_get_payment_gateway',
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
 * Execute aafm/wc-get-payment-gateway.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_get_payment_gateway( array $input ) {
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
	return aafm_wc_gateway_shape( $gateways[ $gateway_id ], aafm_wc_gateway_order( $gateway_id, $gateways ) );
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
				'gateway_id'  => array(
					'type'        => 'string',
					'description' => __( 'The payment gateway\'s id slug (e.g. "bacs", "cod", "stripe"), from wc-list-payment-gateways. Must reference an existing gateway or the request fails.', 'agent-abilities-for-mcp' ),
				),
				'enabled'     => array(
					'type'        => 'boolean',
					'description' => 'Whether the gateway is enabled, as a boolean (true/false). Note: this differs from the shipping-method abilities, where the equivalent enabled flag is the string "yes"/"no".',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Customer-facing gateway name shown at checkout, overriding the gateway\'s default title.', 'agent-abilities-for-mcp' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Customer-facing text shown under the gateway\'s title at checkout, describing the payment method.', 'agent-abilities-for-mcp' ),
				),
				'order'       => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'This gateway\'s raw position in the store\'s checkout gateway ordering. Lower values sort earlier. Stored directly as given, not validated against other gateways\' order values, so two gateways can end up sharing the same position. Reading the gateway back afterwards does not return this number: the read reports the gateway\'s resolved zero-based rank among all gateways, so a value of 5 written here can come back as 2. Both are correct, they answer different questions.', 'agent-abilities-for-mcp' ),
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
 * Updates only the fields provided: enabled, title, description, order. Each field is persisted
 * immediately through WC_Payment_Gateway::update_option() (WooCommerce gateways expose no save()
 * method; update_option writes straight to the gateway's option store). Audits deny when the
 * gateway id is unknown. Secrets are redacted from the returned shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_exec_wc_update_payment_gateway( array $input ) {
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

	// Each setting persists immediately through WC_Payment_Gateway::update_option(). That method
	// returns WordPress's update_option() result, which is false when the new value already equals
	// the stored value (no write needed) - NOT only on failure. So a return-value gate would falsely
	// error on unchanged values. Instead, apply each write and verify the desired end-state by
	// reading the value back; only a genuine read-back mismatch is a failure.
	$desired = array();
	if ( isset( $input['enabled'] ) ) {
		$enabled_val      = $input['enabled'] ? 'yes' : 'no';
		$gateway->enabled = $enabled_val;
		$gateway->update_option( 'enabled', $enabled_val );
		$desired['enabled'] = $enabled_val;
	}
	if ( isset( $input['title'] ) ) {
		$title_val      = sanitize_text_field( (string) $input['title'] );
		$gateway->title = $title_val;
		$gateway->update_option( 'title', $title_val );
		$desired['title'] = $title_val;
	}
	if ( isset( $input['description'] ) ) {
		$desc_val             = sanitize_textarea_field( (string) $input['description'] );
		$gateway->description = $desc_val;
		$gateway->update_option( 'description', $desc_val );
		$desired['description'] = $desc_val;
	}
	if ( isset( $input['order'] ) ) {
		// Display order is not a per-gateway setting, and WC_Payment_Gateway has no `order` property
		// to set (M13) - WooCommerce keeps order in the woocommerce_gateway_order option (a
		// gateway_id => position map). Persist it there so the change survives the next request.
		$order_val               = (int) $input['order'];
		$ordering                = get_option( 'woocommerce_gateway_order', array() );
		$ordering                = is_array( $ordering ) ? $ordering : array();
		$ordering[ $gateway_id ] = $order_val;
		update_option( 'woocommerce_gateway_order', $ordering );

		$saved_order = get_option( 'woocommerce_gateway_order', array() );
		if ( ! is_array( $saved_order ) || (int) ( $saved_order[ $gateway_id ] ?? -1 ) !== $order_val ) {
			return aafm_generic_error();
		}
	}
	// Verify the persisted state matches what we asked for, reading the value WooCommerce actually
	// wrote to the database - NOT the gateway's in-memory copy. WC_Settings_API::update_option() sets
	// $this->settings[$key] in memory BEFORE the DB write, and get_option() reads that in-memory copy,
	// so a failed write (or a sanitize filter that altered the value on the way to disk) would still
	// read back as a match through $gateway->get_option() and report a false success. Re-read the
	// persisted settings row (get_option_key()) so only a genuinely persisted value counts as success.
	if ( ! empty( $desired ) ) {
		$persisted = get_option( $gateway->get_option_key(), array() );
		$persisted = is_array( $persisted ) ? $persisted : array();
		foreach ( $desired as $key => $value ) {
			$stored = array_key_exists( $key, $persisted ) ? (string) $persisted[ $key ] : '';
			if ( $stored !== (string) $value ) {
				return aafm_generic_error();
			}
		}
	}
	// The response order reflects what was just requested when the request set one (the
	// woocommerce_gateway_order write above is confirmed, but WC only re-sorts payment_gateways() on
	// its own object's next init(), not against $gateways already fetched above). Otherwise fall back
	// to the gateway's real position in WooCommerce's own sorted list (M13).
	$order = isset( $input['order'] ) ? (int) $input['order'] : aafm_wc_gateway_order( $gateway_id, $gateways );
	return aafm_wc_gateway_shape( $gateway, $order );
}
