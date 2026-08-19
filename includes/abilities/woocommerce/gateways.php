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
 * Recursively MARK secret/credential fields in an arbitrary settings array.
 *
 * Marks, rather than removes. That distinction is the whole point of this function's current shape.
 * Dropping a matched key meant a caller could not tell "this setting is not configured" from "this
 * setting is withheld", so a benign field caught by a loose pattern simply vanished and the agent
 * answered questions about it wrongly: asked whether a carrier method required a signature on
 * delivery, it saw no signature_required key and said there was no such setting, when the setting
 * existed and was set. Silent data loss dressed as an absent field. A fixed marker keeps the key
 * visible and its value withheld, which is the honest shape and is what makes the widening below
 * safe -- once a false positive is recoverable rather than invisible, breadth costs the caller
 * clarity instead of costing them the truth.
 *
 * The walk recurses into nested arrays so a credential under a benign parent is still caught. A
 * secret-NAMED key whose value is an array is marked whole rather than recursed into: the name
 * already says the subtree is credential material.
 *
 * The pattern has two groups and the split is deliberate.
 *
 * The LOOSE group matches anywhere in the key, and holds only words that mean "secret" wherever
 * they appear: secret, token, password/passwd/passphrase/passcode/pwd, private, credential,
 * signature, hmac, oauth, bearer, api_key/apikey, client_id.
 *
 * The ANCHORED group needs a start/end or underscore/hyphen boundary, because each of these is a
 * common substring of an ordinary word. This is where key, api, sign and auth now live, moved out
 * of the loose group because unanchored they matched monkey_bars, rapid_dispatch, design_template
 * and author -- absurd on their face, and formerly a silent delete of each. Anchoring keeps
 * api_key and account_number while letting capital_city_only through.
 *
 * The 1.7.0 additions close what two sim lanes proved leaks on a REAL gateway: iban, bic and
 * sort_code are WooCommerce core's OWN bacs fields, present on a default install, and cvv,
 * security_code, routing and bank are the ordinary vocabulary of card and bank data. hmac, seed and
 * iv are cryptographic material by name. passcode is the exact miss the docblock already recorded
 * for passphrase: anchored "pass" does not match "passcode" because the next character is "c", so
 * the earlier fix never generalised.
 *
 * TWO HONEST LIMITS, both proven rather than theoretical.
 *
 * First, a name denylist cannot catch a secret carried in a VALUE. The sim found notify_url holding
 * "https://psp.example.com/hook?token=...": the key is benign, the query string is not. Nothing
 * here will ever catch that shape.
 *
 * Second, and this corrects a caveat that used to overstate its own coverage: the old docblock
 * excused misses as coming from "third-party gateway plugins this codebase has no visibility
 * into". That is not what failed. iban and sort_code are core WooCommerce's own field names, so
 * they were visible all along. The genuinely uncatchable names are the ones that carry no signal
 * AT ALL -- custom_field_3, vendor_code, store_hash, partner_code, entity_id, shopper_reference --
 * and no denylist over names can be expected to know those hold credentials. Treat this as
 * best-effort over names, widened as real gaps are proven, never a completeness guarantee.
 *
 * @param array<int|string,mixed> $settings Raw settings array (may be nested).
 * @return array<int|string,mixed>
 */
function aafm_wc_redact_settings_deep( array $settings ): array {
	$result = aafm_wc_redact_settings_report( $settings );
	return (array) $result['settings'];
}

/**
 * Redact a settings array AND report, out of band, exactly which paths were withheld.
 *
 * The marker alone cannot carry this signal, and that is not a detail. A gateway may legitimately
 * store the literal string "[redacted]" in a benign field, so a marker living inside the same
 * arbitrary-string value domain as real data can always be forged by accident. The claim that it was
 * "impossible to mistake for a real configured value" was not achievable as written.
 *
 * So the authoritative answer moves outside the values: a list of paths. Each path is an ARRAY OF
 * SEGMENTS, not a joined string, and the difference is the whole point. A settings key is an
 * arbitrary array key, so it may contain any character including whatever separator a joined path
 * would use; "a.b" then reads identically as the nested key b under a and as a single key literally
 * named "a.b". Escaping the separator would work, but segments cannot be ambiguous in the first
 * place: every element is exactly one key, verbatim, so a caller reconstructs the exact key by
 * indexing rather than by parsing.
 *
 * @param array<int|string,mixed> $settings Raw settings array (may be nested).
 * @param array<int,string>       $prefix   Segments of the parent's path, used by the recursion.
 * @return array{settings:array<int|string,mixed>,redacted:array<int,array<int,string>>}
 */
