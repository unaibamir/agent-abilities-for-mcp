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
	 * When set to an array, WC_Shipping_Zones::get_zones() returns it verbatim instead of
	 * building rows from the store. Lets a test feed a malformed row (e.g. one with no
	 * resolvable zone id) so the list ability's WP_Error branch is exercisable.
	 *
	 * @var array<int|string,mixed>|null
	 */
	public static ?array $rows_override = null;

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
		self::$rows_override        = null;
	}

	// =========================================================================
	// is_enabled table (mirrors WC's woocommerce_shipping_zone_methods table)
	// =========================================================================

	/**
	 * Fully-qualified name of the temp methods table in the test DB.
	 *
	 * @return string
	 */
	private static function methods_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'woocommerce_shipping_zone_methods';
	}

	/**
	 * Whether the temp methods table currently exists in the test DB.
	 *
	 * @return bool
	 */
	private static function methods_table_exists(): bool {
		global $wpdb;
		$table      = self::methods_table();
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- existence probe; table name is internal.
		$wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" );
		$exists = ( '' === $wpdb->last_error );
		$wpdb->suppress_errors( $suppressed );
		return $exists;
	}

	/**
	 * Create the woocommerce_shipping_zone_methods temp table in the test DB.
	 *
	 * The production enabled toggle in aafm_exec_wc_update_shipping_method() runs a direct
	 * $wpdb->update() against this table (no core API exists for the is_enabled column), so a
	 * real table is created here - mirroring how WcTaxStubStore backs the tax-rate queries - and
	 * the stub WC_Shipping_Method constructor reads is_enabled back from it. Call from the
	 * shipping test set_up after seeding the in-memory store.
	 *
	 * @return void
	 */
	public static function create_methods_table(): void {
		global $wpdb;
		$table   = self::methods_table();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL; table name and charset are internal constants, no user input.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				zone_id      BIGINT UNSIGNED NOT NULL,
				instance_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				method_id    varchar(200)    NOT NULL,
				method_order BIGINT UNSIGNED NOT NULL,
				is_enabled   tinyint(1)      NOT NULL DEFAULT 1,
				PRIMARY KEY (instance_id)
			) {$charset}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Mirror every seeded in-memory method into the table so its is_enabled column
		// agrees with the stored enabled flag.
		foreach ( self::$methods as $method ) {
			self::sync_method_row(
				(int) ( $method['zone_id'] ?? 0 ),
				(int) ( $method['instance_id'] ?? 0 ),
				(string) ( $method['id'] ?? 'flat_rate' ),
				'no' === (string) ( $method['enabled'] ?? 'yes' ) ? 0 : 1
			);
		}
	}

	/**
	 * Drop the woocommerce_shipping_zone_methods temp table.
	 *
	 * @return void
	 */
	public static function drop_methods_table(): void {
		global $wpdb;
		$table = self::methods_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL; table name is an internal constant, no user input.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Insert or update a single is_enabled row in the temp table.
	 *
	 * @param int    $zone_id     Zone id.
	 * @param int    $instance_id Instance id.
	 * @param string $method_id   Method type (e.g. flat_rate).
	 * @param int    $is_enabled  1 or 0.
	 * @return void
	 */
	public static function sync_method_row( int $zone_id, int $instance_id, string $method_id, int $is_enabled ): void {
		global $wpdb;
		if ( ! self::methods_table_exists() ) {
			return;
		}
		$table = self::methods_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture row sync into the temp table.
		$wpdb->replace(
			$table,
			array(
				'zone_id'      => $zone_id,
				'instance_id'  => $instance_id,
				'method_id'    => $method_id,
				'method_order' => $instance_id,
				'is_enabled'   => $is_enabled,
			),
			array( '%d', '%d', '%s', '%d', '%d' )
		);
	}

	/**
	 * Read the is_enabled column for an instance, or null when no row exists.
	 *
	 * @param int $instance_id Instance id.
	 * @return int|null 1, 0, or null.
	 */
	public static function read_is_enabled( int $instance_id ): ?int {
		global $wpdb;
		if ( ! self::methods_table_exists() ) {
			return null;
		}
		$table = self::methods_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture read from the temp table; table name is internal.
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT is_enabled FROM `{$table}` WHERE instance_id = %d", $instance_id ) );

		return ( null === $val ) ? null : (int) $val;
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

		// Mirror the new method into the is_enabled temp table (when it exists) so a subsequent
		// enabled toggle through the production $wpdb->update() finds a row to update.
		self::sync_method_row( $zone_id, $instance_id, $type, 1 );

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
