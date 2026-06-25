<?php
/**
 * Process-wide backing store for the WooCommerce tax rate and class stubs (Wave 4 / W4-WC6 integration tests).
 *
 * Lives in its own file so the IntegrationStubs trait file holds a single object structure (the
 * trait), satisfying Generic.Files.OneObjectStructurePerFile. Required directly from the test
 * bootstrap, never shipped.
 *
 * Tax rates are backed by a real temp table (woocommerce_tax_rates) created in the test DB so
 * that the production $wpdb direct-query helpers work without modification. Tax classes are
 * backed by a plain static array — WC_Tax::get_tax_classes() / create / delete are intercepted
 * by the WC_Tax eval stub.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests;

/**
 * Process-wide backing store for the WooCommerce tax rate and class stubs.
 */
class WcTaxStubStore {

	/**
	 * Tax classes keyed by slug: slug => name.
	 * The standard class is NOT stored here; it is synthesised at list time.
	 *
	 * @var array<string,string>
	 */
	public static array $classes = array();

	/**
	 * When true, create_tax_class() returns a WP_Error so the failure path is exercisable.
	 *
	 * @var bool
	 */
	public static bool $force_save_failure = false;

	/**
	 * When true, delete_tax_class_by() returns a WP_Error so the delete-failure path is exercisable.
	 *
	 * @var bool
	 */
	public static bool $force_delete_failure = false;

	/**
	 * Clear all class state. Does NOT touch the temp DB table — call drop/create for that.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$classes              = array();
		self::$force_save_failure   = false;
		self::$force_delete_failure = false;
	}

	/**
	 * Seed default tax classes into the store.
	 *
	 * @return void
	 */
	public static function seed(): void {
		self::$classes = array(
			'reduced-rate' => 'Reduced Rate',
			'zero-rate'    => 'Zero Rate',
		);
	}

	// =========================================================================
	// Temp DB table helpers (for tax rates)
	// =========================================================================

	/**
	 * Create the woocommerce_tax_rates temp table in the test DB.
	 *
	 * Called from WooTaxTest::set_up() so $wpdb direct queries in the production
	 * helper functions (oversio_wc_get_all_tax_rates etc.) resolve to this table.
	 *
	 * @return void
	 */
	public static function create_tax_rates_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'woocommerce_tax_rates';
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL; table name and charset are internal constants, no user input.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				tax_rate_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tax_rate_country  varchar(200)  NOT NULL DEFAULT '',
				tax_rate_state    varchar(200)  NOT NULL DEFAULT '',
				tax_rate     decimal(26,4) NOT NULL DEFAULT '0.0000',
				tax_rate_name     varchar(200)  NOT NULL DEFAULT '',
				tax_rate_priority BIGINT UNSIGNED NOT NULL DEFAULT 1,
				tax_rate_compound int(1)        NOT NULL DEFAULT 0,
				tax_rate_shipping int(1)        NOT NULL DEFAULT 1,
				tax_rate_order    BIGINT UNSIGNED NOT NULL DEFAULT 0,
				tax_rate_class    varchar(200)  NOT NULL DEFAULT '',
				PRIMARY KEY (tax_rate_id)
			) {$charset}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop the woocommerce_tax_rates temp table.
	 *
	 * @return void
	 */
	public static function drop_tax_rates_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_tax_rates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL; table name is an internal constant, no user input.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Seed two rates into the temp table: standard 20% (id auto) and reduced 5%.
	 *
	 * @return void
	 */
	public static function seed_rates(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_tax_rates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tax_rate_country'  => 'GB',
				'tax_rate_state'    => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tax_rate_country'  => 'GB',
				'tax_rate_state'    => '',
				'tax_rate'          => '5.0000',
				'tax_rate_name'     => 'Reduced VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 0,
				'tax_rate_order'    => 2,
				'tax_rate_class'    => 'reduced-rate',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
	}
}
