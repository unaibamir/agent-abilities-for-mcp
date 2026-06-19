<?php
/**
 * Process-wide backing store for the WooCommerce shipping zone and method stubs (Wave 4 / W4-WC5 integration tests).
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
 * Process-wide backing store for the WooCommerce shipping zone and method stubs.
 *
 * WooCommerce's WC_Shipping_Zone object and WC_Shipping_Zones::get_zones() are defined once per
 * process; this static store holds seeded zones keyed by zone_id and methods keyed by a composite
 * zone_id+instance_id string so a value written through ->save() is visible to a following
 * WC_Shipping_Zones::get_zones() / new WC_Shipping_Zone() inside one test, and ->delete() removes
 * it. stub_wc_shipping() reset()s and seeds it each test; reset_integration_stubs() clears it on
 * tear-down.
 */
class WcShippingStubStore {

	/**
	 * Zones keyed by zone_id: zone_id => array of zone data.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $zones = array();

	/**
	 * Methods keyed by "{zone_id}_{instance_id}": composite => array of method data.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static array $methods = array();

	/**
	 * The next zone id handed out to a created zone.
	 *
	 * @var int
	 */
	public static int $next_zone_id = 9000;

	/**
	 * The next instance id handed out to a created shipping method.
	 *
	 * @var int
	 */
	public static int $next_instance_id = 1;

	/**
	 * When true, zone/method save() returns 0 so create/update failure paths are exercisable.
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
		self::$zones                = array();
		self::$methods              = array();
		self::$next_zone_id         = 9000;
		self::$next_instance_id     = 1;
		self::$force_save_failure   = false;
		self::$force_delete_failure = false;
	}

	// =========================================================================
	// Zone helpers
	// =========================================================================

	/**
	 * Seed one zone's data under its zone_id (the test fixture setup path).
	 *
	 * @param int                 $id   Zone id.
	 * @param array<string,mixed> $data Zone data.
	 * @return void
	 */
	public static function seed( int $id, array $data ): void {
		$data['zone_id']    = $id;
		self::$zones[ $id ] = self::with_zone_defaults( $data );
	}

	/**
	 * Whether a zone id exists in the store.
	 *
	 * @param int $id Zone id.
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		return isset( self::$zones[ $id ] );
	}

	/**
	 * The stored data for a zone id, or null.
	 *
	 * @param int $id Zone id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		return self::$zones[ $id ] ?? null;
	}

	/**
	 * All stored zones in zone_order order (the WC_Shipping_Zones::get_zones() source).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$out = array_values( self::$zones );
		usort(
			$out,
			static fn( array $a, array $b ): int => ( (int) $a['zone_order'] ) <=> ( (int) $b['zone_order'] )
		);
		return $out;
	}

	/**
	 * Persist zone data (create when zone_id is absent/0, else update), returning the zone_id.
	 *
	 * Returns 0 when self::$force_save_failure is true.
	 *
	 * @param array<string,mixed> $data Zone data, including 'zone_id' (0 to create).
	 * @return int The persisted zone_id, or 0 on forced failure.
	 */
	public static function save_zone( array $data ): int {
		if ( self::$force_save_failure ) {
			return 0;
		}

		$id = (int) ( $data['zone_id'] ?? 0 );
		if ( $id <= 0 ) {
			$id = self::$next_zone_id;
			++self::$next_zone_id;
		}
		$data['zone_id']    = $id;
		self::$zones[ $id ] = self::with_zone_defaults( $data );
		return $id;
	}

	/**
	 * Permanently remove a zone by zone_id.
	 *
	 * Returns false when self::$force_delete_failure is true.
	 *
	 * @param int $id Zone id.
	 * @return bool
	 */
	public static function delete_zone( int $id ): bool {
		if ( self::$force_delete_failure ) {
			return false;
		}
		unset( self::$zones[ $id ] );
		// Also remove all methods belonging to this zone.
		foreach ( array_keys( self::$methods ) as $key ) {
			if ( str_starts_with( $key, $id . '_' ) ) {
				unset( self::$methods[ $key ] );
			}
		}
		return true;
	}

	/**
	 * Fill a zone data array with the defaults the WC_Shipping_Zone getters expect.
	 *
	 * @param array<string,mixed> $data Raw zone data.
	 * @return array<string,mixed>
	 */
	private static function with_zone_defaults( array $data ): array {
		return array_merge(
			array(
				'zone_id'        => 0,
				'zone_name'      => '',
				'zone_order'     => 0,
				'zone_locations' => array(),
			),
			$data
		);
	}

	// =========================================================================
	// Method helpers
	// =========================================================================

