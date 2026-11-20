<?php
/**
 * The rule "a read writes nothing" for the read abilities of Site Ops.
 *
 * Each batch that registers a read adds its row to reads() and, where the read needs a stub or a
 * fake adapter, its lines to prepare().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SiteOpsReadsWriteNothingTest extends TestCase {

	public function tear_down(): void {
		\SiteopsCacheStubStore::reset();
		parent::tear_down();
	}

	/**
	 * Every Site Ops read: its execute callback and the input to call it with.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function reads(): array {
		return array(
			'site-health-status' => array( 'aafm_exec_site_health_status', array() ),
			'site-list-updates'  => array( 'aafm_exec_site_list_updates', array() ),
			'site-cron-status'   => array( 'aafm_exec_site_cron_status', array() ),
			'site-cache-status'  => array( 'aafm_exec_site_cache_status', array() ),
		);
	}

	/**
	 * Put the stubs or fake adapters one read needs in place.
	 *
	 * @param string $callback Execute callback about to be called.
	 */
	private function prepare( string $callback ): void {
		if ( 'aafm_exec_site_cache_status' === $callback ) {
			\SiteopsCacheStubStore::register(
				array(
					'fake_a' => \SiteopsCacheStubStore::entry( 'Fake A' ),
					'fake_b' => \SiteopsCacheStubStore::entry( 'Fake B' ),
				)
			);
			\SiteopsCacheStubStore::$serving = array( 'fake_b' => false );
		}
	}

	/**
	 * Everything a read must leave unchanged.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(): array {
		global $wpdb;
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_N );
		return array(
			'options'    => md5( serialize( $options ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fingerprint of the options table, never stored.
			'cron'       => md5( serialize( _get_cron_array() ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fingerprint of the cron array, never stored.
			'posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'postmeta'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
			'transients' => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%' ORDER BY option_name" ),
		);
	}

	/**
	 * One read leaves every option, transient, cron event and post count as it found them.
	 *
	 * @dataProvider reads
	 *
	 * @param string              $callback Execute callback.
	 * @param array<string,mixed> $input    Input.
	 */
	public function test_a_read_writes_nothing( string $callback, array $input ): void {
		$this->acting_as( 'administrator' );
		$this->prepare( $callback );

		$writes        = array();
		$on_update     = static function ( $value, $option ) use ( &$writes ) {
			$writes[] = 'update_option ' . $option;
			return $value;
		};
		$on_add        = static function ( $option ) use ( &$writes ): void {
			$writes[] = 'add_option ' . $option;
		};
		$on_delete     = static function ( $option ) use ( &$writes ): void {
			$writes[] = 'delete_option ' . $option;
		};
		$on_transients = static function ( $hook ) use ( &$writes ): void {
			if ( is_string( $hook ) && ( 0 === strpos( $hook, 'pre_set_transient_' ) || 0 === strpos( $hook, 'pre_set_site_transient_' ) ) ) {
				$writes[] = $hook;
			}
		};

		$before = $this->snapshot();
		add_filter( 'pre_update_option', $on_update, 10, 2 );
		add_action( 'add_option', $on_add );
		add_action( 'delete_option', $on_delete );
		add_action( 'all', $on_transients );

		$result = $callback( $input );

		remove_filter( 'pre_update_option', $on_update, 10 );
		remove_action( 'add_option', $on_add );
		remove_action( 'delete_option', $on_delete );
		remove_action( 'all', $on_transients );

		$this->assertIsArray( $result, 'The read answered.' );
		$this->assertSame( array(), $writes, 'No option or transient write was attempted.' );
		$this->assertSame( $before, $this->snapshot() );
	}
}
