<?php
/**
 * Sweep: every WooCommerce by-id load in the WooCommerce ability files is classified.
 *
 * A vendor loader that reads one object by id can be answered by another object's row when its
 * query is faulted. Each call below is either covered (an exact load precedes it, or its answer
 * is checked against the requested id) or named as a residual in the plan's not-covered list. A
 * new by-id vendor load fails this test until it is classified here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class WooByIdLoadSweepTest extends TestCase {

	/**
	 * Vendor by-id loaders, as they appear in a call: a function name, a class after `new`, or a
	 * method name after `->` or `::`.
	 */
	private const LOADERS = array(
		'wc_get_product',
		'wc_get_order',
		'wc_get_order_notes',
		'wc_get_attribute',
		'wc_get_coupon_id_by_code',
		'WC_Coupon',
		'WC_Customer',
		'WC_Product_Variation',
		'get_zone',
		'get_shipping_methods',
		'_get_tax_rate',
		'payment_gateways',
	);

	private const COVERED_EXACT  = 'covered: exact load first, and a throwing registry keeps it';
	private const COVERED_ID     = 'covered: the answer is matched against the requested id';
	private const COVERED_KEYED  = 'covered by construction: WooCommerce keys the answer by id';
	private const COVERED_MEMORY = 'covered: an in-memory list, no row read';
	private const BY_SPEC_W5     = 'BY-SPEC: shipping zone and method writes are not certified; reads keep 1.7.5 (E-W5)';
	private const BY_SPEC_COUPON = 'BY-SPEC: the duplicate-code check asks WooCommerce (E-W5 coupon line)';
	private const BY_SPEC_LIST   = 'BY-SPEC: a list for an exactly loaded order keeps 1.7.5 (pure reads)';

	/**
	 * Every by-id load, keyed `path|function|loader#ordinal`, with its class.
	 *
	 * @return array<string,string>
	 */
	private function classified(): array {
		$wc = 'includes/abilities/woocommerce/';
		return array(
			$wc . 'products.php|aafm_wc_get_product|wc_get_product#1'                  => self::COVERED_EXACT,
			$wc . 'variations.php|aafm_wc_get_variation|wc_get_product#1'              => self::COVERED_EXACT,
			$wc . 'variations.php|aafm_wc_get_variation|WC_Product_Variation#1'        => self::COVERED_EXACT,
			$wc . 'coupons.php|aafm_wc_get_coupon_object|WC_Coupon#1'                  => self::COVERED_EXACT,
			$wc . 'customers.php|aafm_wc_get_customer_object|WC_Customer#1'            => self::COVERED_EXACT,
			$wc . 'orders.php|aafm_wc_get_order_object|wc_get_order#1'                 => self::COVERED_EXACT,
			$wc . 'orders.php|aafm_wc_apply_order_input|wc_get_product#1'              => self::COVERED_EXACT,
			$wc . 'orders.php|aafm_wc_load_order_or_null|wc_get_order#1'               => self::COVERED_EXACT,
			$wc . 'orders.php|aafm_wc_order_still_exists|wc_get_order#1'               => self::COVERED_EXACT,
			$wc . 'orders.php|aafm_wc_get_refund_object|wc_get_order#1'                => self::COVERED_EXACT,
			$wc . 'orders.php|aafm_wc_get_order_note|wc_get_order_notes#1'             => self::COVERED_ID,
			$wc . 'orders.php|aafm_exec_wc_list_order_notes|wc_get_order_notes#1'      => self::BY_SPEC_LIST,
			$wc . 'tax.php|aafm_wc_get_tax_rate_by_id|_get_tax_rate#1'                 => self::COVERED_ID,
			$wc . 'attributes.php|aafm_exec_wc_create_product_attribute|wc_get_attribute#1' => self::COVERED_KEYED,
			$wc . 'attributes.php|aafm_exec_wc_update_product_attribute|wc_get_attribute#1' => self::COVERED_KEYED,
			$wc . 'attributes.php|aafm_exec_wc_update_product_attribute|wc_get_attribute#2' => self::COVERED_KEYED,
			$wc . 'coupons.php|aafm_wc_apply_coupon_input|wc_get_coupon_id_by_code#1'  => self::BY_SPEC_COUPON,
			$wc . 'coupons.php|aafm_wc_coupon_code_race_error|wc_get_coupon_id_by_code#1' => self::BY_SPEC_COUPON,
			$wc . 'shipping.php|aafm_wc_get_shipping_zone_object|get_zone#1'           => self::BY_SPEC_W5,
			$wc . 'shipping.php|aafm_wc_get_shipping_method_object|get_shipping_methods#1' => self::BY_SPEC_W5,
			$wc . 'shipping.php|aafm_exec_wc_list_shipping_methods|get_shipping_methods#1' => self::BY_SPEC_W5,
			$wc . 'gateways.php|aafm_exec_wc_list_payment_gateways|payment_gateways#1' => self::COVERED_MEMORY,
			$wc . 'gateways.php|aafm_exec_wc_get_payment_gateway|payment_gateways#1'   => self::COVERED_MEMORY,
			$wc . 'gateways.php|aafm_exec_wc_update_payment_gateway|payment_gateways#1' => self::COVERED_MEMORY,
		);
	}

	/**
	 * The by-id load calls in one file, keyed `path|function|loader#ordinal`.
	 *
	 * @param string $path Path relative to the plugin root.
	 * @return string[]
	 */
	private function loads_in( string $path ): array {
		$tokens   = array_values(
			array_filter(
				token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local source file.
				static fn( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
			)
		);
		$function = '';
		$seen     = array();
		$keys     = array();
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_FUNCTION === $token[0] && is_array( $tokens[ $i + 1 ] ?? null ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				$function = $tokens[ $i + 1 ][1];
				$seen     = array();
				continue;
			}
			// PHP 8 reads `\WC_Coupon` as one name token; PHP 7.4 reads a separator, then the name.
			$name = ltrim( $token[1], '\\' );
			if ( ! in_array( $token[0], array( T_STRING, defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : T_STRING ), true ) || ! in_array( $name, self::LOADERS, true ) ) {
				continue;
			}
			$prev = $tokens[ $i - 1 ] ?? '';
			if ( is_array( $prev ) && T_NS_SEPARATOR === $prev[0] ) {
				$prev = $tokens[ $i - 2 ] ?? '';
			}
			if ( '(' !== ( $tokens[ $i + 1 ] ?? '' ) || ( is_array( $prev ) && T_FUNCTION === $prev[0] ) ) {
				continue;
			}
			// A constructor with no argument builds a new object; it loads nothing.
			if ( is_array( $prev ) && T_NEW === $prev[0] && ')' === ( $tokens[ $i + 2 ] ?? '' ) ) {
				continue;
			}
			$seen[ $name ] = ( $seen[ $name ] ?? 0 ) + 1;
			$keys[]        = "$path|$function|$name#{$seen[ $name ]}";
		}
		return $keys;
	}

	/**
	 * Every by-id load is classified, and every classified key still matches exactly once.
	 */
	public function test_every_woocommerce_by_id_load_is_classified(): void {
		$found = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/includes/abilities/woocommerce/*.php' ) as $file ) {
			$found = array_merge( $found, $this->loads_in( 'includes/abilities/woocommerce/' . basename( $file ) ) );
		}
		$counts     = array_count_values( $found );
		$classified = $this->classified();

		$this->assertSame( array(), array_values( array_diff( array_keys( $counts ), array_keys( $classified ) ) ), 'unclassified by-id loads' );
		$this->assertSame( array(), array_values( array_diff( array_keys( $classified ), array_keys( $counts ) ) ), 'classified keys that no longer match' );
		$this->assertSame( array(), array_keys( array_filter( $counts, static fn( int $n ): bool => 1 !== $n ) ), 'keys matched more than once' );
	}
}
