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
		\Aafm_Siteops_Redirect_Stub::reset();
		parent::tear_down();
	}

	/**
	 * Every Site Ops read: its execute callback and the input to call it with.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function reads(): array {
		return array(
			'site-health-status'  => array( 'aafm_exec_site_health_status', array() ),
			'site-list-updates'   => array( 'aafm_exec_site_list_updates', array() ),
			'site-cron-status'    => array( 'aafm_exec_site_cron_status', array() ),
			'site-cache-status'   => array( 'aafm_exec_site_cache_status', array() ),
			'site-list-redirects' => array( 'aafm_exec_site_list_redirects', array() ),
			'site-get-redirect'   => array( 'aafm_exec_site_get_redirect', array( 'id' => '1' ) ),
			'site-list-404s'      => array( 'aafm_exec_site_list_404s', array() ),
			'site-test-redirect'  => array( 'aafm_exec_site_test_redirect', array( 'url' => '/old-a' ) ),
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
		if ( in_array( $callback, array( 'aafm_exec_site_list_redirects', 'aafm_exec_site_get_redirect', 'aafm_exec_site_list_404s', 'aafm_exec_site_test_redirect' ), true ) ) {
			\Aafm_Siteops_Redirect_Stub::reset();
			aafm_siteops_redirect_load();
			add_filter(
				'aafm_site_redirect_adapters',
				static function (): array {
					return array( 'fake_a' => \Aafm_Siteops_Redirect_Stub::entry( 'Fake A' ) );
				}
			);
			\Aafm_Siteops_Redirect_Stub::$fake['fake_a']['rows'] = array( \Aafm_Siteops_Redirect_Stub::rule( '1', '/old-a', '/new-a/' ) );
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

	/**
	 * Everything a read must leave alone, for the redirect cases of this test.
	 *
	 * @return array<string,mixed>
	 */
	private function redirect_write_snapshot(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A test reading the whole options table on purpose.
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );

		return array(
			'options'    => md5( (string) wp_json_encode( $options ) ),
			'transients' => array_values(
				array_filter(
					array_column( (array) $options, 'option_name' ),
					static function ( $name ): bool {
						return 0 === strpos( (string) $name, '_transient_' );
					}
				)
			),
			'cron'       => md5( (string) wp_json_encode( _get_cron_array() ) ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A test counting rows on purpose.
			'posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A test counting rows on purpose.
			'postmeta'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
		);
	}

	/**
	 * Run the four redirect reads between two snapshots and assert nothing was written.
	 *
	 * @param array<int,array{0:string,1:array<string,mixed>}> $calls Execute callback and input, in order.
	 */
	private function assert_redirect_reads_write_nothing( array $calls ): void {
		$writes = array();
		$record = static function () use ( &$writes ): void {
			$writes[] = current_action() . ':' . (string) func_get_arg( 0 );
		};
		foreach ( array( 'added_option', 'updated_option', 'deleted_option', 'setted_transient', 'deleted_transient' ) as $hook ) {
			add_action( $hook, $record, 10, 1 );
		}

		$before = $this->redirect_write_snapshot();
		foreach ( $calls as $call ) {
			$result = call_user_func( $call[0], $call[1] );
			$this->assertIsArray( $result, $call[0] . ' must answer, not refuse, so its whole read path runs.' );
		}
		$after = $this->redirect_write_snapshot();

		$this->assertSame( array(), $writes, 'A redirect read wrote an option or a transient.' );
		$this->assertSame( $before, $after, 'A redirect read changed the database.' );
	}

	public function test_redirect_reads_write_nothing_with_fake_adapters(): void {
		\Aafm_Siteops_Redirect_Stub::reset();
		aafm_siteops_redirect_load();
		$this->acting_as( 'administrator' );
		add_filter(
			'aafm_site_redirect_adapters',
			static function (): array {
				return array( 'fake_a' => \Aafm_Siteops_Redirect_Stub::entry( 'Fake A' ) );
			}
		);
		\Aafm_Siteops_Redirect_Stub::$fake['fake_a'] = array(
			'rows'   => array( \Aafm_Siteops_Redirect_Stub::rule( '1', '/old-a', '/new-a/' ) ),
			'log404' => array(
				array(
					'path'      => '/missing',
					'count'     => 1,
					'last_seen' => 1700000000,
				),
			),
		);

		$this->assert_redirect_reads_write_nothing(
			array(
				array( 'aafm_exec_site_list_redirects', array() ),
				array( 'aafm_exec_site_get_redirect', array( 'id' => '1' ) ),
				array( 'aafm_exec_site_list_404s', array() ),
				array( 'aafm_exec_site_test_redirect', array( 'url' => '/old-a' ) ),
			)
		);
	}


	public function test_redirect_reads_write_nothing_with_every_vendor_stub(): void {
		\Aafm_Siteops_Redirect_Stub::reset();
		aafm_siteops_redirect_stub_activate_eps();
		aafm_siteops_redirect_stub_activate_redirection();
		aafm_siteops_redirect_stub_activate_srm();
		aafm_siteops_redirect_stub_activate_rankmath();
		aafm_siteops_redirect_stub_activate_slim_seo();
		aafm_siteops_redirect_load();
		$this->acting_as( 'administrator' );
		foreach ( array( 'srm_manage_redirects', 'rank_math_redirections', 'rank_math_404_monitor' ) as $capability ) {
			wp_get_current_user()->add_cap( $capability );
		}
		add_filter(
			'aafm_site_redirect_adapters',
			static function ( array $adapters ): array {
				foreach ( $adapters as $slug => $entry ) {
					$adapters[ $slug ]['detect'] = array();
				}
				return $adapters;
			}
		);

		\Aafm_Siteops_Redirect_Stub::$vendor = array(
			'rm_modules'    => array( 'redirections', '404-monitor' ),
			'slim_settings' => array( 'enable_404_logs' => 1 ),
			// The one named exception: with its redirect cache on, Redirection writes an object cache entry.
			'red_cache_on'  => true,
			'eps'           => array(
				(object) array(
					'id'       => '3',
					'url_from' => 'same',
					'url_to'   => '/t-eps',
					'status'   => '301',
					'type'     => 'url',
					'count'    => '0',
				),
			),
			'red'           => array( aafm_siteops_redirect_stub_red_row( 4, '/other', '/t-redirection' ) ),
			'rm'            => array(
				aafm_siteops_redirect_stub_rm_row(
					5,
					array(
						array(
							'pattern'    => 'other',
							'comparison' => 'exact',
						),
					),
					'http://example.org/t-rankmath'
				),
			),
			'slim'          => array( 'abc123' => aafm_siteops_redirect_stub_slim_rule( 'other', 't-slim' ) ),
		);
		aafm_siteops_redirect_stub_srm_rule( '/other', '/t-srm' );
		update_option( 'eps_redirects_404_log', array() );
		// The cold-cache state: no stored rule list, nothing in the object cache.
		delete_transient( '_srm_redirects_1000' );
		wp_cache_flush();

		$this->assertCount( 5, aafm_site_redirect_ready_slugs() );
		$this->assert_redirect_reads_write_nothing(
			array(
				array( 'aafm_exec_site_list_redirects', array() ),
				array(
					'aafm_exec_site_get_redirect',
					array(
						'provider' => 'eps_301',
						'id'       => '3',
					),
				),
				array( 'aafm_exec_site_list_404s', array() ),
				array( 'aafm_exec_site_test_redirect', array( 'url' => '/other' ) ),
			)
		);

		$this->assertSame( 0, \Aafm_Siteops_Redirect_Stub::count( 'srm_get_redirects' ), 'The cached getter stores a transient and must never be called.' );
		$this->assertSame( 0, \Aafm_Siteops_Redirect_Stub::count( 'srm_match_redirect' ) );
		$this->assertFalse( get_transient( '_srm_redirects_1000' ) );
		$this->assertSame( 1, wp_cache_get( 'aafm-stub-' . md5( '/other' ), 'redirection' ), 'An object cache entry in the plugin own group is not a database write.' );
	}
}
