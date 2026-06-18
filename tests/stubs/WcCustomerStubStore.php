<?php
/**
 * Process-wide backing store for the WooCommerce customer host stubs (Wave 4 integration tests).
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
 * Process-wide backing store for the WooCommerce customer stubs.
 *
 * The WC_Customer class stub and free functions wc_create_customer() / wc_update_customer() are
 * defined once per process; this static store holds the seeded data each stub reads and writes,
 * so tests can assert the shape of the customer abilities without WooCommerce installed.
 * seed_wc_customers() resets + seeds it per test, and reset_integration_stubs() clears it via
 * reset().
 */
class WcCustomerStubStore {

	/**
	 * Customers keyed by id: id => array of customer data (the WC_Customer getter source).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $customers = array();

	/**
	 * The next id handed out to a created customer.
	 *
	 * @var int
	 */
	public static int $next_id = 7000;

	/**
	 * When true, WC_Customer::save() returns 0 so the "don't lie success" guard is exercised.
	 *
	 * @var bool
	 */
	public static bool $create_should_fail = false;

	/**
	 * When true, WC_Customer::save() on an existing customer returns 0 on update path.
	 *
	 * @var bool
	 */
	public static bool $update_should_fail = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$customers          = array();
		self::$next_id            = 7000;
		self::$create_should_fail = false;
		self::$update_should_fail = false;
	}

	/**
	 * Seed one customer's data under its id (the test fixture's setup path).
	 *
	 * @param int                 $id   Customer id.
	 * @param array<string,mixed> $data Customer data.
	 * @return void
	 */
	public static function seed( int $id, array $data ): void {
		$data['id']             = $id;
		self::$customers[ $id ] = self::with_defaults( $data );
	}

	/**
	 * Whether a customer id exists in the store.
	 *
	 * @param int $id Customer id.
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		return isset( self::$customers[ $id ] );
	}

	/**
	 * The stored data for a customer id, or null.
	 *
	 * @param int $id Customer id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		return self::$customers[ $id ] ?? null;
	}

	/**
	 * Persist a customer data array to the store, assigning a new id when none is provided.
	 *
	 * Used by the WC_Customer stub's save() method so create/update abilities can round-trip
	 * through the stub store without WooCommerce installed.
	 *
	 * @param array<string,mixed> $data Customer data. An id of 0 or absent triggers auto-assign.
	 * @param bool                $is_new True when this is a new customer (create path).
	 * @return int The assigned or existing customer id.
	 */
	public static function save( array $data, bool $is_new = false ): int {
		if ( $is_new && self::$create_should_fail ) {
			return 0;
		}
		if ( ! $is_new && self::$update_should_fail ) {
			return 0;
		}
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $id < 1 ) {
			$id         = self::$next_id++;
			$data['id'] = $id;
		}
		self::$customers[ $id ] = self::with_defaults( $data );
		return $id;
	}

	/**
	 * Every stored customer's data, in id order (the list-customers source).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$out = array_values( self::$customers );
		usort(
			$out,
			static fn( array $a, array $b ): int => ( (int) $a['id'] ) <=> ( (int) $b['id'] )
		);
		return $out;
	}

	/**
	 * Query customers with limit/paged filtering.
	 *
	 * Returns a plain array of WC_Customer objects, mirroring the shape the customer abilities use.
	 *
	 * @param array<string,mixed> $args Query args (limit, paged).
	 * @return array<int,\WC_Customer>
	 */
	public static function query( array $args = array() ): array {
		$rows  = self::all();
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		if ( $limit > 0 ) {
			$paged  = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
			$offset = ( $paged - 1 ) * $limit;
			$rows   = array_slice( $rows, $offset, $limit );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = new \WC_Customer( (int) $row['id'] );
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fill a customer data array with the defaults the WC_Customer getters expect, so a partial
	 * seed still reads back a complete, typed shape.
	 *
	 * @param array<string,mixed> $data Raw customer data.
	 * @return array<string,mixed>
	 */
	private static function with_defaults( array $data ): array {
		return array_merge(
			array(
				'id'           => 0,
				'email'        => '',
				'first_name'   => '',
				'last_name'    => '',
				'username'     => '',
				'orders_count' => 0,
				'total_spent'  => '0.00',
				'date_created' => '2024-01-01T00:00:00',
				'billing'      => array(
					'first_name' => '',
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => '',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
					'email'      => '',
					'phone'      => '',
				),
				'shipping'     => array(
					'first_name' => '',
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => '',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
				),
			),
			$data
		);
	}
}
