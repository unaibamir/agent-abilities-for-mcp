<?php
/**
 * WooCommerce integration abilities - shared cross-domain helpers.
 *
 * Loaded FIRST among the WooCommerce domain files so the helpers below exist before any
 * domain file references them. Holds only the truly cross-cutting helpers used across products,
 * orders, customers, coupons, tax, and the rest: the manage_woocommerce permission floor, the
 * price sanitiser, and the date-to-string formatter. Registers no abilities and adds no filter.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The object-independent permission floor for every WooCommerce product ability: the caller holds
 * the manage_woocommerce capability (the cap WordPress puts on the WooCommerce admin screens).
 *
 * Used as each ability's permission_callback directly. Because it takes no object id, the abilities
 * are object-independent and fall through to this callback at discovery with empty input - the
 * correct discovery answer - so none needs a server.php case.
 *
 * @return bool
 */
function aafm_wc_perm(): bool {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * Reject a non-empty billing email that is not a valid address, before any write happens.
 *
 * The sanitize_email() call turns an invalid address ("not-an-email") into '', which silently ERASES the
 * stored billing email through set_billing_email(''), reports success, and returns the field as an
 * empty string on the wire. An explicitly empty string is an intentional clear and is allowed; a
 * non-empty value that is not a valid email is refused so it cannot erase stored PII by accident.
 * Callers run this before touching the order/customer so nothing partial is saved.
 *
 * @param array<string,mixed> $input The ability input (billing under $input['billing']['email']).
 * @return \WP_Error|null WP_Error when the billing email is present, non-empty, and invalid.
 */
function aafm_wc_billing_email_error( array $input ): ?\WP_Error {
	if ( ! isset( $input['billing'] ) || ! is_array( $input['billing'] ) || ! array_key_exists( 'email', $input['billing'] ) ) {
		return null;
	}
	$raw = (string) $input['billing']['email'];
	if ( '' !== $raw && ! is_email( $raw ) ) {
		return new \WP_Error(
			'aafm_invalid_billing_email',
			__( 'The billing email is not a valid email address.', 'agent-abilities-for-mcp' )
		);
	}
	return null;
}

/**
 * The declared output schema for the `redacted_fields` list, shared by every shape that carries it.
 *
 * Three shapes return this field - the two gateway abilities and the shipping-method properties,
 * which themselves back both a get and a list ability - and the contract was written out three
 * times. That is the shape of a fact that drifts, so it lives here once and each schema points at
 * it. Lives in _shared.php rather than gateways.php because shipping.php is required BEFORE
 * gateways.php; a shared helper belongs in the file that loads first.
 *
 * @return array<string,mixed>
 */
function aafm_wc_redacted_fields_schema(): array {
	return array(
		'type'        => 'array',
		'items'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
		'description' => 'Which values inside `settings` were withheld as credentials. Each entry is a path given as an ARRAY OF KEY SEGMENTS from the root of `settings` - ["advanced","live","passcode"] means settings.advanced.live.passcode. Segments rather than a joined string because a settings key may itself contain any character, so a joined path could not be parsed back to the exact key. This list is authoritative: a value inside `settings` may itself read "[redacted]" without having been withheld, so check membership here rather than comparing values.',
	);
}

/**
 * Sanitize a price-like string to a bare decimal: strips every character except digits and the
 * decimal point (currency symbols, spaces, thousands separators, and any minus sign all go).
 *
 * @param mixed $value Raw price.
 * @return string
 */
function aafm_wc_sanitize_price( $value ): string {
	$clean = preg_replace( '/[^0-9.]/', '', (string) $value );
	return is_string( $clean ) ? $clean : '';
}

/**
 * Normalise a WooCommerce date value (WC_DateTime object, ISO string, or null) to a plain string.
 *
 * WooCommerce date getters (get_date_created, get_date_paid, …) return a WC_DateTime instance at
 * runtime, but their PHPStan signature is typed as string|object|null because WC_DateTime is not
 * present in the static-analysis stubs. This helper accepts all three variants and always returns
 * a string or null - avoiding unsafe casts on raw object|null values.
 *
 * @param string|object|null $date Raw date value from a WC_Order getter.
 * @return string|null
 */
function aafm_wc_date_string( $date ): ?string {
	if ( null === $date ) {
		return null;
	}
	if ( is_object( $date ) && method_exists( $date, '__toString' ) ) {
		return (string) $date;
	}
	return is_string( $date ) ? $date : null;
}

/**
 * Resolve one of a taxonomy attribute's stored options to its term, reading only.
 *
 * THE RULE THIS EXISTS TO ENFORCE, and the reason it lives here rather than beside one caller:
 * **a WooCommerce attribute API that resolves an option by NAME can create that option.** Both
 * WC_Product_Attribute methods that turn stored options into terms take the same branch --
 * `get_term_by( 'name', ... )`, and on a miss `wp_insert_term( $option, ... )`. The family is
 * exactly two, enumerated rather than assumed by grepping the class:
 *
 *   WC_Product_Attribute::get_terms()   class-wc-product-attribute.php, the insert is in its else arm
 *   WC_Product_Attribute::get_slugs()   same shape, same insert
 *
 * Neither is called anywhere in this plugin, deliberately. `get_slugs()` was removed from the
 * variation validator once it was clear a validator must not write, and `get_terms()` is the one
 * the product write path would have reached: it is what
 * WC_Product_Data_Store_CPT::update_attributes() calls to persist a taxonomy attribute, so a
 * caller echoing back the term IDS a read handed them would have had three terms literally NAMED
 * "1707", "1708", "1709" created in the taxonomy. Anything new on this class that turns an option
 * into a term belongs on this list, and belongs behind this function.
 *
 * A miss is returned as a miss; nothing is created to make one.
 *
 * An option out of the data store is an int term id. The numeric-string arm is there because that
 * typing is a WordPress implementation detail (WP_Term::term_id happens to be an int) rather than a
 * documented guarantee, and refusing to resolve "12" would be a silly thing to fail a write over.
 * A genuinely non-numeric option is resolved by name first, matching WooCommerce's own order, then
 * by slug, since an option already written in slug form should still produce a usable list.
 *
 * @param mixed  $option   One entry from WC_Product_Attribute::get_options().
 * @param string $taxonomy The attribute taxonomy.
 * @return \WP_Term|null The resolved term, or null when it cannot be found without creating it.
 */
function aafm_wc_find_attribute_term( $option, string $taxonomy ): ?\WP_Term {
	if ( is_int( $option ) || ( is_string( $option ) && '' !== $option && ctype_digit( $option ) ) ) {
		$term = get_term_by( 'id', (int) $option, $taxonomy );
		return $term instanceof \WP_Term ? $term : null;
	}

	if ( ! is_string( $option ) || '' === $option ) {
		return null;
	}

	$term = get_term_by( 'name', $option, $taxonomy );
	if ( ! $term instanceof \WP_Term ) {
		$term = get_term_by( 'slug', $option, $taxonomy );
	}

	return $term instanceof \WP_Term ? $term : null;
}
