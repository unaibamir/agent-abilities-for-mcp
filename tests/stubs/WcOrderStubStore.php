<?php
/**
 * Process-wide backing store for the WooCommerce order host stubs (Wave 4 integration tests).
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
 * Process-wide backing store for the WooCommerce order stubs.
 *
 * Functions wc_get_orders() and wc_get_order() are defined once per process; this static store holds the
 * seeded orders keyed by id and the data each stub WC_Order reads, so tests can assert the shape
 * of the order read abilities without WooCommerce installed. seed_wc_orders() resets + seeds it
 * per test, and reset_integration_stubs() clears it via reset().
 */
class WcOrderStubStore {

	/**
	 * Orders keyed by id: id => array of order data (the WC_Order getter source).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $orders = array();

	/**
	 * The next id handed out to a created order.
	 *
	 * @var int
	 */
	public static int $next_id = 5000;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$orders  = array();
		self::$next_id = 5000;
	}

	/**
	 * Seed one order's data under its id (the test fixture's setup path).
	 *
	 * @param int                 $id   Order id.
	 * @param array<string,mixed> $data Order data.
	 * @return void
	 */
	public static function seed( int $id, array $data ): void {
		$data['id']          = $id;
		self::$orders[ $id ] = self::with_defaults( $data );
	}

	/**
	 * Whether an order id exists in the store.
	 *
	 * @param int $id Order id.
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		return isset( self::$orders[ $id ] );
	}

	/**
	 * The stored data for an order id, or null.
	 *
	 * @param int $id Order id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		return self::$orders[ $id ] ?? null;
	}

	/**
	 * Every stored order's data, in id order (the wc_get_orders() source).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$out = array_values( self::$orders );
		usort(
			$out,
			static fn( array $a, array $b ): int => ( (int) $a['id'] ) <=> ( (int) $b['id'] )
		);
		return $out;
	}

	/**
	 * Query orders with limit/paged/status filtering, honoring the wc_get_orders() paginate shape.
	 *
	 * When `paginate` is true, returns a stdClass with ->orders (the page slice) and ->total (the
	 * full matching count before slicing). Without paginate it returns a plain array. This mirrors
	 * the real WooCommerce wc_get_orders() paginate contract the order abilities depend on.
	 *
	 * @param array<string,mixed> $args Query args (status, limit, paged, paginate).
	 * @return array<int,\WC_Order>|object
	 */
	public static function query( array $args = array() ) {
		$status = $args['status'] ?? '';
		$rows   = self::all();

		if ( '' !== $status && 'any' !== $status ) {
			$wanted = (array) $status;
			$rows   = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => in_array( (string) ( $row['status'] ?? '' ), $wanted, true )
				)
			);
		}

		// Capture the full matching count before the page slice so paginate->total is correct.
		$total = count( $rows );

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		if ( $limit > 0 ) {
			$paged  = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
			$offset = ( $paged - 1 ) * $limit;
			$rows   = array_slice( $rows, $offset, $limit );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = new \WC_Order( (int) $row['id'] );
		}

		if ( ! empty( $args['paginate'] ) ) {
			$result         = new \stdClass();
			$result->orders = $out;
			$result->total  = $total;
			return $result;
		}

		return $out;
	}

	/**
	 * Fill an order data array with the defaults the WC_Order getters expect, so a partial seed
	 * still reads back a complete, typed shape.
	 *
	 * @param array<string,mixed> $data Raw order data.
	 * @return array<string,mixed>
	 */
	private static function with_defaults( array $data ): array {
		return array_merge(
			array(
				'id'             => 0,
				'number'         => '',
				'status'         => 'processing',
				'total'          => '0.00',
				'currency'       => 'USD',
				'date_created'   => '2024-01-01T00:00:00',
				'date_paid'      => null,
				'customer_id'    => 0,
				'customer_note'  => '',
				'items'          => array(),
				'billing'        => array(
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
				'shipping'       => array(
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
				'total_tax'      => '0.00',
				'subtotal'       => '0.00',
				'shipping_total' => '0.00',
			),
			$data
		);
	}
}
