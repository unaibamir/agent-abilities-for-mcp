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
 * WooCommerce stores coupons as the 'shop_coupon' post type; the list ability queries them with
 * WP_Query and hydrates each through WC_Coupon. To exercise that real path the store seeds genuine
 * shop_coupon posts (so WP_Query finds them) and keeps the field data in this static map keyed by
 * the post id (so the WC_Coupon getters can read it). A value written through ->save() (create or
 * update) is visible to a following WP_Query / new WC_Coupon() inside one test, and ->delete()
 * removes both the post and the field row. stub_wc_coupons() reset()s and seeds it each test;
 * reset_integration_stubs() clears it on tear-down.
 */
class WcCouponStubStore {

	/**
	 * Coupons keyed by id: id => array of coupon data.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $coupons = array();

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
	 * Clear all state, removing any shop_coupon posts created during the test.
	 *
	 * @return void
	 */
	public static function reset(): void {
		foreach ( array_keys( self::$coupons ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		self::$coupons              = array();
		self::$force_save_failure   = false;
		self::$force_delete_failure = false;
	}

	/**
	 * Seed one coupon's data, backed by a real shop_coupon post forced to the given id.
	 *
	 * @param int                 $id   Coupon id (forced as the post id).
	 * @param array<string,mixed> $data Coupon data.
	 * @return void
	 */
	public static function seed( int $id, array $data ): void {
		$data['id'] = $id;
		self::insert_post( $id, (string) ( $data['code'] ?? '' ) );
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
	 * On create a real shop_coupon post is inserted so the list query (WP_Query) sees it.
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
			$id = self::insert_post( 0, (string) ( $data['code'] ?? '' ) );
		} else {
			self::insert_post( $id, (string) ( $data['code'] ?? '' ) );
		}
		$data['id']           = $id;
		self::$coupons[ $id ] = self::with_defaults( $data );
		return $id;
	}

	/**
	 * Permanently remove a coupon by id, deleting both the field row and the shop_coupon post.
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
		wp_delete_post( $id, true );
		unset( self::$coupons[ $id ] );
		return true;
	}

	/**
	 * Insert (or re-use) a published shop_coupon post for a coupon id.
	 *
	 * Passing $id = 0 lets WordPress assign the id; passing a positive id forces it via import_id so
	 * seeded fixtures keep their stable ids. Returns the resulting post id.
	 *
	 * @param int    $id   Forced post id, or 0 to let WordPress assign one.
	 * @param string $code Coupon code, used as the post title.
	 * @return int
	 */
	private static function insert_post( int $id, string $code ): int {
		if ( $id > 0 && null !== get_post( $id ) ) {
			return $id;
		}
		$args = array(
			'post_type'   => 'shop_coupon',
			'post_status' => 'publish',
			'post_title'  => strtolower( $code ),
		);
		if ( $id > 0 ) {
			$args['import_id'] = $id;
		}
		return (int) wp_insert_post( $args );
	}

	/**
	 * Fill a coupon data array with the defaults the WC_Coupon getters expect, so a partial seed
	 * or a partial create still reads back a complete, typed shape.
	 *
	 * @param array<string,mixed> $data Raw coupon data.
	 * @return array<string,mixed>
	 */
	private static function with_defaults( array $data ): array {
		$merged = array_merge(
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
		// Real WooCommerce lowercases coupon codes on save; mirror that so seeded codes behave
		// consistently with codes written through set_code() + save().
		if ( isset( $merged['code'] ) && '' !== $merged['code'] ) {
			$merged['code'] = strtolower( (string) $merged['code'] );
		}
		return $merged;
	}
}