function aafm_wc_redact_settings_report( array $settings, array $prefix = array() ): array {
	$redacted = array();
	$paths    = array();

	foreach ( $settings as $key => $value ) {
		$path   = $prefix;
		$path[] = (string) $key;

		if ( aafm_wc_settings_key_is_secret( (string) $key ) ) {
			$redacted[ $key ] = aafm_wc_redaction_marker();
			$paths[]          = $path;
			continue;
		}

		if ( is_array( $value ) ) {
			$nested           = aafm_wc_redact_settings_report( $value, $path );
			$redacted[ $key ] = $nested['settings'];
			$paths            = array_merge( $paths, $nested['redacted'] );
			continue;
		}

		$redacted[ $key ] = $value;
	}

	return array(
		'settings' => $redacted,
		'redacted' => $paths,
	);
}

/**
 * The value a withheld setting is replaced with, in place.
 *
 * A convenience, NOT the signal. It cannot be the signal: this is an arbitrary string sitting in a
 * field whose real values are also arbitrary strings, so a setting genuinely holding "[redacted]" is
 * indistinguishable from a withheld one by value alone. The `redacted_fields` segment-path list
 * returned alongside the settings is the authoritative answer; read that, not this.
 *
 * Deliberately not translated: an agent parses it, so it has to be stable across locales.
 *
 * @return string
 */
function aafm_wc_redaction_marker(): string {
	return '[redacted]';
}

/**
 * Whether a settings key names something that must not be returned.
 *
 * Split out from the walk so the pattern has one home and can be exercised directly by a test.
 * See aafm_wc_redact_settings_deep() for why the two groups are anchored differently.
 *
 * The classification runs TWICE: once on the key as sent, and once on a copy where every camelCase
 * hump has been split into an underscore boundary. Half the tokens below are boundary-anchored, and
 * a camelCase transition is not a boundary, so `accessKey` and `apiLoginID` walked straight past a
 * denylist that stops `access_key` and `api_login_id` dead. Authorize.Net alone ships both of those
 * names. Authorize.Net alone ships `apiLoginID` and `transactionKey`.
 *
 * THREE spellings are tested, not two, and the third is not belt-and-braces. A camelCase split has
 * to handle acronym runs, which needs a second pattern: `([A-Z]+)([A-Z][a-z])` splits before the
 * LAST capital of a run, so `SSLCertificate` becomes `SSL_Certificate` and `APIKey` becomes
 * `API_Key` rather than the `AP_I_Key` a naive upper-to-upper split would produce (measured, both
 * spellings). Without it a leading acronym defeats every anchored-only token: `SSLCertificate`,
 * `MIDValue` and `IBANNumber` all walked past a list that stops `ssl_certificate`, `mid_value` and
 * `iban_number` dead. `APIKey` and `APIToken` survived only because `api[_-]?key` and bare `token`
 * happen to be in the LOOSE group.
 *
 * But the acronym pass can also SPLIT a token apart: `OAuthClientID` becomes `O_Auth_Client_ID`,
 * destroying `oauth`. That is harmless today only because `oauth` is loose and the raw form catches
 * it anyway, which is an accident of where one token currently sits rather than a property. So the
 * single-pass spelling is kept as its own candidate instead of being superseded, which makes
 * "adding the acronym pass can never lose a catch the simpler split had" structural rather than an
 * argument a future retuning of the groups could quietly invalidate.
 *
 * STATED BOUND, measured rather than asserted: NOTHING CURRENTLY DEPENDS ON THAT THIRD SPELLING.
 * Removing it and keeping only the raw key and the acronym form leaves the whole corpus green,
 * because the acronym form already runs the hump pass on top of itself and the one key where the
 * two genuinely differ is caught on the raw form regardless. It is kept as a guard against a
 * future token move, not because any row demonstrates a need for it. If that ever stops being
 * true, a row will start depending on it and this paragraph should be rewritten, not deleted.
 *
 * One-directional by construction: the raw key is tested first and unchanged, and the two derived
 * spellings can only add matches on top of it. A key that is withheld today cannot become released
 * by any of this. The only movement available is from released to withheld.
 *
 * @param string $key Settings key.
 * @return bool
 */