	/**
	 * Composite key for a zone+instance pair.
	 *
	 * @param int $zone_id     Zone id.
	 * @param int $instance_id Instance id.
	 * @return string
	 */
	private static function method_key( int $zone_id, int $instance_id ): string {
		return $zone_id . '_' . $instance_id;
	}

	/**
	 * Seed one method for a zone (test fixture setup path).
	 *
	 * @param int                 $zone_id     Zone id.
	 * @param int                 $instance_id Instance id.
	 * @param array<string,mixed> $data        Method data.
	 * @return void
	 */
	public static function seed_method( int $zone_id, int $instance_id, array $data ): void {
		$data['zone_id']     = $zone_id;
		$data['instance_id'] = $instance_id;
		self::$methods[ self::method_key( $zone_id, $instance_id ) ] = self::with_method_defaults( $data );
	}

	/**
	 * The stored data for a zone+instance pair, or null.
	 *
	 * @param int $zone_id     Zone id.
	 * @param int $instance_id Instance id.
	 * @return array<string,mixed>|null
	 */
	public static function get_method( int $zone_id, int $instance_id ): ?array {
		return self::$methods[ self::method_key( $zone_id, $instance_id ) ] ?? null;
	}

	/**
	 * All methods for a given zone_id.
	 *
	 * @param int $zone_id Zone id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function methods_for_zone( int $zone_id ): array {
		$out = array();
		foreach ( self::$methods as $key => $method ) {
			if ( (int) ( $method['zone_id'] ?? -1 ) === $zone_id ) {
				$out[] = $method;
			}
		}
		return $out;
	}

	/**
	 * Add a method to a zone (create path), returning the new instance_id.
	 *
	 * Returns 0 when self::$force_save_failure is true.
	 *
	 * @param int    $zone_id Zone id.
	 * @param string $type    Method type (e.g. "flat_rate").
	 * @return int The new instance_id, or 0 on forced failure.
	 */
	public static function add_method( int $zone_id, string $type ): int {
		if ( self::$force_save_failure ) {
			return 0;
		}

		$instance_id = self::$next_instance_id;
		++self::$next_instance_id;

		self::$methods[ self::method_key( $zone_id, $instance_id ) ] = self::with_method_defaults(
			array(
				'zone_id'      => $zone_id,
				'instance_id'  => $instance_id,
				'id'           => $type,
				'method_title' => ucfirst( str_replace( '_', ' ', $type ) ),
				'enabled'      => 'yes',
				'settings'     => array(),
			)
		);

		return $instance_id;
	}

	/**
	 * Update a method's data. Returns 0 on forced failure, 1 on success.
	 *
	 * @param int                 $zone_id     Zone id.
	 * @param int                 $instance_id Instance id.
	 * @param array<string,mixed> $data        Fields to merge.
	 * @return int 1 on success, 0 on forced failure.
	 */
	public static function update_method( int $zone_id, int $instance_id, array $data ): int {
		if ( self::$force_save_failure ) {
			return 0;
		}

		$key = self::method_key( $zone_id, $instance_id );
		if ( ! isset( self::$methods[ $key ] ) ) {
			return 0;
		}

		$existing              = self::$methods[ $key ];
		$merged                = array_merge( $existing, $data );
		$merged['zone_id']     = $zone_id;
		$merged['instance_id'] = $instance_id;
		self::$methods[ $key ] = self::with_method_defaults( $merged );
		return $instance_id;
	}

	/**
	 * Permanently remove a method by zone_id + instance_id.
	 *
	 * Returns false when self::$force_delete_failure is true.
	 *
	 * @param int $zone_id     Zone id.
	 * @param int $instance_id Instance id.
	 * @return bool
	 */
	public static function delete_method( int $zone_id, int $instance_id ): bool {
		if ( self::$force_delete_failure ) {
			return false;
		}
		$key = self::method_key( $zone_id, $instance_id );
		if ( ! isset( self::$methods[ $key ] ) ) {
			return false;
		}
		unset( self::$methods[ $key ] );
		return true;
	}

	/**
	 * Fill a method data array with the defaults a WC_Shipping_Method stub needs.
	 *
	 * @param array<string,mixed> $data Raw method data.
	 * @return array<string,mixed>
	 */
	private static function with_method_defaults( array $data ): array {
		return array_merge(
			array(
				'zone_id'      => 0,
				'instance_id'  => 0,
				'id'           => 'flat_rate',
				'method_title' => 'Flat Rate',
				'enabled'      => 'yes',
				'settings'     => array(),
			),
			$data
		);
	}
}
