<?php
/**
 * WooCommerce contract tests under an injected database fault, plus two shipping parity pins.
 *
 * Real WooCommerce's by-id tax rate read (WC_Tax::_get_tax_rate(), a bare get_row() with no
 * filter) is faulted so the previous query's row answers it. The tax abilities must report that
 * as not found or not confirmed, never as another rate. The shipping pins keep 1.7.5's answer for
 * a zone_loaded listener and a foreign-zone methods filter, which real WooCommerce fires and the
 * stubs do not. Runs on every WooCommerce leg the contract workflow installs.
 *
 * Run: vendor/bin/phpunit -c phpunit-contract.xml.dist (after tests/bin/install-vendors.sh).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Contract;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Tax rate reads that another rate's row answers, and shipping writes under healthy vendor
 * hooks, on real WooCommerce.
 *
 * @group contract
 */
final class WooCommerceFaultContractTest extends TestCase {

	/**
	 * Skip the whole class if WooCommerce is not provisioned in the test core.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\WC_Tax' ) ) {
			$this->markTestSkipped( 'WooCommerce not provisioned — run tests/bin/install-vendors.sh.' );
		}
		QueryFaultInjector::reset_fired_count();
	}

	/**
	 * A tax rate inserted through WooCommerce itself.
	 *
	 * @param string $name Rate name.
	 * @return int The rate id.
	 */
	private function rate( string $name ): int {
		return (int) \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'GB',
				'tax_rate'         => '20.0000',
				'tax_rate_name'    => $name,
			)
		);
	}

	/**
	 * The stored name of a rate, read from its row.
	 *
	 * @param int $rate_id Rate id.
	 */
	private function stored_name( int $rate_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT tax_rate_name FROM %i WHERE tax_rate_id = %d', $wpdb->prefix . 'woocommerce_tax_rates', $rate_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks the row itself.
	}

	/**
	 * Run $run while the $occurrence-th WooCommerce by-id rate read that matches $needle is
	 * answered with rate $leak's row, database errors suppressed and output discarded.
	 *
	 * WooCommerce's own read spans several lines, so a needle carrying a newline never matches the
	 * one-line leak query.
	 *
	 * @param string[] $needle     AND-matched substrings of the read to fault.
	 * @param int      $leak       The rate whose row is left behind.
	 * @param int      $occurrence Which matching read to fault.
	 * @param callable $run        The call.
	 * @return mixed
	 */
	private function with_leaked_rate( array $needle, int $leak, int $occurrence, callable $run ) {
		global $wpdb;
		$filter     = QueryFaultInjector::leak_row_filter( $needle, $wpdb->prepare( 'SELECT * FROM %i WHERE tax_rate_id = %d', $wpdb->prefix . 'woocommerce_tax_rates', $leak ), $occurrence );
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * A confirming read that another rate's row answers reports the write as not confirmed. The
	 * update itself has landed.
	 */
	public function test_a_confirming_read_that_leaks_another_rate_is_not_a_success(): void {
		$a = $this->rate( 'Rate A' );
		$b = $this->rate( 'Rate B' );

		$update = $this->with_leaked_rate(
			array( 'woocommerce_tax_rates', "\n", "WHERE tax_rate_id = {$a}\n" ),
			$b,
			2,
			static fn() => aafm_exec_wc_update_tax_rate(
				array(
					'rate_id' => $a,
					'name'    => 'Renamed A',
				)
			)
		);
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'update: the confirming read was faulted' );
		$this->assertInstanceOf( WP_Error::class, $update, 'update' );
		$this->assertSame( 'aafm_error', $update->get_error_code() );
		$this->assertSame( 'Renamed A', $this->stored_name( $a ), 'the update landed' );

		QueryFaultInjector::reset_fired_count();
		$create = $this->with_leaked_rate(
			array( 'woocommerce_tax_rates', "\n", 'WHERE tax_rate_id = ' ),
			$b,
			1,
			static fn() => aafm_exec_wc_create_tax_rate(
				array(
					'rate'    => '7.0000',
					'name'    => 'New C',
					'country' => 'US',
				)
			)
		);
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'create: the confirming read was faulted' );
		$this->assertInstanceOf( WP_Error::class, $create, 'create' );
		$this->assertSame( 'aafm_error', $create->get_error_code() );
	}

	/**
	 * A get or an update's pre-read that another rate's row answers is not found, and the update
	 * writes nothing.
	 */
	public function test_a_read_that_leaks_another_rate_is_not_found(): void {
		$a = $this->rate( 'Rate A' );
		$b = $this->rate( 'Rate B' );

		$get = $this->with_leaked_rate( array( 'woocommerce_tax_rates', "\n", "WHERE tax_rate_id = {$a}\n" ), $b, 1, static fn() => aafm_exec_wc_get_tax_rate( array( 'rate_id' => $a ) ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'get: the read was faulted' );
		$this->assertInstanceOf( WP_Error::class, $get, 'get' );
		$this->assertSame( 'aafm_not_found', $get->get_error_code() );

		QueryFaultInjector::reset_fired_count();
		$update = $this->with_leaked_rate(
			array( 'woocommerce_tax_rates', "\n", "WHERE tax_rate_id = {$a}\n" ),
			$b,
			1,
			static fn() => aafm_exec_wc_update_tax_rate(
				array(
					'rate_id' => $a,
					'name'    => 'Renamed A',
				)
			)
		);
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'update: the pre-read was faulted' );
		$this->assertInstanceOf( WP_Error::class, $update, 'update' );
		$this->assertSame( 'aafm_not_found', $update->get_error_code() );
		$this->assertSame( 'Rate A', $this->stored_name( $a ), 'nothing was written' );
		$this->assertSame( 'Rate B', $this->stored_name( $b ), 'the other rate is untouched' );
	}

	/**
	 * A zone created through WooCommerce itself.
	 *
	 * @param string $name  Zone name.
	 * @param int    $order Zone order.
	 * @return \WC_Shipping_Zone
	 */
	private function zone( string $name, int $order ): \WC_Shipping_Zone {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		$zone->set_zone_order( $order );
		$zone->save();
		return $zone;
	}

	/**
	 * A zone's stored name and order, read from its row.
	 *
	 * @param int $zone_id Zone id.
	 * @return array<string,string>|null
	 */
	private function stored_zone( int $zone_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT zone_name, zone_order FROM %i WHERE zone_id = %d', $wpdb->prefix . 'woocommerce_shipping_zones', $zone_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks the row itself.
	}

	/**
	 * A zone_loaded listener that renames a zone is saved with the update, as WooCommerce saves
	 * what a listener changed and as 1.7.5 did (the step 14 shipping residual).
	 */
	public function test_a_zone_loaded_listener_rename_is_saved_with_the_update(): void {
		$zone_id  = $this->zone( 'Zone A', 1 )->get_id();
		$listener = static function ( $zone ) use ( $zone_id ): void {
			if ( $zone instanceof \WC_Shipping_Zone && $zone_id === $zone->get_id() ) {
				$zone->set_zone_name( 'Listener name' );
			}
		};
		add_action( 'woocommerce_shipping_zone_loaded', $listener );
		try {
			$result = aafm_exec_wc_update_shipping_zone(
				array(
					'zone_id'    => $zone_id,
					'zone_order' => 7,
				)
			);
		} finally {
			remove_action( 'woocommerce_shipping_zone_loaded', $listener );
		}

		$this->assertSame(
			array(
				'id'             => $zone_id,
				'zone_name'      => 'Listener name',
				'zone_order'     => 7,
				'zone_locations' => array(),
			),
			$result
		);
		$this->assertSame(
			array(
				'zone_name'  => 'Listener name',
				'zone_order' => '7',
			),
			$this->stored_zone( $zone_id ),
			'the listener rename and the requested order are both stored'
		);
	}

	/**
	 * A methods filter that lists another zone's method under this zone lets update-method write
	 * that method, as 1.7.5 did (the step 14 shipping residual).
	 */
	public function test_a_foreign_zone_method_added_by_a_filter_is_written(): void {
		$zone_a = $this->zone( 'Zone A', 1 );
		$zone_b = $this->zone( 'Zone B', 2 );
		$zone_a->add_shipping_method( 'flat_rate' );
		$foreign  = (int) $zone_b->add_shipping_method( 'flat_rate' );
		$a_id     = $zone_a->get_id();
		$b_id     = $zone_b->get_id();
		$add_from = static function ( $methods, $raw, $allowed, $zone ) use ( $a_id, $b_id, $foreign ) {
			if ( $zone instanceof \WC_Shipping_Zone && $a_id === $zone->get_id() ) {
				$methods[ $foreign ] = ( new \WC_Shipping_Zone( $b_id ) )->get_shipping_methods()[ $foreign ];
			}
			return $methods;
		};
		add_filter( 'woocommerce_shipping_zone_shipping_methods', $add_from, 10, 4 );
		try {
			$result = aafm_exec_wc_update_shipping_method(
				array(
					'zone_id'      => $a_id,
					'instance_id'  => $foreign,
					'enabled'      => 'no',
					'method_title' => 'Written through A',
				)
			);
		} finally {
			remove_filter( 'woocommerce_shipping_zone_shipping_methods', $add_from, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertSame( $foreign, $result['instance_id'] );
		$this->assertSame( 'flat_rate', $result['id'] );
		$this->assertSame( 'Written through A', $result['method_title'] );
		$this->assertSame( 'no', $result['enabled'] );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT zone_id, is_enabled FROM %i WHERE instance_id = %d', $wpdb->prefix . 'woocommerce_shipping_zone_methods', $foreign ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks the row itself.
		$this->assertSame(
			array(
				'zone_id'    => (string) $b_id,
				'is_enabled' => '0',
			),
			$row,
			'zone B\'s method row holds the write'
		);
		$settings = aafm_option_row( 'woocommerce_flat_rate_' . $foreign . '_settings' );
		$this->assertTrue( $settings['ok'] && $settings['found'] );
		$this->assertSame( 'Written through A', $settings['value']['title'] ?? null );
	}
}
