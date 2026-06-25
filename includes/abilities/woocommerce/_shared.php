<?php
/**
 * WooCommerce integration abilities — shared cross-domain helpers.
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
 * are object-independent and fall through to this callback at discovery with empty input — the
 * correct discovery answer — so none needs a server.php case.
 *
 * @return bool
 */
function aafm_wc_perm(): bool {
	return current_user_can( 'manage_woocommerce' );
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
 * a string or null — avoiding unsafe casts on raw object|null values.
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
