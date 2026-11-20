<?php
/**
 * The cache adapter registry, the dispatcher, the two confirmation sequences and the four cache
 * tools, all against fake adapters. No vendor symbol is involved.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use SiteopsCacheStubStore as Stub;

final class SiteCacheDispatchTest extends TestCase {

	private const TOOLS = array( 'aafm/site-cache-status', 'aafm/site-purge-post-cache', 'aafm/site-purge-url-cache', 'aafm/site-purge-site-cache' );

	/**
	 * Temporary directories made by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	public function set_up(): void {
		parent::set_up();
		aafm_siteops_load();
		Stub::reset();
		aafm_current_invocation_row_id( 0 );
		update_option( 'home', 'https://example.com' );
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		Stub::reset();
		aafm_current_invocation_row_id( 0 );
		remove_all_filters( 'aafm_site_purge_budget' );
		remove_all_filters( 'aafm_siteops_is_cli' );
		remove_filter( 'aafm_integration_active_site_cache', '__return_true' );
		aafm_siteops_http_guard_end();
		remove_filter( 'http_request_args', 'aafm_siteops_http_guard_args', PHP_INT_MAX );
		aafm_flush_registry_cache();
		foreach ( $this->dirs as $dir ) {
			$this->remove_tree( $dir );
		}
		$this->dirs = array();
		parent::tear_down();
	}

	/**
	 * A fresh temporary directory.
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	private function tmp(): string {
		$dir = rtrim( get_temp_dir(), '/' ) . '/aafm-siteops-' . strtolower( wp_generate_password( 10, false ) );
		wp_mkdir_p( $dir );
		$this->dirs[] = $dir;
		return (string) realpath( $dir );
	}

	/**
	 * Write a file, creating its directory.
	 *
	 * @param string $path Absolute path.
	 */
	private function put( string $path ): void {
		wp_mkdir_p( dirname( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture in a temporary directory.
		file_put_contents( $path, 'x' );
	}

	/**
	 * Remove a directory tree made by this test.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_tree( string $dir ): void {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_tree( $dir . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture in a temporary directory.
		rmdir( $dir );
	}

	public function test_the_registry_holds_the_13_adapters_in_fan_out_order(): void {
		$adapters = aafm_site_cache_adapters();
		$this->assertSame(
			array( 'wp_super_cache', 'wp_fastest_cache', 'w3_total_cache', 'wp_optimize', 'cache_enabler', 'hummingbird', 'jetpack_boost', 'speedycache', 'breeze', 'siteground', 'litespeed', 'nginx_helper', 'proxy_cache_purge' ),
			array_keys( $adapters )
		);

		$flag = static function ( string $key, $value ) use ( $adapters ): array {
			return array_keys(
				array_filter(
					$adapters,
					static function ( array $entry ) use ( $key, $value ): bool {
						return $value === $entry[ $key ];
					}
				)
			);
		};
		foreach ( $adapters as $slug => $entry ) {
			$this->assertSame( array( 'label', 'plugins', 'detect', 'ops', 'confirm', 'vendor_http', 'recursive_url', 'rewarms', 'cli' ), array_keys( $entry ), $slug );
			$this->assertSame( array( 'post', 'url', 'all' ), $entry['ops'], $slug );
			$this->assertCount( 1, $entry['plugins'], $slug );
			$this->assertNotEmpty( $entry['detect'], $slug );
		}
		$this->assertSame( array( 'litespeed', 'proxy_cache_purge' ), $flag( 'confirm', 'none' ) );
		$this->assertSame( array( 'wp_optimize', 'hummingbird', 'jetpack_boost', 'speedycache', 'litespeed' ), $flag( 'cli', false ) );
		$this->assertSame( array( 'wp_super_cache', 'cache_enabler', 'jetpack_boost', 'litespeed' ), $flag( 'vendor_http', false ) );
		$this->assertSame( array( 'jetpack_boost' ), $flag( 'recursive_url', true ) );
		$this->assertSame( array( 'siteground' ), $flag( 'rewarms', true ) );
		$this->assertSame( 'Breeze (file cache)', $adapters['breeze']['label'] );
		$this->assertSame( array( 'sg-cachepress/sg-cachepress.php' ), $adapters['siteground']['plugins'] );
		$this->assertSame( array( array( 'class', 'VarnishPurger' ) ), $adapters['proxy_cache_purge']['detect'] );
	}

	public function test_no_real_adapter_is_detected_on_the_test_site(): void {
		foreach ( aafm_site_cache_adapters() as $slug => $entry ) {
			$this->assertFalse( aafm_site_cache_detected( $entry ), $slug );
		}
	}

	public function test_detected_needs_every_test_to_pass(): void {
		$this->assertTrue( aafm_site_cache_detected( array( 'detect' => array() ) ) );
		$this->assertTrue( aafm_site_cache_detected( array( 'detect' => array( array( 'function', 'strlen' ), array( 'class', 'WP_Error' ), array( 'constant', 'ABSPATH' ) ) ) ) );
		$this->assertFalse( aafm_site_cache_detected( array( 'detect' => array( array( 'function', 'strlen' ), array( 'function', 'aafm_no_such_function' ) ) ) ) );
		$this->assertFalse( aafm_site_cache_detected( array( 'detect' => array( array( 'class', 'Aafm_No_Such_Class' ) ) ) ) );
		$this->assertFalse( aafm_site_cache_detected( array( 'detect' => array( array( 'constant', 'AAFM_NO_SUCH_CONSTANT' ) ) ) ) );
		$this->assertFalse( aafm_site_cache_detected( array( 'detect' => array( array( 'global', 'wpdb' ) ) ) ), 'An unknown kind never passes.' );
		$this->assertFalse( aafm_site_cache_detected( array( 'detect' => array( 'strlen' ) ) ), 'A malformed test never passes.' );
	}

	public function test_plugin_active_reads_the_active_plugin_list(): void {
		update_option( 'active_plugins', array( 'one/one.php', 'two/two.php' ) );
		$this->assertTrue( aafm_siteops_plugin_active( array( 'zero/zero.php', 'two/two.php' ) ) );
		$this->assertFalse( aafm_siteops_plugin_active( array( 'zero/zero.php' ) ) );
		$this->assertFalse( aafm_siteops_plugin_active( array() ) );
	}

	public function test_result_has_exactly_three_keys(): void {
		$this->assertSame(
			array(
				'state'      => 'requested',
				'reason'     => 'not_found_in_cache',
				'was_cached' => false,
			),
			aafm_site_cache_result( 'requested', 'not_found_in_cache', false )
		);
		$this->assertNull( aafm_site_cache_result( 'failed', 'vendor_error', null )['was_cached'] );
	}

	public function test_adapter_ready_needs_all_four_functions(): void {
		$this->assertTrue( aafm_site_cache_adapter_ready( 'fake_a' ) );
		$this->assertFalse( aafm_site_cache_adapter_ready( 'fake_partial' ) );
		$this->assertFalse( aafm_site_cache_adapter_ready( 'wp_super_cache' ), 'No adapter file exists yet for a real slug.' );
		$this->assertFalse( aafm_site_cache_adapter_ready( '../budget' ), 'A slug that is not a plain identifier is never loaded.' );
	}

	public function test_the_stub_replaces_the_registry(): void {
		Stub::register( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );
		$this->assertSame( array( 'fake_a' ), array_keys( aafm_site_cache_adapters() ) );
		Stub::reset();
		$this->assertCount( 13, aafm_site_cache_adapters() );
	}
}
