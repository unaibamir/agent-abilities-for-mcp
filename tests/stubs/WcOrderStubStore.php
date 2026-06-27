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
 * Process-wide backing store for the WooCommerce order, note, and refund stubs.
 *
 * Functions wc_get_orders(), wc_get_order(), wc_get_order_notes(), wc_delete_order_note(), and
 * wc_create_refund() are defined once per process; this static store holds the seeded data each
 * stub reads and writes, so tests can assert the shape of the order abilities without WooCommerce
 * installed. seed_wc_orders() resets + seeds it per test, and reset_integration_stubs() clears it
 * via reset().
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
	 * Order notes keyed by order_id, then by note_id.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public static array $notes = array();

	/**
	 * The next id handed out to a created note.
	 *
	 * @var int
	 */
	public static int $next_note_id = 1000;

	/**
	 * Refunds keyed by order_id, then by refund_id.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public static array $refunds = array();

	/**
	 * Refunds keyed globally by refund_id for cross-order lookup.
	 *
	 * @var array<int,int>
	 */
	public static array $refund_order_map = array();

	/**
	 * The next id handed out to a created refund.
	 *
	 * @var int
	 */
	public static int $next_refund_id = 2000;

	/**
	 * When true, WC_Order::delete() returns false so the "don't lie success" guard is exercised.
	 *
	 * @var bool
	 */
	public static bool $delete_should_fail = false;

	/**
	 * When true, wc_delete_order_note() returns false so the note-delete failure path is exercised.
	 *
	 * @var bool
	 */
	public static bool $delete_note_should_fail = false;

	/**
	 * When true, WC_Order_Refund::delete() returns false so the refund-delete failure path is exercised.
	 *
	 * @var bool
	 */
	public static bool $delete_refund_should_fail = false;

	/**
	 * When true, WC_Order::add_order_note() returns 0 so the "don't lie success" guard is exercised.
	 *
	 * @var bool
	 */
	public static bool $add_note_should_fail = false;

	/**
	 * The args from the most recent query() call, so a test can assert what was pushed into
	 * wc_get_orders() (e.g. that a date window constrained the query rather than -1 + PHP filter).
	 *
	 * @var array<string,mixed>
	 */
	public static array $last_query_args = array();

	/**
	 * The full args from the most recent wc_create_refund() call, so a test can assert how a line
	 * item's refund_tax was distributed across its tax rates before it was handed to WooCommerce.
	 *
	 * @var array<string,mixed>
	 */
	public static array $last_refund_args = array();

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$orders                    = array();
		self::$next_id                   = 5000;
		self::$notes                     = array();
		self::$next_note_id              = 1000;
		self::$refunds                   = array();
		self::$refund_order_map          = array();
		self::$next_refund_id            = 2000;
		self::$delete_should_fail        = false;
		self::$delete_note_should_fail   = false;
		self::$delete_refund_should_fail = false;
		self::$add_note_should_fail      = false;
		self::$last_query_args           = array();
		self::$last_refund_args          = array();
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
	 * Persist an order data array to the store, assigning a new id when none is provided.
	 *
	 * Used by the WC_Order stub's save() method so create/update abilities can round-trip
	 * through the stub store without WooCommerce installed.
	 *
	 * @param array<string,mixed> $data Order data. An id of 0 or absent triggers auto-assign.
	 * @return int The assigned or existing order id.
	 */
	public static function save( array $data ): int {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $id < 1 ) {
			$id         = self::$next_id++;
			$data['id'] = $id;
		}
		self::$orders[ $id ] = self::with_defaults( $data );
		return $id;
	}

	/**
	 * Remove an order from the store.
	 *
	 * Returns true on success, false if the fail-flag is set (letting tests exercise the
	 * "don't lie success" guard in the delete executor).
	 *
	 * @param int $id Order id.
	 * @return bool
	 */
	public static function delete_order( int $id ): bool {
		if ( self::$delete_should_fail ) {
			return false;
		}
		unset( self::$orders[ $id ] );
		return true;
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
	 * @param array<string,mixed> $args Query args (status, date_created, limit, paged, paginate).
	 * @return array<int,\WC_Order>|object
	 */
	public static function query( array $args = array() ) {
		self::$last_query_args = $args;
		$status                = $args['status'] ?? '';
		$rows                  = self::all();

		if ( '' !== $status && 'any' !== $status ) {
			$wanted = (array) $status;
			$rows   = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => in_array( (string) ( $row['status'] ?? '' ), $wanted, true )
				)
			);
		}

		// Date window: model wc_get_orders()'s `date_created => '>=<ts>'` lower-bound form, the
		// shape the top-sellers report uses to push its window into the query. An order with no
		// date_created (or one before the bound) is excluded, exactly as WC would exclude it.
		if ( isset( $args['date_created'] ) && is_string( $args['date_created'] )
			&& 0 === strpos( $args['date_created'], '>=' ) ) {
			$bound_ts = (int) substr( $args['date_created'], 2 );
			$rows     = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $bound_ts ): bool {
						$created = (string) ( $row['date_created'] ?? '' );
						return '' !== $created && (int) strtotime( $created ) >= $bound_ts;
					}
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

	// -------------------------------------------------------------------------
	// Notes API
	// -------------------------------------------------------------------------

	/**
	 * Seed the notes for an order, replacing any existing notes for that order.
	 *
	 * Each note in $notes must carry at least 'id' and 'note'. Missing fields are filled with
	 * defaults so the redact helper never reads undefined keys.
	 *
	 * @param int                            $order_id Order id.
	 * @param array<int,array<string,mixed>> $notes   Note rows to seed.
	 * @return void
	 */
	public static function seed_notes( int $order_id, array $notes ): void {
		self::$notes[ $order_id ] = array();
		foreach ( $notes as $note ) {
			$id                              = (int) ( $note['id'] ?? self::$next_note_id++ );
			self::$notes[ $order_id ][ $id ] = self::with_note_defaults( array_merge( $note, array( 'id' => $id ) ) );
		}
	}

	/**
	 * All notes for an order as an array of stdClass objects (mirrors real wc_get_order_notes() shape).
	 *
	 * @param int $order_id Order id.
	 * @return array<int,object>
	 */
	public static function get_notes( int $order_id ): array {
		$rows = self::$notes[ $order_id ] ?? array();
		return array_values(
			array_map(
				static function ( array $row ): object {
					// Mirror the normalized objects real wc_get_order_notes() returns: ->id and ->content.
					$obj                = new \stdClass();
					$obj->id            = (int) $row['id'];
					$obj->content       = (string) $row['note'];
					$obj->added_by      = $row['added_by_user'] ? 'user' : '';
					$obj->date_created  = (string) $row['date_created'];
					$obj->customer_note = (bool) $row['customer_note'];
					return $obj;
				},
				$rows
			)
		);
	}

	/**
	 * Retrieve a single note by its id, or null if not found.
	 *
	 * @param int $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public static function get_note_by_id( int $note_id ): ?array {
		foreach ( self::$notes as $order_notes ) {
			if ( isset( $order_notes[ $note_id ] ) ) {
				return $order_notes[ $note_id ];
			}
		}
		return null;
	}

	/**
	 * Add a note to an order and return the new note's id.
	 *
	 * @param int    $order_id      Order id.
	 * @param string $note          Note text.
	 * @param bool   $customer_note Whether this is a customer-facing note.
	 * @return int The new note id.
	 */
	public static function add_note( int $order_id, string $note, bool $customer_note = false ): int {
		if ( self::$add_note_should_fail ) {
			return 0;
		}
		$id = self::$next_note_id++;
		if ( ! isset( self::$notes[ $order_id ] ) ) {
			self::$notes[ $order_id ] = array();
		}
		self::$notes[ $order_id ][ $id ] = self::with_note_defaults(
			array(
				'id'            => $id,
				'note'          => $note,
				'added_by_user' => true,
				'date_created'  => gmdate( 'Y-m-d\TH:i:s' ),
				'customer_note' => $customer_note,
			)
		);
		return $id;
	}

	/**
	 * Delete a note by id.
	 *
	 * Returns false when the fail-flag is set so the "don't lie success" guard can be tested.
	 *
	 * @param int $note_id Note id.
	 * @return bool
	 */
	public static function delete_note( int $note_id ): bool {
		if ( self::$delete_note_should_fail ) {
			return false;
		}
		foreach ( self::$notes as $order_id => &$order_notes ) {
			if ( isset( $order_notes[ $note_id ] ) ) {
				unset( $order_notes[ $note_id ] );
				return true;
			}
		}
		unset( $order_notes );
		return false;
	}

	// -------------------------------------------------------------------------
	// Refunds API
	// -------------------------------------------------------------------------

	/**
	 * Seed the refunds for an order, replacing any existing refunds for that order.
	 *
	 * Each refund in $refunds must carry at least 'id', 'amount', and 'reason'. Missing fields
	 * are filled with defaults. The global refund_order_map is also updated for cross-order
	 * lookup (used by wc-get-order-refund and wc-delete-order-refund).
	 *
	 * @param int                            $order_id Order id.
	 * @param array<int,array<string,mixed>> $refunds Refund rows to seed.
	 * @return void
	 */
	public static function seed_refunds( int $order_id, array $refunds ): void {
		// Remove old cross-order map entries for this order.
		foreach ( array_keys( self::$refunds[ $order_id ] ?? array() ) as $old_refund_id ) {
			unset( self::$refund_order_map[ $old_refund_id ] );
		}
		self::$refunds[ $order_id ] = array();
		foreach ( $refunds as $refund ) {
			$id                                = (int) ( $refund['id'] ?? self::$next_refund_id++ );
			$row                               = self::with_refund_defaults( array_merge( $refund, array( 'id' => $id ) ) );
			self::$refunds[ $order_id ][ $id ] = $row;
			self::$refund_order_map[ $id ]     = $order_id;
		}
	}

	/**
	 * All refunds for an order as WC_Order_Refund stub objects.
	 *
	 * @param int $order_id Order id.
	 * @return array<int,\WC_Order_Refund>
	 */
	public static function get_refunds_for_order( int $order_id ): array {
		$rows = self::$refunds[ $order_id ] ?? array();
		return array_values(
			array_map(
				static fn( array $row ): \WC_Order_Refund => new \WC_Order_Refund( (int) $row['id'] ),
				$rows
			)
		);
	}

	/**
	 * Retrieve a refund row by its id, or null if not found.
	 *
	 * @param int $refund_id Refund id.
	 * @return array<string,mixed>|null
	 */
	public static function get_refund_by_id( int $refund_id ): ?array {
		$order_id = self::$refund_order_map[ $refund_id ] ?? null;
		if ( null === $order_id ) {
			return null;
		}
		return self::$refunds[ $order_id ][ $refund_id ] ?? null;
	}

	/**
	 * Add a refund to an order and return the new WC_Order_Refund stub object, or false on failure.
	 *
	 * @param int    $order_id Order id.
	 * @param string $amount   Refund amount as a decimal string.
	 * @param string $reason   Refund reason.
	 * @return \WC_Order_Refund|false
	 */
	public static function add_refund( int $order_id, string $amount, string $reason = '' ) {
		$id  = self::$next_refund_id++;
		$row = self::with_refund_defaults(
			array(
				'id'           => $id,
				'amount'       => $amount,
				'reason'       => $reason,
				'date_created' => gmdate( 'Y-m-d\TH:i:s' ),
			)
		);
		if ( ! isset( self::$refunds[ $order_id ] ) ) {
			self::$refunds[ $order_id ] = array();
		}
		self::$refunds[ $order_id ][ $id ] = $row;
		self::$refund_order_map[ $id ]     = $order_id;
		return new \WC_Order_Refund( $id );
	}

	/**
	 * Delete a refund by id.
	 *
	 * Returns false when the fail-flag is set so the "don't lie success" guard can be tested.
	 *
	 * @param int $refund_id Refund id.
	 * @return bool
	 */
	public static function delete_refund( int $refund_id ): bool {
		if ( self::$delete_refund_should_fail ) {
			return false;
		}
		$order_id = self::$refund_order_map[ $refund_id ] ?? null;
		if ( null === $order_id ) {
			return false;
		}
		unset( self::$refunds[ $order_id ][ $refund_id ], self::$refund_order_map[ $refund_id ] );
		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

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

	/**
	 * Fill a note data array with defaults.
	 *
	 * @param array<string,mixed> $data Raw note data.
	 * @return array<string,mixed>
	 */
	private static function with_note_defaults( array $data ): array {
		return array_merge(
			array(
				'id'            => 0,
				'note'          => '',
				'added_by_user' => false,
				'date_created'  => '2024-01-01T00:00:00',
				'customer_note' => false,
			),
			$data
		);
	}

	/**
	 * Fill a refund data array with defaults.
	 *
	 * @param array<string,mixed> $data Raw refund data.
	 * @return array<string,mixed>
	 */
	private static function with_refund_defaults( array $data ): array {
		return array_merge(
			array(
				'id'           => 0,
				'amount'       => '0.00',
				'reason'       => '',
				'date_created' => '2024-01-01T00:00:00',
			),
			$data
		);
	}
}
