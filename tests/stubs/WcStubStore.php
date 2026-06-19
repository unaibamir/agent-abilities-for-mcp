<?php
/**
 * Process-wide backing store for the WooCommerce host stubs (Wave 4 integration tests).
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
 * Process-wide backing store for the WooCommerce host stubs.
 *
 * WooCommerce's wc_get_product/wc_get_products and the WC_Product object are defined once per
 * process; this static store holds the seeded products keyed by id and the data each stub WC_Product
 * reads/writes, so a value written through ->save() (create or update) is visible to a following
 * wc_get_product()/wc_get_products() inside one test, and a ->delete() removes it. stub_woocommerce()
 * reset()s + seeds it each test, and reset_integration_stubs() clears it.
 */
class WcStubStore {

	/**
	 * Products keyed by id: id => array of product data (the WC_Product getter source).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $products = array();

	/**
	 * The next id handed out to a created product.
	 *
	 * @var int
	 */
	public static int $next_id = 1000;

	/**
	 * When true, delete() refuses to remove the row and reports failure — modelling a WC
	 * data-store delete failure so the delete-failure path is exercisable.
	 *
	 * @var bool
	 */
	public static bool $delete_should_fail = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$products           = array();
		self::$next_id            = 1000;
		self::$delete_should_fail = false;
	}

	/**
	 * Seed one product's data under its id (the test fixture's setup path).
	 *
	 * @param int                 $id   Product id.
	 * @param array<string,mixed> $data Product data.
	 * @return void
	 */
	public static function seed( int $id, array $data ): void {
		$data['id']            = $id;
		self::$products[ $id ] = self::with_defaults( $data );
		self::link_child_to_parent( $id, (int) ( self::$products[ $id ]['parent_id'] ?? 0 ) );
	}

	/**
	 * Whether a product id exists in the store.
	 *
	 * @param int $id Product id.
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		return isset( self::$products[ $id ] );
	}

	/**
	 * The stored data for a product id, or null.
	 *
	 * @param int $id Product id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		return self::$products[ $id ] ?? null;
	}

	/**
	 * Persist a product's data (create when id is 0/absent, else update), returning the id.
	 *
	 * When the row carries a parent_id (a variation), the parent product's children list is kept in
	 * sync so a following $parent->get_children() returns the new id — mirroring how a real variable
	 * product owns its variations.
	 *
	 * @param array<string,mixed> $data Product data, including 'id' (0 to create).
	 * @return int The persisted id.
	 */
	public static function save( array $data ): int {
		$id = (int) ( $data['id'] ?? 0 );
		if ( $id <= 0 ) {
			$id = self::$next_id;
			++self::$next_id;
		}
		$data['id']            = $id;
		self::$products[ $id ] = self::with_defaults( $data );

		self::link_child_to_parent( $id, (int) ( self::$products[ $id ]['parent_id'] ?? 0 ) );

		return $id;
	}

	/**
	 * Permanently remove a product id, unlinking it from its parent's children list if it is a
	 * variation.
	 *
	 * @param int $id Product id.
	 * @return bool True when removed, false when the configured failure flag is set.
	 */
	public static function delete( int $id ): bool {
		if ( self::$delete_should_fail ) {
			return false; // Model a WC data-store delete failure: nothing removed.
		}
		$parent_id = (int) ( self::$products[ $id ]['parent_id'] ?? 0 );
		unset( self::$products[ $id ] );
		if ( $parent_id > 0 ) {
			self::unlink_child_from_parent( $id, $parent_id );
		}
		return true;
	}

	/**
	 * Ensure a parent product's children list contains the child id (idempotent).
	 *
	 * @param int $child_id  The variation id.
	 * @param int $parent_id The parent product id (0 = not a variation).
	 * @return void
	 */
	private static function link_child_to_parent( int $child_id, int $parent_id ): void {
		if ( $parent_id <= 0 || ! isset( self::$products[ $parent_id ] ) ) {
			return;
		}
		$children = array_map( 'intval', (array) ( self::$products[ $parent_id ]['children'] ?? array() ) );
		if ( ! in_array( $child_id, $children, true ) ) {
			$children[]                               = $child_id;
			self::$products[ $parent_id ]['children'] = $children;
		}
	}

	/**
	 * Remove a child id from a parent product's children list.
	 *
	 * @param int $child_id  The variation id.
	 * @param int $parent_id The parent product id.
	 * @return void
	 */
	private static function unlink_child_from_parent( int $child_id, int $parent_id ): void {
		if ( ! isset( self::$products[ $parent_id ] ) ) {
			return;
		}
		$children                                 = array_map( 'intval', (array) ( self::$products[ $parent_id ]['children'] ?? array() ) );
		self::$products[ $parent_id ]['children'] = array_values(
			array_filter( $children, static fn( int $cid ): bool => $cid !== $child_id )
		);
	}

	/**
	 * Every stored product's data, in id order (the wc_get_products() source).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$out = array_values( self::$products );
		usort(
			$out,
			static fn( array $a, array $b ): int => ( (int) $a['id'] ) <=> ( (int) $b['id'] )
		);
		return $out;
	}

	/**
	 * The wc_get_products() stub: returns WC_Product objects for the seeded products, honouring the
	 * status filter and limit/page paging. Mirrors WC's default return ('objects').
	 *
	 * When `paginate` is set, mirrors WC's paginated return: a stdClass with `->products` (the page
	 * slice) and `->total` (the full matching count, before the page slice). Without `paginate` it
	 * returns the plain page-sliced array (back-compat).
	 *
	 * @param array<string,mixed> $args Query args (status, limit, page, paginate).
	 * @return array<int,\WC_Product>|object
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

		// The full matching count, captured before the page slice so paginate->total is the grand total.
		$total = count( $rows );

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		if ( $limit > 0 ) {
			$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
			$offset = ( $page - 1 ) * $limit;
			$rows   = array_slice( $rows, $offset, $limit );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = new \WC_Product( (int) $row['id'] );
		}

		if ( ! empty( $args['paginate'] ) ) {
			$result           = new \stdClass();
			$result->products = $out;
			$result->total    = $total;
			return $result;
		}

		return $out;
	}

	/**
	 * Fill a product data array with the defaults the WC_Product getters expect, so a partial seed
	 * or a partial create still reads back a complete, typed shape.
	 *
	 * @param array<string,mixed> $data Raw product data.
	 * @return array<string,mixed>
	 */
	private static function with_defaults( array $data ): array {
		return array_merge(
			array(
				'id'                => 0,
				'parent_id'         => 0,
				'name'              => '',
				'type'              => 'simple',
				'status'            => 'publish',
				'sku'               => '',
				'description'       => '',
				'short_description' => '',
				'price'             => '',
				'regular_price'     => '',
				'sale_price'        => '',
				'stock_status'      => 'instock',
				'stock_quantity'    => null,
				'manage_stock'      => false,
				'featured'          => false,
				'category_ids'      => array(),
				'tag_ids'           => array(),
				'gallery_image_ids' => array(),
				'image_id'          => 0,
				'attributes'        => array(),
				'children'          => array(),
			),
			$data
		);
	}
}
