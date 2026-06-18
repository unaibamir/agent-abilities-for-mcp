<?php
/**
 * Process-wide backing store for the WooCommerce coupon stubs (Wave 4 / W4-WC4 integration tests).
 *
 * Lives in its own file so the IntegrationStubs trait file holds a single object structure (the
 * trait), satisfying Generic.Files.OneObjectStructurePerFile. Required directly from the test
 * bootstrap, never shipped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

/**
 * Process-wide backing store for the WooCommerce coupon stubs.
 *
 * WooCommerce's WC_Coupon object and wc_get_coupons() are defined once per process; this static
 * store holds seeded coupons keyed by id so a value written through ->save() (create or update)
 * is visible to a following wc_get_coupons() / new WC_Coupon() inside one test, and ->delete()
 * removes it. stub_wc_coupons() reset()s and seeds it each test; reset_integration_stubs() clears
 * it on tear-down.
 */
class WcCouponStubStore {

	/**
	 * Coupons keyed by id: id => array of coupon data.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $coupons = array();

	/**
	 * The next id handed out to a created coupon.
	 *
	 * @var int
	 */
	public static int $next_id = 5000;

	/**
	 * When true, save() returns 0 so create/update failure paths are exercisable.
	 *
	 * @var bool
	 */
	public static bool $force_save_failure = false;

	/**
	 * When true, delete() returns false so the delete-failure path is exercisable.
	 *
	 * @var bool
	 */
	public static bool $force_delete_failure = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$coupons              = array();
		self::$next_id              = 5000;
		self::$force_save_failure   = false;
		self::$force_delete_failure = false;
	}

	/**
	 * Seed one coupon's data under its id (the test fixture's setup path).
	 *
	 * @param int                 $id   Coupon id.
	 * @param array<string,mixed> $data Coupon data.
	 * @return void
	 */
	public static function seed( int $id, array $data ): void {
		$data['id']           = $id;
		self::$coupons[ $id ] = self::with_defaults( $data );
	}

	/**
	 * Whether a coupon id exists in the store.
	 *
	 * @param int $id Coupon id.
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		return isset( self::$coupons[ $id ] );
	}

	/**
	 * The stored data for a coupon id, or null.
	 *
	 * @param int $id Coupon id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		return self::$coupons[ $id ] ?? null;
	}

	/**
	 * Look up a coupon id by its code string (mirrors wc_get_coupon_id_by_code()).
	 *
	 * @param string $code Coupon code.
	 * @return int 0 when not found.
	 */
	public static function get_id_by_code( string $code ): int {
		foreach ( self::$coupons as $id => $data ) {
			if ( strtolower( (string) ( $data['code'] ?? '' ) ) === strtolower( $code ) ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Persist coupon data (create when id is 0/absent, else update), returning the id.
	 *
	 * Returns 0 when self::$force_save_failure is true.
	 *
	 * @param array<string,mixed> $data Coupon data, including 'id' (0 to create).
	 * @return int The persisted id, or 0 on forced failure.
	 */
	public static function save( array $data ): int {
		if ( self::$force_save_failure ) {
			return 0;
		}

		$id = (int) ( $data['id'] ?? 0 );
		if ( $id <= 0 ) {
			$id = self::$next_id;
			++self::$next_id;
		}
		$data['id']           = $id;
		self::$coupons[ $id ] = self::with_defaults( $data );
		return $id;
	}

	/**
	 * Permanently remove a coupon by id.
	 *
	 * Returns false when self::$force_delete_failure is true.
	 *
	 * @param int $id Coupon id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		if ( self::$force_delete_failure ) {
			return false;
		}
		unset( self::$coupons[ $id ] );
		return true;
	}

	/**
	 * All stored coupons in id order (the wc_get_coupons() source).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$out = array_values( self::$coupons );
		usort(
			$out,
			static fn( array $a, array $b ): int => ( (int) $a['id'] ) <=> ( (int) $b['id'] )
		);
		return $out;
	}

	/**
	 * The wc_get_coupons() stub: returns WC_Coupon objects for seeded coupons, honouring limit/page
	 * paging. When `paginate` is set, returns a stdClass with `->coupons` and `->total`; otherwise
	 * returns the plain page-sliced array.
	 *
	 * @param array<string,mixed> $args Query args (limit, page, paginate).
	 * @return array<int,\WC_Coupon>|object
	 */
	public static function query( array $args = array() ) {
		$rows  = self::all();
		$total = count( $rows );

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		if ( $limit > 0 ) {
			$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
			$offset = ( $page - 1 ) * $limit;
			$rows   = array_slice( $rows, $offset, $limit );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = new \WC_Coupon( (int) $row['id'] );
		}

		if ( ! empty( $args['paginate'] ) ) {
			$result          = new \stdClass();
			$result->coupons = $out;
			$result->total   = $total;
			return $result;
		}

		return $out;
	}

	/**
	 * Fill a coupon data array with the defaults the WC_Coupon getters expect, so a partial seed
	 * or a partial create still reads back a complete, typed shape.
	 *
	 * @param array<string,mixed> $data Raw coupon data.
	 * @return array<string,mixed>
	 */
	private static function with_defaults( array $data ): array {
		return array_merge(
			array(
				'id'                   => 0,
				'code'                 => '',
				'amount'               => '0.00',
				'discount_type'        => 'fixed_cart',
				'description'          => '',
				'date_expires'         => null,
				'usage_count'          => 0,
				'usage_limit'          => null,
				'usage_limit_per_user' => null,
				'minimum_amount'       => '',
				'maximum_amount'       => '',
				'individual_use'       => false,
				'exclude_sale_items'   => false,
				'product_ids'          => array(),
				'excluded_product_ids' => array(),
				'email_restrictions'   => array(),
			),
			$data
		);
	}
}