function aafm_wc_settings_key_is_secret( string $key ): bool {
	foreach ( aafm_wc_settings_key_spellings( $key ) as $spelling ) {
		if ( aafm_wc_settings_key_matches_secret( $spelling ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Every spelling of a key the denylist should be judged against, the key itself first.
 *
 * Duplicates are dropped so an all-lowercase key costs exactly one match, which is the common case.
 *
 * @param string $key Settings key.
 * @return array<int,string>
 */
function aafm_wc_settings_key_spellings( string $key ): array {
	$humps   = aafm_wc_split_camel_humps( $key );
	$acronym = aafm_wc_split_camel_humps( aafm_wc_split_acronym_run( $key ) );

	return array_values( array_unique( array( $key, $humps, $acronym ) ) );
}

/**
 * Insert an underscore at every lower-to-upper camelCase hump.
 *
 * `accessKey` becomes `access_Key`, which is what lets the boundary-anchored half of the denylist
 * see a token that carries no underscore or hyphen of its own. Matching is case-insensitive
 * throughout, so the capital that survives the split does not matter.
 *
 * @param string $key Settings key.
 * @return string
 */
function aafm_wc_split_camel_humps( string $key ): string {
	$split = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );

	return null === $split ? $key : $split;
}

/**
 * Break a run of capitals just before the last one, so an acronym keeps its own boundary.
 *
 * `SSLCertificate` becomes `SSL_Certificate`; `APIKey` becomes `API_Key`. The split lands before
 * the FINAL capital of the run because that capital belongs to the following word, not the
 * acronym. Splitting between every pair instead would produce `AP_I_Key` and destroy the token.
 *
 * @param string $key Settings key.
 * @return string
 */
function aafm_wc_split_acronym_run( string $key ): string {
	$split = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key );

	return null === $split ? $key : $split;
}

/**
 * The denylist itself, applied to one spelling of a key.
 *
 * Kept separate from aafm_wc_settings_key_is_secret() so that function can apply it to more than
 * one spelling without the pattern gaining a second home.
 *
 * @param string $key Settings key, in whichever spelling is being tested.
 * @return bool
 */
function aafm_wc_settings_key_matches_secret( string $key ): bool {
	$loose = 'secret|token|passphrase|passcode|password|passwd|pwd|private|credential|signature|hmac|oauth|bearer|api[_-]?key|client[_-]?id';
	// Longer alternatives first, so "certificate" is not shadowed by "cert". Bare "pass" is here
	// rather than in the loose group for the reason the original docblock recorded: anchored, it
	// catches a field literally named "pass" without matching every word containing those letters.
	// "security" and "terminal" are compounds ONLY. Bare, they marked security_badge and
	// terminal_display, which are ordinary UI configuration. Both were added by this release, so
	// narrowing them corrects this release's own overreach.
	//
	// Two tokens are deliberately LEFT broad: user and number. They predate this release and the
	// docblock records a considered decision to keep them wide rather than risk un-redacting
	// something an earlier review relied on being caught; reversing that on a review's say-so trades
	// a withheld benign value for a possible leak, and those are not symmetric.
	$anchored = 'certificate|cert|security[_-]?(?:code|key|token|question|answer|pin|hash)|sort[_-]?code|terminal[_-]?(?:id|key|password|token|secret)|username|user|account|merchant|license|number|routing|swift|iban|epin|seed|salt|pass|auth|sign|key|api|pem|pin|cvv|cvc|bic|mid|iv';

	if ( 1 === preg_match( '/(?:' . $loose . ')|(?:^|[_-])(?:' . $anchored . ')(?:[_-]|$)/i', $key ) ) {
		return true;
	}

	// "bank" and "login" are SOFT: broad enough to be worth keeping, broad enough to be wrong on
	// their own. This release added them to catch bank_details and x_login, two of the names the
	// traffic sim read back in full off a real gateway, and they do still catch those. But bare they
	// also marked bank_logo and login_button_label, which are a picture and a piece of button copy.
	//
	// The carve-out below is SUBTRACTIVE rather than a narrowing, and that is deliberate. Rewriting
	// these into a compound list the way security and terminal were rewritten would flip the default
	// from "caught unless proven benign" to "missed unless enumerated", and every credential-shaped
	// bank_* or *_login name nobody thought to enumerate would go out in full. Instead the tokens
	// stay as broad as they were, and a name is released ONLY when its last segment is one of a
	// closed list of words that name how a thing is displayed, not what it holds. So bank_details,
	// bank_account, bank_iban, x_login and login_token are all still withheld, unchanged, and only
	// bank_logo, login_button_label and their kind come back.
	//
	// The strong pattern above is checked FIRST and wins outright, so a key that carries any other
	// credential signal is never released by this rule: bank_account_label is still withheld, on
	// "account". This rule can only ever release a name whose sole credential signal was bank or
	// login. A future finding that wants these narrowed further needs to argue with this paragraph.
	if ( 1 !== preg_match( '/(?:^|[_-])(?:bank|login)(?:[_-]|$)/i', $key ) ) {
		return false;
	}

	return ! aafm_wc_settings_key_is_presentational( $key );
}

/**
 * Whether a key's last segment names how something is DISPLAYED rather than what it holds.
 *
 * Only consulted for the two soft tokens in aafm_wc_settings_key_is_secret(); see the reasoning
 * there for why the release is subtractive and why this list is closed rather than open-ended.
 *
 * The list is deliberately short and deliberately excludes url and link. A logo is a picture and a
 * label is a caption, so neither can be a credential whatever the surrounding words say; a URL can
 * be, because a secret carried in a query string is the one failure mode the whole name denylist is
 * already documented as unable to see. Releasing login_url would put a name-shaped hole exactly
 * where the value-shaped hole already is.
 *
 * @param string $key Settings key.
 * @return bool
 */
function aafm_wc_settings_key_is_presentational( string $key ): bool {
	$suffixes = 'logo|label|title|subtitle|text|icon|image|img|heading|subheading|description|desc|placeholder|tooltip|caption|banner|badge|colou?r|position|display|style|class|width|height|message|note|notice|instructions|button|alt|enabled|visible|visibility';

	return 1 === preg_match( '/(?:^|[_-])(?:' . $suffixes . ')$/i', $key );
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
	$report   = aafm_wc_redact_settings_report( (array) $gateway->settings );
	$settings = (array) $report['settings'];
	return array(
		'id'              => $gateway->id,
		// WC_Payment_Gateway declares $title and $description with no default; a gateway that
		// never assigns them (a third-party gateway that skips the usual __construct wiring) reads
		// back as null, which would violate the declared string schema. Cast defensively.
		'title'           => (string) $gateway->title,
		'description'     => (string) $gateway->description,
		'enabled'         => 'yes' === $gateway->enabled,
		'order'           => $order,
		// A gateway that never calls init_settings() (again, a non-conforming third-party
		// gateway) leaves $settings as an empty array, which encodes as [] rather than the {}
		// the declared object schema needs.
		'settings'        => array() === $settings ? (object) $settings : $settings,
		// The AUTHORITATIVE list of what was withheld, each entry an array of key segments. The
		// marker inside `settings` is a convenience; it lives in the same string domain as real
		// values, so only this list can distinguish a withheld field from one that holds that
		// string. Segments rather than a joined path because a key may contain any separator.
		'redacted_fields' => array_values( (array) $report['redacted'] ),
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
				'id'              => array( 'type' => 'string' ),
				'title'           => array( 'type' => 'string' ),
				'description'     => array( 'type' => 'string' ),
				'enabled'         => array( 'type' => 'boolean' ),
				'order'           => array( 'type' => 'integer' ),
				'settings'        => array( 'type' => 'object' ),
				'redacted_fields' => aafm_wc_redacted_fields_schema(),
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
				'id'              => array( 'type' => 'string' ),
				'title'           => array( 'type' => 'string' ),
				'description'     => array( 'type' => 'string' ),
				'enabled'         => array( 'type' => 'boolean' ),
				'order'           => array( 'type' => 'integer' ),
				'settings'        => array( 'type' => 'object' ),
				'redacted_fields' => aafm_wc_redacted_fields_schema(),
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
 * Build the error for a gateway update whose read-back verification found unpersisted fields.
 *
 * B32: the caller must never be told a bare "error" about a request that partially changed state.
 * Two branches (the third, full success, never reaches here): nothing persisted, or a partial
 * write naming both sides. The machine-readable split rides in the error data.
 *
 * @param array<int,string> $persisted Field names whose values were confirmed persisted.
 * @param array<int,string> $failed    Field names whose values did not persist.
 * @return \WP_Error
 */
function aafm_wc_gateway_write_failed_error( array $persisted, array $failed ): \WP_Error {
	$data = array(
		'persisted' => $persisted,
		'failed'    => $failed,
	);

	if ( array() === $persisted ) {
		return new \WP_Error(
			'aafm_wc_gateway_write_failed',
			sprintf(
				/* translators: %s: comma-separated list of gateway fields that failed to save. */
				__( 'The gateway settings could not be saved (%s failed to persist). Nothing was changed.', 'agent-abilities-for-mcp' ),
				implode( ', ', $failed )
			),
			$data
		);
	}

	return new \WP_Error(
		'aafm_wc_gateway_write_failed',
		sprintf(
			/* translators: 1: comma-separated gateway fields that saved, 2: comma-separated gateway fields that failed. */
			__( 'Only part of the gateway update persisted: %1$s saved, but %2$s failed. Read the gateway to see its current state.', 'agent-abilities-for-mcp' ),
			implode( ', ', $persisted ),
			implode( ', ', $failed )
		),
		$data
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
	// error on unchanged values. Instead, apply every write, then verify the desired end-state by
	// reading each value back; only a genuine read-back mismatch is a failure.
	//
	// B32: verification runs ONCE, after the whole batch, and a mismatch reports exactly which
	// fields persisted and which did not. The old sequence verified the order write mid-batch and
	// bailed with a bare generic error on the first settings mismatch, so a caller could be told
	// "error" after the title (or more) had already landed, with nothing saying so.
	$desired = array();
	if ( isset( $input['enabled'] ) ) {
		$desired['enabled'] = $input['enabled'] ? 'yes' : 'no';
	}
	if ( isset( $input['title'] ) ) {
		$desired['title'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	if ( isset( $input['description'] ) ) {
		$desired['description'] = aafm_sanitize_multiline_text( (string) $input['description'] );
	}

	foreach ( $desired as $key => $value ) {
		if ( 'enabled' === $key ) {
			$gateway->enabled = $value;
		} elseif ( 'title' === $key ) {
			$gateway->title = $value;
		} else {
			$gateway->description = $value;
		}
		$gateway->update_option( $key, $value );
	}

	$order_val = null;
	if ( isset( $input['order'] ) ) {
		// Display order is not a per-gateway setting, and WC_Payment_Gateway has no `order` property
		// to set (M13) - WooCommerce keeps order in the woocommerce_gateway_order option (a
		// gateway_id => position map). Persist it there so the change survives the next request.
		$order_val               = (int) $input['order'];
		$ordering                = get_option( 'woocommerce_gateway_order', array() );
		$ordering                = is_array( $ordering ) ? $ordering : array();
		$ordering[ $gateway_id ] = $order_val;
		update_option( 'woocommerce_gateway_order', $ordering );
	}

	// Verify the persisted state matches what we asked for, reading the values WooCommerce actually
	// wrote to the database - NOT the gateway's in-memory copy. WC_Settings_API::update_option() sets
	// $this->settings[$key] in memory BEFORE the DB write, and get_option() reads that in-memory copy,
	// so a failed write (or a sanitize filter that altered the value on the way to disk) would still
	// read back as a match through $gateway->get_option() and report a false success. Re-read the
	// persisted settings row (get_option_key()) so only a genuinely persisted value counts as success.
	$persisted_keys = array();
	$failed_keys    = array();
	if ( ! empty( $desired ) ) {
		$persisted = get_option( $gateway->get_option_key(), array() );
		$persisted = is_array( $persisted ) ? $persisted : array();
		foreach ( $desired as $key => $value ) {
			$stored = array_key_exists( $key, $persisted ) ? (string) $persisted[ $key ] : '';
			if ( $stored === (string) $value ) {
				$persisted_keys[] = $key;
			} else {
				$failed_keys[] = $key;
			}
		}
	}
	if ( null !== $order_val ) {
		$saved_order = get_option( 'woocommerce_gateway_order', array() );
		if ( is_array( $saved_order ) && (int) ( $saved_order[ $gateway_id ] ?? -1 ) === $order_val ) {
			$persisted_keys[] = 'order';
		} else {
			$failed_keys[] = 'order';
		}
	}
	if ( array() !== $failed_keys ) {
		return aafm_wc_gateway_write_failed_error( $persisted_keys, $failed_keys );
	}
	// The response order reflects what was just requested when the request set one (the
	// woocommerce_gateway_order write above is confirmed, but WC only re-sorts payment_gateways() on
	// its own object's next init(), not against $gateways already fetched above). Otherwise fall back
	// to the gateway's real position in WooCommerce's own sorted list (M13).
	$order = isset( $input['order'] ) ? (int) $input['order'] : aafm_wc_gateway_order( $gateway_id, $gateways );
	return aafm_wc_gateway_shape( $gateway, $order );
}
