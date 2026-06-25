<?php
/**
 * Process-wide backing store for the WooCommerce global product-attribute stubs (Wave 4 W4-WC1c).
 *
 * Lives in its own file so the IntegrationStubs trait file holds a single object structure (the
 * trait), satisfying Generic.Files.OneObjectStructurePerFile. Required directly from the test
 * bootstrap alongside WcStubStore.php, never shipped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests;

/**
 * Process-wide backing store for the WooCommerce global product-attribute (taxonomy) stubs.
 *
 * Real WooCommerce stores global attributes in a custom table and surfaces them through
 * wc_get_attribute_taxonomies() / wc_create_attribute() / wc_update_attribute() /
 * wc_delete_attribute(). This static store mirrors that surface so tests exercise the same
 * code paths without requiring WooCommerce to be installed.
 *
 * Each attribute row is a stdClass with the real WC field names:
 *   attribute_id, attribute_name (slug, e.g. "color"), attribute_label (e.g. "Color"),
 *   attribute_type (e.g. "select"), attribute_orderby (e.g. "menu_order"), attribute_public (bool).
 */
class WcAttributeStubStore {

	/**
	 * Attributes keyed by id: id => stdClass row with real WC property names.
	 *
	 * @var array<int,\stdClass>
	 */
	public static array $attributes = array();

	/**
	 * The next id handed out on create.
	 *
	 * @var int
	 */
	public static int $next_id = 1;

	/**
	 * When true, the next delete() call returns false (simulates a WC data-store failure).
	 *
	 * @var bool
	 */
	public static bool $fail_delete = false;

	/**
	 * When true, the next create() call returns 0 (simulates a WC create failure).
	 *
	 * @var bool
	 */
	public static bool $fail_create = false;

	/**
	 * When true, the next update() call returns false (simulates a WC data-store failure).
	 *
	 * @var bool
	 */
	public static bool $fail_update = false;

	/**
	 * Clear all state (called at the start of every test via stub_woocommerce).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$attributes  = array();
		self::$next_id     = 1;
		self::$fail_delete = false;
		self::$fail_create = false;
		self::$fail_update = false;
	}

	/**
	 * Control whether the next delete() call should simulate a store failure.
	 *
	 * @param bool $should_fail Pass true to make the next delete() return false.
	 * @return void
	 */
	public static function set_delete_should_fail( bool $should_fail ): void {
		self::$fail_delete = $should_fail;
	}

	/**
	 * Control whether the next create() call should simulate a store failure.
	 *
	 * @param bool $should_fail Pass true to make the next create() return 0.
	 * @return void
	 */
	public static function set_create_should_fail( bool $should_fail ): void {
		self::$fail_create = $should_fail;
	}

	/**
	 * Control whether the next update() call should simulate a store failure.
	 *
	 * @param bool $should_fail Pass true to make the next update() return false.
	 * @return void
	 */
	public static function set_update_should_fail( bool $should_fail ): void {
		self::$fail_update = $should_fail;
	}

	/**
	 * Seed one attribute directly (the test fixture's setup path).
	 *
	 * @param int       $id  Attribute id.
	 * @param \stdClass $row Row with real WC field names.
	 * @return void
	 */
	public static function seed( int $id, \stdClass $row ): void {
		$row->attribute_id       = $id;
		self::$attributes[ $id ] = $row;
		if ( $id >= self::$next_id ) {
			self::$next_id = $id + 1;
		}
	}

	/**
	 * All stored attributes as an indexed array of stdClass rows (in id order).
	 *
	 * Mirrors wc_get_attribute_taxonomies() return type.
	 *
	 * @return \stdClass[]
	 */
	public static function all(): array {
		$rows = array_values( self::$attributes );
		usort(
			$rows,
			static fn( \stdClass $a, \stdClass $b ): int => ( (int) $a->attribute_id ) <=> ( (int) $b->attribute_id )
		);
		return $rows;
	}

	/**
	 * Retrieve one attribute by id, or null.
	 *
	 * @param int $id Attribute id.
	 * @return \stdClass|null
	 */
	public static function get( int $id ): ?\stdClass {
		return self::$attributes[ $id ] ?? null;
	}

	/**
	 * Create a new attribute row from a wc_create_attribute()-style args array, returning the new id.
	 *
	 * The wc_create_attribute() args are name (label), slug (attribute_name), type, order_by, has_archives.
	 * Returns 0 when $fail_create is set (simulates a WC data-store failure).
	 *
	 * @param array<string,mixed> $args Attribute args.
	 * @return int New attribute id, or 0 on simulated failure.
	 */
	public static function create( array $args ): int {
		if ( self::$fail_create ) {
			self::$fail_create = false;
			return 0;
		}

		$id = self::$next_id;
		++self::$next_id;

		$row                    = new \stdClass();
		$row->attribute_id      = $id;
		$row->attribute_label   = (string) ( $args['name'] ?? '' );
		$row->attribute_name    = (string) ( $args['slug'] ?? sanitize_title( $row->attribute_label ) );
		$row->attribute_type    = (string) ( $args['type'] ?? 'select' );
		$row->attribute_orderby = (string) ( $args['order_by'] ?? 'menu_order' );
		$row->attribute_public  = (bool) ( $args['has_archives'] ?? false );

		self::$attributes[ $id ] = $row;
		return $id;
	}

	/**
	 * Update an existing attribute row from a wc_update_attribute()-style args array.
	 *
	 * Only keys present in $args are merged; absent keys are left unchanged.
	 * Returns false when $fail_update is set (simulates a WC data-store failure).
	 *
	 * @param int                 $id   Attribute id.
	 * @param array<string,mixed> $args Partial attribute args (same key set as create).
	 * @return bool True on success, false on simulated failure or when id is unknown.
	 */
	public static function update( int $id, array $args ): bool {
		if ( self::$fail_update ) {
			self::$fail_update = false;
			return false;
		}
		if ( ! isset( self::$attributes[ $id ] ) ) {
			return false;
		}
		$row = self::$attributes[ $id ];
		if ( array_key_exists( 'name', $args ) ) {
			$row->attribute_label = (string) $args['name'];
		}
		if ( array_key_exists( 'slug', $args ) ) {
			$row->attribute_name = (string) $args['slug'];
		}
		if ( array_key_exists( 'type', $args ) ) {
			$row->attribute_type = (string) $args['type'];
		}
		if ( array_key_exists( 'order_by', $args ) ) {
			$row->attribute_orderby = (string) $args['order_by'];
		}
		if ( array_key_exists( 'has_archives', $args ) ) {
			$row->attribute_public = (bool) $args['has_archives'];
		}
		self::$attributes[ $id ] = $row;
		return true;
	}

	/**
	 * Permanently delete an attribute by id.
	 *
	 * Returns false when $fail_delete is set (simulates a WC data-store failure without
	 * actually removing the row — so a subsequent get() still finds the attribute).
	 *
	 * @param int $id Attribute id.
	 * @return bool True on success, false on simulated failure or when id is unknown.
	 */
	public static function delete( int $id ): bool {
		if ( self::$fail_delete ) {
			self::$fail_delete = false;
			return false;
		}
		if ( ! isset( self::$attributes[ $id ] ) ) {
			return false;
		}
		unset( self::$attributes[ $id ] );
		return true;
	}
}
